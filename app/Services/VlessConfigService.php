<?php

namespace App\Services;

class VlessConfigService
{
    public static function serverDecryption(array $settings): string
    {
        return data_get($settings, 'encryption.enabled', false)
            ? (string) data_get($settings, 'encryption.decryption', '') : 'none';
    }

    public static function clientEncryption(array $settings): string
    {
        return data_get($settings, 'encryption.enabled', false)
            ? (string) data_get($settings, 'encryption.encryption', '') : 'none';
    }

    public static function clientFingerprint(array $settings): ?string
    {
        return data_get($settings, 'utls.enabled', false)
            ? (string) data_get($settings, 'utls.fingerprint', 'chrome') : null;
    }

    public static function isEnhanced(array $settings): bool
    {
        return (bool) data_get($settings, 'encryption.enabled', false)
            || in_array($settings['network'] ?? 'tcp', ['xhttp', 'splithttp'], true);
    }

    public static function needsHybridReality(array $settings): bool
    {
        if ((int) ($settings['tls'] ?? 0) !== 2) return false;
        if (data_get($settings, 'reality_settings.support_x25519mlkem768', false)) return true;
        $release = (string) data_get($settings, 'backend.release', '');
        return data_get($settings, 'backend.core') === 'xray'
            && preg_match('/^core-v(\d+\.\d+\.\d+)$/D', $release, $match)
            && version_compare($match[1], '1.1.0', '>=');
    }

    public static function clientSettings(array $settings): array
    {
        if (self::needsHybridReality($settings)) $settings['reality_settings']['support_x25519mlkem768'] = true;
        unset($settings['encryption']['decryption'], $settings['reality_settings']['private_key'], $settings['backend']);
        return $settings;
    }

    public static function supportsClient(array $settings, string $renderer, ?string $name, ?string $version): bool
    {
        if (!self::isEnhanced($settings) && !self::needsHybridReality($settings)) return true;
        if (!$version) return false;
        $name = strtolower($name ?? '');
        if (!self::isEnhanced($settings)) {
            if ($renderer === 'Shadowrocket' && $name === 'shadowrocket' && ($settings['network'] ?? 'tcp') === 'tcp') {
                return ctype_digit($version)
                    ? (int) $version >= 3488
                    : (bool) preg_match('/^\d+\.\d+\.\d+$/D', $version) && version_compare($version, '2.2.93', '>=');
            }
            if ($renderer === 'ClashMeta') {
                return match ($name) {
                    'meta', 'mihomo' => version_compare($version, '1.19.32', '>='),
                    'verge', 'clash-verge' => version_compare($version, '2.5.8', '>='),
                    'clashmetaforandroid' => version_compare($version, '2.11.27', '>='),
                    'clashmi' => version_compare($version, '1.0.31', '>='),
                    'flclash', 'elephantnetwork' => version_compare($version, '2.1.0', '>='),
                    default => false,
                };
            }
            return false;
        }
        if ($renderer !== 'ClashMeta') return false;
        return match ($name) {
            'meta', 'mihomo' => version_compare($version, '1.19.33', '>='),
            'flclash', 'elephantnetwork' => version_compare($version, '2.1.0', '>='),
            default => false,
        };
    }

    private const XHTTP_CLIENT_FIELDS = [
        'headers' => 'headers', 'noGRPCHeader' => 'no-grpc-header',
        'xPaddingBytes' => 'x-padding-bytes', 'xPaddingObfsMode' => 'x-padding-obfs-mode',
        'xPaddingKey' => 'x-padding-key', 'xPaddingHeader' => 'x-padding-header',
        'xPaddingPlacement' => 'x-padding-placement', 'xPaddingMethod' => 'x-padding-method',
        'uplinkHTTPMethod' => 'uplink-http-method', 'sessionIDPlacement' => 'session-placement',
        'sessionIDKey' => 'session-key', 'sessionIDTable' => 'session-table', 'sessionIDLength' => 'session-length',
        'seqPlacement' => 'seq-placement', 'seqKey' => 'seq-key',
        'uplinkDataPlacement' => 'uplink-data-placement', 'uplinkDataKey' => 'uplink-data-key',
        'uplinkChunkSize' => 'uplink-chunk-size', 'scMaxEachPostBytes' => 'sc-max-each-post-bytes',
        'scMinPostsIntervalMs' => 'sc-min-posts-interval-ms',
    ];
    private const XHTTP_REUSE_FIELDS = [
        'maxConcurrency' => 'max-concurrency', 'maxConnections' => 'max-connections',
        'cMaxReuseTimes' => 'c-max-reuse-times', 'hMaxRequestTimes' => 'h-max-request-times',
        'hMaxReusableSecs' => 'h-max-reusable-secs', 'hKeepAlivePeriod' => 'h-keep-alive-period',
    ];

