<?php
namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Cache\Repository;

class SubscriptionBehaviorAlertService
{
    private const PREFIX = 'subscription-behavior:';
    private const REASONS = ['sustained_script_downloads', 'repeated_bursts_with_low_usage'];
    private const FAMILIES = ['curl', 'wget', 'python-requests', 'aiohttp', 'httpx', 'go-http-client', 'clash', 'sing-box', 'shadowrocket', 'other'];
    private const NUMERIC = ['get_count', 'span_seconds', 'script_ratio', 'median_gap_seconds', 'short_gap_ratio', 'minute_peak', 'burst_clusters', 'source_count', 'node_source_count', 'bytes7d'];
    private \Closure $enabled;
    public function __construct(private ConnectionInterface $db, private Repository $cache, private SubscriptionBehaviorTelegramSender $sender, private array $config, ?callable $enabled = null)
    {
        $this->enabled = \Closure::fromCallable($enabled ?? fn() => (bool) admin_setting('telegram_ticket_bot_enable', false));
    }

    public function validate(array $input): array
    {
        if (($input['version'] ?? null) !== 1 || !is_string($input['run_id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{6,80}$/D', $input['run_id'])) throw new \InvalidArgumentException('invalid_envelope');
        foreach (['window_start', 'window_end'] as $key) if (!is_int($input[$key] ?? null) || $input[$key] <= 0) throw new \InvalidArgumentException('invalid_window');
        if ($input['window_end'] <= $input['window_start'] || $input['window_end'] - $input['window_start'] > $this->config['background_seconds'] || $input['window_end'] > time() + 300) throw new \InvalidArgumentException('invalid_window');
        if (!is_array($input['candidates'] ?? null) || !array_is_list($input['candidates']) || count($input['candidates']) > $this->config['max_candidates']) throw new \InvalidArgumentException('invalid_candidates');
        $clean = [];
        foreach ($input['candidates'] as $c) {
            if (!is_array($c) || !is_int($c['user_id'] ?? null) || $c['user_id'] <= 0 || !is_int($c['score'] ?? null) || $c['score'] < $this->config['threshold'] || $c['score'] > 100) throw new \InvalidArgumentException('invalid_candidate');
            if (!is_array($c['rules'] ?? null) || !array_is_list($c['rules']) || !$c['rules'] || count($c['rules']) > 2 || count(array_filter($c['rules'], 'is_string')) !== count($c['rules']) || array_diff($c['rules'], ['R1', 'R2'])) throw new \InvalidArgumentException('invalid_rules');
            if (!is_array($c['reasons'] ?? null) || !array_is_list($c['reasons']) || count($c['reasons']) > 2 || count(array_filter($c['reasons'], 'is_string')) !== count($c['reasons']) || array_diff($c['reasons'], self::REASONS)) throw new \InvalidArgumentException('invalid_reasons');
            if (!is_array($c['metrics'] ?? null) || array_diff(array_keys($c['metrics']), array_merge(self::NUMERIC, ['traffic_known', 'ua_families']))) throw new \InvalidArgumentException('invalid_metrics');
            foreach ($c['metrics'] as $key => $value) {
                if (in_array($key, self::NUMERIC, true) && !(($value === null && in_array($key, ['bytes7d', 'median_gap_seconds'], true)) || ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= PHP_INT_MAX))) throw new \InvalidArgumentException('invalid_metric');
                if (in_array($key, ['script_ratio', 'short_gap_ratio'], true) && $value > 1) throw new \InvalidArgumentException('invalid_ratio');
                if ($key === 'traffic_known' && !is_bool($value)) throw new \InvalidArgumentException('invalid_traffic');
                if ($key === 'ua_families') {
                    if (!is_array($value) || array_diff(array_keys($value), self::FAMILIES)) throw new \InvalidArgumentException('invalid_families');
                    foreach ($value as $count) if (!is_int($count) || $count < 0) throw new \InvalidArgumentException('invalid_family_count');
                }
            }
            $clean[] = ['user_id' => $c['user_id'], 'score' => $c['score'], 'rules' => array_values(array_unique($c['rules'])), 'metrics' => $c['metrics'], 'reasons' => $c['reasons']];
        }
        return ['version' => 1, 'run_id' => $input['run_id'], 'window_start' => $input['window_start'], 'window_end' => $input['window_end'], 'candidates' => $clean];
    }

