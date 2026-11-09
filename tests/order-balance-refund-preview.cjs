// Real admin shell, disposable in-memory API; never loads production credentials.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
let balance = 190, requests = [], failNext = false;
let entitlement = {plan_id: 1, expired_at: 1796083200, transfer_enable: 107374182400, u: 80530636800};
const orders = [
  { id: 1, status: 3, type: 1, total_amount: 3500, balance_amount: 500, handling_amount: 175, plan: { name: '高级套餐' } },
  { id: 2, status: 0, type: 5, total_amount: 5000, plan: { name: '不限时流量包180G' } },
  { id: 3, status: 3, type: 6, total_amount: 10000, period: 'recharge' },
  { id: 4, status: 3, type: 2, total_amount: 5100, commission_status: 2, commission_balance: 510, actual_commission_balance: 510, plan: { name: '旗舰套餐' } },
  { id: 5, status: 4, type: 1, total_amount: 17100, plan: { name: '高级套餐' } },
  { id: 6, status: 3, type: 5, total_amount: 4500, commission_balance: 450, plan: { name: '不限时流量包180G' } },
].map((o, i) => ({ trade_no: '2026110900000' + (i + 1), user_id: 41, period: 'month_price', commission_status: 0, balance_amount: 0,
  created_at: 1794211000 - i * 300, updated_at: 1794211000, payment: { id: 1, name: '微信1（手续费5%）' }, ...o }));
// A completed refund fixture makes the admin list display reviewable without a mutation.
orders[0] = {...orders[0],status:5,total_amount:5000,balance_amount:0,commission_status:3,commission_balance:0,
  balance_refund:{order_id:1,amount:4000,created_at:1794230430,operator_id:1,reason:'隔离退款示例',balance_before:190,balance_after:4190,snapshot:{kind:'subscription',label:'高级套餐',base_amount:5000,order_ids:[1],related_orders:[{id:1,trade_no:orders[0].trade_no}]}},
  balance_refund_entry:{amount:4000}};
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const send = (data, code = 200) => { res.writeHead(code, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(data)); };
  const json = data => send({ status: 'success', data });
  if (url.pathname === '/fixture-state') return json({ balance, requests, entitlement });
  if (url.pathname === '/fixture-fail-next') { failNext = true; return json(true); }
  if (url.pathname.startsWith('/api/')) {
    let raw = ''; for await (const chunk of req) raw += chunk;
    const data = raw ? JSON.parse(raw) : Object.fromEntries(url.searchParams);
    requests.push({ path: url.pathname, data });
    const order = orders.find(o => o.trade_no === data.trade_no || o.id === Number(data.id));
    if (url.pathname.endsWith('/order/fetch')) return send({ status: 'success', data: orders, total: orders.length });
    if (url.pathname.endsWith('/order/detail')) return json({ ...order, user: { id: 41, email: 'refund@example.test' } });
    if (url.pathname.endsWith('/order/refund-preview')) {
      if (order.status !== 3 || order.type === 6 || order.commission_status === 2) return send({ status: 'fail', message: '当前订单不能退款' }, 400);
      const base = order.total_amount + order.balance_amount;
      const amount = Math.floor(base / 4);
      return json({ ...order, email: 'refund@example.test', amount, base_amount: base, kind: 'subscription', label: order.plan?.name, rule: '沿用换套餐折抵：剩余时间与流量比例取较小值', related_orders: [{id:order.id,trade_no:order.trade_no}], quote: 'fixture-prorated-quote', balance_before: balance, balance_after: balance + amount });
    }
    if (url.pathname.endsWith('/order/refund')) {
      await new Promise(resolve => setTimeout(resolve, 700));
      if (failNext) { failNext = false; return send({ status: 'fail', message: '测试：退款暂未完成，请重试' }, 503); }
      if (order.balance_refund) return json(order.balance_refund);
      if (data.quote !== 'fixture-prorated-quote' || data.expected_amount !== Math.floor((order.total_amount + order.balance_amount) / 4)) return send({status:'fail',message:'折算预览无效'},400);
      order.balance_refund = { amount: data.expected_amount, operator_id: 1, created_at: Math.floor(Date.now() / 1000), reason: data.reason, balance_before: balance, balance_after: balance + data.expected_amount, snapshot: {kind:'subscription',label:order.plan?.name,base_amount:order.total_amount+order.balance_amount,related_orders:[{id:order.id,trade_no:order.trade_no}]} };
      entitlement = {plan_id:null,expired_at:null,transfer_enable:0,u:0};
      balance += data.expected_amount; order.status = 5; order.commission_status = 3;
      return json(order.balance_refund);
    }
    if (url.pathname.endsWith('/user/info')) return json({ id: 1, email: 'preview@example.test', is_admin: true, name: '预览管理员' });
    if (url.pathname.endsWith('/plan/fetch')) return json([]);
    return json({});
  }
  if (url.pathname.startsWith('/assets/admin/')) {
    const file = path.resolve(root, 'public' + decodeURIComponent(url.pathname));
    if (!file.startsWith(path.join(root, 'public/assets/admin/')) || !fs.existsSync(file)) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'Content-Type': { '.js': 'text/javascript', '.css': 'text/css', '.ttf': 'font/ttf' }[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
    return fs.createReadStream(file).pipe(res);
  }
  let html = fs.readFileSync(path.join(root, 'resources/views/admin.blade.php'), 'utf8')
    .replaceAll('{{ $title }}', '大象网络 · 订单预览').replaceAll('{{ $version }}', 'refund-preview')
    .replaceAll('{{ $logo }}', '').replaceAll('{{ $secure_path }}', 'preview');
  html = html.replace('<div id="root"></div>', '<script>localStorage.setItem("XBOARD_ACCESS_TOKEN",JSON.stringify({value:"fixture-only",time:Date.now(),expire:null}));localStorage.setItem("i18nextLng","zh-CN");</script><div id="root"></div>');
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }); res.end(html);
});
server.listen(7319, '127.0.0.1', () => console.log('Refund fixture: http://127.0.0.1:7319/preview#/finance/order'));
