<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramGroupRequest;
use App\Models\TelegramGroupInvitation;
use App\Models\TelegramGroupRequest;
use App\Services\TelegramGroupAccessService;
use Illuminate\Console\Command;

class RetryTelegramGroupRequests extends Command
{
    protected $signature = 'telegram:group-retry {--failed : 重新提交已失败的目标群申请}';
    protected $description = '重试待处理入群申请和已使用邀请的撤销';

    public function handle(TelegramGroupAccessService $service): int
    {
        if (!$service->enabled()) return self::SUCCESS;

        if ($this->option('failed')) {
            TelegramGroupRequest::where('chat_id', $service->chatId())->where('status', 'failed')
                ->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => 0]);
        }
        TelegramGroupRequest::where('chat_id', $service->chatId())->where('status', 'pending')
            ->where('next_attempt_at', '<=', time())->orderBy('id')->limit(100)->get()
            ->each(fn($request) => ProcessTelegramGroupRequest::dispatch($request->id));
        TelegramGroupInvitation::where('chat_id', $service->chatId())->whereNotNull('used_at')
            ->whereNull('revoked_at')->where('expires_at', '>', time())->limit(20)->get()
            ->each(fn($invite) => $service->revoke($invite));

        return self::SUCCESS;
    }
}
