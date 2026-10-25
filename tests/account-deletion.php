<?php

declare(strict_types=1);

// Isolated SQLite fixture: never boot Laravel or read .env.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Exceptions\ApiException;
use App\Http\Requests\User\UserDeleteAccount;
use App\Jobs\StatUserJob;
use App\Models\User;
use App\Services\{AccountDeletionService, TicketRetentionService, TicketService};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\{FilesystemAdapter, FilesystemManager};
use Illuminate\Support\Facades\{Cache, Facade, Storage};
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\Factory;

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlite');
$db->getDatabaseManager()->setDefaultConnection('sqlite');
$db->setAsGlobal(); $db->bootEloquent();
$root = sys_get_temp_dir() . '/xboard-account-deletion-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$app = $db->getContainer();
$config = new Repository($app['config']->toArray());
$config->set('database.default', 'sqlite');
$config->set('filesystems.disks.local', ['driver' => 'local', 'root' => $root, 'throw' => false]);
$app->instance('config', $config);
$app->instance('db', $db->getDatabaseManager());
$app->instance('log', new Psr\Log\NullLogger());
$app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$app->singleton('filesystem', fn ($app) => new FilesystemManager($app));
Container::setInstance($app); Facade::setFacadeApplication($app);
$baseDisk = Storage::disk('local');
$disk = new class($baseDisk->getDriver(), $baseDisk->getAdapter()) extends FilesystemAdapter {
    public array $fail = [];
    public function delete($paths) { return in_array($paths, $this->fail, true) ? false : parent::delete($paths); }
};
Storage::set('local', $disk);
$schema = $db->schema();
$schema->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->string('email')->unique(); $t->string('password');
    foreach (['password_algo', 'password_salt', 'token', 'uuid'] as $name) $t->string($name)->nullable();
    $t->integer('telegram_id')->nullable(); $t->integer('invite_user_id')->nullable();
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$ownTables = ['v2_user_traffic_packages', 'v2_traffic_reset_logs', 'v2_stat_user', 'v2_invite_code',
    'v2_gift_card_usage', 'v2_telegram_group_entitlements', 'v2_telegram_group_invitations', 'v2_user_blacklist_event',
    'v2_order', 'v2_ticket', 'v2_gift_card_code', 'v2_commission_log'];
