<?php

declare(strict_types=1);

// Isolated HTTP test for the AI assistant's node automation API: sqlite memory, fake DNS and probe, faked queue.
use App\Jobs\NodeAutomationJob;
use App\Models\{NodeAutomationBackup as Backup, NodeAutomationConnection as Connection, NodeAutomationGroup as Group,
    NodeAutomationLog as Event};
use App\Services\NodeAutomation\{AutomationService, CloudflareDns, ProxyProbe};
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Queue, Schema};
use Illuminate\Support\Str;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/xboard-node-bot-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->useEnvironmentPath($root);
$app->afterBootstrapping(LoadConfiguration::class, function ($app) {
    $app['config']->set([
        'app.key' => 'base64:' . base64_encode(str_repeat('n', 32)), 'app.env' => 'testing', 'app.debug' => false, 'logging.default' => 'null',
        'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
        'cache.default' => 'array', 'cache.stores.redis' => ['driver' => 'array'], 'node_automation.bot_token_sha256' => null,
    ]);
});
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error . PHP_EOL); exit(1); });
Queue::fake();

class BotFakeDns extends CloudflareDns
{
    public array $record = ['id' => 'record-1', 'name' => 'us.example.test', 'type' => 'A', 'content' => '198.51.100.1', 'proxied' => false, 'ttl' => 300];
    public function __construct() {}
    public function match(Connection $connection, string $domain): array { return $this->record; }
    public function read(Connection $connection, string $recordId, string $domain): array { return $this->record; }
    public function patch(Connection $connection, string $recordId, array $changes): void { $this->record = array_merge($this->record, $changes); }
}
class BotFakeProbe extends ProxyProbe
{
    public array $ok = [];
    public function __construct() {}
    public function fingerprint(Group $group): string { return 'config-1'; }
    public function readiness(): void {}
    public function targets(): array { return ['https://one.test/204']; }
    public function dnsMatches(string $domain, string $ip): bool { return true; }
    public function check(Group $group, string $ip, array $targets): array {
        $ok = $this->ok[$ip] ?? false;
        return ['ok' => $ok, 'nodes' => [['node_id' => 1, 'ok' => $ok]]];
    }
}
$dns = new BotFakeDns();
$probe = new BotFakeProbe();
$app->instance(CloudflareDns::class, $dns);
$app->instance(ProxyProbe::class, $probe);

$count = 0;
function check(bool $ok, string $name): void { global $count; if (!$ok) throw new RuntimeException($name); $count++; }
$token = bin2hex(random_bytes(32));
function bot(string $method, string $path, array $body = [], int $expected = 200, ?string $key = null, ?string $auth = null): array
{
    global $kernel, $token;
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . ($auth ?? $token)];
    if ($key !== null) $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
    $response = $kernel->handle(Request::create('/api/v1/node-automation-bot' . $path, $method, [], [], [], $headers, json_encode($body)));
    check($response->getStatusCode() === $expected, "$method $path expected $expected, got " . $response->getStatusCode() . ' ' . substr((string) $response->getContent(), 0, 600));
    return json_decode((string) $response->getContent(), true) ?? [];
}
function runQueued(): void
{
    foreach (Event::where('status', 'queued')->orderBy('id')->pluck('id') as $id) app(AutomationService::class)->runOperation($id);
}

Schema::create('v2_server', function (Blueprint $t) {
    $t->id(); $t->string('type'); $t->string('code')->nullable(); $t->json('group_ids')->nullable(); $t->string('name');
    $t->string('host'); $t->string('port'); $t->timestamps();
});
foreach (['2025_01_18_140511_create_plugins_table', '2025_07_26_000001_add_type_to_plugins_table',
    '2026_10_08_000001_create_node_automation_tables', '2026_10_08_000002_create_node_automation_checks_table',
    '2026_10_08_000003_create_node_automation_settings_table', '2026_10_18_000001_add_group_probe_users_to_node_automation_settings',
    '2026_11_26_000001_add_actor_to_node_automation_logs'] as $migration) {
    (require database_path("migrations/{$migration}.php"))->up();
}
$now = now();
DB::table('v2_server')->insert([
    ['type' => 'vless', 'code' => '1', 'group_ids' => '["1"]', 'name' => 'US 01', 'host' => 'us.example.test', 'port' => '1001', 'created_at' => $now, 'updated_at' => $now],
    ['type' => 'vless', 'code' => '2', 'group_ids' => '["1"]', 'name' => 'US 02', 'host' => 'US.example.test.', 'port' => '1002', 'created_at' => $now, 'updated_at' => $now],
    ['type' => 'vless', 'code' => '3', 'group_ids' => '["1"]', 'name' => 'JP 01', 'host' => 'jp.example.test', 'port' => '443', 'created_at' => $now, 'updated_at' => $now],
    ['type' => 'vless', 'code' => '4', 'group_ids' => '["1"]', 'name' => 'Raw IP', 'host' => '203.0.113.9', 'port' => '443', 'created_at' => $now, 'updated_at' => $now],
]);
$connection = Connection::create(['api_token' => 'cf-secret-never-returned', 'zone_name' => 'example.test', 'zone_id' => 'zone-1', 'verified_at' => now()]);
$group = Group::create(['domain' => 'us.example.test', 'connection_id' => $connection->id, 'record_id' => 'record-1',
    'current_ip' => '198.51.100.1', 'config_hash' => 'config-1', 'enabled' => true, 'state' => 'healthy']);
$first = Backup::create(['group_id' => $group->id, 'name' => 'old spare', 'ip' => '198.51.100.2', 'priority' => 3, 'enabled' => true]);

