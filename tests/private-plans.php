<?php

declare(strict_types=1);

// Isolated database; never boot the application .env or contact payment/node services.
function admin_setting($key = null, $default = null) { return $GLOBALS['private_test_settings'][$key] ?? $default; }
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\PrivatePlanService;
use App\Services\ServerService;
use App\Services\TelegramGroupEligibilityService;
use App\Services\TrafficPackageService;
use App\Services\TrafficResetService;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
$app->instance('app', $app);
$app->instance('config', new Repository(['app' => ['timezone' => 'Asia/Shanghai']]));
$app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->instance('db.schema', $capsule->schema());
Facade::setFacadeApplication($app);
$app->instance(TelegramGroupEligibilityService::class, new class extends TelegramGroupEligibilityService {
    public function grantFromOrder(Order $order): void {}
});
$app->instance(TrafficResetService::class, new class extends TrafficResetService {
    public int $resets = 0;
    public int $initializations = 0;
    public function setInitialResetTime(User $user): void { $this->initializations++; }
    public function performReset(User $user, string $triggerSource = 'manual'): bool {
        $this->resets++;
        $user->u = $user->d = 0;
        return true;
    }
});
$schema = $capsule->schema();
$schema->create('v2_plan', function (Blueprint $t) {
    $t->increments('id'); $t->string('name'); $t->integer('group_id')->nullable();
    $t->integer('transfer_enable')->default(100); $t->text('prices')->nullable();
    $t->boolean('show')->default(true); $t->boolean('sell')->default(true); $t->boolean('renew')->default(true);
    $t->integer('speed_limit')->nullable(); $t->integer('device_limit')->nullable();
    $t->integer('capacity_limit')->nullable(); $t->integer('sort')->default(0);
    $t->integer('reset_traffic_method')->nullable(); $t->text('content')->nullable(); $t->text('tags')->nullable();
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->integer('plan_id')->nullable(); $t->integer('group_id')->nullable();
    $t->integer('expired_at')->nullable(); $t->bigInteger('transfer_enable')->default(0);
    $t->bigInteger('u')->default(0); $t->bigInteger('d')->default(0);
    $t->integer('balance')->default(0); $t->integer('discount')->default(0);
    $t->integer('invite_user_id')->nullable(); $t->boolean('banned')->default(false);
    $t->string('uuid')->default('test'); $t->string('token')->default('test'); $t->string('email')->default('test@example.test');
    $t->integer('speed_limit')->nullable(); $t->integer('device_limit')->nullable();
    $t->integer('next_reset_at')->nullable(); $t->integer('last_reset_at')->nullable();
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_order', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->integer('plan_id')->nullable();
    $t->integer('traffic_package_id')->nullable(); $t->string('period'); $t->string('trade_no')->unique();
    foreach (['total_amount','balance_amount','discount_amount','refund_amount','surplus_amount'] as $column) $t->integer($column)->default(0);
    $t->integer('status')->default(0); $t->integer('type')->default(1); $t->text('surplus_order_ids')->nullable();
    $t->integer('paid_at')->nullable(); $t->string('callback_no')->nullable(); $t->integer('invite_user_id')->nullable();
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_server_group', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
Capsule::table('v2_server_group')->insert(['id'=>100,'name'=>'Private-100']);
$schema->create('v2_server', function (Blueprint $t) {
    $t->increments('id'); $t->text('group_ids'); $t->boolean('show')->default(true);
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_user_traffic_packages', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->string('status');
    $t->bigInteger('total_bytes')->default(0); $t->bigInteger('remaining_bytes');
});
$migration = require dirname(__DIR__) . '/database/migrations/2026_09_21_000001_add_private_plans.php';
$migration->up();
$count = 0;
function check($condition, string $message): void {
    global $count; $count++;
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (ApiException $e) { check(true, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function settle(Order $order): void {
    $order->status = Order::STATUS_PROCESSING; $order->save();
    (new OrderService($order))->open();
}
$standard = Plan::create(['name'=>'高级','group_id'=>1,'prices'=>['monthly'=>35]]);
$flagship = Plan::create(['name'=>'旗舰','group_id'=>2,'prices'=>['monthly'=>55]]);
$custom = Plan::create(['name'=>'私人定制','plan_type'=>'custom','prices'=>['monthly'=>299,'quarterly'=>850]]);
$user = User::create(['plan_id'=>$standard->id,'group_id'=>1,'transfer_enable'=>100*1073741824,'expired_at'=>Carbon::now()->addMonth()->timestamp]);
$other = User::create([]);
$private = Plan::create(['name'=>'定制 #1','plan_type'=>'exclusive','owner_user_id'=>$user->id,'group_id'=>100,'prices'=>['monthly'=>299],'show'=>false,'sell'=>false,'transfer_enable'=>500]);
$node = Server::create(['group_ids'=>['100']]);
$service = new PrivatePlanService();
foreach ([$standard,$flagship,$custom,$private,$user,$other,$node] as $model) $model->refresh();
$old = Order::create(['user_id'=>$user->id,'plan_id'=>$standard->id,'period'=>'monthly','trade_no'=>'old','total_amount'=>3500,'status'=>3]);
check($standard->refresh()->plan_type === 'standard', 'migration defaults old/normal plans to standard');
check(!(new PlanService(new Plan()))->getAvailablePlans()->contains('id',$private->id),'guest cannot see private instance');
rejects(fn()=>(new PlanService($private))->validatePurchase($other,'monthly'),'non-owner cannot buy instance');
rejects(fn()=>$service->assignment($other,['plan_id'=>$private->id]),'admin cannot assign instance to another user');
rejects(fn()=>(new PlanService($custom))->validatePurchase($user,'onetime'),'custom cannot bypass with traffic package period');

$purchase = OrderService::createFromRequest($user,$custom,'monthly');
check($purchase->no_proration && $purchase->surplus_amount > 0,'normal to custom uses old credit and freezes no-proration flag');
check($user->fresh()->plan_id === $standard->id,'unpaid order leaves previous plan active');
settle($purchase);
$user->refresh();
check($user->custom_pending_order_id === $purchase->id && $user->expired_at === 0 && $user->group_id === null,'payment becomes pending without starting clock');
check(!(new UserService())->isAvailable($user),'pending user has no access');
Capsule::table('v2_user_traffic_packages')->insert(['user_id'=>$user->id,'status'=>'active','remaining_bytes'=>100]);
(new TrafficPackageService())->syncAccessProfile($user);
check($user->group_id === null && ServerService::getAvailableServers($user) === [],'package fallback cannot grant pending access');
check(ServerService::getAvailableUsers($node)->isEmpty(),'pending user not sent to dedicated node');
rejects(fn()=>OrderService::createFromRequest($user,$custom,'monthly'),'cannot purchase duplicate pending custom');
(new OrderService($purchase))->open();
check($user->fresh()->custom_pending_order_id === $purchase->id,'repeated open is idempotent');

$node->update(['show'=>false]);
rejects(fn()=>$service->savePlan(['id'=>$private->id,'name'=>'Must roll back']), 'saving without a usable node fails delivery');
check($private->fresh()->name==='定制 #1' && $user->fresh()->custom_pending_order_id===$purchase->id, 'failed save rolls back both plan edits and user assignment');
$node->update(['show'=>true]);
$service->savePlan(['id'=>$private->id,'name'=>'自动开通专属']);
$user->refresh();
check($user->plan_id===$private->id, 'saving existing exclusive plan automatically assigns owner');
$initialExpiry=$user->expired_at;
$user->update(['u'=>2048]);
$service->savePlan(['id'=>$private->id,'name'=>'修改说明后的专属']);
$user->refresh();
check($user->expired_at===$initialExpiry && $user->u===2048 && app(TrafficResetService::class)->initializations===1, 'repeat plan save preserves expiry and usage without repeated activation');

check(abs($user->expired_at-Carbon::now()->addMonth()->timestamp)<3,'activation grants full paid cycle from now');
check(!$user->custom_pending_order_id && $user->group_id===100 && $user->transfer_enable===500*1073741824,'activation applies assigned instance quota and group');
rejects(fn()=>$service->assignment($user,['plan_id'=>$private->id,'expected_custom_order_id'=>$purchase->id]),'stale admin form cannot repeat activation');
check(ServerService::getAvailableUsers($node)->pluck('id')->all()===[$user->id],'dedicated node only receives its owner');
$other->update(['plan_id'=>$standard->id,'group_id'=>100,'transfer_enable'=>100,'expired_at'=>time()+100]);
check(ServerService::getAvailableUsers($node)->pluck('id')->all()===[$user->id],'misassigned second user still cannot access node');
check(ServerService::getAvailableServers($other)===[],'misassigned second user cannot fetch dedicated subscription');
$other->update(['group_id'=>null]);
$node->update(['group_ids'=>['100','1']]);
check(ServerService::getAvailableUsers($node)->isEmpty(),'mixed public/private node fails closed');
rejects(fn()=>$service->assertExclusiveGroup($private,true),'cannot deliver mixed node');
$node->update(['group_ids'=>['100']]);
$visible = (new PlanService(new Plan()))->getAvailablePlansForUser($user);
check($visible->contains('id',$private->id) && !$visible->contains('id',$custom->id),'owner sees renewal card instead of repurchase');

$user->update(['u'=>1024]); $expiry = $user->expired_at;
$renewal = OrderService::createFromRequest($user,$private,'monthly');
check($renewal->type===Order::TYPE_RENEWAL && !$renewal->surplus_amount && $renewal->total_amount===29900,'private renewal no surplus credit');
settle($renewal); $user->refresh();
check($user->expired_at===Carbon::createFromTimestamp($expiry)->addMonth()->timestamp && $user->u===1024,'renewal extends old expiry and preserves used traffic');
$switched = OrderService::createFromRequest($user,$standard,'monthly');
check(!$switched->surplus_amount && !$switched->refund_amount && $switched->total_amount===3500,'private to standard gives zero credit/refund');
settle($switched); $user->refresh();
check($user->plan_id===$standard->id && !$user->custom_pending_order_id,'paid normal switch replaces private service');
// Reintroduce completed historical private order: defensive snapshot filtering still excludes it.
$renewal->refresh()->update(['status'=>Order::STATUS_COMPLETED]);
$next = OrderService::createFromRequest($user,$flagship,'monthly');
check(!in_array($renewal->id,$next->surplus_order_ids??[],true) && $next->surplus_amount<=3500,'later standard upgrades cannot reclaim private value');
$next->update(['status'=>Order::STATUS_CANCELLED]);
$again = OrderService::createFromRequest($user,$custom,'quarterly'); settle($again); $user->refresh();
$back = OrderService::createFromRequest($user,$standard,'monthly');
check(!$back->surplus_amount && !$back->refund_amount,'pending custom to normal has no credit');
settle($back); $user->refresh();
check(!$user->custom_pending_order_id,'normal payment closes pending custom');
rejects(fn()=>$service->assignment($user,['plan_id'=>$private->id]),'old pending assignment cannot resurrect cancelled delivery');
// An old callback with status=0 in memory must not reopen the already completed order.
$stale = clone $back; $stale->status = Order::STATUS_PENDING;
check((new OrderService($stale))->paid('duplicate') && $back->fresh()->status===Order::STATUS_COMPLETED,'stale payment callback cannot reset completed status');

$prepared = $service->preparePlan(['plan_type'=>'exclusive','owner_user_id'=>$user->id,'group_id'=>100],$private);
check($prepared['show']===false && $prepared['sell']===false,'private instance forcibly hidden and closed to new sales');
rejects(fn()=>$service->preparePlan(['plan_type'=>'standard'],$private),'cannot relabel used private plan to recover credit');
rejects(fn()=>$service->preparePlan(['owner_user_id'=>$other->id],$private),'cannot transfer instance ownership');
// New exclusive plan creation delivers immediately, using the paid period.
$newUser = User::create(['email'=>'private.owner@example.test']);
$newOrder = OrderService::createFromRequest($newUser,$custom,'quarterly'); settle($newOrder);
Capsule::table('v2_server_group')->insert(['id'=>101,'name'=>'Private-101']);
Server::create(['group_ids'=>['101']]);
$newPrivate=$service->savePlan(['plan_type'=>'exclusive','name'=>'New automatic delivery','owner_email'=>'  PRIVATE.OWNER@example.test  ','group_id'=>101,'transfer_enable'=>200,'prices'=>['monthly'=>150]]);
$newUser->refresh();
check($newUser->plan_id===$newPrivate->id && $newUser->group_id===101 && !$newUser->custom_pending_order_id, 'new exclusive plan automatically binds user and clears pending order');
check(abs($newUser->expired_at-Carbon::now()->addMonths(3)->timestamp)<3 && $newUser->transfer_enable===200*1073741824, 'new save grants original paid quarterly period and entity quota');
Capsule::table('v2_server_group')->insert(['id'=>102,'name'=>'Private-102']);
Server::create(['group_ids'=>['102']]);
$unpaidUser=User::create([]);
$beforeCount=Plan::count();
rejects(fn()=>$service->savePlan(['plan_type'=>'exclusive','name'=>'Unpaid attempt','owner_user_id'=>$unpaidUser->id,'group_id'=>102,'prices'=>['monthly'=>150]]), 'creation cannot grant service without paid pending order');
check(Plan::count()===$beforeCount && !$unpaidUser->fresh()->plan_id, 'failed new delivery leaves no orphan plan or user entitlement');
$newExpiry = $newUser->expired_at;
$service->savePlan(['id'=>$newPrivate->id,'owner_email'=>'private.owner@example.test','name'=>'Edited by email']);
check($newPrivate->fresh()->owner_user_id===$newUser->id && $newUser->fresh()->expired_at===$newExpiry, 'email edit resolves same owner and preserves active period');
$beforeName=$newPrivate->fresh()->name;
rejects(fn()=>$service->savePlan(['id'=>$newPrivate->id,'owner_email'=>'missing@example.test','name'=>'bad']), 'unknown email is rejected without ID fallback');
rejects(fn()=>$service->savePlan(['id'=>$newPrivate->id,'owner_email'=>'','name'=>'bad']), 'empty email cannot silently retain stale ID');
$unpaidUser->update(['email'=>'different.owner@example.test']);
rejects(fn()=>$service->savePlan(['id'=>$newPrivate->id,'owner_email'=>$unpaidUser->email,'name'=>'bad']), 'email cannot transfer an existing exclusive plan');
check($newPrivate->fresh()->name===$beforeName && $newPrivate->fresh()->owner_user_id===$newUser->id, 'failed email edits preserve plan and ownership');
$migration->down();
check(!$schema->hasColumn('v2_plan','plan_type') && !$schema->hasColumn('v2_user','custom_pending_order_id'),'migration rollback removes added fields');
echo "Private plan lifecycle: {$count} assertions passed.\n";
