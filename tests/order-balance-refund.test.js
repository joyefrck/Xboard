const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { spawnSync } = require('node:child_process');
const load = require('./admin-module-loader.cjs');
let moduleUI;
test.before(async () => { moduleUI = await load('order-refund.js'); });
function harness({ previewError = false, submitError = false, refreshError = false, kind = 'subscription', commissionDeduction = 0 } = {}) {
  const order = { trade_no: '202611090001', type: 1, status: 3, total_amount: 3500, balance_amount: 500, commission_status: 0 };
  const preview = { ...order, user_id: 41, email: 'refund@example.test', amount: 1000, base_amount: 4000, handling_amount: 175, balance_before: 190, balance_after: 1190, kind, label: '高级套餐', rule: '沿用换套餐折抵', quote: 'signed-fixture-quote', related_orders: [{id:1,trade_no:order.trade_no},{id:2,trade_no:'renewal-trade'}] };
  preview.gross_amount = preview.amount + commissionDeduction;
  preview.commission_deduction = commissionDeduction;
  if (commissionDeduction) Object.assign(order, {commission_status:2,actual_commission_balance:commissionDeduction});
  let hook = 0, pendingEffects = [], deferred;
  const states = [], effects = [], refs = [], calls = [], messages = [];
  const React = {
    Fragment: 'Fragment', createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
    useState(initial) { const index = hook++; if (!(index in states)) states[index] = initial; return [states[index], value => { states[index] = typeof value === 'function' ? value(states[index]) : value; }]; },
    useRef(initial) { const index = hook++; return refs[index] ||= { current: initial }; },
    useId() { return 'reason-id'; },
    useEffect(callback, dependencies) { const index = hook++; if (!effects[index] || dependencies.some((v, i) => v !== effects[index][i])) { effects[index] = dependencies; pendingEffects.push(callback); } },
  };
  global.window = { settings: { secure_path: 'fixture' } };
  const { Actions } = moduleUI.createRefundUI({ React,
    api: { post: async (url, payload) => {
      calls.push({ url, payload });
      if (url.endsWith('refund-preview')) {
        if (previewError) throw Error('预览失败');
        return { data: preview };
      }
      if (submitError) throw Error('退款失败');
      return new Promise(resolve => { deferred = () => resolve({ data: { id: 7, amount: preview.amount } }); });
    } },
    toast: { success: v => messages.push(v), error: v => messages.push(v) },
    ui: Object.fromEntries('Button Menu MenuTrigger MenuContent MenuItem Dots Dialog DialogContent DialogHeader DialogTitle DialogDescription DialogFooter'.split(' ').map(v => [v, v])) });
  const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...(node.children || []).flatMap(flatten)] : [];
  function render() { hook = 0; const result = Actions({ order, refresh: async () => { calls.push({ url: 'refresh' }); if (refreshError) throw Error('fixture'); } }); const effects = pendingEffects; pendingEffects = []; effects.forEach(fn => fn()); return result; }
  const nodes = () => flatten(render());
  const text = node => Array.isArray(node) ? node.map(text).join('') : typeof node === 'string' ? node : node?.children?.map(text).join('') || '';
  const button = label => nodes().find(n => n.type === 'Button' && text(n) === label);
  const settle = async () => { render(); await new Promise(resolve => setImmediate(resolve)); render(); };
  async function open() { nodes().find(n => n.type === 'MenuItem').props.onSelect(); render(); await settle(); }
  function reason(value) { nodes().find(n => n.type === 'textarea').props.onChange({ target: { value } }); render(); }
  const submit = () => nodes().find(n => n.type === 'form').props.onSubmit({ preventDefault() {} });
  return { order, calls, messages, nodes, text, button, open, reason, submit, settle, resolve: () => deferred() };
}
test('menu contains only refund; unavailable orders show a reason', () => {
  const ui = harness();
  assert.equal(ui.nodes().filter(n => n.type === 'MenuItem').length, 1);
  assert.equal(ui.text(ui.nodes().find(n => n.type === 'MenuItem')), '退款到余额');
  for (const order of [{status: 0}, {status: 1}, {status: 2}, {status: 4}, {status: 5}, {status: 3, type: 6}, {status: 3, type: 4}, {status: 3, no_proration: true}, {status: 3, commission_status: 1}]) assert.ok(moduleUI.unavailableReason(order));
  assert.equal(moduleUI.unavailableReason(ui.order), '');
});
test('settled commissions permit preview and display gross, withheld cost and net credit', async () => {
  assert.equal(moduleUI.unavailableReason({status:3,commission_status:2,actual_commission_balance:300}), '');
  const ui=harness({commissionDeduction:300});
  assert.equal(ui.nodes().find(n=>n.type==='MenuItem').props.disabled,false);
  await ui.open();
  const content=ui.text(ui.nodes().find(n=>n.type==='form'));
  for(const expected of ['扣佣前折算退款','¥13.00','扣除已结算佣金','¥3.00','本次实际退款','不扣回邀请人账户']) assert.ok(content.includes(expected),expected);
  ui.reason('扣除已发佣金后退款');const pending=ui.submit();ui.resolve();await pending;
  assert.equal(ui.calls.find(c=>c.url.endsWith('/refund')).payload.expected_amount,1000);
});
test('confirmation is read-only; cancel makes no refund request', async () => {
  const ui = harness(); await ui.open();
  assert.equal(ui.calls.length, 1);
  assert.ok(ui.calls[0].url.endsWith('refund-preview'));
  assert.equal(ui.button('确认退款 ¥10.00').props.disabled, true);
  const content = ui.text(ui.nodes().find(n => n.type === 'form'));
  for (const expected of ['¥1.75', 'refund@example.test', '¥11.90', '退款将立即取消当前套餐，清除套餐流量和有效期', '涉及 2 笔订单（一起关闭）']) assert.ok(content.includes(expected), expected);
  ui.button('取消').props.onClick();
  assert.equal(ui.calls.length, 1);
  assert.equal(ui.nodes().find(n => n.type === 'Dialog').props.open, false);
});
test('submit requires reason, locks immediately, sends exact confirmed preview and refreshes', async () => {
  const ui = harness(); await ui.open(); await ui.submit(); assert.equal(ui.calls.length, 1);
  ui.reason(' 用户申请退款 ');
  const first = ui.submit(); const second = ui.submit(); await second;
  assert.equal(ui.calls.filter(c => c.url.endsWith('/refund')).length, 1);
  assert.equal(ui.button('正在退款…').props.disabled, true);
  ui.nodes().find(n => n.type === 'Dialog').props.onOpenChange(false);
  assert.equal(ui.nodes().find(n => n.type === 'Dialog').props.open, true);
  ui.resolve(); await first;
  assert.deepEqual(ui.calls[1].payload, { trade_no: ui.order.trade_no, expected_amount: 1000, reason: '用户申请退款', confirmed: true, quote: 'signed-fixture-quote' });
  assert.equal(ui.calls.at(-1).url, 'refresh');
  assert.equal(ui.nodes().find(n => n.type === 'Dialog').props.open, false);
  assert.equal(ui.nodes().find(n => n.type === 'MenuItem').props.disabled, true);
});
test('failure preserves reason and dialog, permits a deliberate retry', async () => {
  const ui = harness({ submitError: true }); await ui.open(); ui.reason('测试退款原因'); await ui.submit();
  assert.equal(ui.nodes().find(n => n.type === 'Dialog').props.open, true);
  assert.equal(ui.nodes().find(n => n.type === 'textarea').props.value, '测试退款原因');
  assert.ok(ui.nodes().some(n => n.props.role === 'alert' && ui.text(n) === '退款失败'));
  assert.equal(ui.button('确认退款 ¥10.00').props.disabled, false);
  await ui.submit(); assert.equal(ui.calls.filter(c => c.url.endsWith('/refund')).length, 2);
});
test('preview failure never enables refund and offers recheck', async () => {
  const ui = harness({ previewError: true }); await ui.open();
  assert.ok(ui.button('重新核对')); assert.equal(ui.nodes().filter(n => n.type === 'textarea').length, 0);
  assert.equal(ui.calls.filter(c => c.url.endsWith('/refund')).length, 0);
});
test('list refresh failure cannot make a successful refund appear failed or permit another refund', async () => {
  const ui = harness({ refreshError: true }); await ui.open(); ui.reason('测试退款原因'); const pending = ui.submit(); ui.resolve(); await pending;
  assert.deepEqual(ui.messages, ['已退款 ¥10.00 到用户余额', '退款已完成，列表刷新失败，请手动刷新']);
  assert.equal(ui.nodes().find(n => n.type === 'MenuItem').props.disabled, true);
});
test('isolated PHP integration checks pass', () => {
  const result = spawnSync('php', [path.join(__dirname, 'order-balance-refund.php')], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr); assert.match(result.stdout, /\d+ checks passed/);
});
test('bundle appends a pinned action column and includes audit details and status translations', () => {
  const source = fs.readFileSync(path.join(__dirname, '../public/assets/admin/assets/index.js'), 'utf8');
  const columns = source.slice(source.indexOf('Vh=s=>'), source.indexOf('function Ih()'));
  assert.ok(columns.indexOf('id:"actions"') > columns.indexOf('accessorKey:"created_at"'));
  assert.ok(columns.includes('xboardOrderRefundUI.Actions'));
  assert.ok(source.includes('xboardOrderRefundUI.Audit,{refund:a?.balance_refund}'));
  for (const locale of ['zh-CN', 'en-US', 'ko-KR']) {
    const content = fs.readFileSync(path.join(__dirname, `../public/assets/admin/locales/${locale}.js`), 'utf8');
    new Function(content); assert.match(content, /"REFUNDED":/);
  }
  const route = fs.readFileSync(path.join(__dirname, '../app/Http/Routes/V2/AdminRoute.php'), 'utf8');
  assert.ok(route.includes("'middleware' => ['admin', 'log']"));
  assert.match(route, /post\('\/refund',/);
});

test('both user themes show a refunded result instead of an unexpected state', () => {
  for (const theme of ['ElephantRoute', 'Xboard']) {
    const source = fs.readFileSync(path.join(__dirname, `../theme/${theme}/assets/umi.js`), 'utf8');
    const start = source.lastIndexOf('function i(x){switch(x)', source.indexOf('case 5:return{icon:"info",title:"已退款"'));
    assert.ok(start >= 0, `${theme} has order result formatter`);
    const end = source.indexOf('async function a()', start);
    const result = new Function('r', 'l', `return (${source.slice(start, end)})(5);`)(v => v, { value: {} });
    assert.equal(result.title, '已退款');
    assert.match(result.subTitle, /站内余额/);
    assert.equal(result.icon, 'info');
    assert.deepEqual(fs.readFileSync(path.join(__dirname, `../theme/${theme}/assets/umi.js`)), fs.readFileSync(path.join(__dirname, `../public/theme/${theme}/assets/umi.js`)));
  }
});

test('administrator can explicitly recalculate a stale quote without losing the reason', async () => {
  const ui = harness(); await ui.open(); ui.reason('重新计算后退款'); ui.button('重新核对金额').props.onClick(); await ui.settle();
  assert.equal(ui.calls.filter(c => c.url.endsWith('refund-preview')).length, 2);
  assert.equal(ui.nodes().find(n => n.type === 'textarea').props.value, '重新计算后退款');
  assert.equal(ui.calls.filter(c => c.url.endsWith('/refund')).length, 0);
});

test('standalone traffic package confirmation limits revocation to that order', async () => {
  const ui = harness({kind:'traffic_package'}); await ui.open();
  const content = ui.text(ui.nodes().find(n => n.type === 'form'));
  assert.ok(content.includes('回收该订单对应的全部剩余流量'));
  assert.ok(content.includes('其他套餐和独立流量包不受影响'));
  assert.ok(!content.includes('立即取消当前套餐'));
});

test('admin amount cell shows struck original / refund and preserves normal amounts', () => {
  const React = { createElement: (tag, props, ...children) => ({tag, props, children}) };
  const {AmountCell} = moduleUI.createRefundUI({React,ui:{}});
  const order = {id:1,status:5,total_amount:5000,balance_refund:{order_id:1,amount:4000}};
  const cell = AmountCell({order});
  assert.equal(cell.children[0].tag,'s'); assert.equal(cell.children[0].children[0],'¥50.00');
  assert.equal(cell.children[1],'/'); assert.equal(cell.children[2].children[0],'¥40.00');
  assert.equal(cell.children[2].props.style.color,'#dc2626');
  assert.deepEqual(AmountCell({order:{...order,status:3}}).children,['¥50.00']);
});

test('admin amount uses per-order allocation rather than repeating the group total', () => {
  assert.equal(moduleUI.refundForOrder({id:2,balance_refund:{order_id:1,amount:4000},balance_refund_entry:{amount:1500}}),1500);
  assert.equal(moduleUI.refundForOrder({id:2,balance_refund:{order_id:1,amount:4000}}),0);
  assert.equal(moduleUI.refundForOrder({id:2}),null);
});