// Authentication: disabled by default, then bearer-only against the configured digest.
bot('GET', '/domains', expected: 403);
config(['node_automation.bot_token_sha256' => hash('sha256', $token)]);
bot('GET', '/domains', expected: 401, auth: 'wrong-token');
bot('GET', '/domains');

// Read state.
$list = bot('GET', '/domains')['data'];
check(array_column($list, 'domain') === ['us.example.test', 'jp.example.test'], 'domains follow node order, merge case/trailing dot, skip IP hosts');
check($list[0]['configured'] && $list[0]['enabled'] && count($list[0]['nodes']) === 2, 'configured domain lists both nodes');
check($list[1]['state'] === 'unconfigured' && !$list[1]['configured'] && $list[1]['backups'] === [], 'unconfigured domain still listed');
$raw = json_encode(bot('GET', '/domains/us.example.test'));
foreach (['cf-secret', 'config-1', 'record-1', 'zone-1'] as $secret) check(!str_contains($raw, $secret), "detail never exposes $secret");
bot('GET', '/domains/unknown.example.test', expected: 404);

// Add backups: append is allowed while automation is on, defaults are filled, invariants still enforced.
$added = bot('POST', '/domains/us.example.test/backups', ['ip' => '8.8.4.4'], 201)['data'];
check($added['state'] === 'unchecked' && $added['enabled'] && $added['name'] === 'Hermes 8.8.4.4' && $added['priority'] === 4,
    'new backup joins unchecked, named and ordered after existing spares');
check($group->refresh()->enabled && $group->state === 'healthy', 'appending a backup keeps automation running');
$duplicate = bot('POST', '/domains/us.example.test/backups', ['ip' => '8.8.4.4'], 409);
check($duplicate['data']['id'] === $added['id'], 'duplicate IP returns the existing backup');
bot('POST', '/domains/us.example.test/backups', ['ip' => '10.0.0.8'], 422);
bot('POST', '/domains/us.example.test/backups', ['ip' => '198.51.100.1'], 422);
bot('POST', '/domains/us.example.test/backups', ['ip' => 'not-an-ip'], 422);
bot('POST', '/domains/jp.example.test/backups', ['ip' => '1.1.1.1'], 409);
bot('POST', '/domains/unknown.example.test/backups', ['ip' => '1.1.1.1'], 404);
check(Backup::count() === 2, 'rejected requests never create backups');

// Test a backup: async, idempotent, attributed to the bot.
$key = (string) Str::uuid();
$op = bot('POST', '/domains/us.example.test/backups/8.8.4.4/test', [], 202, $key)['data'];
$replay = bot('POST', '/domains/us.example.test/backups/8.8.4.4/test', [], 202, $key)['data'];
check($op['id'] === $replay['id'] && $op['status'] === 'queued' && !$op['done'] && $op['actor'] === 'hermes' && $op['backup_ip'] === '8.8.4.4',
    'test is queued once per idempotency key and attributed to Hermes');
check(Queue::pushed(NodeAutomationJob::class)->count() === 1, 'replayed key dispatches the job once');
bot('POST', '/domains/us.example.test/backups/8.8.4.4/test', [], 422, 'not-a-uuid');
bot('POST', '/domains/us.example.test/backups/9.9.9.9/test', [], 404);
bot('POST', '/domains/us.example.test/backups', ['ip' => '1.0.0.1'], 422);
check(!Backup::where('ip', '1.0.0.1')->exists(), 'backups cannot be added while an operation is pending');
$probe->ok['8.8.4.4'] = true;
runQueued();
$done = bot('GET', '/operations/' . $op['id'])['data'];
check($done['done'] && $done['succeeded'] && $done['nodes'][0]['ok'] === true, 'operation reports completed test with node results');
check(Backup::find($added['id'])->state === 'ready', 'passing test marks backup ready');
bot('GET', '/operations/999999', expected: 404);

// Pause, enable and observe.
bot('POST', '/domains/us.example.test/enable', [], 422);
$paused = bot('POST', '/domains/us.example.test/pause')['data'];
check(!$paused['enabled'] && $paused['state'] === 'paused', 'pause turns automation off');
$probe->ok['198.51.100.1'] = true;
$enable = bot('POST', '/domains/us.example.test/enable', [], 202)['data'];
runQueued();
$enabled = bot('GET', '/operations/' . $enable['id'])['data'];
check($enabled['succeeded'] && $group->refresh()->enabled && $dns->record['ttl'] === 60, 'enable re-tests and turns automation on with TTL 60');
check(Event::find($enable['id'])->actor === 'hermes' && Event::find($enable['id'])->admin_id === null, 'log row records bot actor');
$detail = bot('GET', '/domains/us.example.test')['data'];
check($detail['current_check']['status'] === 'pass' && $detail['backups'][1]['latest_check']['status'] === 'pass', 'detail includes latest checks');

// Unconfigured groups and admin-only actions stay closed.
$group->update(['config_hash' => null, 'enabled' => false]);
bot('POST', '/domains/us.example.test/enable', [], 409);
foreach (['/domains/us.example.test/switch', '/domains/us.example.test/manual'] as $path) bot('POST', $path, [], 404);
$kernel->handle(Request::create('/api/v1/node-automation-bot/domains/us.example.test/backups/8.8.4.4', 'DELETE', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]))->getStatusCode() === 200
    && throw new RuntimeException('bot cannot delete backups');

echo "node automation bot API: {$count} checks passed\n";
