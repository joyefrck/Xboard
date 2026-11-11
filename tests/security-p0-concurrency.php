<?php

declare(strict_types=1);

function admin_setting($key = null, $default = null)
{
    return $default;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\OrderService;
use App\Services\TelegramGroupEligibilityService;
use App\Services\TrafficResetService;
use App\Services\UserService;
use App\Services\Plugin\HookManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;

$dsn = getenv('SECURITY_P0_DB_DSN') ?: '';
$dbUser = getenv('SECURITY_P0_DB_USER') ?: '';
$dbPassword = getenv('SECURITY_P0_DB_PASSWORD') ?: '';
if ($dsn === '' || $dbUser === '') {
    fwrite(STDERR, "SECURITY_P0_DB_DSN and SECURITY_P0_DB_USER are required\n");
    exit(2);
}
if (!preg_match('/\Amysql:host=(?:127\.0\.0\.1|localhost);port=\d+;dbname=security_p0\z/', $dsn)) {
    fwrite(STDERR, "SECURITY_P0_DB_DSN must target the disposable security_p0 database\n");
    exit(2);
}

$parseDsn = static function (string $value) use ($dbUser, $dbPassword): array {
    preg_match('/host=([^;]+).*port=([^;]+).*dbname=([^;]+)/', $value, $matches);
    return [
        'driver' => 'mysql',
        'host' => $matches[1],
        'port' => $matches[2],
        'database' => $matches[3],
        'username' => $dbUser,
        'password' => $dbPassword,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
    ];
};

$boot = static function () use ($parseDsn, $dsn): Capsule {
    $app = new Container();
    Container::setInstance($app);
    $app->instance('app', $app);
    $app->instance('config', new ConfigRepository(['app' => ['timezone' => 'UTC']]));
    $app->instance('log', new NullLogger());
    $capsule = new Capsule($app);
    $capsule->addConnection($parseDsn($dsn));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    $app->instance('db.schema', $capsule->schema());
    for ($attempt = 0; $attempt < 20; $attempt++) {
        try {
            $capsule->getConnection()->getPdo();
            break;
        } catch (Throwable $error) {
            $capsule->getDatabaseManager()->purge();
            usleep(100000);
        }
    }
    Facade::setFacadeApplication($app);
    Cache::swap(new Repository(new ArrayStore()));
    $app->instance(UserService::class, new UserService());
    $app->instance(TelegramGroupEligibilityService::class, new class extends TelegramGroupEligibilityService {
        public function grantFromOrder(Order $order): void {}
    });
    $app->instance(TrafficResetService::class, new class extends TrafficResetService {
        public function performReset(User $user, string $triggerSource = 'manual'): bool
        {
            return true;
        }
    });
    $app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new class {
        public function dispatchSync($job, $handler = null): mixed
        {
            return $job->handle();
        }
    });
    return $capsule;
};

