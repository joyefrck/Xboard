<?php

namespace App\Http\Controllers\V1;

use App\Models\{NodeAutomationBackup as Backup, NodeAutomationCheck as Observation,
    NodeAutomationGroup as Group, NodeAutomationLog as Event};
use App\Services\NodeAutomation\{AutomationException, AutomationService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\{Collection, Str};

/**
 * Node automation for the AI assistant: read state, add backups, test, enable and pause.
 * Manual DNS switching, deleting backups and connection/account settings stay admin-only.
 */
class NodeAutomationBotController
{
    public function __construct(private AutomationService $service) {}

    public function index(): JsonResponse
    {
        $domains = $this->service->domains();
        $groups = Group::with('backups')->get()->keyBy('domain');
        $pending = $this->pendingByGroup();
        $checks = Observation::all()->groupBy('group_id');
        return response()->json(['data' => $domains->map(function ($nodes, $domain) use ($groups, $pending, $checks) {
            $group = $groups->get($domain);
            return $this->domain($domain, $nodes, $group, $group ? $pending->get($group->id) : null,
                $group ? ($checks->get($group->id) ?? collect()) : collect(), false);
        })->values()]);
    }

    public function show(string $domain): JsonResponse
    {
        $domain = AutomationService::normalizeDomain($domain);
        $nodes = $this->service->domains()->get($domain) ?? $this->fail(404, '节点管理中没有这个域名');
        $group = Group::with('backups')->where('domain', $domain)->first();
        $pending = $group ? $this->pendingByGroup($group->id)->get($group->id) : null;
        $checks = $group ? Observation::where('group_id', $group->id)->get() : collect();
        return response()->json(['data' => $this->domain($domain, $nodes, $group, $pending, $checks, true)]);
    }

    public function storeBackup(Request $request, string $domain): JsonResponse
    {
        $data = $request->validate(['ip' => 'required|ipv4', 'name' => 'sometimes|nullable|string|max:80',
            'priority' => 'sometimes|nullable|integer|min:0|max:999']);
        $group = $this->group($domain);
        $backup = $this->run(fn () => $this->service->locked($group->id, function (Group $group) use ($data) {
            $existing = $group->backups()->where('ip', $data['ip'])->first();
            if ($existing) throw new HttpResponseException(response()->json([
                'message' => '该 IP 已存在于备用池', 'data' => $this->backup($existing)], 409));
            $priority = $data['priority'] ?? min(999, (int) $group->backups()
                ->whereNotIn('state', ['active', 'needs_review'])->max('priority') + 1);
            return $this->service->saveBackup($group, ['ip' => $data['ip'], 'name' => $data['name'] ?? 'Hermes ' . $data['ip'],
                'priority' => $priority, 'enabled' => true], true);
        }));
        return response()->json(['data' => $this->backup($backup)], 201);
    }

    public function test(Request $request, string $domain, string $ip): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $group = $this->group($domain);
        $backup = $group->backups()->where('ip', $ip)->first() ?? $this->fail(404, '备用池中没有这个 IP');
        return $this->queue($group, ['action' => 'test', 'backup_id' => $backup->id, 'request_id' => $key]);
    }

    public function enable(Request $request, string $domain): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $group = $this->group($domain);
        if (!$group->record_id || !$group->config_hash) {
            $this->fail(409, '请先在后台保存该域名配置并匹配 Cloudflare DNS');
        }
        return $this->queue($group, ['action' => 'enable', 'request_id' => $key]);
    }

    public function pause(string $domain): JsonResponse
    {
        $group = $this->group($domain);
        $group = $this->run(fn () => $this->service->locked($group->id, function (Group $group) {
            $this->service->pause($group);
            return $group;
        }));
        return response()->json(['data' => ['domain' => $group->domain, 'enabled' => $group->enabled, 'state' => $group->state]]);
    }

    public function operation(int $operation): JsonResponse
    {
        $event = Event::find($operation) ?? $this->fail(404, '操作不存在');
        return response()->json(['data' => $this->event($event)]);
    }

    private function queue(Group $group, array $data): JsonResponse
    {
        $event = $this->run(fn () => $this->service->locked($group->id,
            fn (Group $group) => $this->service->queueOperation($group, $data, null, config('node_automation.bot_actor', 'hermes'))));
        return response()->json(['data' => $this->event($event)], 202);
    }

    private function run(callable $action): mixed
    {
        try { return $action(); }
        catch (AutomationException $e) { $this->fail(422, $e->getMessage()); }
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }

    private function group(string $domain): Group
    {
        $domain = AutomationService::normalizeDomain($domain);
        $group = Group::where('domain', $domain)->first();
        if ($group) return $group;
        if ($this->service->domains()->has($domain)) $this->fail(409, '该域名尚未在后台配置自动化');
        $this->fail(404, '节点管理中没有这个域名');
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') return (string) Str::uuid();
        if (!Str::isUuid($key)) $this->fail(422, 'Idempotency-Key 必须是 UUID');
        return strtolower($key);
    }

    private function pendingByGroup(?int $groupId = null): Collection
    {
        return Event::whereIn('status', AutomationService::PENDING)->when($groupId, fn ($q) => $q->where('group_id', $groupId))
            ->oldest('id')->get()->groupBy('group_id')->map->first();
    }

    private function domain(string $domain, Collection $nodes, ?Group $group, ?Event $pending, Collection $checks, bool $detail): array
    {
        $checks = $checks->keyBy('ip');
        return [
            'domain' => $domain,
            'configured' => (bool) ($group?->record_id && $group?->config_hash),
            'enabled' => (bool) $group?->enabled,
            'state' => $group?->state ?? 'unconfigured',
            'reason' => $group?->reason,
            'current_ip' => $group?->current_ip,
            'failures' => (int) $group?->failures,
            'last_checked_at' => $group?->last_checked_at,
            'last_switched_at' => $group?->last_switched_at,
            'cooldown_until' => $group?->cooldown_until,
            'current_check' => $group?->current_ip ? $this->check($checks->get($group->current_ip), $detail) : null,
            'pending_operation' => $pending ? $this->event($pending) : null,
            'nodes' => $nodes->map(fn ($n) => ['id' => $n->id, 'display_id' => $n->code ?: $n->id,
                'name' => $n->name, 'port' => $n->port, 'type' => $n->type])->values(),
            'backups' => ($group?->backups ?? collect())->map(fn (Backup $b) => $this->backup($b)
                + ($detail ? ['latest_check' => $this->check($checks->get($b->ip), true)] : []))->values(),
        ];
    }

    private function backup(Backup $backup): array
    {
        return $backup->only(['id', 'name', 'ip', 'priority', 'enabled', 'state', 'reason', 'tested_at']);
    }

    private function check(?Observation $check, bool $nodes): ?array
    {
        if (!$check) return null;
        return ['status' => $check->status, 'message' => $check->message, 'checked_at' => $check->checked_at]
            + ($nodes ? ['nodes' => $check->nodes] : []);
    }

    private function event(Event $event): array
    {
        $result = $event->result ?? [];
        return [
            'id' => $event->id,
            'domain' => Group::whereKey($event->group_id)->value('domain'),
            'action' => $event->action,
            'status' => $event->status,
            'done' => in_array($event->status, AutomationService::FINISHED, true),
            'succeeded' => $event->status === 'completed',
            'message' => $event->message,
            'backup_ip' => $event->backup_id ? Backup::whereKey($event->backup_id)->value('ip') : null,
            'from_ip' => $event->from_ip,
            'to_ip' => $event->to_ip,
            'nodes' => $result['nodes'] ?? null,
            'actor' => $event->actor,
            'request_id' => $event->operation_id,
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
        ];
    }
}
