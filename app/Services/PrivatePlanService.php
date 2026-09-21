<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Private plans reuse normal orders and the existing admin user editor. */
class PrivatePlanService
{
    /** Save and deliver in one transaction, so a failed delivery never leaves a half-bound plan. */
    public function savePlan(array $params, bool $forceUpdate = false): Plan
    {
        return DB::transaction(function () use ($params, $forceUpdate) {
            $existing = !empty($params['id']) ? Plan::find($params['id']) : null;
            if (!empty($params['id']) && !$existing) {
                throw new ApiException('该订阅不存在');
            }
            $type = $params['plan_type'] ?? $existing?->plan_type ?? Plan::TYPE_STANDARD;
            $owner = null;
            if ($type === Plan::TYPE_EXCLUSIVE) {
                if (array_key_exists('owner_email', $params)) {
                    $email = trim((string) $params['owner_email']);
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new ApiException('请填写有效的绑定用户邮箱');
                    }
                    $matches = User::whereRaw('LOWER(email) = ?', [strtolower($email)])
                        ->lockForUpdate()->limit(2)->get();
                    if ($matches->count() !== 1) {
                        throw new ApiException('未找到唯一对应的用户，请检查绑定邮箱');
                    }
                    $owner = $matches->first();
                    $params['owner_user_id'] = $owner->id;
                } else {
                    $owner = User::whereKey($params['owner_user_id'] ?? $existing?->owner_user_id)
                        ->lockForUpdate()->first();
                }
                if (!$owner) throw new ApiException('请选择专属套餐绑定的用户');
            }
            unset($params['owner_email']);
            // Match the user editor's user -> plan lock order.
            $existing = $existing ? Plan::whereKey($existing->id)->lockForUpdate()->firstOrFail() : null;
            $prepared = $this->preparePlan($params, $existing);
            $plan = $existing ?? new Plan();
            $plan->fill($prepared);
            $plan->save();

            if ($plan->isExclusive()) {
                if ($owner->custom_pending_order_id || (int) $owner->plan_id !== (int) $plan->id) {
                    $assignment = $this->assignment($owner, ['plan_id' => $plan->id]);
                    $owner->update($assignment);
                    $owner->unsetRelation('plan');
                    app(TrafficResetService::class)->setInitialResetTime($owner);
                }
            }
            if ($forceUpdate && !$plan->isCustom()) {
                User::where('plan_id', $plan->id)->update([
                    'group_id' => $plan->group_id,
                    'transfer_enable' => $plan->transfer_enable * 1073741824,
                    'speed_limit' => $plan->speed_limit,
                    'device_limit' => $plan->device_limit,
                ]);
            }
            return $plan;
        });
    }

    public function preparePlan(array $params, ?Plan $existing = null): array
    {
        $plan = $existing ? clone $existing : new Plan();
        $plan->fill($params);
        $plan->plan_type ??= Plan::TYPE_STANDARD;
        if ($existing && $existing->plan_type !== $plan->plan_type
            && ($existing->users()->exists() || $existing->orders()->exists())) {
            throw new ApiException('已有用户或订单的套餐不能修改套餐类型');
        }
        if ($existing?->isExclusive() && (int) $existing->owner_user_id !== (int) $plan->owner_user_id) {
            throw new ApiException('专属套餐不能转移给其他用户，请新建套餐');
        }
        if ($plan->isPrivate() && (float) ($plan->prices[Plan::PERIOD_ONETIME] ?? 0) > 0) {
            throw new ApiException('私人定制和专属套餐仅支持周期订阅，不支持一次性流量包');
        }
        if ($plan->isCustom()) {
            $params['capacity_limit'] = null;
            $params['group_id'] = null;
            $params['owner_user_id'] = null;
            if ((float) ($plan->prices[Plan::PERIOD_RESET_TRAFFIC] ?? 0) > 0) {
                throw new ApiException('待人工开通的定制商品不支持购买流量重置');
            }
        } elseif ($plan->isExclusive()) {
            if (!$plan->owner_user_id || !User::whereKey($plan->owner_user_id)->exists()) {
                throw new ApiException('请选择专属套餐绑定的用户');
            }
            $this->assertExclusiveGroup($plan);
            $params['show'] = false;
            $params['sell'] = false;
        } else {
            $params['owner_user_id'] = null;
            if ($plan->group_id && Plan::where('plan_type', Plan::TYPE_EXCLUSIVE)
                ->where('group_id', $plan->group_id)->where('id', '!=', $plan->id ?? 0)->exists()) {
                throw new ApiException('该权限组已用于专属套餐，不能分配给普通套餐');
            }
        }
        return $params;
    }

    public function assertExclusiveGroup(Plan $plan, bool $requireNode = false): void
    {
        if (!$plan->group_id) {
            throw new ApiException('专属套餐必须使用独立权限组');
        }
        if (!ServerGroup::whereKey($plan->group_id)->lockForUpdate()->first()) {
            throw new ApiException('专属权限组不存在');
        }
        if (Plan::where('group_id', $plan->group_id)->where('id', '!=', $plan->id ?? 0)->exists()
            || User::where('group_id', $plan->group_id)->where('id', '!=', $plan->owner_user_id)->exists()) {
            throw new ApiException('该权限组已被其他套餐或用户使用，请创建独立权限组');
        }
        $nodes = Server::whereJsonContains('group_ids', (string) $plan->group_id)->get();
        if ($requireNode && !$nodes->contains(fn ($node) => (bool) $node->show)) {
            throw new ApiException('请先为专属权限组配置可用节点');
        }
        if ($nodes->contains(fn ($node) => count($node->group_ids ?? []) !== 1)) {
            throw new ApiException('专属节点不能同时绑定其他权限组');
        }
    }

    /** Called with the user locked, in the same transaction as the admin update. */
    public function assignment(User $user, array $params): array
    {
        if (array_key_exists('expected_custom_order_id', $params)) {
            if ((int) $params['expected_custom_order_id'] !== (int) $user->custom_pending_order_id) {
                throw new ApiException('用户定制状态已变更，请刷新后重试');
            }
            unset($params['expected_custom_order_id']);
        }
        if (!array_key_exists('plan_id', $params)) {
            if ($user->custom_pending_order_id) {
                return array_merge($params, $this->pendingAttributes($user));
            }
            return $params;
        }
        $plan = $params['plan_id'] ? Plan::lockForUpdate()->find($params['plan_id']) : null;
        if ($params['plan_id'] && !$plan) {
            throw new ApiException('订阅计划不存在');
        }
        if ($plan?->isExclusive()) {
            if ((int) $plan->owner_user_id !== (int) $user->id) {
                throw new ApiException('该专属套餐仅能分配给绑定用户');
            }
            if (!$user->custom_pending_order_id && (int) $user->plan_id !== (int) $plan->id) {
                throw new ApiException('该用户没有待开通的定制订单，请先购买私人定制');
            }
            $this->assertExclusiveGroup($plan, true);
        }
        if ($plan?->isCustom()) {
            if ((int) $user->plan_id !== (int) $plan->id || !$user->custom_pending_order_id) {
                throw new ApiException('私人定制商品须通过订单购买，开通时请选择用户专属套餐');
            }
            return array_merge($params, $this->pendingAttributes($user));
        }
        if ($user->custom_pending_order_id) {
            if (!$plan?->isExclusive()) {
                throw new ApiException('该用户正在等待定制开通，请选择绑定此用户的专属套餐；换购普通套餐请通过订单完成');
            }
            $order = Order::find($user->custom_pending_order_id);
            if (!$order || (int) $order->user_id !== (int) $user->id
                || (int) $order->status !== Order::STATUS_COMPLETED || !$order->no_proration) {
                throw new ApiException('待开通订单无效，请检查订单状态');
            }
            $months = OrderService::STR_TO_TIME[PlanService::getPeriodKey($order->period)] ?? null;
            if (!$months) {
                throw new ApiException('待开通订单的购买周期无效');
            }
            $params = array_merge($params, [
                'expired_at' => Carbon::now()->addMonths($months)->timestamp,
                'transfer_enable' => $plan->transfer_enable * 1073741824,
                'speed_limit' => $plan->speed_limit,
                'device_limit' => $plan->device_limit,
                'u' => 0,
                'd' => 0,
                'next_reset_at' => null,
                'last_reset_at' => time(),
                'custom_pending_order_id' => null,
            ]);
        }
        $params['group_id'] = $plan?->group_id;
        return $params;
    }

    private function pendingAttributes(User $user): array
    {
        return [
            'plan_id' => $user->plan_id,
            'group_id' => null,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'next_reset_at' => null,
        ];
    }
}
