const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const test = require('node:test');
const helpers = require('../theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
const script = fs.readFileSync(require.resolve('../theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'), 'utf8');

test('account labels distinguish username, name and ID without displaying stale identities', () => {
  const user = { telegram_id: 123 };
  const label = helpers.buildTelegramAccountLabel;
  assert.equal(label(user, { id: '123', username: 'example', name: 'Example' }), '已绑定账号：@example');
  assert.equal(label(user, { id: 123, name: '示例' }), '已绑定账号：示例（ID：123）');
  assert.equal(label(user, null), '已绑定账号：ID：123');
  assert.equal(label(user, { id: 456, username: 'old_account' }), '已绑定账号：ID：123');
  assert.equal(label({}, { id: 123, username: 'old_account' }), '');
});

const source = script.slice(script.indexOf('  function loadTelegramAccount('), script.indexOf('  function renderTelegram('));
function fixture() {
  let resolve, reject, renders = 0, calls = 0;
  const context = {
    state: { data: { user: { telegram_id: 123 } }, root: {}, loadGeneration: 1 },
    ENDPOINTS: { telegramBinding: '/api/v1/user/telegram/binding' },
    requestJson: () => { calls++; return new Promise((yes, no) => { resolve=yes; reject=no; }); },
    getResponseData: x => x.data,
    renderTelegram: () => { renders++; }
  };
  vm.runInNewContext(source, context);
  return { ...context, resolve: x => resolve({data:x}), reject: () => reject(new Error('offline')), renders: () => renders, calls: () => calls };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
test('late account responses never overwrite a new binding, page, or unmounted dashboard', async () => {
  for (const change of [f => {f.state.data.user.telegram_id=456;}, f => {f.state.loadGeneration++;}, f => {f.state.root=null;}]) {
    const f=fixture(); f.loadTelegramAccount(1); change(f);
    f.resolve({id:123,username:'old'}); await settle();
    assert.equal(f.renders(),0); assert.equal(f.state.data.telegramAccount,undefined);
  }
});
test('bound account enrichment renders independently and failure preserves ID fallback', async () => {
  const f=fixture(); f.loadTelegramAccount(1); f.resolve({id:123,username:'example'}); await settle();
  assert.equal(f.renders(),1); assert.equal(f.state.data.telegramAccount.username,'example');
  const failed=fixture(); failed.loadTelegramAccount(1); failed.reject(); await settle();
  assert.equal(failed.renders(),0); assert.equal(failed.state.data.user.telegram_id,123);
  const unbound=fixture(); unbound.state.data.user.telegram_id=null; unbound.loadTelegramAccount(1);
  assert.equal(unbound.calls(),0);
});
