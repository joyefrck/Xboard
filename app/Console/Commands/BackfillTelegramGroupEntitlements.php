<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Models\TelegramGroupEntitlement;
use App\Models\UserTrafficPackage;
use App\Services\TelegramGroupEligibilityService;
use Illuminate\Console\Command;

class BackfillTelegramGroupEntitlements extends Command
{
    protected $signature = 'telegram:group-backfill {--apply : 写入预览对应资格} {--user-id=* : 人工确认的历史管理员发放用户 ID} {--operator-id= : 核对管理员 ID} {--reason= : 人工核对依据}';
    protected $description = '预览历史付费资格和待核对用户；默认不修改数据';

    public function handle(TelegramGroupEligibilityService $service): int
    {
        $manualIds = $this->option('user-id');
        if ($manualIds) {
            $operatorId = filter_var($this->option('operator-id'), FILTER_VALIDATE_INT);
            $reason = trim((string) $this->option('reason'));
            if (!$operatorId || !User::whereKey($operatorId)->where('is_admin', 1)->exists() || $reason === '' || mb_strlen($reason) > 255) {
                $this->error('人工补录必须填写有效管理员 --operator-id 及不超过 255 字的 --reason。');
                return self::FAILURE;
            }
            foreach ($manualIds as $id) {
                if (!ctype_digit((string) $id) || !User::whereKey($id)->exists()) {
                    $this->error('包含不存在或无效的用户 ID；未执行补录。');
                    return self::FAILURE;
                }
            }
            foreach (array_unique($manualIds) as $id) {
                $this->line("user_id={$id} source=historical_admin");
                if ($this->option('apply')) $service->grant((int) $id, 'historical_admin', null, $operatorId, $reason);
            }
        } else {
            $count = 0;
            Order::whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
                ->where(function ($query) {
                    $query->where(function ($paid) {
                        $paid->where('paid_at', '>', 0)->whereRaw('(COALESCE(total_amount, 0) + COALESCE(balance_amount, 0)) > 0');
                    })->orWhere('callback_no', 'manual_operation');
                })->orderBy('id')->chunkById(500, function ($orders) use ($service, &$count) {
                    foreach ($orders as $order) {
                        if (!User::whereKey($order->user_id)->exists() || TelegramGroupEntitlement::where('user_id', $order->user_id)->exists()) continue;
                        $source = TelegramGroupEligibilityService::isPaidOrder($order) ? 'paid_order' : 'admin_order';
                        $this->line("user_id={$order->user_id} source={$source} order_id={$order->id}");
                        if ($this->option('apply')) $service->grant((int) $order->user_id, $source, (int) $order->id);
                        $count++;
                    }
                });
            $this->info("可核验来源记录数量：{$count}（同一用户的多笔订单可能重复出现）。");
            // Only current evidence can identify candidates; removed historical grants need manual IDs.
            User::whereNotIn('id', TelegramGroupEntitlement::select('user_id'))
                ->whereNotIn('id', $service->paidOrders()->select('user_id'))
                ->whereNotIn('id', Order::select('user_id')->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])->where('callback_no', 'manual_operation'))
                ->where(function ($query) {
                    $query->whereNotNull('plan_id')->orWhereIn('id', UserTrafficPackage::select('user_id')->whereNull('order_id'));
                })->orderBy('id')->chunkById(500, function ($users) {
                    foreach ($users as $user) $this->line("needs_review user_id={$user->id}");
                });
        }
        $this->info($this->option('apply') ? '补录完成；来源不明的用户未自动授予资格。' : '仅预览，未修改数据。确认后添加 --apply；历史赠送用户使用 --user-id 人工补录。');
        return self::SUCCESS;
    }
}
