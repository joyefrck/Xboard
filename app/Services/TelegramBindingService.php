<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class TelegramBindingService
{
    public function account(User $user): ?array
    {
        if (!$user->telegram_id) {
            return null;
        }

        $id = (string) $user->telegram_id;
        $fallback = ['id' => $id, 'username' => null, 'name' => null];
        $key = 'telegram-binding-account:' . $user->id . ':' . $id;

        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }

            $chat = app(TelegramService::class)->getChat((int) $id)->result ?? null;
            if (!$chat || (string) ($chat->id ?? '') !== $id || ($chat->type ?? '') !== 'private') {
                throw new \UnexpectedValueException('Unexpected Telegram account');
            }

            $account = [
                'id' => $id,
                'username' => trim((string) ($chat->username ?? '')) ?: null,
                'name' => trim(($chat->first_name ?? '') . ' ' . ($chat->last_name ?? '')) ?: null,
            ];
            Cache::put($key, $account, 600);
            return $account;
        } catch (\Throwable) {
            // Missing credentials, blocked bot or transport failures must not hide the binding.
            try {
                Cache::put($key, $fallback, 60);
            } catch (\Throwable) {
                // The ID is still available when the cache is unavailable.
            }
            return $fallback;
        }
    }
}
