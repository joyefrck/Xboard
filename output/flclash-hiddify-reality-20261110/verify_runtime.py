import copy
import hashlib
import http.client
import json
import os
from pathlib import Path
import socket
import ssl
import struct
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parent
PAYLOAD = b'verified-hybrid-reality-payload\n' * 4096


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2))


def prepare():
    repo = ROOT.parents[1]
    xray = repo.parent / 'ElephantNetwork/output/linux-reality-20261109/xray'
    raw = subprocess.check_output(['docker', 'run', '--rm', '--network', 'none', '-v', str(xray) + ':/xray:ro', 'debian:bookworm-slim', '/xray', 'x25519'], text=True)
    keys = dict(line.split(': ', 1) for line in raw.strip().splitlines())
    private = keys.get('PrivateKey', keys.get('Private key'))
    public = keys.get('Password (PublicKey)', keys.get('Password', keys.get('Public key')))
    assert private and public
    node = {
        'name': 'REALITY-TEST', 'type': 'vless', 'host': '127.0.0.1', 'port': 18443,
        'password': '11111111-1111-4111-8111-111111111111',
        'protocol_settings': {
            'tls': 2, 'network': 'tcp', 'flow': 'xtls-rprx-vision',
            'network_settings': {'header': {'type': 'none'}},
            'backend': {'core': 'xray', 'release': 'core-v1.1.0'},
            'reality_settings': {'server_name': 'fixture.test', 'public_key': public, 'short_id': '0123456789abcdef'},
        },
    }
    for name, ua in [('flclash', 'FlClash/v0.9.3 clash-verge Platform/linux'), ('hiddify', 'HiddifyNext/5.5.5 (linux) like ClashMeta v2ray sing-box')]:
        raw = subprocess.check_output(['php', str(repo / 'tests/flclash-hiddify-reality-subscription.php'), '--render'], input=json.dumps({'ua': ua, 'nodes': [node]}).encode())
        rendered = json.loads(raw)
        assert rendered['status'] == 200, rendered
        write_json(ROOT / (name + '-rendered.json'), rendered)
    write_json(ROOT / 'xray.json', {
        'log': {'loglevel': 'debug'},
        'inbounds': [{
            'listen': '127.0.0.1', 'port': 18443, 'protocol': 'vless',
            'settings': {'clients': [{'id': node['password'], 'flow': 'xtls-rprx-vision'}], 'decryption': 'none'},
            'streamSettings': {'network': 'tcp', 'security': 'reality', 'realitySettings': {
                'show': True, 'target': '127.0.0.1:19443', 'serverNames': ['fixture.test'],
                'privateKey': private, 'shortIds': ['0123456789abcdef'],
            }},
        }],
        'outbounds': [{'protocol': 'freedom', 'settings': {'finalRules': [{'action': 'allow', 'ip': ['127.0.0.1/32']}]}}],
    })
    subprocess.run(['/opt/homebrew/opt/openssl@3/bin/openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=fixture.test', '-addext', 'subjectAltName=DNS:fixture.test,IP:127.0.0.1', '-keyout', str(ROOT / 'test-key.pem'), '-out', str(ROOT / 'test-cert.pem')], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    os.chmod(ROOT / 'test-key.pem', 0o600)
    os.chmod(ROOT / 'xray.json', 0o600)
    print('Prepared actual Xboard subscriptions with ephemeral fixture credentials')


def wait_port(port, process):
    for _ in range(100):
        if process.poll() is not None:
            raise RuntimeError('fixture process exited: ' + str(process.args))
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.1):
                return
        except OSError:
            time.sleep(.05)
    raise RuntimeError('listener did not open: ' + str(port))


def stop(process):
    if process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()


def probe(port, name, https=False):
    client = None
    try:
        if https:
            client = http.client.HTTPSConnection('127.0.0.1', port, timeout=5, context=ssl._create_unverified_context())
            client.set_tunnel('127.0.0.1', 19443)
            client.request('GET', '/' + name)
        else:
            client = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
            client.request('GET', 'http://127.0.0.1:18080/' + name)
        response = client.getresponse()
        data = response.read()
        return {'connected': response.status == 200 and data == PAYLOAD, 'status': response.status, 'bytes': len(data)}
    except (OSError, http.client.HTTPException) as error:
        return {'connected': False, 'error': str(error)}
    finally:
        if client:
            client.close()


def exact(conn, size):
    data = b''
    while len(data) < size:
        part = conn.recv(size - len(data))
        if not part:
            raise RuntimeError('IPC disconnected')
        data += part
    return data


