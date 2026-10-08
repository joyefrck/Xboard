<?php

namespace App\Services\NodeAutomation;

use App\Models\{Server, User};
use App\Protocols\SingBox;

/** Reuse subscription builders without building selectors, templates or a subscription. */
class ProbeProtocol extends SingBox
{
    public function __construct()
    {
        $this->clientName = 'sing-box';
        $this->clientVersion = '1.14.2';
    }

    public function outbound(Server $node, User $user, string $ip): array
    {
        $server = $node->toArray();
        $server['port'] = (int) $node->port;
        if (!ctype_digit((string) $node->port) || $server['port'] < 1 || $server['port'] > 65535) {
            throw new AutomationException('探针暂不支持动态端口，请使用固定节点端口');
        }
        $password = $node->generateServerPassword($user);
        $server['password'] = $password;
        $method = match ($node->type) {
            Server::TYPE_SHADOWSOCKS => 'buildShadowsocks', Server::TYPE_VMESS => 'buildVmess',
            Server::TYPE_VLESS => 'buildVless', Server::TYPE_TROJAN => 'buildTrojan',
            Server::TYPE_HYSTERIA => 'buildHysteria', Server::TYPE_TUIC => 'buildTuic',
            Server::TYPE_ANYTLS => 'buildAnyTLS', Server::TYPE_SOCKS => 'buildSocks',
            Server::TYPE_HTTP => 'buildHttp',
            default => throw new AutomationException('当前节点协议尚不支持自动化探针'),
        };
        if ($node->type === Server::TYPE_TUIC && (int) data_get($server, 'protocol_settings.version', 5) !== 5) {
            throw new AutomationException('探针仅支持 TUIC v5');
        }
        $outbound = $this->$method($password, $server);
        $outbound = $this->withoutNulls($outbound);
        if (isset($outbound['tls']) && !isset($outbound['tls']['server_name'])) {
            $outbound['tls']['server_name'] = $node->host;
        }
        // sing-box 1.14 removed this legacy Hysteria field.
        unset($outbound['disable_mtu_discovery']);
        if (isset($outbound['transport']) && $outbound['transport'] === []) unset($outbound['transport']);
        $outbound['tag'] = 'node-' . $node->id;
        $outbound['server'] = $ip;
        return $outbound;
    }

    private function withoutNulls(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($item === null) unset($value[$key]);
            elseif (is_array($item)) $value[$key] = $this->withoutNulls($item);
        }
        return $value;
    }
}
