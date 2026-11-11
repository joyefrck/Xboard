<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class SessionSecurityService
{
    public static function canonicalIp(?string $value): ?string
    {
        if (!$value || !filter_var($value, FILTER_VALIDATE_IP)) return null;
        $packed = inet_pton($value);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($packed, 12));
        }
        return inet_ntop($packed);
    }

    public static function client(Request $request): array
    {
        $ua = substr((string) $request->userAgent(), 0, 512);
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '未知系统',
        };
        $label = match (true) {
            (bool) preg_match('/ElephantNetwork|ElephantRoute/i', $ua) => '大象网络 App',
            (bool) preg_match('/curl\//i', $ua) => 'curl',
            (bool) preg_match('/Dart\//i', $ua) => 'App 客户端',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') => 'Firefox',
            (bool) preg_match('/Chrome\/|Chromium\//', $ua) => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => '未知客户端',
        };
        $type = in_array($label, ['Chrome', 'Edge', 'Firefox', 'Safari'], true) ? 'browser'
            : (in_array($label, ['大象网络 App', 'App 客户端'], true) ? 'app' : 'other');
        return ['client_label' => $label, 'client_type' => $type, 'client_os' => $os];
    }

    public static function metadata(Request $request): array
    {
        $ip = self::canonicalIp($request->ip());
        return self::client($request) + ['login_ip' => $ip, 'last_ip' => $ip, 'metadata_seen_at' => now()];
    }

    public function observe(User $user, Request $request): void
    {
        $token = $user->currentAccessToken();
        if (!$token instanceof PersonalAccessToken || !$token->getKey()) return;
        if ($token->metadata_seen_at && strtotime($token->metadata_seen_at) > time() - 300) return;
        $user->tokens()->whereKey($token->getKey())->update([
            'last_ip' => self::canonicalIp($request->ip()), 'metadata_seen_at' => now(),
        ]);
    }

    public function sessions(User $user, Request $request): array
    {
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        return $user->tokens()->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->orderByDesc('id')->get()->map(fn ($token) => [
            'id' => (string) $token->id,
            'is_current' => (string) $token->id === (string) $currentId,
            'client_label' => $token->client_label ?: '历史会话／设备信息未知',
            'client_type' => $token->client_type ?: 'unknown',
            'client_os' => $token->client_os,
            'login_ip' => $token->login_ip,
            'last_ip' => $token->last_ip,
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
        ])->all();
    }

    public function revoke(User $user, ?string $sessionId = null): void
    {
        DB::transaction(function () use ($user, $sessionId) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $query = $user->tokens();
            if ($sessionId !== null) $query->whereKey($sessionId);
            if ($sessionId !== null && !$query->exists()) throw new ApiException('会话不存在或已退出', 404);
            $query->delete();
            Log::info('account.security.sessions_revoked', [
                'user_id' => $user->id, 'scope' => $sessionId === null ? 'all' : 'single',
                'session_ref' => $sessionId === null ? null : hash('sha256', $sessionId),
            ]);
        });
    }
}