$schema = static function (Capsule $capsule): void {
    $schema = $capsule->schema();
    foreach (['v2_gift_card_usage', 'v2_gift_card_code', 'v2_gift_card_template', 'v2_order', 'v2_plan', 'v2_user'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('v2_user', function (Blueprint $t): void {
        $t->increments('id'); $t->string('email')->nullable(); $t->string('uuid')->nullable(); $t->string('token')->nullable();
        $t->string('password')->nullable(); $t->integer('invite_user_id')->nullable(); $t->integer('plan_id')->nullable();
        $t->integer('group_id')->nullable(); $t->integer('expired_at')->nullable(); $t->bigInteger('transfer_enable')->default(0);
        $t->bigInteger('u')->default(0); $t->bigInteger('d')->default(0); $t->integer('balance')->default(0);
        $t->integer('discount')->default(0); $t->boolean('banned')->default(false); $t->integer('reset_count')->default(0);
        $t->integer('speed_limit')->nullable(); $t->integer('device_limit')->nullable(); $t->integer('next_reset_at')->nullable();
        $t->integer('last_reset_at')->nullable(); $t->integer('custom_pending_order_id')->nullable();
        $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
    });
    $schema->create('v2_plan', function (Blueprint $t): void {
        $t->increments('id'); $t->string('name'); $t->string('plan_type')->default('standard'); $t->integer('group_id')->nullable();
        $t->integer('transfer_enable')->default(100); $t->integer('speed_limit')->nullable(); $t->integer('device_limit')->nullable();
        $t->boolean('show')->default(true); $t->boolean('sell')->default(true); $t->boolean('renew')->default(true);
        $t->text('prices')->nullable(); $t->integer('sort')->default(0); $t->integer('reset_traffic_method')->nullable();
        $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
    });
    $schema->create('v2_order', function (Blueprint $t): void {
        $t->increments('id'); $t->integer('user_id'); $t->integer('plan_id')->nullable(); $t->integer('payment_id')->nullable();
        $t->string('period'); $t->string('trade_no')->unique(); $t->integer('total_amount')->default(0); $t->integer('balance_amount')->default(0);
        $t->integer('refund_amount')->default(0); $t->integer('surplus_amount')->default(0); $t->integer('discount_amount')->default(0);
        $t->integer('commission_balance')->default(0);
        $t->integer('status')->default(0); $t->integer('type')->default(1); $t->boolean('no_proration')->default(false);
        $t->text('surplus_order_ids')->nullable(); $t->integer('paid_at')->nullable(); $t->string('callback_no')->nullable();
        $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
    });
    $schema->create('v2_gift_card_template', function (Blueprint $t): void {
        $t->increments('id'); $t->string('name'); $t->text('description')->nullable(); $t->integer('type'); $t->boolean('status');
        $t->text('conditions')->nullable(); $t->text('rewards'); $t->text('limits')->nullable(); $t->text('special_config')->nullable();
        $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
    });
    $schema->create('v2_gift_card_code', function (Blueprint $t): void {
        $t->increments('id'); $t->integer('template_id'); $t->string('code')->unique(); $t->integer('status')->default(0);
        $t->integer('user_id')->nullable(); $t->integer('used_at')->nullable(); $t->integer('expires_at')->nullable();
        $t->text('actual_rewards')->nullable(); $t->integer('usage_count')->default(0); $t->integer('max_usage')->default(1);
        $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
    });
    $schema->create('v2_gift_card_usage', function (Blueprint $t): void {
        $t->increments('id'); $t->integer('code_id'); $t->integer('template_id'); $t->integer('user_id'); $t->integer('invite_user_id')->nullable();
        $t->text('rewards_given'); $t->text('invite_rewards')->nullable(); $t->integer('user_level_at_use')->nullable();
        $t->integer('plan_id_at_use')->nullable(); $t->decimal('multiplier_applied', 8, 2)->default(1); $t->string('user_agent')->nullable();
        $t->text('notes')->nullable(); $t->integer('created_at')->nullable();
    });
};

$writeResult = static function (array $result): never {
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    exit(0);
};

if (($argv[1] ?? '') === 'worker') {
    $capsule = $boot();
    $operation = $argv[2] ?? '';
    try {
        if ($operation === 'cancel') {
            HookManager::register('order.cancel.before', static function () use ($argv): void {
                if ((int) ($argv[3] ?? 0) > 0) usleep((int) $argv[3]);
            });
            $service = new OrderService(Order::findOrFail((int) ($argv[4] ?? 0)));
            $writeResult(['ok' => true, 'applied' => $service->cancel()]);
        }
        if ($operation === 'gift') {
            $service = new GiftCardService((string) ($argv[3] ?? ''));
            $service->setUser(User::findOrFail((int) ($argv[4] ?? 0)))->redeem();
            $writeResult(['ok' => true]);
        }
        if ($operation === 'paid') {
            $path = (string) ($argv[5] ?? '');
            HookManager::register('payment.notify.success', static function () use ($path): void {
                file_put_contents($path, "1\n", FILE_APPEND | LOCK_EX);
            });
            $controller = new PaymentController();
            $method = new ReflectionMethod($controller, 'handle');
            $result = $method->invoke($controller, (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''));
            $writeResult(['ok' => (bool) $result]);
        }
        if ($operation === 'mail') {
            $cachePath = (string) ($argv[3] ?? '');
            Cache::swap(new Repository(new FileStore(new Filesystem(), $cachePath)));
            $service = new \App\Services\Auth\MailLinkService();
            $writeResult(['user_id' => $service->handleTokenLogin((string) ($argv[4] ?? ''))]);
        }
        if ($operation === 'recharge-create') {
            $order = OrderService::createRechargeFromRequest(User::findOrFail((int) $argv[3]), (int) $argv[4]);
            $writeResult(['ok' => true, 'id' => $order->id]);
        }
        if ($operation === 'recharge-open') {
            (new OrderService(Order::findOrFail((int) $argv[3])))->open();
            $writeResult(['ok' => true]);
        }
    } catch (Throwable $error) {
        $writeResult(['ok' => false, 'error' => $error->getMessage()]);
    }
    exit(1);
}

$capsule = $boot();
$schema($capsule);
User::create(['id' => 1, 'email' => 'one@test', 'uuid' => 'u1', 'token' => 't1', 'invite_user_id' => 2, 'balance' => 0]);
User::create(['id' => 2, 'email' => 'two@test', 'uuid' => 'u2', 'token' => 't2', 'invite_user_id' => 1, 'balance' => 0]);
User::create(['id' => 3, 'email' => 'three@test', 'uuid' => 'u3', 'token' => 't3', 'balance' => 0]);
Plan::create(['id' => 1, 'name' => 'standard', 'group_id' => 1, 'transfer_enable' => 100, 'prices' => ['monthly' => 1]]);
Order::create(['id' => 1, 'user_id' => 1, 'period' => 'monthly', 'trade_no' => 'cancel-race', 'total_amount' => 1000, 'balance_amount' => 100, 'status' => Order::STATUS_PENDING]);
Order::create(['id' => 2, 'user_id' => 3, 'plan_id' => 1, 'period' => 'monthly', 'trade_no' => 'paid-race', 'total_amount' => 1000, 'status' => Order::STATUS_PENDING, 'type' => Order::TYPE_NEW_PURCHASE]);
Order::create(['id' => 3, 'user_id' => 3, 'plan_id' => 1, 'period' => 'monthly', 'trade_no' => 'cancel-paid-race', 'total_amount' => 1000, 'status' => Order::STATUS_PENDING, 'type' => Order::TYPE_NEW_PURCHASE]);
GiftCardTemplate::create(['id' => 1, 'name' => 'general', 'type' => GiftCardTemplate::TYPE_GENERAL, 'status' => 1, 'rewards' => ['balance' => 100, 'invite_reward_rate' => 1]]);
foreach (['SAME', 'ONE', 'TWO', 'MUTUAL_ONE', 'MUTUAL_TWO'] as $code) {
    GiftCardCode::create(['template_id' => 1, 'code' => $code, 'status' => GiftCardCode::STATUS_UNUSED, 'usage_count' => 0, 'max_usage' => 1]);
}

$start = static function (array $arguments, array $environment): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('worker start failed');
    return ['process' => $process, 'pipes' => $pipes];
};
$collect = static function (array $worker): array {
    $pipes = $worker['pipes'];
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($worker['process']);
    $result = json_decode(trim($stdout), true);
    if (!is_array($result)) throw new RuntimeException($stderr ?: 'worker output invalid');
    $result['exit'] = $exit;
    return $result;
};
$environment = array_merge($_ENV, ['SECURITY_P0_DB_DSN' => $dsn, 'SECURITY_P0_DB_USER' => $dbUser, 'SECURITY_P0_DB_PASSWORD' => $dbPassword]);
$mailToken = 'mail-concurrency-token';
$mailCachePath = sys_get_temp_dir() . '/security-p0-mail-' . bin2hex(random_bytes(8));
mkdir($mailCachePath, 0700, true);
$mailCache = new Repository(new FileStore(new Filesystem(), $mailCachePath));
$mailCacheKey = \App\Utils\CacheKey::get('TEMP_TOKEN', $mailToken);
$mailCache->put($mailCacheKey, \App\Services\Auth\MailLinkService::loginGrant(User::find(1)), 300);
$mailGuard = $mailCache->lock($mailCacheKey . ':consume', 5);
$mailGuard->get();
$mailWorkerOne = $start(['worker', 'mail', $mailCachePath, $mailToken], $environment);
$mailWorkerTwo = $start(['worker', 'mail', $mailCachePath, $mailToken], $environment);
usleep(100000);
$mailGuard->release();
$mailOne = $collect($mailWorkerOne);
$mailTwo = $collect($mailWorkerTwo);
(new Filesystem())->deleteDirectory($mailCachePath);
if (count(array_filter([$mailOne, $mailTwo], static fn(array $r): bool => ($r['user_id'] ?? null) === 1)) !== 1
    || count(array_filter([$mailOne, $mailTwo], static fn(array $r): bool => ($r['user_id'] ?? null) === null)) !== 1) {
    throw new RuntimeException('mail token concurrent consumption mismatch');
}
$cancelWorkerOne = $start(['worker', 'cancel', '250000', '1'], $environment);
usleep(50000);
$cancelWorkerTwo = $start(['worker', 'cancel', '0', '1'], $environment);
$cancelOne = $collect($cancelWorkerOne);
$cancelTwo = $collect($cancelWorkerTwo);
$cancelResults = [$cancelOne, $cancelTwo];
if (count(array_filter($cancelResults, static fn(array $r): bool => ($r['applied'] ?? false))) !== 1) throw new RuntimeException('cancel race winner mismatch');
if (Order::find(1)->status !== Order::STATUS_CANCELLED || User::find(1)->balance !== 100) throw new RuntimeException('cancel race state mismatch');

$sameWorkerOne = $start(['worker', 'gift', 'SAME', '1'], $environment);
$sameWorkerTwo = $start(['worker', 'gift', 'SAME', '1'], $environment);
$sameOne = $collect($sameWorkerOne);
$sameTwo = $collect($sameWorkerTwo);
if (count(array_filter([$sameOne, $sameTwo], static fn(array $r): bool => ($r['ok'] ?? false))) !== 1) throw new RuntimeException('same-code redemption winner mismatch');
if (GiftCardCode::where('code', 'SAME')->value('usage_count') !== 1 || User::find(1)->balance !== 200) throw new RuntimeException('same-code redemption state mismatch');

$oneWorker = $start(['worker', 'gift', 'ONE', '1'], $environment);
$twoWorker = $start(['worker', 'gift', 'TWO', '1'], $environment);
$one = $collect($oneWorker);
$two = $collect($twoWorker);
if (!($one['ok'] ?? false) || !($two['ok'] ?? false) || User::find(1)->balance !== 400) throw new RuntimeException('same-user different-code balance lost');

$mutualWorkerOne = $start(['worker', 'gift', 'MUTUAL_ONE', '1'], $environment);
$mutualWorkerTwo = $start(['worker', 'gift', 'MUTUAL_TWO', '2'], $environment);
$mutualOne = $collect($mutualWorkerOne);
$mutualTwo = $collect($mutualWorkerTwo);
if (!($mutualOne['ok'] ?? false) || !($mutualTwo['ok'] ?? false)) throw new RuntimeException('mutual inviter redemption failed');

$hookPath = tempnam(sys_get_temp_dir(), 'security-p0-paid-');
$paidWorkerOne = $start(['worker', 'paid', 'paid-race', 'callback-one', $hookPath], $environment);
$paidWorkerTwo = $start(['worker', 'paid', 'paid-race', 'callback-two', $hookPath], $environment);
$paidOne = $collect($paidWorkerOne);
$paidTwo = $collect($paidWorkerTwo);
$hookCount = is_string($hookPath) ? count(file($hookPath, FILE_IGNORE_NEW_LINES)) : 0;
unlink($hookPath);
if (!($paidOne['ok'] ?? false) || !($paidTwo['ok'] ?? false) || $hookCount !== 1) throw new RuntimeException('paid winner hook mismatch');

$crossHookPath = tempnam(sys_get_temp_dir(), 'security-p0-cross-');
$crossPaidWorker = $start(['worker', 'paid', 'cancel-paid-race', 'callback-cross', $crossHookPath], $environment);
usleep(50000);
$crossCancelWorker = $start(['worker', 'cancel', '0', '3'], $environment);
$crossPaid = $collect($crossPaidWorker);
$crossCancel = $collect($crossCancelWorker);
$crossHookCount = is_string($crossHookPath) ? count(file($crossHookPath, FILE_IGNORE_NEW_LINES)) : 0;
unlink($crossHookPath);
$crossStatus = (int) Order::find(3)->status;
if (!($crossPaid['ok'] ?? false) || !($crossCancel['ok'] ?? false) || !in_array($crossStatus, [Order::STATUS_CANCELLED, Order::STATUS_COMPLETED], true)) {
    throw new RuntimeException('cancel-paid race failed');
}
if (($crossStatus === Order::STATUS_COMPLETED && $crossHookCount !== 1) || ($crossStatus === Order::STATUS_CANCELLED && $crossHookCount !== 0)) {
    throw new RuntimeException('cancel-paid winner hook mismatch');
}

// Real InnoDB contention: duplicate recharge creation, callbacks and completion jobs.
$rechargeUser = User::create(['email' => 'wallet@test', 'balance' => 190]);
$createOne = $start(['worker', 'recharge-create', (string) $rechargeUser->id, '10000'], $environment);
$createTwo = $start(['worker', 'recharge-create', (string) $rechargeUser->id, '20000'], $environment);
$created = [$collect($createOne), $collect($createTwo)];
if (count(array_filter($created, fn ($r) => $r['ok'] ?? false)) !== 1
    || Order::where('user_id', $rechargeUser->id)->count() !== 1) throw new RuntimeException('concurrent recharge create guard failed');
$recharge = Order::where('user_id', $rechargeUser->id)->firstOrFail();
$walletHook = tempnam(sys_get_temp_dir(), 'wallet-paid-');
$callbackOne = $start(['worker', 'paid', $recharge->trade_no, 'wallet-one', $walletHook], $environment);
$callbackTwo = $start(['worker', 'paid', $recharge->trade_no, 'wallet-two', $walletHook], $environment);
$callbacks = [$collect($callbackOne), $collect($callbackTwo)];
$walletHooks = count(file($walletHook, FILE_IGNORE_NEW_LINES)); unlink($walletHook);
if (count(array_filter($callbacks, fn ($r) => $r['ok'] ?? false)) !== 2 || $walletHooks !== 1
    || (int) $rechargeUser->fresh()->balance !== 190 + (int) $recharge->total_amount
    || (int) $recharge->fresh()->status !== Order::STATUS_COMPLETED) throw new RuntimeException('concurrent recharge callbacks double credited');
$jobUser = User::create(['email' => 'wallet-jobs@test', 'balance' => 0]);
$jobOrder = OrderService::createRechargeFromRequest($jobUser, 50000);
$jobOrder->status = Order::STATUS_PROCESSING; $jobOrder->save();
$jobOne = $start(['worker', 'recharge-open', (string) $jobOrder->id], $environment);
$jobTwo = $start(['worker', 'recharge-open', (string) $jobOrder->id], $environment);
$jobs = [$collect($jobOne), $collect($jobTwo)];
if (count(array_filter($jobs, fn ($r) => $r['ok'] ?? false)) !== 2 || (int) $jobUser->fresh()->balance !== 50000
    || (int) $jobOrder->fresh()->status !== Order::STATUS_COMPLETED) throw new RuntimeException('concurrent recharge jobs double credited');
echo "wallet recharge real-service MariaDB concurrency checks passed\n";
echo "security-p0 real-service MariaDB concurrency checks passed\n";
