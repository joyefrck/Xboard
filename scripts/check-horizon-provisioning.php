<?php
declare(strict_types=1);

// Offline release gate: no Laravel bootstrap, .env, Redis, jobs or API calls.
require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\ProvisioningPlan;

$options = getopt('', ['environment:', 'horizon-config:']);
$root = dirname(__DIR__);
$horizon = array_merge(
    require $root . '/vendor/laravel/horizon/config/horizon.php',
    require ($options['horizon-config'] ?? $root . '/config/horizon.php'),
);
$environment = $options['environment'] ?? $horizon['env'] ?? (getenv('APP_ENV') ?: 'local');
$queue = require $root . '/config/queue.php';
$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Repository(['horizon' => $horizon, 'queue' => $queue]));
$app->instance('events', new Dispatcher($app));
$commands = new class implements HorizonCommandQueue {
    public array $options = [];
    public function push($name, $command, array $options = []) { $this->options[] = $options; }
    public function pending($name) { return []; }
    public function flush($name) {}
};
$app->instance(HorizonCommandQueue::class, $commands);

// Exercise Horizon's real first-match selection, rather than inspecting text.
ProvisioningPlan::get('release-check')->deploy($environment);
$provisioned = [];
$invalidLeases = [];
foreach ($commands->options as $worker) {
    $connection = $worker['connection'];
    $connectionConfig = $queue['connections'][$connection] ?? [];
    foreach (explode(',', $worker['queue']) as $name) {
        $provisioned[] = $connection . ':' . trim($name);
    }
    if (($connectionConfig['driver'] ?? null) !== 'redis'
        || (int) $worker['timeout'] >= (int) ($connectionConfig['retry_after'] ?? 0)) {
        $invalidLeases[] = $worker['name'];
    }
}
$required = [
    'redis:default', 'redis:traffic_fetch', 'redis:stat', 'redis:online_sync',
    'redis:order_handle', 'redis:send_telegram', 'redis:send_ticket_telegram',
    'redis:send_email', 'redis:send_email_mass', 'telegram_group:telegram_group',
    'node_automation:node_automation',
];
$missing = array_values(array_diff($required, $provisioned));
$ok = $missing === [] && $invalidLeases === [];
echo json_encode([
    'ok' => $ok, 'environment' => $environment,
    'provisioned_queues' => $provisioned, 'missing_queues' => $missing,
    'invalid_worker_leases' => $invalidLeases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($ok ? 0 : 1);
