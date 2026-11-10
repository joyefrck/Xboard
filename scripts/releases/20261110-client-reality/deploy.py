import concurrent.futures
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.request
from zoneinfo import ZoneInfo

B = Path('/root/xboard-client-reality-20261110')
M = json.loads((B / 'manifest.json').read_text())
R = Path(M['production_root'])
C = (B / 'commit').read_text().strip()


def run(args, **kwargs):
    return subprocess.run(args, check=True, capture_output=True, **kwargs).stdout


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def save(name, data):
    (B / name).write_text(json.dumps(data, indent=2))


def fetch(url, headers=None):
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers or {}), timeout=60) as response:
        return response.read()


def runtime():
    rows = json.loads(run(['docker', 'inspect', 'index-web-1', 'index-horizon-1']))
    return [{'name': d['Name'], 'image': d['Image'], 'image_ref': d['Config']['Image'], 'running': d['State']['Running'], 'restarts': d['RestartCount'], 'init': d['HostConfig'].get('Init'),
             'mounts': [{k: item[k] for k in ['Type', 'Source', 'Destination']} for item in d['Mounts']], 'networks': sorted(d['NetworkSettings']['Networks'])} for d in rows]


def verify_files(section):
    for file, hashes in M['files'].items():
        assert digest(R / file) == hashes[section], 'FILE_DRIFT ' + file
    original = json.loads((B / 'runtime-before.json').read_text())
    assert runtime() == original, 'RUNTIME_DRIFT'
    for file in ['config/horizon.php', 'config/queue.php']:
        assert digest(R / file) == digest(B / 'before' / file), 'CONFIG_DRIFT ' + file


def snapshot(name):
    args = ['docker', 'exec', '-i', 'index-horizon-1', 'php', '--', '--root=/www']
    if (B / 'started.iso').exists():
        args.append('--since=' + (B / 'started.iso').read_text().strip())
    data = json.loads(run(args, input=(B / 'queue-snapshot.php').read_bytes()))
    save(name + '.json', data)
    assert data['coverage_ok'], 'QUEUE_COVERAGE_FAILED'
    print('QUEUE', json.dumps({'sample': name, 'at': data['sample_at'], 'coverage': data['coverage_ok'], 'failed': data['new_failed_jobs'],
        'environments': [data['app_environment'], data['horizon_environment'], data['selected_environment_pattern']],
        'nonempty': {k: [v['ready'], v['reserved'], v['delayed'], v['ready_head_age_seconds']] for k, v in data['queues'].items() if v['ready'] or v['reserved'] or v['delayed']}}), flush=True)
    return data


def acceptance(mode, name, strict=False):
    args = ['docker', 'exec', '-i', 'index-horizon-1', 'php', '--', '--mode=' + mode]
    if strict:
        args.append('--strict')
    if (B / 'started.iso').exists():
        args.append('--since=' + (B / 'started.iso').read_text().strip())
    result = subprocess.run(args, input=(B / 'verify.php').read_bytes(), capture_output=True)
    data = json.loads(result.stdout)
    save(name + '.json', data)
    print(mode.upper(), json.dumps({k: v for k, v in data.items() if k != 'completed_job_evidence'}), flush=True)
    assert result.returncode == 0, 'ACCEPTANCE_FAILED ' + mode
    return data


def provisioning(name, environment):
    output = run(['docker', 'exec', 'index-horizon-1', 'php', 'scripts/check-horizon-provisioning.php', '--environment=' + environment])
    data = json.loads(output)
    assert data['ok'], 'PROVISIONING_FAILED'
    save(name + '.json', data)


def release_evidence(run_id):
    ci = json.loads(fetch('https://api.github.com/repos/joyefrck/Xboard/actions/runs/' + run_id, {'User-Agent': 'Xboard-release-check'}))
    assert ci['head_sha'] == C and ci['conclusion'] == 'success' and ci['path'] == '.github/workflows/docker-publish.yml', 'CI_NOT_VERIFIED'
    save('ci-result.json', {'id': ci['id'], 'head_sha': ci['head_sha'], 'conclusion': ci['conclusion'], 'html_url': ci['html_url']})
    token = json.loads(fetch('https://ghcr.io/token?service=ghcr.io&scope=repository:joyefrck/xboard:pull'))['token']
    headers = {'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json'}
    def registry(path):
        return fetch('https://ghcr.io/v2/joyefrck/xboard/' + path, headers)
    raw = registry('manifests/sha-' + C)
    index = json.loads(raw)
    result = {'commit': C, 'index_digest': 'sha256:' + hashlib.sha256(raw).hexdigest(), 'platforms': []}
    for arch in ['amd64', 'arm64']:
        item = next(x for x in index['manifests'] if x.get('platform', {}).get('os') == 'linux' and x['platform']['architecture'] == arch)
        image = json.loads(registry('manifests/' + item['digest']))
        config = json.loads(registry('blobs/' + image['config']['digest']))
        revision = config['config']['Labels']['org.opencontainers.image.revision']
        assert revision == C, 'WRONG_IMAGE_REVISION'
        result['platforms'].append({'architecture': arch, 'digest': item['digest'], 'revision': revision})
    save('image-verification.json', result)
    print('CI_IMAGE_VERIFIED', C, result['index_digest'], flush=True)


