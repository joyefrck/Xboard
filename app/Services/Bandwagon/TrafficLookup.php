<?php

namespace App\Services\Bandwagon;

use App\Models\BandwagonVps;
use Illuminate\Support\Facades\Schema;

class TrafficLookup
{
    /** Called once per automation fetch, never makes supplier HTTP requests. */
    public function index(): array
    {
        // Rolling upgrades may serve the old database before the migration runs.
        if (!Schema::hasTable('v2_bandwagon_vps')) return [];
        $index = [];
        foreach (BandwagonVps::all(['id', 'veid', 'name', 'info', 'synced_at', 'last_error']) as $vps) {
            if (!$vps->synced_at) continue;
            foreach ($vps->info['ip_addresses'] ?? [] as $ip) {
                $key = VpsData::ipKey($ip);
                if (!$key) continue;
                $value = ['status' => 'matched', 'id' => $vps->id, 'veid' => $vps->veid, 'name' => $vps->name,
                    'usage' => VpsData::usage($vps->info), 'synced_at' => $vps->synced_at->toISOString(),
                    'stale' => $vps->synced_at->lt(now()->subMinutes(15)), 'error' => $vps->last_error];
                $previous = $index[$key] ?? null;
                $index[$key] = $previous && ($previous['status'] !== 'matched' || $previous['id'] !== $vps->id)
                    ? ['status' => 'conflict'] : $value;
            }
        }
        return $index;
    }

    public function find(array $index, ?string $ip): array
    {
        return $index[VpsData::ipKey($ip ?? '') ?? ''] ?? ['status' => 'unmatched'];
    }
}