    private function runKey(string $id): string { return self::PREFIX . 'run:' . $id; }
    private function pairKey(int $id, string $rule): string { return self::PREFIX . 'pair:' . $id . ':' . $rule; }
    private function allowed(object $user): bool
    {
        return (int) $user->id !== $this->config['owner_user_id'] && !(bool) $user->banned && !(bool) $user->is_admin && !(bool) $user->is_staff;
    }
    private function user(int $id): ?object
    {
        return $this->db->table('v2_user')->select(['id', 'email', 'banned', 'is_admin', 'is_staff', 'plan_id', 'expired_at', 'transfer_enable', 'u', 'd'])->where('id', $id)->first();
    }
    public function currentPlan(int $id, int $now): string
    {
        $u = $this->user($id);
        if (!$u) return '账号不存在';
        $name = $u->plan_id ? $this->db->table('v2_plan')->where('id', $u->plan_id)->value('name') : null;
        $original = $name ? $this->text((string) $name) : '无';
        $usable = $u->plan_id !== null && (int) $u->transfer_enable > (int) $u->u + (int) $u->d && ($u->expired_at === null || (int) $u->expired_at > $now);
        if ($usable && $name) return $original . '（周期套餐）';
        $package = $this->db->table('v2_user_traffic_packages')->where('user_id', $id)->where('status', 'active')->where('remaining_bytes', '>', 0)->orderBy('id')->select(['traffic_package_id', 'plan_id'])->first();
        if ($package) {
            $packageName = $package->traffic_package_id ? $this->db->table('v2_traffic_packages')->where('id', $package->traffic_package_id)->value('name') : $this->db->table('v2_plan')->where('id', $package->plan_id)->value('name');
            return $this->text((string) ($packageName ?: '流量包')) . '（有效流量包；账户原套餐：' . $original . '）';
        }
        return '无可用套餐／流量包；账户原套餐：' . $original;
    }
    private function text(string $value): string { return preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '字段无法显示'; }
    private function summary(array $e, string $status, int $sent, int $suppressed, ?string $delivery = null): array
    {
        return ['status' => $status, 'run_id' => $e['run_id'], 'matched' => count($e['candidates']), 'sent' => $sent, 'suppressed' => $suppressed, 'delivery' => $delivery];
    }

    public function handle(array $input, bool $dryRun = false): array
    {
        $e = $this->validate($input);
        if ($dryRun) {
            $eligible = 0;
            foreach ($e['candidates'] as $c) { $u = $this->user($c['user_id']); if ($u && $this->allowed($u)) $eligible++; }
            return $this->summary($e, 'dry_run', 0, count($e['candidates']) - $eligible) + ['eligible' => $eligible];
        }
        $lock = $this->cache->lock(self::PREFIX . 'notify-lock', 120);
        if (!$lock->get()) throw new \RuntimeException('notification_locked');
        try { return $this->lockedHandle($e); } finally { $lock->release(); }
    }

