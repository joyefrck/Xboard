<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\VlessConfigService;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$private = random_bytes(32);
$public = sodium_crypto_scalarmult_base($private);
$encode = fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$settings = [
    'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
    'reality_settings' => ['private_key' => $encode($private), 'public_key' => $encode($public), 'server_name' => 'example.com', 'server_port' => 443, 'short_id' => '0123456789abcdef'],
];
check(VlessConfigService::serverDecryption($settings) === 'none', 'old nodes remain unencrypted inside REALITY');
check(VlessConfigService::clientFingerprint($settings) === null, 'legacy fingerprint selection remains unchanged');
$settings['utls'] = ['enabled' => true, 'fingerprint' => 'chrome'];
check(VlessConfigService::clientFingerprint($settings) === 'chrome', 'explicit fingerprint is used');
$settings['backend'] = ['core' => 'xray', 'release' => 'core-v1.1.0'];
$settings['encryption'] = [
    'enabled' => true,
    'encryption' => 'mlkem768x25519plus.native.0rtt.' . $encode($public),
    'decryption' => 'mlkem768x25519plus.native.600s.' . $encode($private),
];
check(VlessConfigService::errors($settings) === [], 'valid paired encryption accepted');
$client = VlessConfigService::clientSettings($settings);
check(!isset($client['encryption']['decryption'], $client['reality_settings']['private_key']), 'server secrets excluded from client projection');
check(VlessConfigService::serverDecryption($settings) === $settings['encryption']['decryption'], 'full server parameter forwarded');
$invalid = $settings;
$invalid['encryption']['encryption'] = 'mlkem768x25519plus.native.0rtt.' . $encode(random_bytes(32));
check(VlessConfigService::errors($invalid) !== [], 'mismatched X25519 pair rejected');
$invalid = $settings;
$invalid['reality_settings']['short_id'] = 'abc';
check(VlessConfigService::errors($invalid) !== [], 'odd short ID rejected');
$invalid = $settings;
$invalid['network'] = 'xhttp';
check(VlessConfigService::errors($invalid) !== [], 'unverified Vision/XHTTP combination rejected');
$settings['network'] = 'xhttp';
$settings['flow'] = '';
$settings['network_settings'] = ['path' => '/test', 'mode' => 'auto'];
check(VlessConfigService::errors($settings) === [], 'XHTTP with empty flow accepted');
check(!VlessConfigService::supportsClient($settings, 'ClashMeta', null, null), 'unknown client cannot receive enhanced node');
check(VlessConfigService::supportsClient($settings, 'ClashMeta', 'meta', '1.19.33'), 'verified mihomo format supports enhanced output');
check(!VlessConfigService::supportsClient($settings, 'SingBox', 'sing-box', '1.14.0'), 'unsupported renderer does not downgrade encrypted node');
echo "VLESS config: {$checks} checks passed\n";

$app = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($app);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->instance('app', $app);
$app->instance('hook.filters', []);
$node = ['name' => '测试 # & 节点', 'type' => 'vless', 'host' => '2001:db8::1', 'port' => 443, 'password' => '11111111-1111-4111-8111-111111111111', 'protocol_settings' => $settings];
$meta = App\Protocols\ClashMeta::buildVless($node['password'], $node);
check($meta['encryption'] === $settings['encryption']['encryption'] && $meta['network'] === 'xhttp' && $meta['flow'] === '', 'subscription includes complete encryption and XHTTP');
check($meta['client-fingerprint'] === 'chrome', 'subscription uses selected uTLS');
check(!str_contains(json_encode($meta), $settings['encryption']['decryption']), 'subscription does not expose server decryption');
$link = App\Protocols\General::buildVless($node['password'], $node);
parse_str(parse_url(trim($link), PHP_URL_QUERY), $params);
check($params['encryption'] === $meta['encryption'] && $params['type'] === 'xhttp' && $params['fp'] === 'chrome', 'URI carries full parameters');
check(str_ends_with(trim($link), '#' . rawurlencode($node['name'])), 'URI name safely encoded');
$projection = new ReflectionProperty(App\Support\AbstractProtocol::class, 'servers');
$unsupported = new App\Protocols\ClashMeta([], [$node], 'meta', '1.19.0');
check($projection->getValue($unsupported) === [], 'unsupported client cannot receive downgraded node');
$supported = new App\Protocols\ClashMeta([], [$node], 'meta', '1.19.33');
$nodes = $projection->getValue($supported);
check(count($nodes) === 1 && !isset($nodes[0]['protocol_settings']['encryption']['decryption']), 'actual renderer projects safe supported node');
$plain = $node;
$plain['protocol_settings'] = ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision'];
check(count($projection->getValue(new App\Protocols\ClashMeta([], [$plain]))) === 1, 'legacy node remains available to unknown client');
$legacy = (new App\Models\Server())->forceFill(['type' => 'vless', 'protocol_settings' => $plain['protocol_settings']]);
check(!isset($legacy->protocol_settings['encryption'], $legacy->protocol_settings['utls']), 'model does not inject enhancement into old nodes');
$roundtrip = (new App\Models\Server())->forceFill(['type' => 'vless', 'protocol_settings' => $settings]);
check($roundtrip->protocol_settings['encryption']['decryption'] === $settings['encryption']['decryption'], 'model preserves full private parameter');
$settings['encryption']['decryption'] = 'mlkem768x25519plus.native.600s.' . $encode(random_bytes(64));
$settings['encryption']['encryption'] = 'mlkem768x25519plus.native.0rtt.' . $encode(random_bytes(1184));
check(VlessConfigService::errors($settings) !== [], 'unverified MLKEM authentication pair fails closed');
echo "VLESS integration: {$checks} checks passed\n";

