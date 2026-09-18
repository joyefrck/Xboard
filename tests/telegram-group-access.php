<?php
/** Isolated integration checks: in-memory SQLite and a fake Telegram transport. */
declare(strict_types=1);

function admin_setting($key, $default = null) { return $GLOBALS['groupSettings'][$key] ?? $default; }
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\{User, Order, TelegramGroupEntitlement as Entitlement, TelegramGroupInvitation as Invitation, TelegramGroupRequest as JoinRequest};
use App\Services\{TelegramService, TelegramGroupAccessService as Access, TelegramGroupEligibilityService as Eligibility};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Facade, DB};

$GLOBALS['groupSettings'] = ['telegram_group_access_enable'=>true,'telegram_bot_enable'=>true,'telegram_discuss_id'=>'-100123', 'app_url'=>'https://example.invalid', 'telegram_bot_token'=>'test-only'];
$app = new class extends Container { public function runningUnitTests(): bool { return true; } }; Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('config', new Illuminate\Config\Repository());
$capsule = new Capsule($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->instance('db.schema', $capsule->schema());
$app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$app->instance(Illuminate\Contracts\Auth\Factory::class, new class implements Illuminate\Contracts\Auth\Factory { public function id() { return 99; } public function guard($name = null) { return null; } public function shouldUse($name) {} });
Model::encryptUsing(new Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'));
Model::unguard();
$capsule->schema()->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->bigInteger('telegram_id')->nullable(); $t->integer('plan_id')->nullable();
    $t->integer('expired_at')->nullable(); $t->boolean('banned')->default(false); $t->boolean('is_admin')->default(false);
    $t->bigInteger('transfer_enable')->default(0); $t->integer('created_at'); $t->integer('updated_at');
});
$capsule->schema()->create('v2_order', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->integer('status'); $t->integer('paid_at')->nullable();
    $t->integer('total_amount')->default(0); $t->integer('balance_amount')->default(0); $t->string('callback_no')->nullable();
    $t->integer('created_at'); $t->integer('updated_at');
});
$capsule->schema()->create('v2_user_traffic_packages', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->integer('order_id')->nullable();
});
$migration = require dirname(__DIR__) . '/database/migrations/2026_09_18_000001_create_telegram_group_access_tables.php';
$migration->up();

