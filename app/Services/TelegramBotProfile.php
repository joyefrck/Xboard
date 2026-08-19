<?php

namespace App\Services;

enum TelegramBotProfile: string
{
    case GENERAL = 'general';
    case TICKET = 'ticket';

    public function tokenSetting(): string
    {
        return match ($this) {
            self::GENERAL => 'telegram_bot_token',
            self::TICKET => 'telegram_ticket_bot_token',
        };
    }

    public function webhookSecretSetting(): ?string
    {
        return $this === self::TICKET ? 'telegram_ticket_webhook_secret' : null;
    }
}
