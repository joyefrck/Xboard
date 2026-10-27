// Disposable browser fixture: serves the real admin assets, fakes API data in memory.
// No production credentials, database, or Cloudflare requests.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const stamp = () => new Date().toISOString();
const connection = { id: 1, zone_name: 'example.test', zone_id: 'fixture-zone', verified_at: stamp() };
const permissionGroups = [{ id: 1, name: 'level2' }, { id: 2, name: 'level3' }];
let probeAccounts = [{ group_id: 1, id: 5, email: 'probe@example.test' }];
const groups = [{ id: 1, domain: 'us.example.test', enabled: false, state: 'paused', current_ip: '198.51.100.10', failures: 0,
  latest_check: { status: 'pass', nodes: [1,2,3].map(node_id=>({node_id,ok:true})), checked_at: stamp() },
  record_id: 'fixture-record', last_checked_at: stamp(), last_switched_at: null,
  nodes: [1, 2, 3].map(id => ({ id, display_id: id, name: '美国-高峰专线0' + id, type: 'vless', port: 1000 + id, group_ids: ['1'] })),
  backups: [{ id: 1, name: '美国备用一', ip: '198.51.100.20', priority: 1, enabled: true, state: 'ready', tested_at: stamp() },
    { id: 2, name: '美国备用二', ip: '198.51.100.30', priority: 2, enabled: true, state: 'unchecked' },
    { id: 3, name: '原 VPS', ip: '198.51.100.40', priority: 999, enabled: false, state: 'needs_review' }] },
  { id: 2, domain: 'jp.example.test', enabled: false, state: 'unconfigured', current_ip: null, failures: 0,
    nodes: [{ id: 11, display_id: 11, name: '日本-住宅IP01', type: 'hysteria', port: 1001, group_ids: ['2'] }], backups: [] },
  { id: 3, domain: 'sg.example.test', enabled: true, state: 'healthy', current_ip: '198.51.100.60', failures: 0,
    record_id: 'fixture-sg', last_checked_at: stamp(), nodes: [{ id: 12, display_id: 12, name: '新加坡-高峰专线01', type: 'vless', port: 1001, group_ids: ['1'] }],
    backups: [{ id: 4, name: '新加坡备用', ip: '198.51.100.61', priority: 1, enabled: true, state: 'ready', tested_at: stamp() }] },
  { id: 4, domain: 'hk-residential.example.test', enabled: false, state: 'config_error', current_ip: '198.51.100.70', failures: 0,
    reason: 'DNS 记录已变更，请重新验证配置并匹配 DNS。', record_id: 'fixture-hk',
    nodes: [{ id: 13, display_id: 13, name: '香港-住宅IP高峰专线01', type: 'vless', port: 1001, group_ids: ['1'] }], backups: [] },
  { id: 5, domain: 'us-residential.example.test', enabled: true, state: 'healthy', current_ip: '198.51.100.80', failures: 0,
    record_id: 'fixture-us-home', last_checked_at: stamp(),
    nodes: [{ id: 14, display_id: 14, name: '美国-家庭宽带IP01', type: 'vless', port: 1001, group_ids: ['1'] }], backups: [] },
  { id: 6, domain: 'de.example.test', enabled: true, state: 'no_backup', current_ip: '198.51.100.90', failures: 3,
    record_id: 'fixture-de', reason: '没有通过测试的可用备用，DNS 未修改',
    nodes: [{ id: 15, display_id: 15, name: '德国-高峰专线01', type: 'vless', port: 1001, group_ids: ['1'] }], backups: [] },
  { id: 7, domain: 'kr.example.test', enabled: true, state: 'propagating', current_ip: '198.51.100.100', failures: 0,
    record_id: 'fixture-kr', last_checked_at: stamp(),
    nodes: [{ id: 16, display_id: 16, name: '韩国-高峰专线01', type: 'vless', port: 1001, group_ids: ['1'] }],
    backups: [{ id: 5, name: '韩国备用', ip: '198.51.100.101', priority: 1, enabled: true, state: 'ready' }] }];
