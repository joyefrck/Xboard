<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Laravel\Sanctum\PersonalAccessToken;
use App\Exceptions\ApiException;

class LoginService
{
    /**
     * 处理用户登录
     *
     * @param string $email 用户邮箱
     * @param string $password 用户密码
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function login(string $email, string $password): array
    {
        // 检查密码错误限制
        if ((int) admin_setting('password_limit_enable', true)) {
            $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int) admin_setting('password_limit_count', 5)) {
                return [
                    false,
                    [
                        429,
                        __('There are too many password errors, please try again after :minute minutes.', [
                            'minute' => admin_setting('password_limit_expire', 60)
                        ])
                    ]
                ];
            }
        }

        // 查找用户
        $user = User::where('email', $email)->first();
        if (!$user) {
            return [false, [400, __('Incorrect email or password')]];
        }

        // 验证密码
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )
        ) {
            // 增加密码错误计数
            if ((int) admin_setting('password_limit_enable', true)) {
                $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int) $passwordErrorCount + 1,
                    60 * (int) admin_setting('password_limit_expire', 60)
                );
            }
            return [false, [400, __('Incorrect email or password')]];
        }

        // 检查账户状态
        if ($user->banned) {
            return [false, [400, __('Your account has been suspended')]];
        }

        // 更新最后登录时间
        $user->last_login_at = time();
        $user->save();

        HookManager::call('user.login.after', $user);
        return [true, $user];
    }

    /**
     * 处理密码重置
     *
     * @param string $email 用户邮箱
     * @param string $emailCode 邮箱验证码
     * @param string $password 新密码
     * @return array [成功状态, 结果或错误信息]
     */
    public function resetPassword(string $email, string $emailCode, string $password): array
    {
        // 检查重置请求限制
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $email);
        $forgetRequestLimit = (int) Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            return [false, [429, __('Reset failed, Please try again later')]];
        }

        try {
            return Cache::lock('password-reset:' . hash('sha256', $email), 15)->block(2, function () use ($email, $emailCode, $password, $forgetRequestLimitKey) {
                return DB::transaction(function () use ($email, $emailCode, $password, $forgetRequestLimitKey) {
                    $count = (int) Cache::get($forgetRequestLimitKey, 0);
                    if ($count >= 3) return [false, [429, __('Reset failed, Please try again later')]];
                    $cached = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
                    if ($cached === null || $emailCode === '' || !hash_equals((string) $cached, $emailCode)) {
                        Cache::put($forgetRequestLimitKey, $count + 1, 300);
                        return [false, [400, __('Incorrect email verification code')]];
                    }
                    $user = User::where('email', $email)->lockForUpdate()->first();
                    if (!$user) return [false, [400, __('This email is not registered in the system')]];
                    $user->password = password_hash($password, PASSWORD_DEFAULT);
                    $user->password_algo = null;
                    $user->password_salt = null;
                    $user->saveOrFail();
                    PasswordSecurityService::revokeCredentials($user);
                    Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));
                    Log::info('account.security.password_recovered', ['user_id' => $user->id]);
                    HookManager::call('user.password.reset.after', $user);
                    return [true, true];
                });
            });
        } catch (LockTimeoutException $e) {
            return [false, [429, __('Reset failed, Please try again later')]];
        }
    }


    /**
     * 生成临时登录令牌和快速登录URL
     *
     * @param User $user 用户对象
     * @param string $redirect 重定向路径
     * @return string|null 快速登录URL
     */
    public function generateQuickLoginUrl(User $user, ?string $redirect = null): ?string
    {
        if (!$user || !$user->exists) {
            return null;
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);

        $parent = $user->currentAccessToken();
        if (!$parent instanceof PersonalAccessToken) throw new ApiException('请重新登录后获取快速登录链接', 403);
        Cache::put($key, MailLinkService::loginGrant($user, $parent->getKey()), 60);

        $redirect = $redirect ?: 'dashboard';
        $loginRedirect = '/#/login?verify=' . $code . '&redirect=' . rawurlencode($redirect);

        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $loginRedirect;
        } else {
            $url = url($loginRedirect);
        }

        return $url;
    }
}
