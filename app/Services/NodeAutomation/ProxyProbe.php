<?php

namespace App\Services\NodeAutomation;

use App\Models\{NodeAutomationGroup, NodeAutomationSetting, Server, User, UserTrafficPackage};
use App\Services\ServerService;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Symfony\Component\Process\Process;

class ProxyProbe
{
    public function nodes(NodeAutomationGroup $group): array
    {
        $nodes = Server::all()->filter(fn ($node) => strtolower(rtrim($node->host, '.')) === $group->domain)->values();
        if ($nodes->isEmpty()) throw new AutomationException('该域名已没有关联节点');
        if ($nodes->count() > 16) throw new AutomationException('单个域名最多支持 16 个关联节点');
        return $nodes->all();
    }

    public function account(int $groupId): User
    {
        $id = NodeAutomationSetting::find(1)?->probe_users_by_group[$groupId] ?? null;
        if (!$id) throw new AutomationException('请先为权限组 ' . $groupId . ' 配置公共检测账号');
        $user = User::find($id);
        if ($user && (int) $user->group_id !== $groupId) {
            throw new AutomationException('公共检测账号不属于权限组 ' . $groupId);
        }
        if (!$user || $user->banned || $user->custom_pending_order_id || !$user->uuid) {
            throw new AutomationException('公共检测账号无效、过期或无可用流量');
        }
        // Independent packages grant access even when the periodic plan has expired.
        $hasPlanBalance = ($user->expired_at === null || $user->expired_at > time())
            && $user->getRemainingTraffic() > 0;
        $hasPackageBalance = UserTrafficPackage::where('user_id', $user->id)
            ->where('status', UserTrafficPackage::STATUS_ACTIVE)->where('remaining_bytes', '>', 0)->exists();
        if (!$hasPlanBalance && !$hasPackageBalance) {
            throw new AutomationException('公共检测账号无效、过期或无可用流量');
        }
        return $user;
    }

    public function user(NodeAutomationGroup $group, Server $node): User
    {
        $groupIds = array_unique(array_map('intval', $node->group_ids ?? []));
        sort($groupIds, SORT_NUMERIC);
        $available = null;
        foreach ($groupIds as $groupId) {
            try { $user = $this->account($groupId); }
            catch (AutomationException $e) { continue; }
            $available ??= ServerService::getAvailableUsers($node);
            if ($available->contains('id', $user->id)) return $user;
        }
        throw new AutomationException('节点 ' . ($node->code ?: $node->id) .
            ' 没有可用的公共检测账号，请检查权限组 ' . implode('、', $groupIds) . ' 的账号配置、有效期、流量和节点使用权限');
    }

