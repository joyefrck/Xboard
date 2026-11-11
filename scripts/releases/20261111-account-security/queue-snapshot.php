<?php
declare(strict_types=1);

// Run inside the Horizon container. No dispatch, API calls, retries or writes.
$args = getopt('', ['root:', 'since:']);
$root = $args['root'] ?? '/www';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$since = isset($args['since']) ? strtotime($args['since']) : time() - 600;
if ($since === false) {
    fwrite(STDERR, "Invalid --since timestamp\n");
    exit(2);
}

try {
    $errors = [];
    $required = [
        'redis:default', 'redis:traffic_fetch', 'redis:stat', 'redis:online_sync',
        'redis:order_handle', 'redis:send_telegram', 'redis:send_ticket_telegram',
        'redis:send_email', 'redis:send_email_mass', 'telegram_group:telegram_group',
        'node_automation:node_automation',
    ];
    $supervisors = [];
    foreach (app(Laravel\Horizon\Contracts\SupervisorRepository::class)->all() as $s) {
        $supervisors[$s->name] = $s;
    }
    $masters = [];
    foreach (app(Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all() as $m) {
        $masters[$m->name] = $m;
    }
    $workers = [];
    $localMasters = [];
    $zombies = 0;
    foreach (glob('/proc/[0-9]*') ?: [] as $proc) {
        $stat = @file_get_contents($proc . '/stat') ?: '';
        if (preg_match('/^\d+ \(.*\) Z /', $stat)) { $zombies++; }
        $argv = explode("\0", @file_get_contents($proc . '/cmdline') ?: '');
        $workIndex = array_search('horizon:work', $argv, true);
        if ($workIndex === false) { continue; }
        $connection = $argv[$workIndex + 1] ?? '';
        $queue = $supervisor = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--queue=')) { $queue = substr($arg, 8); }
            if (str_starts_with($arg, '--supervisor=')) { $supervisor = substr($arg, 13); }
        }
        $record = $supervisors[$supervisor ?? ''] ?? null;
        if (!$record || $record->status !== 'running') { continue; }
        $master = $masters[$record->master] ?? null;
        if (!$master || $master->status !== 'running') { continue; }
        $localMasters[$master->name] = $master->environment;
        foreach (explode(',', $queue ?? '') as $name) {
            $key = $connection . ':' . trim($name);
            $workers[$key] = ($workers[$key] ?? 0) + 1;
        }
    }
    $environments = array_values(array_unique(array_values($localMasters)));
    if (count($environments) !== 1) { $errors[] = 'expected_one_active_local_horizon_environment'; }
    $environment = $environments[0] ?? config('horizon.env') ?? config('app.env');
    $plan = Laravel\Horizon\ProvisioningPlan::get('snapshot-only');
    $selected = null;
    $configured = [];
    foreach ($plan->parsed as $name => $items) {
        if (!Illuminate\Support\Str::is($name, $environment)) { continue; }
        $selected = $name;
        foreach ($items as $item) {
            if ($item->maxProcesses <= 0) { continue; }
            $retryAfter = config('queue.connections.' . $item->connection . '.retry_after', 0);
            if ($item->timeout >= $retryAfter) { $errors[] = 'invalid_lease:' . $item->name; }
            foreach (explode(',', $item->queue) as $q) { $configured[] = $item->connection . ':' . trim($q); }
        }
        break;
    }
    foreach (array_diff($required, $configured) as $key) { $errors[] = 'not_provisioned:' . $key; }
    foreach (array_diff($required, array_keys($workers)) as $key) { $errors[] = 'no_active_worker:' . $key; }
    $queues = [];
    foreach (array_unique(array_merge($required, $configured)) as $key) {
        [$connection, $queue] = explode(':', $key, 2);
        $redisConnection = config('queue.connections.' . $connection . '.connection', 'default');
        $redis = Illuminate\Support\Facades\Redis::connection($redisConnection);
        $base = 'queues:' . $queue;
        $head = $redis->lrange($base, 0, 0);
        $created = isset($head[0]) ? (json_decode($head[0], true)['createdAt'] ?? null) : null;
        $queues[$key] = [
            'workers' => $workers[$key] ?? 0,
            'ready' => $redis->llen($base), 'reserved' => $redis->zcard($base . ':reserved'),
            'delayed' => $redis->zcard($base . ':delayed'),
            'ready_head_age_seconds' => is_numeric($created) ? max(0, time() - (int) $created) : null,
        ];
    }
    $db = Illuminate\Support\Facades\DB::connection();
    $failed = $db->getSchemaBuilder()->hasTable('failed_jobs')
        ? $db->table('failed_jobs')->where('failed_at', '>=', date('Y-m-d H:i:s', $since))->count() : null;
    $mail = [
        'count' => App\Models\MailLog::where('created_at', '>=', $since)->count(),
        'errors' => App\Models\MailLog::where('created_at', '>=', $since)->whereNotNull('error')->where('error', '!=', '')->count(),
    ];
    echo json_encode([
        'coverage_ok' => $errors === [], 'sample_at' => date('c'), 'since' => date('c', $since),
        'app_environment' => $app->environment(), 'horizon_environment' => $environment,
        'selected_environment_pattern' => $selected, 'configuration_cached' => $app->configurationIsCached(),
        'local_active_masters' => $localMasters, 'queues' => $queues, 'new_failed_jobs' => $failed,
        'mail_results' => $mail,
        'registered' => App\Models\User::where('created_at', '>=', $since)->count(),
        'paid_orders' => App\Models\Order::where('paid_at', '>=', $since)->count(),
        'container_zombies' => $zombies, 'errors' => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($errors === [] ? 0 : 1);
} catch (Throwable $e) {
    // Exception messages may include connection credentials or serialized data.
    echo json_encode(['coverage_ok' => false, 'error_class' => get_class($e)]) . "\n";
    exit(2);
}
