#!/usr/bin/python3
"""Independent destination checks and fixed-recipient Telegram backup alerts."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess as sp
import sys
import time
import urllib.request

CONF = Path('/etc/elephant-backup/telegram.json')
STATE = Path('/var/lib/elephant-backup-alerts')
UNITS = {'elephant-backup.service', 'elephant-download-site-backup.service',
         'elephant-backup-prune.service'}


def send(text):
    config = json.loads(CONF.read_text())
    request = urllib.request.Request('https://api.telegram.org/bot' + config['token'] + '/sendMessage',
        data=json.dumps({'chat_id': config['chat_id'], 'text': text,
                         'disable_web_page_preview': True}).encode(),
        headers={'Content-Type': 'application/json'})
    # Never log a URL or exception containing the bot token.
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            result = json.load(response)
        if not result.get('ok'):
            raise RuntimeError('Telegram rejected alert')
    except Exception:
        raise RuntimeError('Telegram alert delivery failed or uncertain') from None


def notify(issues, statefile, now, sender=send):
    fingerprint = hashlib.sha256(json.dumps(sorted(issues)).encode()).hexdigest() if issues else ''
    previous = json.loads(statefile.read_text()) if statefile.exists() else {}
    if not fingerprint and not previous.get('fingerprint'):
        return 'healthy'
    if previous.get('fingerprint') == fingerprint:
        if now - previous.get('last_sent', 0) < 6 * 3600:
            return 'suppressed'
        if now - previous.get('inflight_at', 0) < 3600:
            return 'delivery_uncertain_suppressed'
    state = {'fingerprint': fingerprint, 'inflight_at': now,
             'last_sent': previous.get('last_sent', 0) if previous.get('fingerprint') == fingerprint else 0}
    staged = statefile.with_suffix('.partial')
    staged.write_text(json.dumps(state)); staged.replace(statefile)
    text = '大象网络备份告警\n' + '\n'.join(issues) if issues else '大象网络备份检查已恢复正常。'
    sender(text)
    state['last_sent'] = now
    staged.write_text(json.dumps(state)); staged.replace(statefile)
    return 'sent' if issues else 'recovery_sent'


def target_issues(now):
    issues = []
    try:
        env = dict(os.environ, SSH_ORIGINAL_COMMAND='status')
        result = sp.run(['/usr/local/sbin/elephant-backup-receiver', 'upload'],
                        env=env, capture_output=True, check=True, timeout=30)
        status = json.loads(result.stdout)
        if not status['backups'] or now - status['backups'][-1]['committed_at'] > 30 * 3600:
            issues.append('香港主站备份已超过 30 小时未成功提交，请检查源机及上传任务。')
        if status['used_bytes'] >= 80 * 1024 ** 3:
            issues.append('硅谷备份分区占用已达到 80 GiB。')
        if shutil.disk_usage('/').free < 20 * 1024 ** 3:
            issues.append('硅谷系统盘可用空间不足 20 GiB。')
    except Exception:
        issues.append('硅谷备份分区或备份清单不可用，请检查挂载及存储。')
    for unit in sorted(UNITS - {'elephant-backup.service'}):
        result = sp.run(['systemctl', 'show', unit, '-p', 'Result', '--value'],
                        capture_output=True, text=True, timeout=10)
        if result.returncode or result.stdout.strip() != 'success':
            issues.append('硅谷任务失败：' + unit)
    try:
        site = json.loads(Path('/var/lib/elephant-download-site/receipt.json').read_text())
        # Date-only receipts have no success timestamp: allow the current and previous date.
        import datetime as dt
        from zoneinfo import ZoneInfo
        today = dt.datetime.fromtimestamp(now, ZoneInfo('Asia/Shanghai')).date()
        if (today - dt.date.fromisoformat(site['day'])).days > 1:
            issues.append('下载站备份已有两天未更新。')
    except Exception:
        issues.append('下载站备份清单不可用。')
    return issues


def main():
    os.umask(0o077)
    STATE.mkdir(mode=0o700, exist_ok=True)
    with (STATE / 'monitor.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        mode = sys.argv[1] if len(sys.argv) > 1 else 'target'
        if mode == 'test':
            marker = STATE / 'activation-sent'
            if not marker.exists():
                send('大象网络备份告警已启用（验证消息）。\n主站每日北京时间 03:10 备份，保留最近 7 个日版本；失败、超时和空间不足会在这里提醒。')
                marker.write_text(str(int(time.time())))
            print('TELEGRAM_ACTIVATION_API_OK')
            return
        if mode == 'failure':
            unit = sys.argv[2]
            if unit not in UNITS:
                raise ValueError('unknown backup unit')
            issues = ['备份任务失败：' + unit + '。请检查对应 systemd 日志；旧的成功备份仍保留。']
            statefile = STATE / (unit + '.json')
        elif mode == 'target':
            issues = target_issues(time.time())
            statefile = STATE / 'destination.json'
        else:
            raise ValueError('unknown check mode')
        status = notify(issues, statefile, time.time())
        print(json.dumps({'issues': issues, 'notification': status}, ensure_ascii=False))
        if issues and mode != 'failure':
            raise SystemExit(1)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('BACKUP_MONITOR_ERROR ' + type(error).__name__, file=sys.stderr)
        raise SystemExit(1)
