<?php
declare(strict_types=1);
// Isolated: no Laravel boot, .env, provider calls or production database.
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Models\BandwagonVps;
use App\Services\Bandwagon\{CsvImporter, KiwiVmClient, InventoryService, TrafficLookup, VpsData, BandwagonException};
use App\Http\Controllers\V2\Admin\Server\BandwagonController;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Facade, Http, Cache};
use Illuminate\Http\Request;
use Illuminate\Http\Client\Factory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Routing\ResponseFactory;

$app = new Container; Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('app', $app);
$app->instance('config', new Illuminate\Config\Repository());
$db = new Capsule($app); $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); $db->setAsGlobal(); $db->bootEloquent();
$app->instance('db', $db->getDatabaseManager()); $app->instance('db.schema', $db->schema());
$app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$app->instance(ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory { public function __construct() {} });
Model::encryptUsing(new Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'));
$bus = new class($app) extends Illuminate\Bus\Dispatcher {
    public array $jobs = [];
    public function dispatch($job) { $this->jobs[] = $job; return count($this->jobs); }
};
$app->instance(BusDispatcher::class, $bus);
$validator = new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'), $app);
$app->instance('translator', $validator->getTranslator());
$validator->setPresenceVerifier(new Illuminate\Validation\DatabasePresenceVerifier($db->getDatabaseManager()));
Request::macro('validate', function ($rules) use ($validator) { return $validator->make($this->all(), $rules)->validate(); });
Illuminate\Pagination\Paginator::currentPathResolver(fn () => '/isolated');
Illuminate\Pagination\Paginator::currentPageResolver(fn () => 1);

$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
function rejected(callable $action, string $name): string {
    try { $action(); } catch (BandwagonException $e) { check(true, $name); return $e->getMessage(); }
    throw new RuntimeException($name . ' did not reject');
}
function fakeHttp($callback): void { Http::swap((new Factory)->preventStrayRequests()); Http::fake($callback); }
function req(array $data): Request { return Request::create('/isolated', 'POST', $data); }
function body($response): array { return $response->getData(true); }

$lookup = new TrafficLookup;
check($lookup->index() === [], 'automation tolerates pre-migration inventory');
$migration = require dirname(__DIR__) . '/database/migrations/2026_11_02_000001_create_bandwagon_vps_table.php'; $migration->up();
$db->schema()->create('v2_node_automation_groups', function ($t) { $t->increments('id'); $t->string('domain'); $t->string('current_ip')->nullable(); });
$db->schema()->create('v2_node_automation_backups', function ($t) { $t->increments('id'); $t->integer('group_id'); $t->string('ip'); $t->integer('priority')->default(0); });
$db->schema()->create('v2_server', function ($t) { $t->increments('id'); $t->string('host'); });
$csv = "\xEF\xBB\xBFVEID,VM_TYPE,HOSTNAME,PRIMARY_IP,IS_TERMINATED,IS_2FA_ENABLED,API_KEY\r\n" .
    '123,kvm,"host, one",198.51.100.1,0,0,private_fixture_key_one' . "\r\n" .
    '124,kvm,host-two,,0,1,private_fixture_key_two' . "\r\n" .
    '125,kvm,terminated,,1,0,' . "\r\n" .
    '123,kvm,duplicate,,0,0,private_other_fixture_key' . "\r\n" .
    '126,kvm,invalid,not-an-ip,0,0,private_fixture_key_bad';
$importer = new CsvImporter; $client = new KiwiVmClient; $service = new InventoryService($client);
$rows = $importer->parse($csv);
check(array_column($rows, 'status') === ['valid','valid','terminated','duplicate','invalid'], 'CSV classifies quoted, empty IP, terminated, duplicate and invalid rows');
check($rows[0]['hostname'] === 'host, one' && $rows[1]['ip'] === '', 'RFC CSV quotes and missing IP preserved');
check(!str_contains(json_encode($importer->preview($rows)), 'private_'), 'preview never contains API keys');
foreach (['', 'VEID,API_KEY\n1,private_fixture', "VEID,API_KEY,IS_TERMINATED,VEID\n1,private_fixture,0,1", str_repeat('a', CsvImporter::MAX_BYTES + 1)] as $bad) {
    rejected(fn () => $importer->parse($bad), 'invalid/bounded CSV rejected');
}
rejected(fn () => $importer->parse("VEID,API_KEY,IS_TERMINATED\n" . str_repeat("1,private_fixture,0\n",501)), '500-row bound enforced');
$result = $service->import($rows);
check($result === ['created'=>2,'updated'=>0,'skipped'=>3,'busy'=>0] && BandwagonVps::count() === 2, 'only valid distinct active instances imported');
$vps = BandwagonVps::where('veid','123')->first();
check($vps->getRawOriginal('api_key') !== 'private_fixture_key_one' && $vps->api_key === 'private_fixture_key_one', 'API key encrypted at rest and decrypts for provider');
check(!str_contains($vps->toJson(), 'private_') && !array_key_exists('api_key', $vps->toArray()), 'model excludes secret');
check(count($bus->jobs) === 2 && $bus->jobs[0]->connection === 'redis' && $bus->jobs[0]->queue === 'bandwagon', 'independent queue receives ID-only jobs');
check(!str_contains(serialize($bus->jobs[0]), 'private_'), 'queue payload has no credentials');
check(!$service->enqueue($vps->id) && count($bus->jobs) === 2, 'pending synchronization deduplicated');
$info = ['error'=>0,'hostname'=>'current-host','vm_type'=>'kvm','node_location'=>'US, Los Angeles','node_alias'=>'Node1',
    'plan'=>'fixture-2g','os'=>'debian-12','ip_addresses'=>['198.51.100.9','2001:db8::1'], 'data_counter'=>'536870912000',
    'plan_monthly_data'=>'1073741824000', 'monthly_data_multiplier'=>'2','data_next_reset'=>time()+86400,
    'plan_ram'=>'2147483648','plan_disk'=>'21474836480','plan_swap'=>0,'suspended'=>'0',
    'api_key'=>'should-never-persist','email'=>'private@example.test','screendump_png_base64'=>'large-sensitive-screenshot'];
$live = array_merge($info, ['ve_status'=>'Running','mem_available_kb'=>'1048576','swap_total_kb'=>'0','swap_available_kb'=>'0',
    'load_average'=>'0.10 0.20 0.30','ve_used_disk_space_b'=>'1073741824','is_cpu_throttled'=>'0','is_disk_throttled'=>'1','ssh_port'=>'22']);
fakeHttp(['*/getServiceInfo' => Http::response($info), '*/getLiveServiceInfo' => Http::response($live)]);
$service->sync($vps->id, $vps->sync_token); $vps->refresh();
check($vps->synced_at !== null && $vps->live_synced_at !== null && $vps->queued_at === null, 'background sync persists both snapshots and clears pending');
check($vps->hostname === 'current-host' && $vps->live['is_disk_throttled'] === true && $vps->info['suspended'] === false, 'numeric-string flags normalized');
check(!str_contains($vps->toJson(), 'should-never-persist') && !str_contains($vps->toJson(), 'screendump') && !str_contains($vps->toJson(), 'private@example'), 'provider fields allowlisted');
check(Http::recorded(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.64clouds.com/v1/getServiceInfo' && $r['api_key'] === 'private_fixture_key_one')->count() === 1, 'provider credentials sent only in POST body');
check(VpsData::usage($vps->info)['percent'] === 50.0 && VpsData::usage($vps->info)['total_bytes'] === 2147483648000.0, 'quota and counter multiplier applied equally');
check(VpsData::usage(['data_counter'=>1,'plan_monthly_data'=>0]) === null, 'zero quota does not divide by zero');
$index = $lookup->index();
check($lookup->find($index,'198.51.100.9')['veid'] === '123' && $lookup->find($index,'198.51.100.1')['status'] === 'unmatched', 'automation matches current provider IP, not stale export IP');
check($lookup->find($index,'2001:0db8:0:0:0:0:0:1')['veid'] === '123', 'IPv6 normalized for exact address matching');
$sameAddresses = $vps->info;
$sameAddresses['ip_addresses'][] = '2001:0db8:0:0:0:0:0:1';
$vps->update(['info' => $sameAddresses]);
check($lookup->find($lookup->index(),'2001:db8::1')['status'] === 'matched', 'equivalent IPv6 addresses on one VPS do not conflict');
check($lookup->find($index,'198.51.100.90')['status'] === 'unmatched', 'IP matching cannot confuse prefix with full address');
$other = BandwagonVps::where('veid','124')->first();
$other->update(['info'=>$vps->info,'synced_at'=>now()]);
check($lookup->find($lookup->index(),'198.51.100.9')['status'] === 'conflict', 'ambiguous provider IP detected');
$other->update(['info'=>null,'synced_at'=>null]);
$vps->update(['synced_at'=>now()->subMinutes(20)]);
check($lookup->find($lookup->index(),'198.51.100.9')['stale'], 'old snapshots explicitly stale');
$lastInfo = $vps->info; $lastLive = $vps->live;
$vps->update(['queued_at'=>now(), 'sync_token'=>'failure-token']);
fakeHttp(['*' => Http::response(['error'=>6,'message'=>'private_fixture_key_one should never echo'])]);
$service->sync($vps->id,'failure-token');$vps->refresh();
check($vps->info === $lastInfo && $vps->live === $lastLive && $vps->last_error !== null, 'failure retains last good data');
check(!str_contains($vps->last_error,'private_'), 'provider error message never reflected');
$vps->update(['queued_at'=>now(), 'sync_token'=>'partial-token']);
fakeHttp(['*/getServiceInfo'=>Http::response($info), '*/getLiveServiceInfo'=>Http::response([],429)]);
$service->sync($vps->id,'partial-token');$vps->refresh();
check($vps->last_error === null && $vps->live_error !== null && $vps->live === $lastLive, 'live failure independent of successful quota refresh');
rejected(fn () => VpsData::live(['error'=>0]), 'empty live payload cannot replace valid runtime metrics');
foreach ([[[],200], [['error'=>'bad'],200], [['error'=>0],302], [['error'=>0],503]] as [$response,$code]) {
    fakeHttp(['*'=>Http::response($response,$code)]);
    rejected(fn () => $client->call($vps,'getServiceInfo'), 'malformed or HTTP error rejected');
    check(Http::recorded()->count() === 1, 'supplier request count');
}
rejected(fn () => $client->call($vps,'reinstallOS'), 'unapproved endpoint inaccessible');
$before = count($bus->jobs); $vps->update(['name'=>'keep this name']);
$updated = $rows[0];$updated['api_key']='private_rotated_fixture_key';
$service->import([$updated]);$vps->refresh();
check($vps->name === 'keep this name' && $vps->info === null && $vps->live === null && !$vps->synced_at, 'credential replacement preserves note but invalidates snapshots');
check($vps->api_key === 'private_rotated_fixture_key' && count($bus->jobs) === $before+1, 'credential replacement queues fresh verification');
fakeHttp(['*'=>Http::response($info)]);
$service->sync($vps->id,'old-token');check(Http::recorded()->isEmpty(), 'supplier never called');
check($lookup->find($lookup->index(),'198.51.100.9')['status'] === 'unmatched', 'rotated credentials cannot supply stale IP matches');
$held = Cache::lock('bandwagon:vps:123',65);$held->get();
rejected(fn () => $service->resetPassword($vps->id,'123'), 'per-VPS mutation lock enforced');$held->release();
fakeHttp(['*'=>Http::response(['error'=>0,'password'=>'fixture-new-root-secret'])]);
rejected(fn () => $service->resetPassword($vps->id,'124'), 'target VEID confirmation enforced');check(Http::recorded()->isEmpty(), 'supplier never called');
$password = $service->resetPassword($vps->id,'123');$vps->refresh();
check($password['password'] === 'fixture-new-root-secret' && $vps->reset_state === 'completed', 'confirmed single-instance reset returns password once');
check(!str_contains(json_encode($db->table('v2_bandwagon_vps')->get()),'fixture-new-root-secret'), 'new root password never stored');
rejected(fn () => $service->resetPassword($vps->id,'123'), 'reset cooldown prevents immediate repeat');check(Http::recorded()->count() === 1, 'supplier request count');
$vps->refresh()->update(['reset_requested_at'=>now()->subMinutes(6)]);
$attempts = 0;
fakeHttp(function () use (&$attempts) { $attempts++; throw new Illuminate\Http\Client\ConnectionException('body with private_rotated_fixture_key'); });
$msg = rejected(fn () => $service->resetPassword($vps->id,'123'), 'ambiguous reset not reported as success');$vps->refresh();
check($vps->reset_state === 'unknown' && !str_contains($msg,'private_'), 'ambiguous reset recorded without secrets');check(Http::recorded()->isEmpty(), 'supplier never called');
check($attempts === 1, 'password transport failure is never automatically retried');
$vps->refresh()->update(['reset_requested_at'=>now()->subMinutes(6)]);
fakeHttp(['*'=>Http::response(['error'=>0])]);
rejected(fn () => $service->resetPassword($vps->id,'123'), 'missing reset password is unknown');check(Http::recorded()->count() === 1, 'supplier request count');

$controller = new BandwagonController($service,$importer);
$vps->refresh()->update(['info' => VpsData::service($info)]);
$db->table('v2_server')->insert([['host'=>'CURRENT.EXAMPLE.'], ['host'=>'backup.example']]);
$db->table('v2_node_automation_groups')->insert([['id'=>1,'domain'=>'current.example','current_ip'=>'198.51.100.9'], ['id'=>2,'domain'=>'backup.example','current_ip'=>null], ['id'=>3,'domain'=>'removed.example','current_ip'=>'198.51.100.9']]);
$db->table('v2_node_automation_backups')->insert([['group_id'=>2,'ip'=>'198.51.100.9','priority'=>1], ['group_id'=>1,'ip'=>'198.51.100.9','priority'=>1], ['group_id'=>3,'ip'=>'198.51.100.9','priority'=>1]]);
$response = $controller->preview(req(['csv'=>$csv]));
check($response->headers->get('Cache-Control') === 'no-store, private' && !str_contains($response->getContent(),'private_'), 'preview API non-cacheable and secret-free');
$data = body($controller->fetch(req(['search'=>'keep this','status'=>'all'])));
check($data['data']['total'] === 1 && $data['data']['data'][0]['veid'] === '123', 'search and pagination use cached inventory');
check($data['data']['data'][0]['ip_domains']['198.51.100.9'] === ['current.example','backup.example'], 'current and backup domains match, deduplicate, and exclude removed node domains');
check(body($controller->fetch(req(['search'=>'CURRENT.EXA'])))['data']['total'] === 1, 'partial case-insensitive current domain search matches');
check(body($controller->fetch(req(['search'=>'backup.example'])))['data']['total'] === 1, 'backup domain search matches');
check(body($controller->fetch(req(['search'=>'removed.example'])))['data']['total'] === 0, 'removed node domain does not match search');
check(!str_contains(json_encode($data),'private_'), 'inventory API secret-free');
$suspendedInfo = $vps->info; $suspendedInfo['suspended'] = true;
$vps->update(['info'=>$suspendedInfo, 'synced_at'=>now()]);
check(body($controller->fetch(req(['search'=>'keep this'])))['data']['total'] === 0, 'suspended VPS excluded from inventory');
check(body($controller->fetch(req(['search'=>'backup.example'])))['data']['total'] === 0, 'domain search excludes suspended VPS');
check($lookup->find($lookup->index(),'198.51.100.9')['status'] === 'unmatched', 'suspended VPS excluded from automation IP matching');
$vps->update(['info'=>array_merge($suspendedInfo, ['suspended'=>false])]);
check(body($controller->fetch(req(['search'=>'keep this'])))['data']['total'] === 1, 'restored VPS reappears after successful sync');
$vps->update(['info'=>array_merge($suspendedInfo, ['suspended'=>null])]);
check(body($controller->fetch(req(['search'=>'keep this'])))['data']['total'] === 1, 'unknown suspension remains visible');
$controller->rename(req(['id'=>$vps->id,'name'=>'renamed']));check($vps->fresh()->name === 'renamed','note edit persists');
// Successful password response is no-store and remains absent from later fetches.
$vps->refresh()->update(['reset_requested_at'=>now()->subMinutes(6)]);fakeHttp(['*'=>Http::response(['error'=>0,'password'=>'once-only-password'])]);
$response = $controller->resetPassword(req(['id'=>$vps->id,'confirm_veid'=>'123']));
check(body($response)['data']['password'] === 'once-only-password' && str_contains($response->headers->get('Cache-Control'),'no-store'), 'password endpoint response is non-cacheable');
check(!str_contains($controller->fetch(req([]))->getContent(),'once-only-password'), 'later inventory reads cannot retrieve password');
$controller->drop(req(['id'=>$vps->id]));check(!BandwagonVps::find($vps->id),'remove drops local record only');
check(Http::recorded()->count() === 1, 'supplier request count');
$service->sync($vps->id,'deleted-token');check(Http::recorded()->count() === 1, 'supplier request count');
$migration->down();check(!$db->schema()->hasTable('v2_bandwagon_vps'),'migration rolls back');
echo "Bandwagon: {$checks} isolated integration checks passed\n";
