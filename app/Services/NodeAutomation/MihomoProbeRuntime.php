<?php

namespace App\Services\NodeAutomation;

use App\Models\{Server, User};
use App\Protocols\ClashMeta;
use App\Services\VlessConfigService;
use Symfony\Component\Process\Process;

class MihomoProbeRuntime
{
    public static function required(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node->type === Server::TYPE_VLESS && VlessConfigService::needsHybridReality($node->protocol_settings)) return true;
        }
        return false;
    }

    public function readiness(): void
    {
        $binary = config('node_automation.mihomo_binary');
        $timeout = config('node_automation.timeout_binary');
        if (!is_string($binary) || !is_executable($binary) || !is_executable($timeout) || !extension_loaded('curl')) {
            throw new UnsupportedProtocolException('新版 REALITY 探针未安装：需要 Mihomo 1.19.33、timeout 和 PHP curl');
        }
        $version = new Process([$binary, '-v'], timeout: 3);
        $version->run();
        if (!$version->isSuccessful() || !preg_match('/\bMihomo Meta v?1\.19\.33(?:\s|$)/', $version->getOutput())) {
            throw new UnsupportedProtocolException('新版 REALITY 探针版本必须为 Mihomo 1.19.33');
        }
    }

    public function outbound(Server $node, User $user, string $ip): array
    {
        ProbeProtocol::assertSupported($node, true);
        $server = $node->toArray();
        $server['port'] = (int) $node->port;
        if (!ctype_digit((string) $node->port) || $server['port'] < 1 || $server['port'] > 65535) {
            throw new AutomationException('探针暂不支持动态端口，请使用固定节点端口');
        }
        if ($node->type === Server::TYPE_TUIC && (int) data_get($server, 'protocol_settings.version', 5) !== 5) {
            throw new AutomationException('探针仅支持 TUIC v5');
        }
        $method = match ($node->type) {
            Server::TYPE_SHADOWSOCKS => 'buildShadowsocks', Server::TYPE_VMESS => 'buildVmess',
            Server::TYPE_VLESS => 'buildVless', Server::TYPE_TROJAN => 'buildTrojan',
            Server::TYPE_HYSTERIA => 'buildHysteria', Server::TYPE_TUIC => 'buildTuic',
            Server::TYPE_ANYTLS => 'buildAnyTLS', Server::TYPE_SOCKS => 'buildSocks5',
            Server::TYPE_HTTP => 'buildHttp',
            default => throw new AutomationException('当前节点协议尚不支持自动化探针'),
        };
        $proxy = ClashMeta::$method($node->generateServerPassword($user), $server, $user->toArray());
        $sni = in_array($node->type, [Server::TYPE_VMESS, Server::TYPE_VLESS], true) ? 'servername' : 'sni';
        $tls = ($proxy['tls'] ?? false) || in_array($node->type, [Server::TYPE_TROJAN, Server::TYPE_HYSTERIA, Server::TYPE_TUIC, Server::TYPE_ANYTLS], true);
        if ($tls && empty($proxy[$sni])) $proxy[$sni] = $node->host;
        $proxy['name'] = 'node-' . $node->id;
        $proxy['server'] = $ip;
        return $proxy;
    }

    public function configuration(array $proxies, array $ports): array
    {
        $listeners = [];
        foreach ($ports as $nodeId => $port) {
            $listeners[] = ['name' => 'probe-' . $nodeId, 'type' => 'socks', 'listen' => '127.0.0.1',
                'port' => $port, 'udp' => false, 'proxy' => 'node-' . $nodeId];
        }
        return ['log-level' => 'silent', 'mode' => 'rule', 'ipv6' => false, 'allow-lan' => false,
            'bind-address' => '127.0.0.1', 'listeners' => $listeners, 'proxies' => $proxies,
            'rules' => ['MATCH,REJECT'], 'profile' => ['store-selected' => false, 'store-fake-ip' => false]];
    }
}
