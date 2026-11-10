<?php
declare(strict_types=1);

function admin_setting($key = null, $default = null) {
    return $GLOBALS['v2rayn_subscription_settings'][$key] ?? $default;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Controllers\V1\Client\ClientController;
use App\Models\User;
use App\Services\UserService;
use App\Services\VlessConfigService;
use App\Support\ProtocolManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function v2raynSubscription(string $ua, array $nodes, ?string $flag = null, bool $reuseContainer = false): Response {
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
    $GLOBALS['v2rayn_subscription_settings'] = ['app_name' => 'v2rayN fixture'];
    $request = Request::create('https://subscription.example.test/s/private-fixture-token', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
    $app->instance('request', $request);
    $user = (new User())->forceFill(['uuid' => '11111111-1111-4111-8111-111111111111', 'u' => 17, 'd' => 29]);
    return (new ClientController())->doSubscribe($request, $user, $nodes);
}

if (($argv[1] ?? '') === '--render') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $response = v2raynSubscription($input['ua'], $input['nodes'], $input['flag'] ?? null);
    echo json_encode(['status' => $response->getStatusCode(), 'headers' => $response->headers->all(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
    exit;
}

$settings = [
    'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
    'network_settings' => ['header' => ['type' => 'none']],
    'reality_settings' => ['server_name' => 'fixture.test', 'public_key' => 'fixture-public-key', 'private_key' => 'private-key-never-export', 'short_id' => '0123456789abcdef', 'support_x25519mlkem768' => true],
];
$modern = ['name' => '东京 # & 03', 'type' => 'vless', 'host' => '2001:db8::1', 'port' => 3001, 'password' => '11111111-1111-4111-8111-111111111111', 'protocol_settings' => $settings];
$legacy = $modern;
$legacy['name'] = 'legacy';
$legacy['protocol_settings']['reality_settings']['support_x25519mlkem768'] = false;
$encrypted = $modern;
$encrypted['protocol_settings']['encryption'] = ['enabled' => true, 'encryption' => 'fixture-encryption', 'decryption' => 'server-decryption-never-export'];
$xhttp = $modern;
$xhttp['protocol_settings']['network'] = 'xhttp';
$xhttp['protocol_settings']['flow'] = '';

foreach (['v2rayN/7.25.6', 'v2rayN/7.25.7 (Windows)', 'v2rayN/7.25.6 Platform/macos', 'v2rayN/7.25.6 Platform/linux'] as $ua) {
    foreach ([null, 'general', 'v2rayn'] as $flag) {
        $response = v2raynSubscription($ua, [$modern, $encrypted, $xhttp, $legacy], $flag);
        check($response->getStatusCode() === 200, 'v2rayN modern subscription succeeds');
        $body = base64_decode($response->getContent(), true);
        $links = array_values(array_filter(explode("\r\n", (string) $body)));
        check(count($links) === 2, 'v2rayN receives ordinary modern and legacy nodes only');
        parse_str(parse_url($links[0], PHP_URL_QUERY), $params);
        check($params['security'] === 'reality' && $params['type'] === 'tcp' && $params['flow'] === 'xtls-rprx-vision', 'native URI retains REALITY, TCP and Vision');
        check($params['fp'] === 'chrome' && $params['sni'] === 'fixture.test' && $params['pbk'] === 'fixture-public-key' && $params['sid'] === '0123456789abcdef', 'native URI retains Chrome and REALITY authentication');
        check($params['encryption'] === 'none' && parse_url($links[0], PHP_URL_USER) === $modern['password'], 'native URI retains UUID and ordinary VLESS');
        check(parse_url($links[0], PHP_URL_HOST) === '[2001:db8::1]' && rawurldecode(parse_url($links[0], PHP_URL_FRAGMENT)) === $modern['name'], 'native URI preserves IPv6 and display name');
        check(!str_contains($body, 'private-key-never-export') && !str_contains($body, 'server-decryption-never-export') && !str_contains($body, 'backend'), 'native subscription excludes server secrets');
        check($response->headers->get('subscription-userinfo') === 'upload=17; download=29; total=1073741824; expire=2000000000', 'v2rayN receives traffic metadata');
        check($response->headers->get('X-Subscription-Compatible-Nodes') === '2' && $response->headers->get('X-Subscription-Filtered-Nodes') === '2', 'v2rayN receives accurate compatibility counts');
    }
    check(v2raynSubscription($ua, [$modern])->getStatusCode() === 200, 'all-modern subscription no longer returns 422');
}
foreach (['v2rayN/7.25.5', 'v2rayN', 'v2rayN/7.25.6-beta', 'v2rayN/7.25.6.1', 'Other/7.25.6 v2rayN', 'v2rayNG/7.25.6', 'v2rayN/7.25.6 (Android)', 'v2rayN/7.25.6 (iOS)'] as $ua) {
    check(v2raynSubscription($ua, [$modern])->getStatusCode() === 422, 'unverified product/version/platform stays filtered: ' . $ua);
}
foreach (['v2rayN/7.25.5', 'Other/7.25.6', ''] as $ua) {
    check(v2raynSubscription($ua, [$modern], 'v2rayn/7.25.6')->getStatusCode() === 422, 'versioned format flag cannot grant v2rayN capabilities');
}
foreach ([$encrypted, $xhttp] as $node) {
    check(v2raynSubscription('v2rayN/7.25.6', [$node])->getStatusCode() === 422, 'v2rayN enhanced features need separate verification');
}
foreach (['ws', 'grpc', 'httpupgrade'] as $transport) {
    $node = $modern;
    $node['protocol_settings']['network'] = $transport;
    $node['protocol_settings']['flow'] = '';
    check(v2raynSubscription('v2rayN/7.25.6', [$node])->getStatusCode() === 422, 'unverified modern transport stays filtered');
}
$http = $modern;
$http['protocol_settings']['network_settings']['header']['type'] = 'http';
check(v2raynSubscription('v2rayN/7.25.6', [$http])->getStatusCode() === 422, 'TCP HTTP camouflage stays filtered');
check(!VlessConfigService::supportsClient($settings, 'ClashMeta', 'v2rayn', '7.25.6'), 'format change cannot grant a foreign renderer');
$registered = $modern;
unset($registered['protocol_settings']['reality_settings']['support_x25519mlkem768']);
$registered['protocol_settings']['backend'] = ['core' => 'xray', 'release' => 'core-v1.1.0'];
check(v2raynSubscription('v2rayN/7.25.6', [$registered])->getStatusCode() === 200, 'backend-inferred hybrid nodes use the same policy');
check(v2raynSubscription('v2rayN/7.24.4', [$legacy])->getStatusCode() === 200, 'older v2rayN keeps legacy REALITY');

echo "v2rayN REALITY actual subscription pipeline: {$checks} checks passed\n";
