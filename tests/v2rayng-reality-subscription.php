<?php
declare(strict_types=1);

function admin_setting($key = null, $default = null) {
    return $GLOBALS['v2rayng_subscription_settings'][$key] ?? $default;
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

function v2rayngSubscription(string $ua, array $nodes, ?string $flag = null, bool $reuseContainer = false): Response {
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
    $GLOBALS['v2rayng_subscription_settings'] = ['app_name' => 'v2rayNG fixture'];
    $request = Request::create('https://subscription.example.test/s/private-fixture-token', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
    $app->instance('request', $request);
    $user = (new User())->forceFill(['uuid' => '11111111-1111-4111-8111-111111111111', 'u' => 17, 'd' => 29]);
    return (new ClientController())->doSubscribe($request, $user, $nodes);
}

if (($argv[1] ?? '') === '--render') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $response = v2rayngSubscription($input['ua'], $input['nodes'], $input['flag'] ?? null);
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
$xhttp['protocol_settings']['network_settings'] = ['path' => '/fixture', 'mode' => 'auto'];

foreach (['v2rayNG/2.2.2', 'v2rayNG/2.2.3 (Android)', 'v2rayNG/2.3.0 platform/android'] as $ua) {
    foreach ([null, 'v2rayng', 'general'] as $flag) {
        $response = v2rayngSubscription($ua, [$modern, $encrypted, $xhttp, $legacy], $flag);
        check($response->getStatusCode() === 200, 'v2rayNG modern subscription succeeds');
        $body = base64_decode($response->getContent(), true);
        $lines = array_values(array_filter(explode("\r\n", (string) $body)));
        check(count($lines) === 2, 'v2rayNG keeps ordinary modern and legacy nodes only');
        parse_str(parse_url($lines[0], PHP_URL_QUERY), $params);
        check($params['security'] === 'reality' && $params['type'] === 'tcp' && $params['flow'] === 'xtls-rprx-vision', 'native URI retains transport, REALITY and Vision');
        check($params['fp'] === 'chrome' && $params['pbk'] === 'fixture-public-key' && $params['sid'] === '0123456789abcdef' && $params['sni'] === 'fixture.test', 'native URI retains deterministic Chrome and authentication parameters');
        check($params['encryption'] === 'none' && parse_url($lines[0], PHP_URL_USER) === $modern['password'], 'native URI retains client identity without enhanced encryption');
        check(parse_url($lines[0], PHP_URL_HOST) === '[2001:db8::1]' && rawurldecode(parse_url($lines[0], PHP_URL_FRAGMENT)) === $modern['name'], 'native URI preserves IPv6 and encoded display name');
        check(!str_contains($body, 'private-key-never-export') && !str_contains($body, 'server-decryption-never-export') && !str_contains($body, 'backend'), 'native output excludes server secrets and metadata');
        check($response->headers->get('subscription-userinfo') === 'upload=17; download=29; total=1073741824; expire=2000000000', 'traffic metadata remains available');
        check($response->headers->get('X-Subscription-Compatible-Nodes') === '2' && $response->headers->get('X-Subscription-Filtered-Nodes') === '2', 'filter diagnostics reflect only unsupported enhanced nodes');
    }
}

foreach (['v2rayNG/2.2.1', 'v2rayNG', 'v2rayNG/2.2.2-beta', 'v2rayNG/2.2.2.1', 'v2rayNG/3.0.0', 'Other/2.2.2 v2rayNG', 'v2rayN/2.2.2', 'NekoBox/Android/1.4.2 (Prefer ClashMeta Format)', 'HiddifyNext/4.1.1 (android) like ClashMeta v2ray sing-box', 'v2rayNG/2.2.2 (macOS)', 'v2rayNG/2.2.2 (Windows)', 'v2rayNG/2.2.2 platform/linux'] as $ua) {
    $response = v2rayngSubscription($ua, [$modern], 'v2rayng');
    check($response->getStatusCode() === 422, 'unverified identity/version/platform stays filtered: ' . $ua);
    $error = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    check($error['code'] === 'NO_COMPATIBLE_NODES' && $error['diagnostics']['hybrid_reality_rejections'] === 1, 'unsupported modern node retains precise error diagnostics');
    check(!str_contains($response->getContent(), $modern['password']) && !str_contains($response->getContent(), 'private-fixture-token'), 'error never contains subscription credentials');
}

foreach ([$encrypted, $xhttp] as $node) {
    check(v2rayngSubscription('v2rayNG/2.2.2', [$node])->getStatusCode() === 422, 'v2rayNG is not opted into enhanced capabilities');
}
foreach (['ws', 'grpc', 'httpupgrade'] as $transport) {
    $node = $modern;
    $node['protocol_settings']['network'] = $transport;
    $node['protocol_settings']['flow'] = '';
    check(v2rayngSubscription('v2rayNG/2.2.2', [$node])->getStatusCode() === 422, 'unverified modern transport stays filtered');
}
$http = $modern;
$http['protocol_settings']['network_settings']['header']['type'] = 'http';
check(v2rayngSubscription('v2rayNG/2.2.2', [$http])->getStatusCode() === 422, 'unverified TCP HTTP camouflage stays filtered');
check(!VlessConfigService::supportsClient($settings, 'ClashMeta', 'v2rayng', '2.2.2'), 'changing format cannot opt v2rayNG into a foreign renderer');
$registered = $modern;
unset($registered['protocol_settings']['reality_settings']['support_x25519mlkem768']);
$registered['protocol_settings']['backend'] = ['core' => 'xray', 'release' => 'core-v1.1.0'];
check(v2rayngSubscription('v2rayNG/2.2.2', [$registered])->getStatusCode() === 200, 'registered modern backend receives the same compatibility policy');
check(v2rayngSubscription('v2rayNG/1.8.0', [$legacy])->getStatusCode() === 200, 'old v2rayNG keeps legacy REALITY');

$eighteen = [];
for ($i = 1; $i <= 18; $i++) {
    $node = $modern;
    $node['name'] = 'node-' . $i;
    $eighteen[] = $node;
}
foreach ([false, true, true] as $reuse) {
    $response = v2rayngSubscription('v2rayNG/2.2.2', $eighteen, null, $reuse);
    check($response->getStatusCode() === 200 && substr_count(base64_decode($response->getContent(), true), 'vless://') === 18, 'initial import and consecutive refreshes retain all 18 modern nodes');
    check($response->headers->get('X-Subscription-Filtered-Nodes') === '0', 'refresh diagnostics remain independent of previous errors');
}
echo "v2rayNG REALITY actual subscription pipeline: {$checks} checks passed\n";
