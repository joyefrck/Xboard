<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{CommissionLog, Order, OrderRefund, OrderRefundOrder, Plan, User, UserTrafficPackage};
use Brick\Math\BigInteger;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\{Crypt, DB};

class OrderRefundService
{
    private const USER_FIELDS = [
        'plan_id', 'expired_at', 'transfer_enable', 'u', 'd', 'group_id',
        'speed_limit', 'device_limit', 'next_reset_at', 'last_reset_at', 'custom_pending_order_id',
    ];

    public function preview(string $tradeNo): array
    {
        return $this->withLockedOrder($tradeNo, function (Order $order, ?User $user) {
            $this->validateOrder($order, $user);
            $at = Carbon::now()->timestamp;
            $scope = $this->scope($order, $user, $at);
            $quote = Crypt::encryptString(json_encode([
                'trade_no' => $order->trade_no,
                'calculated_at' => $at,
                'amount' => $scope['amount'],
                'state_hash' => $this->stateHash($scope),
            ], JSON_THROW_ON_ERROR));
            return [
                'trade_no' => $order->trade_no, 'user_id' => $user->id, 'email' => $user->email,
                'total_amount' => (int) $order->total_amount, 'balance_amount' => (int) $order->balance_amount,
                'handling_amount' => (int) $order->handling_amount,
                'amount' => $scope['amount'], 'base_amount' => $scope['base_amount'],
                'gross_amount' => $scope['gross_amount'], 'commission_deduction' => $scope['commission_deduction'],
                'commission_deductions' => $scope['commission_deductions'],
                'kind' => $scope['kind'], 'label' => $scope['label'], 'rule' => $scope['rule'],
                'related_orders' => $scope['related_orders'],
                'balance_before' => (int) $user->balance,
                'balance_after' => (int) $user->balance + $scope['amount'],
                'calculated_at' => $at, 'expires_at' => $at + 300, 'quote' => $quote,
            ];
        });
    }

