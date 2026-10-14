#!/usr/bin/env python3
"""Read-only subscription access analysis. Never emit URLs, tokens, IPs or raw UA.

Normal runs submit one structured summary to Xboard, including an empty summary
so a previously rejected notification can recover. Historical replay is dry-run
only; notification state and identity mapping remain inside Xboard.
"""
import argparse
import collections
import contextlib
import datetime
import fcntl
import gzip
import hashlib
import ipaddress
import json
import math
import os
import pathlib
import re
import statistics
import subprocess
import sys
import time
import uuid
from urllib.parse import parse_qs, unquote, urlsplit

DEFAULTS = {
    'threshold': 70, 'window_seconds': 7200, 'background_seconds': 86400,
    'r1_min_downloads': 60, 'r1_min_span_seconds': 1800, 'r1_cli_ratio': .8,
    'r1_median_seconds': 60, 'r1_short_seconds': 60, 'r1_short_ratio': .7,
    'r2_min_clusters': 3, 'r2_cluster_gap_seconds': 1800,
    'r2_window_seconds': 60, 'r2_min_downloads': 10, 'r2_median_seconds': 3, 'r2_max_bytes7d': 128 * 1024 ** 2,
}
DEFAULT_SITES = ('www.elephant111.com', 'www.elephant23.com', 'dx.elephantroute.com',
                 'www.elephant111.org', 'www.elephantroute.com')
SCRIPT_FAMILIES = frozenset(('curl', 'wget', 'python-requests', 'aiohttp', 'httpx', 'go-http-client'))
LOG_PATTERN = re.compile(
    r'^(?P<peer>\S+) \S+ \S+ \[(?P<time>[^\]]+)\] '
    r'"(?P<request>(?:[^"\\]|\\.)*)" (?P<status>\d{3}) (?P<size>\d+|-) '
    r'"(?:[^"\\]|\\.)*" "(?P<ua>(?:[^"\\]|\\.)*)"(?:\s+"(?P<xff>(?:[^"\\]|\\.)*)")?\s*$')
