<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Jobs\NodeAutomationJob;
use App\Models\{NodeAutomationConnection as Connection, NodeAutomationGroup as Group,
    NodeAutomationBackup as Backup, NodeAutomationLog as Event, NodeAutomationCheck as Observation,
    NodeAutomationSetting as Setting, Server, ServerGroup, User};
use App\Services\NodeAutomation\{AutomationService, AutomationException, CloudflareDns, ProxyProbe};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;

class AutomationController extends Controller
{
    public function __construct(protected AutomationService $service, protected CloudflareDns $dns) {}

    private function respond(callable $action)
    {
        try { return $this->success($action()); }
        catch (AutomationException $e) { return $this->fail([422, $e->getMessage()]); }
    }

    public function fetch()
    {
        return $this->respond(function () {
            $nodes = Server::all(['id', 'code', 'name', 'host', 'port', 'type', 'group_ids']);
            $domains = $nodes->groupBy(fn ($node) => strtolower(rtrim($node->host, '.')))
                ->filter(fn ($items, $domain) => $this->validDomain($domain));
            $groups = Group::with('backups')->get()->keyBy('domain');
            $pending = Event::whereIn('status', ['queued', 'running', 'prepared', 'verifying', 'dns_unknown'])
                ->oldest('id')->get(['id', 'group_id', 'action', 'status', 'message'])->keyBy('group_id');
            $checks = Observation::all()->groupBy('group_id');
            $rows = [];
            foreach ($domains as $domain => $items) {
                $group = $groups->get($domain);
                $rows[] = array_merge($group?->toArray() ?? ['id' => null, 'domain' => $domain, 'enabled' => false,
                    'state' => 'unconfigured', 'failures' => 0, 'backups' => []],
                    ['pending_operation' => $group ? $pending->get($group->id) : null,
                        'latest_check' => $group ? $checks->get($group->id)?->firstWhere('ip', $group->current_ip) : null,
                        'nodes' => $items->map(fn ($n) => ['id' => $n->id, 'display_id' => $n->code ?: $n->id,
                        'name' => $n->name, 'port' => $n->port, 'type' => $n->type, 'group_ids' => $n->group_ids])->values()->all()]);
            }
            $mapping = Setting::find(1)?->probe_users_by_group ?? [];
            $users = User::whereIn('id', array_values($mapping))->get(['id', 'email', 'group_id'])->keyBy('id');
            $accounts = [];
            foreach ($mapping as $groupId => $userId) {
                $user = $users->get($userId);
                $accounts[] = ['group_id' => (int) $groupId, 'id' => (int) $userId, 'email' => $user?->email];
            }
            return ['connection' => Connection::first(), 'groups' => $rows,
                'probe_accounts' => $accounts, 'permission_groups' => ServerGroup::orderBy('id')->get(['id', 'name']),
                'interval' => 60, 'threshold' => 3, 'cooldown' => 600];
        });
    }

    private function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253 && !filter_var($domain, FILTER_VALIDATE_IP) &&
            str_contains($domain, '.') && (bool) filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    public function connectionSave(Request $request)
    {
        $data = $request->validate(['zone_name' => 'required|string|max:253', 'api_token' => 'nullable|string|min:16|max:512']);
        return $this->respond(function () use ($data) {
            $lock = Cache::lock('node-automation:connection', 30);
            if (!$lock->get()) throw new AutomationException('连接配置正在更新');
            $locks = [];
            try {
                foreach (Group::orderBy('id')->get() as $group) {
                    $groupLock = Cache::lock('node-automation:group:' . $group->id, 600);
                    if (!$groupLock->get()) throw new AutomationException('请等待检测任务完成后再更新连接');
                    $locks[] = $groupLock;
                    $this->service->requireEditable($group);
                }
                $zone = strtolower(rtrim($data['zone_name'], '.'));
                if (!$this->validDomain($zone)) throw new AutomationException('Zone 域名格式不正确');
                $connection = Connection::first() ?? new Connection();
                if (!empty($data['api_token'])) $connection->api_token = trim($data['api_token']);
                if (!$connection->api_token) throw new AutomationException('首次配置必须填写 API Token');
                $connection->zone_name = $zone;
                $connection->zone_id = null;
                $connection->verified_at = null;
                $connection->save();
                Group::query()->update(['record_id' => null, 'state' => 'unconfigured', 'reason' => '连接已更新，请验证并重新匹配']);
                return $connection;
            } finally {
                foreach ($locks as $groupLock) $groupLock->release();
                $lock->release();
            }
        });
    }

