<?php
declare(strict_types=1);
require __DIR__.'/node-automation.php';
use App\Http\Controllers\V2\Admin\Server\AutomationController;
use App\Models\{Server, User, NodeAutomationSetting as Setting, NodeAutomationGroup as ApiGroup,
    NodeAutomationCheck as ApiCheck, NodeAutomationBackup as ApiBackup, NodeAutomationLog as ApiEvent};
use App\Services\NodeAutomation\{ProxyProbe, AutomationException};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Contracts\{Routing\ResponseFactory, Cache\Repository as CacheRepository, Bus\Dispatcher as BusDispatcher};
use Illuminate\Support\Str;

$app->instance('app', $app); // ServerService's plugin hooks use the application facade.
$db->schema()->create('v2_server',function(Blueprint $t){
    $t->increments('id');$t->string('code')->nullable();$t->string('name');$t->string('type');$t->string('host');
    $t->string('port');$t->json('group_ids')->nullable();$t->integer('created_at');$t->integer('updated_at');
});
$db->schema()->create('v2_user', function(Blueprint $t) {
    $t->increments('id'); $t->string('email'); $t->string('uuid'); $t->integer('group_id'); $t->integer('plan_id')->nullable();
    $t->boolean('banned')->default(false); $t->integer('custom_pending_order_id')->nullable(); $t->integer('expired_at')->nullable();
    $t->bigInteger('transfer_enable'); $t->bigInteger('u')->default(0); $t->bigInteger('d')->default(0);
    $t->integer('speed_limit')->nullable(); $t->integer('device_limit')->nullable(); $t->integer('created_at'); $t->integer('updated_at');
});
$db->schema()->create('v2_user_traffic_packages', function(Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->string('status'); $t->bigInteger('remaining_bytes');
});
$db->schema()->create('v2_plan', function(Blueprint $t) {
    $t->increments('id'); $t->string('plan_type'); $t->integer('group_id'); $t->integer('owner_user_id')->nullable();
});
$validator=new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(),'en'),$app);
$app->instance('translator', $validator->getTranslator()); // Pagination serializes translated navigation labels.
$validator->setPresenceVerifier(new Illuminate\Validation\DatabasePresenceVerifier($db->getDatabaseManager()));
Request::macro('validate',function($rules)use($validator){return $validator->make($this->all(),$rules)->validate();});
$app->instance(ResponseFactory::class,new class extends Illuminate\Routing\ResponseFactory{public function __construct(){}});
$app->instance(CacheRepository::class,$app['cache']);
$app->instance(Illuminate\Log\Context\Repository::class,new Illuminate\Log\Context\Repository(new Illuminate\Events\Dispatcher($app)));
$bus=new class($app) extends Illuminate\Bus\Dispatcher{public array $jobs=[];public function dispatch($job){$this->jobs[]=$job;return count($this->jobs);}};
$app->instance(BusDispatcher::class,$bus);
function request(array $data):Request {return Request::create('/isolated','POST',$data);}
function payload($response):array {return $response->getData(true);}
$baseline=$checks;
[$g,$b,$dns,$p,$s,$c]=fixture();$g->update(['enabled'=>false]);
Server::create(['name'=>'node 1','type'=>'vless','host'=>$g->domain,'port'=>'1001','group_ids'=>['1']]);
Server::create(['name'=>'node 2','type'=>'vless','host'=>$g->domain,'port'=>'1002','group_ids'=>['1']]);
$controller=new AutomationController($s,$dns);
$data=payload($controller->fetch());
expect(count($data['data']['groups'])===1&&count($data['data']['groups'][0]['nodes'])===2,'shared domain produces one API group');
expect(!array_key_exists('api_token',$data['data']['connection']),'API never returns token');
$data=payload($controller->backupSave(request(['group_id'=>$g->id,'name'=>'new','ip'=>'8.8.4.4','priority'=>0,'enabled'=>true])));
expect($data['status']==='success'&&$g->backups()->first()->ip==='8.8.4.4','backup created and ordered by priority');
$new=ApiBackup::find($data['data']['id']);
$data=payload($controller->backupSave(request(['group_id'=>$g->id,'id'=>$new->id,'name'=>'renamed','ip'=>'8.8.4.4','priority'=>4,'enabled'=>false])));
expect($data['status']==='success'&&!$new->refresh()->enabled&&$new->priority===4,'edit priority and disable persisted');
foreach(['127.0.0.1','192.168.1.1','0.0.0.0'] as $ip){
    $data=payload($controller->backupSave(request(['group_id'=>$g->id,'name'=>'invalid','ip'=>$ip,'priority'=>1,'enabled'=>true])));
    expect($data['status']==='fail','non-public backup rejected '.$ip);
}
$data=payload($controller->backupSave(request(['group_id'=>$g->id,'name'=>'duplicate','ip'=>$b->ip,'priority'=>1,'enabled'=>true])));
expect($data['status']==='fail','duplicate IP rejected');
$b->update(['state'=>'needs_review','enabled'=>false]);
$data=payload($controller->backupSave(request(['group_id'=>$g->id,'id'=>$b->id,'name'=>'review','ip'=>$b->ip,'priority'=>1,'enabled'=>true])));
expect($data['status']==='fail','quarantine requires explicit rearm');
$data=payload($controller->backupSave(request(['group_id'=>$g->id,'id'=>$b->id,'name'=>'review','ip'=>'8.8.8.8','priority'=>1,'enabled'=>true,'rearm'=>true])));
expect($data['status']==='success'&&$b->refresh()->state==='unchecked','reviewed machine explicitly rearmed');
$g->update(['enabled'=>true]);
$data=payload($controller->backupDrop(request(['group_id'=>$g->id,'id'=>$new->id])));
expect($data['status']==='fail'&&ApiBackup::find($new->id)!==null,'enabled strategy rejects config mutations');
$controller->pause(request(['id'=>$g->id]));$g->refresh();
expect(!$g->enabled,'pause persists disabled state');
$data=payload($controller->backupDrop(request(['group_id'=>$g->id,'id'=>$new->id])));
expect($data['status']==='success'&&ApiBackup::find($new->id)===null,'backup deletion persists when paused');
$requestId=(string)Str::uuid();$params=['id'=>$g->id,'action'=>'test','backup_id'=>$b->id,'request_id'=>$requestId];
$first=payload($controller->operation(request($params)));$second=payload($controller->operation(request($params)));
expect($first['status']==='success'&&$first['data']['id']===$second['data']['id']&&count($bus->jobs)===1,'request UUID deduplicates operation and queue dispatch');
$params['action']='manual';$data=payload($controller->operation(request($params)));
expect($data['status']==='fail','reused UUID cannot change operation');
$controller->pause(request(['id'=>$g->id]));
expect(ApiEvent::find($first['data']['id'])->status==='cancelled','pause cancels queued operation');
$before=ApiEvent::count();
try{$controller->operation(request(['id'=>$g->id,'action'=>'test','backup_id'=>$b->id,'request_id'=>'not-a-uuid']));expect(false,'invalid UUID');}
catch(Illuminate\Validation\ValidationException $e){expect(ApiEvent::count()===$before,'invalid request rejected before queueing');}

