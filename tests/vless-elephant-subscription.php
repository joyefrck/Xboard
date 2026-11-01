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

$modern = $node;
$modern['name'] = 'modern-reality';
$modern['protocol_settings']['network'] = 'tcp';
$modern['protocol_settings']['flow'] = 'xtls-rprx-vision';
unset($modern['protocol_settings']['encryption'], $modern['protocol_settings']['network_settings']);
$encrypted = $modern;
$encrypted['name'] = 'encryption-only';
$encrypted['protocol_settings']['encryption'] = $settings['encryption'];
$xhttp = $modern;
$xhttp['name'] = 'xhttp-only';
$xhttp['protocol_settings']['network'] = 'xhttp';
$xhttp['protocol_settings']['flow'] = '';
$xhttp['protocol_settings']['network_settings'] = $settings['network_settings'];
$thirdPartyNodes = [$modern, $node, $encrypted, $xhttp, $legacy];
foreach ([
    ['clash-verge/v2.5.8', 'meta'],
    ['clash-verge/v2.5.8', null],
    ['verge/2.5.8', 'meta'],
    ['mihomo/1.19.32', 'meta'],
] as [$ua, $flag]) {
    $parsed = Yaml::parse(elephantSubscription($ua, $thirdPartyNodes, $template, $flag)->getContent());
    if (array_column($parsed['proxies'], 'name') !== ['modern-reality', 'legacy']) throw new RuntimeException('Verified client must receive ordinary modern REALITY without enhanced nodes: ' . $ua);
    if ($parsed['proxies'][0]['reality-opts']['support-x25519mlkem768'] !== true) throw new RuntimeException('Required hybrid KeyShare flag was lost');
    if ($parsed['proxy-groups'][0]['proxies'] !== ['modern-reality', 'legacy']) throw new RuntimeException('Filtered proxy group membership is incorrect');
    $checks++;
}
foreach (['clash-verge/v2.5.7', 'clash-verge', 'mihomo/1.19.31', 'unknown/2.5.8'] as $ua) {
    $parsed = Yaml::parse(elephantSubscription($ua, $thirdPartyNodes, $template)->getContent());
    if (array_column($parsed['proxies'], 'name') !== ['legacy']) throw new RuntimeException('Unverified client received modern REALITY: ' . $ua);
    $checks++;
}
foreach (['Shadowrocket/3488', 'Shadowrocket/2.2.93'] as $ua) {
    foreach (['shadowrocket', null] as $flag) {
        $response = elephantSubscription($ua, $thirdPartyNodes, $template, $flag);
        $body = base64_decode($response->getContent(), true);
        $lines = array_values(array_filter(explode("\n", trim($body)), fn($line) => str_starts_with($line, 'vless://')));
        if (count($lines) !== 2) throw new RuntimeException('Shadowrocket must receive modern REALITY and legacy nodes only');
        parse_str(parse_url(trim($lines[0]), PHP_URL_QUERY), $params);
        if ($params['remark'] !== 'modern-reality' || $params['fingerprint'] !== 'Chrome140' || $params['fp'] !== 'chrome' || $params['pbk'] !== $modern['protocol_settings']['reality_settings']['public_key'] || $params['sid'] !== '0123456789abcdef' || $params['xtls'] !== '2') throw new RuntimeException('Shadowrocket modern REALITY URI parameters are incomplete');
        if (str_contains($body, $settings['reality_settings']['private_key']) || str_contains($body, $settings['encryption']['decryption'])) throw new RuntimeException('Shadowrocket output leaked server material');
        $checks++;
    }
}
foreach (['Shadowrocket/3487', 'Shadowrocket/2.2.92', 'Shadowrocket', 'unknown/3488'] as $ua) {
    $body = base64_decode(elephantSubscription($ua, $thirdPartyNodes, $template, 'shadowrocket')->getContent(), true);
    $lines = array_filter(explode("\n", trim($body)), fn($line) => str_starts_with($line, 'vless://'));
    if (count($lines) !== 1 || !str_contains($body, 'remark=legacy')) throw new RuntimeException('Unverified Shadowrocket received unsupported nodes');
    $checks++;
}
echo "Third-party actual subscription pipeline: {$checks} cases passed\n";
