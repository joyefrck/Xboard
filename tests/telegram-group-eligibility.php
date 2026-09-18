<?php
require dirname(__DIR__) . '/vendor/autoload.php';
Illuminate\Database\Eloquent\Model::unguard();
use App\Models\Order;
use App\Models\User;
use App\Services\TelegramGroupEligibilityService as Eligibility;
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
foreach ([Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED] as $status) {
    $order = new Order(['status' => $status, 'paid_at' => 1700000000, 'total_amount' => 100, 'balance_amount' => 0]);
    check(Eligibility::isPaidOrder($order), 'historical paid order qualifies');
    $order->total_amount = 0; $order->balance_amount = 100;
    check(Eligibility::isPaidOrder($order), 'balance purchase qualifies');
    $order->balance_amount = 0;
    check(!Eligibility::isPaidOrder($order), 'zero cost orders do not qualify');
}
foreach ([0, 1, 2] as $status) check(!Eligibility::isPaidOrder(new Order(['status'=>$status,'paid_at'=>1700000000,'total_amount'=>100])), 'unsettled orders must not grant');
$user = new User(['plan_id'=>1, 'expired_at'=>100, 'transfer_enable'=>1024]);
check(!Eligibility::isAdminPlanGrant($user, ['plan_id'=>1,'expired_at'=>100,'transfer_enable'=>1024,'remarks'=>'edited']), 'unchanged plan is not a grant');
check(Eligibility::isAdminPlanGrant($user, ['plan_id'=>2]), 'new admin plan grants');
check(Eligibility::isAdminPlanGrant($user, ['expired_at'=>200]), 'admin renewal grants');
check(!Eligibility::isAdminPlanGrant($user, ['plan_id'=>null]), 'removing plan does not grant');
check(!Eligibility::isAdminPlanGrant(new User(['plan_id'=>null]), ['expired_at'=>200]), 'no plan cannot grant');
echo "Eligibility rules passed\n";
