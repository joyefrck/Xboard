import gzip
import importlib.util
import json
import pathlib
import os
import tempfile
import unittest
from argparse import Namespace
from unittest.mock import patch

MODULE = pathlib.Path(__file__).parents[1] / 'scripts/subscription-behavior-monitor.py'
spec = importlib.util.spec_from_file_location('subscription_behavior_monitor', MODULE)
monitor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(monitor)


class SubscriptionBehaviorMonitorTests(unittest.TestCase):
    def setUp(self):
        self.now = 1791986400
        self.identities = {monitor.token_hash('sample-token'): {'id': 6571, 'excluded': False}}

    def line(self, method='GET', status=200, size=123, path='/custom/sub/sample-token',
             ua='curl/8.18.0', ip='192.0.2.1', time='15/Oct/2026:00:00:00 +0800'):
        return f'{ip} - - [{time}] "{method} {path} HTTP/1.1" {status} {size} "-" "{ua}"\n'

    def events(self, times, family='curl', uid=6571):
        return [{'at': t, 'user_id': uid, 'ua_family': family, 'source': 'hashed-source',
                 'source_is_node': False} for t in times]

    def test_strict_subscription_nonempty_get_only(self):
        record = monitor.parse_access_line(self.line(), '/custom/sub', self.identities)
        self.assertEqual(record['user_id'], 6571)
        self.assertEqual(record['ua_family'], 'curl')
        for kwargs in ({'method': 'HEAD'}, {'status': 301}, {'status': 403}, {'size': 0},
                       {'path': '/other?token=sample-token'},
                       {'path': '/custom/subextra/sample-token'},
                       {'path': '/custom/sub/sample-token?token=sample-token&token=other'},
                       {'path': '/custom/sub/old-token'}):
            self.assertIsNone(monitor.parse_access_line(self.line(**kwargs), '/custom/sub', self.identities))
        self.assertNotIn('sample-token', json.dumps(record))
        self.assertNotIn('192.0.2.1', json.dumps(record))
        self.assertNotIn('8.18.0', json.dumps(record))

    def test_timezone_midnight_and_token_query_order(self):
        a = monitor.parse_access_line(self.line(path='/custom/sub/sample-token?flag=clash&token=sample-token'), '/custom/sub', self.identities)
        b = monitor.parse_access_line(self.line(time='14/Oct/2026:16:00:00 +0000'), '/custom/sub', self.identities)
        self.assertEqual(a['at'], b['at'])

    def test_real_path_token_legacy_and_conflict_rejected(self):
        routes = ['/s', '/api/v1/client/subscribe']
        for path in ['/s/sample-token', '/s/sample%2Dtoken', '/api/v1/client/subscribe?token=sample-token']:
            self.assertEqual(monitor.parse_access_line(self.line(path=path), routes, self.identities)['user_id'], 6571)
        for path in ['/s?token=sample-token', '/s/sample-token/extra', '/s/sample-token?token=other',
                     '/s/sample%2Ftoken', '/s/', '/s/sample-token?token=']:
            self.assertIsNone(monitor.parse_access_line(self.line(path=path), routes, self.identities))

    def test_exclusion_requires_explicit_replay(self):
        self.identities[monitor.token_hash('sample-token')]['excluded'] = True
        self.assertIsNone(monitor.parse_access_line(self.line(), '/custom/sub', self.identities))
        self.assertEqual(monitor.parse_access_line(self.line(), '/custom/sub', self.identities, include_excluded=True)['user_id'], 6571)

    def test_r1_35_second_cli_and_normal_updates(self):
        cli = self.events([self.now - 4200 + i * 35 for i in range(120)])
        result = monitor.evaluate_user(6571, cli, self.now - 7200, self.now, {'known': True, 'bytes7d': 1024})
        self.assertEqual(result['score'], 80)
        self.assertEqual(result['rules'], ['R1'])
        for family, interval in [('clash', 60), ('clash', 300), ('curl', 60)]:
            normal = self.events([self.now - 7200 + i * interval for i in range(24 if interval == 300 else 120)], family)
            self.assertIsNone(monitor.evaluate_user(6571, normal, self.now - 7200, self.now, {'known': True, 'bytes7d': 0}))

    def bursts(self):
        return self.events([self.now - base + i * 2 for base in [14400, 10800, 3600] for i in range(12)], 'clash')

    def test_r2_three_bursts_low_known_usage(self):
        r = monitor.evaluate_user(6571, self.bursts(), self.now - 7200, self.now, {'known': True, 'bytes7d': 32 * 1024 ** 2})
        self.assertEqual(r['score'], 70)
        self.assertEqual(r['rules'], ['R2'])
        for traffic in [{'known': False, 'bytes7d': 0}, {'known': True, 'bytes7d': 129 * 1024 ** 2}]:
            self.assertIsNone(monitor.evaluate_user(6571, self.bursts(), self.now - 7200, self.now, traffic))
        self.assertIsNone(monitor.evaluate_user(6571, self.bursts()[12:], self.now - 7200, self.now, {'known': True, 'bytes7d': 0}))

    def test_old_bursts_and_close_bursts_do_not_trigger(self):
        old = self.events([self.now - base + i * 2 for base in [18000, 14400, 10800] for i in range(12)], 'clash')
        self.assertIsNone(monitor.evaluate_user(6571, old, self.now - 7200, self.now, {'known': True, 'bytes7d': 0}))
        close = self.events([self.now - base + i * 2 for base in [3600, 3000, 2400] for i in range(12)], 'clash')
        self.assertIsNone(monitor.evaluate_user(6571, close, self.now - 7200, self.now, {'known': True, 'bytes7d': 0}))

    def test_sliding_window_peak_and_multiple_rules(self):
        self.assertEqual(monitor.rolling_peak([58, 59, 61, 62], 60), 4)
        cli = self.events([self.now - 4200 + i * 35 for i in range(120)])
        r = monitor.evaluate_user(6571, cli + self.bursts(), self.now - 7200, self.now, {'known': True, 'bytes7d': 0})
        self.assertEqual(r['rules'], ['R1', 'R2'])

    def test_own_node_source_not_independent_evidence(self):
        record = monitor.parse_access_line(self.line(), '/custom/sub', self.identities, known_node_ips=['192.0.2.1'])
        self.assertTrue(record['source_is_node'])
        self.assertNotIn('192.0.2.1', json.dumps(record))
        ordinary = self.events([self.now - i * 300 for i in range(20)], 'clash')
        ordinary += [dict(e, source=str(i), source_is_node=True) for i, e in enumerate(ordinary)]
        self.assertIsNone(monitor.evaluate_user(6571, ordinary, self.now - 7200, self.now, {'known': True, 'bytes7d': 0}))

    def test_gzip_rotations_inode_dedupe_and_new_window(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            log = root / 'site/log/access.log'
            log.parent.mkdir(parents=True)
            log.write_text(self.line())
            log.with_name('access.log.link').hardlink_to(log)
            with gzip.open(log.with_name('access.log.1.gz'), 'wt') as f:
                f.write(self.line(time='14/Oct/2026:23:59:00 +0800'))
            paths = monitor.discover_logs(root)
            self.assertEqual(len(paths), 2)
            end = monitor.parse_access_line(self.line(), '/custom/sub', self.identities)['at']
            events, diagnostics = monitor.collect_events(paths, '/custom/sub', self.identities, end - 86400, end)
            self.assertEqual(len(events), 2)
            self.assertEqual(diagnostics['successful_gets'], 2)

    def test_window_catchup_cap_and_invalid_future(self):
        self.assertEqual(monitor.window_start(self.now, None), self.now - 7200)
        self.assertEqual(monitor.window_start(self.now, self.now - 10800), self.now - 10800)
        self.assertEqual(monitor.window_start(self.now, self.now - 172800), self.now - 86400)
        with self.assertRaises(monitor.MonitorError):
            monitor.window_start(self.now, self.now + 100)

    def test_sensitive_subprocess_error_redacted(self):
        from subprocess import CompletedProcess
        with patch.object(monitor.subprocess, 'run', return_value=CompletedProcess([], 1, 'email secret token', 'credential')):
            with self.assertRaisesRegex(monitor.MonitorError, '^snapshot_command_failed$'):
                monitor.docker_json('index-web-1', 'snapshot', [])

    def test_replay_flags_are_constrained(self):
        with self.assertRaises(monitor.MonitorError):
            monitor.validate_replay(False, self.now, False)
        with self.assertRaises(monitor.MonitorError):
            monitor.validate_replay(True, None, True)
        monitor.validate_replay(True, self.now, True)

    def test_missing_logs_technical_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(monitor.MonitorError):
                monitor.discover_logs(pathlib.Path(directory))

    def test_trusted_xff_and_untrusted_forgery(self):
        log = self.line(ip='192.0.2.1').rstrip() + ' "203.0.113.20"\n'
        untrusted = monitor.parse_access_line(log, '/custom/sub', self.identities)
        trusted = monitor.parse_access_line(log, '/custom/sub', self.identities, trusted_proxy_cidrs=['192.0.2.0/24'])
        self.assertEqual(untrusted['source'], monitor.token_hash('192.0.2.1'))
        self.assertEqual(trusted['source'], monitor.token_hash('203.0.113.20'))

    def test_corrupt_gzip_and_empty_window_fail(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'access.log.gz'
            path.write_bytes(b'corrupt')
            with self.assertRaisesRegex(monitor.MonitorError, 'logs_read_failed'):
                monitor.collect_events([path], '/custom/sub', self.identities, 0, self.now)
            path = pathlib.Path(directory) / 'access.log'
            path.write_text('not nginx')
            with self.assertRaisesRegex(monitor.MonitorError, 'logs_window_missing'):
                monitor.collect_events([path], '/custom/sub', self.identities, 0, self.now)

    def test_stale_only_background_records_cannot_advance_check(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'access.log'
            path.write_text(self.line(time='14/Oct/2026:23:00:00 +0800'))
            end = monitor.parse_access_line(self.line(time='15/Oct/2026:04:00:00 +0800'), '/custom/sub', self.identities)['at']
            with self.assertRaisesRegex(monitor.MonitorError, 'logs_check_window_missing'):
                monitor.collect_events([path], '/custom/sub', self.identities, end - 86400, end,
                                       check_start=end - 7200)
            # A new non-subscription access establishes live logging, without
            # manufacturing a subscription download or a candidate.
            with path.open('a') as f:
                f.write(self.line(path='/health', time='15/Oct/2026:03:00:00 +0800'))
            events, diagnostics = monitor.collect_events([path], '/custom/sub', self.identities,
                                                          end - 86400, end, check_start=end - 7200)
            self.assertEqual(len(events), 1)
            self.assertEqual(diagnostics['check_window_log_records'], 1)

    def test_archive_mtime_pruning_keeps_boundary_and_active_log(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            end = monitor.parse_access_line(self.line(), '/custom/sub', self.identities)['at']
            background = end - 86400
            active = root / 'access.log'
            active.write_text(self.line())
            os.utime(active, (background - 10, background - 10))
            old = root / 'access.log-20261010.gz'
            old.write_bytes(b'not even gzip: must not open this old archive')
            os.utime(old, (background - 1, background - 1))
            boundary = root / 'access.log-20261014.gz'
            with gzip.open(boundary, 'wt') as f:
                f.write(self.line(time='14/Oct/2026:00:00:00 +0800'))
            os.utime(boundary, (background, background))
            events, diagnostics = monitor.collect_events([old, boundary, active], '/custom/sub',
                                                          self.identities, background, end, check_start=end - 7200)
            self.assertEqual(len(events), 2)
            self.assertEqual(diagnostics['skipped_archives'], 1)
            self.assertEqual(diagnostics['check_window_log_records'], 1)

    def snapshot(self):
        return {'version': 1, 'config': dict(monitor.DEFAULTS), 'subscribe_path': 'custom/sub', 'users': self.identities,
                'known_node_ips': [], 'last_success_at': None}

    def test_snapshot_contract_invalid_or_missing(self):
        for change in [{'users': {}}, {'version': 2}, {'subscribe_path': 'https://invalid'},
                       {'known_node_ips': ['invalid']}, {'config': {}},
                       {'config': {**monitor.DEFAULTS, 'r1_cli_ratio': 8}}]:
            with self.assertRaises(monitor.MonitorError):
                monitor.validate_snapshot({**self.snapshot(), **change})

    def test_ambiguous_duplicate_token_never_attributed(self):
        self.identities[monitor.token_hash('sample-token')] = {'id': 0, 'excluded': True}
        monitor.validate_snapshot(self.snapshot())
        self.assertIsNone(monitor.parse_access_line(self.line(), '/custom/sub', self.identities, include_excluded=True))

    def test_main_empty_candidates_still_notifies_once(self):
        args = Namespace(dry_run=False, at=None, include_excluded_for_replay=False,
                         container='index-web-1', log_root='unused', sites='site')
        responses = [self.snapshot(), {'status': 'ok', 'matched': 0, 'sent': 0, 'suppressed': 0, 'delivery': 'none'}]
        with patch.object(monitor, 'docker_json', side_effect=responses) as docker, \
             patch.object(monitor, 'discover_logs', return_value=[]), \
             patch.object(monitor, 'collect_events', return_value=([], {'successful_gets': 0})):
            result = monitor.run(args)
        self.assertEqual(result['sent'], 0)
        self.assertEqual(docker.call_count, 2)
        self.assertEqual(docker.call_args.args[1], 'notify')
        self.assertEqual(docker.call_args.args[3]['candidates'], [])

    def test_dry_run_never_calls_notify_or_persists_state(self):
        args = Namespace(dry_run=True, at=self.now, include_excluded_for_replay=False,
                         container='index-web-1', log_root='unused', sites='site')
        with patch.object(monitor, 'docker_json', return_value=self.snapshot()) as docker, \
             patch.object(monitor.time, 'time', return_value=self.now), \
             patch.object(monitor, 'discover_logs', return_value=[]), \
             patch.object(monitor, 'collect_events', return_value=([], {'successful_gets': 0})):
            result = monitor.run(args)
        self.assertEqual(result['status'], 'dry_run')
        self.assertEqual(docker.call_count, 1)

    def test_traffic_failure_does_not_notify(self):
        args = Namespace(dry_run=False, at=None, include_excluded_for_replay=False,
                         container='index-web-1', log_root='unused', sites='site')
        cli = self.events([self.now - 4200 + i * 35 for i in range(120)])
        with patch.object(monitor, 'docker_json', side_effect=[self.snapshot(), {'traffic': {}}]) as docker, \
             patch.object(monitor.time, 'time', return_value=self.now), \
             patch.object(monitor, 'discover_logs', return_value=[]), \
             patch.object(monitor, 'collect_events', return_value=(cli, {'successful_gets': 120})):
            with self.assertRaisesRegex(monitor.MonitorError, 'traffic_data_missing'):
                monitor.run(args)
        self.assertEqual(docker.call_count, 2)

    def test_subprocess_success_output_whitelist(self):
        args = Namespace(dry_run=False, at=None, include_excluded_for_replay=False,
                         container='index-web-1', log_root='unused', sites='site')
        dirty = {'status': 'ok', 'matched': 'sensitive@example.org', 'sent': 0,
                 'delivery': 'SECRET', 'run_id': 'SECRET', 'message': 'SECRET'}
        with patch.object(monitor, 'docker_json', side_effect=[self.snapshot(), dirty]), \
             patch.object(monitor, 'discover_logs', return_value=[]), \
             patch.object(monitor, 'collect_events', return_value=([], {})):
            result = monitor.run(args)
        self.assertNotIn('SECRET', json.dumps(result))
        self.assertNotIn('sensitive@example.org', json.dumps(result))

    def test_host_flock_prevents_overlap(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'monitor.lock'
            with monitor.host_lock(path):
                with self.assertRaisesRegex(monitor.MonitorError, 'host_lock_busy'):
                    with monitor.host_lock(path):
                        self.fail('second lock acquired')


if __name__ == '__main__':
    unittest.main()
