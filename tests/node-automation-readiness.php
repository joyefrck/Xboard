<?php
declare(strict_types=1);

// Deterministic replay of Mihomo's startup order: listeners open first, traffic is refused until it is running.
// Disposable loopback fixture; never boots the application or reads .env.
function admin_setting($key, $default = null) { return $default; }
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\NodeAutomation\{AutomationException, MihomoProbeRuntime, ProxyProbe};
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

$directory = sys_get_temp_dir() . '/xboard-readiness-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$fake = $directory . '/mihomo';
file_put_contents($fake, <<<'PY'
#!/usr/bin/env python3
import json, os, socket, sys, threading, time
if '-t' in sys.argv:
    sys.exit(0)
path = sys.argv[sys.argv.index('-f') + 1]
config = json.load(open(path))
folder = os.path.dirname(os.path.abspath(sys.argv[0]))
fixture = json.load(open(folder + '/fixture.json'))
with open(folder + '/config.json', 'w') as output:
    json.dump(config, output)
running_at = time.monotonic() + fixture['gap']

def pipe(source, sink):
    try:
        while data := source.recv(65536):
            sink.sendall(data)
        sink.shutdown(socket.SHUT_WR)
    except OSError:
        pass

def handle(client, proxy):
    try:
        if not client.recv(257):  # greeting lists any number of authentication methods
            return
        client.sendall(b'\x05\x00')
        request = client.recv(512)
        port = int.from_bytes(request[-2:], 'big')
        client.sendall(b'\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00')  # Mihomo acknowledges before it decides
        if time.monotonic() < running_at or proxy == fixture['broken']:
            return
        with socket.create_connection(('127.0.0.1', port), timeout=2) as upstream:
            back = threading.Thread(target=pipe, args=(upstream, client), daemon=True)
            back.start()
            pipe(client, upstream)
            back.join(2)
    except OSError:
        pass
    finally:
        client.close()

def serve(listener, proxy):
    while True:
        client, _ = listener.accept()
        threading.Thread(target=handle, args=(client, proxy), daemon=True).start()

for item in config['listeners']:
    listener = socket.create_server((item['listen'], item['port']))
    threading.Thread(target=serve, args=(listener, item['proxy']), daemon=True).start()
time.sleep(30)
PY);
chmod($fake, 0700);
$server = proc_open(['python3', '-c', <<<'PY'
import http.server
class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(204)
        self.end_headers()
    def log_message(self, *args):
        pass
httpd = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
print(httpd.server_port, flush=True)
httpd.serve_forever()
PY], [1 => ['pipe', 'w']], $pipes);
$url = 'http://127.0.0.1:' . (int) fgets($pipes[1]) . '/generate_204';
$dump = $directory . '/config.json';

$app = new class($directory) extends Container {
    public function __construct(private string $directory) {}
    public function storagePath($path = ''): string { return $this->directory . '/' . $path; }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('app', $app);
$app->instance('config', new Illuminate\Config\Repository(['node_automation' => [
    'mihomo_binary' => $fake, 'timeout_binary' => trim((string) shell_exec('command -v timeout')), 'request_timeout' => 4,
]]));

$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
$run = function (float $gap, int $nodes = 1, string $broken = '') use ($url, $directory) {
    file_put_contents($directory . '/fixture.json', json_encode(['gap' => $gap, 'broken' => $broken]));
    $ports = $requests = $proxies = $held = [];
    for ($id = 1; $id <= $nodes; $id++) {
        $held[] = $socket = stream_socket_server('tcp://127.0.0.1:0');
        $ports[$id] = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        $proxies[] = ['name' => 'node-' . $id, 'type' => 'direct'];
        $requests[] = ['url' => $url, 'proxy' => 'socks5h://127.0.0.1:' . $ports[$id], 'connect_timeout' => 4];
    }
    array_map('fclose', $held);
    $configuration = (new MihomoProbeRuntime())->configuration($proxies, $ports);
    return (new ReflectionMethod(ProxyProbe::class, 'run'))->invoke(new ProxyProbe(), $configuration, $requests, $ports, 1);
};

try {
    check($run(0)['ok'], 'ready runtime rejected');
    $configuration = json_decode(file_get_contents($dump), true);
    $ready = array_values(array_filter($configuration['listeners'], fn ($item) => $item['name'] === 'probe-ready'));
    check(count($ready) === 1 && count($configuration['listeners']) === 2, 'exactly one readiness listener');
    check($ready[0]['listen'] === '127.0.0.1' && $ready[0]['proxy'] === 'DIRECT' && $ready[0]['udp'] === false, 'readiness listener must stay on loopback');
    check($configuration['rules'] === ['MATCH,REJECT'], 'direct fallback must stay blocked for nodes');
    check(array_column($configuration['proxies'], 'name') === ['node-1'], 'node proxies must stay unchanged');

    for ($round = 0; $round < 3; $round++) {
        check($run(0.4)['ok'], 'traffic sent inside the startup gap was counted as a node failure');
    }
    $many = $run(0.4, 4);
    check($many['ok'] && array_column($many['nodes'], 'node_id') === [1, 2, 3, 4], 'multi-node group failed inside the startup gap');

    $partial = $run(0.2, 2, 'node-2');
    check(!$partial['ok'] && array_column($partial['nodes'], 'ok', 'node_id') === [1 => true, 2 => false], 'a genuinely broken node must still fail');

    try {
        $run(20);
        check(false, 'never-ready runtime was judged');
    } catch (AutomationException $e) {
        check(str_contains($e->getMessage(), '本轮不计节点故障'), 'never-ready runtime must not count as a node failure');
    }
    check(!(glob($directory . '/app/node-automation-probes/probe-*') ?: []), 'probe files were not removed');
    echo "Node automation readiness: {$checks} checks passed\n";
} finally {
    proc_terminate($server);
    (new Illuminate\Filesystem\Filesystem())->deleteDirectory($directory);
}
