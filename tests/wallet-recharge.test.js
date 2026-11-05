const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const wallet = require('../theme/ElephantRoute/assets/elephant-route-wallet.js');
const root = path.resolve(__dirname, '..');
const methods = [{ id: 9, name: '后台优先通道', handling_fee_fixed: 123, handling_fee_percent: 1.234 },
  { id: 2, name: '免费通道', handling_fee_fixed: 0, handling_fee_percent: 0 }];

function harness({ channels = methods, post, get } = {}) {
  const starts = [], stops = [], calls = [], routes = [], listeners = new Map();
  const user = { balance: 190, getUserInfo: async () => { calls.push(['refresh']); } };
  global.window = { addEventListener: (event, fn) => listeners.set(event, fn), removeEventListener: event => listeners.delete(event),
    location: { assign: target => calls.push(['redirect', target]) } };
  const runtime = {
    h: (type, props, children) => ({ type, props: props || {}, children }), ref: value => ({ value }), defineComponent: value => value,
    onMounted: fn => starts.push(fn), onBeforeUnmount: fn => stops.push(fn),
    Button: 'Button', Card: 'Card', Layout: 'Layout', useUser: () => user,
    useRouter: () => ({ push: async route => routes.push(route) }), useRoute: () => ({ path: '/dashboard' }),
    request: { get: async endpoint => { calls.push(['get', endpoint]); return get ? get(endpoint) : { data: channels }; },
      post: async (endpoint, body) => { calls.push(['post', endpoint, body]); return post ? post(endpoint, body) : { data: 'fixture-trade' }; } }
  };
  const components = wallet.createComponents(runtime);
  const render = components.Recharge.setup();
  function flatten(node) {
    if (node == null) return [];
    if (Array.isArray(node)) return node.flatMap(flatten);
    if (typeof node !== 'object') return [node];
    const children = typeof node.children?.default === 'function' ? node.children.default() : node.children;
    return [node, ...flatten(children)];
  }
  const nodes = () => flatten(render());
  const text = node => flatten(node).filter(item => typeof item === 'string').join('');
  const button = label => nodes().find(node => node.type === 'Button' && text(node) === label);
  return { starts, stops, calls, routes, listeners, user, components, nodes, button, text,
    mount: async () => { for (const start of starts) await start(); } };
}

test('fees match backend rounding and fixed principal; absent numeric fields default to zero', () => {
  assert.deepEqual(wallet.amounts, [10000, 20000, 50000]);
  assert.equal(wallet.fee(10000, methods[0]), 246);
  assert.equal(wallet.fee(20000, methods[0]), 370);
  assert.equal(wallet.fee(50000, { handling_fee_fixed: '200', handling_fee_percent: '2.5' }), 1450);
  assert.equal(wallet.fee(10000, methods[1]), 0);
  assert.equal(wallet.fee(10000, {}), 0);
  assert.equal(wallet.money(190), '1.90');
  assert.equal(wallet.isRecharge({ type: 6, period: 'recharge' }), true);
  assert.equal(wallet.isRecharge({ type: 1, period: 'recharge' }), false);
});

test('page keeps server channel order, supports every amount and updates totals', async () => {
  const ui = harness(); await ui.mount();
  assert.deepEqual(ui.nodes().filter(node => node.props?.class?.includes('er-wallet-method-content')).map(ui.text), ['后台优先通道✓', '免费通道']);
  assert.ok(ui.nodes().some(node => ui.text(node) === '实付金额¥ 102.46'));
  ui.button('200 元').props.onClick();
  assert.ok(ui.nodes().some(node => ui.text(node) === '实付金额¥ 203.70'));
  ui.button('500 元').props.onClick();
  assert.ok(ui.nodes().some(node => ui.text(node) === '实付金额¥ 507.40'));
  ui.button('免费通道').props.onClick();
  assert.ok(ui.nodes().some(node => ui.text(node) === '实付金额¥ 500.00'));
});

test('busy submit creates one order, uses selected channel and hands QR to existing order detail once', async () => {
  let resolveCreate;
  const ui = harness({ post: async endpoint => endpoint.endsWith('/recharge') ? new Promise(resolve => { resolveCreate = resolve; }) : { type: 0, data: 'fixture-qr' } });
  await ui.mount(); ui.button('200 元').props.onClick();
  const submit = ui.button('支付').props.onClick;
  const first = submit(); await submit();
  assert.equal(ui.button('支付').props.disabled, true);
  resolveCreate({ data: 'fixture-200' }); await first;
  assert.deepEqual(ui.calls.filter(call => call[0] === 'post'), [
    ['post', '/user/order/recharge', { amount: 20000 }], ['post', '/user/order/checkout', { trade_no: 'fixture-200', method: 9 }]
  ]);
  assert.deepEqual(ui.routes, ['/order/fixture-200']);
  assert.equal(wallet.takeCheckout('fixture-200'), 'fixture-qr');
  assert.equal(wallet.takeCheckout('fixture-200'), undefined);
});

