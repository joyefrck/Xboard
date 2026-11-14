import concurrent.futures
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time
import urllib.request
from zoneinfo import ZoneInfo

B = Path('/root/xboard-ticket-bot-20261114')
M = json.loads((B / 'manifest.json').read_text())
R = Path(M['production_root'])
C = (B / 'commit').read_text().strip()
PREFIX = 'https://raw.githubusercontent.com/joyefrck/Xboard/' + C + '/'
SCRIPTS = 'scripts/releases/20261114-ticket-bot/'


def run(args, **kwargs):
    result = subprocess.run(args, capture_output=True, **kwargs)
    if result.returncode:
        (B / 'last-command-error.log').write_bytes(result.stdout + result.stderr)
        raise RuntimeError('COMMAND_FAILED:' + ' '.join(args[:6]))
    return result.stdout


def fetch(url, headers=None):
    request = urllib.request.Request(url, headers=headers or {'User-Agent': 'Xboard-release-check'})
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def save(name, data):
    (B / name).write_text(json.dumps(data, indent=2))


def runtime():
    rows = json.loads(run(['docker', 'inspect', 'index-web-1', 'index-horizon-1']))
    return [{'name': d['Name'], 'image': d['Image'], 'image_ref': d['Config']['Image'], 'running': d['State']['Running'],
             'restarts': d['RestartCount'], 'init': d['HostConfig'].get('Init'),
             'mounts': [{k: m[k] for k in ['Type', 'Source', 'Destination']} for m in d['Mounts']],
             'networks': sorted(d['NetworkSettings']['Networks'])} for d in rows]


def snapshot(name, strict=True):
    args = ['docker', 'exec', '-i', 'index-horizon-1', 'php', '--', '--root=/www']
    if (B / 'started.iso').exists(): args.append('--since=' + (B / 'started.iso').read_text().strip())
    data = json.loads(run(args, input=(B / 'queue-snapshot.php').read_bytes()))
    save(name + '.json', data)
    print('QUEUE', json.dumps({'sample': name, 'at': data['sample_at'], 'coverage': data['coverage_ok'],
        'failed': data['new_failed_jobs'], 'environment': [data['app_environment'], data['horizon_environment'], data['selected_environment_pattern']],
        'nonempty': {k: [v['ready'], v['reserved'], v['delayed'], v['ready_head_age_seconds']] for k, v in data['queues'].items() if v['ready'] or v['reserved'] or v['delayed']}}), flush=True)
    if strict: assert data['coverage_ok'], 'QUEUE_COVERAGE_FAILED'
    return data


def acceptance(mode, name):
    args = ['docker', 'exec', '-i', 'index-horizon-1', 'php', '--', '--mode=' + mode]
    if mode == 'subscription': args.append('--strict')
    if (B / 'started.iso').exists(): args.append('--since=' + (B / 'started.iso').read_text().strip())
    data = json.loads(run(args, input=(B / 'business-verify.php').read_bytes()))
    save(name + '.json', data)
    print(mode.upper(), json.dumps({k: v for k, v in data.items() if k not in ['completed_job_evidence', 'results']}), flush=True)
    return data


def scope(phase):
    data = json.loads(run(['docker', 'exec', '-i', 'index-web-1', 'php', '--', '--phase=' + phase], input=(B / 'scope-verify.php').read_bytes()))
    save('scope-' + phase + '.json', data)
    assert data['ok'], 'TICKET_BOT_SCOPE_FAILED'
    print('TICKET_BOT_SCOPE', json.dumps(data), flush=True)


def check_files(section):
    before = json.loads((B / 'file-baseline.json').read_text())
    for target, item in M['files'].items():
        expected = before[target] if section == 'before' else item['after']
        assert digest(R / target) == expected, 'FILE_DRIFT:' + target
    runtime_file = 'runtime-before.json' if section == 'before' else 'runtime-after-reload.json'
    assert runtime() == json.loads((B / runtime_file).read_text()), 'RUNTIME_DRIFT'
    for file in ['config/horizon.php', 'config/queue.php', 'docker-compose.yaml']:
        assert digest(R / file) == digest(B / 'before' / file), 'CONFIG_DRIFT:' + file


