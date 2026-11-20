<?php

namespace App\Services;

use App\Models\{Plan, User};
use Illuminate\Support\Facades\DB;

class TicketPlanAccessService
{
    private const PROFILE = ['id', 'name', 'group_id', 'speed_limit', 'device_limit'];

    public function __construct(private TrafficPackageService $traffic) {}

    /** Caller holds the user lock; lock the allowed products in a consistent order. */
    public function context(User $user): array
    {
        $plans = Plan::whereKey(config('ticket_bot.access_plan_ids', []))
            ->where('plan_type', Plan::TYPE_STANDARD)->whereNotNull('group_id')
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $purchased = Plan::find($user->plan_id);
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
        $restore = (int) $user->plan_id === $target;
        $current = $context['subscription_access_plan'];
        abort_if($current['id'] === $target && !($restore && $user->access_override_plan_id), 409, 'plan_access_already_set');
        $until = (int) $user->expired_at;
        if ($user->access_override_until > time()) {
            $until = min($until, (int) $user->access_override_until);
        }
        return [
            'purchased_plan' => $context['purchased_plan'], 'before' => $current,
            'after' => $to, 'previous_override_until' => $user->access_override_until,
            'override_until' => $restore ? null : $until, 'subscription_expires_at' => (int) $user->expired_at,
            'applies_to_current_traffic' => $context['effective_plan'] !== null,
            'current_profile' => $context['effective_profile'], 'charge_amount' => 0,
        ];
    }

    public function apply(User $user, array $change): void
    {
        $user->access_override_until = $change['override_until'];
        $user->access_override_plan_id = $user->access_override_until ? $change['after']['id'] : null;
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
