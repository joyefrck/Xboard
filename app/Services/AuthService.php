<?php

namespace App\Services;

use App\Models\User;
use App\Exceptions\ApiException;
use App\Services\Auth\SessionSecurityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(): array
    {
        $token = DB::transaction(function () {
            $user = User::whereKey($this->user->id)->lockForUpdate()->firstOrFail();
            if ($user->banned || !hash_equals((string) $this->user->password, (string) $user->password)) {
                throw new ApiException('账号认证状态已变化，请重新登录', 403);
            }
            $parent = $this->user->currentAccessToken();
            if ($parent && (!$parent instanceof PersonalAccessToken || !$parent->getKey() || !$user->tokens()->whereKey($parent->getKey())->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->exists())) throw new ApiException('登录会话已退出，请重新登录', 403);
            $token = $user->createToken(Str::random(20), ['*'], now()->addYear());
            $token->accessToken->forceFill(SessionSecurityService::metadata(request()))->save();
            $this->user = $user;
            return $token;
        });
        $tokenParts = explode('|', $token->plainTextToken);
        $formattedToken = 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);

        return [
            'token' => $this->user->token,
            'auth_data' => $formattedToken,
            'is_admin' => $this->user->is_admin,
        ];
    }

    public function getSessions(): array
    {
        return (new SessionSecurityService())->sessions($this->user, request());
    }

    public function removeSession(string $sessionId): bool
    {
        (new SessionSecurityService())->revoke($this->user, $sessionId);
        return true;
    }

    public function removeAllSessions(): bool
    {
        (new SessionSecurityService())->revoke($this->user);
        return true;
    }

    public static function findUserByBearerToken(string $bearerToken): ?User
    {
        $token = str_replace('Bearer ', '', $bearerToken);
        
        $accessToken = PersonalAccessToken::findToken($token);
        if ($accessToken?->expires_at?->isPast()) return null;
        
        $tokenable = $accessToken?->tokenable;
        
        return $tokenable instanceof User && !$tokenable->banned ? $tokenable->withAccessToken($accessToken) : null;
    }

    /**
     * 解密认证数据
     *
     * @param string $authorization
     * @return array|null 用户数据或null
     */
    public static function decryptAuthData(string $authorization): ?array
    {
        $user = self::findUserByBearerToken($authorization);
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool)$user->is_admin,
            'is_staff' => (bool)$user->is_staff
        ];
    }
}
