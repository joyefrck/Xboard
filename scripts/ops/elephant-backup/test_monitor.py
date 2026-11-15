import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('monitor', Path(__file__).with_name('monitor.py'))
monitor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(monitor)


class AlertTests(unittest.TestCase):
    def test_health_failure_dedupe_recovery(self):
        with tempfile.TemporaryDirectory() as folder:
            state = Path(folder) / 'state.json'
            sent = []
            self.assertEqual(monitor.notify([], state, 100000, sent.append), 'healthy')
            self.assertFalse(sent)
            self.assertEqual(monitor.notify(['failure'], state, 100001, sent.append), 'sent')
            self.assertEqual(monitor.notify(['failure'], state, 100002, sent.append), 'suppressed')
            self.assertEqual(monitor.notify(['failure'], state, 121602, sent.append), 'sent')
            self.assertEqual(monitor.notify([], state, 121603, sent.append), 'recovery_sent')
            self.assertEqual(monitor.notify([], state, 121604, sent.append), 'healthy')
            self.assertEqual(len(sent), 3)

    def test_uncertain_request_is_not_immediately_repeated(self):
        with tempfile.TemporaryDirectory() as folder:
            state = Path(folder) / 'state.json'
            def fail(text):
                raise OSError('network unavailable')
            with self.assertRaises(OSError):
                monitor.notify(['failure'], state, 100000, fail)
            sent = []
            self.assertEqual(monitor.notify(['failure'], state, 100001, sent.append), 'delivery_uncertain_suppressed')
            self.assertFalse(sent)
            self.assertEqual(monitor.notify(['failure'], state, 103601, sent.append), 'sent')


if __name__ == '__main__':
    unittest.main()
