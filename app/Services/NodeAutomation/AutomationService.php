<?php

namespace App\Services\NodeAutomation;

use App\Models\{NodeAutomationGroup as Group, NodeAutomationBackup as Backup, NodeAutomationLog as Event, NodeAutomationCheck as Observation};
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;

class AutomationService
{
    public function __construct(protected CloudflareDns $dns, protected ProxyProbe $probe) {}

    public function locked(int $groupId, callable $callback): mixed
    {
        $lock = Cache::lock('node-automation:group:' . $groupId, 600);
        if (!$lock->get()) throw new AutomationException('该域名正在检测或切换，请稍后再试');
        try { return $callback(Group::findOrFail($groupId)); }
        finally { $lock->release(); }
    }

    public function configurationHash(Group $group): string
    {
        return $this->probe->fingerprint($group);
    }

    protected function observe(Group $group, string $ip, array $targets): array
    {
        try {
            $result = $this->probe->check($group, $ip, $targets);
            Observation::updateOrCreate(['group_id' => $group->id, 'ip' => $ip],
                ['status' => $result['ok'] ? 'pass' : 'fail', 'nodes' => $result['nodes'], 'message' => null, 'checked_at' => now()]);
            return $result;
        } catch (AutomationException $e) {
            Observation::updateOrCreate(['group_id' => $group->id, 'ip' => $ip],
                ['status' => 'error', 'nodes' => null, 'message' => $e->getMessage(), 'checked_at' => now()]);
            throw $e;
        }
    }

    public function pending(Group $group): bool
    {
        return Event::where('group_id', $group->id)->whereIn('status', ['queued', 'running', 'prepared', 'verifying', 'dns_unknown'])->exists();
    }

    public function requireEditable(Group $group): void
    {
        if ($group->enabled || $this->pending($group)) throw new AutomationException('请先暂停策略，并等待检测或切换任务完成后再修改配置');
    }

