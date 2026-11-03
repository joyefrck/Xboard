<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Protocols\General;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Protocol prefix mapping for server names
     */
    private const PROTOCOL_PREFIXES = [
        'hysteria' => [
            1 => '[Hy]',
            2 => '[Hy2]'
        ],
        'vless' => '[vless]',
        'shadowsocks' => '[ss]',
        'vmess' => '[vmess]',
        'trojan' => '[trojan]',
        'tuic' => '[tuic]',
        'socks' => '[socks]',
        'anytls' => '[anytls]'
    ];


    public function subscribe(Request $request)
    {
        HookManager::call('client.subscribe.before');
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'flag' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $userService = new UserService();

        if (!$userService->isAvailable($user)) {
            HookManager::call('client.subscribe.unavailable');
            return response('', 403, ['Content-Type' => 'text/plain']);
        }

        return $this->doSubscribe($request, $user);
    }

    public function doSubscribe(Request $request, $user, $servers = null)
    {
        $trafficSummary = app(UserService::class)->getTrafficSummary($user);
        foreach ($trafficSummary as $key => $value) {
            $user[$key] = $value;
        }
        $user['transfer_enable'] = $trafficSummary['effective_transfer_enable'];
        $user['expired_at'] = $trafficSummary['effective_expired_at'];

        if ($servers === null) {
            $servers = ServerService::getAvailableServers($user);
            $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
        }

        $clientInfo = $this->getClientInfo($request);

        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));

        $protocolClassName = app('protocols.manager')->matchProtocolClassName($clientInfo['flag'])
            ?? General::class;

        $serversFiltered = $this->filterServers(
            servers: $servers,
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );

        $this->setSubscribeInfoToServers($serversFiltered, $user, count($servers) - count($serversFiltered));
        $serversFiltered = $this->addPrefixToServerName($serversFiltered);

        // Instantiate the protocol class with filtered servers and client info
        $protocolInstance = app()->make($protocolClassName, [
            'user' => $user,
            'servers' => $serversFiltered,
            'clientName' => $clientInfo['name'] ?? null,
            'clientVersion' => $clientInfo['version'] ?? null,
            'clientPlatform' => $clientInfo['platform'] ?? null,
        ]);

        $diagnostics = $protocolInstance->subscriptionDiagnostics();
        $filteredCount = $diagnostics['protocol_rejections'] + $diagnostics['version_rejections'] + $diagnostics['vless_feature_rejections'];
        if ($diagnostics['compatible_nodes'] === 0 && $filteredCount > 0) {
            $message = $clientInfo['name'] === 'karing' && $diagnostics['hybrid_reality_rejections'] > 0
                ? 'Karing 当前没有兼容节点；此版本尚未通过新版 REALITY 握手验证，请使用兼容线路或支持该握手的客户端。'
                : '当前客户端没有兼容节点，请使用已验证版本的客户端或兼容线路。';
            return response()->json(['code' => 'NO_COMPATIBLE_NODES', 'message' => $message, 'diagnostics' => $diagnostics], 422)
                ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}")
                ->header('X-Subscription-Compatible-Nodes', '0')
                ->header('X-Subscription-Filtered-Nodes', (string) $filteredCount)
                ->header('X-Subscription-Vless-Filtered-Nodes', (string) $diagnostics['vless_feature_rejections'])
                ->header('Cache-Control', 'no-store');
        }

        return $protocolInstance->handle()
            ->header('X-Subscription-Compatible-Nodes', (string) $diagnostics['compatible_nodes'])
            ->header('X-Subscription-Filtered-Nodes', (string) $filteredCount)
            ->header('X-Subscription-Vless-Filtered-Nodes', (string) $diagnostics['vless_feature_rejections']);
    }

    /**
     * Parses the input string for requested server types.
     */
    private function parseRequestedTypes(?string $typeInputString): array
    {
        if (blank($typeInputString) || $typeInputString === 'all') {
            return Server::VALID_TYPES;
        }

        $requested = collect(preg_split('/[|,｜]+/', $typeInputString))
            ->map(fn($type) => trim($type))
            ->filter() // Remove empty strings that might result from multiple delimiters
            ->all();

        return array_values(array_intersect($requested, Server::VALID_TYPES));
    }

    /**
     * Parses the input string for filter keywords.
     */
    private function parseFilterKeywords(?string $filterInputString): ?array
    {
        if (blank($filterInputString) || mb_strlen($filterInputString) > 20) {
            return null;
        }

        return collect(preg_split('/[|,｜]+/', $filterInputString))
            ->map(fn($keyword) => trim($keyword))
            ->filter() // Remove empty strings
            ->all();
    }

    /**
     * Filters servers based on allowed types and keywords.
     */
    private function filterServers(array $servers, array $allowedTypes, ?array $filterKeywords): array
    {
        return collect($servers)->filter(function ($server) use ($allowedTypes, $filterKeywords) {
            // Condition 1: Server type must be in the list of allowed types
            if ($allowedTypes && !in_array($server['type'], $allowedTypes)) {
                return false; // Filter out (don't keep)
            }

            // Condition 2: If filterKeywords are provided, at least one keyword must match
            if (!empty($filterKeywords)) { // Check if $filterKeywords is not empty
                $keywordMatch = collect($filterKeywords)->contains(function ($keyword) use ($server) {
                    return stripos($server['name'], $keyword) !== false
                        || in_array($keyword, $server['tags'] ?? []);
                });
                if (!$keywordMatch) {
                    return false; // Filter out if no keywords match
                }
            }
            // Keep the server if its type is allowed AND (no filter keywords OR at least one keyword matched)
            return true;
        })->values()->all();
    }

    private function getClientInfo(Request $request): array
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));
        $identity = strtolower($request->header('User-Agent', ''));
        if ($identity === '' || preg_match('/\/v?\d/', $flag)) $identity = $flag;

        $clientName = null;
        $clientVersion = null;

        $nativeClient = (bool) preg_match('/^(quantumult(?:%20|[ -])?x|karing|stash|v2rayng|passwall2?)(?=[\/\s]|$)/', $identity, $native);
        if ($nativeClient) {
            $clientName = str_starts_with($native[1], 'quantumult') ? 'quantumultx' : $native[1];
            $versionPattern = $clientName === 'karing' ? '\d+\.\d+\.\d+(?:\.\d+)?' : '\d+\.\d+\.\d+';
            if (preg_match('/^' . preg_quote($native[1], '/') . '[\/\s]+v?(' . $versionPattern . ')(?=\s|$|[;(])/', $identity, $matches)) {
                $clientVersion = $matches[1];
            }
        } elseif (preg_match('/^elephant network\/v(\d+\.\d+\.\d+)(?=\s|$)/', $identity, $matches)) {
            $clientName = 'elephantnetwork';
            $clientVersion = $matches[1];
        } elseif (preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/', $identity, $matches)) {
            $potentialName = strtolower($matches[1]);
            $clientVersion = preg_replace('/^v/', '', $matches[2]);

            if (in_array($potentialName, app('protocols.flags'))) {
                $clientName = $potentialName;
            }
        }

        if (!$clientName && !$nativeClient) {
            $flags = collect(app('protocols.flags'))->sortByDesc(fn($f) => strlen($f))->values()->all();
            foreach ($flags as $name) {
                if (stripos($identity, $name) !== false) {
                    $clientName = $name;
                    if (!$clientVersion) {
                        $pattern = '/' . preg_quote($name, '/') . '[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/i';
                        if (preg_match($pattern, $identity, $vMatches)) {
                            $clientVersion = preg_replace('/^v/', '', $vMatches[1]);
                        }
                    }
                    break;
                }
            }
        }

        if (!$clientVersion && !$nativeClient) {
            if (preg_match('/\/v?(\d+(?:\.\d+){0,2})/', $identity, $matches)) {
                $clientVersion = $matches[1];
            }
        }

        if (in_array($clientName, ['passwall', 'passwall2'], true)
            && !preg_match('/^passwall2?(?=[\/\s]|$)/i', $request->header('User-Agent', ''))) {
            // A format flag or a compatibility token is not a Passwall identity.
            $clientName = null;
            $clientVersion = null;
            $nativeClient = false;
        }
        if (!$nativeClient && in_array($clientName, ['stash', 'quantumultx', 'quantumult x', 'quantumult%20x', 'quantumult-x', 'karing', 'v2rayng'], true)) {
            // A compatibility token must not inherit some other product's version.
            $clientVersion = null;
        }
        if ($nativeClient && $request->input('flag') === null) {
            $flag = $clientName;
        }

        // Explicit platform markers take precedence over compatibility tokens
        // later in composite UAs (for example Karing's NekoBox/Android token).
        $platform = null;
        $platformIdentity = strtolower($request->header('User-Agent', '')) ?: $identity;
        if (preg_match('/\bplatform[\/\s]+(ios|ipados|macos|windows|android|linux)\b/', $platformIdentity, $matches)) {
            $platform = $matches[1] === 'ipados' ? 'ios' : $matches[1];
        } elseif (preg_match('/\b(macos|macintosh|mac os x)\b/', $platformIdentity)) {
            $platform = 'macos';
        } elseif (preg_match('/\b(ios|ipados|iphone|ipad)\b/', $platformIdentity)) {
            $platform = 'ios';
        } elseif ($nativeClient && $clientName === 'v2rayng'
            && preg_match('/\b(android|windows|linux)\b/', $platformIdentity, $matches)) {
            $platform = $matches[1];
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion,
            'platform' => $platform,
        ];
    }

    private function setSubscribeInfoToServers(&$servers, $user, $rejectServerCount = 0)
    {
        if (!isset($servers[0]))
            return;
        if ($rejectServerCount > 0) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "过滤掉{$rejectServerCount}条线路",
            ]));
        }
        if (!(int) admin_setting('show_info_to_server_enable', 0))
            return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : __('长期有效');
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }

    private function addPrefixToServerName(array $servers): array
    {
        if (!admin_setting('show_protocol_to_server_enable', false)) {
            return $servers;
        }
        return collect($servers)
            ->map(function (array $server): array {
                $server['name'] = $this->getPrefixedServerName($server);
                return $server;
            })
            ->all();
    }

    private function getPrefixedServerName(array $server): string
    {
        $type = $server['type'] ?? '';
        if (!isset(self::PROTOCOL_PREFIXES[$type])) {
            return $server['name'] ?? '';
        }
        $prefix = is_array(self::PROTOCOL_PREFIXES[$type])
            ? self::PROTOCOL_PREFIXES[$type][$server['protocol_settings']['version'] ?? 1] ?? ''
            : self::PROTOCOL_PREFIXES[$type];
        return $prefix . ($server['name'] ?? '');
    }
}