    private function lockedHandle(array $e): array
    {
        $existing = $this->cache->get($this->runKey($e['run_id']));
        if ($existing && in_array($existing['status'], ['sent', 'suppressed', 'uncertain', 'inflight'], true)) return $this->summary($e, $existing['status'] === 'inflight' ? 'uncertain' : ($existing['status'] === 'uncertain' ? 'uncertain' : 'duplicate'), 0, count($e['candidates']));
        $pending = $this->cache->get(self::PREFIX . 'pending-runs', []);
        // Preserve sanitized work before DB/configuration/render failures or process crashes.
        $this->cache->forever($this->runKey($e['run_id']), ['status' => 'pending', 'envelope' => $e]);
        $pending = array_values(array_unique(array_merge($pending, [$e['run_id']])));
        $this->cache->forever(self::PREFIX . 'pending-runs', $pending);
        $runs = [];
        foreach ($pending as $id) {
            $state = $this->cache->get($this->runKey($id));
            if (($state['status'] ?? null) === 'pending') $runs[$id] = $state['envelope'];
        }
        $runs[$e['run_id']] = $e;
        if (!(($this->enabled)())) throw new \RuntimeException('ticket_bot_disabled');
        $owner = $this->db->table('v2_user')->where('id', $this->config['owner_user_id'])->select(['telegram_id'])->first();
        if (!$owner || !preg_match('/^-?[1-9][0-9]{0,18}$/D', (string) $owner->telegram_id)) throw new \RuntimeException('owner_telegram_binding_missing');
        $this->sender->assertConfigured();
        $merged = [];
        foreach ($runs as $envelope) foreach ($envelope['candidates'] as $candidate) {
            $id = $candidate['user_id']; $u = $this->user($id);
            if (!$u || !$this->allowed($u)) continue;
            $rules = [];
            foreach ($candidate['rules'] as $rule) {
                $state = $this->cache->get($this->pairKey($id, $rule));
                if (!$state || ($state['status'] === 'sent' && $state['at'] <= time() - $this->config['dedupe_seconds'])) $rules[] = $rule;
            }
            if (!$rules) continue;
            $evidence = $merged[$id]['evidence'] ?? [];
            foreach ($rules as $rule) $evidence[$rule] = ['metrics' => $candidate['metrics'], 'window_start' => $envelope['window_start'], 'window_end' => $envelope['window_end']];
            if (isset($merged[$id])) {
                $rules = array_values(array_unique(array_merge($merged[$id]['rules'], $rules)));
                $candidate['score'] = max($merged[$id]['score'], $candidate['score']);
            }
            $candidate['rules'] = $rules;
            $candidate['evidence'] = $evidence;
            $merged[$id] = $candidate;
        }
        $suppressed = max(0, count($e['candidates']) - count($merged));
        if (!$merged) {
            foreach ($runs as $id => $envelope) $this->cache->put($this->runKey($id), ['status' => 'suppressed'], $this->config['state_seconds']);
            $this->cache->forget(self::PREFIX . 'pending-runs');
            $this->advance($e['window_end']);
            return $this->summary($e, 'suppressed', 0, $suppressed);
        }
        uasort($merged, fn($a, $b) => $b['score'] <=> $a['score'] ?: (($b['metrics']['get_count'] ?? 0) <=> ($a['metrics']['get_count'] ?? 0)));
        $lines = ['异常订阅行为检测（仅供人工复核，不认定举报身份）', '检查范围：' . $this->date($e['window_start']) . ' — ' . $this->date($e['window_end']), '异常账号：' . count($merged) . ' 个'];
        foreach ($merged as $id => $candidate) {
            $u = $this->user($id); // Recheck immediately before rendering/sending.
            if (!$u || !$this->allowed($u)) { unset($merged[$id]); continue; }
            $lines[] = "\n用户 ID：{$id}\n邮箱：" . $this->text((string) $u->email) . "\n当前套餐：" . $this->currentPlan($id, time());
            $lines[] = '命中：' . implode('、', array_map(fn($r) => $r === 'R1' ? 'R1 持续脚本拉取' : 'R2 反复秒级连发与低使用量', $candidate['rules'])) . '；评分：' . $candidate['score'];
            foreach ($candidate['evidence'] as $rule => $evidence) {
                $m = $evidence['metrics'];
                $lines[] = $rule . ' 证据：';
                $lines[] = '成功下载 ' . ($m['get_count'] ?? 0) . ' 次；活动跨度 ' . ($m['span_seconds'] ?? 0) . ' 秒；间隔中位数 ' . ($m['median_gap_seconds'] ?? '未知') . ' 秒；脚本比例 ' . round(($m['script_ratio'] ?? 0) * 100, 1) . '%；短间隔比例 ' . round(($m['short_gap_ratio'] ?? 0) * 100, 1) . '%';
                $lines[] = '滚动分钟峰值 ' . ($m['minute_peak'] ?? 0) . ' 次；独立连发簇 ' . ($m['burst_clusters'] ?? 0) . '；UA 家族：' . implode('、', array_keys($m['ua_families'] ?? []));
                $lines[] = '七日计费流量：' . (($m['traffic_known'] ?? false) && isset($m['bytes7d']) ? round($m['bytes7d'] / 1048576, 2) . ' MiB' : '统计未知') . '；行为范围：' . $this->date($evidence['window_start']) . ' — ' . $this->date($evidence['window_end']);
            }
        }
        if (!$merged) return $this->summary($e, 'suppressed', 0, count($e['candidates']));
        $text = implode("\n", $lines); $document = (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2) > $this->config['message_max_chars'];
        foreach ($runs as $id => $envelope) $this->cache->forever($this->runKey($id), ['status' => 'inflight', 'envelope' => $envelope]);
        $this->cache->forever(self::PREFIX . 'pending-runs', array_keys($runs));
        foreach ($merged as $id => $candidate) foreach ($candidate['rules'] as $rule) $this->cache->forever($this->pairKey($id, $rule), ['status' => 'inflight', 'at' => time()]);
        try { $status = $this->sender->send((string) $owner->telegram_id, $text, $document, '异常订阅行为：' . count($merged) . ' 个账号；完整邮箱、套餐与证据见附件。'); }
        catch (\Throwable) { $status = 'pending'; } // No request begins when credentials are missing.
        foreach ($runs as $id => $envelope) {
            $state = ['status' => $status, 'envelope' => $envelope];
            if ($status === 'sent') $this->cache->put($this->runKey($id), $state, $this->config['state_seconds']); else $this->cache->forever($this->runKey($id), $state);
        }
        foreach ($merged as $id => $candidate) foreach ($candidate['rules'] as $rule) {
            if ($status === 'pending') $this->cache->forget($this->pairKey($id, $rule));
            elseif ($status === 'sent') $this->cache->put($this->pairKey($id, $rule), ['status' => 'sent', 'at' => time()], $this->config['dedupe_seconds']);
            else $this->cache->forever($this->pairKey($id, $rule), ['status' => 'uncertain', 'at' => time()]);
        }
        if ($status !== 'pending') $this->cache->forget(self::PREFIX . 'pending-runs');
        if ($status === 'sent') $this->advance($e['window_end']);
        return $this->summary($e, $status, $status === 'sent' ? count($merged) : 0, $suppressed, $document ? 'document' : 'message');
    }
    private function advance(int $at): void
    {
        $previous = (int) $this->cache->get(self::PREFIX . 'last-success-at', 0);
        if ($at > $previous) $this->cache->forever(self::PREFIX . 'last-success-at', $at);
    }
    private function date(int $at): string { return (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($this->config['timezone']))->format('Y-m-d H:i:s'); }
    public function test(bool $dryRun = false): array
    {
        if ($dryRun) return ['status' => 'dry_run', 'sent' => 0, 'delivery' => null];
        if (!(($this->enabled)())) throw new \RuntimeException('ticket_bot_disabled');
        $owner = $this->db->table('v2_user')->where('id', $this->config['owner_user_id'])->select(['telegram_id'])->first();
        if (!$owner || !preg_match('/^-?[1-9][0-9]{0,18}$/D', (string) $owner->telegram_id)) throw new \RuntimeException('owner_telegram_binding_missing');
        $text = "检测系统测试：以下均为虚构示例，不是真实异常账号。\n每两小时检查；多个账号合并一条；同账号同规则 24 小时去重；不自动封号。\n\n用户 ID：示例 A\n邮箱：sample-a@example.invalid\n当前套餐：示例高级套餐\n异常：持续 curl 拉取，120 次，间隔中位数 35 秒（R1）。\n\n用户 ID：示例 B\n邮箱：sample-b@example.invalid\n当前套餐：示例流量包（账户原套餐：示例高级套餐）\n异常：3 个独立连发簇，分钟峰值 12 次，七日 32 MiB（R2）。";
        $status = $this->sender->send((string) $owner->telegram_id, $text, false, '');
        return ['status' => $status, 'sent' => $status === 'sent' ? 1 : 0, 'delivery' => 'message'];
    }
}
