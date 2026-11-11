<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\PasswordSecurityService;
use App\Services\Auth\SessionSecurityService;
use App\Services\AuthService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Laravel\Sanctum\PersonalAccessToken;

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlite');
$db->getDatabaseManager()->setDefaultConnection('sqlite');
$db->setAsGlobal();
$db->bootEloquent();
$app = $db->getContainer();
$config = new Repository($app['config']->toArray());
$config->set('database.default', 'sqlite');
$config->set('app.key', 'fixture-only-security-key');
$app->instance('config', $config);
$app->instance('app', $app);
$app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
$app->instance('db', $db->getDatabaseManager());
$app->instance('cache', new Illuminate\Cache\CacheManager($app));
$config->set('cache.default', 'array');
$config->set('cache.stores.array', ['driver' => 'array']);
$app->instance('log', new Psr\Log\NullLogger());
$app->instance(App\Support\Setting::class, new class extends App\Support\Setting {
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $default; }
});
Container::setInstance($app);
Facade::setFacadeApplication($app);
$schema = $db->schema();
$app->instance('db.schema', $schema);
$schema->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->string('email'); $t->string('password');
    $t->string('password_algo')->nullable(); $t->string('password_salt')->nullable();
    $t->string('token'); $t->boolean('banned')->default(false); $t->boolean('is_admin')->default(false);
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('personal_access_tokens', function (Blueprint $t) {
    $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
    $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
    $t->timestamp('expires_at')->nullable(); $t->timestamps();
});
$migration = require dirname(__DIR__) . '/database/migrations/2026_11_11_000001_add_account_session_security.php';
$migration->up();
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function rejected(callable $run, int $code, string $message): void {
    try { $run(); throw new RuntimeException('Expected rejection: ' . $message); }
    catch (ApiException $e) {
        check($e->getCode() === $code, $message . ': ' . $e->getCode());
        check(!preg_match('/\bIP\b|可信|30\s*天|\d+\s*分钟|\d+\s*次/iu', $e->getMessage()), 'public rejection does not disclose security policy');
    }
}
function fixtureUser(int $id): User {
    return User::forceCreate(['id' => $id, 'email' => "fixture{$id}@example.test",
        'password' => password_hash('fixture-old-password', PASSWORD_DEFAULT), 'token' => 'fixture-subscription']);
}
function fixtureRequest(User $user, string $ip = '203.0.113.10', string $ua = 'Mozilla/5.0 (Macintosh) Chrome/131.0'): Request {
    global $app;
    $token = $user->createToken('fixture-device', ['*'], now()->addYear())->accessToken;
    $user->withAccessToken($token);
    $request = Request::create('/api/v1/user/changePassword', 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua]);
    $request->setUserResolver(fn () => $user);
    $app->instance('request', $request);
    return $request;
}

$one = fixtureUser(10); $two = fixtureUser(20);
$request = fixtureRequest($one);
$other = fixtureRequest($two);
$sessions = new SessionSecurityService();
$sent = [];
$security = new PasswordSecurityService(function (array $mail) use (&$sent) { $sent[] = $mail; });

check(SessionSecurityService::canonicalIp('::ffff:203.0.113.10') === '203.0.113.10', 'mapped IPv4 normalized');
check(SessionSecurityService::canonicalIp('2001:0db8::1') === '2001:db8::1', 'IPv6 normalized');
check(SessionSecurityService::canonicalIp('forged') === null, 'invalid IP rejected');
$app->instance('request', $request);
$auth = new AuthService(User::find(10));
$data = $auth->generateAuthData();
$issued = PersonalAccessToken::findToken(str_replace('Bearer ', '', $data['auth_data']));
check($issued->login_ip === '203.0.113.10', 'issued session records server IP');
check($issued->client_label === 'Chrome' && $issued->client_os === 'macOS', 'browser identified');
$expired = $one->createToken('expired', ['*'], now()->subSecond())->accessToken;
$list = $sessions->sessions($one, $request);
check(count($list) === 2, 'expired sessions filtered');
check(count(array_filter($list, fn ($s) => $s['is_current'])) === 1, 'current session marked');
check(!str_contains(json_encode($list), $issued->token), 'token digest never returned');
check(!array_key_exists('name', $list[0]) && !array_key_exists('abilities', $list[0]), 'allowlist response');
check($list[1]['client_label'] === '历史会话／设备信息未知', 'legacy session is honestly labelled');
rejected(fn () => $sessions->revoke($one, (string) $two->currentAccessToken()->id), 404, 'cannot revoke another user');
check($two->tokens()->count() === 1, 'other user preserved');
$sessions->revoke($one, (string) $expired->id);
check(!$one->tokens()->whereKey($expired->id)->exists(), 'own single session revoked');

