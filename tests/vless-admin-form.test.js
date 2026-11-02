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

test('REALITY compatibility can be enabled without selecting a server core or changing keys', async () => {
  const { VlessConfigFields } = await moduleUnderTest();
  let values = { tls: 2, network: 'tcp', reality_settings: { private_key: 'secret', public_key: 'public', short_id: 'aa' } };
  const React = {
    createElement: (type, props, ...children) => ({ type, props: props || {}, children: children.flat(Infinity) }),
    useState: initial => [initial, () => {}], useId: () => 'fixture',
  };
  const form = { watch: () => values, getValues: () => values, setValue: (key, value) => { values = { ...values, [key]: value }; }, formState: { errors: {} } };
  const tree = VlessConfigFields({ React, form, t: key => key, generateKeys: () => {}, generateShortId: () => {} });
  const elements = [];
  function visit(node) { if (!node || typeof node !== 'object') return; elements.push(node); node.children.forEach(visit); }
  visit(tree);
  const label = elements.find(node => node.type === 'label' && node.children.includes('dynamic_form.vless.upgrade.hybridReality'));
  assert.ok(label, 'REALITY exposes an explicit compatibility switch');
  const checkbox = label.children.find(node => node?.type === 'input');
  assert.equal(checkbox.props.checked, false);
  checkbox.props.onChange({ target: { checked: true } });
  assert.equal(values.reality_settings.support_x25519mlkem768, true);
  assert.equal(values.reality_settings.private_key, 'secret');
  assert.equal(values.backend, undefined);
  assert.equal(values.utls, undefined);
  checkbox.props.onChange({ target: { checked: false } });
  assert.equal(values.reality_settings.support_x25519mlkem768, false);
});
