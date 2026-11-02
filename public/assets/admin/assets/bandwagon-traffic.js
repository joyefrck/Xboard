export function formatBytes(value) {
  if (value === null || value === undefined || !Number.isFinite(Number(value)) || Number(value) < 0) return '—';
  let size = Number(value), index = 0;
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
  while (size >= 1024 && index < units.length - 1) { size /= 1024; index++; }
  return size.toLocaleString('zh-CN', { maximumFractionDigits: index ? 2 : 0 }) + ' ' + units[index];
}

export function usageTone(percent) { return percent >= 95 ? 'danger' : percent >= 80 ? 'warning' : 'normal'; }

export function createTrafficMeter(R) {
  const h = R.createElement;
  return function TrafficMeter({ traffic, compact = false }) {
    if (!traffic || traffic.status === 'unmatched') return h('span', { className: 'bw-traffic-empty na-muted' }, '未关联搬瓦工');
    if (traffic.status === 'conflict') return h('span', { className: 'bw-traffic-empty na-error' }, '搬瓦工 IP 匹配冲突');
    const usage = traffic.usage;
    const valid = usage && Number.isFinite(Number(usage.percent)) && usage.total_bytes > 0;
    const percent = valid ? Number(usage.percent) : 0;
    return h('div', { className: 'bw-traffic' + (compact ? ' bw-traffic-compact' : ''),
      title: 'VPS 整机流量，多 IP 共用额度' },
      valid ? h(R.Fragment, null,
        h('div', { className: 'bw-traffic-values' },
          h('span', null, formatBytes(usage.used_bytes) + ' / ' + formatBytes(usage.total_bytes)),
          h('strong', null, percent.toLocaleString('zh-CN', { maximumFractionDigits: 1 }) + '%')),
        h('div', { className: 'bw-traffic-track bw-traffic-' + usageTone(percent), role: 'progressbar',
          'aria-label': 'VPS 流量用量', 'aria-valuemin': 0, 'aria-valuemax': 100,
          'aria-valuenow': Math.max(0, Math.min(100, percent)),
          'aria-valuetext': formatBytes(usage.used_bytes) + ' / ' + formatBytes(usage.total_bytes) },
          h('span', { style: { width: Math.max(0, Math.min(100, percent)) + '%' } })),
        !compact ? h('span', { className: 'na-muted' }, '剩余 ' + formatBytes(usage.remaining_bytes) +
          (usage.reset_at ? ' · ' + new Date(usage.reset_at * 1000).toLocaleDateString('zh-CN') + ' 重置' : '')) : null)
        : h('span', { className: 'na-muted' }, '暂无流量数据'),
      traffic.error || traffic.stale ? h('span', { className: 'bw-traffic-note na-muted',
        title: traffic.error || '超过 15 分钟未更新' }, (traffic.error ? '同步失败' : '数据待更新') +
        (traffic.synced_at ? ' · ' + new Date(traffic.synced_at).toLocaleString('zh-CN') : '')) : null);
  };
}
