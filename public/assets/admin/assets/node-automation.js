// Readable source module for the compiled admin shell; no separate React runtime.
export const stateLabels = {
  unconfigured: '未配置', paused: '已暂停', healthy: '正常', suspect: '疑似故障',
  propagating: '等待 DNS 生效', switching: '切换中', no_backup: '无可用备用',
  probe_error: '检测异常', config_error: '配置异常', dns_conflict: 'DNS 冲突', dns_unknown: '等待 DNS 回读',
  unchecked: '未测试', ready: '测试通过', failed: '失败', needs_review: '待检查', active: '当前 VPS',
  queued: '等待执行', running: '执行中', prepared: 'DNS 修改待确认', verifying: '验证新 VPS',
  completed: '完成', cancelled: '已取消',
};

export const statusFilters = [
  { key: 'healthy', label: '正常' },
  { key: 'healthy_no_backup', label: '正常 · 无备用' },
  { key: 'error', label: '异常' },
  { key: 'paused', label: '已暂停' },
  { key: 'unconfigured', label: '未配置' },
  { key: 'processing', label: '处理中' },
  { key: 'other', label: '其他' },
];

// Keep the same enabled-standby count used by the list; this is not a test result.
export const standbyCount = group => (group.backups || []).filter(b => b.enabled && b.ip !== group.current_ip).length;

export function groupStatus(group) {
  let key;
  if (group.state === 'healthy') key = standbyCount(group) > 0 ? 'healthy' : 'healthy_no_backup';
  else if (['suspect', 'no_backup', 'probe_error', 'config_error', 'dns_conflict'].includes(group.state)) key = 'error';
  else if (['switching', 'propagating', 'dns_unknown'].includes(group.state)) key = 'processing';
  else if (['paused', 'unconfigured'].includes(group.state)) key = group.state;
  else key = 'other';
  return { key, label: key === 'healthy_no_backup' ? '正常 · 无备用' : (stateLabels[group.state] || group.state || '未知状态') };
}

export function filterGroups(groups, search = '', statuses = []) {
  const query = search.trim().toLowerCase();
  return groups.filter(group =>
    (group.domain + ' ' + group.nodes.map(n => (n.display_id ?? n.id) + ' ' + n.name).join(' ')).toLowerCase().includes(query)
    && (!statuses.length || statuses.includes(groupStatus(group).key)));
}