$db = new Illuminate\Database\Capsule\Manager($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$db->schema()->create('v2_node_automation_groups', function (Illuminate\Database\Schema\Blueprint $table): void { $table->id(); $table->string('domain'); $table->boolean('enabled'); });
$translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
$factory = new Illuminate\Validation\Factory($translator, $app);
$payload = ['type' => 'vless', 'host' => 'node.example.test', 'name' => 'test', 'port' => 443, 'server_port' => 443, 'rate' => 1, 'protocol_settings' => $node['protocol_settings']];
$request = App\Http\Requests\Admin\ServerSave::create('/', 'POST', $payload);
$validator = $factory->make($payload, $request->rules());
$request->withValidator($validator);
check(!$validator->fails(), 'actual admin validator accepts valid enhancement');
$db->table('v2_node_automation_groups')->insert(['domain' => 'node.example.test', 'enabled' => true]);
$validator = $factory->make($payload, $request->rules());
$request->withValidator($validator);
check($validator->fails(), 'actual validator blocks enhanced node on active automation domain');
echo "VLESS admin validation: {$checks} checks passed\n";

$db->schema()->create('v2_server', function (Illuminate\Database\Schema\Blueprint $table): void { $table->id(); $table->string('type'); $table->json('protocol_settings'); });
$oldSettings = ['network' => 'h2', 'tls' => 0, 'flow' => 'xtls-rprx-direct'];
$old = (new App\Models\Server())->forceFill(['type' => 'vless', 'protocol_settings' => $oldSettings]);
$db->table('v2_server')->insert(['id' => 9, 'type' => 'vless', 'protocol_settings' => $old->getAttributes()['protocol_settings']]);
$oldPayload = array_replace($payload, ['id' => 9, 'protocol_settings' => $oldSettings]);
$oldRequest = App\Http\Requests\Admin\ServerSave::create('/', 'POST', $oldPayload);
$oldValidator = $factory->make($oldPayload, $oldRequest->rules());
$oldRequest->withValidator($oldValidator);
check(!$oldValidator->fails(), 'unchanged legacy protocol permits metadata-only edit');
$extra = ['network_settings' => ['extra' => ['xPaddingBytes' => '100-200', 'sessionIDKey' => 'sid', 'xmux' => ['maxConcurrency' => '8-16', 'hKeepAlivePeriod' => 30]]]];
$options = VlessConfigService::clashXhttpOptions($extra);
check($options['x-padding-bytes'] === '100-200' && $options['session-key'] === 'sid' && $options['reuse-settings']['max-concurrency'] === '8-16', 'Mihomo XHTTP options translate the Xray field names');
$probeNode = (new App\Models\Server())->forceFill(['type' => 'vless', 'protocol_settings' => $node['protocol_settings']]);
try {
    App\Services\NodeAutomation\ProbeProtocol::assertSupported($probeNode);
    check(false, 'enhanced node must be rejected by probe');
} catch (App\Services\NodeAutomation\UnsupportedProtocolException) {
    check(true, 'probe refuses enhancement before building a downgraded outbound');
}
echo "VLESS legacy/probe/XHTTP: {$checks} checks passed\n";

$modernReality = $node['protocol_settings'];
$modernReality['network'] = 'tcp';
$modernReality['flow'] = 'xtls-rprx-vision';
$modernReality['encryption']['enabled'] = false;
check(VlessConfigService::needsHybridReality($modernReality), 'registered new REALITY core requires hybrid ClientHello');
$modernPayload = array_replace($payload, ['protocol_settings' => $modernReality]);
$modernRequest = App\Http\Requests\Admin\ServerSave::create('/', 'POST', $modernPayload);
$modernValidator = $factory->make($modernPayload, $modernRequest->rules());
$modernRequest->withValidator($modernValidator);
check(!$modernValidator->fails(), 'modern REALITY without Encryption or XHTTP permits active automation');
$modernNode = $node;
$modernNode['protocol_settings'] = VlessConfigService::clientSettings($modernReality);
$modernProxy = App\Protocols\ClashMeta::buildVless($node['password'], $modernNode);
check($modernProxy['reality-opts']['support-x25519mlkem768'] === true, 'new REALITY subscription explicitly enables required hybrid KeyShare');
$modernReality['backend']['release'] = 'core-v1.0.2';
check(!VlessConfigService::needsHybridReality($modernReality), 'old REALITY core keeps legacy ClientHello behavior');
echo "VLESS REALITY compatibility: {$checks} checks passed\n";

$manager = new App\Support\ProtocolManager($app);
$protocolClasses = new ReflectionProperty($manager, 'protocolClasses');
$protocolClasses->setValue($manager, [App\Protocols\ClashMeta::class]);
$app->instance('protocols.flags', $manager->getAllFlags());
$controller = new App\Http\Controllers\V1\Client\ClientController();
$identify = new ReflectionMethod($controller, 'getClientInfo');
$elephantUa = 'Elephant Network/v2.1.0 clash-verge Platform/macos';
foreach ([
    $elephantUa,
    'Elephant Network/v2.1.0 clash-verge Platform/windows',
    'Elephant Network/v2.1.0 clash-verge Platform/android',
    'Elephant Network/v2.1.0 clash-verge Platform/linux',
] as $ua) {
    foreach (['meta', null] as $flag) {
        $request = Illuminate\Http\Request::create('/subscribe', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
        $info = $identify->invoke($controller, $request);
        check($info['name'] === 'elephantnetwork' && $info['version'] === '2.1.0', 'released Elephant identity is independent of its Verge compatibility token');
        check($manager->matchProtocolClassName($info['flag']) === App\Protocols\ClashMeta::class, 'Elephant selects ClashMeta with or without an explicit format flag');
        $renderer = new App\Protocols\ClashMeta([], [$node, $plain, $modernNode], $info['name'], $info['version']);
        check(count($projection->getValue($renderer)) === 3, 'Elephant 2.1.0 retains enhanced, legacy and modern REALITY nodes');
    }
}
foreach ([
    'Elephant Network/v2.0.7 clash-verge Platform/windows',
    'Elephant Network clash-verge Platform/macos',
    'Elephant Network/v2.1.0-beta clash-verge Platform/macos',
    'NotElephant Network/v2.1.0 clash-verge Platform/macos',
    'clash-verge/v2.1.0 Platform/macos',
] as $ua) {
    $request = Illuminate\Http\Request::create('/subscribe', 'GET', ['flag' => 'meta'], [], [], ['HTTP_USER_AGENT' => $ua]);
    $info = $identify->invoke($controller, $request);
    $renderer = new App\Protocols\ClashMeta([], [$node, $plain, $modernNode], $info['name'], $info['version']);
    check(count($projection->getValue($renderer)) === 1, 'unverified client identity retains only the legacy node: ' . $ua);
}
$request = Illuminate\Http\Request::create('/subscribe', 'GET', ['flag' => 'mihomo/1.19.33'], [], [], ['HTTP_USER_AGENT' => $elephantUa]);
$info = $identify->invoke($controller, $request);
check($info['name'] === 'mihomo' && $info['version'] === '1.19.33', 'explicit versioned client flag retains precedence');
check(!VlessConfigService::supportsClient($node['protocol_settings'], 'SingBox', 'elephantnetwork', '2.1.0'), 'Elephant identity does not enable unsupported renderers');
echo "VLESS Elephant subscription compatibility: {$checks} checks passed\n";
