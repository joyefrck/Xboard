// Disposable fixture for visual/interaction QA; serves real theme assets with in-memory APIs.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
let deleted = false, order = null, mode = 'qr', emptyChannels = false, loadFail = false;
const user = { id: 10, email: 'preview@example.test', balance: 190, commission_balance: 0,
  remind_expire: true, remind_traffic: true, telegram_id: 123456789, u: 0, d: 0,
  transfer_enable: 0, created_at: 1700000000, expired_at: 0, plan_id: null, uuid: 'fixture-uuid' };
const requests = [];
const methods = [
  { id: 9, name: '支付宝1（手续费4%）', payment: 'fixture', icon: '/assets/payment-icons/alipay.png', handling_fee_fixed: 0, handling_fee_percent: 4 },
  { id: 2, name: '微信1（手续费5%）', payment: 'fixture', icon: '/assets/payment-icons/wechat.png', handling_fee_fixed: 0, handling_fee_percent: 5 },
  { id: 12, name: '微信（备用·手续费5%）', payment: 'fixture', icon: '/assets/payment-icons/wechat.png', handling_fee_fixed: 0, handling_fee_percent: 5 },
  { id: 6, name: 'USDT支付', payment: 'fixture', icon: '/assets/payment-icons/usdt.png', handling_fee_fixed: 0, handling_fee_percent: 0 }
];
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const json = (data, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(data)); };
  if (url.pathname === '/fixture-state') return json({ deleted, requests, order, user, mode });
  if (url.pathname === '/fixture-control') { mode = url.searchParams.get('mode') || 'qr'; emptyChannels = url.searchParams.has('empty'); loadFail = url.searchParams.has('fail'); order = null; requests.length = 0; user.balance = 190; return json(true); }
  if (url.pathname === '/fixture-paid') { if (order && order.status === 0) { order.status = 3; user.balance += order.total_amount; } return json(true); }
  if (url.pathname === '/fixture-provider') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); return res.end('<h1>隔离测试收银台</h1>'); }
  if (url.pathname === '/fixture-reset') { deleted = false; requests.length = 0; return json(true); }
  if (url.pathname.startsWith('/api/')) {
    let raw = ''; for await (const chunk of req) raw += chunk;
    const body = raw ? (req.headers['content-type']?.includes('application/json') ? JSON.parse(raw) : Object.fromEntries(new URLSearchParams(raw))) : {};
    requests.push({ method: req.method, path: url.pathname, body: { ...body, ...(body.password ? { password: '[fixture]' } : {}) } });
    if (url.pathname.endsWith('/order/getPaymentMethod')) return loadFail ? json({ status: 'fail', message: '测试通道加载失败' }, 503) : json({ status: 'success', data: emptyChannels ? [] : methods });
    if (url.pathname.endsWith('/order/recharge')) {
      if (order && [0, 1].includes(order.status)) return json({ status: 'fail', message: '请先处理未完成的订单' }, 400);
      order = { id: 1, user_id: 10, type: 6, period: 'recharge', plan: null, traffic_package: null, trade_no: '202611050000000001', total_amount: Number(body.amount), status: 0, created_at: Math.floor(Date.now() / 1000), balance_amount: 0 };
      return json({ status: 'success', data: order.trade_no });
    }
    if (url.pathname.endsWith('/order/checkout')) {
      if (mode === 'failpay') return json({ status: 'fail', message: '测试支付失败' }, 400);
      const method = methods.find(row => row.id === Number(body.method));
      order.payment_id = method.id;
      order.handling_amount = Math.round(order.total_amount * method.handling_fee_percent / 100 + method.handling_fee_fixed);
      return json(mode === 'redirect' ? { type: 1, data: '/fixture-provider' } : { type: 0, data: 'https://payments.example.test/fixture-qr' });
    }
    if (url.pathname.endsWith('/order/detail')) return json({ status: 'success', data: order });
    if (url.pathname.endsWith('/order/check')) return json({ status: 'success', data: order?.status });
    if (url.pathname.endsWith('/order/fetch')) return json({ status: 'success', data: order ? [order] : [] });
    if (url.pathname.endsWith('/order/cancel')) { order.status = 2; return json({ status: 'success', data: true }); }
    if (url.pathname.endsWith('/invite/fetch')) return json({ status: 'success', data: { codes: [], stat: [0,0,0,0,0] } });
    if (url.pathname.endsWith('/telegram/account')) return json({ status: 'success', data: null });
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
    if (url.pathname.endsWith('/changePassword')) return json({ status: 'success', data: true });
    if (url.pathname.endsWith('/getStat')) return json({ status: 'success', data: [0, 0, 0] });
    return json({ status: 'success', data: [] });
  }
  if (url.pathname === '/fixture-widget.js') {
    res.writeHead(200, { 'Content-Type': 'text/javascript' });
    return res.end("if (!document.getElementById('dify-chatbot-bubble-button')) { const b = document.createElement('button'); b.id = 'dify-chatbot-bubble-button'; b.hidden = true; document.body.appendChild(b); }");
  }
  if (/^\/assets\/payment-icons\/(alipay|wechat|usdt)\.png$/.test(url.pathname)) {
    res.writeHead(200, { 'Content-Type': 'image/png', 'Cache-Control': 'no-store' });
    return fs.createReadStream(path.join(root, 'public', url.pathname)).pipe(res);
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
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }); res.end(html);
});
server.listen(7317, '127.0.0.1', () => console.log('Wallet fixture: http://127.0.0.1:7317/dashboard#/profile'));