def release_evidence(run_id):
    ci = json.loads(fetch('https://api.github.com/repos/joyefrck/Xboard/actions/runs/' + run_id))
    assert ci['head_sha'] == C and ci['conclusion'] == 'success' and ci['path'] == '.github/workflows/docker-publish.yml', 'CI_NOT_VERIFIED'
    save('ci-result.json', {k: ci[k] for k in ['id', 'head_sha', 'conclusion', 'html_url']})
    bearer = json.loads(fetch('https://ghcr.io/token?service=ghcr.io&scope=repository:joyefrck/xboard:pull'))['token']
    headers = {'Authorization': 'Bearer ' + bearer, 'Accept': 'application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json'}
    def registry(path): return fetch('https://ghcr.io/v2/joyefrck/xboard/' + path, headers)
    raw = registry('manifests/sha-' + C)
    index = json.loads(raw)
    result = {'commit': C, 'index_digest': 'sha256:' + hashlib.sha256(raw).hexdigest(), 'platforms': []}
    for arch in ['amd64', 'arm64']:
        image = next(x for x in index['manifests'] if x.get('platform', {}).get('os') == 'linux' and x['platform']['architecture'] == arch)
        manifest = json.loads(registry('manifests/' + image['digest']))
        config = json.loads(registry('blobs/' + manifest['config']['digest']))
        revision = config['config']['Labels']['org.opencontainers.image.revision']
        assert revision == C, 'IMAGE_REVISION_MISMATCH'
        result['platforms'].append({'architecture': arch, 'digest': image['digest'], 'revision': revision})
    save('image-verification.json', result)
    print('CI_IMAGE_VERIFIED', C, result['index_digest'], flush=True)


def prepare():
    assert not (B / 'prepared').exists(), 'ALREADY_PREPARED'
    rows = runtime()
    for row in rows:
        assert row['running'] and row['init'], 'CONTAINER_NOT_HEALTHY'
        assert {'Type': 'bind', 'Source': str(R), 'Destination': '/www'} in row['mounts'], 'WRONG_BIND_MOUNT'
    baseline = {target: digest(R / target) for target in M['files']}
    bad = [target for target, sha in baseline.items() if sha not in M['files'][target]['allowed_before']]
    if bad:
        save('baseline-drift.json', {f: baseline[f] for f in bad})
        print('BASELINE_DRIFT', json.dumps({f: baseline[f] for f in bad}), flush=True)
        raise RuntimeError('PRODUCTION_FILE_DRIFT')
    save('file-baseline.json', baseline)
    save('runtime-before.json', rows)
    for file in list(M['files']) + ['config/horizon.php', 'config/queue.php', 'docker-compose.yaml']:
        if not (R / file).is_file(): continue
        target = B / 'before' / file
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(R / file, target)
    def stage(pair):
        target, item = pair
        body = fetch(PREFIX + item['source'])
        assert hashlib.sha256(body).hexdigest() == item['after'], 'SOURCE_HASH_MISMATCH:' + target
        path = B / 'stage' / target
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)
        if target.endswith('.php'): run(['docker', 'exec', '-i', 'index-web-1', 'php', '-l'], input=body)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool: list(pool.map(stage, M['files'].items()))
    for name in ['queue-snapshot.php', 'business-verify.php', 'scope-verify.php']:
        body = fetch(PREFIX + SCRIPTS + name)
        assert hashlib.sha256(body).hexdigest() == M[name.replace('.php', '_sha256')], 'VERIFY_SCRIPT_DRIFT'
        (B / name).write_bytes(body)
    scope('before')
    q = snapshot('before')
    provisioning = json.loads(run(['docker', 'exec', 'index-horizon-1', 'php', 'scripts/check-horizon-provisioning.php', '--environment=' + q['horizon_environment']]))
    assert provisioning['ok'], 'PROVISIONING_FAILED'
    save('provisioning-before.json', provisioning)
    save('git-before.json', {'head': run(['git', '-c', 'safe.directory=' + str(R), '-C', str(R), 'rev-parse', 'HEAD']).decode().strip(),
        'tracked_modified_count': len(run(['git', '-c', 'safe.directory=' + str(R), '-C', str(R), 'status', '--porcelain', '--untracked-files=no']).splitlines())})
    acceptance('business', 'business-before')
    health()
    (B / 'prepared').touch()
    print('PREPARED', C, 'FILES', len(M['files']), 'BACKUP', str(B), flush=True)


