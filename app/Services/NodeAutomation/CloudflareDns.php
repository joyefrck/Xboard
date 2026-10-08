<?php

namespace App\Services\NodeAutomation;

use App\Models\NodeAutomationConnection;
use Illuminate\Support\Facades\Http;

class CloudflareDns
{
    public static function isPublicIpv4(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        [$a, $b, $c] = array_map('intval', explode('.', $ip));
        return $a < 224 && !($a === 100 && $b >= 64 && $b <= 127) &&
            !($a === 192 && $b === 0 && in_array($c, [0, 2], true)) &&
            !($a === 198 && (in_array($b, [18, 19], true) || ($b === 51 && $c === 100))) &&
            !($a === 203 && $b === 0 && $c === 113);
    }

    protected function request(NodeAutomationConnection $connection, string $method, string $path, array $params = []): array
    {
        try {
            $client = Http::withToken($connection->api_token)->acceptJson()->connectTimeout(3)->timeout(10);
            $response = $method === 'GET'
                ? $client->get('https://api.cloudflare.com/client/v4/' . $path, $params)
                : $client->patch('https://api.cloudflare.com/client/v4/' . $path, $params);
            if (!$response->successful() || $response->json('success') !== true) {
                if ($method === 'PATCH' && $response->status() < 500 &&
                    ($response->status() >= 400 || $response->json('success') === false)) {
                    throw new DnsWriteRejectedException('Cloudflare 拒绝 DNS 修改，请检查编辑权限、Token 或限流状态');
                }
                throw new AutomationException('Cloudflare 请求失败，请检查 Token 权限、有效期或限流状态');
            }
            return $response->json();
        } catch (AutomationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AutomationException('Cloudflare 请求超时或暂时不可用');
        }
    }

    public function verify(NodeAutomationConnection $connection): string
    {
        $zones = $this->request($connection, 'GET', 'zones', ['name' => $connection->zone_name, 'status' => 'active'])['result'] ?? [];
        if (count($zones) !== 1 || $zones[0]['name'] !== $connection->zone_name) {
            throw new AutomationException('未找到唯一的有效 Zone，请授予指定域名的 Zone 读取权限');
        }
        $zone = $zones[0]['id'];
        $this->request($connection, 'GET', "zones/{$zone}/dns_records", ['per_page' => 1]);
        return $zone;
    }

    public function match(NodeAutomationConnection $connection, string $domain): array
    {
        if (!$connection->zone_id || !$connection->verified_at ||
            ($domain !== $connection->zone_name && !str_ends_with($domain, '.' . $connection->zone_name))) {
            throw new AutomationException('域名不属于已验证的 Cloudflare Zone');
        }
        $response = $this->request($connection, 'GET', "zones/{$connection->zone_id}/dns_records", ['name' => $domain, 'per_page' => 100]);
        $records = array_values(array_filter($response['result'] ?? [], fn ($r) => in_array($r['type'] ?? '', ['A', 'AAAA', 'CNAME'], true)));
        if (count($records) !== 1 || ($response['result_info']['total_count'] ?? 0) > 100) {
            throw new DnsConflictException('DNS 冲突：必须只有一条 A 记录，不能同时存在 AAAA、CNAME 或多条 A');
        }
        return $this->validate($records[0], $domain);
    }

    public function read(NodeAutomationConnection $connection, string $recordId, string $domain): array
    {
        // Re-match as well as reading the bound ID, detecting newly added AAAA/A records.
        $record = $this->match($connection, $domain);
        if ($record['id'] !== $recordId) throw new DnsConflictException('DNS 记录已被外部替换，请重新匹配');
        return $record;
    }

    public function patch(NodeAutomationConnection $connection, string $recordId, array $changes): void
    {
        $this->request($connection, 'PATCH', "zones/{$connection->zone_id}/dns_records/{$recordId}", $changes);
    }

    protected function validate(array $record, string $domain): array
    {
        if (($record['name'] ?? '') !== $domain || ($record['type'] ?? '') !== 'A' ||
            ($record['proxied'] ?? true) || !self::isPublicIpv4($record['content'] ?? '')) {
            throw new DnsConflictException('仅支持未代理的公网 IPv4 A 记录');
        }
        return array_intersect_key($record, array_flip(['id', 'name', 'type', 'content', 'proxied', 'ttl', 'comment', 'tags']));
    }
}
