<?php
declare(strict_types=1);

require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Log, User};
use Illuminate\Support\Facades\Cache;

try {
    $args = getopt('', ['phase:', 'since:']);
    $phase = $args['phase'] ?? 'after';
    $since = strtotime($args['since'] ?? '-10 minutes');
    if ($since === false) throw new RuntimeException('Invalid timestamp');
    $counts = [];
    foreach (['CleanupExpiredOnlineStatus failed', 'UpdateAliveDataJob failed'] as $title) {
        $counts[$title] = Log::where('title', $title)->where('created_at', '>=', $since)->count();
    }
    $counts['statistics_errors'] = Log::where('title', 'like', 'StatUserJob failed%')->where('created_at', '>=', $since)->count();
    $stale = User::where('online_count', '>', 0)->where('last_online_at', '<', now()->subMinutes(5))->count();
    $lastSchedule = Cache::get(App\Utils\CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    $checks = ['schedule_recent' => is_numeric($lastSchedule) && time() - (int) $lastSchedule < 120];
    if ($phase === 'after') {
        $checks['no_new_target_errors'] = array_sum($counts) === 0;
        $checks['retention_converts_minutes'] = str_contains(file_get_contents('/www/public/assets/admin/assets/index.js'),
            'retentionPeriod",{hours:(ke?.periods?.failedJobs||0)/60}');
    }
    $ok = !in_array(false, $checks, true);
    echo json_encode(['ok' => $ok, 'sample_at' => date('c'), 'phase' => $phase, 'since' => date('c', $since),
        'checks' => $checks, 'target_errors_since' => $counts, 'stale_online_users' => $stale,
        'schedule_age_seconds' => is_numeric($lastSchedule) ? time() - (int) $lastSchedule : null,
        'business_writes' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error_class' => get_class($error)]) . "\n";
    exit(2);
}
