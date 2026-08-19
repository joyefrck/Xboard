<?php

namespace App\Services\Telegram;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TrafficPackage;
use App\Models\User;
use App\Services\TelegramBotProfile;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Log;

class TicketTelegramHandler
{
    private const TICKET_HISTORY_PAGE_SIZE = 5;
    private const ORDER_PAGE_SIZE = 5;
    private const MAX_FIELD_LENGTH = 550;

    private ?TelegramService $telegram = null;

    public function __construct(private readonly TicketService $tickets)
    {
    }

    public function handle(object $msg): void
    {
        try {
            if ($msg->message_type === 'callback_query') {
                $this->handleCallback($msg);
                return;
            }

            if ($msg->message_type === 'reply_message' && $this->handleReply($msg)) {
                return;
            }

            match ($msg->command) {
                '/start' => $this->handleStart($msg),
                '/ticket' => $this->handleTicketCommand($msg),
                default => $this->send($msg, '仅支持 /ticket 工单ID 查看工单。'),
            };
        } catch (ApiException $e) {
            $this->auditFailure($msg, $e);
            if (($msg->message_type ?? '') === 'callback_query') {
                try {
                    $this->answer($msg, $e->getMessage(), true);
                } catch (\Throwable) {
                    // The callback may already have been acknowledged.
                }
            }
            $this->send($msg, $e->getMessage());
        } catch (\Throwable $e) {
            $this->auditFailure($msg, $e);
            $this->send($msg, '系统繁忙，请稍后重试');
        }
    }

    private function handleStart(object $msg): void
    {
        $operator = $this->getTicketOperator($msg);
        $this->send($msg, "工单机器人已就绪\n操作人：{$this->clean($operator->email)}\n使用 /ticket 工单ID 查看工单。");
        $this->audit($operator, 0, 'start', true);
    }

    private function handleTicketCommand(object $msg): void
    {
        $this->getTicketOperator($msg);
        $ticketId = isset($msg->args[0]) ? (int) $msg->args[0] : 0;
        $page = isset($msg->args[1]) ? max(1, (int) $msg->args[1]) : 1;
        if ($ticketId <= 0) {
            $this->send($msg, '用法：/ticket 工单ID，例如 /ticket 230');
            return;
        }
        $this->showTicketHistory($msg, $ticketId, $page);
    }

    private function handleCallback(object $msg): void
    {
        if (!str_starts_with((string) $msg->callback_data, 'ticket:')) {
            $this->answer($msg, '不支持的操作', true);
            return;
        }

        $parts = explode(':', $msg->callback_data);
        $action = $parts[1] ?? '';
        $ticketId = isset($parts[2]) ? (int) $parts[2] : 0;
        if ($ticketId <= 0) {
            $this->answer($msg, '工单参数无效', true);
            return;
        }

        match ($action) {
            'view' => $this->showTicketHistory($msg, $ticketId, max(1, (int) ($parts[3] ?? 1))),
            'reply' => $this->requestTicketReply($msg, $ticketId),
            'close' => $this->confirmTicketClose($msg, $ticketId),
            'close_confirm' => $this->closeTicket($msg, $ticketId),
            'close_cancel' => $this->cancelClose($msg, $ticketId),
            'user' => $this->showUser($msg, $ticketId),
            'orders' => $this->showOrders($msg, $ticketId, max(1, (int) ($parts[3] ?? 1))),
            default => $this->answer($msg, '未知工单操作', true),
        };
    }

    private function handleReply(object $msg): bool
    {
        if (!preg_match('/(📮.*?工单提醒.*?#?|工单ID: ?)(\d+)/', (string) ($msg->reply_text ?? ''), $matches)) {
            return false;
        }

        $operator = $this->getTicketOperator($msg);
        $ticketId = (int) $matches[2];
        $ticket = $this->findTicket($ticketId);
        if ($ticket->status === Ticket::STATUS_CLOSED) {
            throw new ApiException('工单已关闭，无法回复');
        }

        $message = trim((string) $msg->text);
        if ($message === '') {
            throw new ApiException('回复内容不能为空');
        }

        $this->tickets->replyByAdmin($ticketId, $message, $operator->id);
        $this->send($msg, "工单 #{$ticketId} 回复成功");
        $this->audit($operator, $ticketId, 'reply', true);
        return true;
    }

