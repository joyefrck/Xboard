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
    $t->string('port');$t->json('group_ids')->nullable();$t->json('protocol_settings')->default('{}');$t->integer('created_at');$t->integer('updated_at');
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
$db->schema()->create('v2_server_group', function(Blueprint $t) { $t->increments('id'); $t->string('name'); });
$db->table('v2_server_group')->insert([['id'=>1,'name'=>'level2'],['id'=>2,'name'=>'level3']]);
(require dirname(__DIR__).'/database/migrations/2026_10_18_000001_add_group_probe_users_to_node_automation_settings.php')->up();
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
$saveUser=fn(int $groupId, ?int $userId)=>payload($controller->probeUserSave(request(['group_id'=>$groupId,'user_id'=>$userId])));
$data=$saveUser(1,$user->id);
expect($data['status']==='success','level2 account saved independently of DNS');
$data=$saveUser(2,$other->id);
expect($data['status']==='success','level3 can configure a separate account');
expect(Setting::find(1)->probe_users_by_group==[1=>$user->id,2=>$other->id],'both permission group accounts persist');
$probe=new ProxyProbe();
$jp=ApiGroup::create(['domain'=>'jp.example.test','enabled'=>false,'state'=>'paused','config_hash'=>'old-hash']);
$jpNode=Server::create(['name'=>'node 3','type'=>'vless','host'=>$jp->domain,'port'=>'1003','group_ids'=>['2']]);
$g->update(['probe_users'=>['1'=>$other->id,'2'=>$other->id]]);
expect($probe->user($g,Server::first())->id===$user->id,'level2 node uses level2 account');
expect($probe->user($jp,$jpNode)->id===$other->id,'level3 node uses level3 account');
$mixed=Server::create(['name'=>'mixed domain','type'=>'vless','host'=>$g->domain,'port'=>'1004','group_ids'=>['2']]);
expect($probe->user($g,$mixed)->id===$other->id,'same domain may use different permission group accounts');
$oldHash=$probe->fingerprint($g);
$alternate=User::create(['email'=>'alternate@example.test','uuid'=>'00000000-0000-4000-8000-000000000003',
    'group_id'=>2,'expired_at'=>time()+86400,'transfer_enable'=>100000]);
