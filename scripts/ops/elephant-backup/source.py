#!/usr/bin/python3
"""Daily consistent database export and independently encrypted full archive."""
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess as sp
import sys
import time
from zoneinfo import ZoneInfo

BASE = Path('/var/lib/elephant-backup')
CONF = Path('/etc/elephant-backup')
APP = Path('/opt/1panel/apps/openresty/openresty/www/sites/xboard/index')
SSH = ['ssh', '-i', str(CONF / 'ssh_ed25519'), '-o', 'BatchMode=yes',
       '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
       '-o', 'UserKnownHostsFile=' + str(CONF / 'known_hosts'),
       '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=30',
       '-o', 'ServerAliveCountMax=3', 'elephant-backup@67.216.201.209']


def run(args, **kwargs):
    return sp.run(args, check=True, capture_output=True, **kwargs).stdout


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def pull_download_site():
    receipt = json.loads(run(SSH + ['download-site-status']))
    assert re.fullmatch(r'\d{4}-\d{2}-\d{2}', receipt['day'])
    assert 1024 <= receipt['size'] < 8 * 1024 ** 3
    folder = BASE / 'download-site'
    folder.mkdir(mode=0o700, exist_ok=True)
    final = folder / (receipt['day'] + '.cms')
    if not final.exists() or sha(final) != receipt['sha256']:
        temporary = folder / 'current.partial'
        with temporary.open('wb') as output:
            sp.run(SSH + ['get-download-site'], stdout=output, stderr=sp.PIPE, check=True)
        assert temporary.stat().st_size == receipt['size'] and sha(temporary) == receipt['sha256']
        temporary.replace(final)
    (BASE / 'download-site-receipt.json').write_text(json.dumps(receipt))
    for file in sorted(folder.glob('????-??-??.cms'))[:-7]:
        file.unlink()
    print(json.dumps({'download_site_saved': receipt}), flush=True)


def db_command(container, credentials, executable, arguments, output=None):
    # Passwords travel through stdin into a short-lived tmpfs option file.
    command = ('set -eu; umask 077; c=$(mktemp /dev/shm/elephant-db.XXXXXX); '
               'trap \'rm -f "$c"\' EXIT; cat > "$c"; '
               + executable + ' --defaults-extra-file="$c" ' + arguments)
    with (output.open('wb') if output else open(os.devnull, 'wb')) as target:
        result = sp.run(['docker', 'exec', '-i', container, 'sh', '-c', command],
                        input=credentials, stdout=target if output else sp.PIPE,
                        stderr=sp.PIPE, check=True)
    return result.stdout


