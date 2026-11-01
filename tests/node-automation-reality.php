<?php
declare(strict_types=1);

function admin_setting($key, $default = null) { return $default; }
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\{Server, User};
use App\Services\NodeAutomation\{AutomationException, MihomoProbeRuntime, ProbeProtocol, UnsupportedProtocolException};
use Illuminate\Container\Container;

$app = new Container();
Container::setInstance($app);
$app->instance('app', $app);
$app->instance('hook.filters', []);
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
$legacy->protocol_settings = array_diff_key($settings, ['backend' => true]);
expect(!MihomoProbeRuntime::required([$legacy]), 'legacy REALITY retains the original runtime');
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
echo "node automation REALITY: {$checks} checks passed\n";