    private function getTicketOperator(object $msg): User
    {
        if (empty($msg->is_private)) {
            throw new ApiException('请在私聊中处理工单', 403);
        }
        if (empty($msg->from_id)) {
            throw new ApiException('无法识别 Telegram 操作人', 403);
        }

        $operator = User::where('telegram_id', $msg->from_id)
            ->where(fn($query) => $query->where('is_admin', 1)->orWhere('is_staff', 1))
            ->first();

        if (!$operator) {
            Log::warning('未授权的工单 Telegram 操作', [
                'bot' => TelegramBotProfile::TICKET->value,
                'telegram_id' => $msg->from_id,
                'callback' => $msg->callback_data ?? null,
            ]);
            throw new ApiException('你没有处理工单的权限', 403);
        }

        return $operator;
    }

    private function findTicket(int $ticketId, array $relations = ['user', 'messages.user']): Ticket
    {
        $ticket = Ticket::with($relations)->find($ticketId);
        if (!$ticket) {
            throw new ApiException('工单不存在', 404);
        }
        return $ticket;
    }

    private function showTicketHistory(object $msg, int $ticketId, int $page): void
    {
        $operator = $this->getTicketOperator($msg);
        $ticket = $this->findTicket($ticketId);
        $messages = $ticket->messages->sortBy('created_at')->values();
        $totalPages = max(1, (int) ceil($messages->count() / self::TICKET_HISTORY_PAGE_SIZE));
        $page = min(max(1, $page), $totalPages);

        $text = "📮 *工单 #{$ticket->id} 记录* ({$page}/{$totalPages})\n";
        $text .= "主题: {$this->clean($ticket->subject)}\n";
        $text .= '状态: ' . (Ticket::$statusMap[$ticket->status] ?? $ticket->status) . "\n";
        $text .= "用户: {$this->clean($ticket->user->email)}\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";

        foreach ($messages->forPage($page, self::TICKET_HISTORY_PAGE_SIZE) as $ticketMessage) {
            /** @var TicketMessage $ticketMessage */
            /** @var User|null $messageUser */
            $messageUser = $ticketMessage->user;
            $author = $ticketMessage->user_id === $ticket->user_id
                ? '用户'
                : ($messageUser?->is_admin ? '管理员' : '客服');
            $text .= '[' . $this->formatTime($ticketMessage->created_at) . "] {$author}\n";
            $text .= $this->clean($ticketMessage->message) . "\n\n";
        }

        $this->render($msg, trim($text), $this->ticketKeyboard($ticketId, $page, $totalPages));
        $this->audit($operator, $ticketId, 'view', true);
    }

    private function requestTicketReply(object $msg, int $ticketId): void
    {
        $operator = $this->getTicketOperator($msg);
        $ticket = $this->findTicket($ticketId);
        if ($ticket->status === Ticket::STATUS_CLOSED) {
            throw new ApiException('工单已关闭，无法回复');
        }

        $this->answer($msg, '请回复提示消息');
        $this->telegram()->sendMessage($msg->chat_id, "请回复这条消息发送工单回复\n工单ID: {$ticketId}", '', [
            'reply_markup' => [
                'force_reply' => true,
                'input_field_placeholder' => '输入回复内容',
            ],
        ]);
        $this->audit($operator, $ticketId, 'reply_prompt', true);
    }

