<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\PasswordSecurityService;
use App\Services\Auth\SessionSecurityService;
use App\Services\AuthService;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$port = getenv('ACCOUNT_SECURITY_TEST_PORT');
$password = getenv('ACCOUNT_SECURITY_TEST_PASSWORD') ?: '';
if (!$port || !ctype_digit($port)) throw new RuntimeException('ACCOUNT_SECURITY_TEST_PORT is required; localhost disposable account_security database only');
$folder = $argv[2] ?? sys_get_temp_dir() . '/xboard-account-security-' . bin2hex(random_bytes(8));
if (!str_starts_with($folder, sys_get_temp_dir() . '/xboard-account-security-')) throw new RuntimeException('Unsafe test folder');
if (!is_dir($folder)) mkdir($folder, 0700, true);
$db = new Capsule();
$db->addConnection(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $port, 'database' => 'account_security',
    'username' => 'root', 'password' => $password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
$db->setAsGlobal(); $db->bootEloquent();
$app = $db->getContainer();
$config = new Repository($app['config']->toArray()); $config->set('app.key', 'fixture-only-concurrency-key');
$app->instance('config', $config); $app->instance('app', $app);
$app->instance('db', $db->getDatabaseManager()); $app->instance('db.schema', $db->schema());
$app->instance('log', new Psr\Log\NullLogger());
$app->instance(App\Support\Setting::class, new class extends App\Support\Setting {
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $default; }
});
Container::setInstance($app); Facade::setFacadeApplication($app);
Cache::swap(new CacheRepository(new FileStore(new Filesystem(), $folder . '/cache')));
function req(User $user, int $tokenId): Request {
    global $app;
    $user->withAccessToken($user->tokens()->findOrFail($tokenId));
    $request = Request::create('/fixture', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
    $request->setUserResolver(fn () => $user); $app->instance('request', $request);
    return $request;
}
function waitFile(string $path): void {
    $until = microtime(true) + 8;
    while (!is_file($path)) { if (microtime(true) > $until) throw new RuntimeException('Worker barrier timed out'); usleep(10000); }
}

if (($argv[1] ?? '') === 'worker') {
    try {
        $op = $argv[3]; $name = $argv[4]; $tokenId = (int) $argv[5];
        $user = User::findOrFail(1);
        $request = req($user, $tokenId);
        if ($op === 'mint-stale') $user->withAccessToken(null);
        file_put_contents($folder . '/' . $name . '.ready', '1');
        waitFile($folder . '/' . $name . '.go');
        if (str_starts_with($op, 'mint')) (new AuthService($user))->generateAuthData();
        elseif ($op === 'change') (new PasswordSecurityService())->change($user, $request, 'fixture-old-password', 'fixture-new-password', $argv[6]);
        echo json_encode(['ok' => true]);
    } catch (ApiException $e) {
        echo json_encode(['ok' => false, 'code' => $e->getCode()]);
    } catch (Throwable $e) {
        echo json_encode(['error_type' => get_class($e), 'message' => $e->getMessage()]);
    }
    exit;
}

$checks = 0;
$check = function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message); $checks++;
};
$schema = $db->schema();
foreach (['v2_user_trusted_ips', 'personal_access_tokens', 'v2_user'] as $table) $schema->dropIfExists($table);
$schema->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->string('email'); $t->string('password'); $t->string('token');
    $t->string('password_algo')->nullable(); $t->string('password_salt')->nullable();
    $t->boolean('banned')->default(false); $t->boolean('is_admin')->default(false);
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('personal_access_tokens', function (Blueprint $t) {
    $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
    $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
});
$migration = require dirname(__DIR__) . '/database/migrations/2026_11_11_000001_add_account_session_security.php';
$migration->up();
$seed = function () use ($db): array {
    $db->table('personal_access_tokens')->delete(); $db->table('v2_user_trusted_ips')->delete(); $db->table('v2_user')->delete(); Cache::flush();
    $user = User::forceCreate(['id' => 1, 'email' => 'concurrency@example.test', 'password' => password_hash('fixture-old-password', PASSWORD_DEFAULT), 'token' => 'fixture-only']);
    $token = $user->createToken('fixture', ['*'], now()->addHour())->accessToken;
    return [$user, req($user, (int) $token->id), (int) $token->id];
};
$start = function (string $op, string $name, int $id, string $code = '') use ($folder): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $folder, $op, $name, (string) $id, $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes];
};
$finish = function (array $worker): array {
    $out = stream_get_contents($worker['pipes'][1]); $err = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]); fclose($worker['pipes'][2]); proc_close($worker['process']);
    $value = json_decode($out, true);
    if (!is_array($value) || isset($value['error_type'])) throw new RuntimeException('Worker failed: ' . ($err ?: $out));
    return $value;
};

try {
    [$user, $request, $id] = $seed();
    DB::beginTransaction(); User::whereKey(1)->lockForUpdate()->firstOrFail();
    $worker = $start('mint-stale', 'stale', $id); waitFile($folder . '/stale.ready');
    $locked = User::find(1); $locked->password = password_hash('fixture-new-password', PASSWORD_DEFAULT); $locked->saveOrFail(); $locked->tokens()->delete();
    file_put_contents($folder . '/stale.go', '1'); usleep(150000);
    $check(proc_get_status($worker['process'])['running'], 'token creation must wait for password row lock');
    DB::commit();
    $check($finish($worker) === ['ok' => false, 'code' => 403], 'stale login rejected after concurrent password commit');
    $check($user->tokens()->count() === 0, 'no old-password token survives');

    [$user, $request, $id] = $seed();
    DB::beginTransaction(); User::whereKey(1)->lockForUpdate()->firstOrFail();
    $worker = $start('mint-parent', 'parent', $id); waitFile($folder . '/parent.ready');
    (new SessionSecurityService())->revoke($user);
    file_put_contents($folder . '/parent.go', '1'); usleep(150000);
    $check(proc_get_status($worker['process'])['running'], 'quick login must wait for logout row lock');
    DB::commit();
    $check($finish($worker) === ['ok' => false, 'code' => 403], 'revoked parent cannot create replacement session');
    $check($user->tokens()->count() === 0, 'logout leaves no racing replacement session');

    [$user, $request, $id] = $seed();
    $code = null;
    $service = new PasswordSecurityService(function (array $mail) use (&$code) { $code = (string) $mail['template_value']['code']; });
    $service->sendCode($user, $request, 'fixture-old-password');
    $one = $start('change', 'one', $id, $code); $two = $start('change', 'two', $id, $code);
    waitFile($folder . '/one.ready'); waitFile($folder . '/two.ready');
    file_put_contents($folder . '/one.go', '1'); file_put_contents($folder . '/two.go', '1');
    $results = [$finish($one), $finish($two)];
    $check(count(array_filter($results, fn ($r) => $r['ok'])) === 1, 'one-time challenge permits exactly one concurrent change');
    $check(password_verify('fixture-new-password', User::find(1)->password), 'concurrent change committed valid password');
    $check($user->tokens()->count() === 0, 'concurrent change revokes original token');
    $check($db->table('v2_user_trusted_ips')->count() === 1, 'concurrent challenge creates one verified IP');
    $migration->down();
    $check(!$schema->hasTable('v2_user_trusted_ips'), 'MariaDB migration rollback succeeds');
    echo "Account session security MariaDB concurrency: {$checks} checks passed\n";
} finally {
    while (DB::transactionLevel()) DB::rollBack();
    (new Filesystem())->deleteDirectory($folder);
}
