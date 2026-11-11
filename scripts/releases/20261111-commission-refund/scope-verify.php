<?php
declare(strict_types=1);
// Preview and render only: no refund, payout, SMTP or financial writes.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Order, CommissionLog, User};
use App\Services\{OrderService, OrderRefundService};

try {
    $phase = getopt('', ['phase:'])['phase'] ?? 'after';
    $order = Order::where('trade_no', '2026102717103834973698873')->firstOrFail();
    $user = User::findOrFail($order->user_id);
    $fields = ['balance', 'plan_id', 'expired_at', 'transfer_enable', 'group_id', 'speed_limit', 'device_limit'];
    $before = [$order->getAttributes(), $user->only($fields)];
    $logs = CommissionLog::where('trade_no', $order->trade_no)->orderBy('id')->get(['id', 'get_amount'])->toArray();
    if ((int) $order->id !== 24627 || (int) $order->status !== Order::STATUS_COMPLETED
        || (int) $order->commission_status !== 2 || (int) $order->actual_commission_balance !== 1000
        || array_sum(array_column($logs, 'get_amount')) != 1000) throw new RuntimeException('target_state_changed');
    $value = OrderService::subscriptionSurplus($user);
    if (array_map('intval', $value['order_ids']) !== [24627]) throw new RuntimeException('unexpected_order_scope');
    $preview = null;
    if ($phase === 'before') {
        try {
            app(OrderRefundService::class)->preview($order->trade_no);
            throw new RuntimeException('expected_previous_guard_not_reached');
        } catch (App\Exceptions\ApiException $error) {
            if ($error->getMessage() !== '关联订单佣金已结算，请先人工核对佣金') throw $error;
        }
    } else {
        $preview = app(OrderRefundService::class)->preview($order->trade_no);
        $value = OrderService::subscriptionSurplus($user, $preview['calculated_at']);
        if ($preview['gross_amount'] !== $value['amount'] || $preview['commission_deduction'] !== 1000
            || $preview['amount'] !== $preview['gross_amount'] - 1000 || $preview['amount'] <= 0
            || array_map('intval', array_column($preview['related_orders'], 'id')) !== [24627]) throw new RuntimeException('incorrect_net_refund');
        $template = 'mail.' . admin_setting('email_template', 'default') . '.orderRefund';
        if (!app('view')->exists($template)) $template = 'mail.default.orderRefund';
        $html = app('view')->make($template, ['name' => '验收', 'refund_date' => '2026-11-11 21:00:00',
            'refund_amount' => '73.49', 'gross_amount' => '83.49', 'commission_deduction' => '10.00',
            'order_amount' => '100.00', 'orders' => [['trade_no' => 'synthetic-receipt', 'order_date' => '2026-10-27 17:18:38',
                'order_amount' => '100.00', 'balance_payment' => '0.00', 'refund_amount' => '73.49']], 'url' => null])->render();
        if (!str_contains($html, '扣除已结算佣金') || !str_contains($html, '−10.00') || !str_contains($html, '+73.49')) throw new RuntimeException('receipt_template_failed');
    }
    if ($before !== [$order->fresh()->getAttributes(), $user->fresh()->only($fields)]
        || $logs !== CommissionLog::where('trade_no', $order->trade_no)->orderBy('id')->get(['id', 'get_amount'])->toArray()) throw new RuntimeException('financial_state_changed');
    echo json_encode(['ok' => true, 'phase' => $phase, 'order_ids' => $value['order_ids'],
        'gross_amount' => $preview['gross_amount'] ?? null, 'commission_deduction' => $preview['commission_deduction'] ?? null,
        'preview_amount' => $preview['amount'] ?? null, 'settlement_preserved' => true, 'balance_unchanged' => true,
        'receipt_rendered' => $phase !== 'before', 'no_refund_executed' => true], JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error_class' => get_class($error), 'source' => basename($error->getFile()), 'line' => $error->getLine()]) . "\n";
    exit(2);
}
