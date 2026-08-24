<?php

declare(strict_types=1);

use App\Models\User;
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
    $table->integer('balance')->default(0);
    $table->integer('created_at');
    $table->integer('updated_at');
});

$schema->create('v2_user_traffic_packages', function (Blueprint $table): void {
    $table->integer('id')->primary();
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

$now = time();

Capsule::table('v2_plan')->insert([
    [
        'id' => 4,
        'name' => 'Base',
        'group_id' => 1,
        'speed_limit' => 120,
        'device_limit' => 2,
        'transfer_enable' => 100,
        'created_at' => $now,
        'updated_at' => $now,
    ],
    [
        'id' => 10,
        'name' => 'Legacy package',
        'group_id' => 4,
        'speed_limit' => 80,
        'device_limit' => 1,
        'transfer_enable' => 50,
        'created_at' => $now,
        'updated_at' => $now,
    ],
]);

Capsule::table('v2_traffic_packages')->insert([
    [
        'id' => 1,
        'name' => 'First package',
        'group_id' => 3,
        'speed_limit' => null,
        'device_limit' => null,
        'transfer_enable' => 100,
        'created_at' => $now,
        'updated_at' => $now,
    ],
    [
        'id' => 2,
        'name' => 'Second package',
        'group_id' => 2,
        'speed_limit' => 60,
        'device_limit' => 3,
        'transfer_enable' => 100,
        'created_at' => $now,
        'updated_at' => $now,
    ],
]);

Capsule::table('v2_user')->insert([
    'id' => 1,
    'plan_id' => 4,
    'group_id' => 1,
    'speed_limit' => 120,
    'device_limit' => 2,
    'transfer_enable' => 100,
    'u' => 0,
    'd' => 0,
    'banned' => false,
    'expired_at' => $now + 3600,
    'balance' => 100,
    'created_at' => $now,
    'updated_at' => $now,
]);

Capsule::table('v2_user_traffic_packages')->insert([
    [
        'id' => 10,
        'user_id' => 1,
        'order_id' => 1,
        'plan_id' => null,
        'traffic_package_id' => 1,
        'total_bytes' => 100,
        'remaining_bytes' => 100,
        'status' => 'active',
        'depleted_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ],
    [
        'id' => 20,
        'user_id' => 1,
        'order_id' => 2,
        'plan_id' => null,
        'traffic_package_id' => 2,
        'total_bytes' => 100,
        'remaining_bytes' => 100,
        'status' => 'active',
        'depleted_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ],
]);

$service = new TrafficPackageService();

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

$user = User::findOrFail(1);
$service->syncAccessProfile($user);
$assertSame(1, (int) $user->group_id, 'plan balance keeps plan access');

Capsule::table('v2_user')->where('id', 1)->update(['u' => 90]);
$user->refresh();
$result = $service->consume(1, 10, 0);
$user->refresh();
$assertSame(10, $result['plan_upload'], 'last plan bytes are consumed first');
$assertSame(0, $result['package_upload'], 'package is untouched at exact plan boundary');
$assertSame(3, (int) $user->group_id, 'exact plan exhaustion switches to first package');

Capsule::table('v2_user')->where('id', 1)->update(['u' => 100]);
$user->refresh();
$user->balance = 999;
$result = $service->consume(1, 101, 0);
$user->refresh();
$assertSame(101, $result['package_upload'], 'package traffic continues after plan exhaustion');
$assertSame(2, (int) $user->group_id, 'FIFO depletion switches to next package');
$assertSame(100, (int) Capsule::table('v2_user')->where('id', 1)->value('balance'), 'access sync does not persist unrelated dirty fields');
$assertSame('depleted', Capsule::table('v2_user_traffic_packages')->where('id', 10)->value('status'), 'first package is depleted');
$assertSame(99, (int) Capsule::table('v2_user_traffic_packages')->where('id', 20)->value('remaining_bytes'), 'second package funds the remainder');

Capsule::table('v2_user')->where('id', 1)->update(['u' => 0, 'd' => 0]);
$user->refresh();
$service->syncAccessProfile($user);
$user->refresh();
$assertSame(1, (int) $user->group_id, 'restored plan balance switches back to plan access');

Capsule::table('v2_user')->where('id', 1)->update(['u' => 100]);
Capsule::table('v2_user_traffic_packages')->whereIn('id', [10, 20])->update([
    'remaining_bytes' => 0,
    'status' => 'depleted',
]);
Capsule::table('v2_user_traffic_packages')->insert([
    'id' => 30,
    'user_id' => 1,
    'order_id' => 3,
    'plan_id' => 10,
    'traffic_package_id' => null,
    'total_bytes' => 50,
    'remaining_bytes' => 50,
    'status' => 'active',
    'depleted_at' => null,
    'created_at' => $now,
    'updated_at' => $now,
]);
$user->refresh();
$service->syncAccessProfile($user);
$user->refresh();
$assertSame(4, (int) $user->group_id, 'legacy package access resolves through plan source');

echo "traffic package access switching runtime test passed\n";
