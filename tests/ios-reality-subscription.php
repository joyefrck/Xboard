<?php
declare(strict_types=1);

function admin_setting($key = null, $default = null) {
    return $GLOBALS['ios_subscription_settings'][$key] ?? $default;
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

function iosSubscription(string $ua, array $nodes, ?string $flag = null, bool $reuseContainer = false): Response {
    $app = $reuseContainer ? Facade::getFacadeApplication() : new Application(dirname(__DIR__));
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
    $template = ['proxies' => [], 'proxy-groups' => [['name' => 'select', 'type' => 'select', 'proxies' => []]], 'rules' => ['MATCH,select']];
    $GLOBALS['ios_subscription_settings'] = [
        'app_name' => 'iOS fixture',
        'subscribe_template_stash' => Yaml::dump($template, 10),
        'subscribe_template_clashmeta' => Yaml::dump($template, 10),
        'subscribe_template_singbox' => [
            'outbounds' => [['type' => 'selector', 'tag' => 'select', 'outbounds' => []], ['type' => 'direct', 'tag' => 'direct']],
            'route' => ['rules' => []],
        ],
    ];
    $request = Request::create('https://subscription.example.test/s/fixture-token-never-log', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
    $app->instance('request', $request);
    $user = (new User())->forceFill(['uuid' => '11111111-1111-4111-8111-111111111111', 'u' => 17, 'd' => 29, 'transfer_enable' => 1073741824, 'expired_at' => 2000000000]);
    return (new ClientController())->doSubscribe($request, $user, $nodes);
}

$encode = fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$private = $encode(random_bytes(32));
$settings = [
    'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
    'network_settings' => ['header' => ['type' => 'none']],
    'reality_settings' => ['server_name' => 'fixture.test', 'public_key' => $encode(random_bytes(32)), 'private_key' => $private, 'short_id' => '0123456789abcdef', 'support_x25519mlkem768' => true],
];
$modern = ['name' => 'modern', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 443, 'password' => '11111111-1111-4111-8111-111111111111', 'protocol_settings' => $settings];
$legacy = $modern;
$legacy['name'] = 'legacy';
$legacy['protocol_settings']['reality_settings']['support_x25519mlkem768'] = false;
$encrypted = $modern;
$encrypted['name'] = 'encrypted';
$encrypted['protocol_settings']['encryption'] = ['enabled' => true, 'encryption' => 'mlkem768x25519plus.native.0rtt.fixture', 'decryption' => 'server-decryption-never-export'];
$xhttp = $modern;
$xhttp['name'] = 'xhttp';
$xhttp['protocol_settings']['network'] = 'xhttp';
$xhttp['protocol_settings']['flow'] = '';
$xhttp['protocol_settings']['network_settings'] = ['path' => '/fixture', 'mode' => 'auto'];
$all = [$modern, $encrypted, $xhttp, $legacy];

foreach (['Stash/3.6.5', 'Stash/3.6.5 Clash/1.11.0', 'Stash/3.6.5 Clashlike/1.0.0 (iOS)', 'Stash/3.6.6 iOS/27.2'] as $ua) {
    foreach ([null, 'stash'] as $flag) {
        $response = iosSubscription($ua, $all, $flag);
        $parsed = Yaml::parse($response->getContent());
        check($response->getStatusCode() === 200, 'verified Stash subscription succeeds');
        check(array_column($parsed['proxies'], 'name') === ['modern', 'legacy'], 'verified Stash receives modern REALITY and legacy, without enhanced nodes');
        check($parsed['proxies'][0]['client-fingerprint'] === 'chrome', 'Stash modern REALITY receives deterministic Chrome');
        check($parsed['proxies'][0]['reality-opts']['support-x25519mlkem768'] === true, 'Stash modern REALITY emits hybrid KeyShare');
        check(!isset($parsed['proxies'][0]['network']) && !isset($parsed['proxies'][0]['http-opts']), 'Stash plain TCP with header none never emits unsupported network none or HTTP options');
        check(!isset($parsed['proxies'][1]['reality-opts']['support-x25519mlkem768']), 'Stash legacy REALITY does not opt into hybrid handshake');
        check($parsed['proxy-groups'][0]['proxies'] === ['modern', 'legacy'], 'Stash groups contain only compatible nodes');
        check(!str_contains($response->getContent(), $private) && !str_contains($response->getContent(), 'server-decryption-never-export'), 'Stash excludes server secrets');
        check($response->headers->get('subscription-userinfo') === 'upload=17; download=29; total=1073741824; expire=2000000000', 'Stash retains traffic metadata');
    }
}

foreach (['Stash/3.6.4', 'Stash', 'Stash/3.6.5-beta', 'OtherApp/3.6.5 Stash', 'Stash/3.6.5 (macOS)', 'Stash/4.3.0 macOS/27.2'] as $ua) {
    $parsed = Yaml::parse(iosSubscription($ua, $all)->getContent());
    check(array_column($parsed['proxies'], 'name') === ['legacy'], 'unverified Stash version/platform keeps legacy only');
}

foreach (['Quantumult%20X/1.8.1', 'Quantumult%20X/1.8.1 (iPhone17,1; iOS 27.2)', 'Quantumult X/1.8.1 (iOS)', 'Quantumult-X/1.8.1', 'QuantumultX/1.8.1', 'Quantumult X/1.8.1 Stash/3.6.5', 'Quantumult%20X/1.8.2'] as $ua) {
    foreach ([null, 'quantumult%20x'] as $flag) {
        $response = iosSubscription($ua, $all, $flag);
        $body = base64_decode($response->getContent(), true);
        $lines = array_values(array_filter(explode("\r\n", $body)));
        check(count($lines) === 2, 'verified Quantumult X receives modern and legacy native nodes');
        check(str_contains($lines[0], 'tag=modern') && str_contains($lines[1], 'tag=legacy'), 'Quantumult X preserves native node membership');
        check(str_contains($lines[0], 'reality-base64-pubkey=' . $settings['reality_settings']['public_key']), 'Quantumult X retains REALITY public key');
        check(str_contains($lines[0], 'vless-flow=xtls-rprx-vision') && str_contains($lines[0], 'fast-open=false'), 'Quantumult X retains verified Vision parameters');
        check(!str_contains($body, 'support-x25519mlkem768') && !str_contains($body, $private), 'Quantumult X excludes foreign fields and secrets');
    }
}
foreach (['Quantumult X/1.8.0', 'Quantumult%20X', 'QuantumultX/1.8.1-beta', 'OtherApp/1.8.1 QuantumultX', 'QuantumultX/1.8.1 macOS/27.2'] as $ua) {
    $body = base64_decode(iosSubscription($ua, $all)->getContent(), true);
    check(substr_count($body, 'vless=') === 1 && str_contains($body, 'tag=legacy'), 'unverified Quantumult X receives legacy only');
}

$karingUa = 'Karing/1.2.25.2802 platform/ios;mihomo/1.19.28;clash-verge;FLClash;mihomo.party/v2.0.0 (clash.meta);ClashMeta;v2ray;sing-box 1.13.0;NekoBox/Android/1.4.1 (Prefer ClashMeta Format);HiddifyNext';
$karing = iosSubscription($karingUa, $all);
$parsed = json_decode($karing->getContent(), true, 512, JSON_THROW_ON_ERROR);
$serverNodes = array_values(array_filter($parsed['outbounds'], fn($item) => isset($item['server'])));
check(array_column($serverNodes, 'tag') === ['legacy'], 'Karing composite UA keeps actual identity and only compatible nodes');
check($karing->getStatusCode() === 200, 'Karing mixed subscription keeps usable nodes');

$empty = iosSubscription($karingUa, [$modern]);
check($empty->getStatusCode() === 422, 'Karing all-modern subscription returns explicit compatibility error');
$error = json_decode($empty->getContent(), true, 512, JSON_THROW_ON_ERROR);
check(($error['code'] ?? '') === 'NO_COMPATIBLE_NODES', 'empty subscription has stable machine-readable error');
check(str_contains($error['message'] ?? '', 'Karing') && str_contains($error['message'] ?? '', 'REALITY'), 'Karing error explains REALITY limitation');
check(!str_contains($empty->getContent(), 'fixture-token-never-log') && !str_contains($empty->getContent(), $private) && !str_contains($empty->getContent(), $modern['password']), 'empty error excludes token, UUID and secrets');
check($empty->headers->get('subscription-userinfo') !== null, 'empty compatibility error retains subscription metadata');
check($empty->headers->get('X-Subscription-Filtered-Nodes') === '1', 'empty error reports filtered node count');
check(($error['diagnostics']['hybrid_reality_rejections'] ?? 0) === 1, 'Karing error identifies modern REALITY rejection without node data');

$unsupportedVersion = iosSubscription('Stash/3.0.0', [$legacy]);
check($unsupportedVersion->getStatusCode() === 422, 'all nodes rejected by version return compatibility error');
$unsupportedProtocol = $legacy;
$unsupportedProtocol['type'] = 'hysteria';
check(iosSubscription('Quantumult X/1.8.1', [$unsupportedProtocol])->getStatusCode() === 422, 'all nodes rejected by renderer type return compatibility error');
check(iosSubscription($karingUa, [$modern], 'meta')->getStatusCode() === 422, 'format override alone does not declare Karing core support');
check(iosSubscription('Quantumult X/1.8.1 macOS/27.2', [$modern], 'quantumultx/1.8.1')->getStatusCode() === 422, 'versioned format flag does not erase actual macOS platform');

foreach (['ws', 'grpc'] as $network) {
    $transport = $modern;
    $transport['protocol_settings']['network'] = $network;
    $transport['protocol_settings']['flow'] = '';
    foreach (['Stash/3.6.5', 'Quantumult X/1.8.1'] as $ua) {
        check(iosSubscription($ua, [$transport])->getStatusCode() === 422, 'unverified modern REALITY transport remains filtered');
    }
}

$registeredModern = $modern;
unset($registeredModern['protocol_settings']['reality_settings']['support_x25519mlkem768']);
$registeredModern['protocol_settings']['backend'] = ['core' => 'xray', 'release' => 'core-v1.1.0'];
$registered = Yaml::parse(iosSubscription('Stash/3.6.5', [$registeredModern])->getContent());
check(($registered['proxies'][0]['reality-opts']['support-x25519mlkem768'] ?? false) === true, 'registered modern backend also emits hybrid KeyShare');
$customLegacy = $legacy;
$customLegacy['protocol_settings']['utls'] = ['enabled' => true, 'fingerprint' => 'safari'];
$custom = Yaml::parse(iosSubscription('Stash/3.6.5', [$customLegacy])->getContent());
check($custom['proxies'][0]['client-fingerprint'] === 'safari', 'Stash preserves explicitly configured legacy fingerprint');

$afterError = iosSubscription('Stash/3.6.5', [$modern], null, true);
check($afterError->getStatusCode() === 200 && count(Yaml::parse($afterError->getContent())['proxies']) === 1, 'subsequent request has no leaked error state');
check($afterError->headers->get('X-Subscription-Filtered-Nodes') === '0', 'same-container subsequent request has independent diagnostics');
$noSource = iosSubscription($karingUa, []);
check($noSource->getStatusCode() === 200, 'no source nodes is distinct from compatibility rejection');

$app = Facade::getFacadeApplication();
$identify = new ReflectionMethod(ClientController::class, 'getClientInfo');
foreach (['Quantumult%20X/1.8.1', 'Quantumult X/1.8.1', 'Quantumult-X/1.8.1'] as $ua) {
    $request = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $ua]);
    $identity = $identify->invoke(new ClientController(), $request);
    check($identity['name'] === 'quantumultx' && $identity['version'] === '1.8.1', 'Quantumult X aliases normalize actual identity');
}
$identity = $identify->invoke(new ClientController(), Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $karingUa]));
check($identity['name'] === 'karing' && $identity['version'] === '1.2.25.2802', 'Karing preserves actual four-part version');

echo "iOS REALITY actual subscription pipeline: {$checks} checks passed\n";
