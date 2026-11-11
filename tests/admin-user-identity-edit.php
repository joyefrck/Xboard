<?php

declare(strict_types=1);

// In-memory fixtures only; never bootstrap Laravel or read .env.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Illuminate\Config\Repository());
$app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
    public function __construct() {}
});
$db = new Capsule($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$validation = new Illuminate\Validation\Factory(
    new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'), $app
);
$validation->setPresenceVerifier(new Illuminate\Validation\DatabasePresenceVerifier($db->getDatabaseManager()));
$db->schema()->create('v2_user', function (Blueprint $table): void {
    $table->increments('id');
    $table->string('email');
    $table->string('password')->nullable();
    $table->string('password_algo')->nullable();
    $table->integer('invite_user_id')->nullable();
    $table->integer('balance')->default(0);
    $table->integer('created_at');
    $table->integer('updated_at');
});
$db->schema()->create('personal_access_tokens', function (Blueprint $table): void {
    $table->id(); $table->morphs('tokenable'); $table->string('name');
    $table->string('token', 64)->unique(); $table->text('abilities')->nullable();
    $table->timestamp('expires_at')->nullable(); $table->timestamps();
});
$app->instance('db.schema', $db->schema());
(require dirname(__DIR__) . '/database/migrations/2026_11_11_000001_add_account_session_security.php')->up();
$inviter = User::create(['email' => 'inviter@example.test']);
$replacement = User::create(['email' => 'replacement@example.test']);
$password = password_hash('Original-password', PASSWORD_DEFAULT);
$user = User::create(['email' => 'member@example.test', 'password' => $password, 'invite_user_id' => $inviter->id]);
$user->createToken('fixture-device');
$db->table('v2_user_trusted_ips')->insert(['user_id' => $user->id, 'ip_hash' => str_repeat('a', 64),
    'email_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay()]);
$controller = new UserController();
$checks = 0;
$expect = function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$update = function (array $changes) use ($controller, $user, $validation): void {
    $request = new UserUpdate();
    $request->replace(['id' => $user->id] + $changes);
    $request->setValidator($validation->make($request->all(), $request->rules(), $request->messages()));
    $request->setUserResolver(fn() => (object) ['id' => 99]);
    $response = $controller->update($request);
    if ($response->getStatusCode() !== 200) throw new RuntimeException('Update failed');
};
$update(['balance' => 25]);
$user->refresh();
$expect((int) $user->invite_user_id === $inviter->id, 'Ordinary save must preserve the existing inviter');
$expect($user->email === 'member@example.test', 'Ordinary save must preserve the email');
$expect($user->password === $password, 'Ordinary save must preserve the exact password hash');
$expect($user->tokens()->count() === 1 && $db->table('v2_user_trusted_ips')->count() === 1,
    'Ordinary save preserves existing sessions and mailbox trust');
$expect((int) $user->balance === 2500, 'Ordinary balance update still works');
$update(['invite_user_email' => $replacement->email]);
$expect((int) $user->refresh()->invite_user_id === $replacement->id, 'Explicit inviter change works');
$update(['balance' => 30]);
$expect((int) $user->refresh()->invite_user_id === $replacement->id, 'Later ordinary save preserves the new inviter');
$update(['invite_user_email' => null]);
$expect($user->refresh()->invite_user_id === null, 'Explicitly clearing the inviter removes the relationship');
$update(['password' => null]);
$expect($user->refresh()->password === $password, 'Empty password does not overwrite the original');
$update(['email' => 'changed@example.test', 'password' => 'New-password-41']);
$expect($user->refresh()->email === 'changed@example.test', 'Explicit email change works');
$expect(password_verify('New-password-41', $user->password), 'Explicit new password is hashed and verifiable');
$expect($user->tokens()->count() === 0 && $db->table('v2_user_trusted_ips')->count() === 0,
    'Explicit password reset revokes existing sessions and mailbox trust');
foreach (['not-an-email', 'unknown@example.test'] as $invalid) {
    try {
        $update(['invite_user_email' => $invalid]);
        throw new RuntimeException('Invalid inviter must be rejected');
    } catch (Illuminate\Validation\ValidationException) {
        $expect($user->refresh()->invite_user_id === null, 'Invalid inviter does not change the relationship');
    }
}
echo "admin user identity edit: {$checks} checks passed\n";
