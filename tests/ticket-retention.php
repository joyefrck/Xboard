<?php

declare(strict_types=1);

// This fixture never boots Laravel, reads .env, or connects to production.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketRetentionService;
use App\Services\TicketService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();
$root = sys_get_temp_dir() . '/xboard-retention-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$container = $db->getContainer();
$config = new Repository($container['config']->toArray());
$config->set('filesystems.disks.local', ['driver' => 'local', 'root' => $root, 'throw' => false]);
$container->instance('config', $config);
$container->instance('db', $db->getDatabaseManager());
$container->instance('log', new Psr\Log\NullLogger());
$container->singleton('filesystem', fn ($app) => new FilesystemManager($app));
Container::setInstance($container);
Facade::setFacadeApplication($container);
$baseDisk = Storage::disk('local');
$disk = new class($baseDisk->getDriver(), $baseDisk->getAdapter()) extends FilesystemAdapter {
    public array $fail = [];
    public function delete($paths)
    {
        if (in_array($paths, $this->fail, true)) {
            return false;
        }
        return parent::delete($paths);
    }
};
Storage::set('local', $disk);
$db->schema()->create('v2_ticket', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->integer('status');
    $t->integer('reply_status')->default(0); $t->integer('created_at'); $t->integer('updated_at');
});
$db->schema()->create('v2_ticket_message', function (Blueprint $t) {
    $t->increments('id'); $t->integer('ticket_id')->index(); $t->integer('user_id');
    $t->text('message'); $t->boolean('is_admin')->default(false);
    $t->integer('created_at'); $t->integer('updated_at');
});
$db->schema()->create('v2_ticket_message_attachment', function (Blueprint $t) {
    $t->increments('id'); $t->integer('ticket_message_id')->index(); $t->string('disk');
    $t->string('path'); $t->integer('size'); $t->integer('created_at'); $t->integer('updated_at');
});
$checks = 0;
$check = function ($actual, $expected, string $label) use (&$checks) {
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . json_encode($actual));
    }
};
$cutoff = time() - 14 * 86400;
$ticket = function (int $id, int $updated, int $status = 1) use ($db, $cutoff) {
    $db->table('v2_ticket')->insert(['id' => $id, 'user_id' => 20, 'status' => $status,
        'created_at' => $cutoff - 100, 'updated_at' => $updated]);
};
$message = function (int $ticketId, int $created, ?int $updated = null) use ($db) {
    return $db->table('v2_ticket_message')->insertGetId(['ticket_id' => $ticketId, 'user_id' => 20,
        'message' => 'fixture', 'created_at' => $created, 'updated_at' => $updated ?? $created]);
};
$attach = function (int $messageId, string $path, bool $write = true) use ($db, $disk, $cutoff) {
    if ($write) { $disk->put($path, 'fixture'); }
    return $db->table('v2_ticket_message_attachment')->insertGetId(['ticket_message_id' => $messageId,
        'disk' => 'local', 'path' => $path, 'size' => 7, 'created_at' => $cutoff - 10, 'updated_at' => $cutoff - 10]);
};
$path = fn (int $id) => sprintf('ticket-attachments/2026/10/00000000-0000-4000-8000-%012d.png', $id);

