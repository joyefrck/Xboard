<?php

declare(strict_types=1);
// Disposable fixtures. No app boot, .env, payment provider or node traffic.
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Exceptions\ApiException;
use App\Models\{CommissionLog, Order, OrderRefund, OrderRefundOrder, Plan, TrafficPackage, User, UserTrafficPackage};
use App\Services\{OrderRefundService, OrderService};
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Crypt, Facade};

function admin_setting($key = null, $default = null) { return $GLOBALS['refund_settings'][$key] ?? $default; }
date_default_timezone_set('UTC');
Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00', 'UTC'));
$db = new Capsule(); $dsn = getenv('ORDER_REFUND_DB_DSN') ?: '';
if ($dsn) {
    if (!preg_match('/\Amysql:host=127\.0\.0\.1;port=(\d+);dbname=order_refund_test\z/', $dsn, $match)) throw new RuntimeException('Disposable local order_refund_test database required');
    $db->addConnection(['driver'=>'mysql','host'=>'127.0.0.1','port'=>$match[1],'database'=>'order_refund_test','username'=>getenv('ORDER_REFUND_DB_USER') ?: 'root','password'=>getenv('ORDER_REFUND_DB_PASSWORD') ?: '']);
} else $db->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db->setEventDispatcher(new Illuminate\Events\Dispatcher($db->getContainer()));
$db->setAsGlobal(); $db->bootEloquent();
$app = $db->getContainer(); Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('app',$app); $app->instance('db',$db->getDatabaseManager()); $app->instance('db.schema',$db->schema());
$app->instance('encrypter',new Illuminate\Encryption\Encrypter(str_repeat('q',32),'AES-256-CBC'));
$app->instance('log',new Psr\Log\NullLogger());
$app->instance('translator',new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(),'en'));
$validator = new Illuminate\Validation\Factory($app['translator'],$app);
Request::macro('validate',function(array $rules)use($validator){return $validator->make($this->all(),$rules)->validate();});
$responseFactory=Mockery::mock(Illuminate\Contracts\Routing\ResponseFactory::class);
$responseFactory->shouldReceive('json')->andReturnUsing(fn($data=[],$status=200)=>new JsonResponse($data,$status));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class,$responseFactory);
$GLOBALS['refund_dispatched'] = [];
$app->instance(Illuminate\Contracts\Bus\Dispatcher::class, new class {
    public function dispatch($job) {
        if ($GLOBALS['refund_queue_fail'] ?? false) throw new RuntimeException('fixture queue unavailable');
        $GLOBALS['refund_dispatched'][] = $job;
        return count($GLOBALS['refund_dispatched']);
    }
});
$service=new OrderRefundService();
if (($argv[1]??'')==='worker') {
    try { $result=$service->refund($argv[2],99,(int)$argv[3],'并发折算退款',$argv[4]); echo json_encode(['ok'=>true,'id'=>$result->id]); }
    catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);} exit;
}
$schema=$db->schema();
foreach(['v2_order_refund_mail','v2_order_refund_order','v2_order_refund','v2_commission_log','v2_user_traffic_packages','v2_traffic_packages','v2_order','v2_plan','v2_user']as $name)$schema->dropIfExists($name);
$schema->create('v2_user',function(Blueprint $t){
    $t->increments('id');$t->string('email')->default('refund@example.test');$t->integer('balance')->default(190);$t->boolean('banned')->default(false);
    foreach(['plan_id','expired_at','group_id','speed_limit','device_limit','next_reset_at','last_reset_at','custom_pending_order_id','invite_user_id']as $field)$t->integer($field)->nullable();
    foreach(['u','d','transfer_enable']as $field)$t->bigInteger($field)->default(0);
    $t->integer('created_at')->nullable();$t->integer('updated_at')->nullable();
});
$schema->create('v2_plan',function(Blueprint $t){
    $t->increments('id');$t->string('name')->default('高级套餐');$t->string('plan_type')->default('standard');$t->integer('transfer_enable')->default(100);
    foreach(['group_id','speed_limit','device_limit']as $field)$t->integer($field)->default(1);
    $t->integer('created_at')->nullable();$t->integer('updated_at')->nullable();
});
$schema->create('v2_order',function(Blueprint $t){
    $t->increments('id');$t->integer('user_id');$t->string('trade_no',36)->unique();$t->integer('invite_user_id')->nullable();$t->integer('plan_id')->nullable();
    $t->string('period')->default('monthly');$t->integer('type')->default(1);$t->integer('status')->default(3);$t->boolean('no_proration')->default(false);$t->text('surplus_order_ids')->nullable();
    foreach(['total_amount','balance_amount','handling_amount','refund_amount','surplus_amount','commission_status','commission_balance','actual_commission_balance']as $field)$t->integer($field)->default(0);
    $t->integer('created_at')->nullable();$t->integer('updated_at')->nullable();
});
$schema->create('v2_commission_log',function(Blueprint $t){
    $t->increments('id');$t->string('trade_no');$t->integer('user_id');$t->integer('invite_user_id');
    foreach(['order_amount','get_amount','created_at','updated_at']as $field)$t->integer($field)->default(0);
});
$schema->create('v2_traffic_packages',function(Blueprint $t){
    $t->increments('id');$t->string('name')->default('独立流量包');foreach(['group_id','speed_limit','device_limit']as $field)$t->integer($field)->default(9);
    $t->integer('created_at')->nullable();$t->integer('updated_at')->nullable();
});
$schema->create('v2_user_traffic_packages',function(Blueprint $t){
    $t->increments('id');$t->integer('user_id');$t->integer('order_id')->nullable();$t->integer('plan_id')->nullable();$t->integer('traffic_package_id')->nullable();
    $t->bigInteger('total_bytes');$t->bigInteger('remaining_bytes');$t->string('status')->default('active');$t->integer('depleted_at')->nullable();
    $t->integer('created_at')->nullable();$t->integer('updated_at')->nullable();
});
$migration=require dirname(__DIR__).'/database/migrations/2026_11_09_000001_create_order_refunds.php';$migration->up();
// Verify the incremental migration retains earlier audit lookups.
$db->table('v2_order_refund')->insert(['id'=>100,'order_id'=>100,'trade_no'=>'old-refund','user_id'=>100,'operator_id'=>99,'amount'=>100,'balance_before'=>0,'balance_after'=>100,'reason'=>'旧记录','created_at'=>1,'updated_at'=>1]);
$upgrade=require dirname(__DIR__).'/database/migrations/2026_11_09_000002_add_refund_entitlement_snapshots.php';$upgrade->up();
$receiptMigration=require dirname(__DIR__).'/database/migrations/2026_11_09_000003_add_refund_receipts_and_mail.php';$receiptMigration->up();
$checks=0;
function check($value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function rejected(callable $call,string $message):void{try{$call();}catch(ApiException|Illuminate\Validation\ValidationException $e){check(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
check($db->table('v2_order_refund_order')->where('order_id',100)->value('refund_id')===100,'older refunds backfilled');
$db->table('v2_order_refund_order')->delete();$db->table('v2_order_refund')->delete();
$plan=Plan::create();$otherPlan=Plan::create(['name'=>'其他套餐']);$traffic=TrafficPackage::create();
function fixture(array $data=[]):Order{
    global $plan;
    $user=User::create(['plan_id'=>$plan->id,'expired_at'=>Carbon::parse('2026-12-01')->timestamp,'transfer_enable'=>100*1073741824,'u'=>10*1073741824,'d'=>0,'group_id'=>1,'speed_limit'=>1,'device_limit'=>1,'next_reset_at'=>Carbon::parse('2026-12-01')->timestamp,'last_reset_at'=>Carbon::parse('2026-11-01')->timestamp]);
    return Order::create(array_merge(['user_id'=>$user->id,'plan_id'=>$plan->id,'trade_no'=>'refund-'. $user->id,'total_amount'=>3500,'created_at'=>Carbon::parse('2026-11-01')->timestamp],$data));
}
function packageFor(Order $order,int $remaining=25,int $total=100):UserTrafficPackage{
    global $traffic;return UserTrafficPackage::create(['user_id'=>$order->user_id,'order_id'=>$order->id,'traffic_package_id'=>$traffic->id,'total_bytes'=>$total*1073741824,'remaining_bytes'=>$remaining*1073741824]);
}
function refundPreview(array $p,string $reason='用户申请折算退款'):OrderRefund{global $service;return $service->refund($p['trade_no'],99,$p['amount'],$reason,$p['quote']);}
function oracle(User $user):Order{
    global $otherPlan;
    $next=new Order(['plan_id'=>$otherPlan->id,'period'=>'monthly','total_amount'=>1000000]);
    (new OrderService($next))->setOrderType($user);return $next;
}
$controller=new App\Http\Controllers\V2\Admin\OrderController();
$request=function(array $data):Request{$r=Request::create('/fixture','POST',$data);$r->setUserResolver(fn()=>(new User())->forceFill(['id'=>99]));return $r;};
// Real purchase-flow oracle: refund uses the same calculation, including prior credits.
foreach([0,1]as $trafficAware)foreach([[3500,1000,0,0],[0,3500,0,0],[1000,200,3000,200]]as [$paid,$balance,$surplus,$oldRefund]){
    $GLOBALS['refund_settings']=['change_order_event_id'=>$trafficAware];
    $order=fixture(['total_amount'=>$paid,'balance_amount'=>$balance,'surplus_amount'=>$surplus,'refund_amount'=>$oldRefund,'handling_amount'=>175]);
    $user=$order->user;$user->u=80*1073741824;$user->save();
    $expected=oracle($user->fresh());$p=$service->preview($order->trade_no);
    check($p['amount']===(int)$expected->surplus_amount,'matches actual new-plan purchase calculation');
    check($p['amount']<$p['base_amount']&&$p['base_amount']===$paid+$balance+$surplus-$oldRefund,'uses remaining value including earlier credits, excludes fees');
    check($user->fresh()->balance===190&&$order->fresh()->status===3,'preview read only');
    $data=['trade_no'=>$order->trade_no,'expected_amount'=>$p['amount'],'quote'=>$p['quote'],'confirmed'=>true,'reason'=>'用户申请退款'];
    foreach(['quote','confirmed','reason','expected_amount']as $field){$bad=$data;unset($bad[$field]);rejected(fn()=>$controller->refund($request($bad)),'requires '.$field);}
    $record=$controller->refund($request($data))->getData(true)['data'];$u=$user->fresh();
    check($record['amount']===$p['amount']&&$u->balance===190+$p['amount'],'only prorated amount credited');
    foreach(['plan_id','expired_at','next_reset_at','last_reset_at','group_id','speed_limit','device_limit','custom_pending_order_id']as $field)check($u->$field===null,'subscription field cleared: '.$field);
    foreach(['transfer_enable','u','d']as $field)check($u->$field===0,'traffic field cleared: '.$field);
    check($order->fresh()->status===5&&$order->fresh()->commission_status===3,'order closed, commission void');
    check($order->fresh()->refund_amount===$oldRefund,'legacy refund field preserved');
    $audit=$order->fresh()->balanceRefund;
    check($audit->snapshot['kind']==='subscription'&&$audit->snapshot['state']['user']['plan_id']===$plan->id,'removed entitlement audited');
    Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));
    check(refundPreview($p)->id===$audit->id&&$user->fresh()->balance===190+$p['amount'],'expired-quote retry is idempotent');
    Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));
}
$GLOBALS['refund_settings']=[];
// A purchase after expiry starts a new entitlement even if old orders remain completed.
$old=fixture(['created_at'=>Carbon::parse('2026-10-01')->timestamp,'total_amount'=>5500,'commission_status'=>2,'actual_commission_balance'=>550]);
CommissionLog::create(['trade_no'=>$old->trade_no,'user_id'=>$old->user_id,'invite_user_id'=>99,'get_amount'=>550]);
$current=Order::create(['user_id'=>$old->user_id,'plan_id'=>$plan->id,'trade_no'=>'purchase-after-expiry','period'=>'monthly','type'=>1,'total_amount'=>5500,'commission_status'=>3,'created_at'=>Carbon::parse('2026-11-08')->timestamp]);
$u=$old->user;$u->expired_at=Carbon::parse('2026-12-08')->timestamp;$u->save();
$value=OrderService::subscriptionSurplus($u);
check($value['order_ids']===[$current->id],'new purchase excludes expired settled order from subscription scope');
$p=$service->preview($current->trade_no);
$expected=(int)(5500*(Carbon::parse('2026-12-08')->timestamp-Carbon::now()->timestamp)/(30*86400));
check($p['amount']===$expected&&$p['base_amount']===5500,'new purchase uses its own start date and principal');
check(oracle($u)->surplus_order_ids===[$current->id],'change-plan purchase uses the same current entitlement');
$next=new Order(['plan_id'=>$plan->id,'period'=>'monthly','total_amount'=>5500]);(new OrderService($next))->setOrderType($u);
check($next->type===Order::TYPE_RENEWAL&&$next->surplus_order_ids===[$current->id],'early renewal excludes expired orders too');
rejected(fn()=>$service->preview($old->trade_no),'expired purchase cannot refund current entitlement');
refundPreview($p);
check($old->fresh()->status===3&&$old->fresh()->commission_status===2&&$old->fresh()->actual_commission_balance===550,'historical order and settlement preserved');
check(CommissionLog::where('trade_no',$old->trade_no)->count()===1,'historical commission ledger preserved');
// Settled commission is a cost deducted once from the remaining-value refund.
$inviter=User::create(['balance'=>9000]);
$o=fixture(['commission_status'=>2,'commission_balance'=>900,'actual_commission_balance'=>350,'invite_user_id'=>$inviter->id]);
$log=CommissionLog::create(['trade_no'=>$o->trade_no,'user_id'=>$o->user_id,'invite_user_id'=>$inviter->id,'get_amount'=>350]);
$gross=OrderService::subscriptionSurplus($o->user)['amount'];$p=$service->preview($o->trade_no);
check($p['gross_amount']===$gross&&$p['commission_deduction']===350&&$p['amount']===$gross-350,'deduct actual settled commission once, not expected commission or two copies');
$r=refundPreview($p);
check($o->user->fresh()->balance===190+$gross-350&&$r->snapshot['commission_deduction']===350,'credit net amount and audit deduction');
check($o->fresh()->commission_status===2&&$o->fresh()->actual_commission_balance===350&&$log->fresh()->get_amount===350&&$inviter->fresh()->balance===9000,'preserve settled status, ledger and inviter balance');
check(refundPreview($p)->id===$r->id&&$o->user->fresh()->balance===190+$gross-350,'settled refund retries neither credit nor deduct twice');
foreach([[0,100],[100,0],[100,100],[0,0]]as [$actual,$logged]){
    $o=fixture(['commission_status'=>2,'actual_commission_balance'=>$actual]);
    if($logged)CommissionLog::create(['trade_no'=>$o->trade_no,'user_id'=>$o->user_id,'invite_user_id'=>99,'get_amount'=>$logged]);
    $p=$service->preview($o->trade_no);check($p['commission_deduction']===max($actual,$logged),'legacy single evidence source and zero commission supported');
}
$o=fixture(['commission_status'=>3,'actual_commission_balance'=>100]);$p=$service->preview($o->trade_no);
check($p['commission_deduction']===100,'void flag cannot hide historically paid commission');
$o=fixture(['commission_balance'=>900]);$p=$service->preview($o->trade_no);
check($p['commission_deduction']===0&&$p['gross_amount']===$p['amount'],'unpaid expected commission is not withheld');
$o=fixture(['commission_status'=>2,'actual_commission_balance'=>100]);
foreach([70,30]as $paid)CommissionLog::create(['trade_no'=>$o->trade_no,'user_id'=>$o->user_id,'invite_user_id'=>99,'get_amount'=>$paid]);
$p=$service->preview($o->trade_no);check($p['commission_deduction']===100&&count($p['commission_deductions'][0]['logs'])===2,'multi-level payouts are summed then deducted once');
foreach([[-1,0],[100,101],[0,-1]]as [$actual,$logged]){
    $o=fixture(['commission_status'=>2,'actual_commission_balance'=>$actual]);
    if($logged)CommissionLog::create(['trade_no'=>$o->trade_no,'user_id'=>$o->user_id,'invite_user_id'=>99,'get_amount'=>$logged]);
    rejected(fn()=>$service->preview($o->trade_no),'invalid or inconsistent commission evidence');
}
$o=fixture(['commission_status'=>2]);$gross=OrderService::subscriptionSurplus($o->user)['amount'];
foreach([$gross,$gross+1]as $fee){$o->actual_commission_balance=$fee;$o->save();rejected(fn()=>$service->preview($o->trade_no),'no zero or negative net refund');}
$o=fixture(['commission_status'=>2,'actual_commission_balance'=>150]);
$related=Order::create(['user_id'=>$o->user_id,'plan_id'=>$plan->id,'trade_no'=>'commission-group','period'=>'monthly','type'=>2,'total_amount'=>2000,'commission_status'=>2,'actual_commission_balance'=>250,'created_at'=>$o->created_at]);
$p=$service->preview($o->trade_no);check($p['commission_deduction']===400&&count($p['commission_deductions'])===2,'group deducts only its associated commissions');
$r=refundPreview($p);check((int)OrderRefundOrder::where('refund_id',$r->id)->sum('amount')===$p['amount']&&$related->fresh()->commission_status===2,'group receipt allocations sum to net refund and preserve settlement');
$o=fixture(['type'=>5,'period'=>'traffic_package','total_amount'=>4000,'commission_status'=>2,'actual_commission_balance'=>200]);$pkg=packageFor($o,25);
$p=$service->preview($o->trade_no);check($p['gross_amount']===1000&&$p['amount']===800,'traffic package deducts paid commission after proration');refundPreview($p);
check($pkg->fresh()->remaining_bytes===0,'net package refund still revokes only remaining package');
foreach(['amount','new_zero_log']as $change){
    $o=fixture(['commission_status'=>2,'actual_commission_balance'=>100]);$p=$service->preview($o->trade_no);
    if($change==='amount')Order::whereKey($o->id)->increment('actual_commission_balance');
    else CommissionLog::create(['trade_no'=>$o->trade_no,'user_id'=>$o->user_id,'invite_user_id'=>99,'get_amount'=>0]);
    rejected(fn()=>refundPreview($p),'commission evidence change invalidates quote: '.$change);
    check($o->user->fresh()->balance===190&&$o->fresh()->status===3,'changed commission leaves money and entitlement untouched');
}
// Multiple still-valid renewal orders are one subscription and one refund.
$order=fixture();$related=Order::create(['user_id'=>$order->user_id,'plan_id'=>$plan->id,'trade_no'=>'renewal-group','period'=>'monthly','type'=>2,'total_amount'=>2000,'created_at'=>Carbon::parse('2026-11-02')->timestamp]);
$packageOrder=Order::create(['user_id'=>$order->user_id,'trade_no'=>'independent-package','period'=>'traffic_package','type'=>5,'total_amount'=>5000]);$independent=packageFor($packageOrder,50);
$p=$service->preview($order->trade_no);$alternate=$service->preview($related->trade_no);
check(count($p['related_orders'])===2&&$p['amount']===$alternate['amount'],'same subscription aggregates renewals');
$r=refundPreview($p);check(refundPreview($alternate)->id===$r->id,'concurrent alternate-row preview returns original group refund');
check($related->fresh()->status===5&&$related->fresh()->balanceRefund->id===$r->id,'every related order has the same audit');
check($independent->fresh()->remaining_bytes===50*1073741824&&$packageOrder->fresh()->status===3,'separately purchased package preserved');
check($order->user->fresh()->plan_id===null&&$order->user->fresh()->group_id===9,'subscription removed, independent package retains access profile');
// Traffic package refund removes only its own balance; original subscription remains.
foreach([false,true]as $legacy){
    $o=fixture(['type'=>5,'period'=>$legacy?'onetime':'traffic_package','total_amount'=>3500,'balance_amount'=>500]);$pkg=packageFor($o,25);
    $before=$o->user->only(['plan_id','expired_at','transfer_enable','u','d']);
    $otherOrder=Order::create(['user_id'=>$o->user_id,'trade_no'=>'other-package-'.$o->id,'type'=>5,'period'=>'traffic_package','total_amount'=>1000]);$other=packageFor($otherOrder,70);
    $p=$service->preview($o->trade_no);check($p['amount']===1000&&$p['kind']==='traffic_package','remaining quarter refunded');
    refundPreview($p);check($pkg->fresh()->remaining_bytes===0&&$pkg->fresh()->status==='depleted','matching package revoked');
    check($o->user->fresh()->only(array_keys($before))===$before&&$other->fresh()->remaining_bytes===70*1073741824,'other subscription and package unchanged');
}
// Byte totals can exceed double precision; refund cents must still round down exactly.
$o=fixture(['type'=>5,'period'=>'traffic_package']);$pkg=packageFor($o);
$pkg->total_bytes=9000000000000000000;$pkg->remaining_bytes=8999999999999999999;$pkg->save();
$p=$service->preview($o->trade_no);check($p['amount']===3499,'large byte ratio uses exact integer arithmetic');
refundPreview($p);check($o->user->fresh()->balance===3689,'large ratio cannot round refund up by one cent');
// A zero-cash renewal still carries forward earlier paid subscription value.
$o=fixture(['total_amount'=>0,'balance_amount'=>0,'surplus_amount'=>4000]);
$p=$service->preview($o->trade_no);check($p['amount']>0&&$p['amount']<4000,'credit-only renewal can refund residual value');refundPreview($p);
check(Order::with('balanceRefund')->find($o->id)->balanceRefund->amount===$p['amount'],'eager audit relation works in the admin order list');
// Old non-expiring plan without a modern package ledger follows legacy proration.
$o=fixture(['type'=>5,'period'=>'onetime','total_amount'=>4000]);$u=$o->user;$u->expired_at=null;$u->u=75*1073741824;$u->save();
$p=$service->preview($o->trade_no);check($p['amount']===1000,'legacy non-expiring plan has quarter residual');refundPreview($p);check($u->fresh()->transfer_enable===0&&$u->fresh()->plan_id===null,'legacy plan revoked');
// Requotes reject stale usage/entitlement/amount/source-order snapshots.
foreach(['u','expired_at','total_amount','new_order']as $field){
    $o=fixture();$p=$service->preview($o->trade_no);
    if($field==='u')User::whereKey($o->user_id)->increment('u',1);
    if($field==='expired_at')User::whereKey($o->user_id)->increment('expired_at',1);
    if($field==='total_amount')Order::whereKey($o->id)->increment('total_amount',1);
    if($field==='new_order')Order::create(['user_id'=>$o->user_id,'plan_id'=>$plan->id,'trade_no'=>'new-source-'.$o->id,'total_amount'=>1,'period'=>'monthly']);
    rejected(fn()=>refundPreview($p),'changed '.$field.' invalidates quote');check($o->user->fresh()->balance===190&&$o->fresh()->status===3,'stale quote causes no changes');
}
$o=fixture();$p=$service->preview($o->trade_no);User::whereKey($o->user_id)->increment('balance',100);
refundPreview($p);check($o->user->fresh()->balance===290+$p['amount'],'unrelated top-up does not invalidate quote or lose money');
$o=fixture();$p=$service->preview($o->trade_no);$bad=$p;$bad['quote'].='tampered';rejected(fn()=>refundPreview($bad),'tampered quote');
$bad=$p;$bad['amount']++;rejected(fn()=>refundPreview($bad),'changed expected amount');
Carbon::setTestNow(Carbon::parse('2026-11-09 12:06:00'));rejected(fn()=>refundPreview($p),'expired quote');Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00'));
foreach([0,1,2,4,5]as $status){$o=fixture(['status'=>$status]);rejected(fn()=>$service->preview($o->trade_no),'status '.$status);}
foreach([['type'=>6,'period'=>'recharge'],['type'=>4,'period'=>'reset_traffic'],['total_amount'=>0],['total_amount'=>-1],['commission_status'=>1],['no_proration'=>true],['plan_id'=>$otherPlan->id]]as $fields){$o=fixture($fields);rejected(fn()=>$service->preview($o->trade_no),'ineligible '.json_encode($fields));}
$o=fixture();$u=$o->user;$u->expired_at=Carbon::now()->timestamp-1;$u->save();rejected(fn()=>$service->preview($o->trade_no),'expired subscription');
$o=fixture();$GLOBALS['refund_settings']=['surplus_enable'=>0];rejected(fn()=>$service->preview($o->trade_no),'system proration disabled');$GLOBALS['refund_settings']=[];
$o=fixture();$private=Plan::create(['plan_type'=>'custom']);$u=$o->user;$u->plan_id=$private->id;$u->save();$o->plan_id=$private->id;$o->save();rejected(fn()=>$service->preview($o->trade_no),'private plan cannot prorate');
$o=fixture();CommissionLog::create(['trade_no'=>$o->trade_no,'user_id'=>$o->user_id,'invite_user_id'=>99,'get_amount'=>100]);$p=$service->preview($o->trade_no);check($p['commission_deduction']===100,'commission ledger protects stale flags by withholding paid cost');
$o=fixture();$related=Order::create(['user_id'=>$o->user_id,'plan_id'=>$plan->id,'trade_no'=>'settled-renewal','period'=>'monthly','type'=>2,'total_amount'=>2000,'commission_status'=>2,'actual_commission_balance'=>100]);$p=$service->preview($o->trade_no);check($p['commission_deduction']===100,'settled related commission is deducted from group refund');
foreach([0,1]as $status){$o=fixture();Order::create(['user_id'=>$o->user_id,'trade_no'=>'pending-'.$o->id,'status'=>$status]);rejected(fn()=>$service->preview($o->trade_no),'unfinished order protects credit');}
$o=fixture();User::whereKey($o->user_id)->delete();rejected(fn()=>$service->preview($o->trade_no),'missing user');
rejected(fn()=>$service->preview('missing'),'missing order');
foreach([0,101]as $remaining){$o=fixture(['type'=>5,'period'=>'traffic_package']);packageFor($o,$remaining);rejected(fn()=>$service->preview($o->trade_no),'depleted or invalid package');}
$o=fixture(['type'=>5,'period'=>'traffic_package']);$pkg=packageFor($o,50);$p=$service->preview($o->trade_no);$pkg->remaining_bytes--;$pkg->save();rejected(fn()=>refundPreview($p),'even one byte of new consumption invalidates package quote');
// Failure at audit or relation persistence rolls back balance, all orders and revoked benefits.
foreach(['audit','link','package']as $failure){
    $o=fixture(array_merge($failure==='package'?['type'=>5,'period'=>'traffic_package']:[],['commission_status'=>2,'actual_commission_balance'=>100]));$pkg=$failure==='package'?packageFor($o,50):null;
    $p=$service->preview($o->trade_no);$before=$o->user->getAttributes();
    if($failure==='audit')OrderRefund::creating(static fn()=>false);
    if($failure==='link')OrderRefundOrder::creating(static function(){throw new RuntimeException('fixture link failure');});
    if($failure==='package')UserTrafficPackage::saving(static fn()=>false);
    try{refundPreview($p);throw new LogicException('rollback failure not injected');}catch(RuntimeException $e){check(!($e instanceof LogicException),'injected '.$failure.' failure');}
    OrderRefund::flushEventListeners();OrderRefundOrder::flushEventListeners();UserTrafficPackage::flushEventListeners();
    check($o->user->fresh()->getAttributes()===$before&&$o->fresh()->status===3,'all entitlement/balance changes rolled back');
    if($pkg)check($pkg->fresh()->remaining_bytes===50*1073741824,'package revocation rolled back');
    check($o->fresh()->balanceRefund===null,'no orphan audit');refundPreview($p);check($o->fresh()->status===5,'retry after storage recovery');
}
// Authorization and settlement remain enforced.
$auth=Mockery::mock();$guard=Mockery::mock();$auth->shouldReceive('guard')->with('sanctum')->andReturn($guard);
$guard->shouldReceive('user')->andReturn(null,(new User())->forceFill(['is_admin'=>false]),(new User())->forceFill(['is_admin'=>true]));Illuminate\Support\Facades\Auth::swap($auth);
foreach([false,false,true]as $allowed)check((new App\Http\Middleware\Admin())->handle(new Request(),fn()=>new JsonResponse())->getStatusCode()===($allowed?200:403),'admin authorization');
$o=fixture();refundPreview($service->preview($o->trade_no));Order::whereKey($o->id)->update(['commission_status'=>1,'invite_user_id'=>99]);
$command=new class extends App\Console\Commands\CheckCommission{public int $paid=0;public function payHandle($id,Order $order){$this->paid++;return true;}};
$command->autoPayCommission();check($command->paid===0,'stale commission scan cannot settle refunded order');
// Same selected row and alternate renewal rows share one group lock and credit.
if($dsn)foreach([0,350]as $paidCommission)foreach([false,true]as $alternate){
    $o=fixture(['commission_status'=>$paidCommission?2:0,'actual_commission_balance'=>$paidCommission]);$rows=[$o];
    if($alternate)$rows[]=Order::create(['user_id'=>$o->user_id,'plan_id'=>$plan->id,'trade_no'=>'race-renewal-'.$o->id,'period'=>'monthly','type'=>Order::TYPE_RENEWAL,'total_amount'=>2000,'created_at'=>$o->created_at]);
    $previews=array_map(fn($row)=>$service->preview($row->trade_no),$rows);$processes=[];
    for($i=0;$i<4;$i++){$p=$previews[$i%count($previews)];$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$p['trade_no'],(string)$p['amount'],$p['quote']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$processes[]=[$proc,$pipes];}
    $ids=[];foreach($processes as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'worker completed '.$err);$result=json_decode($out,true,512,JSON_THROW_ON_ERROR);check($result['ok'],'worker succeeded '.$out);$ids[]=$result['id'];}
    check(count(array_unique($ids))===1&&$o->user->fresh()->balance===190+$previews[0]['amount'],'concurrent group credited only once');
}
$receiptMigration->down();$upgrade->down();$migration->down();$migration->up();$upgrade->up();$receiptMigration->up();check($schema->hasTable('v2_order_refund_order'),'incremental migrations reversible');
echo 'Prorated order refund ('.($dsn?'MySQL':'SQLite')."): {$checks} checks passed\n";
