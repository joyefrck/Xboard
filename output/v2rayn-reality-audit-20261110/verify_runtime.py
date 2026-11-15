import copy
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from urllib.parse import parse_qs, urlsplit

sys.path.insert(0, '/fixture')
import verify_runtime as fixture

ROOT = Path('/work')
FIXTURE = Path('/fixture')
APP = ROOT / 'package/opt/v2rayN'
processes, logs = [], []
results = {'app': 'v2rayN 7.25.6', 'network': 'Docker --network none; loopback only', 'scope': 'Actual Xboard subscription controller output mapped according to pinned official v2rayN importer and Xray config builder; GUI importer not executed', 'cases': []}


def start(args, name):
    log = (ROOT / (name + '.log')).open('w')
    logs.append(log)
    p = subprocess.Popen(args, stdout=log, stderr=log)
    processes.append(p)
    return p


try:
    target = start([str(FIXTURE / 'tls-target'), str(FIXTURE / 'test-cert.pem'), str(FIXTURE / 'test-key.pem')], 'target')
    fixture.wait_port(19443, target)
    server = start(['/xray', 'run', '-c', str(FIXTURE / 'xray.json')], 'server')
    fixture.wait_port(18443, server)
    binary = APP / 'bin/xray/xray'
    results['xray_version'] = subprocess.check_output([str(binary), 'version'], text=True).splitlines()[0]
    results['xray_sha256'] = hashlib.sha256(binary.read_bytes()).hexdigest()
    uri = urlsplit((ROOT / 'vless-uri.txt').read_text())
    query = {k: v[0] for k, v in parse_qs(uri.query).items()}
    assert query['fp'] == 'chrome' and query['security'] == 'reality'
    for mode in ['hybrid', 'wrong-short-id', 'hybrid-recheck']:
        config = {
            'log': {'loglevel': 'debug'},
            'inbounds': [{'listen': '127.0.0.1', 'port': 18803, 'protocol': 'http', 'settings': {}}],
            'outbounds': [{
                'protocol': 'vless',
                'settings': {'address': uri.hostname, 'port': uri.port, 'id': uri.username, 'flow': query['flow'], 'encryption': query['encryption']},
                'streamSettings': {
                    'network': 'raw', 'security': query['security'],
                    'realitySettings': {'serverName': query['sni'], 'publicKey': query['pbk'], 'shortId': 'ffffffffffffffff' if mode == 'wrong-short-id' else query['sid'], 'fingerprint': query['fp'], 'spiderX': query['spx']},
                },
            }],
        }
        path = ROOT / ('client-' + mode + '.json')
        fixture.write_json(path, config)
        client = start([str(binary), 'run', '-c', str(path)], 'xray-' + mode)
        fixture.wait_port(18803, client)
        result = {'mode': mode, 'http': fixture.probe(18803, 'v2rayn-' + mode)}
        expected = mode != 'wrong-short-id'
        if expected:
            result['https'] = fixture.probe(18803, 'v2rayn-https-' + mode, True)
        results['cases'].append(result)
        fixture.write_json(ROOT / 'runtime-results.json', results)
        print(json.dumps(result), flush=True)
        assert result['http']['connected'] == expected, result
        if expected:
            assert result['https']['connected'], result
        fixture.stop(client)
    results['passed'] = True
    fixture.write_json(ROOT / 'runtime-results.json', results)
finally:
    for p in reversed(processes):
        fixture.stop(p)
    for log in logs:
        log.close()
