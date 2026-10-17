<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TrafficPackageService;
use App\Services\UserService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new class extends Container {
    public function runningUnitTests(): bool { return true; }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$schema = $capsule->schema();
foreach (['v2_plan', 'v2_traffic_packages'] as $tableName) {
    $schema->create($tableName, function (Blueprint $t): void {
        $t->integer('id')->primary(); $t->string('name');
        $t->integer('group_id')->nullable(); $t->integer('speed_limit')->nullable();
        $t->integer('device_limit')->nullable(); $t->integer('created_at'); $t->integer('updated_at');
    });
}
$schema->create('v2_user', function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('plan_id')->nullable(); $t->integer('group_id')->nullable();
    $t->integer('speed_limit')->nullable(); $t->integer('device_limit')->nullable();
    $t->integer('custom_pending_order_id')->nullable(); $t->boolean('banned')->default(false);
    $t->integer('expired_at')->nullable(); $t->bigInteger('transfer_enable')->default(0);
    $t->bigInteger('u')->default(0); $t->bigInteger('d')->default(0); $t->integer('balance')->default(0);
    $t->integer('created_at'); $t->integer('updated_at');
});
$schema->create('v2_user_traffic_packages', function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('user_id'); $t->integer('plan_id')->nullable();
    $t->integer('traffic_package_id')->nullable(); $t->bigInteger('total_bytes');
    $t->bigInteger('remaining_bytes'); $t->string('status'); $t->integer('depleted_at')->nullable();
    $t->integer('created_at'); $t->integer('updated_at');
});
$now = time();
Capsule::table('v2_plan')->insert([
    ['id' => 1, 'name' => 'period', 'group_id' => 1, 'speed_limit' => 100, 'device_limit' => 2, 'created_at' => $now, 'updated_at' => $now],
    ['id' => 2, 'name' => 'legacy package', 'group_id' => 4, 'speed_limit' => 50, 'device_limit' => 1, 'created_at' => $now, 'updated_at' => $now],
]);
Capsule::table('v2_traffic_packages')->insert([
    ['id' => 1, 'name' => 'new package', 'group_id' => 3, 'speed_limit' => null, 'device_limit' => null, 'created_at' => $now, 'updated_at' => $now],
    ['id' => 2, 'name' => 'next package', 'group_id' => 2, 'speed_limit' => 60, 'device_limit' => 3, 'created_at' => $now, 'updated_at' => $now],
]);
$makeUser = function (int $id, array $overrides = []) use ($now): void {
    Capsule::table('v2_user')->insert(array_replace([
        'id' => $id, 'plan_id' => 1, 'group_id' => 1, 'speed_limit' => 100, 'device_limit' => 2,
        'custom_pending_order_id' => null, 'banned' => false, 'expired_at' => $now + 3600,
        'transfer_enable' => 100, 'u' => 10, 'd' => 20, 'balance' => 123,
        'created_at' => $now, 'updated_at' => $now,
    ], $overrides));
};
$makePackage = function (int $id, int $userId, array $overrides = []) use ($now): void {
    Capsule::table('v2_user_traffic_packages')->insert(array_replace([
        'id' => $id, 'user_id' => $userId, 'plan_id' => null, 'traffic_package_id' => 1,
        'total_bytes' => 100, 'remaining_bytes' => 80, 'status' => 'active', 'depleted_at' => null,
        'created_at' => $now, 'updated_at' => $now,
    ], $overrides));
};
$makeUser(1);
$makeUser(2, ['expired_at' => $now - 86400]); $makePackage(20, 2);
$makeUser(3, ['expired_at' => 0, 'plan_id' => null]); $makePackage(30, 3, ['plan_id' => 2, 'traffic_package_id' => null]);
$makeUser(4, ['expired_at' => null]);
$makeUser(5, ['banned' => true]); $makePackage(50, 5);
$makeUser(6, ['custom_pending_order_id' => 99]); $makePackage(60, 6);
$makeUser(7, ['u' => 100]); $makePackage(70, 7);
$makeUser(8, ['expired_at' => null, 'plan_id' => null, 'transfer_enable' => 0]);
$makeUser(9, ['u' => 100]);
$makeUser(10, ['expired_at' => $now - 1]); $makePackage(100, 10, ['status' => 'depleted']);
$makeUser(11, ['expired_at' => $now]);
$makeUser(12, ['expired_at' => 0, 'plan_id' => null]); $makePackage(120, 12); $makePackage(121, 12, ['traffic_package_id' => 2]);
$makeUser(13); $makePackage(130, 13);

