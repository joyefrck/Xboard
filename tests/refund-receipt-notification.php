<?php

declare(strict_types=1);
require __DIR__ . '/order-balance-refund.php';

use App\Http\Resources\OrderResource;
use App\Jobs\SendRefundEmailJob;
use App\Models\{Order, OrderRefundMail, OrderRefundOrder};
use App\Services\{OrderRefundNotificationService, OrderRefundReceiptService};
use Carbon\Carbon;
use Illuminate\Http\Request;

$startChecks = $checks;
// No SMTP calls: exercise the production job and outbox with a fake mail provider.
$behavior = 'success'; $sent = [];
$mailer = Mockery::mock('alias:App\Services\MailService');
$mailer->shouldReceive('sendEmail')->andReturnUsing(function ($params) use (&$behavior, &$sent) {
    $sent[] = $params;
    return ['error' => $behavior === 'success' ? null : 'fixture delivery failure'];
});
$GLOBALS['refund_dispatched'] = [];
$order = fixture(); $preview = $service->preview($order->trade_no);
$refund = refundPreview($preview);
$mail = OrderRefundMail::where('refund_id', $refund->id)->firstOrFail();
check(count($GLOBALS['refund_dispatched']) === 1, 'one job after refund');
$job = $GLOBALS['refund_dispatched'][0];
check($job instanceof SendRefundEmailJob && $job->queue === 'send_email' && $job->afterCommit === true, 'existing mail queue after commit');
check($mail->sent_at === null && count($sent) === 0, 'financial action does not call SMTP inline');
refundPreview($preview); check(count($GLOBALS['refund_dispatched']) === 1 && OrderRefundMail::where('refund_id',$refund->id)->count() === 1, 'retry produces one pending notice');
$entry = OrderRefundOrder::where('order_id',$order->id)->firstOrFail();
check($entry->amount === $refund->amount && $entry->order_amount === $order->total_amount, 'single order receipt amount and original captured');
$public = (new OrderResource(Order::with(['balanceRefund','balanceRefundEntry'])->find($order->id)))->toArray(new Request());
check($public['refund']['amount'] === $refund->amount && $public['refund']['destination'] === 'balance', 'safe refund summary');
check(!isset($public['balance_refund']) && !isset($public['balance_refund_entry']) && !str_contains(json_encode($public), 'operator_id'), 'public API does not expose administrator audit');
$job->handle(); $job->handle();
check(count($sent) === 1 && $mail->fresh()->sent_at !== null, 'duplicate job does not repeat a sent notification');
$values = $sent[0]['template_value'];
check($values['orders'][0]['trade_no'] === $order->trade_no && $values['orders'][0]['order_date'] === '2026-11-01 08:00:00', 'receipt number and order date in Beijing time');
check($values['orders'][0]['order_amount'] === '35.00' && $values['refund_amount'] === number_format($refund->amount/100,2,'.',''), 'receipt original and refund amounts');
check($values['refund_date'] === Carbon::createFromTimestamp($refund->created_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'), 'refund timestamp captured, not send time');

// Queue outage does not make the successful refund look failed; scheduler recovers it.
$GLOBALS['refund_dispatched'] = []; $GLOBALS['refund_queue_fail'] = true;
$order = fixture(); $preview = $service->preview($order->trade_no); $refund = refundPreview($preview);
check($order->fresh()->status === 5 && $order->user->fresh()->balance === 190+$preview['amount'], 'queue outage preserves committed refund');
check(OrderRefundMail::where('refund_id',$refund->id)->firstOrFail()->queued_at === null, 'queue outage leaves durable pending record');
$GLOBALS['refund_queue_fail'] = false;
$notifications = new OrderRefundNotificationService(); $notifications->retryPending();
check(count($GLOBALS['refund_dispatched']) === 1, 'scheduler recovers pending notice');
$job = $GLOBALS['refund_dispatched'][0]; $behavior = 'fail';
try { $job->handle(); throw new LogicException('failure not simulated'); } catch (RuntimeException $e) { check(!($e instanceof LogicException), 'SMTP failure triggers job retry'); }
$mail = OrderRefundMail::where('refund_id',$refund->id)->firstOrFail();
check($mail->sent_at === null && $mail->attempts === 1 && $mail->next_attempt_at > Carbon::now()->timestamp, 'failure persists retry metadata');
check($order->user->fresh()->balance === 190+$preview['amount'], 'mail failure cannot reverse or repeat credit');
$before = count($sent); $job->handle(); check(count($sent) === $before, 'early duplicate does not bypass retry delay');
Carbon::setTestNow(Carbon::now()->addMinute()); $behavior = 'success'; $job->handle(); $job->handle();
check($mail->fresh()->attempts === 2 && $mail->fresh()->sent_at !== null, 'mail succeeds on retry');
check($sent[array_key_last($sent)]['template_value']['refund_date'] === '2026-11-09 20:00:00', 'delayed mail retains refund date');
Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));

