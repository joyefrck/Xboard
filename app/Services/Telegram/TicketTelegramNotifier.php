<?php

namespace App\Services\Telegram;

use App\Jobs\SendTelegramJob;
use App\Jobs\SendTicketTelegramJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Utils\Helper;

class TicketTelegramNotifier
{
    public function send(Ticket $ticket): void
    {
        /** @var TicketMessage|null $message */
        $message = $ticket->messages()->latest()->first();
        $user = User::with('plan')->find($ticket->user_id);
        if (!$message || !$user) {
            return;
        }

        $total = $this->transferToGBString((float) ($user->transfer_enable ?? 0));
        $remaining = $this->transferToGBString((float) max(
            0,
            ($user->transfer_enable ?? 0) - ($user->u ?? 0) - ($user->d ?? 0)
        ));
        $upload = $this->transferToGBString((float) ($user->u ?? 0));
        $download = $this->transferToGBString((float) ($user->d ?? 0));
        $expiresAt = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
        $ip = request()->ip() ?? '';
        $region = $ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? (new \Ip2Region())->simple($ip)
            : '';

        $text = "📮 *工单提醒* #{$ticket->id}\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "📧 邮箱: `{$this->clean($user->email)}`\n";
        if ($region !== '') {
            $text .= "📍 位置: `{$this->clean($region)}`\n";
        }
        if ($user->plan) {
            $text .= "📦 套餐: `{$this->clean($user->plan->name)}`\n";
            $text .= "📊 流量: `{$remaining}G / {$total}G` (剩余/总计)\n";
            $text .= "⬆️⬇️ 已用: `{$upload}G / {$download}G`\n";
            $text .= "⏰ 到期: `{$expiresAt}`\n";
        } else {
            $text .= "📦 套餐: `未订购任何套餐`\n";
        }
        $text .= '💰 余额: `' . number_format(($user->balance ?? 0) / 100, 2) . "元`\n";
        $text .= '💸 佣金: `' . number_format(($user->commission_balance ?? 0) / 100, 2) . "元`\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "📝 *主题*: `{$this->clean($ticket->subject)}`\n";
        $text .= "💬 *内容*: `{$this->clean($message->message)}`\n";
        $text .= '↩️ 回复本消息即可回复工单';

        $options = $this->ticketActionKeyboard($ticket->id);
        $dedicated = (bool) admin_setting('telegram_ticket_bot_enable', false);
        $recipients = User::whereNotNull('telegram_id')
            ->where(fn ($query) => $query->where('is_admin', 1)->orWhere('is_staff', 1))
            ->get(['id', 'telegram_id']);

        foreach ($recipients as $recipient) {
            if ($dedicated) {
                SendTicketTelegramJob::dispatch(
                    (int) $recipient->id,
                    (int) $recipient->telegram_id,
                    $text,
                    $options
                );
                continue;
            }
            SendTelegramJob::dispatch((int) $recipient->telegram_id, $text, $options);
        }
    }

    public function ticketActionKeyboard(int $ticketId): array
    {
        return [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '查看记录', 'callback_data' => "ticket:view:{$ticketId}:1"],
                        ['text' => '回复', 'callback_data' => "ticket:reply:{$ticketId}"],
                        ['text' => '关闭', 'callback_data' => "ticket:close:{$ticketId}"],
                    ],
                    [
                        ['text' => '用户信息', 'callback_data' => "ticket:user:{$ticketId}"],
                        ['text' => '订单记录', 'callback_data' => "ticket:orders:{$ticketId}:1"],
                    ],
                ],
            ],
        ];
    }

    private function transferToGBString(float $value): string
    {
        return number_format(Helper::transferToGB($value), 2, '.', '');
    }

    private function clean(?string $value): string
    {
        $value = str_replace(['`', '*', '[', ']', '\\'], ["'", '', '(', ')', ''], trim((string) $value));
        return mb_strlen($value) > 800 ? mb_substr($value, 0, 797) . '...' : $value;
    }
}