const events = Array.from({ length: 25 }, (_, i) => ({ id: i + 1, group_id: i % 2 + 1,
  action: i % 3 ? 'auto' : 'manual', status: 'completed', created_at: new Date(Date.now() - (25 - i) * 60000).toISOString(),
  from_ip: '198.51.100.10', to_ip: '198.51.100.20', message: '演示记录：备用验证通过，DNS 回读已确认' }));
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const json = value => { res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify({ status: 'success', data: value })); };
  if (url.pathname.startsWith('/api/')) {
    let body = ''; for await (const chunk of req) body += chunk;
    const data = body ? JSON.parse(body) : Object.fromEntries(url.searchParams);
    const group = groups.find(g => g.id === Number(data.group_id || data.id) || g.domain === data.domain);
    const action = url.pathname.split('/server/automation')[1];
    if (action === '/fetch') return json({ connection, groups, probe_accounts: probeAccounts, permission_groups: permissionGroups });
    if (action === '/users') return json([{ id: 5, email: 'probe@example.test', banned: false, group_id: 1 }, { id: 6, email: 'probe2@example.test', banned: false, group_id: 2 }].filter(u => u.group_id === Number(data.group_id)));
    if (action === '/connection/save') { connection.zone_name = data.zone_name; connection.verified_at = null; return json(connection); }
    if (action === '/connection/verify') { connection.verified_at = stamp(); return json(connection); }
    if (action === '/probe-user/save') {
      probeAccounts = probeAccounts.filter(a => a.group_id !== data.group_id);
      if (data.user_id) probeAccounts.push({ group_id: data.group_id, id: data.user_id, email: data.user_id === 5 ? 'probe@example.test' : 'probe2@example.test' });
      for (const g of groups) { g.backups.forEach(b => { if (!['active', 'needs_review'].includes(b.state)) b.state = 'unchecked'; }); }
      return json(probeAccounts);
    }
    if (action === '/save') { group.record_id = 'fixture-record-' + group.id; group.state = 'paused'; group.current_ip ||= '198.51.100.50'; return json(group); }
    if (action === '/match') return json({ id: 'fixture-record', content: group.current_ip });
    if (action === '/pause') { group.enabled = false; group.state = 'paused'; return json(group); }
    if (action === '/backup/save') {
      const old = group.backups.find(b => b.id === Number(data.id));
      if (old) Object.assign(old, data, { state: 'unchecked' });
      else group.backups.push({ ...data, id: 10 + group.backups.length, state: 'unchecked' });
      group.backups.sort((a, b) => a.priority - b.priority); return json(true);
    }
    if (action === '/backup/drop') { group.backups = group.backups.filter(b => b.id !== Number(data.id)); return json(true); }
    if (action === '/operation') {
      const event = { id: events.length + 1, group_id: group.id, action: data.action, status: 'queued', created_at: stamp(), message: null };
      events.push(event);
      setTimeout(() => {
        event.status = 'completed'; event.message = '测试环境验证通过';
        if (data.action === 'enable') { group.enabled = true; group.state = 'healthy'; }
        if (data.action === 'test') { const b = group.backups.find(b => b.id === data.backup_id); b.state = 'ready'; b.tested_at = stamp(); }
        if (data.action === 'manual') { const b = group.backups.find(b => b.id === data.backup_id); event.from_ip = group.current_ip; event.to_ip = b.ip; group.current_ip = b.ip; b.state = 'active'; group.state = 'propagating'; group.last_switched_at = stamp(); }
      }, 800); return json(event);
    }
    if (action === '/logs') {
      const list = events.filter(e => !data.id || e.group_id === Number(data.id)).reverse();
      const page = Number(data.page) || 1;
      return json({ data: list.slice((page - 1) * 20, page * 20).map(e => ({ ...e, domain: groups.find(g => g.id === e.group_id)?.domain })),
        total: list.length, current_page: page, last_page: Math.max(1, Math.ceil(list.length / 20)) });
    }
    if (url.pathname.endsWith('/user/info')) return json({ id: 1, email: 'preview@example.test', is_admin: true, name: '预览管理员', avatar_url: '' });
    return json({});
  }
  if (url.pathname.startsWith('/assets/admin/')) {
    const file = path.resolve(root, 'public' + decodeURIComponent(url.pathname));
    if (!file.startsWith(path.join(root, 'public/assets/admin/') ) || !fs.existsSync(file)) { res.writeHead(404); return res.end(); }
    const type = { '.js': 'text/javascript', '.css': 'text/css', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream';
    res.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' }); return fs.createReadStream(file).pipe(res);
  }
  let html = fs.readFileSync(path.join(root, 'resources/views/admin.blade.php'), 'utf8')
    .replaceAll('{{ $title }}', '大象网络 · 自动化预览').replaceAll('{{ $version }}', 'automation-preview')
    .replaceAll('{{ $logo }}', '').replaceAll('{{ $secure_path }}', 'preview');
  html = html.replace('<div id="root"></div>', '<script>localStorage.setItem("XBOARD_ACCESS_TOKEN", JSON.stringify({value:"fixture-only",time:Date.now(),expire:null}));localStorage.setItem("i18nextLng","zh-CN");</script><div id="root"></div>');
  res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }); res.end(html);
});
server.listen(7315, '127.0.0.1', () => console.log('Disposable admin preview: http://127.0.0.1:7315/preview#/server/automation'));