    private function confirmTicketClose(object $msg, int $ticketId): void
    {
        $operator = $this->getTicketOperator($msg);
        $this->findTicket($ticketId);
        $this->answer($msg, '请确认关闭');
        $this->telegram()->sendMessage($msg->chat_id, "确认关闭工单 #{$ticketId}？", '', [
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => '确认关闭', 'callback_data' => "ticket:close_confirm:{$ticketId}"],
                    ['text' => '取消', 'callback_data' => "ticket:close_cancel:{$ticketId}"],
                ]],
            ],
        ]);
        $this->audit($operator, $ticketId, 'close_prompt', true);
    }

    private function closeTicket(object $msg, int $ticketId): void
    {
        $operator = $this->getTicketOperator($msg);
        $this->tickets->closeByAdmin($ticketId, $operator->id);
        $this->answer($msg, '工单已关闭');
        $this->send($msg, "工单 #{$ticketId} 已关闭");
        $this->audit($operator, $ticketId, 'close', true);
    }

    private function cancelClose(object $msg, int $ticketId): void
    {
        $operator = $this->getTicketOperator($msg);
        $this->answer($msg, '已取消');
        $this->send($msg, '已取消关闭工单');
        $this->audit($operator, $ticketId, 'close_cancel', true);
    }

    private function showUser(object $msg, int $ticketId): void
    {
        $operator = $this->getTicketOperator($msg);
        $ticket = Ticket::with(['user.plan', 'user.invite_user'])->find($ticketId);
        if (!$ticket || !$ticket->user) {
            throw new ApiException('工单或用户不存在', 404);
        }
        $user = $ticket->user;
        $total = (float) ($user->transfer_enable ?? 0);
        $used = (float) (($user->u ?? 0) + ($user->d ?? 0));

        $text = "👤 *用户信息*\n";
        $text .= "用户ID: `{$user->id}`\n";
        $text .= "邮箱: `{$this->clean($user->email)}`\n";
        $text .= '状态: `' . ($user->banned ? '已封禁' : '正常') . "`\n";
        $text .= '注册时间: `' . $this->formatTime($user->created_at) . "`\n";
        $text .= '最后登录: `' . $this->formatTime($user->last_login_at) . "`\n";
        $planName = $user->plan_id && $user->plan ? $user->plan->name : '无订阅';
        $text .= '套餐: `' . $this->clean($planName) . "`\n";
        $text .= '到期时间: `' . ($user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效') . "`\n";
        $text .= '流量: `' . $this->gb(max(0, $total - $used)) . 'G / ' . $this->gb($total) . "G` (剩余/总计)\n";
        $text .= '余额: `' . number_format(($user->balance ?? 0) / 100, 2) . "元`\n";
        $text .= '佣金: `' . number_format(($user->commission_balance ?? 0) / 100, 2) . "元`\n";
        $text .= '限速: `' . (($user->speed_limit ?? 0) ?: '不限') . " Mbps`\n";
        $text .= '设备限制: `' . (($user->device_limit ?? 0) ?: '不限') . "`\n";
        $inviterEmail = $user->invite_user_id && $user->invite_user
            ? $user->invite_user->email
            : '无';
        $text .= '邀请人: `' . $this->clean($inviterEmail) . '`';

        $this->render($msg, $text, $this->returnKeyboard($ticketId));
        $this->audit($operator, $ticketId, 'user', true);
    }

    private function showOrders(object $msg, int $ticketId, int $page): void
    {
        $operator = $this->getTicketOperator($msg);
        $ticket = $this->findTicket($ticketId, ['user']);
        $query = Order::with(['plan', 'trafficPackage', 'payment'])
            ->where('user_id', $ticket->user_id)
            ->latest('created_at');
        $total = (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / self::ORDER_PAGE_SIZE));
        $page = min(max(1, $page), $totalPages);
        $orders = $query->forPage($page, self::ORDER_PAGE_SIZE)->get();

        $text = "🧾 *订单记录* ({$page}/{$totalPages})\n";
        $text .= "用户: `{$this->clean($ticket->user->email)}`\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        if ($orders->isEmpty()) {
            $text .= '暂无订单记录';
        }

        foreach ($orders as $order) {
            /** @var TrafficPackage|null $trafficPackage */
            $trafficPackage = $order->trafficPackage;
            /** @var Plan|null $plan */
            $plan = $order->plan;
            $product = match (true) {
                $trafficPackage !== null => $trafficPackage->name,
                $plan !== null => $plan->name,
                default => '未知商品',
            };
            $text .= "订单: `{$this->clean($order->trade_no)}`\n";
            $text .= "商品: `{$this->clean($product)}`\n";
            $text .= '类型/状态: `' . (Order::$typeMap[$order->type] ?? $order->type) . ' / ' . (Order::$statusMap[$order->status] ?? $order->status) . "`\n";
            $text .= '金额: `' . number_format($order->total_amount / 100, 2) . "元`\n";
            $paymentName = $order->payment_id && $order->payment ? $order->payment->name : '未支付';
            $text .= '支付方式: `' . $this->clean($paymentName) . "`\n";
            $text .= '创建/支付: `' . $this->formatTime($order->created_at) . ' / ' . $this->formatTime($order->paid_at) . "`\n\n";
        }

        $this->render($msg, trim($text), $this->orderKeyboard($ticketId, $page, $totalPages));
        $this->audit($operator, $ticketId, 'orders', true);
    }

    private function ticketKeyboard(int $ticketId, int $page, int $totalPages): array
    {
        $pagination = [];
        if ($page > 1) {
            $pagination[] = ['text' => '上一页', 'callback_data' => "ticket:view:{$ticketId}:" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $pagination[] = ['text' => '下一页', 'callback_data' => "ticket:view:{$ticketId}:" . ($page + 1)];
        }

        $rows = $pagination ? [$pagination] : [];
        $rows[] = [
            ['text' => '回复', 'callback_data' => "ticket:reply:{$ticketId}"],
            ['text' => '关闭', 'callback_data' => "ticket:close:{$ticketId}"],
        ];
        $rows[] = [
            ['text' => '用户信息', 'callback_data' => "ticket:user:{$ticketId}"],
            ['text' => '订单记录', 'callback_data' => "ticket:orders:{$ticketId}:1"],
        ];

        return ['reply_markup' => ['inline_keyboard' => $rows]];
    }

    private function orderKeyboard(int $ticketId, int $page, int $totalPages): array
    {
        $row = [];
        if ($page > 1) {
            $row[] = ['text' => '上一页', 'callback_data' => "ticket:orders:{$ticketId}:" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $row[] = ['text' => '下一页', 'callback_data' => "ticket:orders:{$ticketId}:" . ($page + 1)];
        }
        $rows = $row ? [$row] : [];
        $rows[] = [['text' => '返回工单', 'callback_data' => "ticket:view:{$ticketId}:1"]];
        return ['reply_markup' => ['inline_keyboard' => $rows]];
    }

    private function returnKeyboard(int $ticketId): array
    {
        return ['reply_markup' => ['inline_keyboard' => [[
            ['text' => '返回工单', 'callback_data' => "ticket:view:{$ticketId}:1"],
            ['text' => '订单记录', 'callback_data' => "ticket:orders:{$ticketId}:1"],
        ]]]];
    }

    private function render(object $msg, string $text, array $options): void
    {
        $this->answer($msg, '已更新');
        if ($msg->message_type === 'callback_query' && $msg->message_id) {
            $this->telegram()->editMessageText($msg->chat_id, $msg->message_id, $text, 'markdown', $options);
            return;
        }
        $this->telegram()->sendMessage($msg->chat_id, $text, 'markdown', $options);
    }

    private function send(object $msg, string $text): void
    {
        if (!empty($msg->chat_id)) {
            $this->telegram()->sendMessage($msg->chat_id, $text, 'markdown');
        }
    }

    private function answer(object $msg, string $text, bool $alert = false): void
    {
        if (!empty($msg->callback_query_id)) {
            $this->telegram()->answerCallbackQuery($msg->callback_query_id, $text, $alert);
        }
    }

    private function telegram(): TelegramService
    {
        return $this->telegram ??= new TelegramService(profile: TelegramBotProfile::TICKET);
    }

    private function audit(User $operator, int $ticketId, string $action, bool $success): void
    {
        Log::info('工单 Telegram 操作', [
            'bot' => TelegramBotProfile::TICKET->value,
            'operator_id' => $operator->id,
            'telegram_id' => $operator->telegram_id,
            'ticket_id' => $ticketId,
            'action' => $action,
            'success' => $success,
        ]);
    }

    private function auditFailure(object $msg, \Throwable $error): void
    {
        $callbackParts = explode(':', (string) ($msg->callback_data ?? ''));
        $ticketId = isset($callbackParts[2])
            ? (int) $callbackParts[2]
            : (int) ($msg->args[0] ?? 0);
        if ($ticketId <= 0 && preg_match('/工单ID: ?(\d+)/', (string) ($msg->reply_text ?? ''), $matches)) {
            $ticketId = (int) $matches[1];
        }
        $telegramId = (int) ($msg->from_id ?? 0);

        Log::warning('工单 Telegram 操作失败', [
            'bot' => TelegramBotProfile::TICKET->value,
            'operator_id' => $telegramId > 0
                ? User::where('telegram_id', $telegramId)->value('id')
                : null,
            'telegram_id' => $telegramId ?: null,
            'ticket_id' => $ticketId,
            'action' => $callbackParts[1] ?? ($msg->command ?? $msg->message_type ?? 'unknown'),
            'success' => false,
            'error' => $error->getMessage(),
        ]);
    }

    private function clean(?string $text): string
    {
        $text = str_replace(['`', '*', '[', ']', '\\'], ["'", '', '(', ')', ''], trim((string) $text));
        return mb_strlen($text) > self::MAX_FIELD_LENGTH
            ? mb_substr($text, 0, self::MAX_FIELD_LENGTH - 3) . '...'
            : $text;
    }

    private function gb(float $value): string
    {
        return number_format(Helper::transferToGB($value), 2, '.', '');
    }

    private function formatTime(mixed $timestamp): string
    {
        return $timestamp ? date('Y-m-d H:i:s', (int) $timestamp) : '无';
    }
}
