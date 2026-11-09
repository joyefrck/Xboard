<?php

namespace App\Jobs;

use App\Models\OrderRefundMail;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Same send_email queue, rate limiter, timeout and retry policy as ordinary mail.
class SendRefundEmailJob extends SendEmailJob
{
    public function __construct(public int $notificationId)
    {
        parent::__construct([], 'send_email');
    }

    public function handle()
    {
        $success = DB::transaction(function () {
            $mail = OrderRefundMail::whereKey($this->notificationId)->lockForUpdate()->first();
            if (!$mail || $mail->sent_at) return true;
            $now = Carbon::now()->timestamp;
            if ($mail->next_attempt_at > $now) return true;
            $mail->attempts++;
            try {
                $log = MailService::sendEmail($mail->params);
                $sent = empty($log['error']);
            } catch (\Throwable $e) {
                $sent = false;
            }
            $mail->queued_at = $now;
            $mail->next_attempt_at = $sent ? null : $now + [60, 300, 900, 3600][min(3, $mail->attempts - 1)];
            if ($sent) $mail->sent_at = $now;
            if (!$mail->save()) throw new \RuntimeException('退款邮件状态保存失败');
            return $sent;
        });
        if (!$success) throw new \RuntimeException('退款邮件发送失败，已保留待发记录并等待重试');
    }
}
