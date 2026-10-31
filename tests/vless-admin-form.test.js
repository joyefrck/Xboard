const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

async function moduleUnderTest() {
  const source = fs.readFileSync(path.join(__dirname, '../public/assets/admin/assets/vless-config.js'), 'utf8');
  return import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
}

test('editing an advanced field preserves keys and unrelated node settings', async () => {
  const { patchVlessDraft } = await moduleUnderTest();
  const original = { tls: 2, reality_settings: { private_key: 'secret', short_id: 'aa' }, encryption: { enabled: true, encryption: 'client', decryption: 'server' } };
  const disabled = patchVlessDraft(original, 'encryption.enabled', false);
  assert.equal(disabled.encryption.decryption, 'server');
  assert.equal(disabled.reality_settings.private_key, 'secret');
  assert.equal(original.encryption.enabled, true);
  const enabled = patchVlessDraft(disabled, 'encryption.enabled', true);
  assert.equal(enabled.encryption.encryption, 'client');
});

test('XHTTP extra rejects malformed JSON and non-object values', async () => {
  const { parseXhttpExtra } = await moduleUnderTest();
  assert.deepEqual(parseXhttpExtra(''), {});
  assert.deepEqual(parseXhttpExtra('{"headers":{"X-Test":"yes"}}'), { headers: { 'X-Test': 'yes' } });
  for (const value of ['{', 'null', '[]', 'true']) assert.throws(() => parseXhttpExtra(value));
});
