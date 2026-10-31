<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$loader = require $root . '/vendor/autoload.php';
require $root . '/app/Utils/Helper.php';
require $root . '/plugins/Epay/Plugin.php';
require $root . '/plugins/Mgate/Plugin.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(\App\Utils\Helper::validateSubscriptionToken('0123456789abcdef0123456789abcdef') !== null, 'valid token rejected');
$assert(\App\Utils\Helper::validateSubscriptionToken('not-a-token') === null, 'malformed token accepted');
$assert(\App\Utils\Helper::validateSubscriptionToken(123) === null, 'non-string token accepted');

$epay = new \Plugin\Epay\Plugin('epay');
$epay->setConfig(['key' => 'secret', 'pid' => '42']);
$epayParams = [
    'money' => '1.00',
    'name' => 'x',
    'notify_url' => 'https://x.test/n',
    'out_trade_no' => 'order-1',
    'pid' => '42',
    'return_url' => 'https://x.test/r',
    'trade_no' => 'trade-1',
    'trade_status' => 'TRADE_SUCCESS',
];
ksort($epayParams);
$epayParams['sign'] = md5(stripslashes(urldecode(http_build_query($epayParams))) . 'secret');
$epayParams['sign_type'] = 'MD5';
$assert(is_array($epay->notify($epayParams)), 'valid Epay callback rejected');
$wrongPidParams = $epayParams;
$wrongPidParams['pid'] = '43';
unset($wrongPidParams['sign'], $wrongPidParams['sign_type']);
ksort($wrongPidParams);
$wrongPidParams['sign'] = md5(stripslashes(urldecode(http_build_query($wrongPidParams))) . 'secret');
$wrongPidParams['sign_type'] = 'MD5';
$epay->setConfig(['key' => 'secret', 'pid' => '42']);
$assert($epay->notify($wrongPidParams) === false, 'wrong Epay merchant accepted');

$mgate = new \Plugin\Mgate\Plugin('mgate');
$mgate->setConfig(['mgate_app_id' => 'app-42', 'mgate_app_secret' => 'secret']);
$mgateParams = ['app_id' => 'app-42', 'out_trade_no' => 'order-1', 'status' => 'paid', 'trade_no' => 'trade-1'];
ksort($mgateParams);
$mgateParams['sign'] = md5(http_build_query($mgateParams) . 'secret');
$assert(is_array($mgate->notify($mgateParams)), 'valid MGate callback rejected');
$assert($mgate->notify(['sign' => [], 'out_trade_no' => 'order-1', 'trade_no' => 'trade-1']) === false, 'nonscalar MGate callback accepted');
$mgateCallback = static function (array $params): array {
    unset($params['sign']);
    ksort($params);
    $params['sign'] = md5(http_build_query($params) . 'secret');
    return $params;
};
foreach (['pending', 'failed'] as $status) {
    $assert($mgate->notify($mgateCallback(array_merge($mgateParams, ['status' => $status]))) === false, "MGate {$status} callback accepted");
}
$assert($mgate->notify($mgateCallback(['app_id' => 'other-app', 'out_trade_no' => 'order-1', 'status' => 'paid', 'trade_no' => 'trade-1'])) === false, 'wrong MGate merchant accepted');
$missingStatus = $mgateParams;
unset($missingStatus['status'], $missingStatus['sign']);
$assert($mgate->notify($mgateCallback($missingStatus)) === false, 'missing MGate status accepted');

