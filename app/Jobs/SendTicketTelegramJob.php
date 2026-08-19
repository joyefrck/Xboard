<?php

namespace App\Jobs;

use App\Services\TelegramBotProfile;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTicketTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $xboardUserId;
    protected int $telegramId;
    protected string $text;
    protected array $options;

    public $tries = 1;
    public $timeout = 50;

    public function __construct(int $xboardUserId, int $telegramId, string $text, array $options = [])
    {
        $this->onQueue('send_ticket_telegram');
        $this->xboardUserId = $xboardUserId;
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->options = $options;
    }

    public function handle(): void
    {
        try {
            $service = new TelegramService(profile: TelegramBotProfile::TICKET);
            $service->sendMessage($this->telegramId, $this->text, 'markdown', $this->options);
        } catch (\Throwable $error) {
            Log::warning('工单 Telegram 通知发送失败', [
                'user_id' => $this->xboardUserId,
                'telegram_id' => $this->telegramId,
                'error' => $error->getMessage(),
            ]);
            throw $error;
        }
    }
}
