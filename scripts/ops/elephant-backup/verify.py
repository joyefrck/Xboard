#!/usr/bin/env python3
"""Fetch a real remote backup, decrypt locally, verify every manifest checksum."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess as sp
import tarfile
import time

parser = argparse.ArgumentParser()
parser.add_argument('kind', choices=['primary', 'download-site'])
parser.add_argument('--directory', type=Path, required=True)
args = parser.parse_args()
base = args.directory.resolve()
os.umask(0o077)
base.mkdir(mode=0o700, parents=True, exist_ok=True)
ssh = ['ssh', '-i', str(base / 'maintenance_ed25519'), '-o', 'IdentitiesOnly=yes',
       '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
       '-o', 'UserKnownHostsFile=' + str(base / 'known_hosts'),
       '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=30',
       '-o', 'ServerAliveCountMax=3', 'elephant-backup@67.216.201.209']
command = 'status' if args.kind == 'primary' else 'download-site-status'
metadata = json.loads(sp.check_output(ssh + [command]))
receipt = metadata['backups'][-1] if args.kind == 'primary' else metadata
archive = base / (args.kind + '-' + receipt['day'] + '.cms')


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


if not archive.exists() or sha(archive) != receipt['sha256']:
    command = 'get ' + receipt['day'] + '.cms' if args.kind == 'primary' else 'get-download-site'
    temporary = archive.with_suffix('.partial')
    process = sp.Popen(ssh + [command], stdout=sp.PIPE, stderr=sp.PIPE)
    start, count = time.monotonic(), 0
    with temporary.open('wb') as output:
        for block in iter(lambda: process.stdout.read(1024 * 1024), b''):
            output.write(block)
            count += len(block)
            time.sleep(max(0, count / (8 * 1024 ** 2) - (time.monotonic() - start)))
    errors = process.stderr.read()
    assert process.wait() == 0, errors.decode()[:1000]
    assert temporary.stat().st_size == receipt['size'] and sha(temporary) == receipt['sha256']
    temporary.rename(archive)
print(json.dumps({'download_verified': args.kind, 'size': receipt['size'], 'day': receipt['day']}), flush=True)
plain = archive.with_suffix('.tar.gz')
sp.run(['java', '-Xmx256m', '--class-path', str(base / 'lib' / '*'),
        str(Path(__file__).with_name('CmsDecrypt.java')), str(archive),
        str(base / 'recovery-private.pem'), str(plain)], check=True)
prefix = 'var/lib/elephant-backup/work/' if args.kind == 'primary' else 'var/lib/elephant-download-site/work/'
with tarfile.open(plain, 'r:gz') as tar:
    manifest = json.load(tar.extractfile(prefix + 'manifest.json'))
expected = {}
for name, digest in manifest['sha256'].items():
    member = name if name.startswith(('opt/', 'var/')) else prefix + name
    expected[member] = digest
verified = {}
validation = base / ('validation-' + args.kind)
validation.mkdir(mode=0o700, exist_ok=True)
with tarfile.open(plain, 'r|gz') as tar:
    for member in tar:
        if member.name not in expected:
            continue
        assert member.isfile(), 'manifest member must be a regular file'
        stream = tar.extractfile(member)
        h = hashlib.sha256()
        keep = member.name in [prefix + 'databases.sql', prefix + 'gokapi/gokapi-data/gokapi.sqlite']
        output = (validation / Path(member.name).name).open('wb') if keep else None
        try:
            for block in iter(lambda: stream.read(1024 * 1024), b''):
                h.update(block)
                if output:
                    output.write(block)
        finally:
            if output:
                output.close()
        assert h.hexdigest() == expected[member.name], 'file checksum mismatch'
        verified[member.name] = h.hexdigest()
assert len(verified) == len(expected), 'missing manifest files'
sqlite = validation / 'gokapi.sqlite'
if sqlite.exists():
    connection = sqlite3.connect(sqlite.as_uri() + '?mode=ro&immutable=1', uri=True)
    assert connection.execute('PRAGMA integrity_check').fetchone()[0] == 'ok'
    connection.close()
result = {'kind': args.kind, 'day': receipt['day'], 'size': receipt['size'],
          'ciphertext_sha256': receipt['sha256'], 'verified_files': len(verified),
          'verified_sha256': verified, 'sqlite_integrity': 'ok' if sqlite.exists() else None}
(base / (args.kind + '-verification.json')).write_text(json.dumps(result, indent=2))
plain.unlink()
print(json.dumps({k: v for k, v in result.items() if k != 'verified_sha256'}), flush=True)
