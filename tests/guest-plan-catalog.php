<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

require dirname(__DIR__) . '/vendor/autoload.php';

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$capsule->schema()->create('v2_plan', function (Blueprint $table): void {
    $table->integer('id')->primary();
    $table->string('name');
    $table->string('plan_type')->default('standard');
    $table->text('prices');
    $table->boolean('show');
    $table->boolean('sell');
    $table->integer('sort');
    $table->integer('capacity_limit')->nullable();
});
$capsule->schema()->create('v2_user', function (Blueprint $table): void {
    $table->integer('plan_id');
    $table->integer('expired_at')->nullable();
});

$base = ['show' => true, 'sell' => true, 'capacity_limit' => null];
foreach ([
    ['id' => 1, 'name' => '月付套餐', 'prices' => '{"monthly":35}', 'sort' => 1],
    ['id' => 2, 'name' => '不限时套餐', 'prices' => '{"onetime":100}', 'sort' => 3],
    ['id' => 3, 'name' => '隐藏套餐', 'prices' => '{"onetime":100}', 'sort' => 0, 'show' => false],
    ['id' => 4, 'name' => '停售套餐', 'prices' => '{"onetime":100}', 'sort' => 0, 'sell' => false],
    ['id' => 5, 'name' => '售罄套餐', 'prices' => '{"onetime":100}', 'sort' => 0, 'capacity_limit' => 1],
    ['id' => 6, 'name' => '年付套餐', 'prices' => '{"yearly":300}', 'sort' => 2],
] as $plan) {
    Capsule::table('v2_plan')->insert(array_merge($base, $plan));
}
Capsule::table('v2_user')->insert(['plan_id' => 5, 'expired_at' => null]);

$plans = (new PlanService(new Plan()))->getAvailablePlans();
if ($plans->pluck('id')->all() !== [1, 6, 2]) {
    throw new RuntimeException('Guest catalog must include sellable one-time plans in sort order and exclude hidden, stopped and sold-out plans; got ' . $plans->pluck('id')->toJson());
}
if ($plans->keys()->all() !== [0, 1, 2]) {
    throw new RuntimeException('Guest catalog must serialize as a JSON list.');
}
echo "Guest plan catalog regression passed.\n";