// Notification intent is part of the refund transaction; failed persistence sends nothing.
$order = fixture(); $preview = $service->preview($order->trade_no); $before = count($GLOBALS['refund_dispatched']);
OrderRefundMail::creating(static fn () => false);
try { refundPreview($preview); throw new LogicException('failure not injected'); } catch (RuntimeException $e) { check(!($e instanceof LogicException), 'notification write failure reached'); }
OrderRefundMail::flushEventListeners();
check($order->fresh()->status === 3 && $order->user->fresh()->balance === 190, 'outbox failure rolls entire refund back');
check($order->fresh()->balanceRefund === null && count($GLOBALS['refund_dispatched']) === $before, 'rolled back refund has no notification');

// Cent allocation is exact and deterministic even for a multi-order refund.
$state = []; $related = [];
foreach ([100,200,300] as $index=>$amount) {
    $state[] = ['id'=>$index+1,'total_amount'=>$amount,'balance_amount'=>0,'surplus_amount'=>0,'refund_amount'=>0,'created_at'=>1];
    $related[] = ['id'=>$index+1,'trade_no'=>'fixture-'.$index];
}
$entries = OrderRefundReceiptService::allocate(101, ['state'=>['orders'=>$state], 'related_orders'=>$related]);
check(array_column($entries,'amount') === [17,34,50], 'largest remainder allocation avoids rounding loss');
$order = fixture(); $other = Order::create(['user_id'=>$order->user_id,'plan_id'=>$order->plan_id,'trade_no'=>'notification-renewal','period'=>'monthly','type'=>Order::TYPE_RENEWAL,'total_amount'=>2000,'created_at'=>$order->created_at]);
$before = count($GLOBALS['refund_dispatched']); $refund = refundPreview($service->preview($order->trade_no));
check((int) OrderRefundOrder::where('refund_id',$refund->id)->sum('amount') === $refund->amount, 'source receipt amounts sum to single credit');
check(count($GLOBALS['refund_dispatched']) === $before+1, 'merged refund sends one email');
$mail = OrderRefundMail::where('refund_id',$refund->id)->firstOrFail();
check(count($mail->params['template_value']['orders']) === 2, 'merged email lists both source orders');
$amounts=[];
foreach([$order,$other] as $source) {
    $public=(new OrderResource(Order::with(['balanceRefund','balanceRefundEntry'])->find($source->id)))->toArray(new Request());
    $amounts[]=$public['refund']['amount']; check($public['refund']['group_order_count']===2, 'group context available');
}
check(array_sum($amounts)===$refund->amount, 'user rows never duplicate group total');
// Exercise the actual user endpoint: eager loading stays scoped to the owner.
$request = new Request(); $request->setUserResolver(fn () => $order->user->fresh());
$app->instance('request', $request);
$response = (new App\Http\Controllers\V1\User\OrderController())->fetch($request)->getData(true);
check(count($response['data']) === 2, 'user endpoint returns only owned source orders');
check(array_sum(array_column(array_column($response['data'],'refund'),'amount')) === $refund->amount, 'user endpoint returns per-order allocation');
check(!str_contains(json_encode($response),'operator_id') && !str_contains(json_encode($response),'template_value'), 'user endpoint excludes audit and notification payload');
// Admin list receives the same per-order amounts used by the user receipt.
$response = (new App\Http\Controllers\V2\Admin\OrderController())->fetch(new Request(['filter'=>[['id'=>'user_id','value'=>$order->user_id]]]))->getData(true);
check(count($response['data']) === 2, 'admin list includes both grouped source orders');
check(array_sum(array_column(array_column($response['data'],'balance_refund_entry'),'amount')) === $refund->amount, 'admin list provides allocation without duplicating group total');
$order=fixture(['commission_status'=>2,'actual_commission_balance'=>350]);
$preview=$service->preview($order->trade_no);$refund=refundPreview($preview);
$params=OrderRefundMail::where('refund_id',$refund->id)->firstOrFail()->params['template_value'];
check($params['commission_deduction']==='3.50'&&$params['gross_amount']===number_format($preview['gross_amount']/100,2,'.',''),'outbox captures gross refund and settled commission');
check($params['refund_amount']===number_format($preview['amount']/100,2,'.','')&&$params['orders'][0]['refund_amount']===$params['refund_amount'],'outbox and per-order receipts use actual net credit');
echo 'Refund receipts and notification: '.($checks-$startChecks)." checks passed\n";
