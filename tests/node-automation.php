<?php
declare(strict_types=1);

// Isolated integration: never boots Laravel, loads .env, or calls production DNS.
function admin_setting($key, $default = null) { return $default; }
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\{NodeAutomationConnection as Connection, NodeAutomationGroup as Group, NodeAutomationBackup as Backup, NodeAutomationLog as Event};
use App\Services\NodeAutomation\{AutomationService, AutomationException, CloudflareDns, ProxyProbe, DnsWriteRejectedException};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

$app = new Container(); Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('config', new Illuminate\Config\Repository(['node_automation' => require dirname(__DIR__) . '/config/node_automation.php']));
$db = new Capsule($app); $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal(); $db->bootEloquent();
$app->instance('db', $db->getDatabaseManager()); $app->instance('db.schema', $db->schema());
$app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
Model::encryptUsing(new Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'));
(require dirname(__DIR__) . '/database/migrations/2026_10_08_000001_create_node_automation_tables.php')->up();
(require dirname(__DIR__) . '/database/migrations/2026_10_08_000002_create_node_automation_checks_table.php')->up();
(require dirname(__DIR__) . '/database/migrations/2026_10_08_000003_create_node_automation_settings_table.php')->up();

class FakeDns extends CloudflareDns
{
    public array $record = ['id' => 'record-1', 'name' => 'us.example.test', 'type' => 'A', 'content' => '198.51.100.1', 'proxied' => false, 'ttl' => 60];
    public int $writes = 0;
    public bool $unavailable = false, $lostResponse = false, $rejectWrite = false, $loseReadAfterWrite = false;
    public function match(Connection $connection, string $domain): array { return $this->read($connection, 'record-1', $domain); }
    public function read(Connection $connection, string $recordId, string $domain): array {
        if ($this->unavailable) throw new AutomationException('模拟 DNS 不可用');
        return $this->record;
    }
    public function patch(Connection $connection, string $recordId, array $changes): void {
        $this->writes++;
        if ($this->rejectWrite) throw new DnsWriteRejectedException('模拟限流或权限错误');
        $this->record = array_merge($this->record, $changes);
        if ($this->loseReadAfterWrite) $this->unavailable = true;
        if ($this->lostResponse) throw new AutomationException('模拟响应丢失');
    }
}
class FakeProbe extends ProxyProbe
{
    public array $script = [], $calls = [];
    public bool $invalidUser = false, $brokenRuntime = false, $brokenTargets = false;
    public string $hash = 'config-1';
    public function fingerprint(Group $group): string {
        if ($this->invalidUser) throw new AutomationException('测试账号过期');
        return $this->hash;
    }
    public function readiness(): void { if ($this->brokenRuntime) throw new AutomationException('探针缺失'); }
    public function targets(): array {
        if ($this->brokenTargets) throw new AutomationException('目标不可用');
        return ['https://one.test/204', 'https://two.test/204'];
    }
    public function check(Group $group, string $ip, array $targets): array {
        $this->readiness(); $this->fingerprint($group); $this->calls[] = $ip;
        $steps = $this->script[$ip] ?? [false];
        $value = count($steps) > 1 ? array_shift($this->script[$ip]) : $steps[0];
        $nodes = is_array($value) ? $value : [['node_id' => 1, 'ok' => $value], ['node_id' => 2, 'ok' => $value]];
        return ['ok' => !in_array(false, array_column($nodes, 'ok'), true), 'nodes' => $nodes];
    }
}
$checks = 0;
function expect(bool $value, string $name): void { global $checks; if (!$value) throw new RuntimeException($name); $checks++; }
function fixture(): array {
    App\Models\NodeAutomationCheck::query()->delete();
    Event::query()->delete(); Backup::query()->delete(); Group::query()->delete(); Connection::query()->delete();
    $c = Connection::create(['api_token' => 'test-secret-never-log', 'zone_name' => 'example.test', 'zone_id' => 'zone-1', 'verified_at' => now()]);
    $g = Group::create(['domain' => 'us.example.test', 'connection_id' => $c->id, 'record_id' => 'record-1', 'current_ip' => '198.51.100.1',
        'config_hash' => 'config-1', 'enabled' => true, 'state' => 'healthy']);
    $b = Backup::create(['group_id' => $g->id, 'name' => 'backup 1', 'ip' => '198.51.100.2', 'priority' => 1, 'enabled' => true]);
    $dns = new FakeDns(); $probe = new FakeProbe(); $service = new AutomationService($dns, $probe);
    return [$g, $b, $dns, $probe, $service, $c];
}
function roundFor(Group $group, AutomationService $service): void {
    $group->update(['last_checked_at' => now()->subMinutes(2)]);
    $service->tick($group->id, time()); $group->refresh();
}
function operation(Group $g, string $action, ?int $backupId = null, array $extra = []): Event {
    return Event::create(array_merge(['group_id' => $g->id, 'operation_id' => (string) Str::uuid(), 'action' => $action, 'status' => 'queued', 'backup_id' => $backupId], $extra));
}

[$g,$b,$dns,$p,$s,$c] = fixture();
expect($c->getRawOriginal('api_token') !== 'test-secret-never-log' && !str_contains($c->toJson(), 'test-secret'), 'Token encrypted and excluded from serialization');
$p->script = [$g->current_ip => [false, true]];
roundFor($g,$s); expect($g->failures === 1 && $dns->writes === 0, 'first failure does not switch');
expect(App\Models\NodeAutomationCheck::where('group_id',$g->id)->first()->status==='fail','failed observation persisted independently from switch log');
roundFor($g,$s); expect($g->failures === 0 && $g->state === 'healthy', 'success clears transient failures');
expect(App\Models\NodeAutomationCheck::where('group_id',$g->id)->count()===1&&App\Models\NodeAutomationCheck::first()->status==='pass','latest observation updates without unbounded minute history');

[$g,$b,$dns,$p,$s] = fixture(); $p->script = ['198.51.100.1' => [false], '198.51.100.2' => [true]];
roundFor($g,$s); roundFor($g,$s); expect($dns->writes === 0 && $g->failures === 2, 'two failures leave DNS intact');
roundFor($g,$s); expect($dns->writes === 1 && $g->current_ip === $b->ip && $g->state === 'propagating', 'third failure switches and records propagation');
expect($g->cooldown_until->timestamp >= time()+599 && $g->failures === 0, 'switch starts ten minute cooldown');
expect(Backup::where('ip','198.51.100.1')->first()->state === 'needs_review', 'former primary quarantined');
expect(Backup::where('ip','198.51.100.1')->first()->enabled === false, 'former primary disabled');
expect(Event::first()->status === 'completed' && Event::first()->record_before['content'] === '198.51.100.1', 'durable DNS snapshot and completion');
$s->runOperation(Event::first()->id); expect($dns->writes === 1, 'completed job retry cannot repeat switch');
$p->script[$b->ip] = [false]; roundFor($g,$s); roundFor($g,$s); roundFor($g,$s);
expect($dns->writes === 1 && $g->current_ip === $b->ip, 'normal failure cannot bypass cooldown');

[$g,$b,$dns,$p,$s] = fixture(); $p->script = ['198.51.100.1' => [false], '198.51.100.2' => [[['node_id'=>1,'ok'=>true],['node_id'=>2,'ok'=>false]]]];
roundFor($g,$s); roundFor($g,$s); roundFor($g,$s);
expect($g->state === 'no_backup' && $dns->writes === 0, 'backup must pass every shared-domain node');
expect($b->refresh()->state === 'failed', 'failed backup result visible');

[$g,$b,$dns,$p,$s] = fixture(); $p->script = ['198.51.100.1'=>[false,false,false,true], '198.51.100.2'=>[true]];
roundFor($g,$s); roundFor($g,$s); roundFor($g,$s);
expect($dns->writes === 0 && Event::first()->status === 'cancelled', 'recovered primary cancels impending switch');

foreach (['invalidUser', 'brokenRuntime', 'brokenTargets'] as $error) {
    [$g,$b,$dns,$p,$s] = fixture(); $g->update(['failures'=>2]); $p->$error = true;
    roundFor($g,$s); expect($dns->writes === 0 && $g->failures === 0, $error . ' cannot count as VPS failure');
}
[$g,$b,$dns,$p,$s] = fixture(); $p->hash='changed'; roundFor($g,$s);
expect(!$g->enabled && $g->state === 'config_error' && $dns->writes === 0, 'node config drift stops automation');
[$g,$b,$dns,$p,$s] = fixture(); $dns->record['content']='198.51.100.99'; roundFor($g,$s);
expect(!$g->enabled && $g->state==='dns_conflict' && $dns->writes===0, 'external DNS IP edit stops automation');
[$g,$b,$dns,$p,$s] = fixture(); $p->script=[$g->current_ip=>[false]];
$s->tick($g->id,time()-121); expect($g->refresh()->failures===0, 'stale queued observations skipped');
$s->tick($g->id,time()); $s->tick($g->id,time()); expect($g->refresh()->failures===1, 'duplicate same-round delivery skipped');

[$g,$b,$dns,$p,$s] = fixture(); $g->update(['enabled'=>false]); $dns->record['ttl']=1;
$p->script=['198.51.100.1'=>[true], '198.51.100.2'=>[true]];
$e=operation($g,'enable'); $s->runOperation($e->id);
expect($g->refresh()->enabled && $dns->record['ttl']===60 && $e->refresh()->status==='completed', 'enable verifies baseline, backup and TTL');
expect($b->refresh()->state==='ready', 'enable tests the backup twice');
[$g,$b,$dns,$p,$s] = fixture(); $g->update(['enabled'=>false]); $dns->record['ttl']=1;
$p->script=['198.51.100.1'=>[true], '198.51.100.2'=>[true]]; $dns->rejectWrite=true;
$e=operation($g,'enable'); $s->runOperation($e->id);
expect(!$g->refresh()->enabled && $e->refresh()->status==='failed', 'missing DNS write permission never enables strategy');

[$g,$b,$dns,$p,$s] = fixture(); $g->update(['enabled'=>false]); $p->script=['198.51.100.2'=>[true]]; $dns->lostResponse=true;
$e=operation($g,'manual',$b->id); $s->runOperation($e->id);
expect($e->refresh()->status==='completed' && $g->refresh()->current_ip===$b->ip && $dns->writes===1, 'lost PATCH response reconciles successful write');
[$g,$b,$dns,$p,$s] = fixture(); $p->script=['198.51.100.2'=>[true]]; $dns->loseReadAfterWrite=true;
$e=operation($g,'manual',$b->id); $s->runOperation($e->id);
expect($e->refresh()->status==='dns_unknown' && $g->refresh()->current_ip==='198.51.100.1', 'unknown DNS write remains durable and unresolved');
$dns->unavailable=false; $s->runOperation($e->id);
expect($g->refresh()->current_ip===$b->ip && $dns->writes===1 && $e->refresh()->status==='completed', 'worker restart resolves intent without repeating PATCH');

[$g,$b,$dns,$p,$s] = fixture();
$e=operation($g,'manual',$b->id,['status'=>'prepared','from_ip'=>$g->current_ip,'to_ip'=>$b->ip,'record_before'=>$dns->record]);
$s->runOperation($e->id); expect($e->refresh()->status==='failed' && $dns->writes===0, 'crash before PATCH retains original DNS and does not blind retry');

[$g,$b,$dns,$p,$s] = fixture(); $p->script=['198.51.100.2'=>[true,true,false]];
$next=Backup::create(['group_id'=>$g->id,'name'=>'backup 2','ip'=>'198.51.100.3','priority'=>2,'enabled'=>true]); $p->script[$next->ip]=[true];
$e=operation($g,'manual',$b->id); $s->runOperation($e->id);
expect($dns->writes===2 && $g->refresh()->current_ip===$next->ip, 'failed new primary immediately proceeds to next verified backup');
expect($b->refresh()->state==='needs_review' && !$b->enabled, 'post-switch failed machine quarantined');

[$g,$b,$dns,$p,$s] = fixture(); $lock=Illuminate\Support\Facades\Cache::lock('node-automation:group:'.$g->id,600); $lock->get();
try { $s->tick($g->id,time()); expect(false,'concurrent domain lock rejected'); }
catch (AutomationException $e) { expect($dns->writes===0,'concurrent jobs cannot switch the same domain'); }
$lock->release();

[$g,$b,$dns,$p,$s] = fixture(); $g->update(['enabled'=>false]);
$p->script=['198.51.100.1'=>[true],'198.51.100.2'=>[true]]; $dns->rejectWrite=true;
$e=operation($g,'enable');$s->runOperation($e->id);
expect(!$g->refresh()->enabled && $e->refresh()->status==='failed','TTL already 60 cannot hide missing write permission');

[$g,$b,$dns,$p,$s] = fixture(); $dns->record['content']=$b->ip;
$e=operation($g,'manual',$b->id,['status'=>'prepared','from_ip'=>$g->current_ip,'to_ip'=>$b->ip,'record_before'=>$dns->record]);
$p->invalidUser=true;$s->runOperation($e->id);
expect($e->refresh()->status==='failed' && !$g->refresh()->enabled && $g->current_ip===$b->ip,'invalid identity after DNS commit ends pending operation without reverting IP');
$s->requireEditable($g); expect(true,'configuration can be repaired after committed DNS verification failure');

[$g,$b,$dns,$p,$s] = fixture(); $g->update(['current_ip'=>$b->ip]);
$e=operation($g,'manual',$b->id,['status'=>'verifying','from_ip'=>'198.51.100.1','to_ip'=>$b->ip]);
$s->locked($g->id,fn($group)=>$s->pause($group));
expect($e->refresh()->status==='cancelled' && !$s->pending($g),'pause cancels post-commit verification so configuration remains editable');

[$g,$b,$dns,$p,$s] = fixture();
$e=operation($g,'manual',$b->id,['status'=>'dns_unknown','from_ip'=>$g->current_ip,'to_ip'=>$b->ip]);
$s->locked($g->id,fn($group)=>$s->pause($group));
expect($e->refresh()->status==='dns_unknown' && $s->pending($g),'pause preserves unresolved DNS intent');

[$g,$b,$dns,$p,$s] = fixture();$g->update(['enabled'=>false]);$p->script=[$g->current_ip=>[true],$b->ip=>[true]];
$dns->loseReadAfterWrite=true;$e=operation($g,'enable');$s->runOperation($e->id);
$s->locked($g->id,fn($group)=>$s->pause($group));$dns->unavailable=false;$s->runOperation($e->id);
expect(!$g->refresh()->enabled&&$e->refresh()->status==='cancelled'&&$dns->writes===1,'recovered enable intent respects administrator pause');
echo "node automation: {$checks} integration checks passed\n";