def prepare():
    assert not (B / 'prepared').exists(), 'ALREADY_PREPARED'
    rows = runtime()
    for row in rows:
        assert row['running'] and row['init'], 'CONTAINER_UNHEALTHY'
        assert {'Type': 'bind', 'Source': str(R), 'Destination': '/www'} in row['mounts'], 'WRONG_MOUNT'
    save('runtime-before.json', rows)
    for file, hashes in M['files'].items():
        assert digest(R / file) == hashes['before'], 'BASELINE_DRIFT ' + file
        target = B / 'before' / file
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(R / file, target)
    for file in ['config/horizon.php', 'config/queue.php', 'docker-compose.yaml']:
        target = B / 'before' / file
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(R / file, target)
    def stage(item):
        file, hashes = item
        body = fetch('https://raw.githubusercontent.com/joyefrck/Xboard/' + C + '/' + file)
        assert hashlib.sha256(body).hexdigest() == hashes['after'], 'SOURCE_HASH_FAILED'
        path = B / 'stage' / file
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(body)
        if file.endswith('.php'):
            run(['docker', 'exec', '-i', 'index-web-1', 'php', '-l'], input=body)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        list(pool.map(stage, M['files'].items()))
    queue = fetch('https://raw.githubusercontent.com/joyefrck/Xboard/' + C + '/scripts/releases/20261109-refund/queue-snapshot.php')
    assert hashlib.sha256(queue).hexdigest() == M['queue_snapshot_sha256'], 'QUEUE_CHECK_SCRIPT_DRIFT'
    (B / 'queue-snapshot.php').write_bytes(queue)
    q = snapshot('before')
    provisioning('provisioning-before', q['horizon_environment'])
    save('git-before.json', {'head': run(['git', '-c', 'safe.directory=' + str(R), '-C', str(R), 'rev-parse', 'HEAD']).decode().strip(),
        'tracked_modified_count': len(run(['git', '-c', 'safe.directory=' + str(R), '-C', str(R), 'status', '--porcelain', '--untracked-files=no']).splitlines())})
    acceptance('business', 'business-before')
    acceptance('subscription', 'subscription-before')
    (B / 'prepared').touch()
    print('PREPARED', C, 'BACKUP', str(B), 'FILES', len(M['files']), flush=True)


def install(section):
    for file, hashes in M['files'].items():
        source = B / ('stage' if section == 'after' else 'before') / file
        assert digest(source) == hashes[section], 'BACKUP_OR_STAGE_DRIFT'
        target = R / file
        previous = target.stat()
        temporary = target.with_name(target.name + '.client-reality-stage')
        shutil.copyfile(source, temporary)
        os.chown(temporary, previous.st_uid, previous.st_gid)
        os.chmod(temporary, previous.st_mode & 0o777)
        os.replace(temporary, target)


def health():
    php = '$z=0;foreach(glob("/proc/[0-9]*/stat") as $p){if(preg_match("/^\\d+ \\(.*\\) Z /",file_get_contents($p)))$z++;}echo json_encode(["pid1"=>basename(explode(chr(0),file_get_contents("/proc/1/cmdline"))[0]),"zombies"=>$z,"pids_current"=>trim(file_get_contents("/sys/fs/cgroup/pids.current")),"pids_max"=>trim(file_get_contents("/sys/fs/cgroup/pids.max"))]);'
    rows = runtime()
    for row in rows:
        row.update(json.loads(run(['docker', 'exec', row['name'].lstrip('/'), 'php', '-r', php])))
        assert row['zombies'] == 0 and row['pid1'] == 'docker-init', 'PROCESS_HEALTH_FAILED'
    ids = run(['docker', 'ps', '-aq']).decode().split()
    containers = [{'name': d['Name'], 'state': d['State']['Status'], 'restarts': d['RestartCount']} for d in json.loads(run(['docker', 'inspect'] + ids))]
    pong = run(['docker', 'exec', 'index-redis-1', 'redis-cli', 'ping']).decode().strip()
    assert pong == 'PONG', 'REDIS_FAILED'
    save('health.json', {'runtime': rows, 'containers': containers, 'redis': pong})
    print('HEALTH', json.dumps({'runtime': [{k: row[k] for k in ['name', 'restarts', 'init', 'pid1', 'zombies', 'pids_current', 'pids_max']} for row in rows], 'redis': pong, 'containers': containers}), flush=True)


action = sys.argv[1]
if action == 'prepare':
    prepare()
elif action == 'apply':
    assert (B / 'prepared').exists() and not (B / 'started.iso').exists(), 'INVALID_RELEASE_STATE'
    release_evidence(sys.argv[2])
    verify_files('before')
    snapshot('immediate-before')
    (B / 'started.iso').write_text(datetime.now(ZoneInfo('Asia/Shanghai')).isoformat())
    try:
        install('after')
        run(['docker', 'exec', 'index-web-1', 'php', 'artisan', 'octane:reload'], timeout=120)
        verify_files('after')
        acceptance('subscription', 'subscription-after', True)
    except BaseException:
        install('before')
        run(['docker', 'exec', 'index-web-1', 'php', 'artisan', 'octane:reload'], timeout=120)
        print('AUTO_ROLLED_BACK', flush=True)
        raise
    snapshot('after-apply')
    print('APPLIED', C, flush=True)
elif action == 'snapshot':
    snapshot(sys.argv[2])
elif action == 'verify':
    verify_files('after')
    q = snapshot(sys.argv[2] if len(sys.argv) > 2 else 'fresh-session')
    provisioning('provisioning-after', q['horizon_environment'])
    health()
    acceptance('business', 'business-final')
    print('VERIFIED', C, flush=True)
elif action == 'rollback':
    verify_files('after')
    install('before')
    run(['docker', 'exec', 'index-web-1', 'php', 'artisan', 'octane:reload'], timeout=120)
    verify_files('before')
    print('ROLLED_BACK', C, flush=True)
else:
    raise RuntimeError('UNKNOWN_ACTION')
