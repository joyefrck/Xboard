<?php

namespace App\Services\Bandwagon;

use App\Jobs\BandwagonSyncJob;
use App\Models\BandwagonVps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class InventoryService
{
    public function __construct(protected KiwiVmClient $client) {}

    public function locked(string $veid, callable $action): mixed
    {
        $lock = Cache::lock('bandwagon:vps:' . $veid, 65);
        if (!$lock->get()) throw new BandwagonException('此 VPS 正在处理请求，请稍后再试');
        try { return $action(); } finally { $lock->release(); }
    }

    public function import(array $rows): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'busy' => 0];
        foreach ($rows as $row) {
            if ($row['status'] !== 'valid') { $result['skipped']++; continue; }
            try {
                $this->locked($row['veid'], function () use ($row, &$result) {
                    $vps = BandwagonVps::firstOrNew(['veid' => $row['veid']]);
                    $created = !$vps->exists;
                    if (!$created && !hash_equals($vps->api_key, $row['api_key'])) {
                        $vps->fill(['info' => null, 'live' => null, 'synced_at' => null, 'live_synced_at' => null,
                            'queued_at' => null, 'sync_token' => null, 'attempted_at' => null, 'last_error' => null, 'live_error' => null]);
                    }
                    $vps->fill(['api_key' => $row['api_key'], 'hostname' => $row['hostname'],
                        'vm_type' => $row['vm_type'], 'imported_ip' => $row['ip'] ?: null])->save();
                    $result[$created ? 'created' : 'updated']++;
                    $this->enqueueUnlocked($vps, true);
                });
            } catch (BandwagonException $e) { $result['busy']++; }
        }
        return $result;
    }

    public function enqueue(int $id, bool $automatic = false): bool
    {
        if ($automatic && !SyncSettings::enabled()) return false;
        $vps = BandwagonVps::find($id);
        if (!$vps) return false;
        return $this->locked($vps->veid, function () use ($vps, $automatic) {
            $current = $vps->fresh();
            return $current ? $this->enqueueUnlocked($current, $automatic) : false;
        });
    }

    private function enqueueUnlocked(BandwagonVps $vps, bool $automatic = false): bool
    {
        if ($automatic && !SyncSettings::enabled()) return false;
        if ($vps->queued_at?->gt(now()->subMinutes(10)) || $vps->attempted_at?->gt(now()->subSeconds(30))) return false;
        $token = (string) Str::uuid();
        $vps->update(['queued_at' => now(), 'sync_token' => $token]);
        try { BandwagonSyncJob::dispatch($vps->id, $token, $automatic); }
        catch (\Throwable $e) {
            $vps->update(['queued_at' => null, 'sync_token' => null, 'last_error' => '同步任务提交失败，请检查队列后重试']);
            throw new BandwagonException('同步任务提交失败，请检查队列后重试');
        }
        return true;
    }

    public function sync(int $id, string $token, bool $automatic = false): void
    {
        if ($automatic && !SyncSettings::enabled()) {
            // Cancellation need not wait for a password/manual operation's lock.
            // Compare the token atomically so newer work cannot be cancelled.
            BandwagonVps::whereKey($id)->where('sync_token', $token)->update(['queued_at' => null, 'sync_token' => null]);
            return;
        }
        $vps = BandwagonVps::find($id);
        if (!$vps) return;
        $this->locked($vps->veid, function () use ($vps, $token, $automatic) {
            $vps->refresh();
            if ($vps->sync_token !== $token) return;
            if ($automatic && !SyncSettings::enabled()) {
                $vps->update(['queued_at' => null, 'sync_token' => null]);
                return;
            }
            $vps->update(['attempted_at' => now()]);
            try {
                $info = VpsData::service($this->client->call($vps, 'getServiceInfo'));
                $vps->update(['info' => $info, 'synced_at' => now(), 'last_error' => null,
                    'hostname' => $info['hostname'] ?? $vps->hostname, 'vm_type' => $info['vm_type'] ?? $vps->vm_type]);
                if ($automatic && !SyncSettings::enabled()) return;
                try {
                    $vps->update(['live' => VpsData::live($this->client->call($vps, 'getLiveServiceInfo')),
                        'live_synced_at' => now(), 'live_error' => null]);
                } catch (BandwagonException $e) { $vps->update(['live_error' => $e->getMessage()]); }
            } catch (BandwagonException $e) { $vps->update(['last_error' => $e->getMessage()]); }
            finally { $vps->update(['queued_at' => null, 'sync_token' => null]); }
        });
    }

    /** Returns the password once; ambiguous outcomes are never automatically retried. */
    public function resetPassword(int $id, string $confirmation): array
    {
        $vps = BandwagonVps::findOrFail($id);
        return $this->locked($vps->veid, function () use ($vps, $confirmation) {
            $vps->refresh();
            if (!hash_equals($vps->veid, trim($confirmation))) throw new BandwagonException('请输入此 VPS 的 VEID 确认重置');
            if ($vps->reset_requested_at?->gt(now()->subMinutes(5))) throw new BandwagonException('重置请求已提交，五分钟内不能再次提交；请先核实上次结果');
            $vps->update(['reset_requested_at' => now(), 'reset_state' => 'pending']);
            try {
                $data = $this->client->call($vps, 'resetRootPassword');
                if (!is_string($data['password'] ?? null) || $data['password'] === '' || strlen($data['password']) > 1024) {
                    throw new BandwagonException('重置响应未包含新密码');
                }
                $vps->update(['reset_state' => 'completed']);
                return ['password' => $data['password'], 'veid' => $vps->veid];
            } catch (BandwagonException $e) {
                $vps->update(['reset_state' => 'unknown']);
                throw new BandwagonException('重置结果未确认，请到 KiwiVM 核实；系统不会自动重试');
            }
        });
    }
}
