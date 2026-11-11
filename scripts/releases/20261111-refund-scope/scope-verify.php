<?php
declare(strict_types=1);
// Read-only production acceptance: preview only; never calls refund or settlement.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Order, CommissionLog, User};
use App\Services\{OrderService, OrderRefundService};

try {
    $phase = getopt('', ['phase:'])['phase'] ?? 'after';
    $order = Order::where('trade_no', '2026111120115013686070119')->firstOrFail();
    $old = Order::findOrFail(22584);
    $user = User::findOrFail($order->user_id);
    $userFields = ['balance', 'plan_id', 'expired_at', 'transfer_enable', 'group_id', 'speed_limit', 'device_limit'];
    $before = [$order->getAttributes(), $old->getAttributes(), $user->only($userFields)];
    if ((int) $order->id !== 27808 || (int) $order->status !== Order::STATUS_COMPLETED
        || (int) $order->commission_status !== 3 || (int) $order->actual_commission_balance !== 0
        || CommissionLog::where('trade_no', $order->trade_no)->exists()) throw new RuntimeException('target_state_changed');
    if ((int) $old->user_id !== (int) $user->id || (int) $old->commission_status !== 2
        || (int) $old->actual_commission_balance !== 550
        || CommissionLog::where('trade_no', $old->trade_no)->count() !== 1) throw new RuntimeException('historical_settlement_changed');
    $value = OrderService::subscriptionSurplus($user);
    $preview = null;
    if ($phase === 'before') {
        if (array_map('intval', $value['order_ids']) !== [22584, 27808]) throw new RuntimeException('unexpected_before_scope');
        try {
            app(OrderRefundService::class)->preview($order->trade_no);
            throw new RuntimeException('expected_commission_guard_not_reached');
        } catch (App\Exceptions\ApiException $error) {
            if ($error->getMessage() !== '关联订单佣金已结算，请先人工核对佣金') throw $error;
        }
    } else {
        $preview = app(OrderRefundService::class)->preview($order->trade_no);
        if (array_map('intval', $value['order_ids']) !== [27808]
            || array_map('intval', array_column($preview['related_orders'], 'id')) !== [27808]
            || $preview['base_amount'] !== 5500 || $preview['amount'] <= 0 || $preview['amount'] > 5500) throw new RuntimeException('incorrect_refund_scope');
    }
    if ($before !== [$order->fresh()->getAttributes(), $old->fresh()->getAttributes(), $user->fresh()->only($userFields)]) throw new RuntimeException('read_only_state_changed');
    echo json_encode(['ok' => true, 'phase' => $phase, 'order_ids' => $value['order_ids'],
        'preview_amount' => $preview['amount'] ?? null, 'base_amount' => $preview['base_amount'] ?? null,
        'historical_commission_preserved' => true, 'balance_unchanged' => true, 'no_refund_executed' => true], JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error_class' => get_class($error), 'source' => basename($error->getFile()), 'line' => $error->getLine()]) . "\n";
    exit(2);
}