MONTHS = {name: i for i, name in enumerate(('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                                         'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'), 1)}


class MonitorError(Exception):
    """A fixed error category, safe for stderr."""


def token_hash(token):
    return hashlib.sha256(token.encode('utf-8')).hexdigest()


def ua_family(value):
    value = value.lower()
    for name in ('curl', 'wget', 'python-requests', 'aiohttp', 'httpx', 'go-http-client',
                 'shadowrocket', 'sing-box', 'clash'):
        if name in value:
            return name
    return 'other'


def parse_timestamp(value):
    # Parse fixed English nginx month names independently of the host locale.
    match = re.fullmatch(r'(\d{2})/([A-Z][a-z]{2})/(\d{4}):(\d{2}):(\d{2}):(\d{2}) ([+-])(\d{2})(\d{2})', value)
    if not match:
        raise ValueError('timestamp')
    day, month, year, hour, minute, second, sign, zone_hour, zone_minute = match.groups()
    offset = (int(zone_hour) * 60 + int(zone_minute)) * (1 if sign == '+' else -1)
    tz = datetime.timezone(datetime.timedelta(minutes=offset))
    return int(datetime.datetime(int(year), MONTHS[month], int(day), int(hour), int(minute), int(second), tzinfo=tz).timestamp())


def _source(peer, forwarded, trusted_proxy_cidrs):
    address = ipaddress.ip_address(peer)
    if trusted_proxy_cidrs and any(address in ipaddress.ip_network(cidr) for cidr in trusted_proxy_cidrs):
        # Walk from the trusted edge toward the client, never trust a forged
        # leftmost address when a nearer untrusted hop exists.
        chain = [ipaddress.ip_address(part.strip()) for part in forwarded.split(',') if part.strip() and part.strip() != '-']
        for candidate in reversed(chain):
            address = candidate
            if not any(address in ipaddress.ip_network(cidr) for cidr in trusted_proxy_cidrs):
                break
    return str(address)


def _parse_access_line(line, subscribe_path, identities, known_node_ips=(),
                       include_excluded=False, trusted_proxy_cidrs=()):
    match = LOG_PATTERN.fullmatch(line.rstrip('\r\n'))
    if not match:
        return None, 'unparsed', None
    data = match.groupdict()
    try:
        at = parse_timestamp(data['time'])
    except (ValueError, KeyError, OverflowError):
        return None, 'unparsed', None
    request = data['request'].split()
    if len(request) != 3 or not request[2].startswith('HTTP/'):
        return None, 'unparsed', at
    method, target, _ = request
    try:
        parsed = urlsplit(target)
        # nginx logs origin-form request targets; absolute URLs and encoded
        # alternative paths cannot establish a verified subscription endpoint.
        routes = {subscribe_path} if isinstance(subscribe_path, str) else set(subscribe_path)
        legacy = '/api/v1/client/subscribe'
        path_token = None
        matched = parsed.path == legacy and legacy in routes
        for route in routes - {legacy}:
            prefix = route.rstrip('/') + '/'
            if parsed.path.startswith(prefix):
                segment = parsed.path[len(prefix):]
                # Real Xboard endpoint is /<subscribe_path>/<token>, not a
                # query-only /s endpoint. A nested suffix is a different route.
                if segment and '/' not in segment:
                    token = unquote(segment, errors='strict')
                    if token and not any(ord(c) <= 32 or c in '/\\' for c in token):
                        matched, path_token = True, token
                break
        if parsed.scheme or parsed.netloc or not matched:
            return None, 'other_paths', at
        if method != 'GET':
            return None, 'excluded_methods', at
        if int(data['status']) != 200:
            return None, 'excluded_statuses', at
        if data['size'] == '-' or int(data['size']) <= 0:
            return None, 'empty_responses', at
        query = parse_qs(parsed.query, keep_blank_values=True, max_num_fields=64)
        tokens = query.get('token', [])
        # Laravel gives query token priority. Reject ambiguous requests rather
        # than attribute a request to a different account from its URL path.
        if len(tokens) > 1 or (tokens and (not tokens[0] or (path_token is not None and tokens[0] != path_token))):
            return None, 'ambiguous_tokens', at
        token = path_token if path_token is not None else tokens[0] if tokens else None
        if not token:
            return None, 'unmatched_tokens', at
        identity = identities.get(token_hash(token))
        if not identity:
            return None, 'unmatched_tokens', at
        if identity['id'] == 0:
            return None, 'ambiguous_identities', at
        if identity['excluded'] and not include_excluded:
            return None, 'excluded_users', at
        source = _source(data['peer'], data['xff'] or '', trusted_proxy_cidrs)
        event = {'at': at, 'user_id': identity['id'], 'ua_family': ua_family(data['ua']),
                 'source': token_hash(source), 'source_is_node': source in known_node_ips}
        return event, 'successful_gets', at
    except (ValueError, TypeError, KeyError, UnicodeError):
        return None, 'unparsed', at


def parse_access_line(line, subscribe_path, identities, known_node_ips=(),
                      include_excluded=False, trusted_proxy_cidrs=()):
    return _parse_access_line(line, subscribe_path, identities, known_node_ips,
                              include_excluded, trusted_proxy_cidrs)[0]


def discover_logs(log_root, sites=None):
    root = pathlib.Path(log_root)
    paths, seen = [], set()
    try:
        directories = [root / site / 'log' for site in sites] if sites is not None else root.glob('*/log')
        for directory in directories:
            for path in sorted(directory.glob('access.log*')):
                if not path.is_file():
                    continue
                stat = path.stat()
                identity = (stat.st_dev, stat.st_ino)
                if identity not in seen:
                    paths.append(path)
                    seen.add(identity)
    except OSError:
        raise MonitorError('logs_unreadable') from None
    if not paths:
        raise MonitorError('logs_missing')
    return paths


def collect_events(paths, subscribe_path, identities, background_start, end,
                   known_node_ips=(), include_excluded=False, trusted_proxy_cidrs=(), check_start=None):
    events, diagnostics = [], collections.Counter()
    diagnostics['log_files'] = len(paths)
    check_start = background_start if check_start is None else check_start
    for path in paths:
        opener = gzip.open if str(path).endswith('.gz') else open
        try:
            # Rotation archives are immutable. Their latest write predates
            # every event they can contain under the server's clock. Compare
            # Unix epoch values, never local-date strings; retain equality at
            # the 24-hour boundary. Always read the active access.log even if
            # its mtime is old, so stale logging causes a technical failure.
            if pathlib.Path(path).name != 'access.log' and pathlib.Path(path).stat().st_mtime < background_start:
                diagnostics['skipped_archives'] += 1
                continue
            diagnostics['read_log_files'] += 1
            with opener(path, 'rt', encoding='utf-8', errors='replace') as stream:
                for line in stream:
                    event, reason, at = _parse_access_line(line, subscribe_path, identities,
                                                           known_node_ips, include_excluded, trusted_proxy_cidrs)
                    if at is not None and background_start <= at <= end:
                        diagnostics['window_log_records'] += 1
                        if check_start < at <= end:
                            diagnostics['check_window_log_records'] += 1
                        diagnostics[reason] += 1
                        if event:
                            events.append(event)
                    elif at is None:
                        diagnostics['unparsed'] += 1
        except (OSError, EOFError):
            raise MonitorError('logs_read_failed') from None
    diagnostics.setdefault('successful_gets', 0)
    if not diagnostics['window_log_records']:
        raise MonitorError('logs_window_missing')
    if not diagnostics['check_window_log_records']:
        raise MonitorError('logs_check_window_missing')
    return events, dict(diagnostics)


def rolling_peak(times, seconds=60):
    peak, left = 0, 0
    for right, at in enumerate(times):
        while at - times[left] > seconds:
            left += 1
        peak = max(peak, right - left + 1)
    return peak


def burst_clusters(times, config):
    clusters, left = [], 0
    for right, at in enumerate(times):
        while at - times[left] > config['r2_window_seconds']:
            left += 1
        if right - left + 1 < config['r2_min_downloads']:
            continue
        intervals = [times[i] - times[i - 1] for i in range(left + 1, right + 1)]
        if statistics.median(intervals) > config['r2_median_seconds']:
            continue
        start = times[left]
        # All qualifying windows within 30 minutes belong to one episode.
        if clusters and start - clusters[-1]['end'] < config['r2_cluster_gap_seconds']:
            clusters[-1]['end'] = at
        else:
            clusters.append({'start': start, 'end': at})
    return clusters


def evaluate_user(user_id, events, start, end, traffic, config=None):
    config = {**DEFAULTS, **(config or {})}
    events = sorted((e for e in events if end - config['background_seconds'] <= e['at'] <= end), key=lambda e: e['at'])
    recent = [e for e in events if start < e['at'] <= end]
    if not recent:
        return None
    times = [e['at'] for e in recent]
    gaps = [b - a for a, b in zip(times, times[1:])]
    families = dict(sorted(collections.Counter(e['ua_family'] for e in recent).items()))
    script_ratio = sum(families.get(name, 0) for name in SCRIPT_FAMILIES) / len(recent)
    median = statistics.median(gaps) if gaps else None
    short_ratio = sum(gap < config['r1_short_seconds'] for gap in gaps) / len(gaps) if gaps else 0
    clusters = burst_clusters([e['at'] for e in events], config)
    known = traffic.get('known') is True
    bytes7d = traffic.get('bytes7d') if known else None
    r1 = (len(recent) >= config['r1_min_downloads'] and times[-1] - times[0] >= config['r1_min_span_seconds']
          and script_ratio >= config['r1_cli_ratio'] and median is not None
          and median <= config['r1_median_seconds'] and short_ratio >= config['r1_short_ratio'])
    bursts = len(clusters) >= config['r2_min_clusters'] and any(c['end'] > start for c in clusters)
    r2 = bursts and known and isinstance(bytes7d, (int, float)) and 0 <= bytes7d <= config['r2_max_bytes7d']
    score = max(80 if r1 else 0, 70 if r2 else 55 if bursts else 0)
    if score < config['threshold']:
        return None
    rules = (['R1'] if r1 else []) + (['R2'] if r2 else [])
    if not rules:
        return None
    metrics = {'get_count': len(recent), 'span_seconds': times[-1] - times[0],
               'script_ratio': round(script_ratio, 4), 'median_gap_seconds': median,
               'short_gap_ratio': round(short_ratio, 4), 'minute_peak': rolling_peak(times),
               'burst_clusters': len(clusters),
               'source_count': len({e['source'] for e in recent if not e['source_is_node']}),
               'node_source_count': len({e['source'] for e in recent if e['source_is_node']}),
               'bytes7d': bytes7d, 'traffic_known': known, 'ua_families': families}
    reasons = (['sustained_script_downloads'] if r1 else []) + (['repeated_bursts_with_low_usage'] if r2 else [])
    return {'user_id': user_id, 'score': score, 'rules': rules, 'metrics': metrics, 'reasons': reasons}


def window_start(at, last_success_at, config=None):
    config = {**DEFAULTS, **(config or {})}
    if last_success_at is None:
        return at - config['window_seconds']
    if isinstance(last_success_at, bool) or not isinstance(last_success_at, int) or last_success_at > at:
        raise MonitorError('invalid_last_success')
    return max(at - config['background_seconds'], min(last_success_at, at - config['window_seconds']))


def validate_replay(dry_run, at, include_excluded):
    if at is not None and not dry_run:
        raise MonitorError('historical_replay_requires_dry_run')
    if include_excluded and (not dry_run or at is None):
        raise MonitorError('excluded_replay_requires_dry_run_at')


def docker_json(container, command, arguments, payload=None):
    argv = ['docker', 'exec']
    if payload is not None:
        argv.append('-i')
    argv.extend([container, 'php', 'artisan', 'behavior:' + command, *arguments])
    try:
        result = subprocess.run(argv, input=json.dumps(payload, ensure_ascii=False) if payload is not None else None,
                                capture_output=True, text=True, timeout=180, check=False)
    except subprocess.TimeoutExpired:
        raise MonitorError(command + '_command_timeout') from None
    except (OSError, UnicodeError):
        raise MonitorError(command + '_command_unavailable') from None
    if result.returncode:
        raise MonitorError(command + '_command_failed')
    try:
        value = json.loads(result.stdout)
    except (ValueError, TypeError):
        raise MonitorError(command + '_invalid_json') from None
    if not isinstance(value, dict):
        raise MonitorError(command + '_invalid_contract')
    return value


def validate_snapshot(snapshot):
    if snapshot.get('version') != 1 or not isinstance(snapshot.get('config'), dict) or 'last_success_at' not in snapshot:
        raise MonitorError('snapshot_invalid_contract')
    if any(key not in snapshot['config'] for key in DEFAULTS):
        raise MonitorError('snapshot_configuration_missing')
    config = {**DEFAULTS, **snapshot['config']}
    for key, default in DEFAULTS.items():
        value = config[key]
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or value <= 0:
            raise MonitorError('snapshot_invalid_configuration')
        if isinstance(default, int) and not isinstance(value, int):
            raise MonitorError('snapshot_invalid_configuration')
        if key.endswith('ratio') and value > 1:
            raise MonitorError('snapshot_invalid_configuration')
    if config['background_seconds'] > 86400 or config['window_seconds'] > config['background_seconds']:
        raise MonitorError('snapshot_invalid_configuration')
    users = snapshot.get('users')
    if not isinstance(users, dict) or not users:
        raise MonitorError('snapshot_identity_data_missing')
    for digest, identity in users.items():
        if not re.fullmatch('[0-9a-f]{64}', digest) or not isinstance(identity, dict):
            raise MonitorError('snapshot_invalid_identity')
        uid = identity.get('id')
        if (isinstance(uid, bool) or not isinstance(uid, int) or uid < 0
                or not isinstance(identity.get('excluded'), bool) or (uid == 0 and not identity['excluded'])):
            raise MonitorError('snapshot_invalid_identity')
    path = snapshot.get('subscribe_path')
    if not isinstance(path, str) or not path or '?' in path or '#' in path or '://' in path:
        raise MonitorError('snapshot_invalid_subscribe_path')
    path = '/' + path.lstrip('/')
    nodes = snapshot.get('known_node_ips')
    if not isinstance(nodes, list):
        raise MonitorError('snapshot_invalid_node_data')
    try:
        nodes = {str(ipaddress.ip_address(ip)) for ip in nodes}
    except (ValueError, TypeError):
        raise MonitorError('snapshot_invalid_node_data') from None
    return config, users, [path, '/api/v1/client/subscribe'], nodes


@contextlib.contextmanager
def host_lock(path):
    try:
        path = pathlib.Path(path)
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        fd = os.open(path, os.O_CREAT | os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    except OSError:
        raise MonitorError('host_lock_unavailable') from None
    try:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise MonitorError('host_lock_busy') from None
        yield
    finally:
        os.close(fd)


def run(args):
    validate_replay(args.dry_run, args.at, args.include_excluded_for_replay)
    at = args.at if args.at is not None else int(time.time())
    if args.at is not None and (at <= 0 or at > int(time.time())):
        raise MonitorError('invalid_replay_time')
    run_id = str(uuid.uuid4())
    snapshot = docker_json(args.container, 'snapshot', ['--mode=identities', '--at=' + str(at)])
    config, identities, paths, nodes = validate_snapshot(snapshot)
    start = window_start(at, None if args.at is not None else snapshot.get('last_success_at'), config)
    logs = discover_logs(args.log_root, args.sites.split(','))
    events, diagnostics = collect_events(logs, paths, identities, at - config['background_seconds'], at,
                                          nodes, args.include_excluded_for_replay, check_start=start)
    grouped = collections.defaultdict(list)
    for event in events:
        grouped[event['user_id']].append(event)
    prospective = [uid for uid, items in grouped.items()
                   if evaluate_user(uid, items, start, at, {'known': True, 'bytes7d': 0}, config)]
    traffic = {}
    for offset in range(0, len(prospective), 250):
        ids = prospective[offset:offset + 250]
        result = docker_json(args.container, 'snapshot', ['--mode=traffic', '--ids=' + ','.join(map(str, ids)), '--at=' + str(at)])
        if not isinstance(result.get('traffic'), dict):
            raise MonitorError('traffic_invalid_contract')
        for uid in ids:
            row = result['traffic'].get(str(uid))
            if not isinstance(row, dict) or not isinstance(row.get('known'), bool):
                raise MonitorError('traffic_data_missing')
            if row['known'] and (isinstance(row.get('bytes7d'), bool) or not isinstance(row.get('bytes7d'), (int, float))
                                 or not math.isfinite(row['bytes7d']) or row['bytes7d'] < 0 or row.get('last_updated') is None):
                raise MonitorError('traffic_invalid_data')
            traffic[uid] = row
    candidates = [evaluate_user(uid, grouped[uid], start, at, traffic[uid], config) for uid in prospective]
    candidates = sorted((c for c in candidates if c), key=lambda c: (-c['score'], -c['metrics']['get_count'], c['user_id']))
    payload = {'version': 1, 'run_id': run_id, 'window_start': start, 'window_end': at,
               'candidates': candidates, 'diagnostics': diagnostics}
    if args.dry_run:
        # Strictly anonymous except authorized IDs; never print identity map.
        return {'status': 'dry_run', **payload}
    result = docker_json(args.container, 'notify', [], payload)
    allowed = ('status', 'matched', 'sent', 'suppressed', 'delivery', 'run_id')
    if not isinstance(result.get('status'), str) or result['status'] not in ('ok', 'sent', 'suppressed', 'duplicate', 'empty', 'success', 'pending', 'uncertain', 'failed'):
        raise MonitorError('notify_invalid_contract')
    # Never echo arbitrary values from a subprocess even on its success path.
    summary = {'run_id': run_id, 'status': result['status']}
    for key in allowed[1:4]:
        if key in result and isinstance(result[key], int) and not isinstance(result[key], bool):
            summary[key] = result[key]
    if result.get('delivery') in ('confirmed', 'none', 'uncertain', 'rejected', 'message', 'document'):
        summary['delivery'] = result['delivery']
    if summary['status'] in ('pending', 'uncertain', 'failed'):
        raise MonitorError('notify_delivery_' + summary['status'])
    return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--log-root', default='/opt/1panel/www/sites')
    parser.add_argument('--sites', default=','.join(DEFAULT_SITES))
    parser.add_argument('--container', default='index-web-1')
    parser.add_argument('--lock-file', default='/run/xboard-subscription-behavior/monitor.lock')
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--at', type=int)
    parser.add_argument('--include-excluded-for-replay', action='store_true')
    args = parser.parse_args()
    try:
        validate_replay(args.dry_run, args.at, args.include_excluded_for_replay)
        with host_lock(args.lock_file):
            print(json.dumps(run(args), ensure_ascii=False, separators=(',', ':')))
        return 0
    except MonitorError as error:
        print(json.dumps({'status': 'failed', 'error': str(error)}), file=sys.stderr)
        return 1
    except Exception:
        # Tracebacks may include a raw request or command stdout. Keep all
        # unexpected exceptions private and fail closed with a fixed category.
        print('{"status":"failed","error":"unexpected_monitor_failure"}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
