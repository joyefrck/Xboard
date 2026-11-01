<?php
declare(strict_types=1);

function admin_setting($key, $default = null) { return $default; }
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\{NodeAutomationGroup as Group, Server, User};
use App\Services\NodeAutomation\{MihomoProbeRuntime, ProxyProbe, UnsupportedProtocolException};
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

$fixture = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = new class($fixture['directory']) extends Container {
    public function __construct(private string $directory) {}
    public function storagePath($path = ''): string { return $this->directory . '/' . $path; }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('app', $app);
$app->instance('hook.filters', []);
$app->instance('config', new Illuminate\Config\Repository(['node_automation' => [
    'mihomo_binary' => $fixture['mihomo'], 'timeout_binary' => $fixture['timeout'],
    'targets' => [$fixture['target']], 'request_timeout' => 8,
]]));
$probe = new class($fixture) extends ProxyProbe {
    public Server $node;
    public User $identity;
    public function __construct(private array $fixture) {
        $this->node = (new Server())->forceFill($fixture['node']);
        $this->identity = (new User())->forceFill(['uuid' => $fixture['uuid']]);
    }
    public function nodes(Group $group): array { return [$this->node]; }
    public function user(Group $group, Server $node): User { return $this->identity; }
    protected function client(): GuzzleHttp\Client {
        return new GuzzleHttp\Client(array_replace(parent::client()->getConfig(), [
            'verify' => $this->fixture['certificate'],
            'on_stats' => static function (GuzzleHttp\TransferStats $stats): void {
                if ($stats->getHandlerErrorData()) {
                    fwrite(STDERR, 'Fixture transport error: ' . json_encode($stats->getHandlerErrorData()) . "\n");
                }
            },
        ]));
    }

};
$group = (new Group())->forceFill(['domain' => 'probe.example.test']);
$targets = $probe->targets();
function expect(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
    echo 'PASS: ' . $message . "\n";
}
if ($fixture['stopped'] ?? false) {
    expect(!$probe->check($group, '127.0.0.1', $targets)['ok'], 'stopped REALITY server fails instead of reaching the target directly');
} else {
    $runtime = new MihomoProbeRuntime();
    $proxies = [];
    foreach (['shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'socks', 'http'] as $index => $type) {
        $node = clone $probe->node;
        $node->type = $type;
        $node->id = 100 + $index;
        $node->protocol_settings = match ($type) {
            'shadowsocks' => ['cipher' => 'aes-128-gcm'], 'vmess' => ['network' => 'tcp'],
            'hysteria' => ['version' => 2], 'tuic' => ['version' => 5],
            'vless' => $fixture['node']['protocol_settings'], default => [],
        };
        $proxies[] = $runtime->outbound($node, $probe->identity, '127.0.0.1');
    }
    $validationPath = $fixture['directory'] . '/mixed-config.json';
    file_put_contents($validationPath, json_encode($runtime->configuration($proxies, [100 => 19001]), JSON_THROW_ON_ERROR));
    chmod($validationPath, 0600);
    $validation = new Symfony\Component\Process\Process([$fixture['mihomo'], '-t', '-d', $fixture['directory'], '-f', $validationPath]);
    $validation->disableOutput();
    $validation->run();
    unlink($validationPath);
    expect($validation->isSuccessful(), 'fixed Mihomo validates every supported protocol in a mixed group');
    expect($probe->check($group, '127.0.0.1', $targets)['ok'], 'actual PHP probe authenticates modern REALITY and completes verified HTTPS 204');
    $probe->identity->uuid = '22222222-2222-4222-8222-222222222222';
    expect(!$probe->check($group, '127.0.0.1', $targets)['ok'], 'wrong UUID fails authenticated proxy traffic');
    $probe->identity->uuid = $fixture['uuid'];
    $settings = $probe->node->protocol_settings;
    $settings['reality_settings']['public_key'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $probe->node->protocol_settings = $settings;
    expect(!$probe->check($group, '127.0.0.1', $targets)['ok'], 'wrong REALITY public key fails');
    $app['config']->set('node_automation.mihomo_binary', '/missing-mihomo-fixture');
    try { $probe->check($group, '127.0.0.1', $targets); expect(false, 'missing runtime must not count as network failure'); }
    catch (UnsupportedProtocolException) { expect(true, 'missing runtime is an unsupported configuration error'); }
}
expect(!(glob($fixture['directory'] . '/app/node-automation-probes/probe-*') ?: []), 'all probe credentials and Mihomo runtime files are removed');
