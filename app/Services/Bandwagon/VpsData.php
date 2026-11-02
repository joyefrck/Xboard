<?php

namespace App\Services\Bandwagon;

class VpsData
{
    public static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 1e18 ? (float) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, 255) : null;
    }

    private static function flag(mixed $value): ?bool
    {
        return in_array($value, [0, '0', false], true) ? false : (in_array($value, [1, '1', true], true) ? true : null);
    }

    public static function service(array $data): array
    {
        if (!is_array($data['ip_addresses'] ?? null) || self::number($data['data_counter'] ?? null) === null ||
            self::number($data['plan_monthly_data'] ?? null) === null) {
            throw new BandwagonException('搬瓦工基础信息不完整，保留上次数据');
        }
        $info = [];
        foreach (['hostname', 'vm_type', 'node_alias', 'node_location', 'plan', 'os'] as $key) $info[$key] = self::text($data[$key] ?? null);
        foreach (['plan_disk', 'plan_ram', 'plan_swap', 'plan_monthly_data', 'data_counter', 'data_next_reset'] as $key) $info[$key] = self::number($data[$key] ?? null);
        $info['monthly_data_multiplier'] = self::number($data['monthly_data_multiplier'] ?? 1);
        if (!$info['monthly_data_multiplier']) throw new BandwagonException('搬瓦工流量倍率异常，保留上次数据');
        $info['suspended'] = self::flag($data['suspended'] ?? null);
        $info['ip_addresses'] = array_values(array_unique(array_filter($data['ip_addresses'], fn ($ip) => is_string($ip) &&
            (filter_var($ip, FILTER_VALIDATE_IP) || (str_contains($ip, '/') && preg_match('/^[a-fA-F0-9:]+\/(?:[0-9]|[1-9][0-9]|1[01][0-9]|12[0-8])$/D', $ip))))));
        return $info;
    }

    public static function live(array $data): array
    {
        if (!array_intersect(['ve_status', 'vz_status', 'load_average', 'mem_available_kb', 've_used_disk_space_b'], array_keys($data))) {
            throw new BandwagonException('搬瓦工实时状态不完整，保留上次数据');
        }
        $live = [];
        $live['ve_status'] = self::text($data['ve_status'] ?? null);
        if (!in_array($live['ve_status'], ['Starting', 'Running', 'Stopped'], true)) $live['ve_status'] = null;
        foreach (['load_average', 'ssh_port'] as $key) $live[$key] = self::text($data[$key] ?? null);
        foreach (['ve_used_disk_space_b', 've_disk_quota_gb', 'mem_available_kb', 'swap_total_kb', 'swap_available_kb'] as $key) $live[$key] = self::number($data[$key] ?? null);
        foreach (['is_cpu_throttled', 'is_disk_throttled'] as $key) $live[$key] = self::flag($data[$key] ?? null);
        // OVZ reports selected metrics under vz_status, not a KVM ve_status.
        if (is_array($data['vz_status'] ?? null)) {
            $live['load_average'] ??= self::text($data['vz_status']['loadavg'] ?? null);
        }
        return $live;
    }

    public static function usage(?array $info): ?array
    {
        if (!$info) return null;
        $used = self::number($info['data_counter'] ?? null);
        $total = self::number($info['plan_monthly_data'] ?? null);
        $multiplier = self::number($info['monthly_data_multiplier'] ?? 1);
        if ($used === null || !$total || !$multiplier) return null;
        return ['used_bytes' => $used * $multiplier, 'total_bytes' => $total * $multiplier,
            'remaining_bytes' => max(0, $total - $used) * $multiplier, 'percent' => round($used / $total * 100, 2),
            'reset_at' => $info['data_next_reset'] ?? null];
    }

    public static function ipKey(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        return $packed === false ? null : bin2hex($packed);
    }
}