    public function refund(string $tradeNo, int $operatorId, int $expectedAmount, string $reason, string $quote): OrderRefund
    {
        $reason = trim($reason);
        if ($operatorId <= 0 || mb_strlen($reason) < 2 || mb_strlen($reason) > 500 || $expectedAmount <= 0) {
            throw new ApiException('请确认退款金额并填写 2 至 500 字的退款原因');
        }
        try {
            $payload = json_decode(Crypt::decryptString($quote), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            throw new ApiException('退款预览无效，请重新核对');
        }
        if (!is_array($payload) || ($payload['trade_no'] ?? null) !== $tradeNo
            || ($payload['amount'] ?? null) !== $expectedAmount || !is_int($payload['calculated_at'] ?? null)
            || !is_string($payload['state_hash'] ?? null)) {
            throw new ApiException('退款预览与本次确认不一致，请重新核对');
        }

        $refund = $this->withLockedOrder($tradeNo, function (Order $order, ?User $user) use ($operatorId, $expectedAmount, $reason, $payload) {
            // A retry, including one from another order in the same subscription,
            // returns the original evidence before checking the quote expiry.
            $existing = $order->balanceRefund()->first();
            if ($existing) {
                if ($existing->amount !== $expectedAmount) {
                    throw new ApiException('订单已退款，金额与本次确认不一致，请刷新订单');
                }
                return $existing;
            }
            $now = Carbon::now()->timestamp;
            if ($payload['calculated_at'] > $now || $now - $payload['calculated_at'] > 300) {
                throw new ApiException('退款预览已过期，请重新核对');
            }
            $this->validateOrder($order, $user);
            $scope = $this->scope($order, $user, $payload['calculated_at']);
            if ($scope['amount'] !== $expectedAmount || !hash_equals($payload['state_hash'], $this->stateHash($scope))) {
                throw new ApiException('套餐、流量或订单信息已变化，请重新核对后退款');
            }
            $before = (int) $user->balance;
            $user->balance = $before + $expectedAmount;
            if ($scope['kind'] === 'subscription') {
                $user->fill([
                    'plan_id' => null, 'expired_at' => null, 'transfer_enable' => 0, 'u' => 0, 'd' => 0,
                    'group_id' => null, 'speed_limit' => null, 'device_limit' => null,
                    'next_reset_at' => null, 'last_reset_at' => null, 'custom_pending_order_id' => null,
                ]);
            } else {
                $package = UserTrafficPackage::whereKey($scope['state']['package']['id'])->lockForUpdate()->firstOrFail();
                $package->remaining_bytes = 0;
                $package->status = UserTrafficPackage::STATUS_DEPLETED;
                $package->depleted_at = $now;
                if (!$package->save()) throw new \RuntimeException('退款流量回收失败');
            }
            if (!$user->save()) throw new \RuntimeException('退款余额及套餐信息保存失败');
            // Other, separately purchased traffic packages retain their own access.
            app(TrafficPackageService::class)->syncAccessProfile($user);
            $commissions = array_column($scope['commission_deductions'], null, 'order_id');
            foreach ($scope['order_ids'] as $id) {
                $related = Order::whereKey($id)->lockForUpdate()->firstOrFail();
                $related->status = Order::STATUS_REFUNDED;
                // Already paid commission is withheld from this refund, not clawed
                // back from the inviter or falsely marked as an unpaid void.
                if (!$commissions[$id]['settled']) $related->commission_status = 3;
                if (!$related->save()) throw new \RuntimeException('退款订单保存失败');
            }
            $entries = OrderRefundReceiptService::allocate($expectedAmount, $scope);
            $refund = OrderRefund::create([
                'order_id' => $order->id, 'trade_no' => $order->trade_no, 'user_id' => $user->id,
                'operator_id' => $operatorId, 'amount' => $expectedAmount,
                'balance_before' => $before, 'balance_after' => (int) $user->balance, 'reason' => $reason,
                'snapshot' => ['calculated_at' => $payload['calculated_at'], ...$scope, 'receipt_entries' => $entries],
            ]);
            if (!$refund->exists) throw new \RuntimeException('退款记录保存失败');
            foreach ($entries as $entry) {
                $link = OrderRefundOrder::create(['refund_id' => $refund->id, ...$entry]);
                if (!$link->exists) throw new \RuntimeException('退款关联记录保存失败');
            }
            app(OrderRefundNotificationService::class)->capture($refund, $user, $entries);
            return $refund;
        });
        app(OrderRefundNotificationService::class)->enqueue($refund->id);
        return $refund;
    }

    private function withLockedOrder(string $tradeNo, callable $callback): mixed
    {
        $reference = Order::where('trade_no', $tradeNo)->first(['id', 'user_id']);
        if (!$reference) throw new ApiException('订单不存在');
        return DB::transaction(function () use ($reference, $callback) {
            // Match subscription creation and traffic consumption: user first.
            $user = User::whereKey($reference->user_id)->lockForUpdate()->first();
            $order = Order::whereKey($reference->id)->lockForUpdate()->first();
            if (!$order || (int) $order->user_id !== (int) $reference->user_id) throw new ApiException('订单信息已变化，请刷新');
            return $callback($order, $user);
        }, 3);
    }

    private function validateOrder(Order $order, ?User $user): void
    {
        if ((int) $order->status !== Order::STATUS_COMPLETED) throw new ApiException('仅已完成且未退款的订单可以退款到余额');
        if ((int) $order->type === Order::TYPE_RECHARGE || $order->period === Order::PERIOD_RECHARGE) throw new ApiException('充值订单已计入余额，不能再次退款到余额');
        if ((int) $order->type === Order::TYPE_RESET_TRAFFIC || PlanService::getPeriodKey($order->period) === Plan::PERIOD_RESET_TRAFFIC) throw new ApiException('流量重置订单没有独立可回收权益，不能单独自动退款');
        if (!$user) throw new ApiException('订单用户不存在，无法退款');
        if ($order->no_proration) throw new ApiException('该套餐不参与折抵，不能自动折算退款');
        if (Order::where('user_id', $user->id)->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])->lockForUpdate()->first(['id'])) throw new ApiException('该用户还有待支付或开通中的订单，请先处理后再退款');
    }

    private function scope(Order $order, User $user, int $at): array
    {
        $packages = UserTrafficPackage::where('user_id', $user->id)->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $package = $packages->first();
        if ($package) {
            if ($packages->count() !== 1 || $package->status !== UserTrafficPackage::STATUS_ACTIVE
                || $package->total_bytes <= 0 || $package->remaining_bytes <= 0 || $package->remaining_bytes > $package->total_bytes) throw new ApiException('该流量包没有可退款的剩余流量或台账异常');
            $base = (int) $order->total_amount + (int) $order->balance_amount;
            $amount = BigInteger::of($base)->multipliedBy($package->remaining_bytes)->quotient($package->total_bytes)->toInt();
            $orders = collect([$order]);
            $kind = 'traffic_package';
            $label = $package->trafficPackage?->name ?? $package->plan?->name ?? '独立流量包';
            $rule = '按剩余流量 / 总流量折算，仅回收本订单的剩余流量';
            $state = ['package' => $package->only(['id', 'order_id', 'plan_id', 'traffic_package_id', 'total_bytes', 'remaining_bytes', 'status'])];
        } else {
            $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
            if (!$plan || (int) $order->plan_id !== (int) $user->plan_id || $plan->isPrivate()
                || $user->custom_pending_order_id || (int) $user->transfer_enable <= 0
                || ($user->expired_at !== null && (int) $user->expired_at <= Carbon::now()->timestamp)) throw new ApiException('订单不属于当前可折算的有效套餐，不能回收其他套餐或已过期权益');
            $value = OrderService::subscriptionSurplus($user, $at);
            if (!in_array((int) $order->id, array_map('intval', $value['order_ids']), true)) throw new ApiException('该订单已不属于当前套餐的可折抵订单');
            $orders = Order::where('user_id', $user->id)->whereIn('id', $value['order_ids'])->orderBy('id')->lockForUpdate()->get();
            // Legacy non-expiring subscriptions must not absorb independently held packages.
            if (UserTrafficPackage::where('user_id', $user->id)->whereIn('order_id', $value['order_ids'])->lockForUpdate()->first(['id'])) throw new ApiException('旧版套餐与独立流量包存在混合关联，请先人工核对');
            $amount = $value['amount'];
            $base = $orders->sum(fn ($item) => (int) $item->total_amount + (int) $item->balance_amount + (int) $item->surplus_amount - (int) $item->refund_amount);
            if ($user->expired_at === null) {
                $last = $orders->filter(fn ($item) => PlanService::getPeriodKey($item->period) === Plan::PERIOD_ONETIME)->last();
                $base = $last ? (int) $last->total_amount + (int) $last->balance_amount : 0;
            }
            $kind = 'subscription'; $label = $plan->name;
            $rule = $user->expired_at === null ? '沿用换套餐的剩余流量折抵' : ((int) admin_setting('change_order_event_id', 0) === 1 ? '沿用换套餐折抵：剩余时间与流量比例取较小值' : '沿用换套餐折抵：按剩余时间比例');
            $state = ['user' => $user->only(self::USER_FIELDS), 'plan' => $plan->only(['id', 'name', 'plan_type', 'transfer_enable'])];
        }
        $commissionDeduction = 0;
        $commissions = [];
        foreach ($orders as $related) {
            if ((int) $related->status !== Order::STATUS_COMPLETED || (int) $related->total_amount < 0 || (int) $related->balance_amount < 0) throw new ApiException('关联订单状态或金额异常，请重新核对');
            if ((int) $related->commission_status === 1) throw new ApiException('关联订单佣金正在发放，请先处理佣金后再退款');
            $evidence = $this->commissionEvidence($related);
            $commissionDeduction += $evidence['amount'];
            $commissions[] = ['order_id' => (int) $related->id, 'trade_no' => $related->trade_no, ...$evidence];
        }
        if ($amount <= 0) throw new ApiException('按现有折抵规则计算后没有可退款的剩余价值');
        if ($base <= 0 || $amount > $base) throw new ApiException('套餐折抵金额异常，请先核对订单时间和金额');
        $grossAmount = $amount;
        $amount -= $commissionDeduction;
        if ($amount <= 0) throw new ApiException('扣除已结算佣金后没有可退款金额，请人工核对');
        if ($amount + (int) $user->balance > 2147483647) throw new ApiException('退款后余额超出允许范围');
        $state['orders'] = $orders->map(fn ($item) => $item->only(['id', 'plan_id', 'period', 'type', 'status', 'total_amount', 'balance_amount', 'surplus_amount', 'refund_amount', 'no_proration', 'commission_status', 'actual_commission_balance', 'created_at']))->values()->all();
        return [
            'kind' => $kind, 'label' => $label, 'rule' => $rule, 'amount' => $amount, 'base_amount' => (int) $base,
            'gross_amount' => $grossAmount, 'commission_deduction' => $commissionDeduction,
            'commission_deductions' => $commissions,
            'order_ids' => $orders->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'related_orders' => $orders->map(fn ($item) => ['id' => $item->id, 'trade_no' => $item->trade_no])->values()->all(),
            'state' => $state,
        ];
    }

    private function commissionEvidence(Order $order): array
    {
        $actual = (int) $order->actual_commission_balance;
        if ($actual < 0 || $actual > 2147483647) throw new ApiException('关联订单实际佣金金额异常，请人工核对');
        $logs = CommissionLog::where('trade_no', $order->trade_no)->orderBy('id')->lockForUpdate()->get(['id', 'get_amount']);
        $logged = 0;
        foreach ($logs as $log) {
            $paid = (int) $log->get_amount;
            if (!is_numeric($log->get_amount) || $paid != $log->get_amount || $paid < 0 || $paid > 2147483647 - $logged) {
                throw new ApiException('关联订单佣金流水金额异常，请人工核对');
            }
            $logged += $paid;
        }
        // These are two representations of the same payout, never two costs.
        // Legacy records may have only the field or only the ledger populated.
        if ($actual > 0 && $logged > 0 && $actual !== $logged) throw new ApiException('关联订单实发佣金与流水不一致，请人工核对');
        return [
            'amount' => max($actual, $logged),
            'settled' => (int) $order->commission_status === 2 || $actual > 0 || $logs->isNotEmpty(),
            'actual_commission_balance' => $actual, 'log_total' => $logged,
            'logs' => $logs->map(fn ($log) => ['id' => (int) $log->id, 'amount' => (int) $log->get_amount])->all(),
        ];
    }

    private function stateHash(array $scope): string
    {
        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }
}
