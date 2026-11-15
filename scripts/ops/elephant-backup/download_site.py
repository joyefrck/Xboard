#!/usr/bin/python3
"""Prepare one encrypted download-site snapshot for the primary host to pull."""
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import sqlite3
import subprocess as sp
from zoneinfo import ZoneInfo

os.umask(0o077)
base = Path('/var/lib/elephant-download-site')
base.mkdir(mode=0o750, exist_ok=True)
group = pwd.getpwnam('elephant-backup').pw_gid
os.chown(base, 0, group)
base.chmod(0o750)
lock = (base / '.job.lock').open('a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
day = dt.datetime.now(ZoneInfo('Asia/Shanghai')).date().isoformat()
current = base / 'current.cms'
receipt = base / 'receipt.json'
if receipt.exists() and json.loads(receipt.read_text())['day'] == day and current.exists():
    raise SystemExit(0)
work = base / 'work'
if work.exists():
    shutil.rmtree(work)
work.mkdir(mode=0o700)
source = Path('/opt/1panel/docker/compose/gokapi')
copied = work / 'gokapi'
shutil.copytree(source, copied, symlinks=True, ignore=shutil.ignore_patterns('backups'))
for file in source.rglob('*'):
    relative = file.relative_to(source)
    if 'backups' in relative.parts or not file.is_file() or file.is_symlink():
        continue
    with file.open('rb') as stream:
        is_sqlite = stream.read(16) == b'SQLite format 3\x00'
    if is_sqlite:
        target = copied / relative
        for suffix in ['', '-wal', '-shm']:
            Path(str(target) + suffix).unlink(missing_ok=True)
        a = sqlite3.connect(file.as_uri() + '?mode=ro', uri=True)
        b = sqlite3.connect(target)
        a.backup(b)
        assert b.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
        b.close()
        a.close()
runtime = json.loads(sp.check_output(['docker', 'inspect', '1Panel-gokapi']))
(work / 'runtime.json').write_text(json.dumps(runtime, indent=2))
sp.run(['docker', 'image', 'save', '-o', str(work / 'runtime-image.tar'), runtime[0]['Image']], check=True)
checksums = {}
for file in work.rglob('*'):
    if file.is_file() and not file.is_symlink():
        with file.open('rb') as stream:
            checksums[str(file.relative_to(work))] = hashlib.file_digest(stream, 'sha256').hexdigest()
(work / 'manifest.json').write_text(json.dumps({'day': day, 'sha256': checksums}, indent=2))
paths = [str(work).lstrip('/')] + [p.lstrip('/') for p in ['/etc/caddy', '/etc/nftables.conf', '/var/lib/caddy'] if Path(p).exists()]
temporary = base / 'current.partial'
with (base / 'archive.log').open('wb') as log:
    tar = sp.Popen(['tar', '-czf', '-', '-C', '/'] + paths, stdout=sp.PIPE, stderr=log)
    crypt = sp.run(['openssl', 'cms', '-encrypt', '-stream', '-aes-256-gcm', '-binary', '-outform', 'DER',
                    '-out', str(temporary), '/etc/elephant-backup/recovery-cert.pem'], stdin=tar.stdout, stderr=log)
    tar.stdout.close()
    code = tar.wait()
assert crypt.returncode == code == 0, 'download-site archive failed'
temporary.rename(current)
current.chmod(0o640)
os.chown(current, 0, group)
with current.open('rb') as stream:
    digest = hashlib.file_digest(stream, 'sha256').hexdigest()
d = {'day': day, 'size': current.stat().st_size, 'sha256': digest}
temporary_receipt = base / 'receipt.partial'
temporary_receipt.write_text(json.dumps(d))
temporary_receipt.chmod(0o640)
os.chown(temporary_receipt, 0, group)
temporary_receipt.rename(receipt)
print(json.dumps(d))
