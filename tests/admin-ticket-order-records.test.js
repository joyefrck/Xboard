const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const bundle = fs.readFileSync(path.join(__dirname, '../public/assets/admin/assets/index.js'), 'utf8');

test('ticket order action sends an exact user filter through the order page', () => {
  const action = bundle.match(/gs=(\(\)=>\{N\?\.user&&l\([^}]+\)\}),Ue=/);
  assert.ok(action, 'ticket order-record action exists');
  const effect = bundle.slice(bundle.indexOf('function Ih()')).match(/m\.useEffect\((\(\)=>\{const F=[\s\S]*?\}),\[s\]\)/);
  assert.ok(effect, 'order page reads URL filters');

  for (const id of [123, '123']) {
    let url;
    new Function('N', 'l', `return (${action[1]})();`)({ user: { id } }, value => { url = value; });
    const params = new URL(url, 'https://example.test').searchParams;
    assert.equal(params.get('user_id'), 'eq:123');
    let filters;
    new Function('s', 'r', `return (${effect[1]})();`)(params, value => { filters = value; });
    assert.deepEqual(filters, [{ id: 'user_id', value: 'eq:123' }]);
  }
  new Function('N', 'l', `return (${action[1]})();`)({}, () => assert.fail('no user must not navigate'));
});