class FakeGroupBot extends TelegramService
{
    public array $members = [], $approved = [], $declined = [], $revoked = [];
    public int $created = 0;
    public bool $unavailable = false, $loseApprovalResponse = false;
    public string $chatType = 'supergroup', $username = '', $webhook = '';
    public bool $canApprove = true;
    public array $updates = ['message', 'chat_join_request'];
    public function __construct() { $this->webhook = 'https://example.invalid/api/v1/guest/telegram/webhook?access_token=' . md5('test-only'); }
    private function result(array $value): object { if ($this->unavailable) throw new RuntimeException('simulated outage'); return (object) ['result'=>(object)$value]; }
    public function getMe(): object { return $this->result(['id'=>42]); }
    public function getChat(int $chatId): object { return $this->result(['id'=>$chatId,'type'=>$this->chatType,'username'=>$this->username]); }
    public function getWebhookInfo(): object { return $this->result(['url'=>$this->webhook,'allowed_updates'=>$this->updates]); }
    public function getChatMember(int $chatId, int $userId): object { return $this->result($userId === 42 ? ['status'=>'administrator','can_invite_users'=>$this->canApprove] : ['status'=>$this->members[$userId] ?? 'left']); }
    public function createChatInviteLink(int $chatId, int $expiresAt): object { return $this->result(['invite_link'=>'https://t.me/+test_invite_' . ++$this->created, 'creates_join_request'=>true]); }
    public function revokeChatInviteLink(int $chatId, string $inviteLink): object { $result=$this->result([]); $this->revoked[]=$inviteLink; return $result; }
    public function approveChatJoinRequest(int $chatId, int $userId): void {
        $this->result([]); $this->approved[]=$userId; $this->members[$userId]='member';
        if ($this->loseApprovalResponse) { $this->loseApprovalResponse=false; throw new RuntimeException('response lost after success'); }
    }
    public function declineChatJoinRequest(int $chatId, int $userId): void { $this->result([]); $this->declined[]=$userId; }
}
$bot = new FakeGroupBot(); $app->instance(TelegramService::class, $bot);
$eligibility = new Eligibility(); $app->instance(Eligibility::class, $eligibility); $access = new Access($eligibility);
$checks = 0;
function expect(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function qualified(int $tg): User { global $eligibility; $u=User::create(['telegram_id'=>$tg,'plan_id'=>1,'expired_at'=>time()-86400]); $eligibility->grant($u->id,'admin_plan',1,99); return $u; }
function eventFor(string $link, int $tg, ?int $date = null, int $chat = -100123): array { static $id=1000; return ['update_id'=>++$id,'chat_join_request'=>['chat'=>['id'=>$chat],'from'=>['id'=>$tg],'date'=>$date??time(),'invite_link'=>['invite_link'=>$link]]]; }
function submit(string $link, int $tg, ?int $date = null): JoinRequest { global $access; $r=$access->record(eventFor($link,$tg,$date)); $access->process($r->id); return $r->refresh(); }

// Evidence, permanence, free trials and transaction rollback.
$trial=User::create(['plan_id'=>1,'expired_at'=>time()+86400]);
expect(!$eligibility->hasEntitlement($trial), 'automatic trial must not qualify');
foreach ([[3,100,0,true],[4,100,0,true],[3,0,100,true],[3,0,0,false],[0,100,0,false],[1,100,0,false],[2,100,0,false]] as [$status,$cash,$balance,$expected]) {
    $u=User::create([]); $o=Order::create(['user_id'=>$u->id,'status'=>$status,'paid_at'=>time(),'total_amount'=>$cash,'balance_amount'=>$balance]);
    $eligibility->grantFromOrder($o); expect($eligibility->hasEntitlement($u)===$expected,'only completed paid/balance orders qualify');
}
$gift=User::create([]); $eligibility->grantFromOrder(Order::create(['user_id'=>$gift->id,'status'=>3,'callback_no'=>'manual_operation']));
expect($eligibility->hasEntitlement($gift),'administrator fulfilled free order qualifies');
$u=qualified(101); $first=Entitlement::where('user_id',$u->id)->first();
$u->update(['plan_id'=>null,'transfer_enable'=>0]);
expect($access->status($u)['state']==='eligible','expiration, removal and exhausted traffic preserve qualification');
$eligibility->grant($u->id,'paid_order',222);
expect(Entitlement::where('user_id',$u->id)->count()===1 && $first->refresh()->source==='admin_plan','first source preserved');
try { DB::transaction(function () use ($trial,$eligibility) { $trial->update(['plan_id'=>2]); $eligibility->grant($trial->id,'admin_plan',2,99); throw new RuntimeException('rollback'); }); } catch (RuntimeException) {}
expect(!$eligibility->hasEntitlement($trial) && $trial->refresh()->plan_id===1,'grant and plan changes roll back together');
$eligibility->grant($trial->id,'admin_plan',1,99);
expect($access->status($trial)['state']==='binding_required','eligible unbound account requires binding');
$u->update(['banned'=>true]); expect($access->status($u)['state']==='blocked','ban overrides eligibility');
$u->update(['banned'=>false]); expect($eligibility->hasEntitlement($u),'ban does not erase eligibility');

// Readiness includes private group, approval rights and authenticated webhook.
expect($access->checkConfiguration()['ready'],'valid bot configuration ready');
$bot->username='public_group'; expect(!$access->checkConfiguration()['ready'],'public group rejected'); $bot->username='';
$bot->canApprove=false; expect(!$access->checkConfiguration()['ready'],'missing permission rejected'); $bot->canApprove=true;
$bot->updates=['message']; expect(!$access->checkConfiguration()['ready'],'missing join updates rejected'); $bot->updates=['chat_join_request'];
$original=$bot->webhook; $bot->webhook='https://example.invalid/api/v1/guest/telegram/webhook?access_token=wrong';
expect(!$access->checkConfiguration()['ready'],'wrong webhook authentication rejected'); $bot->webhook=$original;

// Short-lived encrypted invites are reused for the same account only.
$ready=$access->issue($u); expect($ready['state']==='ready' && $ready['expires_at']<=time()+600,'ten-minute invite');
$link=$ready['invite_link']; $invite=Invitation::where('user_id',$u->id)->first();
expect($access->issue($u)['invite_link']===$link && $bot->created===1,'repeat clicks reuse invitation');
expect($invite->getRawOriginal('invite_link')!==$link && !str_contains($invite->toJson(),$link),'encrypted and hidden invitation');
expect($access->record(eventFor($link,101,null,-100999))===null,'other group ignored');
$forwarded=submit($link,999); expect($forwarded->status==='declined' && !in_array(999,$bot->approved,true),'forwarding does not admit another person');
expect(!$invite->refresh()->used_at,'wrong applicant does not consume owner invite');
$event=eventFor($link,101); $r=$access->record($event); expect($access->record($event)->id===$r->id,'duplicate update id is idempotent');
$access->process($r->id); $access->process($r->id);
expect($r->refresh()->status==='approved' && count($bot->approved)===1,'one approval on duplicate job');
expect($invite->refresh()->used_at && $invite->revoked_at,'success consumes and revokes invitation');
expect($access->issue($u)['state']==='already_member','existing member does not receive another invite');
$bot->members[101]='left'; $again=$access->issue($u); expect($again['invite_link']!==$link,'member who left may apply again');
expect(submit($link,101)->status==='declined','consumed invitation cannot be reused');
expect(submit('https://t.me/+foreign_invite',101)->status==='declined','unknown link declined');

// Recheck the account at approval time, and distinguish request time from processing time.
$ban=qualified(102); $banLink=$access->issue($ban)['invite_link']; $ban->update(['banned'=>true]);
expect(submit($banLink,102)->status==='declined','ban after link issuance blocks approval');
$delayed=qualified(103); $delayedLink=$access->issue($delayed)['invite_link'];
Invitation::where('user_id',$delayed->id)->update(['created_at'=>time()-1200,'expires_at'=>time()-600]);
expect(submit($delayedLink,103,time()-900)->status==='approved','request inside valid window survives queue delay');
$expired=qualified(104); $expiredLink=$access->issue($expired)['invite_link'];
Invitation::where('user_id',$expired->id)->update(['created_at'=>time()-1200,'expires_at'=>time()-600]);
expect(submit($expiredLink,104)->status==='declined','late request rejected');
$rebound=qualified(105); $reboundLink=$access->issue($rebound)['invite_link']; $rebound->update(['telegram_id'=>205]);
expect(submit($reboundLink,105)->status==='declined','old binding cannot approve');
$u->update(['telegram_id'=>201]); expect($access->status($u)['state']==='binding_conflict','past successful binding cannot be farmed by rebind');
$duplicate=qualified(106); User::create(['telegram_id'=>106]); expect($access->issue($duplicate)['state']==='binding_conflict','ambiguous duplicate binding refused');

// Temporary failures are durable and recover; losing the approval response must not double approve.
$retry=qualified(107); $retryLink=$access->issue($retry)['invite_link']; $r=$access->record(eventFor($retryLink,107));
$bot->unavailable=true; $access->process($r->id); $r->refresh();
expect($r->status==='pending' && $r->attempts===1 && $r->next_attempt_at>time(),'network failure records a retry');
expect($r->last_error==='telegram_or_storage_unavailable','no raw exception or invite persisted');
$bot->unavailable=false; $r->update(['next_attempt_at'=>0]); $access->process($r->id);
expect($r->refresh()->status==='approved','pending request recovers');
$lost=qualified(108); $lostLink=$access->issue($lost)['invite_link']; $r=$access->record(eventFor($lostLink,108));
$bot->loseApprovalResponse=true; $access->process($r->id); expect($r->refresh()->status==='pending','lost response is not falsely marked successful');
$r->update(['next_attempt_at'=>0]); $access->process($r->id);
expect($r->refresh()->status==='approved' && count(array_filter($bot->approved,fn($id)=>$id===108))===1,'membership recovers lost response without another approval');
$failed=qualified(109); $failedLink=$access->issue($failed)['invite_link']; $r=$access->record(eventFor($failedLink,109));
$bot->unavailable=true;
for($i=0;$i<5;$i++){ $r->refresh()->update(['next_attempt_at'=>0]); $access->process($r->id); }
expect($r->refresh()->status==='failed' && $r->attempts===5,'bounded retries end in visible failed state'); $bot->unavailable=false;

// History command defaults to preview and never infers a gift from an existing trial plan.
$history=User::create([]); Order::create(['user_id'=>$history->id,'status'=>4,'paid_at'=>time()-86400,'total_amount'=>120]);
$unknown=User::create(['plan_id'=>7]); $operator=User::create(['is_admin'=>true]);
function backfill(array $input): array {
    global $app;
    $command=new App\Console\Commands\BackfillTelegramGroupEntitlements(); $command->setLaravel($app);
    $output=new Symfony\Component\Console\Output\BufferedOutput();
    $status=$command->run(new Symfony\Component\Console\Input\ArrayInput($input),$output);
    return [$status,$output->fetch()];
}
[$code,$text]=backfill([]);
expect($code===0 && !$eligibility->hasEntitlement($history),'backfill preview makes no writes');
expect(str_contains($text,"needs_review user_id={$unknown->id}"),'unknown plan is flagged for review');
backfill(['--apply'=>true]); expect($eligibility->hasEntitlement($history) && !$eligibility->hasEntitlement($unknown),'backfill only verified payment evidence');
backfill(['--user-id'=>[$unknown->id],'--operator-id'=>$operator->id,'--reason'=>'Verified test grant']);
expect(!$eligibility->hasEntitlement($unknown),'manual preview is read only');
[$code]=backfill(['--apply'=>true,'--user-id'=>[$unknown->id,999999],'--operator-id'=>$operator->id,'--reason'=>'Verified test grant']);
expect($code===1 && !$eligibility->hasEntitlement($unknown),'all manual IDs validated before writing');
backfill(['--apply'=>true,'--user-id'=>[$unknown->id],'--operator-id'=>$operator->id,'--reason'=>'Verified test grant']);
expect($eligibility->hasEntitlement($unknown),'verified historical gift qualifies');
$record=Entitlement::where('user_id',$unknown->id)->first(); expect($record->operator_id==$operator->id && $record->reason==='Verified test grant','manual grant is auditable');

// Exercise the real fulfillment and admin controller entry points, not just the eligibility helper.
$app->instance('app', $app);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory { public function __construct() {} });
$app->instance('url', new class { public function route($name, $parameters=[], $absolute=true) { return '/synthetic-subscription'; } });
$app->instance('log', new Psr\Log\NullLogger());
$app->instance('hash', new Illuminate\Hashing\BcryptHasher(['rounds'=>4]));
$capsule->schema()->table('v2_user', function (Blueprint $t) {
    foreach (['group_id','speed_limit','device_limit','invite_user_id','remind_expire','remind_traffic','balance'] as $column) $t->integer($column)->nullable();
    foreach (['email','password','uuid','token','remarks'] as $column) $t->string($column)->nullable();
});
$capsule->schema()->table('v2_order', function (Blueprint $t) {
    $t->integer('plan_id')->nullable(); $t->integer('type')->nullable(); $t->string('period')->nullable();
});
$capsule->schema()->create('v2_plan', function (Blueprint $t) {
    $t->increments('id'); $t->integer('group_id')->nullable(); $t->integer('speed_limit')->nullable();
    $t->integer('device_limit')->nullable(); $t->integer('transfer_enable')->default(100);
    $t->integer('created_at'); $t->integer('updated_at');
});
$plan=App\Models\Plan::create(['transfer_enable'=>100]);
foreach ([100,0] as $amount) {
    $buyer=User::create(['expired_at'=>time()-1]);
    $order=Order::create(['user_id'=>$buyer->id,'status'=>1,'paid_at'=>time(),'total_amount'=>$amount,'type'=>Order::TYPE_RENEWAL,'period'=>'monthly','plan_id'=>$plan->id]);
    (new App\Services\OrderService($order))->open();
    expect($order->refresh()->status===3 && $buyer->refresh()->plan_id===$plan->id,'real order fulfillment updates plan and status');
    expect($eligibility->hasEntitlement($buyer)===($amount>0),'real paid/free fulfillment grants correctly');
}
$rollbackUser=User::create(['expired_at'=>time()-1]);
$rollbackOrder=Order::create(['user_id'=>$rollbackUser->id,'status'=>1,'paid_at'=>time(),'total_amount'=>100,'type'=>Order::TYPE_RENEWAL,'period'=>'monthly','plan_id'=>$plan->id]);
$app->instance(Eligibility::class,new class extends Eligibility {
    public function grantFromOrder(Order $order): void { parent::grantFromOrder($order); throw new RuntimeException('simulated post-grant failure'); }
});
try { (new App\Services\OrderService($rollbackOrder))->open(); } catch (RuntimeException) {}
expect($rollbackOrder->refresh()->status===1 && !$rollbackUser->refresh()->plan_id && !$eligibility->hasEntitlement($rollbackUser),'real fulfillment rollback removes plan and eligibility atomically');
$app->instance(Eligibility::class,$eligibility);
$controller=new App\Http\Controllers\V2\Admin\UserController();
$adminTrial=User::create(['plan_id'=>$plan->id,'expired_at'=>time()+3600,'transfer_enable'=>1024]);
function updateByAdmin(User $user, array $changes): Illuminate\Http\JsonResponse {
    global $operator,$controller;
    $request=new class extends App\Http\Requests\Admin\UserUpdate { public function validated($key=null,$default=null) { return $this->all(); } };
    $request->replace(['id'=>$user->id]+$changes); $request->setUserResolver(fn()=>$operator);
    return $controller->update($request);
}
$response=updateByAdmin($adminTrial,['plan_id'=>$plan->id,'expired_at'=>$adminTrial->expired_at,'transfer_enable'=>1024,'remarks'=>'note only']);
expect($response->getStatusCode()===200 && !$eligibility->hasEntitlement($adminTrial),'actual admin full-form notes edit does not grant trial qualification');
$response=updateByAdmin($adminTrial,['plan_id'=>$plan->id,'expired_at'=>time()+7200]);
expect($response->getStatusCode()===200 && $eligibility->hasEntitlement($adminTrial),'actual administrator extension grants');
expect(Entitlement::where('user_id',$adminTrial->id)->first()->operator_id==$operator->id,'admin grant records operator');
$newGrant=User::create([]); updateByAdmin($newGrant,['plan_id'=>$plan->id]);
expect($eligibility->hasEntitlement($newGrant),'actual administrator plan assignment grants');
$GLOBALS['groupSettings']['try_out_plan_id']=$plan->id;
foreach ([true,false] as $explicitPlan) {
    $prefix=$explicitPlan?'gift-fixture':'trial-fixture';
    $request=new App\Http\Requests\Admin\UserGenerate();
    $request->replace(['email_prefix'=>$prefix,'email_suffix'=>'example.invalid']+($explicitPlan?['plan_id'=>$plan->id]:[]));
    $request->setUserResolver(fn()=>$operator); $controller->generate($request);
    $created=User::where('email',$prefix.'@example.invalid')->firstOrFail();
    expect($created->plan_id===$plan->id && $eligibility->hasEntitlement($created)===$explicitPlan,'admin account creation distinguishes explicit plan from automatic trial');
}
$request=new App\Http\Requests\Admin\UserGenerate();$request->replace(['generate_count'=>2,'email_suffix'=>'example.invalid','plan_id'=>$plan->id]);$request->setUserResolver(fn()=>$operator);
$before=Entitlement::count(); $controller->generate($request);
expect(Entitlement::count()===$before+2,'batch-created administrator plans each grant eligibility');

