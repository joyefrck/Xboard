import { createTrafficMeter, formatBytes } from './bandwagon-traffic.js?v=admin-e4644bda520fa7f3da82';

export function vpsState(vps) {
  if (vps.info?.suspended) return { label: '已暂停', tone: 'error' };
  if (!vps.synced_at) return { label: '待同步', tone: 'unconfigured' };
  const labels = { Running: ['运行中', 'healthy'], Stopped: ['已关机', 'paused'], Starting: ['启动中', 'processing'] };
  const [label, tone] = labels[vps.live?.ve_status] || ['状态未知', 'unconfigured'];
  return { label, tone };
}

export function previewSummary(rows) {
  return rows.reduce((counts, row) => { counts[row.status] = (counts[row.status] || 0) + 1; return counts; }, {});
}

export function syncPending(row, now = Date.now()) {
  return !!row.queued_at && now - new Date(row.queued_at).getTime() < 600000;
}

export function createBandwagonPage({ React: R, api, layout }) {
  const h = R.createElement, TrafficMeter = createTrafficMeter(R);
  const prefix = window.settings.secure_path + '/server/bandwagon';
  const autoRefreshKey = 'xboard.bandwagon.autoRefresh.' + window.settings.secure_path;
  const time = value => value ? new Date(value).toLocaleString('zh-CN') : '—';
  const resetDate = value => {
    const seconds = Number(value), date = new Date(seconds * 1000);
    return value != null && seconds > 0 && Number.isFinite(date.getTime()) ? date.toLocaleDateString('zh-CN') : '未提供';
  };
  const messageOf = e => e.response?.data?.message || e.message || '请求失败，请稍后再试';
  const button = (label, onClick, disabled = false, kind = '') => h('button', {
    type: 'button', className: 'na-button ' + kind, onClick, disabled }, label);
  const trafficOf = row => ({ status: 'matched', usage: row.usage, synced_at: row.synced_at, stale: row.stale, error: row.last_error });
  async function call(path, data = {}, get = false) {
    const result = get ? await api.get(prefix + path, { params: data, timeout: 30000 })
      : await api.post(prefix + path, data, { timeout: path === '/reset-password' ? 30000 : 60000 });
    if (result.status === 'fail') throw new Error(result.message || '请求失败');
    return result.data;
  }

  function Dialog({ title, close, busy = false, children }) {
    const ref = R.useRef(null), titleId = R.useId();
    R.useEffect(() => {
      const previous = document.activeElement, dialog = ref.current, overflow = document.body.style.overflow;
      dialog.showModal(); document.body.style.overflow = 'hidden';
      return () => { dialog.close(); document.body.style.overflow = overflow; previous?.focus(); };
    }, []);
    return h('dialog', { ref, className: 'na-dialog bw-dialog', 'aria-labelledby': titleId,
      onCancel: e => { e.preventDefault(); if (!busy) close(); },
      onClick: e => {
        const rect = ref.current.getBoundingClientRect();
        if (!busy && e.target === ref.current && (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom)) close();
      } },
      h('div', { className: 'na-section-title na-dialog-header' }, h('h3', { id: titleId }, title), button('关闭', close, busy)),
      h('div', { className: 'na-dialog-content' }, children));
  }

  function Details({ row, close }) {
    const info = row.info || {}, live = row.live || {};
    const flag = value => value === null || value === undefined ? '未提供' : value ? '是' : '否';
    const kb = value => value === null || value === undefined ? '未提供' : formatBytes(value * 1024);
    const fields = [['VEID', row.veid], ['备注', row.name || '—'], ['主机名', row.hostname || '—'],
      ['虚拟化', row.vm_type || '—'], ['IP 地址', info.ip_addresses?.join('、') || row.imported_ip || '—'],
      ['机房', info.node_location || '—'], ['物理节点', info.node_alias || '—'], ['操作系统', info.os || '—'],
      ['套餐', info.plan || '—'], ['内存配额', formatBytes(info.plan_ram)], ['Swap 配额', formatBytes(info.plan_swap)],
      ['磁盘配额', formatBytes(info.plan_disk)], ['运行状态', vpsState(row).label], ['负载', live.load_average || '未提供'],
      ['可用内存', kb(live.mem_available_kb)], ['可用 Swap / 总量', kb(live.swap_available_kb) + ' / ' + kb(live.swap_total_kb)],
      ['磁盘已用（映射空间）', formatBytes(live.ve_used_disk_space_b)], ['CPU 限速', flag(live.is_cpu_throttled)],
      ['磁盘 I/O 限速', flag(live.is_disk_throttled)], ['SSH 端口', live.ssh_port || '未提供'],
      ['基础信息更新', time(row.synced_at)], ['实时状态更新', time(row.live_synced_at)],
      ['最近同步尝试', time(row.attempted_at)], ['密码重置记录', { none: '无', pending: '请求已提交，结果待核实', completed: '上次请求成功', unknown: '上次结果未确认，请到 KiwiVM 核实' }[row.reset_state] || '无']];
    return h(Dialog, { title: 'VPS 详情 · ' + row.veid, close },
      h(TrafficMeter, { traffic: trafficOf(row) }),
      row.last_error ? h('p', { className: 'na-error', role: 'alert' }, row.last_error) : null,
      row.live_error ? h('p', { className: 'na-error', role: 'alert' }, '实时状态：' + row.live_error + '；下方保留上次数据') : null,
      h('dl', { className: 'bw-details' }, fields.map(([label, value]) => h('div', { key: label }, h('dt', { className: 'na-muted' }, label), h('dd', null, value)))),
      h('p', { className: 'na-muted' }, '实时状态为最近同步结果；接口未提供的指标显示“未提供”。'));
  }

  function EditName({ row, close, done }) {
    const [name, setName] = R.useState(row.name), [busy, setBusy] = R.useState(false), [error, setError] = R.useState('');
    return h(Dialog, { title: '修改备注 · ' + row.veid, close, busy }, h('form', { onSubmit: async e => {
      e.preventDefault(); setBusy(true); setError('');
      try { await call('/rename', { id: row.id, name }); done(); close(); }
      catch (e) { setError(messageOf(e)); } finally { setBusy(false); }
    } }, h('label', { className: 'na-field' }, h('span', null, 'VPS 备注'),
      h('input', { value: name, onChange: e => setName(e.target.value), maxLength: 80, autoFocus: true, disabled: busy })),
    error ? h('p', { role: 'alert', className: 'na-error' }, error) : null,
    h('div', { className: 'na-actions bw-dialog-actions' }, h('button', { className: 'na-button na-primary', disabled: busy }, busy ? '保存中…' : '保存备注'))));
  }

  function ResetPassword({ row, close, done }) {
    const [busy, setBusy] = R.useState(false), [error, setError] = R.useState('');
    const [result, setResult] = R.useState(null), [visible, setVisible] = R.useState(false), [copied, setCopied] = R.useState(false);
    const [submitted, setSubmitted] = R.useState(false);
    return h(Dialog, { title: '重置 root 密码 · ' + row.veid, close, busy },
      h('p', null, (row.name || row.hostname || 'VPS') + ' · ' + (row.info?.ip_addresses?.join('、') || row.imported_ip || 'IP 待同步')),
      result ? h('div', null,
        h('p', { role: 'status' }, '重置成功。请立即保存新密码，关闭后无法再次查看。'),
        h('label', { className: 'na-field' }, h('span', null, '新 root 密码'), h('input', {
          type: visible ? 'text' : 'password', value: result.password, readOnly: true, autoComplete: 'off', spellCheck: false })),
        h('div', { className: 'na-actions bw-dialog-actions' }, button(visible ? '隐藏密码' : '显示密码', () => setVisible(!visible)),
          button(copied ? '已复制' : '复制密码', async () => {
            try { await navigator.clipboard.writeText(result.password); setCopied(true); }
            catch { setError('复制失败，请显示密码后手动复制'); }
          }))) : h('form', { onSubmit: async e => {
        e.preventDefault(); if (submitted) return;
        setBusy(true); setSubmitted(true); setError('');
        try { setResult(await call('/reset-password', { id: row.id, confirm_veid: row.veid })); }
        catch (e) { setError(messageOf(e) + '。如请求已发出，请先到 KiwiVM 核实结果。'); }
        finally { setBusy(false); done(); }
      } },
      h('p', { className: 'na-note' }, '将由搬瓦工生成新的 root 密码，原密码将失效。此操作不会自动重试。'),
      ['unknown', 'pending'].includes(row.reset_state) ? h('p', { className: 'na-error' }, '上次重置结果尚未确认，请先到 KiwiVM 核实。') : null,
      h('div', { className: 'na-actions bw-dialog-actions' }, h('button', { className: 'na-button na-danger',
        disabled: busy || submitted }, busy ? '正在重置，请勿关闭…' : submitted ? '请求已提交' : '确认重置 root 密码'))),
      error ? h('p', { role: 'alert', className: 'na-error' }, error) : null);
  }

  function Page() {
    const [csv, setCsv] = R.useState(''), [preview, setPreview] = R.useState(null);
    const [result, setResult] = R.useState(null), [busy, setBusy] = R.useState(false), [error, setError] = R.useState(''), [message, setMessage] = R.useState('');
    const [loadError, setLoadError] = R.useState('');
    const [autoSync, setAutoSync] = R.useState(null), syncStatusId = R.useId();
    const [autoRefresh, setAutoRefresh] = R.useState(() => {
      try { return window.localStorage.getItem(autoRefreshKey) !== 'false'; }
      catch { return true; }
    });
    const refreshStatusId = R.useId();
    const [search, setSearch] = R.useState(''), [query, setQuery] = R.useState(''), [status, setStatus] = R.useState('all'), [page, setPage] = R.useState(1);
    const [dialog, setDialog] = R.useState(null), alive = R.useRef(false), request = R.useRef(0), filters = R.useRef({ query, status, page });
    filters.current = { query, status, page };
    const summary = previewSummary(preview || []);
    async function load() {
      const sequence = ++request.current, filter = filters.current;
      try {
        const data = await call('/fetch', { search: filter.query, status: filter.status, page: filter.page }, true);
        if (alive.current && sequence === request.current) {
          setLoadError('');
          setAutoSync(typeof data.auto_sync_enabled === 'boolean' ? data.auto_sync_enabled : null);
          if (data.current_page > data.last_page) setPage(data.last_page);
          else setResult(data);
        }
      } catch (e) { if (alive.current && sequence === request.current) setLoadError(messageOf(e)); }
    }
    R.useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);
    R.useEffect(() => { const id = setTimeout(() => { setQuery(search); setPage(1); }, 300); return () => clearTimeout(id); }, [search]);
    R.useEffect(() => { load(); }, [query, status, page]);
    R.useEffect(() => {
      try { window.localStorage.setItem(autoRefreshKey, String(autoRefresh)); } catch { /* Storage is optional. */ }
      if (!autoRefresh) return;
      const id = setInterval(() => { if (!document.hidden) load(); }, 10000);
      return () => clearInterval(id);
    }, [autoRefresh]);
    async function perform(action, data, success) {
      setBusy(true); setError(''); setMessage('');
      try {
        const value = await call(action, data);
        if (alive.current) { setMessage(typeof success === 'function' ? success(value) : success); await load(); }
        return value;
      } catch (e) { if (alive.current) setError(messageOf(e)); return null; }
      finally { if (alive.current) setBusy(false); }
    }
    const done = () => load();
    const current = dialog && result?.data.find(row => row.id === dialog.row.id) || dialog?.row;
    const content = h('div', { className: 'node-automation bandwagon-vps' },
      h('header', null, h('h2', null, '搬瓦工VPS管理'), h('p', null, '批量导入服务器，查看流量、资源与运行状态。',
        h('span', { id: syncStatusId }, autoSync === null ? '正在读取后台自动同步设置…' : autoSync ?
          '后台每 5 分钟自动同步，支持手动刷新。' : '后台自动同步已暂停，仍可手动刷新。'))),
      error || loadError ? h('div', { role: 'alert', className: 'na-alert na-error' }, error || loadError) : null,
      message ? h('div', { role: 'status', className: 'na-alert' }, message) : null,
      h('section', { className: 'na-connection bw-import', 'aria-label': '批量导入 VPS' },
        h('div', { className: 'na-section-title' }, h('h3', null, '批量导入 VPS'), h('span', { className: 'na-muted' }, '支持搬瓦工官方导出 CSV')),
        h('label', { className: 'na-field' }, h('span', { className: 'na-sr-only' }, '搬瓦工服务器 CSV'), h('textarea', {
          value: csv, onChange: e => { setCsv(e.target.value); setPreview(null); }, rows: 5, maxLength: 262144,
          disabled: busy, spellCheck: false, autoComplete: 'off', 'aria-label': '搬瓦工服务器 CSV',
          placeholder: 'VEID,VM_TYPE,HOSTNAME,PRIMARY_IP,IS_TERMINATED,IS_2FA_ENABLED,API_KEY\n请在此粘贴完整 CSV，每行一台服务器…' })),
        h('p', { className: 'na-muted bw-caption' }, '每次最多 500 条、256 KiB。已终止实例自动跳过；相同 VEID 更新密钥并保留备注。密钥加密保存。'),
        h('div', { className: 'na-actions' },
          button(busy ? '处理中…' : '识别服务器', async () => {
            const value = await perform('/preview', { csv }, '识别完成，请检查下方预览'); if (value) setPreview(value);
          }, busy || !csv.trim(), 'na-primary'),
          button('清空输入', () => { setCsv(''); setPreview(null); }, busy || !csv)),
        preview ? h('div', { className: 'bw-preview' },
          h('p', null, '可导入 ' + (summary.valid || 0) + ' · 已终止 ' + (summary.terminated || 0) + ' · 重复 ' + (summary.duplicate || 0) + ' · 格式错误 ' + (summary.invalid || 0)),
          h('div', { className: 'na-table-wrap bw-preview-table' }, h('table', null,
            h('thead', null, h('tr', null, ['行号', 'VEID', '主机名', '导出 IP', '识别结果'].map(title => h('th', { key: title }, title)))),
            h('tbody', null, preview.map(row => h('tr', { key: row.line }, h('td', null, row.line), h('td', null, row.veid || '—'),
              h('td', null, row.hostname || '—'), h('td', null, row.ip || '—'), h('td', { className: row.status === 'invalid' ? 'na-error' : '' },
                row.status === 'valid' ? row.existing ? '更新现有服务器' : '新增服务器' : row.reason)))))),
          h('div', { className: 'na-actions bw-dialog-actions' }, button('导入 ' + (summary.valid || 0) + ' 台服务器', async () => {
            const value = await perform('/import', { csv }, value => '已新增 ' + value.created + ' 台、更新 ' + value.updated + ' 台、跳过 ' + value.skipped + ' 条' +
              (value.busy ? '；' + value.busy + ' 条未完成，请稍后重新识别并导入' : value.auto_sync_enabled ?
                '，同步任务已提交' : '；后台自动同步已暂停，可手动刷新'));
            if (value && !value.busy) { setCsv(''); setPreview(null); setPage(1); }
          }, busy || !summary.valid, 'na-primary'))) : null),
      h('section', { 'aria-label': '服务器列表' },
        h('div', { className: 'na-pool-toolbar' }, h('div', null, h('h3', null, '服务器列表'),
          h('p', { className: 'na-muted', id: refreshStatusId }, '共 ' + (result?.total ?? '—') + ' 台 · ' +
            (autoRefresh ? '每 10 秒更新同步结果' : '自动刷新已暂停'))),
        h('div', { className: 'na-actions bw-toolbar' },
          h('input', { type: 'search', 'aria-label': '搜索服务器', placeholder: '搜索域名、备注、VEID、IP 或机房', value: search, maxLength: 255, onChange: e => setSearch(e.target.value) }),
          h('select', { 'aria-label': '筛选服务器状态', value: status, onChange: e => { setStatus(e.target.value); setPage(1); } },
            [['all', '全部状态'], ['running', '运行中'], ['stopped', '已关机'], ['error', '同步异常'], ['pending', '待同步']]
              .map(([value, title]) => h('option', { key: value, value }, title))),
          h('button', { type: 'button', role: 'switch', 'aria-checked': autoSync === true, 'aria-describedby': syncStatusId,
            'aria-busy': autoSync === null, className: 'na-button bw-auto-refresh', disabled: busy || autoSync === null,
            onClick: () => perform('/sync-settings', { enabled: !autoSync }, value => {
              setAutoSync(value.auto_sync_enabled);
              return value.auto_sync_enabled ? '已开启后台自动同步，将按原有每 5 分钟的调度执行' :
                '已暂停后台自动同步；已发出的请求可以完成，仍可手动刷新';
            }) }, '自动同步', h('span', { className: 'bw-switch-track', 'aria-hidden': true })),
          h('button', { type: 'button', role: 'switch', 'aria-checked': autoRefresh, 'aria-describedby': refreshStatusId,
            className: 'na-button bw-auto-refresh', onClick: () => { setAutoRefresh(!autoRefresh); if (!autoRefresh) load(); } },
            '自动刷新', h('span', { className: 'bw-switch-track', 'aria-hidden': true })),
          button('同步全部', () => perform('/refresh', {}, value => '已提交 ' + value.queued + ' 台；' + value.skipped + ' 台处理中、冷却中或未提交成功'), busy || !result))),
        h('div', { className: 'bw-list' },
          h('div', { className: 'bw-list-heading', 'aria-hidden': true }, ['IP / 关联域名', '机房 / 套餐', '本周期流量', '运行 / 同步状态', '操作'].map(label => h('span', { key: label }, label))),
          !result ? h('p', { className: 'na-empty', role: 'status' }, '正在加载服务器…') : !result.data.length ? h('p', { className: 'na-empty' }, query || status !== 'all' ? '没有符合筛选条件的服务器' : '暂无服务器，请先粘贴 CSV 并导入') : result.data.map(row => {
            const state = vpsState(row), ips = row.info?.ip_addresses || (row.imported_ip ? [row.imported_ip] : []);
            const queued = syncPending(row);
            const resetCooling = row.reset_requested_at && Date.now() - new Date(row.reset_requested_at).getTime() < 300000;
            return h('article', { className: 'bw-row', key: row.id, 'aria-label': 'VPS ' + row.veid },
              h('div', { className: 'bw-identity' },
                row.name ? h('strong', null, row.name) : null,
                ips.length ? ips.map(ip => h('div', { key: ip }, h('strong', { className: 'bw-ip' }, ip),
                  h('div', { className: 'na-muted bw-caption' }, (row.ip_domains?.[ip] || []).join('、') || '未关联域名'))) : h('span', { className: 'na-muted' }, 'IP 待同步'),
                !row.synced_at && row.imported_ip ? h('span', { className: 'na-muted bw-caption' }, '导出 IP，待接口验证') : null),
              h('div', { className: 'bw-location' }, h('span', null, row.info?.node_location || '机房待同步'), h('span', { className: 'na-muted' }, row.info?.plan || '套餐待同步'), h('span', { className: 'na-muted bw-caption' }, '流量重置：' + resetDate(row.info?.data_next_reset))),
              h(TrafficMeter, { traffic: trafficOf(row) }),
              h('div', { className: 'bw-sync' }, h('span', { className: 'na-badge na-status na-tone-' + state.tone }, state.label),
                row.queued_at ? h('span', { className: 'na-muted' }, queued ? '同步排队中' : '同步排队超时，请重试') : null,
                row.synced_at ? h('span', { className: 'na-muted bw-caption' }, '基础 ' + time(row.synced_at)) : null,
                row.live_synced_at ? h('span', { className: 'na-muted bw-caption' }, '实时 ' + time(row.live_synced_at)) : null,
                row.last_error ? h('span', { className: 'na-error bw-caption' }, row.last_error) : null,
                row.live_error ? h('span', { className: 'na-error bw-caption' }, '实时状态同步失败，保留上次数据') : null),
              h('div', { className: 'na-actions bw-row-actions' },
                button('详情', () => setDialog({ type: 'details', row })),
                button('刷新', () => perform('/refresh', { id: row.id }, value => value.queued ? '同步任务已提交' : '正在同步、请求过于频繁或队列不可用，请稍后刷新'), busy || queued),
                button('备注', () => setDialog({ type: 'name', row }), busy),
                button('重置密码', () => setDialog({ type: 'reset', row }), busy || queued || !!resetCooling),
                button('移除', () => {
                  if (window.confirm('从本面板移除 VPS ' + row.veid + ' 的管理记录和密钥？此操作不会删除搬瓦工服务器。')) perform('/drop', { id: row.id }, '已移除管理记录');
                }, busy, 'na-danger')));
          })),
        result ? h('div', { className: 'na-actions na-pagination' }, button('上一页', () => setPage(page - 1), page <= 1 || busy),
          h('span', null, result.current_page + ' / ' + result.last_page), button('下一页', () => setPage(page + 1), page >= result.last_page || busy)) : null),
      dialog?.type === 'details' ? h(Details, { row: current, close: () => setDialog(null) }) : null,
      dialog?.type === 'name' ? h(EditName, { row: current, close: () => setDialog(null), done }) : null,
      dialog?.type === 'reset' ? h(ResetPassword, { row: current, close: () => setDialog(null), done }) : null);
    return h(layout.Root, null, h(layout.Header, null,
      h('div', { className: 'hidden md:block' }, h(layout.Search)),
      h('div', { className: 'ml-auto flex items-center space-x-4' }, h(layout.Language), h(layout.User))),
    h(layout.Main, { className: 'flex flex-col' }, content));
  }
  return Page;
}
