import argparse
from contextlib import ExitStack
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import tempfile
import threading
import time


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def stop(process):
    if process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()


def main(server_binary, mihomo_binary, client_baseline=False, subscription_baseline=False):
    server_binary, mihomo_binary = str(Path(server_binary).resolve()), str(Path(mihomo_binary).resolve())
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix='xboard-reality-runtime-') as temporary, ExitStack() as cleanup:
        folder = Path(temporary)
        cert, key = folder / 'cert.pem', folder / 'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=fixture.test', '-addext', 'subjectAltName=DNS:fixture.test,IP:127.0.0.1', '-keyout', str(key), '-out', str(cert)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        os.chmod(key, 0o600)
        uuid = '11111111-1111-4111-8111-111111111111'
        pair = subprocess.check_output([server_binary, 'x25519'], input=b'n\n').decode()
        private = pair.split('Private key: ')[1].splitlines()[0]
        public = pair.split('Public key: ')[1].splitlines()[0]
        port, camouflage_port = free_port(), free_port()
        settings = {'tls': 2, 'network': 'tcp', 'network_settings': {}, 'flow': 'xtls-rprx-vision', 'decryption': 'none', 'server_port': port, 'tls_settings': {'server_name': 'fixture.test', 'dest': '127.0.0.1', 'server_port': str(camouflage_port), 'private_key': private, 'short_id': '0123456789abcdef'}, 'base_config': {'pull_interval': 60, 'push_interval': 60}}

        class Target(BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(204)
                self.end_headers()

            def log_message(self, *args):
                pass

        class Panel(BaseHTTPRequestHandler):
            def do_GET(self):
                action = self.path.split('?', 1)[0].rsplit('/', 1)[-1]
                result = settings if action == 'config' else {'users': [{'id': 7, 'uuid': uuid}]} if action == 'user' else {'alive': {}}
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps(result).encode())

            def do_POST(self):
                self.rfile.read(int(self.headers.get('Content-Length', 0)))
                self.send_response(200)
                self.end_headers()
                self.wfile.write(b'{}')

            def log_message(self, *args):
                pass

        def serve(handler, encrypted=False):
            httpd = ThreadingHTTPServer(('127.0.0.1', 0), handler)
            if encrypted:
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                context.load_cert_chain(cert, key)
                httpd.socket = context.wrap_socket(httpd.socket, server_side=True)
            threading.Thread(target=httpd.serve_forever, daemon=True).start()
            cleanup.callback(httpd.server_close)
            cleanup.callback(httpd.shutdown)
            return httpd

        target, panel = serve(Target, True), serve(Panel)
        camouflage_log = cleanup.enter_context(open(folder / 'camouflage.log', 'wb'))
        camouflage = subprocess.Popen(['openssl', 's_server', '-accept', f'127.0.0.1:{camouflage_port}', '-cert', str(cert), '-key', str(key), '-tls1_3', '-groups', 'X25519', '-alpn', 'h2', '-www', '-quiet'], stdout=camouflage_log, stderr=subprocess.STDOUT)
        cleanup.callback(stop, camouflage)
        for _ in range(100):
            if camouflage.poll() is not None:
                raise RuntimeError('REALITY camouflage fixture exited before readiness')
            try:
                with socket.create_connection(('127.0.0.1', camouflage_port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('REALITY camouflage fixture did not listen')
        server_config = {'Log': {'Level': 'info'}, 'Cores': [{'Type': 'xray', 'Log': {'Level': os.getenv('V2BX_TEST_LOG', 'error')}, 'AssetPath': str(folder)}], 'Nodes': [{'Core': 'xray', 'ApiHost': f'http://127.0.0.1:{panel.server_port}', 'ApiKey': 'fixture', 'NodeID': 1, 'NodeType': 'vless', 'ListenIP': '127.0.0.1', 'AllowPrivateNetwork': True}]}
        server_path = folder / 'server.json'
        server_path.write_text(json.dumps(server_config))
        os.chmod(server_path, 0o600)
        server_log = cleanup.enter_context(open(folder / 'server.log', 'wb'))
        server = subprocess.Popen([server_binary, 'server', '-c', str(server_path), '--watch=false'], cwd=folder, stdout=server_log, stderr=subprocess.STDOUT)
        cleanup.callback(stop, server)
        ready = False
        for _ in range(100):
            if server.poll() is not None:
                break
            if 'Nodes started' in (folder / 'server.log').read_text(errors='replace'):
                ready = True
                break
            time.sleep(.1)
        if not ready:
            raise RuntimeError('REALITY fixture did not start: ' + (folder / 'server.log').read_text()[-2000:])
        fixture = {'directory': str(folder), 'mihomo': mihomo_binary, 'timeout': shutil.which('timeout'), 'certificate': str(cert), 'uuid': uuid, 'target': f'https://127.0.0.1:{target.server_port}/generate_204', 'node': {'id': 4, 'name': 'REALITY fixture', 'type': 'vless', 'host': 'fixture.test', 'port': str(port), 'protocol_settings': {'tls': 2, 'network': 'tcp', 'flow': 'xtls-rprx-vision', 'utls': {'enabled': True, 'fingerprint': 'chrome'}, 'backend': {'core': 'xray', 'release': 'core-v1.1.0'}, 'reality_settings': {'server_name': 'fixture.test', 'public_key': public, 'short_id': '0123456789abcdef'}}}}
        fixture['client_baseline'] = client_baseline
        fixture['subscription_baseline'] = subscription_baseline
        def probe():
            try:
                subprocess.run([os.getenv('PHP_BINARY', 'php'), str(root / 'tests/node-automation-reality-runtime.php')], input=json.dumps(fixture).encode(), check=True, timeout=120)
            except subprocess.CalledProcessError:
                print((folder / 'server.log').read_text()[-12000:])
                print((folder / 'camouflage.log').read_text()[-4000:])
                raise

        probe()
        stop(server)
        fixture['stopped'] = True
        probe()
        for _ in range(50):
            output = subprocess.check_output(['ps', '-eo', 'args']).decode()
            if not any(mihomo_binary in line and str(folder) in line for line in output.splitlines()):
                break
            time.sleep(.04)
        else:
            raise RuntimeError('probe child process leaked')
        print('PASS: no Mihomo process remains after the real REALITY checks')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('server_binary')
    parser.add_argument('mihomo_binary')
    parser.add_argument('--client-baseline', action='store_true')
    parser.add_argument('--subscription-baseline', action='store_true')
    args = parser.parse_args()
    main(args.server_binary, args.mihomo_binary, args.client_baseline, args.subscription_baseline)