def verify():
    os.chdir(ROOT)
    processes, logs = [], []
    results = {'cases': [], 'network': 'isolated Docker --network none, loopback only', 'tls_target': 'TLS 1.3, X25519MLKEM768 only'}
    flcore = ROOT / 'flclash/opt/FlClash/FlClashCore'
    hidcore = ROOT / 'hiddify/hiddify-core-linux-arm64/hiddify-core'
    results['core_sha256'] = {name: hashlib.sha256(path.read_bytes()).hexdigest() for name, path in [('flclash-0.9.3', flcore), ('hiddify-5.5.5', hidcore)]}
    results['xray_version'] = subprocess.check_output(['/xray', 'version'], text=True).splitlines()[0]
    results['hiddify_version'] = subprocess.check_output([str(hidcore), 'version'], text=True).strip()
    def start(args, name, env=None):
        log = (ROOT / (name + '.log')).open('w')
        logs.append(log)
        process = subprocess.Popen(args, stdout=log, stderr=log, env=env)
        processes.append(process)
        return process
    server = None
    conn = None
    try:
        target = start([str(ROOT / 'tls-target'), str(ROOT / 'test-cert.pem'), str(ROOT / 'test-key.pem')], 'target')
        wait_port(19443, target)
        xray = start(['/xray', 'run', '-c', str(ROOT / 'xray.json')], 'xray-runtime')
        wait_port(18443, xray)
        home = Path('/tmp/flclash-fixture')
        home.mkdir(exist_ok=True)
        sock = home / 'ipc'
        if sock.exists():
            sock.unlink()
        server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        server.bind(str(sock))
        server.listen(1)
        server.settimeout(15)
        fl = start([str(flcore), str(sock)], 'flclash-runtime')
        conn, _ = server.accept()
        conn.settimeout(20)
        counter = 0
        def call(method, arguments=None):
            nonlocal counter
            counter += 1
            ident = str(counter)
            data = json.dumps({'id': ident, 'method': method, 'arguments': arguments}).encode()
            conn.sendall(struct.pack('<I', len(data)) + data)
            while True:
                answer = json.loads(exact(conn, struct.unpack('<I', exact(conn, 4))[0]))
                if answer.get('id') != ident:
                    with (ROOT / 'flclash-events.jsonl').open('a') as out:
                        out.write(json.dumps(answer) + '\n')
                    continue
                assert not answer.get('error'), answer
                return answer.get('result')
        call('core.init', {'home-dir': str(home), 'version': 903})
        call('log.start')
        rendered = json.loads((ROOT / 'flclash-rendered.json').read_text())['config']
        for mode in ['hybrid', 'wrong-short-id', 'missing-hybrid-flag', 'hybrid-recheck']:
            config = copy.deepcopy(rendered)
            config.update({'mixed-port': 18801, 'mode': 'rule', 'log-level': 'debug', 'find-process-mode': 'off', 'dns': {'enable': False}})
            opts = config['proxies'][0]['reality-opts']
            if mode == 'wrong-short-id':
                opts['short-id'] = 'ffffffffffffffff'
            if mode == 'missing-hybrid-flag':
                opts['support-x25519mlkem768'] = False
            write_json(home / 'config.yaml', config)
            assert call('config.validate', {'path': str(home / 'config.yaml')}) is None
            call('connection.closeAll')
            call('config.setup', {'selected-map': {'select': 'REALITY-TEST'}, 'options': {'test-url': 'http://127.0.0.1:18080'}})
            call('listener.start')
            wait_port(18801, fl)
            result = {'client': 'flclash-0.9.3', 'mode': mode, 'http': probe(18801, 'flclash-' + mode)}
            expected = mode in ['hybrid', 'hybrid-recheck']
            if expected:
                result['https'] = probe(18801, 'flclash-https-' + mode, True)
            results['cases'].append(result)
            write_json(ROOT / 'runtime-results.json', results)
            print(json.dumps(result), flush=True)
            assert result['http']['connected'] == expected, result
            if expected:
                assert result['https']['connected'], result
        call('listener.stop')
        conn.close()
        conn = None
        fl.wait(timeout=10)
        assert fl.returncode == 0
        rendered = json.loads((ROOT / 'hiddify-rendered.json').read_text())['config']
        for mode in ['hybrid', 'wrong-short-id', 'classical', 'hybrid-recheck']:
            config = copy.deepcopy(rendered)
            config['log'] = {'level': 'debug', 'timestamp': True}
            config['inbounds'] = [{'type': 'mixed', 'listen': '127.0.0.1', 'listen_port': 18802}]
            opts = next(p for p in config['outbounds'] if p['type'] == 'vless')['tls']['reality']
            if mode == 'wrong-short-id':
                opts['short_id'] = 'ffffffffffffffff'
            if mode == 'classical':
                opts['key_share'] = 'classical'
            path = ROOT / ('hiddify-' + mode + '.json')
            write_json(path, config)
            env = os.environ.copy()
            env['LD_LIBRARY_PATH'] = str(hidcore.parent)
            hid = start([str(hidcore), 'srun', '-c', str(path)], 'hiddify-' + mode, env)
            wait_port(18802, hid)
            result = {'client': 'hiddify-5.5.5', 'mode': mode, 'http': probe(18802, 'hiddify-' + mode)}
            expected = mode in ['hybrid', 'hybrid-recheck']
            if expected:
                result['https'] = probe(18802, 'hiddify-https-' + mode, True)
            results['cases'].append(result)
            write_json(ROOT / 'runtime-results.json', results)
            print(json.dumps(result), flush=True)
            assert result['http']['connected'] == expected, result
            if expected:
                assert result['https']['connected'], result
            stop(hid)
        results['passed'] = True
        write_json(ROOT / 'runtime-results.json', results)
    finally:
        if conn:
            conn.close()
        if server:
            server.close()
        for process in reversed(processes):
            stop(process)
        for log in logs:
            log.close()


if __name__ == '__main__':
    prepare() if '--prepare' in sys.argv else verify()
