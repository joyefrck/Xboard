<?php
declare(strict_types=1);

// Disposable in-memory database. Never boot the application or read .env.
require dirname(__DIR__) . '/vendor/autoload.php';
foreach (['Btcpay', 'Coinbase', 'CoinPayments'] as $plugin) {
    require_once dirname(__DIR__) . "/plugins/{$plugin}/Plugin.php";
}

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\{Order, Plan, User};
use App\Services\Auth\{LoginService, MailLinkService};
use App\Services\OrderService;
use App\Services\UserService;
use App\Utils\{CacheKey, Helper};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Cache, Facade};

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setEventDispatcher(new Illuminate\Events\Dispatcher($db->getContainer()));
$db->setAsGlobal();
$db->bootEloquent();
$app = $db->getContainer();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('app', $app);
$app->instance('db', $db->getDatabaseManager());
$app->instance('log', new Psr\Log\NullLogger());
$app['config']['app.timezone'] = 'UTC';
$app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$app->instance(App\Support\Setting::class, new class extends App\Support\Setting {
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed
    {
        return $key === 'login_with_mail_link_enable' ? 1 : $default;
    }
});
$responses = Mockery::mock(Illuminate\Contracts\Routing\ResponseFactory::class);
$responses->shouldReceive('json')->andReturnUsing(fn ($data = [], $status = 200) => new JsonResponse($data, $status));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, $responses);

$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $call, string $message): void
{
    try {
        $result = $call();
    } catch (ApiException) {
        check(true, $message);
        return;
    }
    check($result === false, $message);
}
function deliver(object $plugin, array $body, string $header, string $signature): mixed
{
    $request = Request::create('/notify', 'POST', [], [], [], [], json_encode($body));
    $request->headers->set($header, $signature);
    Container::getInstance()->instance('request', $request);
    return $plugin->notify($body);
}

// BTCPay: only the live invoice state settles an order.
$btcpay = new class('btcpay') extends Plugin\Btcpay\Plugin {
    public ?array $invoice = null;
    protected function fetchInvoice(string $invoiceId): ?array { return $this->invoice; }
};
$btcpay->setConfig(['btcpay_webhook_key' => 'secret']);
$body = ['type' => 'InvoiceCreated', 'invoiceId' => 'inv-1'];
$sign = fn (array $b, string $key = 'secret') => 'sha256=' . hash_hmac('sha256', json_encode($b), $key);
$invoice = fn (string $status, string $id = 'inv-1') => ['id' => $id, 'status' => $status, 'metadata' => ['orderId' => 'order-1']];
foreach (['New', 'Processing', 'Expired', 'Invalid'] as $status) {
    $btcpay->invoice = $invoice($status);
    check(deliver($btcpay, $body, 'Btcpay-Sig', $sign($body)) === 'ignored', "BTCPay {$status} invoice credited");
}
$btcpay->invoice = $invoice('Settled');
check(deliver($btcpay, $body, 'Btcpay-Sig', $sign($body)) === ['trade_no' => 'order-1', 'callback_no' => 'inv-1'], 'settled BTCPay invoice rejected');
rejects(fn () => deliver($btcpay, $body, 'Btcpay-Sig', $sign($body, 'other')), 'forged BTCPay signature accepted');
$btcpay->invoice = $invoice('Settled', 'inv-2');
rejects(fn () => deliver($btcpay, $body, 'Btcpay-Sig', $sign($body)), 'mismatched BTCPay invoice accepted');
$btcpay->invoice = null;
rejects(fn () => deliver($btcpay, $body, 'Btcpay-Sig', $sign($body)), 'unverifiable BTCPay invoice accepted');
$btcpay->invoice = $invoice('Settled');
$btcpay->setConfig(['btcpay_webhook_key' => '']);
rejects(fn () => deliver($btcpay, $body, 'Btcpay-Sig', $sign($body, '')), 'empty BTCPay secret accepted');

