<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\Helper;
use App\Utils\CacheKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordSecurityService
{
    public function __construct(private ?Closure $sender = null) {}

    public function sendCode(User $actor, Request $request, string $oldPassword): array
    {
        return $this->locked($actor, $request, 'send_code', function (User $user) use ($request, $oldPassword) {
            $sessionId = $this->activeSession($user, $request);
            $this->verifyPassword($user, $oldPassword);
            $ipHash = $this->ipHash($request);
            $scope = $this->scope($user, $sessionId, $ipHash);
            $cooldown = 'password-security:cooldown:' . $user->id;
            if (Cache::has($cooldown)) throw new ApiException('发送过于频繁，请稍后重试', 429);
            Cache::lock('password-security:ip-send-lock:' . $ipHash, 5)->block(1, function () use ($user, $ipHash) {
                foreach (['account:' . $user->id => 5, 'ip:' . $ipHash => 20] as $key => $limit) {
                    $key = 'password-security:send:' . $key;
                    if ((int) Cache::get($key, 0) >= $limit) throw new ApiException('验证码发送次数过多，请稍后重试', 429);
                }
                foreach (['account:' . $user->id, 'ip:' . $ipHash] as $key) {
                    $key = 'password-security:send:' . $key;
                    Cache::add($key, 0, 3600);
                    Cache::increment($key);
                }
            });
            $code = (string) random_int(100000, 999999);
            $challenge = [
                'digest' => $this->digest($code), 'attempts' => 0,
                'password_stamp' => $this->digest($user->password),
                'email_stamp' => $this->digest($user->email), 'expires_at' => now()->timestamp + 300,
            ];
            Cache::put($scope, $challenge, 300);
            Cache::put($cooldown, true, 60);
            $mail = ['email' => $user->email, 'subject' => '修改密码邮箱验证', 'template_name' => 'verify',
                'template_value' => ['name' => admin_setting('app_name', 'XBoard'), 'code' => $code,
                    'url' => admin_setting('app_url')]];
            if ($this->sender) ($this->sender)($mail);
            else SendEmailJob::dispatch($mail);
            Log::info('account.security.password_code_sent', ['user_id' => $user->id,
                'session_ref' => hash('sha256', $sessionId), 'source_ip' => SessionSecurityService::canonicalIp($request->ip())]);
            return ['masked_email' => self::maskedEmail($user->email), 'cooldown_seconds' => 60];
        });
    }

    public function change(User $actor, Request $request, string $oldPassword, string $newPassword, ?string $code = null): void
    {
        $this->locked($actor, $request, 'change_password', function (User $user) use ($request, $oldPassword, $newPassword, $code) {
            $sessionId = $this->activeSession($user, $request);
            $this->verifyPassword($user, $oldPassword);
            if (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 128) throw new ApiException('新密码长度应为 8–128 位', 422);
            $ipHash = $this->ipHash($request);
            $trusted = DB::table('v2_user_trusted_ips')->where('user_id', $user->id)->where('ip_hash', $ipHash)
                ->where('email_hash', $this->digest($user->email))->where('expires_at', '>', now())->exists();
            $verified = false;
            $scope = $this->scope($user, $sessionId, $ipHash);
            if (!$trusted) {
                if (!$code) throw new ApiException('请完成安全校验后重试', 428,
                    ['code' => 'EMAIL_VERIFICATION_REQUIRED', 'masked_email' => self::maskedEmail($user->email)]);
                $challenge = Cache::get($scope);
                if (!$challenge || $challenge['expires_at'] <= now()->timestamp
                    || !hash_equals($challenge['password_stamp'], $this->digest($user->password))
                    || !hash_equals($challenge['email_stamp'], $this->digest($user->email))) {
                    throw new ApiException('验证码已失效，请重新发送', 400);
                }
                if ($challenge['attempts'] >= 5) throw new ApiException('验证码错误次数过多，请重新发送', 429);
                if (!preg_match('/^[0-9]{6}$/D', $code) || !hash_equals($challenge['digest'], $this->digest($code))) {
                    $challenge['attempts']++;
                    Cache::put($scope, $challenge, max(1, $challenge['expires_at'] - now()->timestamp));
                    throw new ApiException('邮箱验证码错误', 400);
                }
                Cache::forget($scope);
                $verified = true;
            }
            $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
            $user->password_algo = null;
            $user->password_salt = null;
            $user->saveOrFail();
            self::revokeCredentials($user, false);
            if ($verified) DB::table('v2_user_trusted_ips')->updateOrInsert(
                ['user_id' => $user->id, 'ip_hash' => $ipHash],
                ['email_hash' => $this->digest($user->email), 'expires_at' => now()->addDays(30)]
            );
            Log::info('account.security.password_changed', ['user_id' => $user->id,
                'session_ref' => hash('sha256', $sessionId), 'source_ip' => SessionSecurityService::canonicalIp($request->ip()),
                'mailbox_verified' => $verified]);
        });
    }

    private function locked(User $actor, Request $request, string $action, Closure $operation): mixed
    {
        try {
            return Cache::lock('password-security:lock:' . $actor->id, 15)->block(2, fn () => DB::transaction(
                fn () => $operation(User::whereKey($actor->id)->lockForUpdate()->firstOrFail())
            ));
        } catch (LockTimeoutException $e) {
            throw new ApiException('安全操作正在处理中，请稍后重试', 429);
        } catch (ApiException $e) {
            Log::warning('account.security.password_operation_rejected', [
                'user_id' => $actor->id, 'operation' => $action, 'http_status' => $e->getCode(),
                'reason' => $e->getMessage(), 'source_ip' => SessionSecurityService::canonicalIp($request->ip()),
                'client_label' => SessionSecurityService::client($request)['client_label'],
            ]);
            throw $e;
        }
    }

    public static function revokeCredentials(User $user, bool $forgetTrust = true): void
    {
        $user->tokens()->delete();
        if ($forgetTrust) DB::table('v2_user_trusted_ips')->where('user_id', $user->id)->delete();
        Cache::forget(CacheKey::get('PASSWORD_ERROR_LIMIT', $user->email));
        Cache::forget('password-security:password-errors:' . $user->id);
    }

    private function activeSession(User $user, Request $request): string
    {
        $current = $request->user()?->currentAccessToken();
        $id = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        if (!$id || $user->banned || !$user->tokens()->whereKey($id)->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->exists()) throw new ApiException('登录已失效，请重新登录', 403);
        return (string) $id;
    }

    private function verifyPassword(User $user, string $password): void
    {
        $key = 'password-security:password-errors:' . $user->id;
        if ((int) Cache::get($key, 0) >= 5) throw new ApiException('操作暂时受限，请稍后重试', 429);
        if (!Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password)) {
            Cache::add($key, 0, 900);
            Cache::increment($key);
            throw new ApiException('当前密码错误', 400);
        }
        Cache::forget($key);
    }

    private function ipHash(Request $request): string
    {
        $ip = SessionSecurityService::canonicalIp($request->ip());
        if (!$ip) throw new ApiException('无法确认请求来源，请稍后重试', 400);
        return $this->digest($ip);
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function scope(User $user, string $sessionId, string $ipHash): string
    {
        return 'password-security:challenge:' . $user->id . ':' . $sessionId . ':' . $ipHash;
    }

    public static function maskedEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        return mb_substr($local, 0, 1) . '***@' . $domain;
    }
}