    public function connectionVerify()
    {
        return $this->respond(function () {
            $lock = Cache::lock('node-automation:connection', 30);
            if (!$lock->get()) throw new AutomationException('连接配置正在更新');
            try {
                $connection = Connection::first();
                if (!$connection) throw new AutomationException('请先保存 Cloudflare 连接');
                $connection->update(['zone_id' => $this->dns->verify($connection), 'verified_at' => now()]);
                return $connection;
            } finally { $lock->release(); }
        });
    }

    public function save(Request $request)
    {
        $data = $request->validate(['domain' => 'required|string|max:253', 'probe_users' => 'prohibited']);
        return $this->respond(function () use ($data) {
            $domain = strtolower(rtrim($data['domain'], '.'));
            if (!$this->validDomain($domain) || !Server::all(['host'])->contains(fn ($n) => strtolower(rtrim($n->host, '.')) === $domain)) {
                throw new AutomationException('只能配置节点管理中已有的域名');
            }
            $connection = Connection::first();
            if (!$connection?->verified_at) throw new AutomationException('请先验证 Cloudflare 连接');
            $group = Group::firstOrCreate(['domain' => $domain]);
            return $this->service->locked($group->id, function (Group $group) use ($data, $connection) {
                $this->service->requireEditable($group);
                $group->connection_id = $connection->id;
                $group->config_hash = $this->service->configurationHash($group);
                $group->save();
                Backup::where('group_id', $group->id)->whereNotIn('state', ['active', 'needs_review'])
                    ->update(['state' => 'unchecked', 'tested_at' => null, 'tested_hash' => null]);
                $this->service->reconcileBinding($group);
                return $group;
            });
        });
    }

    public function probeUserSave(Request $request)
    {
        $data = $request->validate(['group_id' => 'required|integer|min:1|exists:v2_server_group,id',
            'user_id' => 'present|nullable|integer|min:1|exists:v2_user,id']);
        return $this->respond(function () use ($data) {
            $lock = Cache::lock('node-automation:connection', 30);
            if (!$lock->get()) throw new AutomationException('公共配置正在更新');
            $locks = [];
            try {
                foreach (Group::orderBy('id')->get() as $group) {
                    $groupLock = Cache::lock('node-automation:group:' . $group->id, 600);
                    if (!$groupLock->get()) throw new AutomationException('请等待检测任务完成后再更新公共检测账号');
                    $locks[] = $groupLock;
                    $this->service->requireEditable($group);
                }
                return DB::transaction(function () use ($data) {
                    $setting = Setting::find(1) ?? new Setting();
                    $mapping = $setting->probe_users_by_group ?? [];
                    $groupId = (int) $data['group_id'];
                    $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
                    $changed = ($mapping[$groupId] ?? null) !== $userId;
                    if ($userId) $mapping[$groupId] = $userId;
                    else unset($mapping[$groupId]);
                    $setting->forceFill(['id' => 1, 'probe_user_id' => null, 'probe_users_by_group' => $mapping])->save();
                    $user = $userId ? (new ProxyProbe())->account($groupId) : null;
                    if ($changed) {
                        Group::query()->update(['config_hash' => null, 'failures' => 0,
                            'reason' => '公共检测账号已更新，请重新验证配置和备用']);
                        Backup::whereNotIn('state', ['active', 'needs_review'])
                            ->update(['state' => 'unchecked', 'tested_at' => null, 'tested_hash' => null]);
                        Observation::query()->delete();
                    }
                    return ['group_id' => $groupId, 'account' => $user ? ['id' => $user->id, 'email' => $user->email] : null];
                });
            } finally {
                foreach ($locks as $groupLock) $groupLock->release();
                $lock->release();
            }
        });
    }

    public function match(Request $request)
    {
        $id = $request->validate(['id' => 'required|integer|exists:v2_node_automation_groups,id'])['id'];
        return $this->respond(fn () => $this->service->locked($id, fn ($g) => $this->service->reconcileBinding($g)));
    }

    public function users(Request $request)
    {
        $data = $request->validate(['search' => 'required|string|min:1|max:100',
            'group_id' => 'required|integer|min:1|exists:v2_server_group,id']);
        // This endpoint returns identifiers for admin selection, never auth material.
        $users = User::query()->where('group_id', $data['group_id'])->where(function ($query) use ($data) {
            $query->where('email', 'like', '%' . $data['search'] . '%')
                ->orWhere('id', ctype_digit($data['search']) ? (int) $data['search'] : -1);
        })->limit(20)->get(['id', 'email', 'banned', 'expired_at', 'group_id']);
        return $this->success($users);
    }

    private function publicIpv4(string $ip): bool
    {
        return CloudflareDns::isPublicIpv4($ip);
    }

