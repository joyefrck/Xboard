<?php
namespace App\Services;

use Illuminate\Support\Facades\Redis;

/** Read-only queue health; payloads are never returned, persisted or logged. */
class SubscriptionBehaviorTrafficHealth
{
    public function queueHealthy(int $now, int $maxAge): bool
    {
        $connection = (string) config('queue.connections.redis.connection', 'default');
        $retryAfter = (int) config('queue.connections.redis.retry_after', 0);
        if ($retryAfter <= 0) throw new \RuntimeException('invalid_statistics_queue_lease');
        $redis = Redis::connection($connection);
        $key = 'queues:stat';
        $head = $redis->lrange($key, 0, 0);
        $reserved = $redis->zrange($key . ':reserved', 0, 0);
        $score = $reserved ? $redis->zscore($key . ':reserved', $reserved[0]) : null;
        return $this->assess($head[0] ?? null, $score, (bool) $reserved, $retryAfter, $now, $maxAge);
    }

    public function assess(?string $readyPayload, mixed $reservedScore, bool $hasReserved, int $retryAfter, int $now, int $maxAge): bool
    {
        if ($retryAfter <= 0 || $maxAge <= 0) throw new \InvalidArgumentException('invalid_queue_health_threshold');
        if ($readyPayload !== null) {
            try { $payload = json_decode($readyPayload, true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return false; }
            $created = $payload['createdAt'] ?? null;
            if (!is_numeric($created) || !is_finite((float) $created) || (float) $created <= 0 || (float) $created > $now + 300 || $now - (float) $created > $maxAge) return false;
        }
        if ($hasReserved) {
            // The sorted-set score is reservation expiry, not its start time.
            if (!is_numeric($reservedScore) || !is_finite((float) $reservedScore)) return false;
            $started = (float) $reservedScore - $retryAfter;
            if ($started <= 0 || $started > $now + 300 || $now - $started > $maxAge) return false;
        }
        return true;
    }
}
