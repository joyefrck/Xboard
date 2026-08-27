<?php

declare(strict_types=1);

use App\Models\TrafficPackage;
use App\Models\User;
use App\Models\UserTrafficPackage;
use App\Services\TrafficPackageService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

require dirname(__DIR__) . '/vendor/autoload.php';

$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$schema = $capsule->schema();

$schema->create('v2_plan', function (Blueprint $table): void {
    $table->integer('id')->primary();
    $table->string('name');
    $table->integer('group_id')->nullable();
    $table->integer('speed_limit')->nullable();
    $table->integer('device_limit')->nullable();
    $table->integer('transfer_enable')->default(0);
    $table->integer('created_at');
    $table->integer('updated_at');
});

$schema->create('v2_traffic_packages', function (Blueprint $table): void {
    $table->integer('id')->primary();
    $table->string('name');
    $table->integer('group_id')->nullable();
    $table->integer('speed_limit')->nullable();
    $table->integer('device_limit')->nullable();
    $table->integer('transfer_enable')->default(0);
    $table->integer('created_at');
    $table->integer('updated_at');
});

$schema->create('v2_user', function (Blueprint $table): void {
    $table->integer('id')->primary();
    $table->integer('plan_id')->nullable();
    $table->integer('group_id')->nullable();
    $table->integer('speed_limit')->nullable();
    $table->integer('device_limit')->nullable();
    $table->unsignedBigInteger('transfer_enable')->default(0);
    $table->unsignedBigInteger('u')->default(0);
    $table->unsignedBigInteger('d')->default(0);
    $table->boolean('banned')->default(false);
    $table->integer('expired_at')->nullable();
    $table->integer('created_at');
    $table->integer('updated_at');
});

$schema->create('v2_user_traffic_packages', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('user_id');
    $table->integer('order_id')->nullable();
    $table->integer('plan_id')->nullable();
    $table->integer('traffic_package_id')->nullable();
    $table->unsignedBigInteger('total_bytes');
    $table->unsignedBigInteger('remaining_bytes');
    $table->string('status', 16);
    $table->integer('depleted_at')->nullable();
    $table->integer('created_at');
    $table->integer('updated_at');
});

$gib = 1073741824;
$now = time();

Capsule::table('v2_plan')->insert([
    'id' => 4,
    'name' => 'Base Plan',
    'group_id' => 1,
    'speed_limit' => 100,
    'device_limit' => 2,
    'transfer_enable' => 100,
    'created_at' => $now,
    'updated_at' => $now,
]);

Capsule::table('v2_traffic_packages')->insert([
    [
        'id' => 10,
        'name' => 'Premium Package',
        'group_id' => 3,
        'speed_limit' => 500,
        'device_limit' => 5,
        'transfer_enable' => 180,
        'created_at' => $now,
        'updated_at' => $now,
    ],
    [
        'id' => 11,
        'name' => 'Backup Package',
        'group_id' => 2,
        'speed_limit' => 200,
        'device_limit' => 3,
        'transfer_enable' => 50,
        'created_at' => $now,
        'updated_at' => $now,
    ],
]);

Capsule::table('v2_user')->insert([
    [
        'id' => 1,
        'plan_id' => 4,
        'group_id' => 1,
        'speed_limit' => 100,
        'device_limit' => 2,
        'transfer_enable' => 100 * $gib,
        'u' => 10 * $gib,
        'd' => 5 * $gib,
        'banned' => false,
        'expired_at' => $now + 3600,
        'created_at' => $now,
        'updated_at' => $now,
    ],
    [
        'id' => 2,
        'plan_id' => null,
        'group_id' => null,
        'speed_limit' => null,
        'device_limit' => null,
        'transfer_enable' => 0,
        'u' => 0,
        'd' => 0,
        'banned' => false,
        'expired_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ],
]);

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
};

$assertThrows = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
};

$service = new TrafficPackageService();
$user = User::findOrFail(1);
$beforePlan = $user->only(['plan_id', 'transfer_enable', 'u', 'd', 'expired_at']);
$granted = $service->grantByAdmin($user, TrafficPackage::findOrFail(10), 25);

$assertSame(
    $beforePlan,
    $user->refresh()->only(['plan_id', 'transfer_enable', 'u', 'd', 'expired_at']),
    'admin grant leaves plan state unchanged'
);
$assertSame(null, $granted->order_id, 'admin grant has no order');
$assertSame(null, $granted->plan_id, 'admin grant is not a legacy plan');
$assertSame(10, $granted->traffic_package_id, 'grant keeps the selected product');
$assertSame(25 * $gib, $granted->total_bytes, 'grant stores requested total bytes');
$assertSame(25 * $gib, $granted->remaining_bytes, 'grant starts fully available');
$assertSame(UserTrafficPackage::STATUS_ACTIVE, $granted->status, 'grant starts active');
$assertSame(1, (int) $user->group_id, 'usable plan remains the access source');

$secondGrant = $service->grantByAdmin($user, TrafficPackage::findOrFail(11), 5);
$assertSame(2, UserTrafficPackage::where('user_id', 1)->count(), 'each grant creates a separate row');
$assertSame(11, $secondGrant->traffic_package_id, 'second grant keeps its own product');
$assertSame(25 * $gib, UserTrafficPackage::findOrFail($granted->id)->remaining_bytes, 'second grant does not rewrite the first balance');

Capsule::table('v2_user')->where('id', 1)->update([
    'u' => 100 * $gib,
    'd' => 0,
]);
$user->refresh();
$service->syncAccessProfile($user);
$user->refresh();
$assertSame(3, (int) $user->group_id, 'oldest active package wins after plan exhaustion');
$assertSame(500, (int) $user->speed_limit, 'package speed limit follows selected product');
$assertSame(5, (int) $user->device_limit, 'package device limit follows selected product');

$packageOnlyUser = User::findOrFail(2);
$service->grantByAdmin($packageOnlyUser, TrafficPackage::findOrFail(10), 3);
$packageOnlyUser->refresh();
$assertSame(3, (int) $packageOnlyUser->group_id, 'grant immediately activates package access without a usable plan');

$rowCountBeforeInvalidGrants = UserTrafficPackage::count();
$assertThrows(
    fn() => $service->grantByAdmin($user, TrafficPackage::findOrFail(10), 0),
    'zero GB grant must be rejected'
);
$assertThrows(
    fn() => $service->grantByAdmin(
        $user,
        TrafficPackage::findOrFail(10),
        intdiv(PHP_INT_MAX, $gib) + 1
    ),
    'overflowing grant must be rejected'
);
$assertSame(
    $rowCountBeforeInvalidGrants,
    UserTrafficPackage::count(),
    'invalid grants create no rows'
);

echo "admin user traffic package grant runtime test passed\n";
