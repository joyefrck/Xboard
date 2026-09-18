<?php

namespace App\Jobs;

use App\Services\TelegramGroupAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTelegramGroupRequest implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 210;
    public $uniqueFor = 900;

    public function __construct(public int $requestId)
    {
        $this->onConnection('telegram_group')->onQueue('telegram_group');
    }

    public function uniqueId(): string
    {
        return (string) $this->requestId;
    }

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(TelegramGroupAccessService $service): void
    {
        $service->process($this->requestId);
    }
}
