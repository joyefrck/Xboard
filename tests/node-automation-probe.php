<?php
declare(strict_types=1);

function admin_setting($key, $default = null) { return $default; }
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Models\{NodeAutomationGroup as Group, NodeAutomationSetting as Setting, Server, User};
use App\Services\NodeAutomation\{ProxyProbe, ProbeProtocol, AutomationException};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;

$taskDir = sys_get_temp_dir() . '/xboard-probe-test-' . bin2hex(random_bytes(8)); mkdir($taskDir,0700);
$app = new class($taskDir) extends Container {
    public function __construct(private string $dir) {}
    public function storagePath($path = ''): string { return $this->dir . '/' . $path; }
};
Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('app', $app);
$settings = require dirname(__DIR__) . '/config/node_automation.php';
$settings['binary'] = getenv('NODE_AUTOMATION_TEST_BINARY') ?: '/usr/local/bin/sing-box';
$app->instance('config', new Illuminate\Config\Repository(['node_automation'=>$settings]));
$db = new Capsule($app); $db->addConnection(['driver'=>'sqlite','database'=>':memory:']); $db->setAsGlobal(); $db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$db->schema()->create('v2_server', function(Blueprint $t) {
    $t->increments('id'); $t->string('code')->nullable(); $t->string('name'); $t->string('type'); $t->string('host');
    $t->string('port'); $t->json('protocol_settings'); $t->integer('parent_id')->nullable(); $t->json('group_ids')->nullable();
    $t->integer('created_at'); $t->integer('updated_at');
});
$db->schema()->create('v2_user', function(Blueprint $t) {
    $t->increments('id'); $t->string('uuid'); $t->integer('group_id'); $t->integer('plan_id')->nullable();
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
$user=User::create(['uuid'=>'00000000-0000-4000-8000-000000000001','group_id'=>1,'expired_at'=>time()+86400,'transfer_enable'=>1000000000]);
$db->schema()->create('v2_node_automation_settings', function(Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('probe_user_id')->nullable(); $t->json('probe_users_by_group')->nullable(); $t->timestamps();
});
Setting::create(['probe_users_by_group'=>[1=>$user->id]]);
$group=new Group(['domain'=>'probe.example.test','config_hash'=>'test']);
$probe=new ProxyProbe(); $probe->readiness();
$checks=0;
function expect(bool $condition,string $message): void { global $checks; if(!$condition) throw new RuntimeException($message); $checks++; }
function port(): int { $s=stream_socket_server('tcp://127.0.0.1:0'); $p=(int) substr(strrchr(stream_socket_get_name($s,false),':'),1); fclose($s); return $p; }
function writeConfig(string $path,array $config): void { file_put_contents($path,json_encode($config,JSON_THROW_ON_ERROR)); chmod($path,0600); }

$cert=$taskDir.'/cert.pem'; $key=$taskDir.'/key.pem';
$generate=new Process(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
    '-subj','/CN=probe.example.test','-addext','subjectAltName=DNS:probe.example.test,IP:127.0.0.1',
    '-keyout',$key,'-out',$cert],timeout:8);
$generate->disableOutput();$generate->run();expect($generate->isSuccessful(),'temporary test certificate generated');chmod($key,0600);
$targetServers=[];$targetUrls=[];
foreach([1,2] as $target){
    $targetPort=port();$targetUrls[]='https://127.0.0.1:'.$targetPort.'/generate_204';
    $process=new Process([PHP_BINARY,__DIR__.'/node-automation-204.php',$cert,$key,(string)$targetPort],timeout:120);
    $process->disableOutput();$process->start();$targetServers[]=$process;
}
$app['config']->set('node_automation.targets',$targetUrls);usleep(150000);
$probe=new class($cert) extends ProxyProbe {
    public function __construct(private string $cert) {}
    protected function client():GuzzleHttp\Client {
        return new GuzzleHttp\Client(array_replace(parent::client()->getConfig(),['verify'=>$this->cert]));
    }
};

$node=Server::create(['name'=>'Probe','type'=>'shadowsocks','host'=>'probe.example.test','port'=>port(),
    'group_ids'=>['1'],'protocol_settings'=>['cipher'=>'aes-128-gcm']]);
expect($probe->user($group,$node)->id===$user->id,'eligible test identity accepted');
$user->update(['expired_at'=>time()-1]);
try{$probe->user($group,$node);expect(false,'expired account rejected');}catch(AutomationException $e){expect(true,'expired account rejected');}
$user->update(['expired_at'=>time()+86400,'banned'=>1]);
try{$probe->user($group,$node);expect(false,'banned account rejected');}catch(AutomationException $e){expect(true,'banned account rejected');}
$user->update(['banned'=>0,'u'=>1000000000]);
try{$probe->user($group,$node);expect(false,'exhausted account rejected');}catch(AutomationException $e){expect(true,'exhausted account rejected');}
$user->update(['u'=>0,'group_id'=>2]);
try{$probe->user($group,$node);expect(false,'foreign group rejected');}catch(AutomationException $e){expect(true,'foreign group rejected');}
$user->update(['group_id'=>1]);

$protocol=new ProbeProtocol();
foreach (['shadowsocks','vmess','vless','trojan','hysteria','tuic','anytls','socks','http'] as $type) {
    $testNode=new Server(['name'=>'Test','type'=>$type,'host'=>'original.example.test','port'=>'443',
        'protocol_settings'=>match($type){'shadowsocks'=>['cipher'=>'aes-128-gcm'],'hysteria'=>['version'=>2],default=>[]}]);
    $testNode->id=100; $outbound=$protocol->outbound($testNode,$user,'127.0.0.1');
    expect($outbound['server']==='127.0.0.1','pinned IP for '.$type);
    if (isset($outbound['tls'])) expect($outbound['tls']['server_name']==='original.example.test','original SNI preserved for '.$type);
    $path=$taskDir.'/protocol.json'; writeConfig($path,['outbounds'=>[$outbound]]);
    $check=new Process([$settings['binary'],'check','-c',$path],timeout:4); $check->run();
    expect($check->isSuccessful(),'sing-box validates '.$type.': '.$check->getErrorOutput());
}

$serverConfig=['log'=>['disabled'=>true],'inbounds'=>[['type'=>'shadowsocks','listen'=>'127.0.0.1','listen_port'=>(int)$node->port,
    'method'=>'aes-128-gcm','password'=>$user->uuid]],'outbounds'=>[['type'=>'direct']]];
$serverPath=$taskDir.'/server.json'; writeConfig($serverPath,$serverConfig);
$server=new Process([$settings['binary'],'run','-c',$serverPath],timeout:60); $server->disableOutput();
try {
    $server->start();
    $deadline=microtime(true)+2;
    do{$socket=@stream_socket_client('tcp://127.0.0.1:'.$node->port,$errno,$err,0.05);if($socket)fclose($socket);else usleep(20000);}while(!$socket&&microtime(true)<$deadline);
    expect((bool)$socket,'real proxy test server listening');
    $targets=$probe->targets();
    expect($probe->check($group,'127.0.0.1',$targets)['ok'],'real Shadowsocks authenticated proxy completes HTTPS 204');
    $server->stop(1);
    expect(!$probe->check($group,'127.0.0.1',$targets)['ok'],'server stopped is a proxy failure');
    // A TCP listener alone must not be considered a working proxy.
    $listener=stream_socket_server('tcp://127.0.0.1:'.$node->port);
    try{expect(!$probe->check($group,'127.0.0.1',$targets)['ok'],'open port without protocol is not healthy');}finally{fclose($listener);}
    $server->start(); usleep(100000); $user->uuid='00000000-0000-4000-8000-000000000002'; $user->save();
    expect(!$probe->check($group,'127.0.0.1',$targets)['ok'],'wrong proxy password is not healthy');
    $server->stop(1);
    // QUIC transport must pass a real UDP handshake, even if TCP is reachable.
    $user->update(['uuid'=>'00000000-0000-4000-8000-000000000001']);
    $node->update(['type'=>'hysteria','port'=>(string)port(),'protocol_settings'=>['version'=>2,'tls'=>['server_name'=>'probe.example.test']]]);
    writeConfig($serverPath,['log'=>['disabled'=>true],'inbounds'=>[['type'=>'hysteria2','listen'=>'127.0.0.1',
        'listen_port'=>(int)$node->port,'users'=>[['password'=>$user->uuid]],
        'tls'=>['enabled'=>true,'certificate_path'=>$cert,'key_path'=>$key]]],'outbounds'=>[['type'=>'direct']]]);
    $udpServer=new Process([$settings['binary'],'run','-c',$serverPath],timeout:60);$udpServer->disableOutput();
    try {
        $udpServer->start();usleep(150000);
        expect($udpServer->isRunning(),'real Hysteria2 server starts');
        expect(!$probe->check($group,'127.0.0.1',$targets)['ok'],'untrusted Hysteria2 certificate fails verification');
        // Pin this disposable fixture certificate, keeping TLS verification enabled.
        $trustedProbe=new class($cert) extends ProxyProbe {
            public function __construct(private string $cert) {}
            protected function client():GuzzleHttp\Client {
                return new GuzzleHttp\Client(array_replace(parent::client()->getConfig(),['verify'=>$this->cert]));
            }
            protected function run(array $config,array $requests,array $ports,int $targetCount):array {
                foreach($config['outbounds'] as &$outbound)$outbound['tls']['certificate_path']=$this->cert;
                unset($outbound);
                return parent::run($config,$requests,$ports,$targetCount);
            }
        };
        expect($trustedProbe->check($group,'127.0.0.1',$targets)['ok'],'real Hysteria2 UDP proxy completes HTTPS 204 with verified TLS');
        $udpServer->stop(1);
        $listener=stream_socket_server('tcp://127.0.0.1:'.$node->port);
        try{expect(!$trustedProbe->check($group,'127.0.0.1',$targets)['ok'],'TCP reachable but UDP proxy unavailable is a failure');}
        finally{fclose($listener);}
    } finally {
        if($udpServer->isRunning())$udpServer->stop(1);
    }
    expect(!(glob($taskDir.'/app/node-automation-probes/probe-*')?:[]),'temporary credentials removed after all probe runs');
} finally {
    if($server->isRunning())$server->stop(1);
    foreach($targetServers as $targetServer)if($targetServer->isRunning())$targetServer->stop(1);
    foreach(glob($taskDir.'/*')?:[] as $path)if(is_file($path))@unlink($path);
    @rmdir($taskDir.'/app/node-automation-probes');@rmdir($taskDir.'/app');@rmdir($taskDir);
}
echo "node automation real probe: {$checks} checks passed\n";