rejected(fn () => $security->change($one, $request, 'wrong', 'fixture-new-password'), 400, 'wrong old password denied');
rejected(fn () => $security->change($one, $request, 'fixture-old-password', 'fixture-new-password'), 428, 'first change needs mailbox verification');
check($db->table('v2_user_trusted_ips')->count() === 0, 'password login did not trust IP');
$challenge = $security->sendCode($one, $request, 'fixture-old-password');
check(count($sent) === 1 && $sent[0]['email'] === $one->email, 'code sent only to authenticated mailbox');
check(!isset($challenge['code']) && $challenge['cooldown_seconds'] === 60, 'response never returns code');
check(!array_key_exists('expires_in', $challenge), 'verification response omits internal validity policy');
$code = (string) $sent[0]['template_value']['code'];
check(preg_match('/^\d{6}$/D', $code) === 1, 'six digit random code');
rejected(fn () => $security->sendCode($one, $request, 'fixture-old-password'), 429, 'send cooldown enforced');
for ($i = 0; $i < 5; $i++) {
    rejected(fn () => $security->change($one, $request, 'fixture-old-password', 'fixture-new-password', '000000'), 400, 'wrong code denied');
}
rejected(fn () => $security->change($one, $request, 'fixture-old-password', 'fixture-new-password', $code), 429, 'five failures block correct code too');
Carbon::setTestNow(now()->addSeconds(61));
$security->sendCode($one, $request, 'fixture-old-password');
$code2 = (string) end($sent)['template_value']['code'];
rejected(fn () => $security->change($one, $request, 'fixture-old-password', 'fixture-new-password', $code), 400, 'resend invalidates old code');
$changedIp = clone $request; $changedIp->server->set('REMOTE_ADDR', '203.0.113.11');
rejected(fn () => $security->change($one, $changedIp, 'fixture-old-password', 'fixture-new-password', $code2), 400, 'code bound to IP');
$changedSession = fixtureRequest(User::find(10));
rejected(fn () => $security->change($changedSession->user(), $changedSession, 'fixture-old-password', 'fixture-new-password', $code2), 400, 'code bound to session');
$staleLogin = User::find(10);
$security->change($one, $request, 'fixture-old-password', 'fixture-new-password', $code2);
check(password_verify('fixture-new-password', User::find(10)->password), 'verified change applied');
check($one->tokens()->count() === 0, 'password change revokes every old session');
check($two->tokens()->count() === 1, 'password change does not revoke other account');
check($db->table('v2_user_trusted_ips')->count() === 1, 'only verified IP became trusted');
rejected(fn () => (new AuthService($staleLogin))->generateAuthData(), 403, 'stale password login cannot mint token after reset');
rejected(fn () => $security->change($one, $request, 'fixture-new-password', 'fixture-next-password', $code2), 403, 'revoked session cannot replay challenge');
$fresh = fixtureRequest(User::find(10));
$security->change($fresh->user(), $fresh, 'fixture-new-password', 'fixture-next-password');
check(password_verify('fixture-next-password', User::find(10)->password), 'verified IP can change without another code');
$fresh = fixtureRequest(User::find(10));
Carbon::setTestNow(now()->addDays(31));
rejected(fn () => $security->change($fresh->user(), $fresh, 'fixture-next-password', 'fixture-expired-password'), 428, 'trust expires after thirty days');
Carbon::setTestNow();

