<?php
declare(strict_types=1);

function admin_setting($key = null, $default = null) {
    return $GLOBALS['third_party_subscription_settings'][$key] ?? $default;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Controllers\V1\Client\ClientController;
use App\Models\User;
use App\Services\UserService;
use App\Support\ProtocolManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function thirdPartySubscription(string $ua, array $nodes, ?string $flag = null, bool $defaultTemplates = false): Response {
    $app = new Application(dirname(__DIR__));
    Facade::setFacadeApplication($app);
    $app->instance('files', new Illuminate\Filesystem\Filesystem());
    $app->instance('hook.filters', []);
    $manager = new ProtocolManager($app);
    $manager->registerAllProtocols();
    $app->instance('protocols.manager', $manager);
    $app->instance('protocols.flags', $manager->getAllFlags());
    $app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
        public function __construct() {}
    });
    $app->instance(UserService::class, new class extends UserService {
        public function getTrafficSummary(User $user): array {
            return ['effective_transfer_enable' => 1073741824, 'effective_expired_at' => 2000000000];
        }
    });
    $GLOBALS['third_party_subscription_settings'] = [
        'app_name' => 'Third-party fixture',
        'subscribe_template_clashmeta' => Yaml::dump([
            'proxies' => [],
            'proxy-groups' => [['name' => 'select', 'type' => 'select', 'proxies' => []]],
            'rules' => ['MATCH,select'],
        ], 10),
        'subscribe_template_singbox' => [
            'outbounds' => [['type' => 'selector', 'tag' => 'select', 'outbounds' => []], ['type' => 'direct', 'tag' => 'direct']],
            'route' => ['rules' => [], 'final' => 'select'],
        ],
    ];
    if ($defaultTemplates) {
        $GLOBALS['third_party_subscription_settings'] = ['app_name' => 'Third-party fixture'];
    }
    $request = Request::create('https://subscription.example.test/s/private-fixture-token', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
    $app->instance('request', $request);
    $user = (new User())->forceFill(['uuid' => '11111111-1111-4111-8111-111111111111', 'u' => 17, 'd' => 29, 'transfer_enable' => 1073741824, 'expired_at' => 2000000000]);
    return (new ClientController())->doSubscribe($request, $user, $nodes);
}

if (($argv[1] ?? '') === '--render') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $response = thirdPartySubscription($input['ua'], $input['nodes'], $input['flag'] ?? null, $input['default_templates'] ?? false);
    $body = $response->getContent();
    $config = str_contains($response->headers->get('Content-Type', ''), 'json')
        ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : Yaml::parse($body);
    echo json_encode(['status' => $response->getStatusCode(), 'headers' => $response->headers->all(), 'body' => $body, 'config' => $config], JSON_THROW_ON_ERROR);
    exit;
}

$settings = [
    'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
    'network_settings' => ['header' => ['type' => 'none']],
    'reality_settings' => ['server_name' => 'fixture.test', 'public_key' => 'fixture-public-key', 'private_key' => 'private-key-never-export', 'short_id' => '0123456789abcdef', 'support_x25519mlkem768' => true],
];
$modern = ['name' => 'modern # & 03', 'type' => 'vless', 'host' => '2001:db8::1', 'port' => 3001, 'password' => '11111111-1111-4111-8111-111111111111', 'protocol_settings' => $settings];
$legacy = $modern;
$legacy['name'] = 'legacy';
$legacy['protocol_settings']['reality_settings']['support_x25519mlkem768'] = false;
$encrypted = $modern;
$encrypted['name'] = 'encrypted';
$encrypted['protocol_settings']['encryption'] = ['enabled' => true, 'encryption' => 'fixture-encryption', 'decryption' => 'server-decryption-never-export'];
$xhttp = $modern;
$xhttp['name'] = 'xhttp';
$xhttp['protocol_settings']['network'] = 'xhttp';
$xhttp['protocol_settings']['flow'] = '';
$all = [$modern, $encrypted, $xhttp, $legacy];

