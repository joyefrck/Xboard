// Disposable fixture for visual/interaction QA; serves real theme assets with in-memory APIs.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
let deleted = false;
let loggedOut = false;
let passwordChanged = false;
const initialSessions = () => [
  { id: '101', is_current: true, client_label: 'Chrome', client_type: 'browser', client_os: 'macOS', login_ip: '203.0.113.10', last_ip: '203.0.113.10', created_at: '2026-11-10T23:30:00Z', last_used_at: '2026-11-11T00:15:00Z' },
  { id: '102', is_current: false, client_label: 'App 客户端', client_type: 'app', client_os: 'Android', login_ip: '2001:db8::12', last_ip: '2001:db8::12', created_at: '2026-11-10T12:30:00Z', last_used_at: '2026-11-11T00:10:00Z' },
  { id: '103', is_current: false, client_label: '历史会话／设备信息未知', client_type: 'unknown', created_at: '2026-11-08T12:30:00Z', last_used_at: null }
];
let sessions = initialSessions();
const user = { id: 10, email: 'preview@example.test', balance: 12800, commission_balance: 0,
  remind_expire: true, remind_traffic: true, telegram_id: 123456789, u: 0, d: 0,
  transfer_enable: 0, created_at: 1700000000, expired_at: 0, plan_id: null, uuid: 'fixture-uuid' };
const requests = [];
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const json = (data, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(data)); };
  if (url.pathname === '/fixture-state') return json({ deleted, loggedOut, passwordChanged, sessions, requests });
  if (url.pathname === '/fixture-reset') { deleted = loggedOut = passwordChanged = false; sessions = initialSessions(); requests.length = 0; return json(true); }
  if (url.pathname.startsWith('/api/')) {
    let raw = ''; for await (const chunk of req) raw += chunk;
    const body = raw ? (req.headers['content-type']?.includes('application/json') ? JSON.parse(raw) : Object.fromEntries(new URLSearchParams(raw))) : {};
    requests.push({ method: req.method, path: url.pathname, body: Object.fromEntries(Object.entries(body).map(([key, value]) => [key, /password|email_code/i.test(key) ? '[fixture]' : value])) });
    if (loggedOut && url.pathname.startsWith('/api/v1/user/')) return json({ status: 'fail', message: '登录已失效' }, 403);
    if (url.pathname.endsWith('/getActiveSession')) return json({ status: 'success', data: sessions });
    if (url.pathname.endsWith('/removeAllActiveSessions')) { sessions = []; loggedOut = true; return json({ status: 'success', data: true }); }
    if (url.pathname.endsWith('/removeActiveSession')) {
      if (body.session_id === '101') loggedOut = true;
      sessions = sessions.filter(session => session.id !== body.session_id);
      return json({ status: 'success', data: true });
    }
    if (url.pathname.endsWith('/sendPasswordChangeCode')) {
      if (body.old_password !== 'fixture-password') return json({ status: 'fail', message: '当前密码错误' }, 400);
      return json({ status: 'success', data: { masked_email: 'p***@example.test', cooldown_seconds: 60 } });
    }
    if (url.pathname.endsWith('/support/dify-context')) return json({ status: 'success', data: { token: 'fixture', user_id: 10, base_url: '', embed_script_url: '/fixture-widget.js' } });
    if (url.pathname.endsWith('/deleteAccount')) {
      if (body.password !== 'fixture-password' || body.confirmation !== '注销账号') return json({ status: 'fail', message: '当前密码错误，账号未注销' }, 400);
      deleted = true; return json({ status: 'success', data: true });
    }
    if (url.pathname.endsWith('/config')) return json({ status: 'success', data: { app_name: '大象网络', currency: 'CNY', currency_symbol: '¥', is_telegram: true, telegram_discuss_link: 'https://t.me/example', invite_commission: 10 } });
    if (url.pathname.endsWith('/user/info')) return json({ status: 'success', data: user });
    if (url.pathname.endsWith('/checkLogin')) return json({ status: 'success', data: { is_login: !deleted } });
    if (url.pathname.endsWith('/getSubscribe')) return json({ status: 'success', data: { ...user, subscribe_url: 'https://example.test/subscription' } });
    if (url.pathname.endsWith('/update')) { Object.assign(user, body); return json({ status: 'success', data: true }); }
    if (url.pathname.endsWith('/changePassword')) {
      if (body.old_password !== 'fixture-password') return json({ status: 'fail', message: '当前密码错误' }, 400);
      if (!body.email_code) return json({ status: 'fail', message: '请完成安全校验后重试', error: { code: 'EMAIL_VERIFICATION_REQUIRED', masked_email: 'p***@example.test' } }, 428);
      if (body.email_code !== '123456') return json({ status: 'fail', message: '邮箱验证码错误' }, 400);
      passwordChanged = loggedOut = true; sessions = [];
      return json({ status: 'success', data: true });
    }
    if (url.pathname.endsWith('/getStat')) return json({ status: 'success', data: [0, 0, 0] });
    return json({ status: 'success', data: [] });
  }
  if (url.pathname === '/fixture-widget.js') {
    res.writeHead(200, { 'Content-Type': 'text/javascript' });
    return res.end("if (!document.getElementById('dify-chatbot-bubble-button')) { const b = document.createElement('button'); b.id = 'dify-chatbot-bubble-button'; b.hidden = true; document.body.appendChild(b); }");
  }
  if (url.pathname.startsWith('/theme/ElephantRoute/')) {
    const file = path.resolve(root, 'public' + decodeURIComponent(url.pathname));
    if (!file.startsWith(path.join(root, 'public/theme/ElephantRoute/')) || !fs.existsSync(file)) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'Content-Type': { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png' }[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    return fs.createReadStream(file).pipe(res);
  }
  let html = fs.readFileSync(path.join(root, 'theme/ElephantRoute/dashboard.blade.php'), 'utf8');
  html = html.replace(/\{\{\$theme\}\}/g, 'ElephantRoute').replace(/\{\{\$title\}\}/g, '大象网络')
    .replace(/\{\{\$version\}\}/g, 'profile-fixture').replace(/\{\{[\s\S]*?\}\}/g, '').replace(/\{!![\s\S]*?!!\}/g, '');
  html = html.replace('<div id="app"></div>', `<script>if(!sessionStorage.getItem('fixture-initialized')){localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN',JSON.stringify({value:'Bearer fixture-only',time:Date.now(),expire:null}));sessionStorage.setItem('fixture-initialized','true');}localStorage.setItem('VUE_NAIVE_LOCALE',JSON.stringify({value:'zh-CN',time:Date.now(),expire:null}));</script><div id="app"></div>`);
  res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }); res.end(html);
});
const port = Number(process.env.PROFILE_PREVIEW_PORT || 7316);
server.listen(port, '127.0.0.1', () => console.log(`Profile fixture: http://127.0.0.1:${port}/dashboard#/profile`));