$fresh->user()->email = 'changed@example.test'; $fresh->user()->save();
rejected(fn () => $security->change($fresh->user(), $fresh, 'fixture-next-password', 'fixture-email-password'), 428, 'email changes invalidate IP trust');
$thirty = fixtureUser(30); $req30 = fixtureRequest($thirty);
$security->sendCode($thirty, $req30, 'fixture-old-password');
$code30 = (string) end($sent)['template_value']['code'];
Carbon::setTestNow(now()->addSeconds(301));
rejected(fn () => $security->change($thirty, $req30, 'fixture-old-password', 'fixture-new-password', $code30), 400, 'expired code rejected');
Carbon::setTestNow();
rejected(fn () => $security->change($two, $other, 'fixture-old-password', 'fixture-new-password', $code30), 400, 'cross-account code rejected');
check($thirty->tokens()->count() === 1, 'failed verification preserves session');

$mail = new App\Services\Auth\MailLinkService();
$quickUser = User::find(30)->withAccessToken($thirty->currentAccessToken());
$grant = App\Services\Auth\MailLinkService::loginGrant($quickUser, $thirty->currentAccessToken()->id);
Illuminate\Support\Facades\Cache::put(App\Utils\CacheKey::get('TEMP_TOKEN', 'fixture-quick'), $grant, 60);
$consumed = $mail->consumeTokenLogin('fixture-quick');
check($consumed?->id === 30 && $mail->consumeTokenLogin('fixture-quick') === null, 'quick login consumed once');
$sessions->revoke($thirty);
rejected(fn () => (new AuthService($consumed))->generateAuthData(), 403, 'quick login cannot mint token after parent session revoked');
Illuminate\Support\Facades\Cache::put(App\Utils\CacheKey::get('TEMP_TOKEN', 'fixture-quick-revoked'), $grant, 60);
check($mail->consumeTokenLogin('fixture-quick-revoked') === null, 'revoked parent invalidates outstanding quick login');
Illuminate\Support\Facades\Cache::put(App\Utils\CacheKey::get('TEMP_TOKEN', 'fixture-legacy'), 30, 60);
check($mail->consumeTokenLogin('fixture-legacy') === null, 'legacy unbound quick login rejected');

$recovery = new App\Services\Auth\LoginService();
$before = User::find(20)->password;
check($recovery->resetPassword($two->email, '', 'fixture-recovered-password')[0] === false, 'missing cached code cannot reset password');
check(User::find(20)->password === $before, 'invalid recovery preserves password');
Illuminate\Support\Facades\Cache::put(App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', $two->email), '654321', 300);
$stale = User::find(20);
$emailGrant = App\Services\Auth\MailLinkService::loginGrant($stale);
Illuminate\Support\Facades\Cache::put(App\Utils\CacheKey::get('TEMP_TOKEN', 'fixture-before-recovery'), $emailGrant, 300);
check($recovery->resetPassword($two->email, '654321', 'fixture-recovered-password') === [true, true], 'mailbox recovery succeeds');
check($two->tokens()->count() === 0, 'recovery revokes all sessions');
check($mail->consumeTokenLogin('fixture-before-recovery') === null, 'password recovery invalidates outstanding mail login');
rejected(fn () => (new AuthService($stale))->generateAuthData(), 403, 'recovery prevents stale login token creation');
check($recovery->resetPassword($two->email, '654321', 'fixture-replay-password')[0] === false, 'recovery code consumed once');

$adminTarget = User::find(30);
$adminTarget->createToken('before-admin-reset');
$db->table('v2_user_trusted_ips')->insert(['user_id' => 30, 'ip_hash' => str_repeat('c', 64), 'email_hash' => str_repeat('d', 64), 'expires_at' => now()->addDay()]);
$adminRequest = new class extends App\Http\Requests\Admin\UserUpdate {
    public function validated($key = null, $default = null) { return $this->only(['id', 'password']); }
};
$adminRequest->initialize([], ['id' => 30, 'password' => 'fixture-admin-password']); $adminRequest->setMethod('POST');
$adminRequest->setUserResolver(fn () => $two);
$factory = Mockery::mock(Illuminate\Contracts\Routing\ResponseFactory::class);
$factory->shouldReceive('json')->andReturnUsing(fn ($data, $status) => new Illuminate\Http\JsonResponse($data, $status));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, $factory);
$response = (new App\Http\Controllers\V2\Admin\UserController())->update($adminRequest);
check($response->getStatusCode() === 200 && password_verify('fixture-admin-password', User::find(30)->password), 'real admin update changes password');
check($adminTarget->tokens()->count() === 0 && !$db->table('v2_user_trusted_ips')->where('user_id', 30)->exists(), 'admin reset revokes sessions and trust');
$adminTarget->createToken('before-cli-reset');
$command = new class extends App\Console\Commands\ResetPassword {
    public array $values = [];
    public function argument($key = null) { return $this->values[$key] ?? null; }
    public function info($string, $verbosity = null) {}
};
$command->values = ['email' => $adminTarget->email, 'password' => 'fixture-cli-password'];
$command->handle();
check(password_verify('fixture-cli-password', User::find(30)->password) && $adminTarget->tokens()->count() === 0, 'CLI reset revokes old sessions');