def install_file(target, section):
    expected = M['files'][target]['after'] if section == 'after' else json.loads((B / 'file-baseline.json').read_text())[target]
    destination = R / target
    if expected is None:
        destination.unlink(missing_ok=True)
        return
    source = B / ('stage' if section == 'after' else 'before') / target
    assert digest(source) == expected, 'STAGED_OR_BACKUP_HASH_MISMATCH:' + target
    destination.parent.mkdir(parents=True, exist_ok=True)
    previous = destination.stat() if destination.exists() else destination.parent.stat()
    temporary = destination.with_name(destination.name + '.ticket-bot-stage')
    shutil.copyfile(source, temporary)
    os.chown(temporary, previous.st_uid, previous.st_gid)
    os.chmod(temporary, previous.st_mode & 0o777 if destination.exists() else 0o644)
    os.replace(temporary, destination)


def install(section):
    targets = sorted(M['files'], key=lambda f: (f not in ['app/Logging/SensitiveData.php', 'app/Services/Auth/SessionSecurityService.php', 'app/Services/Auth/PasswordSecurityService.php'], f))
    for target in targets: install_file(target, section)


def reload():
    expected = runtime()
    horizon = next(row for row in expected if row['name'] == '/index-horizon-1')
    # Production runs `php artisan horizon` as its main process. Graceful
    # termination exits that process, so Docker's existing restart policy
    # performs exactly one planned restart of this container.
    horizon['restarts'] += 1
    run(['docker', 'exec', 'index-web-1', 'php', 'artisan', 'view:clear'], timeout=120)
    run(['docker', 'exec', 'index-web-1', 'php', 'artisan', 'octane:reload'], timeout=120)
    run(['docker', 'exec', 'index-horizon-1', 'php', 'artisan', 'horizon:terminate'], timeout=120)
    deadline = time.monotonic() + 360
    while time.monotonic() < deadline:
        actual = runtime()
        for row in actual:
            target = next(item for item in expected if item['name'] == row['name'])
            assert row['restarts'] <= target['restarts'], 'UNEXPECTED_CONTAINER_RESTART'
        if actual == expected:
            status = subprocess.run(['docker', 'exec', 'index-horizon-1', 'php', 'artisan', 'horizon:status'], capture_output=True)
            if status.returncode == 0:
                save('runtime-after-reload.json', actual)
                print('GRACEFUL_RELOAD_VERIFIED', json.dumps({row['name']: row['restarts'] for row in actual}), flush=True)
                return
        time.sleep(1)
    raise RuntimeError('HORIZON_GRACEFUL_RELOAD_TIMEOUT')