$app = new \Illuminate\Container\Container();
\Illuminate\Container\Container::setInstance($app);
$app->instance('app', $app);
$app->instance('config', new \Illuminate\Config\Repository(['app' => ['timezone' => 'UTC']]));
$app->instance('translator', new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en'));
$capsule = new \Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->instance('db.schema', $capsule->schema());
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
\Illuminate\Support\Facades\Cache::swap(new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
$app->instance(\App\Support\Setting::class, new class extends \App\Support\Setting {
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'login_with_mail_link_enable' => 1,
            'app_url' => 'https://x.test',
            'app_name' => 'Test Board',
            default => $default,
        };
    }
});
$capsule->schema()->create('v2_user', function (\Illuminate\Database\Schema\Blueprint $table): void {
    $table->increments('id');
    $table->string('email')->nullable();
    $table->string('token')->nullable();
    $table->boolean('banned')->default(false);
});
$capsule->table('v2_user')->insert(['email' => 'existing@test', 'token' => '0123456789abcdef0123456789abcdef']);
$capsule->table('v2_user')->insert(['email' => 'second@test', 'token' => 'abcdef0123456789abcdef0123456789']);
$dispatcher = new class {
    public array $jobs = [];
    public function dispatch($job): mixed
    {
        $this->jobs[] = $job;
        return $job;
    }
};
$app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
$mailResult = (new \App\Services\Auth\MailLinkService())->handleMailLink('missing@test');
$assert($mailResult === [true, true], 'mail-link response leaked or rejected unknown user');
$existingMailResult = (new \App\Services\Auth\MailLinkService())->handleMailLink('existing@test');
$assert($existingMailResult === [true, true], 'mail-link response leaked for existing user');
$jobParams = (new \ReflectionProperty(\App\Jobs\SendEmailJob::class, 'params'))->getValue($dispatcher->jobs[0]);
$assert(($jobParams['template_name'] ?? null) === 'mailLogin', 'mail-link job used the wrong template');
$tokenService = new \App\Services\Auth\MailLinkService();
\Illuminate\Support\Facades\Cache::put('TEMP_TOKEN_single-use', 1, 300);
$assert($tokenService->handleTokenLogin('single-use') === 1, 'mail token was not consumed');
$assert($tokenService->handleTokenLogin('single-use') === null, 'mail token was replayable');
$auth = new class {
    public mixed $user = null;
    public function setUser($user): void { $this->user = $user; }
};
\Illuminate\Support\Facades\Auth::swap($auth);
$middleware = new \App\Http\Middleware\Client();
$request = \Mockery::mock();
$request->shouldReceive('route')->with('token')->andReturn('0123456789abcdef0123456789abcdef');
$request->shouldReceive('query')->with('token')->andReturn('abcdef0123456789abcdef0123456789');
$assert($middleware->handle($request, static fn(): string => 'ok') === 'ok', 'route token did not take precedence over query token');
$assert($auth->user->email === 'existing@test', 'route token selected the wrong user');
\Mockery::close();
$request = \Mockery::mock();
$request->shouldReceive('route')->with('token')->andReturn(null);
$request->shouldReceive('query')->with('token')->andReturn('abcdef0123456789abcdef0123456789');
$auth->user = null;
$assert($middleware->handle($request, static fn(): string => 'ok') === 'ok', 'query token was not accepted when route token was absent');
$assert($auth->user->email === 'second@test', 'query token selected the wrong user');
\Mockery::close();
$request = \Mockery::mock();
$request->shouldReceive('route')->with('token')->andReturn('malformed');
$request->shouldReceive('query')->with('token')->andReturn('abcdef0123456789abcdef0123456789');
try {
    $middleware->handle($request, static fn(): string => 'unexpected');
    $assert(false, 'malformed route token was accepted over a valid query token');
} catch (\App\Exceptions\ApiException) {
    $assert(true, 'malformed route token rejected before query fallback');
}
$payment = new \App\Models\Payment();
$payment->setRawAttributes(['config' => json_encode(['key' => 'secret']), 'name' => 'test']);
$assert(!array_key_exists('config', $payment->toArray()), 'payment config is visible by default');
$assert(array_key_exists('config', $payment->makeVisible('config')->toArray()), 'admin payment config access missing');
$capsule->schema()->create('v2_payment', function (\Illuminate\Database\Schema\Blueprint $table): void {
    $table->increments('id');
    $table->string('name');
    $table->string('payment');
    $table->text('config');
    $table->boolean('enable')->default(true);
    $table->string('uuid');
    $table->string('notify_domain')->nullable();
});
$capsule->table('v2_payment')->insert([
    ['id' => 1, 'name' => 'A', 'payment' => 'EPay', 'config' => json_encode(['marker' => 'A']), 'enable' => 1, 'uuid' => 'pay-a'],
    ['id' => 2, 'name' => 'B', 'payment' => 'EPay', 'config' => json_encode(['marker' => 'B']), 'enable' => 1, 'uuid' => 'pay-b'],
]);
$paymentPlugin = new class('fake') extends \App\Services\Plugin\AbstractPlugin {
    public function notify($params): array
    {
        return ['marker' => $this->getConfig('marker')];
    }
};
$pluginManager = new class($paymentPlugin) extends \App\Services\Plugin\PluginManager {
    public function __construct(private object $paymentPlugin) {}
    public function getEnabledPaymentPlugins(): array { return [$this->paymentPlugin]; }
};
$app->instance(\App\Services\Plugin\PluginManager::class, $pluginManager);
\App\Services\Plugin\HookManager::registerFilter('available_payment_methods', static function (array $methods): array {
    $methods['EPay'] = ['plugin_code' => 'fake'];
    $methods['Alias'] = ['plugin_code' => 'fake'];
    return $methods;
});
$paymentServiceA = new \App\Services\PaymentService('EPay', 1);
$assert($paymentServiceA->notify([])['marker'] === 'A', 'configured payment verifier was not used');
$paymentServiceB = new \App\Services\PaymentService('EPay', 2);
$assert($paymentServiceB->notify([])['marker'] === 'B', 'payment plugin config leaked across instances');
try {
    (new \App\Services\PaymentService('Alias', 1))->notify([]);
    $assert(false, 'callback method mismatch was accepted');
} catch (\App\Exceptions\ApiException) {
    $assert(true, 'callback method mismatch rejected');
}
$capsule->schema()->create('v2_order', function (\Illuminate\Database\Schema\Blueprint $table): void {
    $table->increments('id');
    $table->integer('user_id');
    $table->string('trade_no');
    $table->integer('status')->default(0);
    $table->integer('total_amount')->default(0);
});
$capsule->table('v2_order')->insert(['user_id' => 1, 'trade_no' => 'negative-order', 'status' => 0, 'total_amount' => -1]);
$responseFactory = new \Illuminate\Routing\ResponseFactory(
    \Mockery::mock(\Illuminate\Contracts\View\Factory::class),
    \Mockery::mock(\Illuminate\Routing\Redirector::class)
);
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $responseFactory);
$request = \Illuminate\Http\Request::create('/checkout', 'POST', ['trade_no' => 'negative-order']);
$request->setUserResolver(static function (): \App\Models\User {
    $user = new \App\Models\User();
    $user->setRawAttributes(['id' => 1]);
    return $user;
});
$negativeResponse = (new \App\Http\Controllers\V1\User\OrderController())->checkout($request);
$assert($negativeResponse->getStatusCode() === 400, 'negative order amount was not rejected');

