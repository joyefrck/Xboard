<?php
declare(strict_types=1);

function admin_setting($key = null, $default = null) {
    return $GLOBALS['elephant_settings'][$key] ?? $default;
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Controllers\V1\Client\ClientController;
use App\Models\User;
use App\Services\UserService;
use App\Support\ProtocolManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Yaml\Yaml;

function elephantSubscription(string $ua, array $nodes, array $template, ?string $flag = 'meta'): Illuminate\Http\Response {
    $app = new Application(dirname(__DIR__));
    Facade::setFacadeApplication($app);
    $app->instance('files', new Illuminate\Filesystem\Filesystem());
    $app->instance('hook.filters', []);
    $manager = new ProtocolManager($app);
    $manager->registerAllProtocols();
    $app->instance('protocols.manager', $manager);
    $app->instance('protocols.flags', $manager->getAllFlags());
    $app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class {
        public function make($content = '', $status = 200, array $headers = []): Illuminate\Http\Response {
            return new Illuminate\Http\Response($content, $status, $headers);
        }
    });
    $app->instance(UserService::class, new class extends UserService {
        public function getTrafficSummary(User $user): array {
            return ['effective_transfer_enable' => 1073741824, 'effective_expired_at' => 2000000000];
        }
    });
    $GLOBALS['elephant_settings'] = ['subscribe_template_clashmeta' => Yaml::dump($template, 10), 'app_name' => 'Elephant fixture'];
    $request = Request::create('http://subscription.example.test/subscribe', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
    $app->instance('request', $request);
    $user = (new User())->forceFill(['uuid' => '11111111-1111-4111-8111-111111111111', 'u' => 17, 'd' => 29, 'transfer_enable' => 1073741824, 'expired_at' => 2000000000]);
    return (new ClientController())->doSubscribe($request, $user, $nodes);
}

if (($argv[1] ?? '') === '--render') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $response = elephantSubscription($input['ua'], $input['nodes'], $input['template'], $input['flag'] ?? 'meta');
    echo $response->getContent();
    exit;
}

$private = random_bytes(32);
$encode = fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$settings = [
    'tls' => 2, 'network' => 'xhttp', 'flow' => '',
    'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
    'backend' => ['core' => 'xray', 'release' => 'core-v1.1.0'],
    'reality_settings' => ['private_key' => $encode($private), 'public_key' => $encode(sodium_crypto_scalarmult_base($private)), 'server_name' => 'fixture.test', 'short_id' => '0123456789abcdef'],
    'encryption' => ['enabled' => true, 'encryption' => 'mlkem768x25519plus.native.0rtt.' . $encode(sodium_crypto_scalarmult_base($private)), 'decryption' => 'mlkem768x25519plus.native.600s.' . $encode($private)],
    'network_settings' => ['path' => '/fixture', 'mode' => 'auto', 'extra' => ['xPaddingBytes' => '100-200']],
];
$node = ['name' => 'enhanced', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 443, 'password' => '11111111-1111-4111-8111-111111111111', 'protocol_settings' => $settings];
$legacy = $node;
$legacy['name'] = 'legacy';
$legacy['protocol_settings'] = ['tls' => 0, 'network' => 'tcp', 'flow' => ''];
$template = ['proxies' => [], 'proxy-groups' => [['name' => 'select', 'type' => 'select', 'proxies' => []]], 'rules' => ['MATCH,select']];
$checks = 0;
foreach ([
    'Elephant Network/v2.1.0 clash-verge Platform/macos',
    'Elephant Network/v2.1.0 clash-verge Platform/windows',
    'Elephant Network/v2.1.0 clash-verge Platform/android',
    'Elephant Network/v2.1.0 clash-verge Platform/linux',
] as $ua) {
    foreach (['meta', null] as $flag) {
        $response = elephantSubscription($ua, [$node, $legacy], $template, $flag);
        $yaml = $response->getContent();
        $parsed = Yaml::parse($yaml);
        $proxy = $parsed['proxies'][0];
        if (count($parsed['proxies']) !== 2 || $proxy['encryption'] !== $settings['encryption']['encryption'] || $proxy['xhttp-opts']['x-padding-bytes'] !== '100-200' || $proxy['client-fingerprint'] !== 'chrome' || $proxy['reality-opts']['support-x25519mlkem768'] !== true) throw new RuntimeException('Released Elephant must receive complete enhanced subscription');
        if (str_contains($yaml, $settings['encryption']['decryption']) || str_contains($yaml, $settings['reality_settings']['private_key'])) throw new RuntimeException('Server secrets appeared in subscription');
        if ($parsed['rules'][0] !== 'DOMAIN,subscription.example.test,DIRECT' || $parsed['proxy-groups'][0]['proxies'] !== ['enhanced', 'legacy']) throw new RuntimeException('Subscription routing and group membership changed');
        if ($response->headers->get('subscription-userinfo') !== 'upload=17; download=29; total=1073741824; expire=2000000000') throw new RuntimeException('Subscription traffic metadata changed');
        $checks++;
    }
}
foreach (['Elephant Network/v2.0.7 clash-verge Platform/macos', 'clash-verge/v2.1.0 Platform/windows', 'unknown/2.1.0'] as $ua) {
    $response = elephantSubscription($ua, [$node, $legacy], $template);
    $parsed = Yaml::parse($response->getContent());
    if (array_column($parsed['proxies'], 'name') !== ['legacy']) throw new RuntimeException('Unverified client received enhanced node');
    $checks++;
}
echo "Elephant actual subscription pipeline: {$checks} cases passed\n";