    public static function clashXhttpOptions(array $settings): array
    {
        $options = array_filter([
            'path' => data_get($settings, 'network_settings.path', '/'),
            'host' => data_get($settings, 'network_settings.host'),
            'mode' => data_get($settings, 'network_settings.mode', 'auto'),
        ], fn($value) => $value !== null);
        $extra = data_get($settings, 'network_settings.extra', []);
        if (!is_array($extra)) return $options;
        foreach (self::XHTTP_CLIENT_FIELDS as $key => $target) {
            if (array_key_exists($key, $extra)) $options[$target] = is_int($extra[$key]) ? (string) $extra[$key] : $extra[$key];
        }
        foreach (self::XHTTP_REUSE_FIELDS as $key => $target) {
            if (isset($extra['xmux'][$key])) $options['reuse-settings'][$target] = $key === 'hKeepAlivePeriod' ? (int) $extra['xmux'][$key] : (string) $extra['xmux'][$key];
        }
        return $options;
    }

    public static function errors(array $settings): array
    {
        $errors = [];
        $add = static function (string $field, string $message) use (&$errors): void {
            $errors['protocol_settings.' . $field] = $message;
        };
        $network = $settings['network'] ?? 'tcp';
        $tls = (int) ($settings['tls'] ?? 0);
        $flow = $settings['flow'] ?? '';
        if (!in_array($network, ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp', 'kcp'], true)) $add('network', '不支持此传输协议');
        if (!in_array($tls, [0, 1, 2], true)) $add('tls', '安全层配置无效');
        if (!in_array($flow, ['', null, 'none', 'xtls-rprx-vision'], true)) $add('flow', '请选择无流控或 Vision');
        if ($flow === 'xtls-rprx-vision' && ($network !== 'tcp' || $tls === 0)) $add('flow', 'Vision 仅支持 TCP 配合 TLS 或 REALITY');
        if (self::clientFingerprint($settings) !== null && !in_array(self::clientFingerprint($settings), ['chrome', 'firefox', 'safari', 'ios', 'edge', 'android', 'qq', 'random'], true)) $add('utls.fingerprint', '不支持此客户端指纹');
        if (self::isEnhanced($settings)) {
            $release = (string) data_get($settings, 'backend.release', '');
            if (data_get($settings, 'backend.core') !== 'xray' || !preg_match('/^core-v(\d+\.\d+\.\d+)$/D', $release, $match) || version_compare($match[1], '1.1.0', '<')) $add('backend.release', '请先核实节点已安装支持增强配置的 Xray / V2bX core-v1.1.0 或更新版本');
        }
        if ($network === 'xhttp') {
            if (!in_array(data_get($settings, 'network_settings.mode', 'auto'), ['auto', 'packet-up', 'stream-up', 'stream-one'], true)) $add('network_settings.mode', 'XHTTP 模式无效');
            $path = data_get($settings, 'network_settings.path', '/');
            if (!is_string($path) || !str_starts_with($path, '/')) $add('network_settings.path', 'XHTTP 路径必须以 / 开头');
            if (isset($settings['network_settings']['extra']) && (!is_array($settings['network_settings']['extra']) || ($settings['network_settings']['extra'] !== [] && array_is_list($settings['network_settings']['extra'])))) $add('network_settings.extra', '高级配置必须是 JSON 对象');
            $extra = data_get($settings, 'network_settings.extra', []);
            if (is_array($extra)) {
                $allowed = [...array_keys(self::XHTTP_CLIENT_FIELDS), 'xmux', 'noSSEHeader', 'scMaxBufferedPosts', 'scStreamUpServerSecs', 'serverMaxHeaderBytes'];
                if (array_diff(array_keys($extra), $allowed)) $add('network_settings.extra', '包含本批尚未适配的 XHTTP 参数；downloadSettings 和 h2Params 暂不支持');
                if (isset($extra['xmux']) && (!is_array($extra['xmux']) || array_diff(array_keys($extra['xmux']), array_keys(self::XHTTP_REUSE_FIELDS)))) $add('network_settings.extra', 'XMUX 参数格式无效');
            }

        }
        if (self::needsHybridReality($settings) && self::clientFingerprint($settings) !== 'chrome') $add('utls.fingerprint', '新版 REALITY 需要显式启用 chrome 指纹以发送混合 KeyShare');
        if ($tls === 2) {
            $reality = $settings['reality_settings'] ?? [];
            $short = $reality['short_id'] ?? '';
            if (!is_string($short) || !preg_match('/\A(?:[a-f0-9]{2}){0,8}\z/i', $short)) $add('reality_settings.short_id', 'Short ID 必须为偶数长度且最多 16 位十六进制字符');
            $port = filter_var($reality['server_port'] ?? 443, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) $add('reality_settings.server_port', '目标端口必须为 1–65535');
            foreach (['server_name', 'dest'] as $field) {
                $host = $reality[$field] ?? null;
                if (($host === null || $host === '') && $field === 'dest') continue;
                if (!is_string($host) || $host === '' || (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) $add('reality_settings.' . $field, '请填写有效主机名或 IP，不包含协议和端口');
            }
            if (!self::matchingKeys($reality['private_key'] ?? '', $reality['public_key'] ?? '')) $add('reality_settings.private_key', 'REALITY X25519 密钥格式错误或不匹配');
        }
        if (data_get($settings, 'encryption.enabled', false)) {
            $client = self::parseEncryption(self::clientEncryption($settings), true);
            $server = self::parseEncryption(self::serverDecryption($settings), false);
            if (!$client || !$server || $client['mode'] !== $server['mode'] || !self::matchingKeys($server['key'], $client['key'])) $add('encryption.decryption', 'VLESS Encryption 参数格式错误或密钥不匹配；请使用 V2bX vlessenc --json --auth x25519 生成配对参数');
        }
        if ($tls === 0 && !data_get($settings, 'encryption.enabled', false)) $add('tls', '公网 VLESS 必须启用 TLS、REALITY 或 VLESS Encryption');
        return $errors;
    }

    private static function parseEncryption(string $value, bool $client): ?array
    {
        $parts = explode('.', $value);
        if (count($parts) < 4 || $parts[0] !== 'mlkem768x25519plus' || !in_array($parts[1], ['native', 'xorpub', 'random'], true)) return null;
        if ($client && !in_array($parts[2], ['0rtt', '1rtt'], true)) return null;
        if (!$client && (!preg_match('/\A([1-9]\d*)(?:-([1-9]\d*))?s\z/', $parts[2], $match) || (int) $match[1] > 4294967295 || (isset($match[2]) && ((int) $match[2] < (int) $match[1] || (int) $match[2] > 4294967295)))) return null;
        $keys = [];
        foreach (array_slice($parts, 3) as $part) {
            if (strlen($part) < 20 && preg_match('/\A\d+-\d+-\d+\z/', $part)) continue;
            if (!self::decodeKey($part)) return null;
            $keys[] = $part;
        }
        return count($keys) === 1 ? ['mode' => $parts[1], 'key' => $keys[0]] : null;
    }

    private static function decodeKey(string $value): string|false
    {
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) return false;
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private static function matchingKeys(string $private, string $public): bool
    {
        $secret = self::decodeKey($private);
        $expected = self::decodeKey($public);
        if ($secret === false || $expected === false) return false;
        if (strlen($secret) !== 32 || strlen($expected) !== 32) return false;
        try {
            $actual = function_exists('sodium_crypto_scalarmult_base') ? sodium_crypto_scalarmult_base($secret) : \ParagonIE_Sodium_Compat::crypto_scalarmult_base($secret);
            return hash_equals($actual, $expected);
        } catch (\Throwable) {
            return false;
        }
    }
}