    public function fingerprint(NodeAutomationGroup $group): string
    {
        $values = [];
        foreach ($this->nodes($group) as $node) {
            $user = $this->user($group, $node);
            $values[] = [$node->id, $node->host, $node->port, $node->parent_id, $node->protocol_settings,
                $node->type, $node->generateServerPassword($user), $user->id];
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function readiness(): void
    {
        $binary = config('node_automation.binary');
        $timeout = config('node_automation.timeout_binary');
        if (!is_executable($binary) || !is_executable($timeout) || !extension_loaded('curl')) {
            throw new AutomationException('探针未安装：需要 sing-box 1.14.2、timeout 和 PHP curl');
        }
        $version = new Process([$binary, 'version'], timeout: 3);
        $version->run();
        if (!$version->isSuccessful() || !preg_match('/sing-box version 1\.14\.2(?:\s|$)/', $version->getOutput())) {
            throw new AutomationException('探针版本必须为 sing-box 1.14.2');
        }
    }

    public function dnsMatches(string $domain, string $ip): bool
    {
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA);
        if (!$records) return false;
        $addresses = [];
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'AAAA') return false;
            if (isset($record['ip'])) $addresses[] = $record['ip'];
        }
        return array_values(array_unique($addresses)) === [$ip];
    }

    /** Only healthy independent targets participate in this round. */
    public function targets(): array
    {
        $urls = config('node_automation.targets');
        $results = $this->requests(array_map(fn ($url) => ['url' => $url, 'proxy' => ''], $urls));
        $healthy = [];
        foreach ($results as $i => $ok) if ($ok) $healthy[] = $urls[$i];
        if (!$healthy) throw new AutomationException('检测目标直连不可用，本轮不计节点故障');
        return $healthy;
    }

    protected function client(): Client
    {
        return new Client(['timeout' => config('node_automation.request_timeout', 8), 'connect_timeout' => 3,
            'allow_redirects' => false, 'http_errors' => false, 'verify' => true, 'headers' => ['Cache-Control' => 'no-cache']]);
    }

    protected function requests(array $requests): array
    {
        $client = $this->client();
        $promises = [];
        foreach ($requests as $request) {
            $promises[] = $client->getAsync($request['url'], ['proxy' => $request['proxy']])->then(
                fn ($response) => $response->getStatusCode() === 204,
                fn () => false
            );
        }
        return Utils::unwrap($promises);
    }

    public function check(NodeAutomationGroup $group, string $ip, array $targets): array
    {
        $this->readiness();
        $nodes = $this->nodes($group);
        $outbounds = $inbounds = $rules = $requests = $ports = [];
        // Keep sockets reserved until all ports are chosen, avoiding duplicates in a batch.
        $sockets = [];
        try {
            foreach ($nodes as $node) {
                $outbounds[] = (new ProbeProtocol())->outbound($node, $this->user($group, $node), $ip);
                $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
                if (!$socket) throw new AutomationException('无法分配回环探针端口');
                $sockets[] = $socket;
                $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
                $ports[$node->id] = $port;
                $tag = 'probe-' . $node->id;
                $inbounds[] = ['type' => 'socks', 'tag' => $tag, 'listen' => '127.0.0.1', 'listen_port' => $port];
                $rules[] = ['inbound' => [$tag], 'action' => 'route', 'outbound' => 'node-' . $node->id];
                foreach ($targets as $url) $requests[] = ['url' => $url, 'proxy' => 'socks5h://127.0.0.1:' . $port];
            }
        } finally {
            foreach ($sockets as $socket) fclose($socket);
        }
        $config = ['log' => ['disabled' => true], 'inbounds' => $inbounds, 'outbounds' => $outbounds,
            'route' => ['rules' => $rules, 'final' => $outbounds[0]['tag']]];
        return $this->run($config, $requests, $ports, count($targets));
    }

    protected function run(array $config, array $requests, array $ports, int $targetCount): array
    {
        $base = storage_path('app/node-automation-probes');
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) throw new AutomationException('探针临时目录不可写');
        foreach (glob($base . '/probe-*') ?: [] as $old) {
            if (is_dir($old) && filemtime($old) < time() - 120) {
                @unlink($old . '/config.json'); @rmdir($old);
            }
        }
        $dir = $base . '/probe-' . bin2hex(random_bytes(12));
        if (!mkdir($dir, 0700)) throw new AutomationException('探针临时目录不可写');
        $path = $dir . '/config.json';
        $process = null;
        try {
            if (file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR)) === false) throw new AutomationException('探针配置不可写');
            chmod($path, 0600);
            $check = new Process([config('node_automation.binary'), 'check', '-c', $path], timeout: 4);
            $check->run();
            if (!$check->isSuccessful()) throw new AutomationException('节点协议配置不兼容探针版本，请检查传输、TLS 和协议参数');
            // timeout is an independent watchdog even if the queue worker is killed.
            $process = new Process([config('node_automation.timeout_binary'), '-s', 'TERM', '-k', '2', '25',
                config('node_automation.binary'), 'run', '-c', $path], timeout: 28);
            $process->disableOutput();
            $process->start();
            $deadline = microtime(true) + 2;
            do {
                if (!$process->isRunning()) throw new AutomationException('探针启动失败，本轮不计节点故障');
                $ready = true;
                foreach ($ports as $port) {
                    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.05);
                    if ($socket) fclose($socket); else $ready = false;
                }
                if (!$ready) usleep(20000);
            } while (!$ready && microtime(true) < $deadline);
            if (!$ready) throw new AutomationException('探针回环监听未就绪');
            $results = $this->requests($requests);
            if (!$process->isRunning()) throw new AutomationException('探针异常退出，本轮不计节点故障');
            $checks = [];
            foreach (array_keys($ports) as $i => $nodeId) {
                $checks[] = ['node_id' => $nodeId, 'ok' => in_array(true, array_slice($results, $i * $targetCount, $targetCount), true)];
            }
            return ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'nodes' => $checks];
        } catch (AutomationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AutomationException('探针执行异常，本轮不计节点故障');
        } finally {
            if ($process?->isRunning()) $process->stop(1);
            @unlink($path); @rmdir($dir);
        }
    }
}