$failedGrantUser=User::create([]);
$app->instance(Eligibility::class,new class extends Eligibility {
    public function grant(int $userId, string $source, ?int $sourceId=null, ?int $operatorId=null, ?string $reason=null): Entitlement {
        parent::grant($userId,$source,$sourceId,$operatorId,$reason);
        throw new RuntimeException('simulated admin transaction failure');
    }
});
$response=updateByAdmin($failedGrantUser,['plan_id'=>$plan->id]);
expect($response->getStatusCode()===500 && !$failedGrantUser->refresh()->plan_id && !$eligibility->hasEntitlement($failedGrantUser),'real admin failure rolls back plan and grant');
$app->instance(Eligibility::class,$eligibility);

$apiCalls=[];
$transport=new Symfony\Component\HttpClient\MockHttpClient(function($method,$url,$options) use (&$apiCalls) {
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query); $apiCalls[]=[$method,basename(parse_url($url,PHP_URL_PATH)),$query];
    return new Symfony\Component\HttpClient\Response\MockResponse('{"ok":true,"result":true}');
});
$api=new TelegramService('test-only',$transport);
$api->createChatInviteLink(-100123,time()+600);
expect($apiCalls[0][2]['creates_join_request']==='true' && !isset($apiCalls[0][2]['member_limit']),'actual API wrapper requests approval without conflicting member limit');
$api->setWebhook('https://example.invalid/hook',['allowed_updates'=>['message','callback_query','chat_join_request']]);
expect(json_decode($apiCalls[1][2]['allowed_updates'],true)===['message','callback_query','chat_join_request'],'actual webhook API encodes update subscriptions');
$api->approveChatJoinRequest(-100123,123); $api->declineChatJoinRequest(-100123,456); $api->revokeChatInviteLink(-100123,'https://t.me/+synthetic');
expect($apiCalls[2][1]==='approveChatJoinRequest' && $apiCalls[2][2]['user_id']==='123' && $apiCalls[3][1]==='declineChatJoinRequest' && $apiCalls[4][1]==='revokeChatInviteLink','actual approval, rejection and revocation wrappers address expected methods');
$brokenTransport=new Symfony\Component\HttpClient\MockHttpClient(new Symfony\Component\HttpClient\Response\MockResponse('{"ok":false,"description":"sensitive-url-test-only"}',['http_code'=>400]));
try { (new TelegramService('test-only',$brokenTransport))->getMe(); expect(false,'API failure expected'); }
catch (App\Exceptions\ApiException $e) { expect(!str_contains($e->getMessage(),'sensitive-url') && !str_contains($e->getMessage(),'test-only'),'raw Telegram error is not exposed to user'); }
$hookController=new App\Http\Controllers\V1\Guest\TelegramController();
$hookRequest=Illuminate\Http\Request::create('/webhook','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],'{}');
try { $hookController->webhook($hookRequest); expect(false,'unauthorized webhook must fail'); }
catch (App\Exceptions\ApiException $e) { expect($e->getCode()===401,'webhook authenticates before processing'); }

$GLOBALS['groupSettings']['telegram_group_access_enable']=false;
expect($access->issue($retry)['state']==='unavailable' && $access->record(eventFor($retryLink,107))===null,'disabled gate does not issue or handle new invites');
$migration->down(); expect(!$capsule->schema()->hasTable('v2_telegram_group_requests'),'migration rolls back cleanly in isolated database');
echo "Telegram group integration: {$checks} checks passed\n";
