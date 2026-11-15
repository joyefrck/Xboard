#!/usr/bin/python3
"""Forced SSH receiver: upload-only production key, read-only recovery key."""
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import sys
import time
from zoneinfo import ZoneInfo

ROOT = Path('/srv/elephant-backups')
DAILY = ROOT / 'daily'
GIB = 1024 ** 3


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def receipts():
    result = []
    for p in sorted(DAILY.glob('????-??-??.json')):
        d = json.loads(p.read_text())
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', p.stem):
            raise ValueError('invalid receipt')
        data = DAILY / (p.stem + '.cms')
        if d['day'] != p.stem or not data.is_file() or data.stat().st_size != d['size']:
            raise ValueError('incomplete backup')
        result.append(d)
    return result


def main():
    os.umask(0o077)
    signal.alarm(4 * 3600)
    if not os.path.ismount(ROOT) or ROOT.stat().st_dev == Path('/').stat().st_dev:
        raise RuntimeError('backup filesystem is not mounted')
    mode = sys.argv[1]
    if mode == 'prune':
        if os.geteuid() != 0:
            raise PermissionError('root maintenance only')
        with (DAILY / '.upload.lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            valid = receipts()
            # Never remove an old recovery point before verifying its replacements.
            for d in valid[-7:]:
                if sha(DAILY / (d['day'] + '.cms')) != d['sha256']:
                    raise ValueError('retained backup checksum mismatch')
            for d in valid[:-7]:
                (DAILY / (d['day'] + '.cms')).unlink()
                (DAILY / (d['day'] + '.json')).unlink()
            print(json.dumps({'retained': len(valid[-7:]), 'removed': len(valid[:-7])}))
        return
    command = os.environ.get('SSH_ORIGINAL_COMMAND', '').split()
    site = Path('/var/lib/elephant-download-site')
    if command == ['download-site-status']:
        print((site / 'receipt.json').read_text())
        return
    if command == ['get-download-site']:
        if not (site / 'receipt.json').is_file():
            raise FileNotFoundError('download-site backup unavailable')
        with (site / 'current.cms').open('rb') as stream:
            start = time.monotonic()
            sent = 0
            for block in iter(lambda: stream.read(1024 * 1024), b''):
                sys.stdout.buffer.write(block)
                sent += len(block)
                time.sleep(max(0, sent / (8 * 1024 ** 2) - (time.monotonic() - start)))
        return
    if command == ['status']:
        fs = shutil.disk_usage(ROOT)
        print(json.dumps({'backups': receipts(), 'used_bytes': fs.used,
                          'free_bytes': fs.free, 'warning_bytes': 80 * GIB}))
        return
    if mode == 'read' and len(command) == 2 and command[0] == 'get':
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}\.cms', command[1]):
            raise ValueError('invalid name')
        day = command[1][:-4]
        if not any(d['day'] == day for d in receipts()):
            raise FileNotFoundError('uncommitted backup')
        fd = os.open(DAILY / command[1], os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as stream:
            shutil.copyfileobj(stream, sys.stdout.buffer, 1024 * 1024)
        return
    if mode != 'upload' or len(command) != 4 or command[0] != 'upload':
        raise PermissionError('command denied')
    _, day, size, digest = command
    today = dt.datetime.now(ZoneInfo('Asia/Shanghai')).date().isoformat()
    if day != today or not re.fullmatch(r'[0-9a-f]{64}', digest):
        raise ValueError('invalid day or digest')
    size = int(size)
    if not 1024 <= size <= 15 * GIB:
        raise ValueError('invalid size')
    with (DAILY / '.upload.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        final = DAILY / (day + '.cms')
        receipt = DAILY / (day + '.json')
        if receipt.exists():
            raise FileExistsError('daily backup already committed')
        if shutil.disk_usage(ROOT).free < size + 2 * GIB or shutil.disk_usage('/').free < 20 * GIB:
            raise OSError('insufficient reserved space')
        temporary = DAILY / ('.upload-' + str(os.getpid()))
        try:
            h = hashlib.sha256()
            remaining = size
            with temporary.open('xb') as stream:
                while remaining:
                    block = sys.stdin.buffer.read(min(1024 * 1024, remaining))
                    if not block:
                        raise ValueError('truncated upload')
                    stream.write(block)
                    h.update(block)
                    remaining -= len(block)
                if sys.stdin.buffer.read(1) or h.hexdigest() != digest:
                    raise ValueError('upload checksum mismatch')
                stream.flush()
                os.fsync(stream.fileno())
            if final.exists():
                raise FileExistsError('orphaned backup requires administrator review')
            temporary.rename(final)
            d = {'day': day, 'size': size, 'sha256': digest, 'committed_at': int(time.time())}
            staged = DAILY / ('.receipt-' + str(os.getpid()))
            with staged.open('x') as stream:
                json.dump(d, stream)
                stream.flush()
                os.fsync(stream.fileno())
            staged.rename(receipt)
            fd = os.open(DAILY, os.O_DIRECTORY)
            os.fsync(fd)
            os.close(fd)
            print(json.dumps(d))
        finally:
            temporary.unlink(missing_ok=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(type(error).__name__ + ': ' + str(error), file=sys.stderr)
        sys.exit(1)
