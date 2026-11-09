<?php

namespace App\Console\Commands;

use App\Services\OrderRefundNotificationService;
use Illuminate\Console\Command;

class RetryRefundMail extends Command
{
    protected $signature = 'refund:retry-mail';
    protected $description = '投递尚未成功发送的退款余额通知';

    public function handle(OrderRefundNotificationService $notifications): int
    {
        $notifications->retryPending();
        return self::SUCCESS;
    }
}
