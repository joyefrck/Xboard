<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\TelegramGroupEntitlement;
use Illuminate\Database\Eloquent\Builder;

class TelegramGroupEligibilityService
{
    public static function isPaidOrder(Order $order): bool
    {
        return in_array((int) $order->status, [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED], true)
            && (int) $order->paid_at > 0
            && (int) $order->total_amount + (int) $order->balance_amount > 0;
    }

    public function paidOrders(): Builder
    {
        return Order::whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
            ->where('paid_at', '>', 0)
            ->whereRaw('(COALESCE(total_amount, 0) + COALESCE(balance_amount, 0)) > 0');
    }

    public function grant(int $userId, string $source, ?int $sourceId = null, ?int $operatorId = null, ?string $reason = null): TelegramGroupEntitlement
    {
        // Unique user_id makes retries safe and preserves the first evidence of qualification.
        return TelegramGroupEntitlement::firstOrCreate(['user_id' => $userId], [
            'source' => $source, 'source_id' => $sourceId, 'operator_id' => $operatorId, 'reason' => $reason,
        ]);
    }

    public function grantFromOrder(Order $order): void
    {
        if (self::isPaidOrder($order)) {
            $this->grant((int) $order->user_id, 'paid_order', (int) $order->id);
        } elseif ($order->callback_no === 'manual_operation' && (int) $order->status === Order::STATUS_COMPLETED) {
            $this->grant((int) $order->user_id, 'admin_order', (int) $order->id, auth()->id());
        }
    }

    public function hasEntitlement(User $user): bool
    {
        return TelegramGroupEntitlement::where('user_id', $user->id)->exists();
    }

    public function eligible(User $user): bool
    {
        return !$user->banned && $this->hasEntitlement($user);
    }

    public static function isAdminPlanGrant(User $user, array $changes): bool
    {
        $planId = array_key_exists('plan_id', $changes) ? $changes['plan_id'] : $user->plan_id;
        if (!$planId) return false;
        if ((int) $planId !== (int) $user->plan_id) return true;
        // Admin forms submit unchanged fields too. Only an actual extension/increase qualifies.
        if (array_key_exists('expired_at', $changes) && $user->expired_at !== null) {
            if ($changes['expired_at'] === null || (int) $changes['expired_at'] > (int) $user->expired_at) return true;
        }
        return isset($changes['transfer_enable']) && (int) $changes['transfer_enable'] > (int) $user->transfer_enable;
    }
}
