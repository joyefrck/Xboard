const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const ui = require('../theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');

test('paid private placeholder shows waiting state even with existing package credit', () => {
  const state = ui.buildSubscriptionViewModel({
    custom_pending: true, plan: { name: '香港定制' }, expired_at: 0,
    has_active_plan: false, traffic_package_total: 100, traffic_package_remaining: 100,
    subscribe_url: 'should-not-be-enabled'
  });
  assert.equal(state.name, '香港定制');
  assert.equal(state.status, '待开通');
  assert.equal(state.expiryLabel, '开通后计算');
  assert.equal(state.canSubscribe, false);
  assert.match(state.trafficNote, /等待管理员/);
});

test('admin module graph uses one cache version, preventing duplicate React initialization', () => {
  const view = read('resources/views/admin.blade.php');
  const version = view.match(/index\.js\?v=([^"<]+)/)[1];
  for (const file of ['index.js','vendor.js']) {
    const imports = [...read(`public/assets/admin/assets/${file}`).matchAll(/(?:index|vendor)\.js\?v=([^"']+)/g)];
    assert.ok(imports.length);
    for (const match of imports) assert.equal(match[1], version);
  }
});

test('user assets and precompressed JS exactly match their served counterparts', () => {
  const zlib = require('node:zlib');
  const bundle = fs.readFileSync(path.join(root, 'theme/ElephantRoute/assets/umi.js'));
  for (const base of ['theme/ElephantRoute', 'public/theme/ElephantRoute']) {
    assert.deepEqual(zlib.gunzipSync(fs.readFileSync(path.join(root, base, 'assets/umi.js.gz'))), bundle);
    assert.deepEqual(zlib.brotliDecompressSync(fs.readFileSync(path.join(root, base, 'assets/umi.js.br'))), bundle);
    assert.equal(read(`${base}/assets/umi.js`), bundle.toString());
  }
});

test('admin partial user update retains the observed pending order for stale-state protection', () => {
  const bundle = read('public/assets/admin/assets/index.js');
  const start = bundle.indexOf('u.handleSubmit(o=>{const packageId=');
  const end = bundle.indexOf('})()', start) + 4;
  assert.ok(start >= 0 && end > start);
  for (const pendingOrder of [88, null]) {
    let sent;
    const form = {
      formState: { dirtyFields: { plan_id: true } },
      handleSubmit: callback => () => callback({
        id: 1024, plan_id: 21, expected_custom_order_id: pendingOrder,
        traffic_package_id: null, traffic_package_add_gb: null
      })
    };
    const api = { update: payload => { sent = payload; return { then() {} }; } };
    new Function('u', 'Ys', 'A', 's', 't', 'a', bundle.slice(start, end))(
      form, api, { error: message => assert.fail(message) }, key => key, () => {}, () => {}
    );
    assert.deepEqual(sent, { id: 1024, plan_id: 21, expected_custom_order_id: pendingOrder });
  }
});

test('plan tabs isolate all three types and default legacy records to standard', () => {
  const bundle = read('public/assets/admin/assets/index.js');
  const start = bundle.indexOf('function xboardFilterPlanType(');
  const end = bundle.indexOf('function _h()', start);
  const helpers = new Function(bundle.slice(start, end) + ';return {filter:xboardFilterPlanType,reorder:xboardReorderPlanType}')();
  const plans = [{id:1},{id:5,plan_type:'custom'},{id:2,plan_type:'standard'},{id:6,plan_type:'exclusive'},{id:7,plan_type:'custom'}];
  const ids = list => list.map(plan => plan.id);
  assert.deepEqual(ids(helpers.filter(plans,'standard')), [1,2]);
  assert.deepEqual(ids(helpers.filter(plans,'custom')), [5,7]);
  assert.deepEqual(ids(helpers.filter(plans,'exclusive')), [6]);
  assert.deepEqual(ids(helpers.reorder(plans,'custom',0,1)), [1,7,2,6,5]);
  assert.deepEqual(ids(helpers.reorder(plans,'standard',1,0)), [2,5,1,6,7]);
  assert.deepEqual(ids(plans), [1,5,2,6,7]);
  assert.strictEqual(helpers.reorder(plans,'custom',-1,0), plans);
  assert.match(bundle, /function _h\(\)\{const\[planType,setPlanType\]=m\.useState\("standard"\)/);
});

test('exclusive plan search matches registered email or plan name without mixing normal plans', () => {
  const bundle = read('public/assets/admin/assets/index.js');
  const start = bundle.indexOf('function xboardPlanMatchesSearch(');
  const end = bundle.indexOf('function xboardFilterPlanType(', start);
  const matches = new Function(bundle.slice(start, end) + ';return xboardPlanMatchesSearch')();
  const plan = {name:'香港专属',plan_type:'exclusive',owner_email:'owner@example.test'};
  assert.equal(matches(plan,' OWNER@EXAMPLE '),true);
  assert.equal(matches(plan,'香港'),true);
  assert.equal(matches(plan,'another@example'),false);
  assert.equal(matches({...plan,plan_type:'standard'},'owner@example'),false);
  assert.equal(matches({name:'普通套餐'},''),true);
  assert.match(bundle, /name:"owner_email",render:/);
  assert.doesNotMatch(bundle, /name:"owner_user_id",render:/);
});