// Coinbase: every event type is signed, only charge:confirmed is a payment.
$coinbase = new Plugin\Coinbase\Plugin('coinbase');
$coinbase->setConfig(['coinbase_webhook_key' => 'secret']);
$event = fn (string $type) => ['event' => ['id' => 'evt-1', 'type' => $type, 'data' => ['metadata' => ['outTradeNo' => 'order-1']]]];
$hmac = fn (array $b, string $key = 'secret') => hash_hmac('sha256', json_encode($b), $key);
foreach (['charge:created', 'charge:pending', 'charge:failed', 'charge:delayed'] as $type) {
    check(deliver($coinbase, $event($type), 'X-Cc-Webhook-Signature', $hmac($event($type))) === 'ignored', "Coinbase {$type} credited");
}
$paid = $event('charge:confirmed');
check(deliver($coinbase, $paid, 'X-Cc-Webhook-Signature', $hmac($paid)) === ['trade_no' => 'order-1', 'callback_no' => 'evt-1'], 'confirmed Coinbase charge rejected');
rejects(fn () => deliver($coinbase, $paid, 'X-Cc-Webhook-Signature', $hmac($paid, 'other')), 'forged Coinbase signature accepted');
$coinbase->setConfig(['coinbase_webhook_key' => '']);
rejects(fn () => deliver($coinbase, $paid, 'X-Cc-Webhook-Signature', $hmac($paid, '')), 'empty Coinbase secret accepted');

// CoinPayments: pending acknowledgement and signature still work.
$coinpayments = new Plugin\CoinPayments\Plugin('coin_payments');
$coinpayments->setConfig(['coinpayments_merchant_id' => 'm', 'coinpayments_ipn_secret' => 'secret']);
$ipn = fn (int $status) => ['item_number' => 'order-1', 'merchant' => 'm', 'status' => $status, 'txn_id' => 'txn-1'];
$ipnSign = fn (array $b) => hash_hmac('sha512', http_build_query($b), 'secret');
check(deliver($coinpayments, $ipn(1), 'Hmac', $ipnSign($ipn(1))) === 'IPN OK: pending', 'CoinPayments pending not acknowledged');
check(deliver($coinpayments, $ipn(100), 'Hmac', $ipnSign($ipn(100)))['trade_no'] === 'order-1', 'CoinPayments payment rejected');

$schema = $db->schema();
$schema->create('v2_user', function (Blueprint $t) {
    $t->increments('id');
    $t->string('email');
    $t->string('password')->nullable();
    $t->string('password_algo')->nullable();
    $t->string('password_salt')->nullable();
    $t->boolean('banned')->default(false);
    foreach (['balance', 'discount', 'invite_user_id', 'plan_id', 'group_id', 'expired_at', 'last_login_at', 'created_at', 'updated_at', 'custom_pending_order_id'] as $name) $t->integer($name)->nullable();
});
$schema->create('v2_order', function (Blueprint $t) {
    $t->increments('id');
    $t->integer('user_id');
    $t->string('trade_no');
    $t->string('period')->nullable();
    $t->boolean('no_proration')->default(false);
    foreach (['plan_id', 'type', 'status', 'total_amount', 'balance_amount', 'discount_amount', 'surplus_amount', 'refund_amount', 'commission_balance', 'invite_user_id', 'created_at', 'updated_at'] as $name) $t->integer($name)->default(0)->nullable();
});

// Controller: unknown orders fail, acknowledgements never reach an order.
$service = Mockery::mock('overload:App\Services\PaymentService');
$service->shouldReceive('notify')->andReturnUsing(fn ($p) => ($p['ack'] ?? false) ? 'ignored' : ['trade_no' => 'missing', 'callback_no' => 'cb']);
$controller = new PaymentController();
$missing = $controller->notify('X', 'u', Request::create('/', 'POST'));
check($missing instanceof JsonResponse && $missing->getStatusCode() >= 400, 'unknown order acknowledged as success');
check($controller->notify('X', 'u', Request::create('/', 'POST', ['ack' => 1])) === 'ignored', 'non-payment event not acknowledged');