foreach (['FlClash/v0.9.3 clash-verge Platform/macos', 'FlClash/v0.9.4 Platform/windows', 'FlClash/v0.9.3 Platform/android', 'FlClash/v0.9.3 Platform/linux'] as $ua) {
    foreach ([null, 'meta', 'flclash'] as $flag) {
        $response = thirdPartySubscription($ua, $all, $flag);
        check($response->getStatusCode() === 200, 'official FlClash subscription succeeds: ' . $ua);
        $parsed = Yaml::parse($response->getContent());
        check(array_column($parsed['proxies'], 'name') === [$modern['name'], 'legacy'], 'FlClash receives modern and legacy nodes without enhanced nodes');
        $proxy = $parsed['proxies'][0];
        check($proxy['client-fingerprint'] === 'chrome' && $proxy['reality-opts']['support-x25519mlkem768'] === true, 'FlClash receives Chrome hybrid REALITY');
        check($proxy['flow'] === 'xtls-rprx-vision' && $proxy['uuid'] === $modern['password'] && $proxy['server'] === $modern['host'], 'FlClash retains Vision and node identity');
        check($proxy['reality-opts']['public-key'] === 'fixture-public-key' && $proxy['reality-opts']['short-id'] === '0123456789abcdef', 'FlClash retains REALITY authentication');
        check($parsed['proxy-groups'][0]['proxies'] === [$modern['name'], 'legacy'], 'FlClash selectors contain only emitted nodes');
        check($response->headers->get('X-Subscription-Compatible-Nodes') === '2' && $response->headers->get('X-Subscription-Filtered-Nodes') === '2', 'FlClash compatibility counts match actual nodes');
    }
    check(thirdPartySubscription($ua, [$modern])->getStatusCode() === 200, 'all-modern FlClash subscription no longer returns 422');
}

foreach (['HiddifyNext/5.5.5 (macos) like ClashMeta v2ray sing-box', 'HiddifyNext/5.5.6 (windows) like ClashMeta v2ray sing-box', 'HiddifyNext/5.5.5 (android) like ClashMeta v2ray sing-box', 'HiddifyNext/5.5.5 (linux) like ClashMeta v2ray sing-box', 'Hiddify/5.5.5'] as $ua) {
    foreach ([null, 'sing-box', 'hiddify'] as $flag) {
        $response = thirdPartySubscription($ua, $all, $flag);
        check($response->getStatusCode() === 200, 'official Hiddify subscription succeeds: ' . $ua);
        $parsed = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $proxies = array_values(array_filter($parsed['outbounds'], fn($node) => $node['type'] === 'vless'));
        check(array_column($proxies, 'tag') === [$modern['name'], 'legacy'], 'Hiddify receives modern and legacy nodes without enhanced nodes');
        $proxy = $proxies[0];
        check($proxy['tls']['utls']['fingerprint'] === 'chrome' && $proxy['tls']['reality']['key_share'] === 'hybrid', 'Hiddify receives native hybrid REALITY with Chrome');
        check($proxy['flow'] === 'xtls-rprx-vision' && $proxy['uuid'] === $modern['password'] && $proxy['server'] === $modern['host'], 'Hiddify retains Vision and node identity');
        check($proxy['tls']['reality']['public_key'] === 'fixture-public-key' && $proxy['tls']['reality']['short_id'] === '0123456789abcdef', 'Hiddify retains REALITY authentication');
        check(!isset($proxies[1]['tls']['reality']['key_share']), 'legacy REALITY does not gain a hybrid override');
        check($parsed['outbounds'][0]['outbounds'] === [$modern['name'], 'legacy'], 'Hiddify selectors contain only emitted nodes');
        check($response->headers->get('X-Subscription-Compatible-Nodes') === '2' && $response->headers->get('X-Subscription-Filtered-Nodes') === '2', 'Hiddify compatibility counts match actual nodes');
    }
    check(thirdPartySubscription($ua, [$modern])->getStatusCode() === 200, 'all-modern Hiddify subscription no longer returns 422');
}

