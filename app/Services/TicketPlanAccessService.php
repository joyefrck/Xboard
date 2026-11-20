<?php

namespace App\Services;

use App\Models\{Order, Plan, User};
use Illuminate\Support\Facades\DB;

class TicketPlanAccessService
{
    private const PROFILE = ['id', 'name', 'group_id', 'speed_limit', 'device_limit', 'transfer_enable', 'reset_traffic_method'];

    public function __construct(private TrafficPackageService $traffic) {}

    /** Caller holds the user lock; lock the allowed products in a consistent order. */
    public function context(User $user): array
    {
        $plans = Plan::whereKey(config('ticket_bot.access_plan_ids', []))
            ->where('plan_type', Plan::TYPE_STANDARD)->whereNotNull('group_id')->where('transfer_enable', '>', 0)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $purchased = $plans->get($user->plan_id) ?? Plan::find($user->plan_id);
        $eligible = $plans->has($user->plan_id) && !$user->custom_pending_order_id
            && $user->expired_at !== null && $this->traffic->hasActivePlan($user);
        $tier = $user->access_override_plan_id ? $this->traffic->subscriptionAccessPlan($user) : $purchased;
        $effective = $this->traffic->hasUsablePlanBalance($user) ? $tier : null;
        return [
            'purchased_plan' => $purchased?->only(self::PROFILE),
            'effective_plan' => $effective?->only(self::PROFILE),
            'subscription_access_plan' => $tier?->only(self::PROFILE),
            'effective_profile' => $user->only(['group_id', 'speed_limit', 'device_limit']),
            'subscription_expires_at' => $user->expired_at,
            'override_until' => $user->access_override_until,
            'allowed_plans' => $eligible ? $plans->map(fn ($plan) => $plan->only(self::PROFILE))->values()->all() : [],
        ];
    }

    public function preview(User $user, int $target): array
    {
        $context = $this->context($user);
        $to = collect($context['allowed_plans'])->firstWhere('id', $target);
        abort_unless($to, 422, 'plan_access_unavailable');
        abort_if((int) $user->plan_id === $target && !$user->access_override_plan_id, 409, 'plan_access_already_set');
        $pendingOrder = $user->orders()->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereIn('type', [Order::TYPE_NEW_PURCHASE, Order::TYPE_RENEWAL, Order::TYPE_UPGRADE, Order::TYPE_RESET_TRAFFIC])
            ->lockForUpdate()->first(['id']);
        abort_if($pendingOrder, 409, 'subscription_order_pending');
        $quota = (int) $to['transfer_enable'] * TrafficPackageService::BYTES_PER_GB;
        $used = $user->getTotalUsedTraffic();
        return [
            'mode' => 'permanent', 'purchased_plan' => $context['purchased_plan'],
            'before' => $context['subscription_access_plan'], 'after' => $to,
            'previous_override_until' => $user->access_override_until, 'override_until' => null,
            'subscription_expires_at' => (int) $user->expired_at,
            'quota_before_bytes' => (int) $user->transfer_enable, 'quota_after_bytes' => $quota,
            'applies_to_current_traffic' => $used < $quota,
            'current_profile' => $context['effective_profile'], 'charge_amount' => 0,
        ];
    }

    public function apply(User $user, array $change): void
    {
        $target = $change['after']['id'];
        $user->support_plan_order_scope = [
            'plan_id' => $target,
            'through_id' => (int) $user->orders()->max('id'),
            'order_ids' => OrderService::currentPeriodOrders($user)->modelKeys(),
        ];
        $user->plan_id = $target;
        $user->transfer_enable = $change['quota_after_bytes'];
        $user->access_override_until = $user->access_override_plan_id = null;
        $user->saveOrFail();
        $this->traffic->syncAccessProfile($user);
    }

    public function expire(): void
    {
        User::where('access_override_until', '<=', time())->where('banned', false)
            ->select('id')->chunkById(200, function ($users) {
                foreach ($users as $candidate) {
                    DB::transaction(function () use ($candidate) {
                        $user = User::lockForUpdate()->find($candidate->id);
                        if (!$user || !$user->access_override_until || $user->access_override_until > time() || $user->banned) {
                            return;
                        }
                        $user->access_override_until = $user->access_override_plan_id = null;
                        $user->saveOrFail();
                        $this->traffic->syncAccessProfile($user);
                    }, 3);
                }
            });
    }
}