// Amounts are rounded to cents, never truncated.
$planService = Mockery::mock('overload:App\Services\PlanService');
$planService->shouldReceive('validatePurchase');
$planService->shouldReceive('getPeriodKey')->andReturnUsing(fn ($p) => $p);
$app->instance(UserService::class, new UserService());
foreach (['19.99' => 1999, '35.8' => 3580, '1.15' => 115, '100' => 10000] as $price => $cents) {
    $user = User::forceCreate(['email' => "buyer{$cents}@test.com"]);
    $plan = new Plan();
    $plan->id = 1;
    $plan->plan_type = Plan::TYPE_STANDARD;
    $plan->prices = ['monthly' => (float) $price];
    $order = OrderService::createFromRequest($user, $plan, 'monthly');
    check((int) $order->total_amount === $cents, "price {$price} became {$order->total_amount}");
}

// Email identity: case variants share one limit; accent variants are different people.
foreach (CacheKey::EMAIL_KEYS as $key) {
    check(CacheKey::get($key, ' User@Test.COM') === CacheKey::get($key, 'user@test.com'), "{$key} splits by case");
}
check(CacheKey::get('TEMP_TOKEN', 'AbC') === 'TEMP_TOKEN_AbC', 'non-email key was normalized');
check(Helper::isSameEmail('User@test.com', 'user@TEST.com') && !Helper::isSameEmail('jose@test.com', 'josé@test.com'), 'email identity comparison');

// Emulate MySQL utf8mb4_unicode_ci equality, including its accent folding.
$db->schema()->drop('v2_user');
$db->getConnection()->getPdo()->sqliteCreateCollation('UCI', fn ($a, $b) => strcmp(strtr(mb_strtolower($a), ['é' => 'e']), strtr(mb_strtolower($b), ['é' => 'e'])));
$db->getConnection()->statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT COLLATE UCI, password TEXT, password_algo TEXT, password_salt TEXT, banned INTEGER DEFAULT 0, last_login_at INTEGER, created_at INTEGER, updated_at INTEGER)');
$user = User::forceCreate(['email' => 'Jose@test.com', 'password' => password_hash('correct-pass', PASSWORD_BCRYPT, ['cost' => 4])]);
$login = new LoginService();
check($login->login('jose@TEST.com', 'correct-pass')[0] === true, 'stored mixed-case account cannot log in');
check($login->login('josé@test.com', 'correct-pass')[0] === false, 'accent variant logged in');
foreach (['jose@test.com', 'JOSE@test.com', 'Jose@test.com', 'jOSE@test.com', 'joSE@test.com'] as $variant) {
    check($login->login($variant, 'wrong')[1][0] === 400, 'wrong password accepted');
}
check($login->login('josE@test.com', 'correct-pass')[1][0] === 429, 'case variant bypassed the password limit');
Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'josé@test.com'), '123456', 300);
check($login->resetPassword('josé@test.com', '123456', 'taken-over')[0] === false, 'accent variant reset another account');
check(password_verify('correct-pass', $user->fresh()->password), 'password changed by accent variant');
foreach (['jose@test.com', 'JOSE@test.com', 'joSe@test.com'] as $variant) $login->resetPassword($variant, '000000', 'new-pass-1');
check($login->resetPassword('josE@test.com', '000000', 'new-pass-1')[1][0] === 429, 'case variant bypassed the reset limit');
check((new MailLinkService())->handleMailLink('josé@test.com') === [true, true], 'accent mail link response leaked');
check(Cache::get(CacheKey::get('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', 'josé@test.com')) === null, 'accent variant received a login link');

Mockery::close();
echo "Payment and email hardening: {$checks} checks passed\n";
