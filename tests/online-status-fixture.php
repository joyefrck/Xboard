<?php
declare(strict_types=1);

// Standalone fixtures only: never boot Laravel or load .env.
require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;

$port = getenv('ONLINE_STATUS_TEST_PORT');
$db = new Capsule();
if ($port !== false && $port !== '') {
    if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
        throw new RuntimeException('A localhost disposable online_status_test database port is required');
    }
    $db->addConnection(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $port,
        'database' => 'online_status_test', 'username' => 'root', 'password' => getenv('ONLINE_STATUS_TEST_PASSWORD') ?: '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'], 'mysql');
} else {
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlite');
}
$db->getDatabaseManager()->setDefaultConnection($port ? 'mysql' : 'sqlite');
$db->setAsGlobal();
$db->bootEloquent();
$app = $db->getContainer();
$config = new Repository($app['config']->toArray());
$config->set('database.default', $port ? 'mysql' : 'sqlite');
$app->instance('config', $config);
$app->instance('db', $db->getDatabaseManager());
$app->instance('log', new Psr\Log\NullLogger());
$app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$app->instance(App\Support\Setting::class, new class extends App\Support\Setting {
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed { return $default; }
});
Container::setInstance($app);
Facade::setFacadeApplication($app);

function onlineFixtureSchema(): void
{
    global $db;
    foreach (['v2_stat_user', 'v2_user'] as $table) $db->schema()->dropIfExists($table);
    $db->schema()->create('v2_user', function (Blueprint $t) {
        $t->engine = 'InnoDB';
        $t->increments('id');
        $t->integer('online_count')->default(0);
        $t->dateTime('last_online_at')->nullable();
        $t->integer('created_at')->nullable();
        $t->integer('updated_at')->nullable();
    });
    $db->schema()->create('v2_stat_user', function (Blueprint $t) {
        $t->engine = 'InnoDB';
        $t->increments('id');
        $t->integer('user_id');
        $t->float('server_rate');
        $t->integer('record_at');
        $t->string('record_type');
        $t->bigInteger('u');
        $t->bigInteger('d');
        $t->integer('created_at');
        $t->integer('updated_at');
        $t->unique(['user_id', 'server_rate', 'record_at', 'record_type'], 'fixture_stat_unique');
    });
}

function onlineFixtureSeed(): void
{
    global $db;
    $db->table('v2_stat_user')->delete();
    $db->table('v2_user')->delete();
    foreach ([1, 2, 3] as $id) $db->table('v2_user')->insert([
        'id' => $id, 'online_count' => 7, 'last_online_at' => now()->subMinutes($id === 3 ? 1 : 10)->format('Y-m-d H:i:s'),
    ]);
}

class OnlineFixtureCleanup extends App\Console\Commands\CleanupExpiredOnlineStatus
{
    public array $messages = [];
    public function info($string, $verbosity = null) { $this->messages[] = $string; }
    public function error($string, $verbosity = null) { $this->messages[] = $string; }
}