try {
    foreach ([1, 2, 5, 6, 8, 9, 10] as $id) { $ticket($id, $cutoff - 1, $id === 2 ? 0 : 1); }
    $ticket(3, $cutoff); $ticket(4, $cutoff + 1); $ticket(12, $cutoff - 1, 0);
    $ticket(7, $cutoff - 1);
    $db->table('v2_ticket')->where('id', 7)->update(['created_at' => $cutoff + 1]);
    $m1 = $message(1, $cutoff - 1); $attach($m1, $path(1));
    $message(2, $cutoff - 1);
    $message(5, $cutoff); // Recent message protects a stale ticket timestamp.
    $m6 = $message(6, $cutoff - 1, $cutoff + 1); $attach($m6, $path(6));
    $m9 = $message(9, $cutoff - 1); $a9 = $attach($m9, $path(9)); $disk->fail = [$path(9)];
    $m10 = $message(10, $cutoff - 1); $attach($m10, $path(6), false); // Shared with a retained message.
    $attach($m10, $path(10), false); // Missing file must not prevent metadata cleanup.
    $oldOrphan = $message(99999, $cutoff - 1); $attach($oldOrphan, $path(20));
    $newOrphan = $message(99998, $cutoff + 1); $attach($newOrphan, $path(21));

    $service = new TicketRetentionService();
    $preview = $service->preview($cutoff);
    $check($preview['tickets'], 6, 'preview selects both statuses and excludes boundary/recent activity');
    $check($preview['attachments'], 4, 'preview counts attachment records');
    $check($preview['orphan_messages'], 1, 'preview only selects stale legacy orphan messages');
    $check($db->table('v2_ticket')->count(), 11, 'preview makes no writes');
    $check($disk->exists($path(1)), true, 'preview leaves images intact');

    // Simulate a reply arriving between preview and pruning.
    $message(2, $cutoff + 1);
    $result = $service->prune($cutoff);
    $check($result['tickets'], 5, 'prune rechecks activity and deletes inactive open tickets');
    $check($result['failed_attachments'], 1, 'filesystem failure is reported');
    $check($result['orphan_messages'], 1, 'stale legacy orphan message is removed');
    $check($db->table('v2_ticket_message')->where('id', $newOrphan)->exists(), true, 'recent orphan message is retained');
    $check($disk->exists($path(20)), false, 'legacy orphan image is physically removed');
    $check($disk->exists($path(21)), true, 'recent orphan image is retained');
    $check($db->table('v2_ticket')->orderBy('id')->pluck('id')->all(), [2, 3, 4, 5, 6, 7], 'recent/boundary tickets survive');
    $check($disk->exists($path(1)), false, 'expired image is physically removed');
    $check($disk->exists($path(6)), true, 'shared retained image survives');
    $check($db->table('v2_ticket_message_attachment')->where('id', $a9)->exists(), true, 'failed deletion retains retry metadata');
    $check($disk->exists($path(9)), true, 'failed deletion leaves file for retry');
    $disk->fail = [];
    $retry = $service->prune($cutoff);
    $check($retry['tickets'], 0, 'retry does not redelete tickets');
    $check($retry['attachments'], 1, 'retry consumes durable metadata');
    $check($disk->exists($path(9)), false, 'retry removes file');
    $check($service->prune($cutoff)['attachments'], 0, 'repeated run is a no-op');

    // Unsafe orphan paths are retained, never followed outside the attachment directory.
    $unsafe = $attach(99999, 'public/keep.png');
    $check($service->prune($cutoff)['failed_attachments'], 1, 'unsafe path blocks deletion');
    $check($disk->exists('public/keep.png'), true, 'unrelated files survive');
    $db->table('v2_ticket_message_attachment')->where('id', $unsafe)->delete();

    // DB failure must roll back messages and leave images untouched.
    $ticket(11, $cutoff - 1); $m11 = $message(11, $cutoff - 1); $attach($m11, $path(11));
    $db->getConnection()->unprepared("CREATE TRIGGER prevent_delete BEFORE DELETE ON v2_ticket WHEN OLD.id=11 BEGIN SELECT RAISE(ABORT, 'fixture'); END");
    try { $service->prune($cutoff); throw new RuntimeException('expected DB failure'); }
    catch (Illuminate\Database\QueryException $e) { /* expected */ }
    $check($db->table('v2_ticket_message')->where('id', $m11)->exists(), true, 'DB rollback restores messages');
    $check($disk->exists($path(11)), true, 'DB rollback never removes image');
    $db->getConnection()->unprepared('DROP TRIGGER prevent_delete');
    $service->prune($cutoff);

    // A stale request cannot resurrect a ticket that pruning has already deleted.
    $stale = new Ticket(); $stale->id = 1; $stale->exists = true;
    $check((new TicketService())->reply($stale, 'late request', 20), false, 'stale user reply is rejected');
    $check(TicketMessage::where('ticket_id', 1)->count(), 0, 'stale reply creates no orphan message');
    try { (new TicketService())->replyByAdmin(1, 'late admin request', 20); throw new RuntimeException('expected missing ticket'); }
    catch (App\Exceptions\ApiException $e) { $checks++; }
    echo "ticket retention: {$checks} checks passed\n";
} finally {
    (new Illuminate\Filesystem\Filesystem())->deleteDirectory($root);
}