$forty = fixtureUser(40); $req40 = fixtureRequest($forty);
$req40->headers->set('X-Forwarded-For', '198.51.100.99');
check(SessionSecurityService::metadata($req40)['login_ip'] === '203.0.113.10', 'untrusted forwarded IP cannot establish trust');
$sessions->observe($forty, $req40);
$observed = $forty->tokens()->first();
check($observed->login_ip === null && $observed->client_label === null && $observed->last_ip === '203.0.113.10', 'observing legacy session never invents historical login metadata');
for ($i = 0; $i < 5; $i++) rejected(fn () => $security->sendCode($forty, $req40, 'wrong'), 400, 'old password guessing denied');
rejected(fn () => $security->sendCode($forty, $req40, 'fixture-old-password'), 429, 'old password guessing limited per account');
$fifty = fixtureUser(50); $req50 = fixtureRequest($fifty, '203.0.113.50');
for ($i = 0; $i < 5; $i++) {
    $security->sendCode($fifty, $req50, 'fixture-old-password');
    Carbon::setTestNow(now()->addSeconds(61));
}
rejected(fn () => $security->sendCode($fifty, $req50, 'fixture-old-password'), 429, 'hourly account send quota enforced');
Carbon::setTestNow();
$expiredBearer = $fifty->createToken('expired-bearer', ['*'], now()->subMinute());
check(AuthService::findUserByBearerToken($expiredBearer->plainTextToken) === null, 'expired bearer cannot request quick login');

$schema->create('v2_log', function (Blueprint $t) {
    $t->increments('id');
    foreach (['title', 'level', 'host', 'uri', 'method', 'ip', 'data', 'context'] as $name) $t->text($name)->nullable();
    $t->integer('created_at'); $t->integer('updated_at');
});
$logRequest = Request::create('/api/v1/user/changePassword?verify=fixture-verify-secret', 'POST', [
    'old_password' => 'fixture-old-secret', 'new_password' => 'fixture-new-secret', 'email_code' => '345678',
    'nested' => ['auth_data' => 'fixture-auth-secret'], 'user_id' => 30,
]);
$app->instance('request', $logRequest);
$handler = new App\Logging\MysqlLoggerHandler();
$handler->handle(new Monolog\LogRecord(new DateTimeImmutable(), 'mysql', Monolog\Level::Info,
    'failed SQL fixture-new-secret', ['exception' => new RuntimeException('fixture-old-secret'),
        'request' => '{"token":"fixture-context-secret"}', 'operation' => 'password_change']));
$logged = json_encode($db->table('v2_log')->first());
foreach (['fixture-old-secret', 'fixture-new-secret', '345678', 'fixture-auth-secret', 'fixture-verify-secret', 'fixture-context-secret'] as $secret) {
    check(!str_contains($logged, $secret), 'log does not expose fixture secret');
}
check(str_contains($logged, 'password_change') && str_contains($logged, 'user_id'), 'redacted audit retains useful event fields');
check(!str_contains(App\Logging\SensitiveData::uri('/s/' . str_repeat('a', 32)), str_repeat('a', 32)), 'subscription path secret redacted');
check(App\Logging\SensitiveData::redact(['mailers' => ['smtp' => ['password' => 'fixture-smtp-secret']]])['mailers']['smtp']['password'] === '[REDACTED]', 'mail configuration credentials redacted recursively');

$migration->down();
check(!$schema->hasTable('v2_user_trusted_ips') && !$schema->hasColumn('personal_access_tokens', 'login_ip'), 'migration rolls back cleanly');

echo "Account session security: {$checks} checks passed\n";
Mockery::close();