def health():
    php = '$z=0;foreach(glob("/proc/[0-9]*/stat") as $p){if(preg_match("/^\\\\d+ \\\\(.*\\\\) Z /",file_get_contents($p)))$z++;}echo json_encode(["pid1"=>basename(explode(chr(0),file_get_contents("/proc/1/cmdline"))[0]),"zombies"=>$z,"pids_current"=>trim(file_get_contents("/sys/fs/cgroup/pids.current")),"pids_max"=>trim(file_get_contents("/sys/fs/cgroup/pids.max"))]);'
    rows = runtime()
    for row in rows:
        row.update(json.loads(run(['docker', 'exec', row['name'].lstrip('/'), 'php', '-r', php])))
        assert row['zombies'] == 0 and row['pid1'] == 'docker-init', 'PROCESS_HEALTH_FAILED'
    pong = run(['docker', 'exec', 'index-redis-1', 'redis-cli', 'ping']).decode().strip()
    assert pong == 'PONG', 'REDIS_FAILED'
    ids = run(['docker', 'ps', '-aq']).decode().split()
    containers = [{'name': d['Name'], 'state': d['State']['Status'], 'restarts': d['RestartCount']} for d in json.loads(run(['docker', 'inspect'] + ids))]
    save('health.json', {'runtime': rows, 'containers': containers, 'redis': pong})
    print('HEALTH', json.dumps({'runtime': [{k: d[k] for k in ['name', 'restarts', 'init', 'pid1', 'zombies', 'pids_current', 'pids_max']} for d in rows], 'containers': containers, 'redis': pong}), flush=True)


def public():
    import re
    view = (R / 'resources/views/admin.blade.php').read_text()
    assets = sorted(set(re.findall(r'/assets/admin/(?:assets|locales)/[^"\s]+\.(?:js|css)\?v=[^"\s]+', view)))
    version = re.search(r'admin-[a-f0-9]{20}', view).group()
    results = []
    for base in ['https://elephant111.org', 'https://www.elephant111.org']:
        for path in ['/', '/app', '/cfc29397', '/api/v1/guest/comm/config']:
            body = fetch(base + path)
            assert body, 'EMPTY_PUBLIC_RESPONSE'
            if path == '/cfc29397': assert version.encode() in body, 'STALE_ADMIN_HTML'
            results.append({'base': base, 'path': path, 'ok': True})
        for asset in assets:
            body = fetch(base + asset)
            local = R / 'public' / asset.split('?')[0].lstrip('/')
            assert hashlib.sha256(body).hexdigest() == digest(local), 'PUBLIC_ASSET_MISMATCH:' + asset
            results.append({'base': base, 'path': asset, 'ok': True, 'sha256': hashlib.sha256(body).hexdigest()})
    save('public.json', {'version': version, 'results': results})
    print('PUBLIC', json.dumps({'checks': len(results), 'assets_per_domain': len(assets), 'version': version}), flush=True)


if __name__ == '__main__':
    action = sys.argv[1]
    if action == 'prepare': prepare()
    elif action == 'apply':
        assert (B / 'prepared').exists() and not (B / 'started.iso').exists(), 'INVALID_RELEASE_STATE'
        release_evidence(sys.argv[2])
        check_files('before')
        snapshot('immediate-before')
        (B / 'started.iso').write_text(datetime.now(ZoneInfo('Asia/Shanghai')).isoformat())
        try:
            install('after')
            reload()
            check_files('after')
            scope('after')
            public()
        except BaseException:
            install('before')
            reload()
            print('CODE_AUTO_ROLLED_BACK', flush=True)
            raise
        snapshot('after-apply', strict=False)
        print('APPLIED', C, flush=True)
    elif action == 'snapshot': snapshot(sys.argv[2])
    elif action == 'verify':
        check_files('after')
        q = snapshot(sys.argv[2] if len(sys.argv) > 2 else 'fresh-session')
        provisioning = json.loads(run(['docker', 'exec', 'index-horizon-1', 'php', 'scripts/check-horizon-provisioning.php', '--environment=' + q['horizon_environment']]))
        assert provisioning['ok'], 'PROVISIONING_FAILED'
        save('provisioning-after.json', provisioning)
        scope('after')
        public()
        health()
        acceptance('business', 'business-final')
        print('VERIFIED', C, flush=True)
    elif action == 'rollback':
        check_files('after')
        install('before')
        reload()
        print('CODE_ROLLED_BACK', C, flush=True)
    else: raise RuntimeError('UNKNOWN_ACTION')