$checks = 0;
$expect = function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$users = new UserService();
$packages = new TrafficPackageService();
$eligible = $users->getAvailableUsersQuery($now)->orderBy('id')->pluck('id')->all();
$expect($eligible === [1, 2, 3, 4, 7, 12, 13], 'availability must include packages, exclude banned/pending/exhausted and deduplicate holders');
$expect($users->getAvailableUsers()->pluck('id')->sort()->values()->all() === $eligible, 'existing collection API uses same predicate');
$expect($packages->getPackageAccessSyncCandidatesQuery($now)->orderBy('v2_user.id')->pluck('v2_user.id')->all() === [2, 3, 7, 12], 'only stale package access is selected');

$protectedFields = ['plan_id', 'expired_at', 'transfer_enable', 'u', 'd', 'balance', 'banned', 'custom_pending_order_id'];
$before = User::all()->mapWithKeys(fn (User $u) => [$u->id => $u->only($protectedFields)])->all();
$beforeBalances = Capsule::table('v2_user_traffic_packages')->orderBy('id')->get()->toJson();
$command = new App\Console\Commands\SyncPackageAccessProfiles($packages);
$command->setLaravel($app);
$output = new Symfony\Component\Console\Output\BufferedOutput();
$code = $command->run(new Symfony\Component\Console\Input\ArrayInput(['--dry-run' => true]), $output);
$expect($code === 0 && str_contains($output->fetch(), 'candidates=4'), 'command dry-run reports candidates');
$expect(User::findOrFail(2)->group_id == 1, 'dry-run does not update access');

// Candidate can renew or become banned before a worker obtains its lock.
Capsule::table('v2_user')->where('id', 2)->update(['expired_at' => $now + 3600]);
$expect(!$packages->syncPackageAccessForUser(2), 'renewed plan is rechecked and not overwritten');
$expect(User::findOrFail(2)->group_id == 1, 'renewal retains plan access');
Capsule::table('v2_user')->where('id', 2)->update(['expired_at' => $now - 86400]);
Capsule::table('v2_user')->where('id', 3)->update(['banned' => true]);
$expect(!$packages->syncPackageAccessForUser(3), 'new ban is rechecked');
Capsule::table('v2_user')->where('id', 3)->update(['banned' => false]);
$expect(!$packages->syncPackageAccessForUser(9999), 'missing user safely skipped');

$command = new App\Console\Commands\SyncPackageAccessProfiles($packages);
$command->setLaravel($app);
$output = new Symfony\Component\Console\Output\BufferedOutput();
$code = $command->run(new Symfony\Component\Console\Input\ArrayInput([]), $output);
$expect($code === 0 && str_contains($output->fetch(), 'updated=4'), 'command repairs all four candidates');
$expect(User::findOrFail(2)->group_id == 3 && User::findOrFail(2)->speed_limit === null, 'expired plan switches to new package including null limits');
$expect(User::findOrFail(3)->group_id == 4, 'legacy package uses legacy product access');
$expect(User::findOrFail(7)->group_id == 3, 'exhausted plan switches to package');
$expect(User::findOrFail(12)->group_id == 3, 'first package defines access with multiple packages');
$expect(User::all()->mapWithKeys(fn (User $u) => [$u->id => $u->only($protectedFields)])->all() === $before, 'repair preserves plans, expiry, usage, balances and bans');
$expect(Capsule::table('v2_user_traffic_packages')->orderBy('id')->get()->toJson() === $beforeBalances, 'repair never changes package ledger');
$expect($packages->getPackageAccessSyncCandidatesQuery()->count() === 0, 'repair is idempotent');
$expect(!$packages->syncPackageAccessForUser(2), 'repeat synchronization reports no mutation');

// FIFO source changes; an already depleted package cannot grant access.
Capsule::table('v2_user_traffic_packages')->where('id', 120)->update(['remaining_bytes' => 0, 'status' => 'depleted']);
$expect($packages->syncPackageAccessForUser(12), 'switches to the next usable package');
$expect(User::findOrFail(12)->group_id == 2 && User::findOrFail(12)->device_limit == 3, 'next package limits are applied');
Capsule::table('v2_user')->where('id', 2)->update(['group_id' => 1]);
Capsule::table('v2_user_traffic_packages')->where('id', 20)->update(['remaining_bytes' => 0, 'status' => 'depleted']);
$expect(!$packages->syncPackageAccessForUser(2), 'package depleted after selection is rechecked under lock');
$expect(User::findOrFail(2)->group_id == 1, 'no package does not mutate access in this scoped repair');

echo "available users and package access runtime checks passed ({$checks})\n";