foreach ($ownTables as $table) {
    $schema->create($table, function (Blueprint $t) use ($table) {
        $t->increments('id'); $t->integer('user_id')->nullable();
        if (in_array($table, ['v2_order', 'v2_gift_card_usage', 'v2_commission_log'])) $t->integer('invite_user_id')->nullable();
        if ($table === 'v2_gift_card_code') $t->integer('status')->default(1);
        if ($table === 'v2_order') $t->integer('status')->default(0);
        if ($table === 'v2_ticket') { $t->integer('status')->default(0); $t->string('subject')->default('fixture'); }
        if ($table === 'v2_stat_user') {
            $t->float('server_rate')->default(1); $t->integer('record_at')->default(0); $t->string('record_type')->default('d');
            $t->integer('u')->default(0); $t->integer('d')->default(0);
        }
        $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
    });
}
$schema->create('v2_ticket_message', function (Blueprint $t) {
    $t->increments('id'); $t->integer('ticket_id'); $t->integer('user_id'); $t->text('message')->default('fixture');
    $t->boolean('is_admin')->default(false); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_ticket_message_attachment', function (Blueprint $t) {
    $t->increments('id'); $t->integer('ticket_message_id'); $t->string('disk'); $t->string('path'); $t->integer('size')->default(7);
});
$schema->create('personal_access_tokens', function (Blueprint $t) {
    $t->increments('id'); $t->string('tokenable_type'); $t->integer('tokenable_id'); $t->string('token')->unique();
});
$schema->create('v2_mail_log', function (Blueprint $t) { $t->increments('id'); $t->string('email'); });
$schema->create('v2_telegram_group_requests', function (Blueprint $t) { $t->increments('id'); $t->integer('invitation_id'); });
$schema->create('v2_plan', function (Blueprint $t) {
    $t->increments('id'); $t->integer('owner_user_id')->nullable(); $t->boolean('show')->default(false); $t->boolean('renew')->default(true);
});
$schema->create('v2_node_automation_settings', function (Blueprint $t) {
    $t->increments('id'); $t->integer('probe_user_id')->nullable(); $t->text('probe_users_by_group'); $t->timestamps();
});
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$seed = function () use ($db, $schema, $disk, $ownTables): void {
    foreach ($schema->getTableListing() as $table) $db->table($table)->delete();
    foreach ([10, 20] as $id) {
        User::forceCreate(['id' => $id, 'email' => "user{$id}@example.test", 'password' => password_hash('fixture-password', PASSWORD_DEFAULT),
            'token' => "subscription-{$id}", 'uuid' => "uuid-{$id}", 'telegram_id' => $id * 100, 'invite_user_id' => $id === 20 ? 10 : null]);
        foreach ($ownTables as $table) {
            $row = ['id' => $id, 'user_id' => $id];
            if (in_array($table, ['v2_order', 'v2_gift_card_usage', 'v2_commission_log'])) $row['invite_user_id'] = $id === 20 ? 10 : 20;
            $db->table($table)->insert($row);
        }
        $db->table('v2_ticket_message')->insert(['id' => $id, 'ticket_id' => $id, 'user_id' => $id]);
        // Include the staff reply in the user's ticket, not just the user's own messages.
        $db->table('v2_ticket_message')->insert(['id' => $id + 1, 'ticket_id' => $id, 'user_id' => 99, 'is_admin' => true]);
        $path = sprintf('ticket-attachments/2026/10/00000000-0000-4000-8000-%012d.png', $id);
        $disk->put($path, 'fixture');
        $db->table('v2_ticket_message_attachment')->insert(['id' => $id, 'ticket_message_id' => $id + 1, 'disk' => 'local', 'path' => $path]);
        foreach ([0, 1] as $n) $db->table('personal_access_tokens')->insert(['id' => $id + $n, 'tokenable_id' => $id, 'tokenable_type' => User::class, 'token' => "token-{$id}-{$n}"]);
        $db->table('v2_mail_log')->insert(['id' => $id, 'email' => "user{$id}@example.test"]);
        $db->table('v2_telegram_group_requests')->insert(['id' => $id, 'invitation_id' => $id]);
        $db->table('v2_plan')->insert(['id' => $id, 'owner_user_id' => $id]);
    }
    foreach (range(0, 4) as $status) $db->table('v2_order')->insert(['id' => 30 + $status, 'user_id' => 10, 'status' => $status]);
    $db->table('v2_node_automation_settings')->insert(['id' => 1, 'probe_users_by_group' => '{"1":10,"2":20}']);
};
$path10 = 'ticket-attachments/2026/10/00000000-0000-4000-8000-000000000010.png';
$path20 = 'ticket-attachments/2026/10/00000000-0000-4000-8000-000000000020.png';
$validator = new Factory(new Translator(new ArrayLoader(), 'en'));
$rules = (new UserDeleteAccount())->rules();
$service = new AccountDeletionService();
try {
    foreach ([[], ['password' => 'fixture-password'], ['password' => 'fixture-password', 'confirmation' => 'true'],
        ['password' => ['array'], 'confirmation' => '注销账号']] as $input) {
        check($validator->make($input, $rules)->fails(), 'missing or invalid final confirmation/password must fail validation');
    }
    check($validator->make(['password' => 'fixture-password', 'confirmation' => '注销账号'], $rules)->passes(), 'valid final confirmation passes');
    $seed();
    try { $service->delete(10, 'wrong-password'); throw new RuntimeException('expected password rejection'); }
    catch (ApiException $e) { check(str_contains($e->getMessage(), '密码错误'), 'wrong password is rejected'); }
    check(User::count() === 2 && $db->table('v2_order')->count() === 7 && $db->table('personal_access_tokens')->count() === 4, 'wrong password makes no data changes');

    $db->getConnection()->unprepared("CREATE TRIGGER prevent_delete BEFORE DELETE ON v2_user WHEN OLD.id=10 BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    try { $service->delete(10, 'fixture-password'); throw new RuntimeException('expected database failure'); }
    catch (Illuminate\Database\QueryException $e) { check(true, 'database failure propagates'); }
    check(User::count() === 2 && $db->table('v2_order')->count() === 7 && $db->table('personal_access_tokens')->count() === 4, 'database failure rolls back account/orders/tokens');
    check($db->table('v2_ticket_message')->count() === 4 && $disk->exists($path10), 'rollback preserves ticket messages and physical attachments');
    check($db->table('v2_user')->where('id', 20)->value('invite_user_id') === 10, 'rollback restores other account references');
    $db->getConnection()->unprepared('DROP TRIGGER prevent_delete');

    check($db->table('v2_order')->where('user_id', 10)->distinct()->count('status') === 5, 'fixture includes pending, processing, cancelled, completed and discounted orders');
    $service->delete(10, 'fixture-password');
    check(User::find(10) === null && User::find(20) !== null, 'only the authenticated account is deleted');
    foreach ($ownTables as $table) {
        if ($table === 'v2_gift_card_code') continue;
        check(!$db->table($table)->where('user_id', 10)->exists(), "own records removed: {$table}");
        if ($table !== 'v2_commission_log') check($db->table($table)->where('user_id', 20)->exists(), "other user records preserved: {$table}");
    }
    check($db->table('v2_order')->where('id', 20)->value('invite_user_id') === null, 'other orders detach deleted inviter');
    check($db->table('v2_user')->where('id', 20)->value('invite_user_id') === null, 'invited users survive without dangling inviter');
    check($db->table('v2_ticket_message')->where('ticket_id', 10)->count() === 0 && $db->table('v2_ticket_message')->where('ticket_id', 20)->count() === 2, 'all replies of the deleted account ticket are removed');
    check(!$disk->exists($path10) && $disk->exists($path20), 'only deleted account attachments are physically removed');
    check($db->table('v2_ticket_message_attachment')->where('id', 10)->doesntExist(), 'successful file cleanup removes outbox metadata');
    check($db->table('personal_access_tokens')->where('tokenable_id', 10)->count() === 0 && $db->table('personal_access_tokens')->where('tokenable_id', 20)->count() === 2, 'all own sessions revoked, other sessions intact');
    check($db->table('v2_mail_log')->where('id', 10)->doesntExist() && $db->table('v2_mail_log')->where('id', 20)->exists(), 'mail history cleanup scoped by email');
    check($db->table('v2_telegram_group_requests')->where('id', 10)->doesntExist(), 'join requests removed with invitations');
    check($db->table('v2_gift_card_code')->where('id', 10)->value('user_id') === null && $db->table('v2_gift_card_code')->where('id', 10)->value('status') === 1, 'redeemed cards cannot become reusable');
    check(json_decode($db->table('v2_node_automation_settings')->value('probe_users_by_group'), true) === [2 => 20], 'only deleted probe identity detached');
    check($db->table('v2_plan')->where('id', 10)->value('owner_user_id') === null, 'private catalog detaches account');

    (new StatUserJob(['rate' => 1], [10 => [10, 20], 20 => [30, 40]], 'vless'))->handle();
    check(!$db->table('v2_stat_user')->where('user_id', 10)->exists(), 'delayed node stats cannot recreate deleted account records');
    check($db->table('v2_stat_user')->where('user_id', 20)->sum('u') === 30, 'delayed stats still update an existing user');
    try { (new TicketService())->createTicket(10, 'late ticket', 0, 'fixture'); throw new RuntimeException('expected stale identity rejection'); }
    catch (ApiException $e) { check($e->getCode() === 403, 'stale request cannot create tickets for deleted user'); }
    check(!$db->table('v2_ticket')->where('user_id', 10)->exists(), 'stale ticket creates no orphan');

    try { $service->delete(10, 'fixture-password'); throw new RuntimeException('expected missing account'); }
    catch (ApiException $e) { check($e->getCode() === 403, 'repeated deletion rejects missing identity'); }

    $seed(); $disk->fail = [$path10];
    $service->delete(10, 'fixture-password');
    check(User::find(10) === null, 'file cleanup failure cannot undo committed deletion');
    check($disk->exists($path10) && $db->table('v2_ticket_message_attachment')->where('id', 10)->exists(), 'failed file cleanup keeps durable retry metadata');
    $disk->fail = [];
    (new TicketRetentionService())->prunePendingAttachments();
    check(!$disk->exists($path10) && $disk->exists($path20), 'retry removes orphan file without touching surviving account');

    $seed();
    User::whereKey(10)->update(['password_algo' => 'md5salt', 'password_salt' => 'fixture-salt', 'password' => md5('fixture-passwordfixture-salt')]);
    $service->delete(10, 'fixture-password');
    check(User::find(10) === null, 'legacy salted password authentication supported');
    echo "account deletion: {$checks} checks passed\n";
} finally {
    (new Illuminate\Filesystem\Filesystem())->deleteDirectory($root);
}
