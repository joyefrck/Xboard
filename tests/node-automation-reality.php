<?php
declare(strict_types=1);

function admin_setting($key, $default = null) { return $default; }
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\{NodeAutomationGroup as Group, Server, User};
use App\Protocols\ClashMeta;
use App\Services\NodeAutomation\{AutomationException, MihomoProbeRuntime, ProbeProtocol, ProxyProbe, UnsupportedProtocolException};
use Illuminate\Container\Container;

$app = new Container();
Container::setInstance($app);
$app->instance('app', $app);
$app->instance('hook.filters', []);
$app->instance('config', new Illuminate\Config\Repository(['node_automation' => ['request_timeout' => 8]]));
$checks = 0;
function expect(bool $value, string $message): void {
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}

$settings = [
    'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
    'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
    'backend' => ['core' => 'xray', 'release' => 'core-v1.1.0'],
    'reality_settings' => ['server_name' => 'camouflage.example.test', 'public_key' => 'fixture-public-key', 'short_id' => '1234'],
];
$node = (new Server())->forceFill(['id' => 4, 'name' => 'fixture', 'type' => 'vless', 'host' => 'node.example.test', 'port' => '1001', 'protocol_settings' => $settings]);
$user = (new User())->forceFill(['uuid' => '11111111-1111-4111-8111-111111111111']);
$runtime = new MihomoProbeRuntime();
expect(MihomoProbeRuntime::required([$node]), 'new REALITY selects a compatible runtime');
$proxy = $runtime->outbound($node, $user, '198.51.100.1');
expect($proxy['server'] === '198.51.100.1' && $proxy['port'] === 1001, 'probe pins the requested IP and fixed port');
expect($proxy['servername'] === 'camouflage.example.test', 'pinned probe preserves original REALITY SNI');
expect($proxy['client-fingerprint'] === 'chrome' && $proxy['reality-opts']['support-x25519mlkem768'] === true, 'probe sends the required hybrid KeyShare');
expect($proxy['uuid'] === $user->uuid && $proxy['reality-opts']['public-key'] === 'fixture-public-key', 'probe preserves authentication');
expect($proxy['name'] === 'node-4' && $proxy['skip-cert-verify'] === false, 'probe uses an internal name and preserves verification');
$legacy = clone $node;
$legacy->protocol_settings = array_diff_key($settings, ['backend' => true, 'utls' => true]);
expect(MihomoProbeRuntime::required([$legacy]), 'REALITY without backend registration selects the compatible runtime');
$originalSettings = $legacy->protocol_settings;
$legacyProxy = $runtime->outbound($legacy, $user, '198.51.100.1');
expect($legacyProxy['client-fingerprint'] === 'chrome' && $legacyProxy['reality-opts']['support-x25519mlkem768'] === true, 'unregistered REALITY probes support new and old server cores');
expect($legacy->protocol_settings === $originalSettings, 'probe defaults do not change node settings');
$subscription = ClashMeta::buildVless($user->uuid, $legacy->toArray());
expect(!isset($subscription['reality-opts']['support-x25519mlkem768']), 'probe defaults do not enable hybrid REALITY in subscriptions');
foreach (['firefox', 'safari'] as $fingerprint) {
    $candidate = clone $legacy;
    $candidate->protocol_settings = array_replace($originalSettings, ['utls' => ['enabled' => true, 'fingerprint' => $fingerprint]]);
    $candidateSettings = $candidate->protocol_settings;
    $candidateProxy = $runtime->outbound($candidate, $user, '198.51.100.1');
    expect($candidateProxy['client-fingerprint'] === 'chrome', 'probe uses Chrome independently of node fingerprint ' . $fingerprint);
    expect($candidate->protocol_settings === $candidateSettings, 'probe preserves configured node fingerprint ' . $fingerprint);
    expect(ClashMeta::buildVless($user->uuid, $candidate->toArray())['client-fingerprint'] === $fingerprint, 'subscription retains node fingerprint ' . $fingerprint);
}
expect(!MihomoProbeRuntime::required([]), 'empty group does not select Mihomo');
foreach ([['vless', 0], ['vless', 1], ['vmess', 2], ['trojan', 2], ['hysteria', 2]] as [$type, $tls]) {
    $candidate = clone $node;
    $candidate->type = $type;
    $candidate->protocol_settings = ['tls' => $tls];
    expect(!MihomoProbeRuntime::required([$candidate]), 'non-REALITY group retains original runtime: ' . $type . '/' . $tls);
}
$stringTls = clone $legacy;
$stringTls->protocol_settings = array_replace($originalSettings, ['tls' => '2']);
expect(MihomoProbeRuntime::required([$stringTls]), 'numeric string REALITY mode is supported');
expect(MihomoProbeRuntime::required([$legacy, $node]), 'a mixed group uses the compatible runtime for every member');
$legacy->id = 5;
$configuration = $runtime->configuration([$proxy, $runtime->outbound($legacy, $user, '198.51.100.1')], [4 => 19001, 5 => 19002]);
expect($configuration['rules'] === ['MATCH,REJECT'], 'probe never falls back to direct traffic');
expect(count($configuration['listeners']) === 2 && $configuration['listeners'][0]['proxy'] === 'node-4' && $configuration['listeners'][1]['proxy'] === 'node-5', 'each local listener routes only to its matching node');
expect($configuration['listeners'][0]['listen'] === '127.0.0.1' && $configuration['listeners'][0]['port'] === 19001, 'listeners stay on reserved loopback ports');
expect(!isset($configuration['external-controller']), 'probe exposes no controller API');
foreach (['trojan', 'hysteria', 'tuic', 'anytls'] as $type) {
    $mixed = (new Server())->forceFill(['id' => 6, 'name' => 'mixed', 'type' => $type, 'host' => 'original.example.test', 'port' => '443',
        'protocol_settings' => match ($type) { 'hysteria' => ['version' => 2], 'tuic' => ['version' => 5], default => [] }]);
    $mixedProxy = $runtime->outbound($mixed, $user, '198.51.100.1');
    expect($mixedProxy['sni'] === 'original.example.test', 'mixed ' . $type . ' preserves hostname verification after IP pinning');
}
foreach (['encryption' => array_replace($settings, ['encryption' => ['enabled' => true]]), 'xhttp' => array_replace($settings, ['network' => 'xhttp', 'flow' => ''])] as $name => $unsupported) {
    $candidate = clone $node;
    $candidate->protocol_settings = $unsupported;
    try { $runtime->outbound($candidate, $user, '198.51.100.1'); expect(false, $name . ' must remain blocked'); }
    catch (UnsupportedProtocolException) { expect(true, $name . ' is not silently downgraded'); }
}
try { ProbeProtocol::assertSupported($node); expect(false, 'legacy runtime cannot build modern REALITY'); }
catch (UnsupportedProtocolException) { expect(true, 'legacy runtime remains protected'); }
ProbeProtocol::assertSupported($node, true);
expect(true, 'compatible runtime can fingerprint modern REALITY');
$node->port = '1001-1005';
try { $runtime->outbound($node, $user, '198.51.100.1'); expect(false, 'dynamic ports must fail'); }
catch (AutomationException) { expect(true, 'dynamic ports rejected'); }