$previousCache = \Illuminate\Support\Facades\Cache::getFacadeRoot();
$rejectDelete = new class extends \Illuminate\Cache\ArrayStore {
    public function forget($key): bool { return false; }
};
\Illuminate\Support\Facades\Cache::swap(new \Illuminate\Cache\Repository($rejectDelete));
\Illuminate\Support\Facades\Cache::put(\App\Utils\CacheKey::get('TEMP_TOKEN', 'delete-failure'), 1, 300);
$assert((new \App\Services\Auth\MailLinkService())->handleTokenLogin('delete-failure') === null, 'token login succeeded without consuming its cache entry');
\Illuminate\Support\Facades\Cache::swap($previousCache);

$required = [
    'app/Services/Auth/MailLinkService.php' => ["return [true, true];", "'template_name' => 'mailLogin'"],
    'app/Http/Middleware/Client.php' => ["query('token')", 'validateSubscriptionToken'],
    'app/Services/PaymentService.php' => ['clone $plugin', 'configuredMethod'],
    'app/Services/OrderService.php' => ['lockCurrentOrderWhenStatus(Order::STATUS_PENDING)'],
    'app/Services/GiftCardService.php' => ['GiftCardCode::whereKey($this->code->id)', 'lockForUpdate'],
    'app/Http/Controllers/V1/Guest/PaymentController.php' => ['wasPaymentApplied'],
];

foreach ($required as $file => $needles) {
    $contents = file_get_contents($root . '/' . $file);
    if ($contents === false) {
        fwrite(STDERR, "missing {$file}\n");
        exit(1);
    }
    foreach ($needles as $needle) {
        if (!str_contains($contents, $needle)) {
            fwrite(STDERR, "missing {$needle} in {$file}\n");
            exit(1);
        }
    }
}

fwrite(STDOUT, "security-p0 behavior checks passed\n");