$user=User::create(['email'=>'probe@example.test','uuid'=>'00000000-0000-4000-8000-000000000001',
    'group_id'=>1,'expired_at'=>time()+86400,'transfer_enable'=>100000]);
$other=User::create(['email'=>'other@example.test','uuid'=>'00000000-0000-4000-8000-000000000002',
    'group_id'=>2,'expired_at'=>time()+86400,'transfer_enable'=>100000]);
$data=payload($controller->probeUserSave(request(['user_id'=>$user->id])));
expect($data['status']==='success'&&Setting::find(1)->probe_user_id===$user->id,'one global account saved independently of DNS connection');
$probe=new ProxyProbe();
$jp=ApiGroup::create(['domain'=>'jp.example.test','enabled'=>false,'state'=>'paused','config_hash'=>'old-hash']);
$jpNode=Server::create(['name'=>'node 3','type'=>'vless','host'=>$jp->domain,'port'=>'1003','group_ids'=>['1']]);
$g->update(['probe_users'=>['1'=>$other->id,'2'=>$other->id]]);
foreach(Server::all() as $node) {
    expect($probe->user($node->host===$g->domain?$g:$jp,$node)->id===$user->id,'all nodes and domains use global account, ignoring retired overrides '.$node->id);
}
$data=payload($controller->fetch());
expect($data['data']['probe_account']===['id'=>$user->id,'email'=>$user->email],'fetch exposes one selected identifier and email');
expect(!array_key_exists('probe_users',$data['data']['groups'][0]),'retired per-node account settings not exposed');
expect(!str_contains(json_encode($data),'00000000-0000-4000'),'fetch never exposes proxy identity');
Setting::find(1)->update(['probe_user_id'=>null]);
try{$probe->user($g,Server::first());expect(false,'missing common account');}
catch(AutomationException $e){expect(str_contains($e->getMessage(),'公共检测账号'),'missing common account never falls back to legacy per-node overrides');}
Setting::find(1)->update(['probe_user_id'=>$user->id]);
$g->update(['enabled'=>true]);
$data=payload($controller->probeUserSave(request(['user_id'=>$other->id])));
expect($data['status']==='fail'&&Setting::find(1)->probe_user_id===$user->id,'cannot change shared account while any strategy is enabled');
$controller->pause(request(['id'=>$g->id]));
$pending=operation($jp,'test');
$data=payload($controller->probeUserSave(request(['user_id'=>$other->id])));
expect($data['status']==='fail'&&Setting::find(1)->probe_user_id===$user->id,'cannot change shared account while another domain has a queued operation');
$pending->update(['status'=>'cancelled']);
$groupLock=Illuminate\Support\Facades\Cache::lock('node-automation:group:'.$jp->id,600);$groupLock->get();
$data=payload($controller->probeUserSave(request(['user_id'=>$other->id])));$groupLock->release();
expect($data['status']==='fail'&&Setting::find(1)->probe_user_id===$user->id,'in-flight domain detection lock prevents account mutation');
$g->update(['config_hash'=>'old-hash','failures'=>2]);
$b->update(['state'=>'ready','tested_at'=>now(),'tested_hash'=>'old-hash']);
$quarantined=ApiBackup::create(['group_id'=>$jp->id,'name'=>'old','ip'=>'9.9.9.9','state'=>'needs_review','enabled'=>false]);
ApiCheck::create(['group_id'=>$g->id,'ip'=>$g->current_ip,'status'=>'pass','checked_at'=>now()]);
$data=payload($controller->probeUserSave(request(['user_id'=>$other->id])));
expect($data['status']==='success'&&Setting::find(1)->probe_user_id===$other->id,'new global account persisted');
expect($g->refresh()->config_hash===null&&$jp->refresh()->config_hash===null&&$g->failures===0,'shared identity change invalidates every domain and failure streak');
expect($b->refresh()->state==='unchecked'&&$b->tested_hash===null&&ApiCheck::count()===0,'old identity observations and backup certifications cleared');
expect($quarantined->refresh()->state==='needs_review'&&!$quarantined->enabled,'identity changes never rearm quarantined VPS');
try{$probe->user($jp,$jpNode);expect(false,'missing permission');}
catch(AutomationException $e){expect(str_contains($e->getMessage(),'权限'),'global account lacking node permission is configuration error');}
$other->update(['expired_at'=>time()-1]);
$data=payload($controller->probeUserSave(request(['user_id'=>$other->id])));
expect($data['status']==='fail','expired shared account cannot be saved');
$user->update(['u'=>100000]);
$data=payload($controller->probeUserSave(request(['user_id'=>$user->id])));
expect($data['status']==='fail'&&Setting::find(1)->probe_user_id===$other->id,'invalid replacement rolls back shared identity');
$user->update(['u'=>0]);
$controller->probeUserSave(request(['user_id'=>$user->id]));
$data=payload($controller->save(request(['domain'=>$g->domain])));
expect($data['status']==='success'&&$g->refresh()->config_hash==='config-1','domain config and DNS matching no longer require per-node users');
try{$controller->save(request(['domain'=>$g->domain,'probe_users'=>['1'=>$other->id]]));expect(false,'retired request');}
catch(Illuminate\Validation\ValidationException $e){expect(true,'per-node account overrides rejected');}
ApiEvent::query()->delete();
for($i=0;$i<25;$i++) {
    ApiEvent::create(['group_id'=>$i%2?$g->id:$jp->id,'operation_id'=>(string)Str::uuid(),
        'action'=>$i%2?'manual':'auto','status'=>'completed','from_ip'=>'8.8.8.8','to_ip'=>'8.8.4.4']);
}
$all=payload($controller->logs(request([])))['data'];
expect($all['total']===25&&count($all['data'])===20&&$all['last_page']===2,'global records are paginated on backend');
expect(count(array_unique(array_column($all['data'],'domain')))===2,'global records include both domains');
expect($all['data'][0]['id']>$all['data'][19]['id'],'records ordered newest first');
$second=payload($controller->logs(request(['page'=>2])))['data'];
expect($second['current_page']===2&&count($second['data'])===5&&
    !array_intersect(array_column($all['data'],'id'),array_column($second['data'],'id')),'second page contains remaining records without overlap');
$scoped=payload($controller->logs(request(['id'=>$g->id])))['data'];
expect($scoped['total']===12&&array_unique(array_column($scoped['data'],'group_id'))===[$g->id],
    'domain modal query excludes all other domain records');
$empty=payload($controller->logs(request(['id'=>999999])))['data'];
expect($empty['total']===0&&$empty['data']===[],'unknown domain returns empty log page');
echo 'node automation API: '.($checks-$baseline)." checks passed\n";
