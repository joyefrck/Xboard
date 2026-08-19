<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class TelegramCredentialService
{
    public function getToken(TelegramBotProfile $profile): string
    {
        if ($profile === TelegramBotProfile::GENERAL) {
            return trim((string) admin_setting($profile->tokenSetting(), ''));
        }

        return $this->decryptSetting($profile->tokenSetting());
    }

    public function hasToken(TelegramBotProfile $profile): bool
    {
        return $this->getToken($profile) !== '';
    }

    public function storeTicketToken(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            throw new ApiException('工单机器人令牌不能为空', 422);
        }

        admin_setting([
            TelegramBotProfile::TICKET->tokenSetting() => Crypt::encryptString($token),
        ]);
    }

    public function getTicketWebhookSecret(): string
    {
        return $this->decryptSetting('telegram_ticket_webhook_secret');
    }

    public function ensureTicketWebhookSecret(): string
    {
        $secret = $this->getTicketWebhookSecret();
        if ($secret !== '') {
            return $secret;
        }

        $secret = Str::random(48);
        admin_setting([
            'telegram_ticket_webhook_secret' => Crypt::encryptString($secret),
        ]);

        return $secret;
    }

    private function decryptSetting(string $key): string
    {
        $encrypted = trim((string) admin_setting($key, ''));
        if ($encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }
}