def main():
    os.umask(0o077)
    BASE.mkdir(mode=0o700, exist_ok=True)
    lock = (BASE / 'job.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    day = dt.datetime.now(ZoneInfo('Asia/Shanghai')).date().isoformat()
    try:
        pull_download_site()
    except Exception as error:
        print('DOWNLOAD_SITE_WARNING ' + type(error).__name__, file=sys.stderr, flush=True)
        try:
            sp.run(['/usr/local/sbin/elephant-backup-monitor', 'failure',
                    'elephant-download-site-backup.service'], capture_output=True, timeout=30)
        except (OSError, sp.TimeoutExpired):
            print('DOWNLOAD_SITE_ALERT_WARNING', file=sys.stderr, flush=True)
    remote = json.loads(run(SSH + ['status']))
    if any(d['day'] == day for d in remote['backups']):
        print(json.dumps({'already_backed_up': day}))
        return
    if shutil.disk_usage(BASE).free < 8 * 1024 ** 3:
        raise OSError('source requires at least 8 GiB free')
    encrypted = BASE / (day + '.cms')
    work = BASE / 'work'
    # A failed upload can be retried without regenerating its encrypted payload.
    if not encrypted.exists():
        if work.exists():
            shutil.rmtree(work)
        work.mkdir(mode=0o700)
        rows = json.loads(run(['docker', 'inspect'] + run(['docker', 'ps', '-q']).decode().split()))
        (work / 'docker-inspect.json').write_text(json.dumps(rows, indent=2))
        database = [d for d in rows if d['Config']['Image'].startswith('mariadb:')]
        assert len(database) == 1, 'unexpected database topology'
        database = database[0]
        env = dict(s.split('=', 1) for s in database['Config'].get('Env', []) if '=' in s)
        password = env.get('MARIADB_ROOT_PASSWORD') or env.get('MYSQL_ROOT_PASSWORD')
        assert password, 'database credentials unavailable'
        escaped = password.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\r', '\\r')
        credentials = ('[client]\nuser=root\npassword="' + escaped + '"\n').encode()
        dbname = database['Name'].lstrip('/')
        names = db_command(dbname, credentials, 'mariadb', '-NBe "SHOW DATABASES"').decode().split()
        names = [n for n in names if n not in ['mysql', 'sys', 'performance_schema', 'information_schema']]
        assert names and all(re.fullmatch(r'[A-Za-z0-9_]+', n) for n in names)
        engines = db_command(dbname, credentials, 'mariadb',
            '-NBe "SELECT TABLE_SCHEMA,TABLE_NAME,ENGINE FROM information_schema.tables '
            'WHERE TABLE_TYPE=\'BASE TABLE\'"').decode().splitlines()
        assert all(line.split('\t')[-1] == 'InnoDB' for line in engines if line.split('\t')[0] in names), 'nontransactional business tables'
        started = dt.datetime.now(dt.timezone.utc).isoformat()
        db_command(dbname, credentials, 'mariadb-dump',
            '--single-transaction --quick --hex-blob --routines --events --triggers '
            '--skip-lock-tables --databases ' + ' '.join(names), work / 'databases.sql')
        db_command(dbname, credentials, 'mariadb-dump', '--system=users', work / 'database-users.sql')
        assert (work / 'databases.sql').stat().st_size > 1024
        redisfile = '/tmp/elephant-backup-' + str(os.getpid()) + '.rdb'
        try:
            run(['docker', 'exec', 'index-redis-1', 'redis-cli', '--rdb', redisfile])
            run(['docker', 'cp', 'index-redis-1:' + redisfile, str(work / 'redis.rdb')])
        finally:
            run(['docker', 'exec', 'index-redis-1', 'rm', '-f', redisfile])
        assert (work / 'redis.rdb').read_bytes()[:5] == b'REDIS'
        sqlite = work / 'panel-sqlite'
        sqlite.mkdir()
        for file in Path('/opt/1panel/db').glob('*'):
            if file.is_file() and file.open('rb').read(16) == b'SQLite format 3\x00':
                source = sqlite3.connect(file.as_uri() + '?mode=ro', uri=True)
                target = sqlite3.connect(sqlite / file.name)
                source.backup(target)
                assert target.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
                target.close()
                source.close()
        # Save exact runtime images, including locally built images without a registry tag.
        images = sorted({d['Image'] for d in rows})
        run(['docker', 'image', 'save', '--output', str(work / 'runtime-images.tar')] + images)
        files = {str(p.relative_to(work)): sha(p) for p in work.rglob('*') if p.is_file()}
        for folder in [APP / 'storage/app', APP / 'public/storage']:
            if folder.exists() and not folder.is_symlink():
                for p in folder.rglob('*'):
                    if p.is_file() and not p.is_symlink():
                        files[str(p).lstrip('/')] = sha(p)
        files[str(APP / '.env').lstrip('/')] = sha(APP / '.env')
        site_files = sorted((BASE / 'download-site').glob('????-??-??.cms'))
        if site_files:
            files[str(site_files[-1]).lstrip('/')] = sha(site_files[-1])
        manifest = {'day': day, 'database_snapshot_started_at': started,
                    'databases': names, 'runtime_images': images, 'sha256': files,
                    'frequency': 'daily', 'retention': '7 successful days',
                    'pitr': False, 'scope': 'Xboard host and locally discovered databases'}
        (work / 'manifest.json').write_text(json.dumps(manifest, indent=2))
        paths = ['/etc', '/opt/1panel/apps/openresty/openresty',
                 '/opt/1panel/apps/mariadb/mariadb', '/opt/1panel/www',
                 '/opt/1panel/conf', '/usr/local/sbin', '/usr/local/bin',
                 '/var/spool/cron', '/root/.ssh']
        exclude = [str(APP / '.docker/.data'), str(APP / 'storage/logs'),
                   str(APP / 'storage/framework'), str(APP / 'bootstrap/cache'),
                   '/opt/1panel/apps/openresty/openresty/log',
                   '/opt/1panel/apps/mariadb/mariadb/db/data',
                   '/opt/1panel/apps/mariadb/mariadb/data',
                   '/opt/1panel/www/sites/*/log',
                   '/opt/1panel/apps/openresty/openresty/www/sites/*/log',
                   '/opt/1panel/apps/openresty/openresty/1pwaf/data']
        command = ['tar', '-I', 'gzip -1', '-cf', '-']
        for item in exclude:
            command += ['--exclude=' + item.lstrip('/')]
        command += ['-C', '/', str(work).lstrip('/')]
        command += [p.lstrip('/') for p in paths if Path(p).exists()]
        if site_files:
            command += [str(site_files[-1]).lstrip('/')]
        temporary = encrypted.with_suffix('.partial')
        with (BASE / 'archive.log').open('wb') as log:
            tar = sp.Popen(command, stdout=sp.PIPE, stderr=log)
            crypt = sp.run(['openssl', 'cms', '-encrypt', '-stream', '-aes-256-gcm',
                            '-binary', '-outform', 'DER', '-out', str(temporary),
                            str(CONF / 'recovery-cert.pem')], stdin=tar.stdout,
                           stdout=sp.DEVNULL, stderr=log)
            tar.stdout.close()
            tarcode = tar.wait()
        if crypt.returncode or tarcode:
            temporary.unlink(missing_ok=True)
            raise RuntimeError('archive/encryption failed; see protected archive.log')
        temporary.rename(encrypted)
    digest = sha(encrypted)
    size = encrypted.stat().st_size
    process = sp.Popen(SSH + ['upload ' + day + ' ' + str(size) + ' ' + digest],
                       stdin=sp.PIPE, stdout=sp.PIPE, stderr=sp.PIPE)
    start = time.monotonic()
    sent = 0
    with encrypted.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            process.stdin.write(block)
            sent += len(block)
            time.sleep(max(0, sent / (8 * 1024 ** 2) - (time.monotonic() - start)))
    process.stdin.close()
    result = process.stdout.read()
    errors = process.stderr.read()
    if process.wait() != 0:
        raise RuntimeError('SSH upload failed: ' + errors.decode()[:1000])
    receipt = json.loads(result)
    assert receipt['sha256'] == digest and receipt['size'] == size and receipt['day'] == day
    (BASE / 'last-success.json').write_text(json.dumps(receipt, indent=2))
    # Keep one local encrypted fallback, never remove the current successful backup.
    for p in BASE.glob('????-??-??.cms'):
        if p != encrypted:
            p.unlink()
    if work.exists():
        shutil.rmtree(work)
    print(json.dumps({'success': receipt, 'upload_seconds': round(time.monotonic() - start)}))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(type(error).__name__ + ': ' + str(error), file=sys.stderr)
        sys.exit(1)