test('failed checkout retries the same order and locks its principal', async () => {
  let attempts = 0;
  const ui = harness({ post: async endpoint => {
    if (endpoint.endsWith('/recharge')) return { data: 'retry-order' };
    if (++attempts === 1) throw Error('fixture payment failure');
    return { type: 1, data: 'https://payments.example.test/fixture' };
  } });
  await ui.mount(); await ui.button('支付').props.onClick();
  assert.ok(ui.nodes().some(node => node.props?.role === 'alert'));
  assert.equal(ui.button('100 元').props.disabled, true);
  await ui.button('继续支付').props.onClick();
  assert.equal(ui.calls.filter(call => call[1] === '/user/order/recharge').length, 1);
  assert.equal(ui.calls.filter(call => call[1] === '/user/order/checkout').length, 2);
  assert.ok(ui.calls.some(call => call[0] === 'redirect'));
});

test('no methods disables payment and loading errors offer retry', async () => {
  const empty = harness({ channels: [] }); await empty.mount();
  assert.equal(empty.button('支付').props.disabled, true);
  await empty.button('支付').props.onClick();
  assert.equal(empty.calls.some(call => call[0] === 'post'), false);
  let fail = true;
  const ui = harness({ get: async () => { if (fail) throw Error('fixture'); return { data: methods }; } });
  await ui.mount(); assert.ok(ui.button('重新加载'));
  fail = false; await ui.button('重新加载').props.onClick();
  assert.equal(ui.button('支付').props.disabled, false);
});

test('focus refresh and component disposal keep account data current', async () => {
  const ui = harness(); await ui.mount();
  const before = ui.calls.filter(call => call[0] === 'refresh').length;
  ui.listeners.get('focus')();
  assert.equal(ui.calls.filter(call => call[0] === 'refresh').length, before + 1);
  for (const stop of ui.stops) stop();
  assert.equal(ui.listeners.has('focus'), false);
});

test('the shared request client can resolve an error payload; it must never be treated as success', async () => {
  const unavailable = harness({ get: async () => ({ code: 503, message: 'unavailable' }) });
  await unavailable.mount(); assert.ok(unavailable.button('重新加载'));
  const rejected = harness({ post: async () => ({ code: 400, message: '请先处理未完成的订单' }) });
  await rejected.mount(); await rejected.button('支付').props.onClick();
  assert.equal(rejected.calls.filter(call => call[1] === '/user/order/checkout').length, 0);
  assert.equal(rejected.routes.length, 0);
  assert.ok(rejected.nodes().some(node => node.props?.role === 'alert' && rejected.text(node).includes('请先处理未完成的订单')));
  const checkout = harness({ post: async endpoint => endpoint.endsWith('/recharge') ? { data: 'resolved-error' } : { code: 400, message: '支付失败' } });
  await checkout.mount(); await checkout.button('支付').props.onClick();
  assert.ok(checkout.button('继续支付'));
  assert.equal(checkout.routes.length, 0);
});

test('wallet browser assets are mirrored, cache-busted, and bundles match their compressed siblings', () => {
  for (const asset of ['elephant-route-wallet.js', 'elephant-route-wallet.css', 'umi.js', 'elephant-route-pages-v2.js']) {
    assert.deepEqual(fs.readFileSync(path.join(root, 'theme/ElephantRoute/assets', asset)), fs.readFileSync(path.join(root, 'public/theme/ElephantRoute/assets', asset)));
  }
  const bundle = fs.readFileSync(path.join(root, 'theme/ElephantRoute/assets/umi.js'));
  assert.deepEqual(zlib.gunzipSync(fs.readFileSync(path.join(root, 'theme/ElephantRoute/assets/umi.js.gz'))), bundle);
  assert.deepEqual(zlib.brotliDecompressSync(fs.readFileSync(path.join(root, 'theme/ElephantRoute/assets/umi.js.br'))), bundle);
  const view = fs.readFileSync(path.join(root, 'theme/ElephantRoute/dashboard.blade.php'), 'utf8');
  assert.match(view, /elephant-route-wallet\.js\?v=\{\{\$version\}\}-er20261105wallet1/);
  assert.ok(view.indexOf('elephant-route-wallet.js') < view.indexOf('type="module"'));
});
