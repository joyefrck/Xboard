<?php
namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Cache\Repository;

class SubscriptionBehaviorSnapshotService
{
    private \Closure $setting;
    private \Closure $queueHealth;
    public function __construct(private ConnectionInterface $db, private Repository $cache, private array $config, ?callable $setting = null, ?callable $queueHealth = null)
    {
        $this->setting = \Closure::fromCallable($setting ?? fn($key, $default) => admin_setting($key, $default));
        $this->queueHealth = \Closure::fromCallable($queueHealth ?? fn($now, $maxAge) => (new SubscriptionBehaviorTrafficHealth())->queueHealthy($now, $maxAge));
    }

    public function identities(int $at): array
    {
        $users = [];
        // Do not select email, UUID, credentials or subscription URLs.
        foreach ($this->db->table('v2_user')->select(['id', 'token', 'banned', 'is_admin', 'is_staff'])->orderBy('id')->cursor() as $user) {
            if (!is_string($user->token) || $user->token === '') continue;
            $hash = hash('sha256', $user->token);
            $entry = ['id' => (int) $user->id, 'excluded' => (int) $user->id === $this->config['owner_user_id'] || (bool) $user->banned || (bool) $user->is_admin || (bool) $user->is_staff];
            // Shared tokens cannot safely be attributed to either account.
            $users[$hash] = isset($users[$hash]) ? ['id' => 0, 'excluded' => true] : $entry;
            unset($user->token);
        }
        $ips = $this->config['known_node_ips'];
        foreach ($this->db->table('v2_server')->select(['host'])->cursor() as $node) {
            if (filter_var($node->host, FILTER_VALIDATE_IP)) $ips[] = $node->host;
        }
        if ($this->db->getSchemaBuilder()->hasTable('v2_node_automation_backups')) {
            foreach ($this->db->table('v2_node_automation_backups')->select(['ip'])->cursor() as $node) {
                if (filter_var($node->ip, FILTER_VALIDATE_IP)) $ips[] = $node->ip;
            }
        }
        $path = '/' . trim((string) ($this->setting)('subscribe_path', 's'), '/');
        if (!preg_match('~^/[A-Za-z0-9_/-]+$~D', $path)) throw new \RuntimeException('invalid_subscription_path');
        return ['version' => 1, 'config' => $this->config, 'subscribe_path' => $path, 'users' => (object) $users, 'known_node_ips' => array_values(array_unique($ips)), 'last_success_at' => $this->lastSuccessAt()];
    }

    private function lastSuccessAt(): ?int
    {
        $value = $this->cache->get('subscription-behavior:last-success-at');
        if ($value === null) return null;
        if (is_int($value) && $value > 0) return $value;
        // Laravel RedisStore deliberately returns numeric values without unserializing.
        // Normalize only decimal integers; never coerce booleans, floats or corrupt state.
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $maximum = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0)) return (int) $value;
        }
        throw new \RuntimeException('invalid_last_success_timestamp');
    }

    public function traffic(array $ids, int $at): array
    {
        $date = (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($this->config['timezone']));
        $start = $date->setTime(0, 0)->modify('-6 days')->getTimestamp();
        $end = $date->setTime(0, 0)->modify('+1 day')->getTimestamp();
        // --at selects historical traffic only; pipeline health always uses actual time.
        $now = time();
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($this->config['timezone']))->setTime(0, 0);
        $maxAge = $this->config['traffic_health_max_age_seconds'];
        $recentUpdate = $this->db->table('v2_stat_user')->where('record_type', 'd')->where('record_at', '>=', $today->modify('-1 day')->getTimestamp())->where('record_at', '<', $today->modify('+1 day')->getTimestamp())->max('updated_at');
        $statisticsRecent = is_numeric($recentUpdate) && (int) $recentUpdate > 0 && (int) $recentUpdate <= $now + 300 && $now - (int) $recentUpdate <= $maxAge;
        $queueHealthy = ($this->queueHealth)($now, $maxAge);
        if (!is_bool($queueHealthy)) throw new \RuntimeException('invalid_statistics_queue_health');
        $healthy = $statisticsRecent && $queueHealthy;
        $traffic = [];
        foreach ($ids as $id) $traffic[$id] = ['bytes7d' => null, 'known' => false, 'last_updated' => null];
        if ($ids && $healthy) {
            $rows = $this->db->table('v2_stat_user')->whereIn('user_id', $ids)->where('record_type', 'd')->where('record_at', '>=', $start)->where('record_at', '<', $end)->selectRaw('user_id, SUM(u + d) AS bytes7d, MAX(updated_at) AS last_updated')->groupBy('user_id')->get();
            foreach ($rows as $row) {
                if ($row->bytes7d === null || (float) $row->bytes7d < 0 || (float) $row->bytes7d > PHP_INT_MAX) throw new \RuntimeException('invalid_traffic_statistics');
                $traffic[(int) $row->user_id] = ['bytes7d' => (int) $row->bytes7d, 'known' => true, 'last_updated' => (int) $row->last_updated];
            }
        }
        return ['traffic' => (object) $traffic, 'traffic_health' => ['healthy' => $healthy, 'statistics_recent' => $statisticsRecent, 'queue_healthy' => $queueHealthy]];
    }
}
