import argparse
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import time
import urllib.request
from zoneinfo import ZoneInfo


def command(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def snapshot(root, backup, name, since=None):
    args = ['docker', 'exec', '-i', 'index-horizon-1', 'php', '--', '--root=/www']
    if since:
        args.append('--since=' + since)
    result = subprocess.run(args, input=(backup / 'queue-snapshot.php').read_bytes(), capture_output=True)
    target = backup / (name + '.json')
    target.write_bytes(result.stdout)
    data = json.loads(result.stdout)
    if result.returncode or not data.get('coverage_ok'):
        raise RuntimeError('Queue coverage failed; inspect ' + str(target))
    print(json.dumps({
        'sample': name, 'at': data['sample_at'], 'coverage_ok': data['coverage_ok'],
        'new_failed_jobs': data['new_failed_jobs'], 'zombies': data['container_zombies'],
        'queues': {k: [v['workers'], v['ready'], v['reserved'], v['delayed'], v['ready_head_age_seconds']] for k, v in data['queues'].items()},
    }), flush=True)
    return data


def main(action, manifest_path):
    manifest = json.loads(Path(manifest_path).read_text())
    root, backup = Path(manifest['production_root']), Path(manifest['backup'])
    if not root.is_dir() or os.geteuid() != 0:
        raise RuntimeError('Run as root on the owning production host')
    backup.mkdir(mode=0o700, exist_ok=True)
    os.chmod(backup, 0o700)
    image = manifest['image']
    compose = ['docker', 'compose', '-f', str(root / 'docker-compose.yaml'), '-f', str(backup / 'image.override.yaml')]
    if action == 'stage':
        for file, hashes in manifest['files'].items():
            source = root / file
            if digest(source) != hashes['before']:
                raise RuntimeError('Production file drift: ' + file)
            request = urllib.request.Request('https://raw.githubusercontent.com/joyefrck/Xboard/' + manifest['commit'] + '/' + file)
            body = urllib.request.urlopen(request, timeout=30).read()
            if hashlib.sha256(body).hexdigest() != hashes['after']:
                raise RuntimeError('Downloaded file mismatch: ' + file)
            before, staged = backup / 'before' / file, backup / 'stage' / file
            before.parent.mkdir(parents=True, exist_ok=True)
            staged.parent.mkdir(parents=True, exist_ok=True)
            if before.exists() and digest(before) != hashes['before']:
                raise RuntimeError('Existing backup differs: ' + file)
            if not before.exists():
                shutil.copy2(source, before)
            staged.write_bytes(body)
            os.chmod(staged, 0o644)
        (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2))
        shutil.copy2('/root/xboard-vless-20261101/queue-snapshot.php', backup / 'queue-snapshot.php')
        (backup / 'image.override.yaml').write_text('services:\n  web:\n    image: ' + image + '\n  horizon:\n    image: ' + image + '\n')
        for container in ['index-web-1', 'index-horizon-1']:
            info = json.loads(command(['docker', 'inspect', container], capture_output=True, text=True).stdout)[0]
            if not info['HostConfig'].get('Init'):
                raise RuntimeError('Production init must be preserved: ' + container)
            saved = {key: info[key] for key in ['Image', 'Mounts']}
            saved['revision'] = info['Config'].get('Labels', {}).get('org.opencontainers.image.revision')
            (backup / (container + '.before.json')).write_text(json.dumps(saved, indent=2))
        configured = json.loads(command(compose + ['config', '--format', 'json'], capture_output=True, text=True).stdout)
        for service in ['web', 'horizon']:
            item = configured['services'][service]
            if item['image'] != image or not item.get('init') or '1panel-network' not in item.get('networks', {}):
                raise RuntimeError('Rendered Compose would change required runtime properties: ' + service)
        snapshot(root, backup, 'pre-deployment')
        command(['docker', 'pull', image])
        label = command(['docker', 'image', 'inspect', image, '--format', '{{index .Config.Labels "org.opencontainers.image.revision"}}'], capture_output=True, text=True).stdout.strip()
        if label != manifest['commit']:
            raise RuntimeError('Image revision differs from verified CI commit')
        command(['docker', 'run', '--rm', '--network', 'none', image, 'php', 'scripts/check-horizon-provisioning.php', '--environment=local'])
        for file in manifest['files']:
            command(['docker', 'run', '--rm', '--network', 'none', '-v', str(backup / 'stage') + ':/stage:ro', image, 'php', '-l', '/stage/' + file])
        print('STAGE_VERIFIED', manifest['commit'], flush=True)
    elif action == 'apply':
        for file, hashes in manifest['files'].items():
            if digest(root / file) != hashes['before'] or digest(backup / 'stage' / file) != hashes['after'] or digest(backup / 'before' / file) != hashes['before']:
                raise RuntimeError('Pre-apply hash mismatch: ' + file)
        data = snapshot(root, backup, 'immediate-before')
        since = datetime.now(ZoneInfo('Asia/Shanghai')).isoformat()
        (backup / 'started.iso').write_text(since)
        for file in manifest['files']:
            source = root / file
            temporary = source.with_name(source.name + '.thirdparty-stage')
            shutil.copy2(backup / 'stage' / file, temporary)
            original = source.stat()
            os.chown(temporary, original.st_uid, original.st_gid)
            os.chmod(temporary, original.st_mode & 0o777)
            os.replace(temporary, source)
        command(compose + ['up', '-d', '--no-deps', '--timeout', '600', 'web', 'horizon'], cwd=root)
        for attempt in range(30):
            try:
                snapshot(root, backup, 'post-1', since)
                break
            except RuntimeError:
                if attempt == 29:
                    raise
                time.sleep(2)
        for number in [2, 3]:
            time.sleep(40)
            snapshot(root, backup, 'post-' + str(number), since)
        verify(manifest, root, backup)
        print('DEPLOYED_QUEUE_TRENDS_VERIFIED', manifest['commit'], flush=True)
    elif action == 'verify':
        verify(manifest, root, backup)
        snapshot(root, backup, 'fresh-session', (backup / 'started.iso').read_text().strip())


def verify(manifest, root, backup):
    for file, hashes in manifest['files'].items():
        if digest(root / file) != hashes['after']:
            raise RuntimeError('Deployed file mismatch: ' + file)
    for container in ['index-web-1', 'index-horizon-1']:
        info = json.loads(command(['docker', 'inspect', container], capture_output=True, text=True).stdout)[0]
        old = json.loads((backup / (container + '.before.json')).read_text())
        if not info['State']['Running'] or not info['HostConfig'].get('Init'):
            raise RuntimeError('Runtime health or init failed: ' + container)
        if info['Config']['Image'] != manifest['image'] or info['Config'].get('Labels', {}).get('org.opencontainers.image.revision') != manifest['commit']:
            raise RuntimeError('Running image differs: ' + container)
        mounts = lambda records: sorted((record['Type'], record['Source'], record['Destination']) for record in records)
        if mounts(info['Mounts']) != mounts(old['Mounts']):
            raise RuntimeError('Mounts changed: ' + container)
        print(container, manifest['commit'], 'restarts', info['RestartCount'], flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=['stage', 'apply', 'verify'])
    parser.add_argument('manifest')
    arguments = parser.parse_args()
    main(arguments.action, arguments.manifest)