    public function backupSave(Request $request)
    {
        $data = $request->validate(['group_id' => 'required|integer|exists:v2_node_automation_groups,id',
            'id' => 'nullable|integer', 'name' => 'required|string|max:80', 'ip' => 'required|ipv4',
            'priority' => 'required|integer|min:0|max:999', 'enabled' => 'required|boolean', 'rearm' => 'sometimes|boolean']);
        return $this->respond(fn () => $this->service->locked($data['group_id'], function (Group $group) use ($data) {
            $this->service->requireEditable($group);
            if (!$this->publicIpv4($data['ip'])) throw new AutomationException('备用必须填写公网 IPv4 地址');
            if ($data['ip'] === $group->current_ip) throw new AutomationException('当前 VPS 不能作为备用重新加入');
            $backup = isset($data['id']) ? $group->backups()->findOrFail($data['id']) : new Backup(['group_id' => $group->id]);
            if ((!$backup->exists || $backup->state === 'needs_review') && $group->backups()
                ->whereNotIn('state', ['needs_review', 'active'])->count() >= 10) throw new AutomationException('最多配置 10 台备用');
            if ($group->backups()->where('ip', $data['ip'])->when($backup->exists, fn ($q) => $q->where('id', '!=', $backup->id))->exists()) throw new AutomationException('该 IP 已存在于备用池');
            if ($backup->state === 'needs_review' && empty($data['rearm'])) throw new AutomationException('原 VPS 需要检查后明确重新加入备用');
            $backup->fill(array_intersect_key($data, array_flip(['name', 'ip', 'priority', 'enabled'])));
            $backup->state = 'unchecked'; $backup->tested_at = null; $backup->tested_hash = null; $backup->reason = null;
            $backup->save();
            return $backup;
        }));
    }

    public function backupDrop(Request $request)
    {
        $data = $request->validate(['group_id' => 'required|integer|exists:v2_node_automation_groups,id', 'id' => 'required|integer']);
        return $this->respond(fn () => $this->service->locked($data['group_id'], function (Group $group) use ($data) {
            $this->service->requireEditable($group);
            $backup = $group->backups()->findOrFail($data['id']);
            if ($backup->ip === $group->current_ip) throw new AutomationException('不能删除当前 VPS');
            Observation::where('group_id', $group->id)->where('ip', $backup->ip)->delete();
            return $backup->delete();
        }));
    }

    public function pause(Request $request)
    {
        $id = $request->validate(['id' => 'required|integer|exists:v2_node_automation_groups,id'])['id'];
        return $this->respond(fn () => $this->service->locked($id, function (Group $group) {
            // A prepared DNS intent must be reconciled even when the policy is paused.
            $this->service->pause($group);
            return $group;
        }));
    }

    public function operation(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_node_automation_groups,id',
            'action' => 'required|in:enable,test,manual', 'backup_id' => 'nullable|integer', 'request_id' => 'required|uuid']);
        return $this->respond(fn () => $this->service->locked($data['id'], function (Group $group) use ($data, $request) {
            $existing = Event::where('operation_id', $data['request_id'])->first();
            if ($existing) {
                if ((int) $existing->group_id !== $group->id || $existing->action !== $data['action'] ||
                    (int) $existing->backup_id !== (int) ($data['backup_id'] ?? 0)) throw new AutomationException('操作标识与请求不匹配');
                return $existing;
            }
            if ($this->service->pending($group)) throw new AutomationException('已有任务执行中，请等待结果');
            if ($data['action'] === 'enable' && $group->enabled) throw new AutomationException('策略已开启');
            if (in_array($data['action'], ['test', 'manual'], true)) {
                if (empty($data['backup_id'])) throw new AutomationException('请选择备用 VPS');
                $backup = $group->backups()->findOrFail($data['backup_id']);
                if ($data['action'] === 'manual' && (!$backup->enabled || in_array($backup->state, ['needs_review', 'active'], true))) {
                    throw new AutomationException('该 VPS 未启用或需要检查后重新加入');
                }
            }
            $event = Event::create(['group_id' => $group->id, 'operation_id' => $data['request_id'],
                'action' => $data['action'], 'backup_id' => $data['backup_id'] ?? null,
                'admin_id' => $request->user()?->id, 'status' => 'queued']);
            NodeAutomationJob::dispatch($group->id, time(), $event->id);
            return $event;
        }));
    }

    public function logs(Request $request)
    {
        $data = $request->validate(['id' => 'sometimes|integer|min:1', 'page' => 'sometimes|integer|min:1']);
        return $this->success(Event::query()
            ->leftJoin('v2_node_automation_groups as groups', 'groups.id', '=', 'v2_node_automation_logs.group_id')
            ->select('v2_node_automation_logs.*', 'groups.domain')
            ->when(isset($data['id']), fn ($query) => $query->where('v2_node_automation_logs.group_id', $data['id']))
            ->orderByDesc('v2_node_automation_logs.id')->paginate(20, ['*'], 'page', $data['page'] ?? 1));
    }
}
