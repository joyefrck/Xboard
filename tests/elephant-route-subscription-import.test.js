const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const zlib = require('node:zlib');

const bundle = fs.readFileSync(path.join(__dirname, '../theme/ElephantRoute/assets/umi.js'), 'utf8');

function importMenu(subscribe, load) {
  const start = bundle.indexOf('let erSubscriptionImportPending=');
  assert.notEqual(start, -1, 'subscription import must validate its own subscription state');
  const end = bundle.indexOf('const K=j([])', start);
  assert.notEqual(end, -1);
  const y = { value: subscribe };
  const d = { value: false };
  const errors = [];
  const a = { getUserSubscribe: load };
  const open = new Function('a', 'y', 'd', 'window', bundle.slice(start, end) + '; return erOpenSubscriptionImport;')(
    a, y, d, { $message: { error: message => errors.push(message) } }
  );
  return { open, y, d, errors };
}

test('missing initial subscription waits for recovery and ignores repeated clicks', async () => {
  let resolve;
  let calls = 0;
  const pending = new Promise(done => { resolve = done; });
  const menu = importMenu(undefined, async () => { calls++; menu.y.value = await pending; });
  const first = menu.open();
  await menu.open();
  assert.equal(calls, 1);
  assert.equal(menu.d.value, false, 'empty menu stays closed while loading');
  resolve({ subscribe_url: 'https://example.test/sub?token=fixture' });
  await first;
  assert.equal(menu.d.value, true);
  assert.deepEqual(menu.errors, []);
});

test('loaded subscription opens immediately without another request', async () => {
  const menu = importMenu({ subscribe_url: 'https://example.test/sub' }, () => assert.fail('unnecessary request'));
  const result = menu.open();
  assert.equal(menu.d.value, true);
  await result;
});

test('failed initialization remains closed and the next click can retry', async () => {
  let calls = 0;
  const menu = importMenu(undefined, async () => {
    if (++calls === 1) throw new Error('temporary failure');
    menu.y.value = { subscribe_url: 'https://example.test/sub' };
  });
  await menu.open();
  assert.equal(menu.d.value, false);
  assert.equal(menu.errors.length, 1);
  await menu.open();
  assert.equal(menu.d.value, true);
  assert.equal(calls, 2);
});

test('successful response without a subscription URL never opens an empty menu', async () => {
  const menu = importMenu({}, async () => { menu.y.value = { plan_id: null }; });
  await menu.open();
  assert.equal(menu.d.value, false);
  assert.equal(menu.errors.length, 1);
});

test('the actual one-click subscription action uses the validated entry point', () => {
  assert.match(bundle, /onClick:re\[12\]\|\|\(re\[12\]=erOpenSubscriptionImport\)/);
});

test('served and precompressed ElephantRoute bundles contain the same recovery handler', () => {
  for (const base of ['theme/ElephantRoute', 'public/theme/ElephantRoute']) {
    const file = path.join(__dirname, '..', base, 'assets/umi.js');
    assert.equal(fs.readFileSync(file, 'utf8'), bundle);
    assert.equal(zlib.gunzipSync(fs.readFileSync(file + '.gz')).toString(), bundle);
    assert.equal(zlib.brotliDecompressSync(fs.readFileSync(file + '.br')).toString(), bundle);
  }
});
