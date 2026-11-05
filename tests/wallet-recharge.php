<?php
declare(strict_types=1);

// Disposable in-memory database. Never boot the application or read .env.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\User\OrderController;
use App\Models\{Order, Payment, User};
use App\Services\{OrderService, TelegramGroupEligibilityService, UserService};
use App\Services\Plugin\HookManager;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Facade;

function admin_setting($key = null, $default = null) { return $default; }
$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setEventDispatcher(new Illuminate\Events\Dispatcher($db->getContainer()));
$db->setAsGlobal(); $db->bootEloquent();
$app = $db->getContainer(); Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('app', $app);
$app->instance('db', $db->getDatabaseManager());
$app->instance('log', new Psr\Log\NullLogger());
$app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
$validator = new Illuminate\Validation\Factory($app['translator'], $app);
Request::macro('validate', function (array $rules) use ($validator) { return $validator->make($this->all(), $rules)->validate(); });
$app->instance(UserService::class, new UserService());
$app->instance(Illuminate\Contracts\Bus\Dispatcher::class, new class {
    public function dispatchSync($job, $handler = null) { return $job->handle(); }
});
$responseFactory = Mockery::mock(Illuminate\Contracts\Routing\ResponseFactory::class);
$responseFactory->shouldReceive('json')->andReturnUsing(fn ($data = [], $status = 200) => new JsonResponse($data, $status));
$responseFactory->shouldReceive('make')->andReturnUsing(fn ($data = '', $status = 200, $headers = []) => new Illuminate\Http\Response($data, $status, $headers));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, $responseFactory);
$schema = $db->schema();
$schema->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->integer('balance')->default(190); $t->integer('discount')->default(90);
    $t->integer('invite_user_id')->nullable(); $t->integer('commission_type')->default(2); $t->integer('commission_rate')->default(10);
    foreach (['plan_id', 'group_id', 'expired_at', 'transfer_enable', 'u', 'd', 'speed_limit', 'device_limit'] as $name) $t->integer($name)->default(123);
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_order', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->integer('plan_id')->nullable(); $t->integer('traffic_package_id')->nullable();
    $t->string('period'); $t->string('trade_no')->unique(); $t->integer('type'); $t->integer('status')->default(0);
    foreach (['total_amount', 'balance_amount', 'commission_balance', 'handling_amount'] as $name) $t->integer($name)->default(0)->nullable();
    $t->integer('payment_id')->nullable(); $t->string('callback_no')->nullable(); $t->integer('paid_at')->nullable();
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_payment', function (Blueprint $t) {
    $t->increments('id'); $t->string('name'); $t->string('payment')->default('fixture'); $t->string('icon')->default('');
    $t->boolean('enable')->default(true); $t->integer('sort')->default(0); $t->integer('handling_fee_fixed')->default(0);
    $t->float('handling_fee_percent')->default(0); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$checks = 0;
