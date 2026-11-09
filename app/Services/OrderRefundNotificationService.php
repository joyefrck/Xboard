<?php

namespace App\Services;

use App\Jobs\SendRefundEmailJob;
use App\Models\{OrderRefund, OrderRefundMail, User};
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\{DB, Log};

class OrderRefundNotificationService
{
    /** Persist the notification intent inside the same transaction as the credit. */
    public function capture(OrderRefund $refund, User $user, array $entries): void
    {
        $date = fn ($at) => Carbon::createFromTimestamp($at)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $money = fn ($cents) => number_format($cents / 100, 2, '.', '');
        $orders = array_map(fn ($entry) => [
            'trade_no' => $entry['trade_no'], 'order_date' => $date($entry['order_created_at']),
            'order_amount' => $money($entry['order_amount']), 'balance_payment' => $money($entry['balance_payment']),
            'refund_amount' => $money($entry['amount']),
        ], $entries);
        $name = admin_setting('app_name', 'XBoard');
        $url = rtrim((string) admin_setting('app_url', ''), '/');
        $mail = OrderRefundMail::create([
            'refund_id' => $refund->id,
            'params' => [
                'email' => $user->email, 'subject' => $name . ' · 退款已到账户余额', 'template_name' => 'orderRefund',
                'template_value' => [
                    'name' => $name, 'orders' => $orders, 'refund_date' => $date($refund->created_at),
                    'refund_amount' => $money($refund->amount), 'order_amount' => $money(array_sum(array_column($entries, 'order_amount'))),
                    'url' => preg_match('~\Ahttps?://~i', $url) ? $url . '/app#/order' : null,
                ],
            ],
        ]);
        if (!$mail->exists) throw new \RuntimeException('退款通知记录保存失败');
    }

    /** Queue failure must never turn a committed refund into an apparent failure. */
    public function enqueue(int $refundId): void
    {
        try {
            DB::transaction(function () use ($refundId) {
                $mail = OrderRefundMail::where('refund_id', $refundId)->lockForUpdate()->first();
                $now = Carbon::now()->timestamp;
                if (!$mail || $mail->sent_at || $mail->next_attempt_at > $now || $mail->queued_at > $now - 600) return;
                $mail->queued_at = $now;
                if (!$mail->save()) throw new \RuntimeException('退款通知入队状态保存失败');
                app(Dispatcher::class)->dispatch((new SendRefundEmailJob($mail->id))->afterCommit());
            });
        } catch (\Throwable $e) {
            try {
                OrderRefundMail::where('refund_id', $refundId)->whereNull('sent_at')->update(['queued_at' => null]);
            } catch (\Throwable $stateError) {
                // The scheduler can reclaim the persisted intent after the lease expires.
            }
            Log::warning('Refund notification remains pending', ['refund_id' => $refundId, 'error_type' => get_class($e)]);
        }
    }

    public function retryPending(): void
    {
        $now = Carbon::now()->timestamp;
        OrderRefundMail::whereNull('sent_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<=', $now - 600))
            ->orderBy('id')->chunkById(100, function ($mails) {
                foreach ($mails as $mail) $this->enqueue((int) $mail->refund_id);
            });
    }
}