$saveUser(2,$alternate->id);
expect($probe->fingerprint($g)!==$oldHash,'mixed-domain fingerprint changes when one group account changes');
$saveUser(2,$other->id);
$alternate->delete();
$data=payload($controller->fetch());
expect(count($data['data']['probe_accounts'])===2&&$data['data']['permission_groups'][1]['name']==='level3','fetch returns group accounts and names');
expect(!array_key_exists('probe_users',$data['data']['groups'][0]),'retired per-node account settings not exposed');
expect(!str_contains(json_encode($data),'00000000-0000-4000'),'fetch never exposes proxy identity');
$data=payload($controller->users(request(['search'=>'example.test','group_id'=>2])))['data'];
expect(count($data)===1&&$data[0]['id']===$other->id,'search is restricted to selected permission group');
$data=payload($controller->users(request(['search'=>(string)$user->id,'group_id'=>2])))['data'];
expect($data===[],'ID search cannot bypass group filter');
$data=$saveUser(1,$other->id);
expect($data['status']==='fail'&&Setting::find(1)->probe_users_by_group[1]===$user->id,'wrong group user cannot overwrite mapping');
$g->update(['enabled'=>true]);
$data=$saveUser(2,null);
expect($data['status']==='fail'&&isset(Setting::find(1)->probe_users_by_group[2]),'enabled strategy blocks account mutation');
$controller->pause(request(['id'=>$g->id]));
$pending=operation($jp,'test');
$data=$saveUser(2,null);
expect($data['status']==='fail','pending operation blocks account mutation');
$pending->update(['status'=>'cancelled']);
$groupLock=Illuminate\Support\Facades\Cache::lock('node-automation:group:'.$jp->id,600);$groupLock->get();
$data=$saveUser(2,null);$groupLock->release();
expect($data['status']==='fail','in-flight detection lock blocks account mutation');
$g->update(['config_hash'=>'old-hash','failures'=>2]);
$b->update(['state'=>'ready','tested_at'=>now(),'tested_hash'=>'old-hash']);
$quarantined=ApiBackup::create(['group_id'=>$jp->id,'name'=>'old','ip'=>'9.9.9.9','state'=>'needs_review','enabled'=>false]);
ApiCheck::create(['group_id'=>$g->id,'ip'=>$g->current_ip,'status'=>'pass','checked_at'=>now()]);
$data=$saveUser(2,null);
expect($data['status']==='success'&&!isset(Setting::find(1)->probe_users_by_group[2]),'clear removes only selected group account');
expect($g->refresh()->config_hash===null&&$jp->refresh()->config_hash===null&&$g->failures===0,'account change invalidates configurations and failure streak');
expect($b->refresh()->state==='unchecked'&&$b->tested_hash===null&&ApiCheck::count()===0,'old observations and certifications cleared');
expect($quarantined->refresh()->state==='needs_review'&&!$quarantined->enabled,'account changes never rearm quarantined VPS');
try{$probe->user($jp,$jpNode);expect(false,'missing group account');}
catch(AutomationException $e){expect(str_contains($e->getMessage(),'公共检测账号'),'missing group never falls back to other or legacy accounts');}
$saveUser(2,$other->id);
$jpNode->update(['group_ids'=>['2','1']]);
expect($probe->user($jp,$jpNode)->id===$user->id,'multi-group selection uses stable ascending group IDs');
$user->update(['banned'=>true]);
expect($probe->user($jp,$jpNode)->id===$other->id,'multi-group node skips unusable candidate');
$user->update(['banned'=>false,'group_id'=>2]);
$jpNode->update(['group_ids'=>['1']]);
try{$probe->user($jp,$jpNode);expect(false,'account changed group');}
catch(AutomationException $e){expect(true,'account moved out of mapped group is rejected');}
$user->update(['group_id'=>1]);
$other->update(['expired_at'=>time()-1]);
$data=$saveUser(2,$other->id);
expect($data['status']==='fail','expired account cannot be saved');
$jpNode->update(['group_ids'=>['2']]);
try{$probe->user($jp,$jpNode);expect(false,'expired node account');}
catch(AutomationException $e){expect(true,'expired candidate rejected during node detection');}
// Independent traffic packages remain usable after the periodic plan expires.
$packageId=$db->table('v2_user_traffic_packages')->insertGetId(['user_id'=>$other->id,'status'=>'active','remaining_bytes'=>1000]);
expect(App\Services\ServerService::getAvailableUsers($jpNode)->contains('id',$other->id),'node access already accepts expired plan with active package');
$data=$saveUser(2,$other->id);
expect($data['status']==='success','expired periodic plan with active traffic package can be saved as probe account');
expect($probe->user($jp,$jpNode)->id===$other->id,'package-backed account can perform node detection');
$other->update(['expired_at'=>0,'plan_id'=>null,'transfer_enable'=>0]);
expect($saveUser(2,$other->id)['status']==='success'&&$probe->user($jp,$jpNode)->id===$other->id,'package-only account with no periodic quota is accepted');
$other->update(['banned'=>true]);
expect($saveUser(2,$other->id)['status']==='fail','active package never bypasses account ban');
$other->update(['banned'=>false,'custom_pending_order_id'=>1]);
expect($saveUser(2,$other->id)['status']==='fail','active package never bypasses pending order restriction');
$other->update(['custom_pending_order_id'=>null]);
expect($saveUser(1,$other->id)['status']==='fail','active package never bypasses permission-group ownership');
$db->table('v2_user_traffic_packages')->where('id',$packageId)->update(['status'=>'depleted']);
expect($saveUser(2,$other->id)['status']==='fail','inactive package with stale positive balance cannot grant probe access');
$db->table('v2_user_traffic_packages')->where('id',$packageId)->update(['status'=>'active','remaining_bytes'=>0]);
expect($saveUser(2,$other->id)['status']==='fail','active package without remaining quota cannot grant probe access');
$db->table('v2_user_traffic_packages')->where('id',$packageId)->delete();
$other->update(['transfer_enable'=>100000]);

$other->update(['expired_at'=>time()+86400,'custom_pending_order_id'=>1]);
try{$probe->user($jp,$jpNode);expect(false,'pending order node account');}
catch(AutomationException $e){expect(true,'pending custom order candidate rejected');}
$other->update(['custom_pending_order_id'=>null]);
$exclusive=$db->table('v2_plan')->insertGetId(['plan_type'=>'exclusive','group_id'=>2,'owner_user_id'=>999]);
try{$probe->user($jp,$jpNode);expect(false,'exclusive node account');}
catch(AutomationException $e){expect(true,'configured same-group account still requires actual exclusive node permission');}
$db->table('v2_plan')->where('id',$exclusive)->delete();
$user->update(['u'=>100000]);
$data=$saveUser(1,$user->id);
expect($data['status']==='fail'&&Setting::find(1)->probe_users_by_group[1]===$user->id,'invalid save leaves existing mapping intact');
$user->update(['u'=>0]);
$saveUser(1,$user->id);
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
