<?php
declare(strict_types=1);

require __DIR__ . '/online-status-fixture.php';

use App\Jobs\{StatUserJob, UpdateAliveDataJob};
use Illuminate\Support\Facades\DB;

onlineFixtureSchema();
$checks = 0;
$failures = [];
$hook = null;
$db->getConnection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$hook) {
    if ($hook) $hook($sql, $bindings, $connection);
});
$check = function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$run = function (string $name, callable $test) use (&$failures, &$hook): void {
    onlineFixtureSeed();
    try { $test(); echo 'PASS ' . $name . PHP_EOL; }
    catch (Throwable $e) { $failures[] = $name . ': ' . $e->getMessage(); echo 'FAIL ' . end($failures) . PHP_EOL; }
    finally { $hook = null; }
};

$run('statistics lock order and deleted-user protection', function () use ($check) {
    $job = new class(['rate' => 1], [3 => [30, 60], 1 => [10, 20], 99 => [90, 90], 2 => [20, 40]], 'vless') extends StatUserJob {
        public array $processed = [];
        protected function processUserStat(int $uid, array $v, int $recordAt): void {
            $this->processed[] = $uid;
            parent::processUserStat($uid, $v, $recordAt);
        }
    };
    $job->handle();
    $check($job->processed === [1, 2, 3], 'statistics must acquire user locks in ascending numeric order');
    $check(DB::table('v2_stat_user')->count() === 3, 'deleted users must not recreate orphan statistics');
});

$run('statistics retry rolls back the entire batch exactly once', function () use ($check) {
    $job = new class(['rate' => 1], [1 => [10, 20], 2 => [30, 40]], 'vless') extends StatUserJob {
        public int $faults = 0;
        protected function processUserStat(int $uid, array $v, int $recordAt): void {
            parent::processUserStat($uid, $v, $recordAt);
            if ($uid === 2 && $this->faults++ === 0) throw new PDOException('Deadlock found when trying to get lock', 40001);
        }
    };
    $job->handle();
    $check($job->faults === 2, 'failed transaction must be retried');
    $check((int) DB::table('v2_stat_user')->sum('u') === 40 && (int) DB::table('v2_stat_user')->sum('d') === 60,
        'transaction retry must not double count previously processed users');
});

$run('online updates use numeric lock order and retry the whole batch', function () use ($check, &$hook) {
    $order = [];
    $faults = 0;
    $hook = function ($sql, $bindings) use (&$order, &$faults): void {
        if (!preg_match('/^update ["`]v2_user["`]/i', $sql)) return;
        $id = (int) end($bindings);
        $order[] = $id;
        if ($id === 2 && $faults++ === 0) throw new PDOException('Deadlock found when trying to get lock', 40001);
    };
    $job = (new UpdateAliveDataJob([2 => ['192.0.2.2'], 1 => ['192.0.2.1', '192.0.2.3'], 99 => ['192.0.2.9']], 'vless', 1))
        ->withFakeQueueInteractions();
    $job->handle();
    $check(!$job->job->hasFailed(), 'a recovered deadlock must not mark the queue job failed');
    $check($order === [1, 2, 1, 2], 'online writes must use the same numeric order on retry');
    $hook = null;
    $check((int) DB::table('v2_user')->where('id', 1)->value('online_count') === 2, 'first user update commits after retry');
    $check((int) DB::table('v2_user')->where('id', 2)->value('online_count') === 1, 'second user update commits after retry');
    $check(DB::table('v2_user')->count() === 3, 'online reports must not recreate deleted users');
});

$run('cleanup rechecks stale status after candidate selection', function () use ($check, &$hook) {
    $changed = false;
    $hook = function ($sql, $bindings) use (&$hook, &$changed): void {
        if (!preg_match('/^update ["`]v2_user["`]/i', $sql) || $changed) return;
        $changed = true;
        $hook = null;
        DB::table('v2_user')->where('id', 1)->update(['last_online_at' => now()->format('Y-m-d H:i:s'), 'online_count' => 9]);
    };
    $check((new OnlineFixtureCleanup())->handle() === 0, 'cleanup succeeds');
    $check((int) DB::table('v2_user')->where('id', 1)->value('online_count') === 9, 'freshly reconnected user must not be marked offline');
    $check((int) DB::table('v2_user')->where('id', 2)->value('online_count') === 0, 'stale user is reset');
    $check((int) DB::table('v2_user')->where('id', 3)->value('online_count') === 7, 'recent user is preserved');
});

$run('cleanup retries deadlocks and holds at most one user per write', function () use ($check, &$hook) {
    $faults = 0;
    $widths = [];
    $hook = function ($sql, $bindings) use (&$faults, &$widths): void {
        if (!preg_match('/^update ["`]v2_user["`]/i', $sql)) return;
        preg_match('/where.*?\bid\b["`]? in \(([^)]+)\)/i', $sql, $m);
        $widths[] = $m ? substr_count($m[1], '?') : 1;
        if ($faults++ === 0) throw new PDOException('Deadlock found when trying to get lock', 40001);
    };
    $check((new OnlineFixtureCleanup())->handle() === 0, 'cleanup must recover from a transient deadlock');
    $hook = null;
    $check(max($widths) === 1, 'cleanup must not hold a multi-user update lock');
    $check((int) DB::table('v2_user')->whereIn('id', [1, 2])->sum('online_count') === 0, 'all stale users are cleaned');
});

$run('unexpected statistics failure remains atomic and is not retried', function () use ($check) {
    $job = new class(['rate' => 1], [1 => [10, 20], 2 => [30, 40]], 'vless') extends StatUserJob {
        public int $calls = 0;
        protected function processUserStat(int $uid, array $v, int $recordAt): void {
            $this->calls++;
            parent::processUserStat($uid, $v, $recordAt);
            if ($uid === 2) throw new RuntimeException('fixture permanent failure');
        }
    };
    try { $job->handle(); throw new LogicException('expected failure'); }
    catch (RuntimeException $e) { $check($e->getMessage() === 'fixture permanent failure', 'permanent failure propagates'); }
    $check($job->calls === 2 && DB::table('v2_stat_user')->count() === 0, 'non-concurrency failure rolls back all increments without retry');
});

echo json_encode(['checks' => $checks, 'failures' => count($failures)]) . PHP_EOL;
exit($failures ? 1 : 0);
