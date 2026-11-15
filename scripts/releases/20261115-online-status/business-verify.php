<?php
declare(strict_types=1);

require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{User, Order, MailLog, TicketMessage, StatUser, StatServer};
use App\Services\{UserService, ServerService, VlessConfigService};
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

try {
    $args = getopt('', ['mode:', 'since:', 'strict']);
    $since = strtotime($args['since'] ?? '-10 minutes');
    if (($args['mode'] ?? '') === 'business') {
        $jobs = [];
        $evidence = [];
        $repo = app(Laravel\Horizon\Contracts\JobRepository::class);
        for ($page = 0; $page < 50; $page++) {
            $rows = $repo->getCompleted($page * 50);
            foreach ($rows as $job) {
                if ((float) ($job->completed_at ?? 0) < $since) continue;
                $name = $job->name ?? 'unknown';
                $jobs[$name] = ($jobs[$name] ?? 0) + 1;
                if (count($evidence) < 200) $evidence[] = ['id' => $job->id, 'name' => $name, 'queue' => $job->queue, 'status' => $job->status, 'completed_at' => $job->completed_at];
            }
            if (count($rows) < 50) break;
        }
        $orders = Order::where('paid_at', '>=', $since)->selectRaw('status, COUNT(*) AS count')->groupBy('status')->pluck('count', 'status');
        $result = [
            'sample_at' => date('c'), 'since' => date('c', $since),
            'registered' => User::where('created_at', '>=', $since)->count(),
            'mail_count' => MailLog::where('created_at', '>=', $since)->count(),
            'mail_errors' => MailLog::where('created_at', '>=', $since)->whereNotNull('error')->where('error', '!=', '')->count(),
            'paid_order_statuses' => $orders,
            'ticket_messages' => TicketMessage::where('created_at', '>=', $since)->count(),
            'latest_update_age_seconds' => ['user_stat' => time() - (int) StatUser::max('updated_at'), 'server_stat' => time() - (int) StatServer::max('updated_at')],
            'latest_node_push_age_seconds' => time() - (int) User::max('t'),
            'completed_jobs' => $jobs, 'completed_job_evidence' => $evidence,
        ];
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit;
    }

    $user = User::find(30745);
    if (!$user || !(new UserService())->isAvailable($user)) throw new RuntimeException('acceptance_account_unavailable');
    $servers = ServerService::getAvailableServers($user);
    $expectedModern = count(array_filter($servers, fn($s) => ($s['type'] ?? '') === 'vless'
        && VlessConfigService::needsHybridReality($s['protocol_settings'])
        && !VlessConfigService::isEnhanced($s['protocol_settings'])
        && ($s['protocol_settings']['network'] ?? 'tcp') === 'tcp'
        && data_get($s, 'protocol_settings.network_settings.header.type', 'none') === 'none'));
    if ($expectedModern < 1) throw new RuntimeException('no_acceptance_nodes');
    $path = route('client.subscribe', ['token' => $user->token], false);
    $clients = [
        'flclash' => 'FlClash/v0.9.3 clash-verge Platform/macos',
        'hiddify' => 'HiddifyNext/5.5.5 (macos) like ClashMeta v2ray sing-box',
        'v2rayn' => 'v2rayN/7.25.6',
        'elephant' => 'Elephant Network/v2.1.0 clash-verge Platform/macos',
    ];
    $http = new GuzzleHttp\Client(['http_errors' => false, 'timeout' => 30, 'allow_redirects' => false]);
    $results = [];
    foreach (['internal' => 'http://index-web-1:7001', 'public' => 'https://www.elephant111.org'] as $target => $base) {
        foreach ($clients as $name => $ua) {
            $response = $http->get($base . $path, ['headers' => ['User-Agent' => $ua, 'Host' => 'www.elephant111.org']]);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $vless = 0;
            $hybrid = 0;
            if ($status === 200) {
                if ($name === 'v2rayn') {
                    foreach (explode("\n", (string) base64_decode($body, true)) as $line) {
                        if (!str_starts_with($line, 'vless://')) continue;
                        $vless++;
                        parse_str(parse_url(trim($line), PHP_URL_QUERY) ?? '', $query);
                        if (($query['security'] ?? '') === 'reality' && ($query['fp'] ?? '') === 'chrome'
                            && isset($query['pbk'], $query['sid'], $query['sni'])) $hybrid++;
                    }
                } else {
                    $config = $name === 'hiddify' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : Yaml::parse($body);
                    foreach ($config[$name === 'hiddify' ? 'outbounds' : 'proxies'] ?? [] as $node) {
                        if (($node['type'] ?? '') !== 'vless') continue;
                        $vless++;
                        if ($name === 'hiddify') {
                            if (($node['tls']['reality']['key_share'] ?? '') === 'hybrid' && ($node['tls']['utls']['fingerprint'] ?? '') === 'chrome') $hybrid++;
                        } elseif (($node['reality-opts']['support-x25519mlkem768'] ?? false) && ($node['client-fingerprint'] ?? '') === 'chrome') {
                            $hybrid++;
                        }
                    }
                }
            }
            $passed = $status === 200 && $hybrid >= $expectedModern;
            $results[] = ['target' => $target, 'client' => $name, 'status' => $status, 'compatible' => $response->getHeaderLine('X-Subscription-Compatible-Nodes'), 'vless_nodes' => $vless, 'hybrid_nodes' => $hybrid, 'userinfo_present' => $response->hasHeader('subscription-userinfo'), 'passed' => $passed];
        }
    }
    $allPass = !in_array(false, array_column($results, 'passed'), true);
    echo json_encode(['sample_at' => date('c'), 'expected_modern_nodes' => $expectedModern, 'results' => $results, 'all_pass' => $allPass], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(isset($args['strict']) && !$allPass ? 1 : 0);
} catch (Throwable $error) {
    echo json_encode(['all_pass' => false, 'error_class' => get_class($error), 'source' => basename($error->getFile()), 'line' => $error->getLine()]) . "\n";
    exit(2);
}
