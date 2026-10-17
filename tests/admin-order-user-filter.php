<?php

declare(strict_types=1);

// Isolated SQLite fixture: never boot the application or read its .env/database.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Controllers\V2\Admin\OrderController;
use App\Models\Order;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();
$db->schema()->create('v2_order', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('user_id');
    $table->string('trade_no');
    $table->integer('status');
});
foreach ([123, 1123, 1234, 123] as $index => $userId) {
    $db->table('v2_order')->insert([
        'id' => $index + 1, 'user_id' => $userId,
        'trade_no' => 'fixture-' . ($index + 1), 'status' => $index === 0 ? 0 : 3,
    ]);
}

$controller = new OrderController();
$apply = new ReflectionMethod($controller, 'applyFilters');
$query = function (array $filters) use ($controller, $apply) {
    $builder = Order::query();
    $apply->invoke($controller, new Request(['filter' => $filters]), $builder);
    return $builder->orderBy('id');
};
$checks = 0;
$check = function ($actual, $expected, string $message) use (&$checks) {
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . json_encode($actual));
    }
};
foreach (['123', 123, 'eq:123', [123]] as $value) {
    $filters = [['id' => 'user_id', 'value' => $value]];
    $check($query($filters)->pluck('id')->all(), [1, 4], 'user filter excludes IDs containing the same digits');
    $page = $query($filters)->paginate(1, ['*'], 'page', 2);
    $check($page->total(), 2, 'pagination total counts only this user');
    $check($page->pluck('id')->all(), [4], 'pagination includes only this user');
}
$check($query([
    ['id' => 'user_id', 'value' => '123'], ['id' => 'status', 'value' => [3]],
])->pluck('id')->all(), [4], 'user and status filters intersect');
$check($query([['id' => 'user_id', 'value' => '999']])->count(), 0, 'unknown user returns no orders');
$check($query([['id' => 'trade_no', 'value' => 'fixture-']])->count(), 4, 'order-number search remains fuzzy');
$check($query([])->count(), 4, 'unfiltered admin list keeps all orders');
echo "admin order user filter: {$checks} checks passed\n";
