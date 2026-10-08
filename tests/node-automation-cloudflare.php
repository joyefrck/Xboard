<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use App\Models\NodeAutomationConnection as Connection;
use App\Services\NodeAutomation\{CloudflareDns, AutomationException, DnsConflictException, DnsWriteRejectedException};
use Illuminate\Container\Container;
use Illuminate\Support\Facades\{Facade, Http};
use Illuminate\Http\Client\Factory;
use Illuminate\Database\Eloquent\Model;

$app=new Container(); Container::setInstance($app);Facade::setFacadeApplication($app);
Model::encryptUsing(new Illuminate\Encryption\Encrypter(random_bytes(32),'AES-256-CBC'));
$connection=(new Connection())->setDateFormat('Y-m-d H:i:s')->forceFill(['api_token'=>'isolated-test-token','zone_name'=>'example.test','zone_id'=>'zone-1','verified_at'=>now()]);
$dns=new CloudflareDns();$checks=0;
function expect(bool $value,string $message):void { global $checks;if(!$value)throw new RuntimeException($message);$checks++; }
function fake(array $result,int $status=200,bool $success=true):void {
    Http::swap(new Factory());Http::preventStrayRequests();Http::fake(fn()=>Http::response(['success'=>$success,'result'=>$result],$status));
}
function rejected(callable $fn,string $class,string $message):void {
    try{$fn();expect(false,$message);}catch(AutomationException $e){expect($e instanceof $class,$message);}
}
$a=['id'=>'record-1','name'=>'us.example.test','type'=>'A','content'=>'8.8.8.8','ttl'=>60,'proxied'=>false];
fake([$a]);expect($dns->match($connection,$a['name'])===$a,'single DNS-only A matches');
fake([array_merge($a,['name'=>'example.test'])]);expect($dns->match($connection,'example.test')['name']==='example.test','apex node domain is within the configured zone');
foreach(['127.0.0.1','10.0.0.1','100.64.0.1','224.0.0.1','198.18.0.1','203.0.113.10'] as $ip){
    fake([array_merge($a,['content'=>$ip])]);rejected(fn()=>$dns->match($connection,$a['name']),DnsConflictException::class,'non-public current VPS rejected '.$ip);
}
foreach([
    [$a,array_merge($a,['id'=>'record-2','content'=>'8.8.4.4'])],
    [$a,array_merge($a,['type'=>'AAAA','content'=>'2001:4860:4860::8888'])],
    [array_merge($a,['type'=>'CNAME','content'=>'other.example.test'])],
    [array_merge($a,['proxied'=>true])],
    [array_merge($a,['content'=>'::1'])],
    [array_merge($a,['name'=>'foreign.example.test'])],
    [],
] as $i=>$records) {
    fake($records);rejected(fn()=>$dns->match($connection,$a['name']),DnsConflictException::class,'DNS conflict case '.$i);
}
fake([$a]);rejected(fn()=>$dns->match($connection,'evil-example.test'),AutomationException::class,'suffix lookalike cannot escape zone');
expect(Http::recorded()->isEmpty(),'out-of-zone rejected before API request');
fake([$a]);rejected(fn()=>$dns->read($connection,'replaced-id',$a['name']),DnsConflictException::class,'replaced record ID blocks automatic changes');
fake([['id'=>'zone-1','name'=>'example.test']]);expect($dns->verify($connection)==='zone-1','zone verification requires readable DNS');
fake([]);rejected(fn()=>$dns->verify($connection),AutomationException::class,'unknown zone rejected');
fake([['id'=>'zone-1','name'=>'example.test'],['id'=>'zone-2','name'=>'example.test']]);
rejected(fn()=>$dns->verify($connection),AutomationException::class,'ambiguous zone rejected');
foreach([401,403,429] as $status) {
    fake([],$status,false);rejected(fn()=>$dns->match($connection,$a['name']),AutomationException::class,'read failure '.$status);
    fake([],$status,false);rejected(fn()=>$dns->patch($connection,'record-1',['content'=>'8.8.4.4']),DnsWriteRejectedException::class,'definite write rejection '.$status);
}
fake([],503,false);rejected(fn()=>$dns->patch($connection,'record-1',['content'=>'8.8.4.4']),AutomationException::class,'server error remains uncertain write');
fake([],200,false);rejected(fn()=>$dns->patch($connection,'record-1',['ttl'=>60]),DnsWriteRejectedException::class,'explicit unsuccessful API result is a definite rejection');
fake($a);$dns->patch($connection,'record-1',['content'=>'8.8.4.4']);
expect(Http::recorded(fn($r)=>$r->method()==='PATCH'&&str_ends_with($r->url(),'/zones/zone-1/dns_records/record-1')&&$r->data()===['content'=>'8.8.4.4'])->count()===1,'PATCH targets bound record and preserves other attributes');
Http::swap(new Factory());Http::preventStrayRequests();Http::fake(fn()=>throw new Illuminate\Http\Client\ConnectionException('test transport failure'));
try{$dns->patch($connection,'record-1',['content'=>'8.8.4.4']);expect(false,'transport failure');}
catch(AutomationException $e){expect(!$e instanceof DnsWriteRejectedException&&!str_contains($e->getMessage(),'isolated-test-token'),'timeout is uncertain and message has no credentials');}
echo "node automation Cloudflare: {$checks} checks passed\n";
