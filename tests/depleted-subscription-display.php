<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TrafficPackageService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

require dirname(__DIR__) . '/vendor/autoload.php';

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

foreach (['v2_plan', 'v2_traffic_packages'] as $tableName) {
    $capsule->schema()->create($tableName, function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->string('name');
    });
}
$capsule->schema()->create('v2_user_traffic_packages', function (Blueprint $table): void {
    $table->integer('id')->primary();
    $table->integer('user_id');
    $table->integer('plan_id')->nullable();
    $table->integer('traffic_package_id')->nullable();
    $table->bigInteger('total_bytes');
    $table->bigInteger('remaining_bytes');
    $table->string('status');
});

$gib = 1073741824;
Capsule::table('v2_traffic_packages')->insert(['id' => 1, 'name' => '不限时流量包180G']);
Capsule::table('v2_plan')->insert(['id' => 2, 'name' => '旧版不限时流量包']);
foreach ([
    [1, 1, null, 1, 50 * $gib, 0, 'depleted'],
    [2, 1, null, 1, 180 * $gib, 0, 'depleted'],
    [3, 2, 2, null, 100 * $gib, 0, 'depleted'],
    [4, 3, null, 1, 180 * $gib, 0, 'active'],
    [5, 4, null, 1, 180 * $gib, 0, 'cancelled'],
    [6, 5, null, 1, 180 * $gib, 100 * $gib, 'active'],
] as $row) {
    Capsule::table('v2_user_traffic_packages')->insert(array_combine(
        ['id', 'user_id', 'plan_id', 'traffic_package_id', 'total_bytes', 'remaining_bytes', 'status'],
        $row
    ));
}

$service = new TrafficPackageService();
$fixtures = [];
foreach ([1 => 'depleted', 2 => 'legacy', 3 => 'zeroActive', 4 => 'cancelled', 5 => 'available', 6 => 'empty'] as $id => $key) {
    $user = (new User())->forceFill(['id' => $id, 'plan_id' => null, 'transfer_enable' => 0]);
    $summary = $service->getTrafficSummary($user);
    $summary['depleted_traffic_package'] = $summary['traffic_package_remaining'] > 0
        ? null : $service->getLatestDepletedPackage($user);
    $fixtures[$key] = $summary;
}

if ($fixtures['depleted']['depleted_traffic_package']['total_bytes'] !== 180 * $gib
    || $fixtures['depleted']['active_product_type'] !== 'none'
    || $fixtures['depleted']['effective_transfer_enable'] !== 0
    || $fixtures['depleted']['effective_remaining_traffic'] !== 0
    || $fixtures['depleted']['traffic_package_total'] !== 0
    || $service->hasActivePackageBalance(1)
    || $fixtures['legacy']['depleted_traffic_package']['name'] !== '旧版不限时流量包'
    || $fixtures['zeroActive']['depleted_traffic_package'] === null
    || $fixtures['cancelled']['depleted_traffic_package'] !== null
    || $fixtures['available']['depleted_traffic_package'] !== null
    || $fixtures['empty']['depleted_traffic_package'] !== null) {
    throw new RuntimeException('Depleted display data must preserve access eligibility and account isolation.');
}

// Repurchase on the same account after depletion: historical quota must not accumulate.
Capsule::table('v2_user_traffic_packages')->insert([
    'id' => 7,
    'user_id' => 1,
    'traffic_package_id' => 1,
    'total_bytes' => 180 * $gib,
    'remaining_bytes' => 180 * $gib,
    'status' => 'active',
]);
$user = (new User())->forceFill(['id' => 1, 'plan_id' => null, 'transfer_enable' => 0]);
foreach ([
    ['repurchased', 180 * $gib, 'active'],
    ['repurchasedUsed', 170 * $gib, 'active'],
    ['repurchasedDepleted', 0, 'depleted'],
] as [$key, $remaining, $status]) {
    Capsule::table('v2_user_traffic_packages')->where('id', 7)->update([
        'remaining_bytes' => $remaining,
        'status' => $status,
    ]);
    $summary = $service->getTrafficSummary($user);
    $summary['depleted_traffic_package'] = $summary['traffic_package_remaining'] > 0
        ? null : $service->getLatestDepletedPackage($user);
    $fixtures[$key] = $summary;
}

echo json_encode($fixtures, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
