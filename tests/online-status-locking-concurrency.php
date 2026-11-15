<?php
declare(strict_types=1);

if (!getenv('ONLINE_STATUS_TEST_PORT')) throw new RuntimeException('ONLINE_STATUS_TEST_PORT is required for real InnoDB concurrency tests');
require __DIR__ . '/online-status-fixture.php';

use App\Jobs\{StatUserJob, UpdateAliveDataJob};
use Illuminate\Support\Facades\DB;

$folder = $argv[2] ?? sys_get_temp_dir() . '/xboard-online-concurrency-' . bin2hex(random_bytes(8));
if (!str_starts_with($folder, sys_get_temp_dir() . '/xboard-online-concurrency-')) throw new RuntimeException('Unsafe fixture folder');
if (!is_dir($folder)) mkdir($folder, 0700, true);

function onlineWaitFile(string $file): void
{
    $deadline = microtime(true) + 8;
    while (!is_file($file)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Concurrency barrier timed out');
        usleep(10000);
    }
}

if (($argv[1] ?? '') === 'worker') {
    $marked = false;
    $db->getConnection()->beforeExecuting(function ($sql) use ($folder, &$marked): void {
        if (!$marked && preg_match('/^update `v2_user`/i', $sql)) {
            $marked = true;
            file_put_contents($folder . '/updating', '1');
        }
    });
    try {
        if ($argv[3] === 'online') {
            $job = (new UpdateAliveDataJob([2 => ['192.0.2.2'], 1 => ['192.0.2.1']], 'vless', 1))->withFakeQueueInteractions();
            $job->handle();
            $result = ['ok' => !$job->job->hasFailed()];
        } else {
            $result = ['ok' => (new OnlineFixtureCleanup())->handle() === 0];
        }
        echo json_encode($result);
    } catch (Throwable $error) {
        echo json_encode(['ok' => false, 'type' => get_class($error)]);
    }
    exit;
}

onlineFixtureSchema();
$db->schema()->dropIfExists('online_fixture_weight');
$db->schema()->create('online_fixture_weight', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->engine = 'InnoDB';
    $table->increments('id');
    $table->integer('value');
});
$start = function (string $operation) use ($folder): array {
    @unlink($folder . '/updating');
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $folder, $operation],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    onlineWaitFile($folder . '/updating');
    // The peer is now executing its update, blocked by the row held here.
    usleep(200000);
    if (!proc_get_status($process)['running']) throw new RuntimeException('Peer did not reach its row-lock wait');
    return [$process, $pipes];
};
$finish = function (array $worker): array {
    [$process, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    $result = json_decode($out, true);
    if (!is_array($result)) throw new RuntimeException('Peer failed: ' . $err);
    return $result;
};
$checks = 0;
$check = function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};

try {
    foreach (['cleanup', 'online'] as $operation) {
        onlineFixtureSeed();
        $db->table('online_fixture_weight')->delete();
        $worker = null;
        // With the old code, this opposite order and the peer's multi-row
        // update create the exact S/X user-row cycle observed in production.
        $data = $operation === 'cleanup' ? [2 => [30, 40], 1 => [10, 20]] : [1 => [10, 20], 2 => [30, 40]];
        $job = new class(['rate' => 1], $data, 'vless') extends StatUserJob {
            public $afterFirst;
            private bool $started = false;
            protected function processUserStat(int $uid, array $v, int $recordAt): void {
                parent::processUserStat($uid, $v, $recordAt);
                if (!$this->started) {
                    $this->started = true;
                    // Make statistics the heavier transaction, so InnoDB
                    // deterministically selects the old peer as deadlock victim.
                    DB::table('online_fixture_weight')->insert(array_fill(0, 100, ['value' => 1]));
                    ($this->afterFirst)();
                }
            }
        };
        $job->afterFirst = function () use (&$worker, $start, $operation): void { $worker = $start($operation); };
        $job->handle();
        $check($finish($worker) === ['ok' => true], $operation . ' must finish despite concurrent statistics row locks');
        $check((int) DB::table('v2_stat_user')->sum('u') === 40 && (int) DB::table('v2_stat_user')->sum('d') === 60,
            'concurrent ' . $operation . ' must not lose or duplicate traffic statistics');
        $expected = $operation === 'cleanup' ? 0 : 2;
        $check((int) DB::table('v2_user')->whereIn('id', [1, 2])->sum('online_count') === $expected,
            $operation . ' must commit both user states');
        echo 'PASS statistics vs ' . $operation . PHP_EOL;
    }

    onlineFixtureSeed();
    DB::beginTransaction();
    DB::table('v2_user')->where('id', 1)->lockForUpdate()->first();
    $worker = $start('cleanup');
    DB::table('v2_user')->where('id', 1)->update(['online_count' => 9, 'last_online_at' => now()->format('Y-m-d H:i:s')]);
    DB::commit();
    $check($finish($worker) === ['ok' => true], 'cleanup must finish after concurrent reconnect');
    $check((int) DB::table('v2_user')->where('id', 1)->value('online_count') === 9, 'row-lock wait must recheck newly refreshed status');
    $check((int) DB::table('v2_user')->where('id', 2)->value('online_count') === 0, 'other stale user must be cleaned');
    echo 'PASS cleanup vs reconnect' . PHP_EOL;
    echo json_encode(['checks' => $checks, 'failures' => 0]) . PHP_EOL;
} finally {
    while (DB::transactionLevel()) DB::rollBack();
    foreach (['online_fixture_weight', 'v2_stat_user', 'v2_user'] as $table) $db->schema()->dropIfExists($table);
    foreach (glob($folder . '/*') ?: [] as $file) unlink($file);
    rmdir($folder);
}