function check($value, string $message): void {
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
$paymentService = Mockery::mock('overload:App\Services\PaymentService');
$paymentService->shouldReceive('pay')->andReturnUsing(function ($payload) {
    $GLOBALS['lastPayment'] = $payload;
    return ['type' => 1, 'data' => 'https://payments.example.test/fixture'];
});
$paymentService->shouldReceive('notify')->andReturnUsing(fn ($payload) => [
    'trade_no' => $payload['trade_no'], 'callback_no' => 'verified-fixture',
]);
$controller = new OrderController();
$request = function (User $user, array $payload): Request {
    $request = Request::create('/fixture', 'POST', $payload);
    $request->setUserResolver(fn () => $user);
    return $request;
};

foreach (Order::RECHARGE_AMOUNTS as $amount) {
    $user = User::create(); $before = $user->fresh()->getAttributes();
    $order = OrderService::createRechargeFromRequest($user, $amount);
    check($order->isRecharge() && !$order->plan_id, 'dedicated planless recharge');
    check($user->fresh()->balance === 190 && !$order->balance_amount && !$order->commission_balance, 'no balance, discount or commission deduction');
    $order = $order->fresh(); // Real callbacks re-query the persisted order.
    $stale = Order::find($order->id);
    check((new OrderService($order))->paid('fixture-callback'), 'normal paid path completes');
    check($order->fresh()->status === Order::STATUS_COMPLETED && $user->fresh()->balance === 190 + $amount, 'credit principal');
    check((new OrderService($stale))->paid('repeated-callback'), 'stale callback accepted');
    (new OrderService($stale))->open();
    check($user->fresh()->balance === 190 + $amount, 'duplicate callback and job cannot credit twice');
    foreach (['plan_id', 'group_id', 'expired_at', 'transfer_enable', 'u', 'd', 'speed_limit', 'device_limit'] as $key) {
        check($user->fresh()->getAttribute($key) === $before[$key], 'subscription unchanged: ' . $key);
    }
    check(!TelegramGroupEligibilityService::isPaidOrder($order->fresh()), 'no paid-order group entitlement');
}
$user = User::create();
foreach ([-100, 0, 100, 15000, 99999] as $amount) {
    try { OrderService::createRechargeFromRequest($user, $amount); throw new RuntimeException('invalid amount accepted'); }
    catch (ApiException $e) { check(true, 'invalid principal rejected'); }
}
$order = OrderService::createRechargeFromRequest($user, 10000);
try { OrderService::createRechargeFromRequest($user, 20000); throw new RuntimeException('pending duplicate accepted'); }
catch (ApiException $e) { check(true, 'pending-order guard'); }
check((new OrderService($order->fresh()))->cancel(), 'cancel pending recharge');
(new OrderService($order->fresh()))->paid('late-callback');
check($user->fresh()->balance === 190, 'cancelled order does not credit');

// A save failure rolls the credit back, and a processing order can then retry.
$order = OrderService::createRechargeFromRequest($user, 20000);
$order->status = Order::STATUS_PROCESSING; $order->save();
Order::saving(function (Order $candidate) { if ($candidate->isRecharge() && $candidate->status === Order::STATUS_COMPLETED) throw new RuntimeException('fixture disk failure'); });
try { (new OrderService($order))->open(); throw new LogicException('save failure ignored'); }
catch (RuntimeException $e) { check($e->getMessage() === 'fixture disk failure', 'completion failure raised'); }
check($user->fresh()->balance === 190 && $order->fresh()->status === 1, 'atomic rollback retains processing order');
Order::flushEventListeners();
(new OrderService($order))->open();
check($user->fresh()->balance === 20190, 'retry credits once after rollback');

$vetoUser = User::create(); $vetoOrder = OrderService::createRechargeFromRequest($vetoUser, 10000)->fresh();
$vetoOrder->status = 1; $vetoOrder->save();
User::saving(fn () => false);
try { (new OrderService($vetoOrder))->open(); throw new LogicException('vetoed balance save ignored'); }
catch (RuntimeException $e) { check($e->getMessage() === '充值余额保存失败', 'balance save veto raised'); }
check($vetoUser->fresh()->balance === 190 && $vetoOrder->fresh()->status === 1, 'save veto leaves balance and status unchanged');
User::flushEventListeners();
(new OrderService($vetoOrder))->open();
check($vetoUser->fresh()->balance === 10190, 'retry after veto credits once');

$user = User::create(); $order = OrderService::createRechargeFromRequest($user, 10000);
$order->status = 1; $order->total_amount = 15000; $order->save();
try { (new OrderService($order))->open(); throw new RuntimeException('invalid stored amount accepted'); }
catch (ApiException $e) { check($user->fresh()->balance === 190, 'tampered amount cannot credit'); }
$order->status = 2; $order->save();

// Execute the real checkout controller; only the external provider is mocked.
$payment = Payment::create(['name' => 'First', 'sort' => 1]);
foreach ([[0, 0, 0], [200, 0, 200], [0, 2.25, 225], [123, 1.234, 246]] as [$fixed, $percent, $expected]) {
    $payment->update(['handling_fee_fixed' => $fixed, 'handling_fee_percent' => $percent]);
    $order = OrderService::createRechargeFromRequest($user, 10000);
    $response = $controller->checkout($request($user, ['trade_no' => $order->trade_no, 'method' => $payment->id]));
    check($response->getStatusCode() === 200, 'checkout succeeds');
    check($GLOBALS['lastPayment']['total_amount'] === 10000 + $expected, 'existing configured fee calculation');
    check($order->fresh()->total_amount === 10000, 'fees do not change credited principal');
    $other = User::create();
    check($controller->checkout($request($other, ['trade_no' => $order->trade_no, 'method' => $payment->id]))->getStatusCode() === 400, 'cannot pay another user order');
    $payment->enable = false; $payment->save();
    check($controller->checkout($request($user, ['trade_no' => $order->trade_no, 'method' => $payment->id]))->getStatusCode() === 400, 'disabled channel rejected at checkout');
    $payment->enable = true; $payment->save();
    (new OrderService($order->fresh()))->cancel();
}
Payment::create(['name' => 'Before', 'sort' => 0]); Payment::create(['name' => 'Disabled', 'sort' => -1, 'enable' => false]);
$methods = $controller->getPaymentMethod()->getData(true)['data'];
check(array_column($methods, 'name') === ['Before', 'First'], 'enabled methods preserve backend sort');
check(!(new TelegramGroupEligibilityService())->paidOrders()->where('type', 6)->exists(), 'historical entitlement query excludes recharge');

// Exercise input validation and the normal guest callback, with only signature verification mocked.
foreach ([10000, 20000, 50000] as $amount) {
    $buyer = User::create();
    $created = $controller->recharge($request($buyer, ['amount' => $amount]))->getData(true);
    $recharge = Order::where('trade_no', $created['data'])->firstOrFail();
    $notify = new App\Http\Controllers\V1\Guest\PaymentController();
    $callback = Request::create('/fixture', 'POST', ['trade_no' => $recharge->trade_no]);
    check($notify->notify('fixture', 'fixture-uuid', $callback) === 'success', 'existing guest callback succeeds');
    check($notify->notify('fixture', 'fixture-uuid', $callback) === 'success', 'duplicate guest callback succeeds');
    check($buyer->fresh()->balance === 190 + $amount, 'guest callback credits only once');
    $detailRequest = $request($buyer, ['trade_no' => $recharge->trade_no]);
    $app->instance('request', $detailRequest);
    $detail = $controller->detail($detailRequest)->getData(true);
    check($detail['data']['type'] === 6 && $detail['data']['plan'] === null, 'planless recharge detail is available');
    check($controller->detail($request($user, ['trade_no' => $recharge->trade_no]))->getStatusCode() === 400, 'another user cannot read recharge detail');
}
foreach ([[], ['amount' => 15000], ['amount' => 10000.1], ['amount' => -10000], ['amount' => 'abc']] as $payload) {
    try { $controller->recharge($request(User::create(), $payload)); throw new RuntimeException('invalid request accepted'); }
    catch (Illuminate\Validation\ValidationException $e) { check(isset($e->errors()['amount']), 'endpoint validates fixed integer principal'); }
}
$inviter = User::create(); $buyer = User::create(['invite_user_id' => $inviter->id]);
$recharge = OrderService::createRechargeFromRequest($buyer, 10000)->fresh();
(new OrderService($recharge))->paid('first-recharge');
$purchase = new Order(['user_id' => $buyer->id, 'type' => 1, 'period' => 'monthly', 'total_amount' => 10000]);
(new OrderService($purchase))->setInvite($buyer);
check((int) $purchase->commission_balance === 1000, 'recharge does not consume first-purchase commission eligibility');
Mockery::close();
echo "Wallet recharge: {$checks} checks passed\n";
