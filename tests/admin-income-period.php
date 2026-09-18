<?php

declare(strict_types=1);

use App\Http\Controllers\V2\Admin\StatController;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('Asia/Shanghai');
$validator = new Factory(new Translator(new ArrayLoader(), 'en'));
Request::macro('validate', function (array $rules) use ($validator) {
    return $validator->make($this->all(), $rules)->validate();
});
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$capsule->schema()->create('v2_stat', function (Blueprint $table): void {
    $table->increments('id');
    $table->string('record_type');
    $table->integer('record_at');
    foreach (['paid_total', 'paid_count', 'commission_total', 'commission_count'] as $field) {
        $table->integer($field);
    }
});
foreach ([
    ['2023-12-31', 1200, 2, 120, 1, 'd'],
    ['2024-01-01', 3000, 1, 100, 1, 'd'],
    ['2024-01-31', 9000, 3, 500, 2, 'd'],
    ['2024-02-01', 1000, 1, 0, 0, 'd'],
    ['2024-02-29', 5000, 2, 300, 1, 'd'],
    ['2024-03-01', 7777, 1, 0, 0, 'd'],
    ['2024-12-31', 8000, 4, 0, 0, 'd'],
    ['2025-01-01', 9999, 1, 0, 0, 'd'],
    ['2024-01-01', 999999, 999, 999, 99, 'm'],
] as [$date, $paid, $count, $commission, $commissionCount, $type]) {
    Capsule::table('v2_stat')->insert([
        'record_type' => $type, 'record_at' => strtotime($date),
        'paid_total' => $paid, 'paid_count' => $count,
        'commission_total' => $commission, 'commission_count' => $commissionCount,
    ]);
}
// getOrder does not need the Redis-backed service used by other controller methods.
$controller = (new ReflectionClass(StatController::class))->newInstanceWithoutConstructor();
function stats(array $params): array {
    global $controller;
    return $controller->getOrder(Request::create('/', 'GET', $params))['data'];
}
function same(mixed $actual, mixed $expected, string $message): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . json_encode([$actual, $expected]));
    }
}
$daily = stats(['start_date' => '2024-01-01', 'end_date' => '2024-01-31']);
same($daily['period'], 'day', 'Default is daily');
same(array_column($daily['list'], 'date'), ['2024-01-01', '2024-01-31'], 'Daily order and inclusive end');
same($daily['summary']['paid_total'], 12000, 'Only daily records contribute');
$monthly = stats(['period' => 'month', 'start_date' => '2023-12-15', 'end_date' => '2024-02-10']);
same(array_column($monthly['list'], 'date'), ['2023-12', '2024-01', '2024-02'], 'Monthly cross-year order');
same($monthly['summary']['start_date'], '2023-12-01', 'Month start expands');
same($monthly['summary']['end_date'], '2024-02-29', 'Leap month expands');
same($monthly['summary']['paid_total'], 19200, 'Monthly summary excludes next month');
same($monthly['list'][1]['paid_count'], 4, 'Monthly count sums');
same($monthly['list'][1]['avg_order_amount'], 3000.0, 'Weighted order average');
same($monthly['list'][1]['commission_total'], 600, 'Monthly commission sums');
same($monthly['list'][1]['avg_commission_amount'], 200.0, 'Weighted commission average');
same($monthly['summary']['avg_paid_amount'], 2133.33, 'Weighted summary average');
$yearly = stats(['period' => 'year', 'start_date' => '2023-12-15', 'end_date' => '2024-02-10']);
same(array_column($yearly['list'], 'date'), ['2023', '2024'], 'Yearly grouping');
same($yearly['summary']['start_date'], '2023-01-01', 'Year start expands');
same($yearly['summary']['end_date'], '2024-12-31', 'Year end expands');
same($yearly['list'][1]['paid_total'], 33777, 'Full year includes December and excludes next January');
foreach (['paid_total', 'paid_count', 'commission_total', 'commission_count'] as $field) {
    $typed = stats(['period' => 'month', 'start_date' => '2024-01-01', 'end_date' => '2024-01-31', 'type' => $field]);
    same($typed['list'][0]['value'], $monthly['list'][1][$field], 'Type filter uses grouped values');
}
$empty = stats(['period' => 'year', 'start_date' => '2020-01-01', 'end_date' => '2021-12-31']);
same($empty['list'], [], 'Empty range remains empty');
same($empty['summary']['avg_paid_amount'], 0, 'Empty average safe');
same($empty['summary']['commission_rate'], 0, 'Empty rate safe');
foreach ([['period' => 'week'], ['start_date' => '2024-03-01', 'end_date' => '2024-02-01'], ['end_date' => '2024-02-30']] as $params) {
    try {
        stats($params);
        throw new RuntimeException('Expected validation failure: ' . json_encode($params));
    } catch (ValidationException $exception) {
        // Expected; no database request for invalid ranges or periods.
    }
}
same(stats(['end_date' => '2023-12-31'])['summary']['paid_total'], 1200, 'End-only filter remains supported');
Capsule::table('v2_stat')->delete();
same(stats([])['summary']['start_date'], date('Y-m-d'), 'Unbounded empty default date is stable');
echo "Income period API regression passed (daily/monthly/yearly, calendar boundaries, totals, averages, validation).\n";