export function createAutomationPage({ React: R, api, layout }) {
  const h = R.createElement;
  const prefix = window.settings.secure_path + '/server/automation';
  const time = value => value ? new Date(value).toLocaleString() : '—';
  const label = value => stateLabels[value] || value;
  async function call(path, data, method = 'post') {
    const result = method === 'get'
      ? await api.get(prefix + path, { params: data, timeout: 30000 })
      : await api.post(prefix + path, data, { timeout: 30000 });
    if (result.status === 'fail') throw new Error(result.message || '请求失败');
    return result.data;
  }
  const field = (title, props) => h('label', { className: 'na-field' }, h('span', null, title), h('input', props));
  const button = (text, onClick, disabled, kind = '') => h('button', { type: 'button', onClick, disabled,
    className: 'na-button ' + kind }, text);
  const nodeIds = group => group.nodes.map(n => n.display_id ?? n.id).join('、');

  function BackupBinding({ group }) {
    return h('div', { className: 'na-binding' },
      h('div', null, h('span', { className: 'na-muted' }, '所属域名（来自节点管理）'), h('strong', null, group.domain)),
      h('div', null, h('span', { className: 'na-muted' }, '备用 VPS 必须部署的节点'),
        h('ul', null, group.nodes.map(n => h('li', { key: n.id }, h('strong', null, 'NodeID ' + (n.display_id ?? n.id)),
          ' · ' + n.name + ' · ' + n.type + ' / 端口 ' + n.port)))),
      h('p', { className: 'na-muted' }, '这台备用关联以上全部节点，沿用原 NodeID；切换时只更新这个域名的 IP。'));
  }

  function UserPicker({ group, value, selected, onChange, disabled }) {
    const [search, setSearch] = R.useState('');
    const [users, setUsers] = R.useState([]);
    const [error, setError] = R.useState('');
    const [busy, setBusy] = R.useState(false);
    async function find() {
      setBusy(true); setError('');
      try { setUsers(await call('/users', { search, group_id: group.id }, 'get')); }
      catch (e) { setError(e.message || '搜索失败'); }
      finally { setBusy(false); }
    }
    return h('div', { className: 'na-user' },
      h('div', { className: 'na-actions' },
        h('input', { 'aria-label': group.name + ' 公共检测账号搜索', placeholder: '邮箱或用户 ID', value: search,
          onChange: e => setSearch(e.target.value), disabled }),
        button(busy ? '搜索中…' : '搜索账号', find, disabled || busy || search.trim().length < 1),
        h('select', { 'aria-label': group.name + ' 公共检测账号', value: value || '', disabled,
          onChange: e => onChange(e.target.value ? Number(e.target.value) : null) },
          h('option', { value: '' }, '选择公共检测账号'),
          value && !users.some(u => u.id === Number(value)) ? h('option', { value }, '#' + value + (selected?.email && selected.id === Number(value) ? ' ' + selected.email : '')) : null,
          users.map(u => h('option', { key: u.id, value: u.id, disabled: !!u.banned }, '#' + u.id + ' ' + u.email + (u.banned ? '（已封禁）' : ''))))),
      error ? h('p', { role: 'alert', className: 'na-error' }, error) : null);
  }

  function GroupAccountRow({ group, selected, perform, disabled }) {
    const [value, setValue] = R.useState(selected?.id || null);
    R.useEffect(() => { setValue(selected?.id || null); }, [selected?.id]);
    return h('div', { className: 'na-account-row' },
      h('h4', null, group.name + ' · #' + group.id),
      selected ? h('p', { className: 'na-muted' }, '已保存：#' + selected.id + (selected.email ? ' ' + selected.email : '（账号已删除）')) : null,
      h(UserPicker, { group, value, selected, onChange: setValue, disabled }),
      h('div', { className: 'na-actions' },
        button('保存 ' + group.name + ' 检测账号', () => perform('/probe-user/save', { group_id: group.id, user_id: value }), !value || disabled, 'na-primary'),
        button('清除 ' + group.name + ' 检测账号', () => perform('/probe-user/save', { group_id: group.id, user_id: null }), !selected || disabled)));
  }

  function BackupForm({ group, initial, perform, cancel }) {
    const formRef = R.useRef(null);
    R.useEffect(() => { formRef.current?.scrollIntoView({ block: 'start' }); }, []);
    const [data, setData] = R.useState(initial || { name: '', ip: '', priority: (group.backups?.length || 0) + 1, enabled: true });
    const [rearm, setRearm] = R.useState(false);
    const [saving, setSaving] = R.useState(false);
    const set = key => e => setData({ ...data, [key]: e.target.value });
    async function save(e) {
      e.preventDefault(); setSaving(true);
      const ok = await perform('/backup/save', { group_id: group.id, id: data.id, name: data.name,
        ip: data.ip, priority: Number(data.priority), enabled: data.enabled, rearm });
      setSaving(false); if (ok) cancel();
    }
    return h('form', { className: 'na-backup-form', onSubmit: save, ref: formRef },
      h('h3', null, (initial ? '编辑备用：' : '新增备用：') + group.domain),
      h(BackupBinding, { group }),
      h('div', { className: 'na-grid' },
        field('VPS 名称', { value: data.name, onChange: set('name'), required: true, maxLength: 80 }),
        field('公网 IPv4', { value: data.ip, onChange: set('ip'), required: true, placeholder: '填写备用 VPS 的公网 IPv4' }),
        field('优先顺序（越小越优先）', { type: 'number', min: 0, max: 999, value: data.priority, onChange: set('priority'), required: true })),
      h('label', { className: 'na-check' }, h('input', { type: 'checkbox', checked: data.enabled,
        onChange: e => setData({ ...data, enabled: e.target.checked }) }), '启用此备用'),
      initial?.state === 'needs_review' ? h('label', { className: 'na-check' }, h('input', { type: 'checkbox', checked: rearm,
        onChange: e => setRearm(e.target.checked) }), '我已检查原 VPS，并将它重新加入备用池') : null,
      h('div', { className: 'na-actions' }, h('button', { type: 'submit', className: 'na-button na-primary', disabled: saving }, saving ? '保存中…' : '保存备用'), button('取消', cancel, saving)));
  }

  const stateBadge = group => {
    const status = groupStatus(group);
    return h('span', { className: 'na-badge na-status na-tone-' + status.key }, status.label);
  };

  function GroupRow({ group, accountsReady, perform, openLogs, openIp, queue, busy, requestBusy, pending }) {
    const mayPause = !!group.enabled || !!group.pending_operation || pending;
    const locked = !!group.enabled || !!group.pending_operation || busy;
    return h('article', { className: 'na-group', 'aria-label': group.domain },
      h('div', { className: 'na-domain' }, h('strong', null, group.domain),
        h('ul', { className: 'na-node-list na-muted' }, group.nodes.map(n => h('li', { key: n.id }, '#' + (n.display_id ?? n.id) + ' · ' + n.name)))),
      h('div', { className: 'na-current-ip' }, h('span', { className: 'na-mobile-label na-muted' }, '当前 IP'),
        h('span', { className: group.current_ip ? 'na-ip' : 'na-muted' }, group.current_ip || '未匹配 DNS')),
      h('div', { className: 'na-group-status' }, stateBadge(group),
        h('span', { className: 'na-muted' }, '失败 ' + group.failures + '/3 · 备用 ' + standbyCount(group))),
      h('div', { className: 'na-actions na-row-actions', 'aria-label': group.domain + ' 操作' },
        button(mayPause ? '暂停自动化' : '验证并开启', () => mayPause ? perform('/pause', { id: group.id }) : queue(group, 'enable'), !group.id || (!mayPause && (!group.record_id || !accountsReady)) || (mayPause ? requestBusy : busy), mayPause ? '' : 'na-primary'),
        button(group.record_id ? '重新验证配置并匹配 DNS' : '验证配置并匹配 DNS', () => perform('/save', { domain: group.domain }), locked || !accountsReady),
        button('切换记录', () => openLogs(group), !group.id || requestBusy),
        button('IP 管理', () => openIp(group))));
  }

  function GroupDetails({ group, perform, queue, busy, error, message, operation }) {
    const [editing, setEditing] = R.useState(null);
    const locked = !!group.enabled || !!group.pending_operation || busy;
    return h('div', { className: 'na-group-body' },
        error ? h('p', { className: 'na-alert na-error', role: 'alert' }, error) : null,
        message ? h('p', { className: 'na-alert', role: 'status' }, message) : null,
        group.reason ? h('p', { className: 'na-alert na-error', role: 'status' }, group.reason) : null,
        group.pending_operation || operation ? h('p', { className: 'na-note', role: 'status' },
          '后台操作：' + label((group.pending_operation || operation).status) + ' · ' + ((group.pending_operation || operation).message || '等待后台验证')) : null,
        h('dl', { className: 'na-overview' },
          [['当前 IP', h('span', { className: 'na-ip' }, group.current_ip || '未匹配 DNS')], ['自动化状态', stateBadge(group)],
            ['最近检测', time(group.last_checked_at)], ['最近切换', time(group.last_switched_at)]].map(([title, value]) =>
            h('div', { key: title }, h('dt', { className: 'na-muted' }, title), h('dd', null, value)))),
        h('div', { className: 'na-detail-nodes na-muted' }, '关联节点：' + group.nodes.map(n => '#' + (n.display_id ?? n.id) + ' ' + n.name).join(' / ')),
        group.latest_check?.nodes ? h('p', { className: 'na-muted' }, '节点实测：' + group.latest_check.nodes.map(result =>
          (group.nodes.find(n => n.id === result.node_id)?.name || '节点 #' + result.node_id) + ' ' + (result.ok ? '通过' : '不可用')).join(' / ')) : null,
        group.nodes.length > 1 ? h('p', { className: 'na-note' }, '此域名共用 DNS。切换会影响以上 ' + group.nodes.length + ' 个节点，备用必须支持全部端口和协议。') : null,
        h('p', { className: 'na-muted' }, '本域名各节点按权限组使用「固定配置」中的对应检测账号。备用 V2bX 沿用原 NodeID、端口、协议和密钥。'),
        h('div', { className: 'na-section-title' }, h('h3', null, '备用 VPS'), button('为此域名新增备用', () => setEditing({}), !group.id || locked, 'na-primary')),
        h('p', { className: 'na-muted' }, '以下备用均关联 ' + group.domain + '，共同接替 NodeID ' + nodeIds(group) + '。请在备用 V2bX 上部署这些相同的 NodeID、端口和协议。'),
        !group.backups?.length ? h('p', { className: 'na-empty' }, '尚未配置备用。先保存「固定配置」中的公共检测账号并匹配本域名 DNS，再添加已部署 V2bX 的 VPS。') :
          h('div', { className: 'na-table-wrap' }, h('table', null,
            h('thead', null, h('tr', null, ['顺序', '备用 VPS / IP', '关联域名 / NodeID', '状态', '最近测试', '操作'].map(t => h('th', { key: t }, t)))),
            h('tbody', null, group.backups.map(b => h('tr', { key: b.id },
              h('td', null, b.priority), h('td', null, h('strong', null, b.name), h('div', { className: 'na-muted' }, b.ip)),
              h('td', { className: 'na-binding-cell' }, h('strong', null, group.domain), h('div', { className: 'na-muted' }, 'NodeID ' + nodeIds(group))),
              h('td', null, label(b.state), !b.enabled ? h('div', { className: 'na-muted' }, '已禁用') : null, b.reason ? h('div', { className: 'na-error' }, b.reason) : null),
              h('td', null, time(b.tested_at)), h('td', null, h('div', { className: 'na-actions' },
                button('测试备用', () => queue(group, 'test', b), busy || b.ip === group.current_ip),
                button('手动切换', () => queue(group, 'manual', b), busy || !b.enabled || ['active', 'needs_review'].includes(b.state)),
                button(b.state === 'needs_review' ? '检查并加入' : '编辑', () => setEditing(b), locked || b.ip === group.current_ip),
                button('删除', () => { if (window.confirm('删除备用「' + b.name + '」？')) perform('/backup/drop', { group_id: group.id, id: b.id }); }, locked || b.ip === group.current_ip, 'na-danger')))))))),
        editing ? h(BackupForm, { key: editing.id || 'new', group, initial: editing.id ? editing : null, perform, cancel: () => setEditing(null) }) : null);
  }

  function LogList({ group = null, active = true }) {
    const [page, setPage] = R.useState(1);
    const [result, setResult] = R.useState(null);
    const [busy, setBusy] = R.useState(false);
    const [error, setError] = R.useState('');
    const [refresh, setRefresh] = R.useState(0);
    R.useEffect(() => {
      if (!active) return;
      let cancelled = false;
      setBusy(true); setError('');
      call('/logs', { ...(group ? { id: group.id } : {}), page }, 'get')
        .then(value => { if (!cancelled) setResult(value); })
        .catch(e => { if (!cancelled) setError(e.message || '日志读取失败'); })
        .finally(() => { if (!cancelled) setBusy(false); });
      return () => { cancelled = true; };
    }, [group?.id, page, active, refresh]);
    return h('div', { className: 'na-log-list', 'aria-busy': busy },
      h('div', { className: 'na-section-title' }, h('span', { className: 'na-muted' }, result ? '共 ' + result.total + ' 条记录' : '读取记录'),
        button('刷新记录', () => setRefresh(refresh + 1), busy)),
      error ? h('p', { role: 'alert', className: 'na-error' }, error) : null,
      busy ? h('p', { role: 'status', className: 'na-muted' }, '正在读取记录…') : null,
      !busy && !error && result && !result.data.length ? h('p', { className: 'na-empty' }, '暂无操作记录') : null,
      result?.data.length ? h('div', { className: 'na-table-wrap' }, h('table', null,
        h('thead', null, h('tr', null, ['时间', ...(group ? [] : ['域名']), '操作', '状态', 'IP 变化', '结果'].map(t => h('th', { key: t }, t)))),
        h('tbody', null, result.data.map(e => h('tr', { key: e.id },
          h('td', null, time(e.created_at)), group ? null : h('td', null, e.domain || '已移除域名 #' + e.group_id),
          h('td', null, { enable: '开启', test: '测试', manual: '手动切换', auto: '自动切换' }[e.action] || e.action),
          h('td', null, label(e.status)), h('td', null, (e.from_ip || '—') + ' → ' + (e.to_ip || '—')),
          h('td', null, e.message || '—')))))) : null,
      result ? h('div', { className: 'na-actions na-pagination' },
        button('上一页', () => setPage(page - 1), busy || page <= 1),
        h('span', null, result.current_page + ' / ' + result.last_page),
        button('下一页', () => setPage(page + 1), busy || page >= result.last_page)) : null);
  }

  function Dialog({ title, close, children, closeLabel = '关闭' }) {
    const ref = R.useRef(null);
    const titleId = R.useId();
    R.useEffect(() => {
      const dialog = ref.current;
      const previous = document.activeElement;
      dialog.showModal();
      const oldOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      return () => { dialog.close(); document.body.style.overflow = oldOverflow; previous?.focus(); };
    }, []);
    return h('dialog', { ref, className: 'na-dialog', 'aria-labelledby': titleId,
      onCancel: e => { e.preventDefault(); close(); },
      onKeyDown: e => {
        if (e.key !== 'Tab') return;
        const controls = [...ref.current.querySelectorAll('button, input, select, textarea, a[href], [tabindex]')]
          .filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
      },
      onClick: e => {
        const rect = ref.current.getBoundingClientRect();
        if (e.target === ref.current && (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom)) close();
      } },
      h('div', { className: 'na-section-title na-dialog-header' }, h('h3', { id: titleId }, title),
        button(closeLabel, close)),
      h('div', { className: 'na-dialog-content' }, children));
  }

  function Page() {
    const [data, setData] = R.useState({ groups: [], connection: null, probe_accounts: [], permission_groups: [] });
    const [zone, setZone] = R.useState('443ds443.com');
    const [token, setToken] = R.useState('');
    const [busy, setBusy] = R.useState(false);
    const [message, setMessage] = R.useState('');
    const [error, setError] = R.useState('');
    const [search, setSearch] = R.useState('');
    const [statuses, setStatuses] = R.useState([]);
    const [operation, setOperation] = R.useState(null);
    const [logs, setLogs] = R.useState(null);
    const [ipDomain, setIpDomain] = R.useState(null);
    const [loaded, setLoaded] = R.useState(false);
    const [refreshing, setRefreshing] = R.useState(false);
    const [tab, setTab] = R.useState('pool');
    const tabs = [{ id: 'pool', name: '备用池' }, { id: 'settings', name: '固定配置' }, { id: 'logs', name: '全部切换记录' }];
    const live = R.useRef(true);
    const zoneInitialized = R.useRef(false);
    async function load() {
      try {
        const value = await call('/fetch', {}, 'get');
        if (live.current) {
          setData(value);
          if (!zoneInitialized.current) { setZone(value.connection?.zone_name || '443ds443.com'); zoneInitialized.current = true; }
        }
      } catch (e) { if (live.current) setError(e.message || '加载失败'); }
      finally { if (live.current) setLoaded(true); }
    }
    R.useEffect(() => {
      live.current = true; load();
      const timer = setInterval(load, 10000);
      return () => { live.current = false; clearInterval(timer); };
    }, []);
    async function perform(path, payload) {
      setBusy(true); setError(''); setMessage('');
      try { await call(path, payload); setMessage('操作成功'); await load(); return true; }
      catch (e) { setError(e.message || '操作失败'); return false; }
      finally { setBusy(false); }
    }
    async function queue(group, action, backup) {
      if (action === 'manual' && !window.confirm('将 ' + group.domain + ' 切换到 ' + backup.ip + '？影响：' + group.nodes.map(n => n.name).join('、') + '。备用测试合格后才修改 DNS。')) return;
      setBusy(true); setError(''); setMessage('');
      try {
        const event = await call('/operation', { id: group.id, action, backup_id: backup?.id, request_id: crypto.randomUUID() });
        setOperation({ group, event }); setMessage('任务已提交，等待后台验证。');
      } catch (e) { setError(e.message || '提交失败'); }
      finally { setBusy(false); }
    }
    R.useEffect(() => {
      if (!operation || ['completed', 'failed', 'cancelled'].includes(operation.event.status)) return;
      let cancelled = false;
      const timer = setInterval(async () => {
        try {
          const page = await call('/logs', { id: operation.group.id }, 'get');
          const event = page.data.find(e => e.id === operation.event.id);
          if (!cancelled && event) {
            setOperation({ ...operation, event });
            if (['completed', 'failed', 'cancelled'].includes(event.status)) { clearInterval(timer); await load(); }
          }
        } catch (e) { if (!cancelled) setError(e.message || '任务状态读取失败'); }
      }, 3000);
      return () => { cancelled = true; clearInterval(timer); };
    }, [operation?.event.id, operation?.event.status]);
    const openLogs = group => setLogs(group);
    const openIp = group => { setError(''); setMessage(''); setIpDomain(group.domain); };
    const running = operation && !['completed', 'failed', 'cancelled'].includes(operation.event.status);
    const ipGroup = data.groups.find(g => g.domain === ipDomain);
    const searchedGroups = filterGroups(data.groups, search);
    const visibleGroups = filterGroups(searchedGroups, '', statuses);
    const statusCounts = searchedGroups.reduce((counts, group) => {
      const key = groupStatus(group).key;
      counts[key] = (counts[key] || 0) + 1;
      return counts;
    }, {});
    const toggleStatus = key => setStatuses(current => current.includes(key) ? current.filter(item => item !== key) : [...current, key]);
    const content = h('div', { className: 'node-automation' },
      h('header', null, h('h2', null, '自动化配置'), h('p', null, '检测节点持续不可用，验证备用后自动切换 DNS。每 60 秒检测，连续 3 轮失败触发，切换后冷却 10 分钟。')),
      error ? h('div', { role: 'alert', className: 'na-alert na-error' }, error) : null,
      message ? h('div', { role: 'status', className: 'na-alert' }, message) : null,
      operation ? h('div', { role: 'status', className: 'na-alert' }, operation.group.domain + '：' + label(operation.event.status) + ' · ' + (operation.event.message || '后台任务执行中，页面每 3 秒更新')) : null,
      h('div', { className: 'na-tabs', role: 'tablist', 'aria-label': '自动化配置页面' }, tabs.map((item, index) =>
        h('button', { key: item.id, id: 'na-tab-' + item.id, type: 'button', role: 'tab',
          'aria-selected': tab === item.id, 'aria-controls': 'na-panel-' + item.id, tabIndex: tab === item.id ? 0 : -1,
          onClick: () => setTab(item.id), onKeyDown: e => {
            let next;
            if (e.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (e.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (e.key === 'Home') next = 0;
            if (e.key === 'End') next = tabs.length - 1;
            if (next !== undefined) { e.preventDefault(); setTab(tabs[next].id); document.getElementById('na-tab-' + tabs[next].id)?.focus(); }
          } }, item.name))),
      h('div', { id: 'na-panel-pool', role: 'tabpanel', 'aria-labelledby': 'na-tab-pool', className: 'na-tab-panel', hidden: tab !== 'pool' },
        !data.connection?.verified_at || !data.probe_accounts?.length ? h('p', { className: 'na-note' }, '请先在「固定配置」中验证 Cloudflare 连接并选择公共检测账号。',
          button('前往固定配置', () => setTab('settings'))) : null,
      h('div', { className: 'na-pool-toolbar' },
        h('div', null, h('h3', null, '节点与备用池'), h('p', { className: 'na-muted' }, '按节点管理顺序排列 · 同域名合并显示')),
        h('div', { className: 'na-actions' },
          h('input', { type: 'search', 'aria-label': '筛选域名或节点', placeholder: '搜索域名、节点名称或 NodeID', value: search, onChange: e => setSearch(e.target.value) }),
          button(refreshing ? '刷新中…' : '刷新', async () => { setRefreshing(true); await load(); setRefreshing(false); }, busy || refreshing))),
      h('div', { className: 'na-status-filters', role: 'group', 'aria-label': '按状态筛选' },
        h('button', { type: 'button', className: 'na-filter na-filter-all', 'aria-pressed': statuses.length === 0,
          onClick: () => setStatuses([]) }, '全部', h('span', { className: 'na-filter-count' }, loaded ? searchedGroups.length : '—')),
        statusFilters.filter(item => item.key !== 'other' || statusCounts.other || statuses.includes('other')).map(item =>
          h('button', { key: item.key, type: 'button', className: 'na-filter na-tone-' + item.key,
            'aria-pressed': statuses.includes(item.key), onClick: () => toggleStatus(item.key) },
            item.label, h('span', { className: 'na-filter-count' }, loaded ? (statusCounts[item.key] || 0) : '—')))),
      h('div', { className: 'na-pool' },
        h('div', { className: 'na-pool-heading', 'aria-hidden': true },
          h('span', null, '域名 / 关联节点'), h('span', null, '当前 IP'), h('span', null, '状态 / 备用'), h('span', null, '操作')),
        !loaded ? h('p', { className: 'na-empty', role: 'status' }, '正在加载节点与备用池…') :
        !data.groups.length ? h('p', { className: 'na-empty' }, '暂无使用域名的节点。IP 地址节点不支持 DNS 自动切换。') :
        !visibleGroups.length ? h('div', { className: 'na-empty' }, h('p', null, '没有符合当前筛选条件的域名或节点'), button('清除筛选', () => { setSearch(''); setStatuses([]); })) :
          visibleGroups.map(g => h(GroupRow, { key: g.domain, group: g, accountsReady: g.nodes.every(n => data.probe_accounts?.some(a => n.group_ids?.map(Number).includes(a.group_id))), perform, queue, openLogs, openIp, busy: busy || running,
            requestBusy: busy, pending: running && operation.group.id === g.id }))),
      loaded ? h('p', { className: 'na-pool-footer na-muted' }, '显示 ' + visibleGroups.length + ' / ' + data.groups.length + ' 个域名 · 每 10 秒更新状态') : null),
      h('div', { id: 'na-panel-settings', role: 'tabpanel', 'aria-labelledby': 'na-tab-settings', className: 'na-tab-panel', hidden: tab !== 'settings' },
      h('section', { className: 'na-connection', 'aria-label': 'Cloudflare 连接' },
        h('div', { className: 'na-section-title' }, h('h3', null, 'Cloudflare 连接'), h('span', { className: 'na-badge' }, data.connection?.verified_at ? '连接已验证' : data.connection ? 'Token 已配置，待验证' : '尚未配置')),
        h('form', { onSubmit: async e => { e.preventDefault(); if (await perform('/connection/save', { zone_name: zone, api_token: token || undefined })) setToken(''); } },
          h('div', { className: 'na-grid' }, field('Zone 域名', { value: zone, onChange: e => setZone(e.target.value), required: true }),
            field('API Token', { type: 'password', value: token, onChange: e => setToken(e.target.value), autoComplete: 'new-password',
              placeholder: data.connection ? '已配置；留空保留现有 Token' : '输入限定域名的 API Token' })),
          h('p', { className: 'na-muted' }, 'Token 仅授予指定 Zone 的 DNS 编辑和 Zone 读取权限。开启策略会将受管 A 记录的 TTL 设为 60 秒；客户端 DNS 缓存更新仍需时间。'),
          h('div', { className: 'na-actions' }, h('button', { type: 'submit', className: 'na-button na-primary', disabled: busy || running || data.groups.some(g => g.enabled) }, '保存连接'),
            button('验证连接', () => perform('/connection/verify', {}), !data.connection || busy || running),
            h('a', { href: 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/', target: '_blank', rel: 'noreferrer' }, 'Token 配置说明')))),
      h('section', { className: 'na-connection', 'aria-label': '公共检测账号设置' },
        h('div', { className: 'na-section-title' }, h('h3', null, '公共检测账号'),
          h('span', { className: 'na-badge' }, data.probe_accounts?.length ? '已配置 ' + data.probe_accounts.length + ' 个权限组' : '尚未配置')),
        h('p', { className: 'na-muted' }, '每个权限组分别选择一个 Xboard 订阅用户，例如 level2、level3 各配置一个。检测时按节点权限组选择账号；账号须属于该组、有效且有可用流量，检测会消耗少量流量。'),
        data.permission_groups?.length ? data.permission_groups.map(group => h(GroupAccountRow, { key: group.id, group,
          selected: data.probe_accounts?.find(a => a.group_id === group.id), perform,
          disabled: busy || running || data.groups.some(g => g.enabled || g.pending_operation) })) :
          h('p', { className: 'na-empty' }, '请先在「权限组管理」创建权限组。'),
        h('p', { className: 'na-muted' }, '更换或清除账号前请先暂停所有自动化，并等待任务完成；保存后逐域名重新验证配置与备用。多权限组节点优先使用权限组 ID 较小的可用账号。'))),
      h('div', { id: 'na-panel-logs', role: 'tabpanel', 'aria-labelledby': 'na-tab-logs', className: 'na-tab-panel', hidden: tab !== 'logs' },
        h('section', { className: 'na-log', 'aria-label': '全部切换记录' }, h(LogList, { active: tab === 'logs' }))),
      logs ? h(Dialog, { key: 'logs-' + logs.id, title: logs.domain + ' · 切换记录', close: () => setLogs(null), closeLabel: '关闭记录' }, h(LogList, { group: logs })) : null,
      ipDomain ? h(Dialog, { key: 'ip-' + ipDomain, title: ipDomain + ' · IP 管理', close: () => setIpDomain(null), closeLabel: '关闭 IP 管理' },
        ipGroup ? h(GroupDetails, { group: ipGroup, perform, queue, busy: busy || running, error, message,
          operation: operation?.group.domain === ipDomain ? operation.event : null }) : h('p', { className: 'na-empty' }, '该域名已不在节点管理中，请关闭后刷新列表。')) : null);
    return h(layout.Root, null, h(layout.Header, null,
      h('div', { className: 'hidden md:block' }, h(layout.Search)),
      h('div', { className: 'ml-auto flex items-center space-x-4' }, h(layout.Language), h(layout.User))),
      h(layout.Main, { className: 'flex flex-col' }, content));
  }
  return Page;
}
