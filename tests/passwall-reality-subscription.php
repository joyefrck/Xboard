<?php
declare(strict_types=1);

function admin_setting($key = null, $default = null) {
    return $GLOBALS['passwall_subscription_settings'][$key] ?? $default;
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

function passwallSubscription(string $ua, array $nodes, ?string $flag = null, bool $reuseContainer = false): Response {
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
    $GLOBALS['passwall_subscription_settings'] = ['app_name' => 'Passwall fixture'];
    $request = Request::create('https://subscription.example.test/s/private-fixture-token', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
    $app->instance('request', $request);
    $user = (new User())->forceFill(['uuid' => '11111111-1111-4111-8111-111111111111', 'u' => 17, 'd' => 29]);
    return (new ClientController())->doSubscribe($request, $user, $nodes);
}

if (($argv[1] ?? '') === '--render') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $response = passwallSubscription($input['ua'], $input['nodes'], $input['flag'] ?? null);
    echo json_encode(['status' => $response->getStatusCode(), 'headers' => $response->headers->all(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
    exit;
}

$settings = [
    'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
    'reality_settings' => ['server_name' => 'fixture.test', 'public_key' => 'fixture-public-key', 'private_key' => 'server-private-never-export', 'short_id' => '0123456789abcdef', 'support_x25519mlkem768' => true],
];
$modern = ['name' => '东京 # & 03', 'type' => 'vless', 'host' => '2001:db8::1', 'port' => 3001, 'password' => '11111111-1111-4111-8111-111111111111', 'protocol_settings' => $settings];
$legacy = $modern;
$legacy['protocol_settings']['reality_settings']['support_x25519mlkem768'] = false;
$encrypted = $modern;
$encrypted['protocol_settings']['encryption'] = ['enabled' => true, 'encryption' => 'client-encryption', 'decryption' => 'server-decryption-never-export'];
$xhttp = $modern;
$xhttp['protocol_settings']['network'] = 'xhttp';
$xhttp['protocol_settings']['flow'] = '';
$fixtureNodes = [$modern, $encrypted, $xhttp, $legacy];

foreach (['passwall', 'passwall/26.10.1', 'PassWall/25.12.1-r1', 'passwall2', 'passwall2/26.10.1'] as $ua) {
    foreach ([null, 'general', 'passwall', 'passwall2'] as $flag) {
        $response = passwallSubscription($ua, $fixtureNodes, $flag);
        check($response->getStatusCode() === 200, 'native Passwall subscription succeeds');
        $body = base64_decode($response->getContent(), true);
        $lines = array_values(array_filter(explode("\r\n", (string) $body)));
        check(count($lines) === 2, 'only standard modern and legacy REALITY returned');
        parse_str(parse_url($lines[0], PHP_URL_QUERY), $params);
        check($params['security'] === 'reality' && $params['type'] === 'tcp' && $params['flow'] === 'xtls-rprx-vision', 'standard TCP REALITY Vision URI');
        check($params['fp'] === 'chrome' && $params['pbk'] === 'fixture-public-key' && $params['sid'] === '0123456789abcdef' && $params['sni'] === 'fixture.test', 'Chrome and REALITY authentication survive rendering');
        check($params['encryption'] === 'none', 'enhanced encryption never downgraded');
        check(parse_url($lines[0], PHP_URL_HOST) === '[2001:db8::1]' && rawurldecode(parse_url($lines[0], PHP_URL_FRAGMENT)) === $modern['name'], 'IPv6 and special display names preserved');
        check(!str_contains($body, 'server-private-never-export') && !str_contains($body, 'server-decryption-never-export') && !str_contains($body, 'backend'), 'server secrets excluded');
        check($response->headers->get('X-Subscription-Compatible-Nodes') === '2' && $response->headers->get('X-Subscription-Filtered-Nodes') === '2', 'filter diagnostics accurate');
    }
}
foreach (['curl/8.0', 'Other/26.10.1 passwall2', 'notpasswall/26.10.1', 'passwallish/26.10.1', 'passwall2evil/26.10.1'] as $ua) {
    check(passwallSubscription($ua, [$modern], 'passwall2')->getStatusCode() === 422, 'format or embedded token cannot grant native identity');
}
check(passwallSubscription('', [$modern], 'passwall2')->getStatusCode() === 422, 'empty UA cannot opt in using a format flag');
foreach (['passwall', 'passwall2'] as $ua) {
    $response = passwallSubscription($ua, [$modern]);
    check($response->getStatusCode() === 200 && $response->headers->get('X-Subscription-Compatible-Nodes') === '1', 'all-modern subscription no longer returns 422');
}
foreach ([$encrypted, $xhttp] as $node) {
    check(passwallSubscription('passwall2', [$node])->getStatusCode() === 422, 'enhanced node stays filtered');
}
foreach (['ws', 'grpc', 'httpupgrade', 'kcp'] as $transport) {
    $node = $modern;
    $node['protocol_settings']['network'] = $transport;
    $node['protocol_settings']['flow'] = '';
    check(passwallSubscription('passwall2', [$node])->getStatusCode() === 422, 'unverified modern transport stays filtered');
}
$http = $modern;
$http['protocol_settings']['network_settings']['header']['type'] = 'http';
check(passwallSubscription('passwall', [$http])->getStatusCode() === 422, 'HTTP camouflage stays filtered');
check(!VlessConfigService::supportsClient($settings, 'ClashMeta', 'passwall2', '26.10.1'), 'foreign renderer stays filtered');
$registered = $modern;
unset($registered['protocol_settings']['reality_settings']['support_x25519mlkem768']);
$registered['protocol_settings']['backend'] = ['core' => 'xray', 'release' => 'core-v1.1.0'];
check(passwallSubscription('passwall2', [$registered])->getStatusCode() === 200, 'registered backend gets same policy');
check(passwallSubscription('curl/8.0', [$legacy])->getStatusCode() === 200, 'legacy import unchanged');
foreach ([false, true, true] as $reuse) {
    $response = passwallSubscription('passwall2', array_fill(0, 18, $modern), null, $reuse);
    check($response->getStatusCode() === 200 && substr_count(base64_decode($response->getContent(), true), 'vless://') === 18, '18 nodes retained on import and consecutive refreshes');
    check($response->headers->get('X-Subscription-Filtered-Nodes') === '0', 'refresh diagnostics independent');
}
echo "Passwall REALITY actual subscription pipeline: {$checks} checks passed\n";