// The active VPS and backup may require different REALITY KeyShare modes.
class CompatibleRuntimeFixture extends MihomoProbeRuntime {
    public function readiness(): void {}
}
class CompatibleProbeFixture extends ProxyProbe {
    public array $runs = [];
    public function __construct(public array $fixtureNodes, public User $identity, public array $responses) {}
    public function nodes(Group $group): array { return $this->fixtureNodes; }
    public function user(Group $group, Server $node): User { return $this->identity; }
    protected function runtime(array $nodes): ?MihomoProbeRuntime { return new CompatibleRuntimeFixture(); }
    protected function run(array $config, array $requests, array $ports, int $targetCount): array {
        $this->runs[] = compact('config', 'requests', 'ports', 'targetCount');
        return array_shift($this->responses) ?? throw new RuntimeException('unexpected probe attempt');
    }
}
$legacy->port = '1001';
$other = (new Server())->forceFill(['id' => 6, 'name' => 'other', 'type' => 'trojan', 'host' => 'other.example.test', 'port' => '443', 'protocol_settings' => []]);
$targets = ['https://one.example.test/generate_204', 'https://two.example.test/generate_204'];
$group = (new Group())->forceFill(['domain' => 'node.example.test']);
$first = ['ok' => false, 'nodes' => [['node_id' => 5, 'ok' => false], ['node_id' => 6, 'ok' => true]]];
$second = ['ok' => true, 'nodes' => [['node_id' => 5, 'ok' => true]]];
$probe = new CompatibleProbeFixture([$legacy, $other], $user, [$first, $second]);
expect($probe->check($group, '198.51.100.1', $targets)['ok'], 'legacy REALITY can pass the authenticated compatibility retry');
expect(count($probe->runs) === 2, 'failed REALITY retries once');
$retry = $probe->runs[1];
expect(array_keys($retry['ports']) === [5] && count($retry['config']['proxies']) === 1 && count($retry['requests']) === 2, 'retry only includes failed REALITY nodes and their targets');
expect($retry['config']['proxies'][0]['reality-opts']['support-x25519mlkem768'] === false, 'retry uses traditional KeyShare');
expect($retry['config']['proxies'][0]['uuid'] === $legacyProxy['uuid'] && $retry['config']['proxies'][0]['servername'] === $legacyProxy['servername'], 'retry preserves identity and SNI');
expect($retry['config']['rules'] === ['MATCH,REJECT'], 'retry also forbids direct fallback');
expect($legacy->protocol_settings === $originalSettings, 'compatibility retry does not change node configuration');
$passed = ['ok' => true, 'nodes' => [['node_id' => 5, 'ok' => true], ['node_id' => 6, 'ok' => true]]];
$probe = new CompatibleProbeFixture([$legacy, $other], $user, [$passed]);
expect($probe->check($group, '198.51.100.1', $targets) === $passed && count($probe->runs) === 1, 'successful probes do not retry');
$nonRealityFailure = ['ok' => false, 'nodes' => [['node_id' => 5, 'ok' => true], ['node_id' => 6, 'ok' => false]]];
$probe = new CompatibleProbeFixture([$legacy, $other], $user, [$nonRealityFailure]);
expect($probe->check($group, '198.51.100.1', $targets) === $nonRealityFailure && count($probe->runs) === 1, 'non-REALITY failures never trigger KeyShare retry');
$bothFailed = ['ok' => false, 'nodes' => [['node_id' => 6, 'ok' => false], ['node_id' => 5, 'ok' => false]]];
$probe = new CompatibleProbeFixture([$other, $legacy], $user, [$bothFailed, $second]);
$result = $probe->check($group, '198.51.100.1', $targets);
expect($result === ['ok' => false, 'nodes' => [['node_id' => 6, 'ok' => false], ['node_id' => 5, 'ok' => true]]], 'REALITY retry does not hide failures of other nodes');
$retry = $probe->runs[1];
expect($retry['requests'][0]['proxy'] === $probe->runs[0]['requests'][2]['proxy'] && $retry['ports'] === [5 => $probe->runs[0]['ports'][5]], 'retry preserves later node request and port mapping in mixed groups');
$stillFailed = ['ok' => false, 'nodes' => [['node_id' => 5, 'ok' => false]]];
$probe = new CompatibleProbeFixture([$legacy, $other], $user, [$first, $stillFailed]);
expect(!$probe->check($group, '198.51.100.1', $targets)['ok'] && count($probe->runs) === 2, 'two failed authenticated attempts remain a failure');
echo "node automation REALITY: {$checks} checks passed\n";