foreach (['FlClash/v0.9.2', 'FlClash', 'FlClash/v0.9.3-beta', 'FlClash/v0.9.3.1', 'HiddifyNext/5.5.4 (macos) like ClashMeta v2ray sing-box', 'HiddifyNext/4.1.1 (android) like ClashMeta v2ray sing-box', 'HiddifyNext', 'Hiddify/5.5.5.dev', 'HiddifyNext/5.5.5.1', 'Other/5.5.5 Hiddify', 'Other/0.9.3 FlClash', 'HiddifyNext/4.1.1 FlClash/0.9.3'] as $ua) {
    $response = thirdPartySubscription($ua, [$modern]);
    check($response->getStatusCode() === 422, 'old, malformed or borrowed identity remains filtered: ' . $ua);
    $error = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    check($error['code'] === 'NO_COMPATIBLE_NODES', 'unsupported clients retain explicit compatibility error');
}

foreach (['FlClash/v0.9.2', 'Other/9.9.9', ''] as $ua) {
    check(thirdPartySubscription($ua, [$modern], 'flclash/0.9.3')->getStatusCode() === 422, 'versioned format flag cannot upgrade a FlClash identity');
}
foreach (['HiddifyNext/5.5.4', 'Other/9.9.9', ''] as $ua) {
    check(thirdPartySubscription($ua, [$modern], 'hiddify/5.5.5')->getStatusCode() === 422, 'versioned format flag cannot upgrade a Hiddify identity');
}
foreach (['FlClash/v0.9.3', 'HiddifyNext/5.5.5'] as $ua) {
    foreach ([$encrypted, $xhttp] as $node) {
        check(thirdPartySubscription($ua, [$node])->getStatusCode() === 422, 'new client support does not grant unverified enhanced capabilities');
    }
    foreach (['ws', 'grpc', 'httpupgrade'] as $transport) {
        $node = $modern;
        $node['protocol_settings']['network'] = $transport;
        $node['protocol_settings']['flow'] = '';
        check(thirdPartySubscription($ua, [$node])->getStatusCode() === 422, 'unverified hybrid transport stays filtered');
    }
    $node = $modern;
    $node['protocol_settings']['network_settings']['header']['type'] = 'http';
    check(thirdPartySubscription($ua, [$node])->getStatusCode() === 422, 'unverified TCP HTTP camouflage stays filtered');
    $registered = $modern;
    unset($registered['protocol_settings']['reality_settings']['support_x25519mlkem768']);
    $registered['protocol_settings']['backend'] = ['core' => 'xray', 'release' => 'core-v1.1.0'];
    check(thirdPartySubscription($ua, [$registered])->getStatusCode() === 200, 'backend-inferred hybrid node uses the same policy');
    $response = thirdPartySubscription($ua, [$registered]);
    check($response->headers->get('subscription-userinfo') === 'upload=17; download=29; total=1073741824; expire=2000000000', 'traffic headers remain intact');
    check(!str_contains($response->getContent(), 'private-key-never-export') && !str_contains($response->getContent(), 'backend') && !str_contains($response->getContent(), 'server-decryption-never-export'), 'output excludes server secrets and metadata');
}
check(thirdPartySubscription('HiddifyNext/5.5.5', [$modern], 'meta')->getStatusCode() === 422, 'Hiddify identity does not grant a foreign renderer');
check(thirdPartySubscription('FlClash/v0.9.3', [$modern], 'sing-box')->getStatusCode() === 422, 'FlClash identity does not grant a foreign renderer');
foreach (['HiddifyNext/4.1.1', 'FlClash/v0.9.2', 'sing-box/1.13.0'] as $ua) {
    $response = thirdPartySubscription($ua, [$legacy]);
    check($response->getStatusCode() === 200, 'legacy nodes remain available to previous clients');
    check(!str_contains($response->getContent(), 'key_share'), 'ordinary sing-box and legacy output keep their existing schema');
}
check(thirdPartySubscription('ElephantNetwork/2.1.0', [$encrypted])->getStatusCode() === 200, 'ElephantNetwork enhanced support remains independent');

echo "FlClash and Hiddify REALITY subscription pipeline: {$checks} checks passed\n";