    public function pause(Group $group): void
    {
        Event::where('group_id', $group->id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'cancelled', 'message' => '管理员暂停策略']);
        // The DNS commit is already durable; cancellation only stops further probing.
        Event::where('group_id', $group->id)->where('status', 'verifying')->where('to_ip', $group->current_ip)
            ->update(['status' => 'cancelled', 'message' => 'DNS 已确认，管理员暂停后续验证']);
        foreach (Event::where('group_id', $group->id)->whereIn('status', ['prepared', 'dns_unknown'])->get() as $event) {
            $event->update(['result' => array_merge($event->result ?? [], ['pause_requested' => true])]);
        }
        $group->update(['enabled' => false, 'state' => 'paused', 'failures' => 0, 'reason' => null]);
    }

    public function match(Group $group): array
    {
        if (!$group->connection) throw new AutomationException('请先配置 Cloudflare 连接');
        return $this->dns->match($group->connection, $group->domain);
    }

    public function reconcileBinding(Group $group): array
    {
        $this->requireEditable($group);
        $record = $this->match($group);
        $group->backups()->where('state', 'active')->where('ip', '!=', $record['content'])
            ->update(['state' => 'needs_review', 'enabled' => false, 'reason' => 'DNS 重新绑定后原 VPS 需检查']);
        $group->backups()->where('ip', $record['content'])->update(['state' => 'active']);
        $group->update(['record_id' => $record['id'], 'current_ip' => $record['content'],
            'enabled' => false, 'failures' => 0, 'state' => 'paused', 'reason' => null]);
        return $record;
    }

    protected function boundRecord(Group $group): array
    {
        if (!$group->connection || !$group->record_id || !$group->current_ip) throw new AutomationException('请先匹配 Cloudflare DNS 记录');
        try { $record = $this->dns->read($group->connection, $group->record_id, $group->domain); }
        catch (DnsConflictException $e) {
            $group->update(['enabled' => false, 'state' => 'dns_conflict', 'reason' => $e->getMessage()]);
            throw $e;
        }
        if ($group->enabled && (int) $record['ttl'] !== 60) {
            $group->update(['enabled' => false, 'state' => 'dns_conflict', 'reason' => '受管 DNS 的 TTL 已被修改，请重新匹配']);
            throw new DnsConflictException('受管 DNS 的 TTL 已被外部修改');
        }
        if ($record['content'] !== $group->current_ip) {
            $group->update(['enabled' => false, 'state' => 'dns_conflict', 'reason' => 'DNS IP 已被外部修改，请暂停后重新匹配']);
            throw new AutomationException('DNS IP 已被外部修改，已停止自动切换');
        }
        return $record;
    }

    protected function assertConfiguration(Group $group): string
    {
        $hash = $this->configurationHash($group);
        if (!$group->config_hash || !hash_equals($group->config_hash, $hash)) {
            $group->update(['enabled' => false, 'state' => 'config_error', 'reason' => '关联节点或测试身份已变化，请保存配置并重新测试']);
            throw new AutomationException('关联节点或测试身份已变化，请重新配置');
        }
        return $hash;
    }

    public function runOperation(int $eventId): void
    {
        $event = Event::findOrFail($eventId);
        $this->locked($event->group_id, function (Group $group) use ($event) {
            $event->refresh();
            if (in_array($event->status, ['completed', 'failed', 'cancelled'], true)) return;
            if (in_array($event->status, ['prepared', 'verifying', 'dns_unknown'], true)) {
                $this->recover($group, $event);
                return;
            }
            try {
                $event->update(['status' => 'running']);
                if ($event->action === 'enable') $this->enable($group, $event);
                elseif ($event->action === 'test') $this->test($group, $event);
                elseif (in_array($event->action, ['manual', 'auto'], true)) $this->switch($group, $this->probe->targets(), $event, $event->action === 'manual');
                else throw new AutomationException('未知自动化操作');
            } catch (AutomationException $e) {
                if (!in_array($event->fresh()->status, ['prepared', 'verifying', 'dns_unknown'], true)) {
                    $event->update(['status' => 'failed', 'message' => $e->getMessage()]);
                }
                if (!in_array($group->fresh()->state, ['dns_conflict', 'config_error', 'dns_unknown', 'switching'], true)) {
                    $group->update(['state' => 'config_error', 'reason' => $e->getMessage()]);
                }
            }
        });
    }

    protected function test(Group $group, Event $event): void
    {
        $this->assertConfiguration($group);
        $backup = Backup::where('group_id', $group->id)->findOrFail($event->backup_id);
        $targets = $this->probe->targets();
        $result = $this->checkBackup($group, $backup, $targets);
        $event->update(['status' => $result['ok'] ? 'completed' : 'failed', 'result' => $result,
            'message' => $result['ok'] ? '备用全部关联节点连续两次测试通过' : '备用存在不可用节点']);
    }

    protected function enable(Group $group, Event $event): void
    {
        $hash = $this->assertConfiguration($group);
        $record = $this->boundRecord($group);
        $this->probe->readiness();
        $targets = $this->probe->targets();
        if (!$this->observe($group, $group->current_ip, $targets)['ok']) throw new AutomationException('当前 VPS 基线测试失败，暂不能开启自动化');
        $ready = false;
        foreach ($this->candidates($group) as $backup) {
            if ($this->checkBackup($group, $backup, $targets)['ok']) { $ready = true; break; }
        }
        if (!$ready) throw new AutomationException('没有通过全部关联节点测试的备用 VPS');
        // TTL modification uses the same durable intent and read-after-write recovery.
        $event->update(['status' => 'prepared', 'record_before' => $record,
            'from_ip' => $group->current_ip, 'to_ip' => $group->current_ip, 'result' => ['config_hash' => $hash]]);
        try { $this->dns->patch($group->connection, $group->record_id, ['ttl' => 60]); }
        catch (DnsWriteRejectedException $e) { $event->update(['status' => 'failed', 'message' => $e->getMessage()]); throw $e; }
        catch (AutomationException $e) { /* Read back: failure may only be a lost response. */ }
        $this->recover($group, $event);
    }

    protected function candidates(Group $group)
    {
        return $group->backups()->where('enabled', true)->where('ip', '!=', $group->current_ip)
            ->whereNotIn('state', ['needs_review', 'active'])->limit(config('node_automation.max_backups', 10))->get();
    }

    protected function checkBackup(Group $group, Backup $backup, array $targets): array
    {
        $first = $this->observe($group, $backup->ip, $targets);
        $second = $first['ok'] ? $this->observe($group, $backup->ip, $targets) : $first;
        $result = ['ok' => $first['ok'] && $second['ok'], 'nodes' => $second['nodes']];
        // A test must never re-arm an exhausted/failed former active machine.
        $state = in_array($backup->state, ['needs_review', 'active'], true) ? $backup->state : ($result['ok'] ? 'ready' : 'failed');
        $backup->update(['state' => $state, 'tested_at' => now(), 'tested_hash' => $group->config_hash,
            'reason' => $result['ok'] ? null : '备用存在不可用节点']);
        return $result;
    }

    public function tick(int $groupId, int $scheduledAt): void
    {
        // Stale queued rounds do not count as consecutive live observations.
        if (time() - $scheduledAt > 120) return;
        $this->locked($groupId, function (Group $group) use ($scheduledAt) {
            $pending = Event::where('group_id', $group->id)->whereIn('status', ['prepared', 'verifying', 'dns_unknown'])->oldest('id')->first();
            if ($pending) { $this->recover($group, $pending); return; }
            if (!$group->enabled || $this->pending($group)) return;
            if ($group->last_checked_at && $scheduledAt <= $group->last_checked_at->timestamp + 45) return;
            try {
                $this->assertConfiguration($group);
                $this->boundRecord($group);
                $targets = $this->probe->targets();
                $result = $this->observe($group, $group->current_ip, $targets);
                if ($result['ok']) {
                    $propagating = $group->last_switched_at &&
                        (($group->propagation_until && now()->lessThan($group->propagation_until)) || !$this->probe->dnsMatches($group->domain, $group->current_ip));
                    $group->update(['failures' => 0, 'last_checked_at' => now(),
                        'state' => $propagating ? 'propagating' : 'healthy', 'reason' => null]);
                    return;
                }
                // Verify the targets are still healthy after a failed proxy round.
                $this->verifyTargets($targets);
                $failures = $group->failures + 1;
                $group->update(['failures' => $failures, 'last_checked_at' => now(), 'state' => 'suspect', 'reason' => '关联节点代理请求失败']);
                if ($failures >= config('node_automation.failure_threshold', 3) &&
                    (!$group->cooldown_until || now()->greaterThanOrEqualTo($group->cooldown_until))) {
                    $event = Event::create(['group_id' => $group->id, 'operation_id' => (string) Str::uuid(),
                        'action' => 'auto', 'status' => 'running']);
                    $this->switch($group, $targets, $event, false);
                }
            } catch (AutomationException $e) {
                if (isset($event) && !in_array($event->fresh()->status, ['prepared', 'verifying', 'dns_unknown', 'completed', 'cancelled'], true)) {
                    $event->update(['status' => 'failed', 'message' => $e->getMessage()]);
                }
                if (!in_array($group->fresh()->state, ['dns_conflict', 'config_error', 'dns_unknown'], true)) {
                    $group->update(['state' => 'probe_error', 'reason' => $e->getMessage()]);
                }
                // Missing accounts/runtime/targets interrupt the failure streak.
                $group->update(['failures' => 0]);
            }
        });
    }

    protected function switch(Group $group, array $targets, Event $event, bool $manual): void
    {
        $this->assertConfiguration($group);
        if (!$manual && !$group->enabled) { $event->update(['status' => 'cancelled', 'message' => '策略已暂停']); return; }
        if ($group->cooldown_until && now()->lessThan($group->cooldown_until)) throw new AutomationException('处于切换冷却期，请稍后再试');
        $record = $this->boundRecord($group);
        $candidates = $this->candidates($group);
        if ($manual && $event->backup_id) $candidates = $candidates->where('id', $event->backup_id);
        foreach ($candidates as $backup) {
            $result = $this->checkBackup($group, $backup, $targets);
            if (!$result['ok']) continue;
            $this->verifyTargets($targets);
            if (!$manual && $this->observe($group, $group->current_ip, $targets)['ok']) {
                $group->update(['failures' => 0, 'state' => 'healthy', 'reason' => null]);
                $event->update(['status' => 'cancelled', 'message' => '当前 VPS 已恢复，取消自动切换']);
                return;
            }
            $this->assertConfiguration($group);
            $record = $this->boundRecord($group);
            $event->update(['status' => 'prepared', 'backup_id' => $backup->id, 'from_ip' => $group->current_ip,
                'to_ip' => $backup->ip, 'record_before' => $record]);
            $group->update(['state' => 'switching', 'reason' => null]);
            try { $this->dns->patch($group->connection, $group->record_id, ['content' => $backup->ip]); }
            catch (DnsWriteRejectedException $e) {
                $event->update(['status' => 'failed', 'message' => $e->getMessage()]);
                $group->update(['enabled' => false, 'state' => 'paused', 'reason' => $e->getMessage()]);
                return;
            }
            catch (AutomationException $e) { /* Reconcile the record before deciding what happened. */ }
            $this->recover($group, $event);
            return;
        }
        $group->update(['state' => 'no_backup', 'reason' => '没有通过测试的可用备用，DNS 未修改']);
        $event->update(['status' => 'failed', 'message' => '没有通过测试的可用备用，DNS 未修改']);
    }

    /** Resume an intent after timeout, a worker crash, or a successful DNS write. */
    protected function recover(Group $group, Event $event): void
    {
        try { $record = $this->dns->read($group->connection, $group->record_id, $group->domain); }
        catch (DnsConflictException $e) {
            $event->update(['status' => 'failed', 'message' => $e->getMessage()]);
            $group->update(['enabled' => false, 'state' => 'dns_conflict', 'reason' => $e->getMessage()]);
            return;
        }
        catch (AutomationException $e) {
            $event->update(['status' => 'dns_unknown', 'message' => 'DNS 结果未确认，等待回读，禁止重复切换']);
            $group->update(['state' => 'dns_unknown', 'reason' => 'DNS 结果未确认，等待回读']);
            return;
        }
        if ($record['content'] !== $event->to_ip) {
            $isOriginal = $record['content'] === $event->from_ip;
            $event->update(['status' => 'failed', 'message' => $isOriginal ? 'DNS 未修改成功' : 'DNS 被外部修改，停止切换']);
            $group->update(['enabled' => false, 'state' => $isOriginal ? 'paused' : 'dns_conflict',
                'reason' => $event->message]);
            return;
        }
        if ($event->action === 'enable') {
            if ((int) $record['ttl'] !== 60) {
                $event->update(['status' => 'failed', 'message' => 'TTL 修改未确认，检查 DNS 编辑权限']);
                return;
            }
            if ($event->result['pause_requested'] ?? false) {
                $group->update(['enabled' => false, 'state' => 'paused', 'reason' => null]);
                $event->update(['status' => 'cancelled', 'message' => 'TTL 已确认，管理员暂停，自动化保持关闭']);
                return;
            }
            try { $this->assertConfiguration($group); }
            catch (AutomationException $e) { $event->update(['status' => 'failed', 'message' => $e->getMessage()]); return; }
            if (($event->result['config_hash'] ?? '') !== $group->config_hash) throw new AutomationException('配置已变化，停止启用');
            $group->update(['enabled' => true, 'state' => 'healthy', 'failures' => 0, 'reason' => null]);
            $event->update(['status' => 'completed', 'message' => '基线和备用验证通过，TTL 60 秒，自动化已开启']);
            return;
        }
        // Atomic local commit; repeat recovery must not reset cooldown or quarantine again.
        DB::transaction(function () use ($group, $event, $record) {
            if ($group->current_ip !== $event->to_ip) {
                $old = Backup::firstOrCreate(['group_id' => $group->id, 'ip' => $event->from_ip],
                    ['name' => '原 VPS ' . $event->from_ip, 'priority' => 999]);
                $old->update(['enabled' => false, 'state' => 'needs_review', 'reason' => '切换后的原 VPS，需检查并手动重新加入']);
                Backup::where('group_id', $group->id)->where('ip', $event->to_ip)->update(['state' => 'active']);
                $oldTtl = (int) ($event->record_before['ttl'] ?? 300);
                $group->update(['current_ip' => $event->to_ip, 'failures' => 0, 'last_switched_at' => now(),
                    'cooldown_until' => now()->addSeconds(config('node_automation.cooldown_seconds', 600)),
                    'propagation_until' => now()->addSeconds($oldTtl === 1 ? 300 : max(60, $oldTtl)),
                    'state' => 'propagating', 'reason' => null]);
            }
            $event->update(['status' => 'verifying']);
        });
        if ($event->result['pause_requested'] ?? false) {
            $group->update(['enabled' => false, 'state' => 'paused', 'reason' => 'DNS 已确认，请手动测试当前 VPS']);
            $event->update(['status' => 'cancelled', 'message' => 'DNS 已确认，管理员暂停后续检测和切换']);
            return;
        }
        try { $this->assertConfiguration($group); }
        catch (AutomationException $e) {
            $group->update(['enabled' => false, 'state' => 'config_error', 'reason' => $e->getMessage()]);
            $event->update(['status' => 'failed', 'message' => 'DNS 已确认，但测试配置失效，请重新配置']);
            return;
        }
        try {
            $targets = $this->probe->targets();
            if ($this->observe($group, $group->current_ip, $targets)['ok']) {
                $event->update(['status' => 'completed', 'message' => 'DNS 回读及新 IP 代理测试通过，等待客户端 DNS 缓存更新']);
                $group->update(['state' => 'propagating', 'reason' => null]);
                return;
            }
            $this->verifyTargets($targets);
        } catch (AutomationException $e) {
            $event->update(['status' => 'verifying', 'message' => 'DNS 已修改，新 IP 验证暂不可执行，等待下一轮']);
            return;
        }
        $event->update(['status' => 'failed', 'message' => 'DNS 已修改，但新 VPS 代理测试失败']);
        Backup::where('group_id', $group->id)->where('ip', $group->current_ip)->update(['state' => 'needs_review', 'enabled' => false]);
        if (!$group->enabled && $event->action !== 'manual') {
            $group->update(['state' => 'paused', 'reason' => '新 VPS 验证失败，策略已暂停']);
            return;
        }
        // Recovery bypasses normal cooldown, never returns to a known failed VPS.
        $group->update(['cooldown_until' => null]);
        $next = Event::create(['group_id' => $group->id, 'operation_id' => (string) Str::uuid(),
            'action' => $event->action, 'status' => 'running', 'admin_id' => $event->admin_id]);
        $this->switch($group, $targets, $next, $event->action === 'manual');
    }

    protected function verifyTargets(array $targets): void
    {
        if ($targets !== $this->probe->targets()) throw new AutomationException('检测目标状态变化，本轮不计节点故障');
    }
}
