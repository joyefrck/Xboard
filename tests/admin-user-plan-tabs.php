<?php

declare(strict_types=1);

// Only an in-memory database: do not boot the application or load .env.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\UserController;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();
$db->schema()->create('v2_plan', function (Blueprint $table) {
    $table->increments('id');
    $table->string('plan_type')->nullable();
});
$db->schema()->create('v2_user', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('plan_id')->nullable();
    $table->string('email');
    $table->integer('expired_at')->nullable();
});
$db->table('v2_plan')->insert([
    ['id' => 1, 'plan_type' => 'standard'],
    ['id' => 2, 'plan_type' => 'custom'],
    ['id' => 3, 'plan_type' => 'exclusive'],
    ['id' => 4, 'plan_type' => null],
]);
foreach ([1, 2, 3, null, 999, 4, 1, 3] as $index => $planId) {
    $db->table('v2_user')->insert([
        'id' => $index + 1, 'plan_id' => $planId,
        'email' => 'user' . ($index + 1) . '@example.test',
        'expired_at' => $index >= 6 ? 1 : null,
    ]);
}
$controller = new UserController();
$apply = new ReflectionMethod($controller, 'applyFilters');
$query = function (array $params) use ($controller, $apply) {
    $builder = User::query();
    $apply->invoke($controller, new Request($params), $builder);
    return $builder->orderBy('id');
};
$checks = 0;
$check = function ($actual, $expected, string $message) use (&$checks) {
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . json_encode($actual));
    }
};
foreach ([
    'all' => [1, 2, 3, 4, 5, 6, 7, 8],
    'standard' => [1, 6, 7],
    'custom' => [2],
    'exclusive' => [3, 8],
] as $type => $ids) {
    $check($query(['plan_type' => $type])->pluck('id')->all(), $ids, $type . ' classification');
}
$check($query([])->count(), 8, 'omitted type keeps all users');
$check($query(['plan_type' => 'exclusive', 'filter' => [['id' => 'email', 'value' => 'user8']]])->pluck('id')->all(), [8], 'email and type intersect');
$check($query(['plan_type' => 'custom', 'filter' => [['id' => 'plan_id', 'value' => 'eq:1']]])->count(), 0, 'conflicting advanced filter returns no users');
$page = $query(['plan_type' => 'standard'])->paginate(2, ['*'], 'page', 2);
$check($page->total(), 3, 'count includes only the selected type');
$check($page->pluck('id')->all(), [7], 'pagination follows server filtering');
// Opening a custom plan changes its tab via the current plan relationship.
$db->table('v2_user')->where('id', 2)->update(['plan_id' => 3]);
$check($query(['plan_type' => 'custom'])->count(), 0, 'activated user leaves custom');
$check($query(['plan_type' => 'exclusive'])->pluck('id')->all(), [2, 3, 8], 'activated user enters exclusive');
foreach (['invalid', '', null, ['exclusive']] as $invalid) {
    try {
        $query(['plan_type' => $invalid]);
        throw new RuntimeException('invalid type must not silently select all users');
    } catch (ApiException $exception) {
        $check($exception->getCode(), 400, 'invalid type rejected');
    }
}
echo "admin user plan tabs: {$checks} checks passed\n";
