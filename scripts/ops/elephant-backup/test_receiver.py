import contextlib
import datetime as dt
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo

spec = importlib.util.spec_from_file_location('receiver', Path(__file__).with_name('receiver.py'))
receiver = importlib.util.module_from_spec(spec)
spec.loader.exec_module(receiver)


class FakeMount(type(Path())):
    def stat(self, *args, **kwargs):
        fields = list(super().stat(*args, **kwargs))
        fields[2] += 1
        return os.stat_result(fields)


class ReceiverTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = FakeMount(self.temp.name)
        self.daily = self.root / 'daily'
        self.daily.mkdir()
        self.stack = contextlib.ExitStack()
        self.addCleanup(self.stack.close)
        for target, value in [('ROOT', self.root), ('DAILY', self.daily)]:
            self.stack.enter_context(patch.object(receiver, target, value))
        self.stack.enter_context(patch.object(receiver.os.path, 'ismount', return_value=True))
        self.stack.enter_context(patch.object(receiver.signal, 'alarm'))
        self.stack.enter_context(patch.object(receiver.os, 'geteuid', return_value=0))
        self.stack.enter_context(patch('sys.stdout', io.StringIO()))

    def backup(self, day):
        payload = (day * 110).encode()
        (self.daily / (day + '.cms')).write_bytes(payload)
        d = {'day': day, 'size': len(payload), 'sha256': hashlib.sha256(payload).hexdigest()}
        (self.daily / (day + '.json')).write_text(json.dumps(d))

    def invoke(self, mode, command='', data=b''):
        with patch('sys.argv', ['receiver', mode]), patch.dict(os.environ, {'SSH_ORIGINAL_COMMAND': command}), \
                patch('sys.stdin', type('Input', (), {'buffer': io.BytesIO(data)})()):
            receiver.main()

    def test_retains_exactly_seven_successful_days(self):
        for day in range(1, 10):
            self.backup(f'2026-10-{day:02d}')
        self.invoke('prune')
        self.assertEqual([r['day'] for r in receiver.receipts()], [f'2026-10-{d:02d}' for d in range(3, 10)])

    def test_corrupt_replacement_prevents_any_pruning(self):
        for day in range(1, 10):
            self.backup(f'2026-10-{day:02d}')
        p = self.daily / '2026-10-09.cms'
        p.write_bytes(b'x' * p.stat().st_size)
        with self.assertRaisesRegex(ValueError, 'checksum'):
            self.invoke('prune')
        self.assertEqual(len(list(self.daily.glob('*.cms'))), 9)

    def test_truncated_upload_is_never_committed(self):
        day = dt.datetime.now(ZoneInfo('Asia/Shanghai')).date().isoformat()
        with self.assertRaisesRegex(ValueError, 'truncated'):
            self.invoke('upload', f'upload {day} 1024 ' + '0' * 64, b'short')
        self.assertEqual(list(self.daily.glob('*.cms')), [])
        self.assertEqual(list(self.daily.glob('.upload-*')), [])

    def test_bad_checksum_is_never_committed(self):
        day = dt.datetime.now(ZoneInfo('Asia/Shanghai')).date().isoformat()
        with self.assertRaisesRegex(ValueError, 'checksum'):
            self.invoke('upload', f'upload {day} 1024 ' + '0' * 64, b'x' * 1024)
        self.assertEqual(receiver.receipts(), [])

    def test_read_key_cannot_upload(self):
        with self.assertRaises(PermissionError):
            self.invoke('read', 'upload 2026-11-07 1024 ' + '0' * 64)

    def test_path_escape_and_shell_are_denied(self):
        for mode, command in [('read', 'get ../../etc/passwd'), ('upload', 'id')]:
            with self.assertRaises((ValueError, PermissionError)):
                self.invoke(mode, command)

    def test_unmounted_disk_fails_closed(self):
        with patch.object(receiver.os.path, 'ismount', return_value=False):
            with self.assertRaisesRegex(RuntimeError, 'not mounted'):
                self.invoke('upload', 'status')


if __name__ == '__main__':
    unittest.main()
