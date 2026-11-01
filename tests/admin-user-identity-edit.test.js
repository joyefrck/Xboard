const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const bundle = fs.readFileSync(path.join(root, 'public/assets/admin/assets/index.js'), 'utf8');
const start = bundle.indexOf('function Ji(){');
const editor = bundle.slice(start, bundle.indexOf('function Hp(){', start));
const helperStart = bundle.indexOf('function xboardAdminUserIdentityField(');
const helpers = helperStart < 0 ? '' : bundle.slice(helperStart, start);
const identityFields = ['email', 'invite_user_email', 'password'];
const labels = { email: 'email', invite_user_email: 'inviter_email', password: 'password' };

function setup() {
  const user = { id: 41, email: 'member@example.test', invite_user: null, commission_type: 0, u: 0, d: 0 };
  const store = { isOpen: true, editingUser: user, setIsOpen(value) { this.isOpen = value; }, refreshData() {} };
  const values = {}, dirtyFields = {}, states = [], effects = [];
  let hook = 0, pending = [], tree;
  const calls = [];
  const form = {
    control: {}, formState: { dirtyFields },
    reset(data) { Object.keys(values).forEach(key => delete values[key]); Object.assign(values, data); Object.keys(dirtyFields).forEach(key => delete dirtyFields[key]); },
    resetField(name, { defaultValue } = {}) { values[name] = defaultValue; delete dirtyFields[name]; },
    handleSubmit(callback) { return () => callback({ ...values }); },
    setValue(name, value, options = {}) { values[name] = value; if (options.shouldDirty) dirtyFields[name] = true; }
  };
  const jsx = (type, props) => typeof type === 'function' ? type(props) : { type, props };
  const context = {
    ...Object.fromEntries('rl pn gn fn jn Re g p f j k E Hi P He q ee X se Z U ls rs Ze Rs Ls te $s'.split(' ').map(key => [key, key])),
    e: { jsx, jsxs: jsx }, Up: {}, Le: value => value, De: () => form,
    M: () => ({ t: key => key }), il: () => ({ ...store, setIsOpen: value => store.setIsOpen(value) }),
    xboardAdminAccountStatus: () => 'normal', b: (...parts) => parts.filter(Boolean).join(' '), re: String,
    Ts: { getList: async () => ({ data: [] }) }, xboardAdminTrafficPackageApi: { getList: async () => ({ data: [] }) },
    Ys: { update: async data => { calls.push(JSON.parse(JSON.stringify(data))); return { data: true }; } },
    A: { success() {}, error(message) { throw new Error(message); } },
    m: {
      useState(initial) { const index = hook++; if (!(index in states)) states[index] = initial; return [states[index], value => { states[index] = typeof value === 'function' ? value(states[index]) : value; }]; },
      useEffect(callback, deps) { const index = hook++; if (!effects[index] || deps.some((value, i) => value !== effects[index][i])) { effects[index] = deps; pending.push(callback); } }
    }
  };
  vm.createContext(context);
  vm.runInContext(`${helpers}${editor};this.renderEditor=Ji`, context);
  function render() {
    hook = 0; tree = context.renderEditor();
    if (pending.length) { const run = pending; pending = []; run.forEach(callback => callback()); return render(); }
    return tree;
  }
  function nodes(node) {
    if (Array.isArray(node)) return node.flatMap(nodes);
    if (!node || typeof node !== 'object') return [];
    return [node, ...nodes(node.props?.children)];
  }
  function fieldTree(name) {
    const control = nodes(render()).find(node => node.type === 'g' && node.props.name === name);
    assert.ok(control, `${name} field exists`);
    return control.props.render({ field: { name, value: values[name], onChange(value) { values[name] = value?.target ? value.target.value : value; dirtyFields[name] = true; } } });
  }
  function input(name) { return nodes(fieldTree(name)).find(node => node.type === 'k'); }
  function button(name, action) {
    const label = `edit.form.${action}${action.includes('password') ? '' : `: edit.form.${labels[name]}`}`;
    const result = nodes(fieldTree(name)).find(node => node.type === 'P' && node.props['aria-label'] === label);
    assert.ok(result, `${name} has ${action} button`);
    return result;
  }
  function change(name, value) { input(name).props.onChange({ target: { value } }); render(); }
  async function save() { nodes(render()).find(node => node.type === 'P' && node.props.children === 'edit.form.submit').props.onClick(); await Promise.resolve(); }
  render();
  return { user, store, form, values, dirtyFields, calls, render, input, button, change, save };
}

test('all identity fields start locked with no synthetic inviter or password', () => {
  const app = setup();
  for (const name of identityFields) {
    assert.equal(app.input(name).props.disabled, true, `${name} starts disabled`);
    assert.equal(app.button(name, 'edit_field').props.type, 'button');
    app.change(name, 'browser-autofill@example.test');
  }
  assert.equal(app.values.email, app.user.email);
  assert.equal(app.values.invite_user_email, null);
  assert.equal(app.values.password, null);
});

test('each pencil unlocks only its own field and cancellation restores the original', () => {
  const app = setup();
  app.button('email', 'edit_field').props.onClick();
  assert.equal(app.input('email').props.disabled, false);
  assert.equal(app.input('invite_user_email').props.disabled, true);
  assert.equal(app.input('password').props.disabled, true);
  app.change('email', 'changed@example.test');
  app.button('email', 'cancel_edit').props.onClick();
  assert.equal(app.input('email').props.disabled, true);
  assert.equal(app.values.email, app.user.email);
  assert.equal(app.dirtyFields.email, undefined);
});

test('eye toggles only the new password and cancellation clears it', () => {
  const app = setup();
  assert.equal(app.button('password', 'show_password').props.disabled, true);
  app.button('password', 'edit_field').props.onClick();
  app.change('password', 'New-password-41');
  assert.equal(app.input('password').props.type, 'password');
  app.button('password', 'show_password').props.onClick();
  assert.equal(app.input('password').props.type, 'text');
  assert.equal(app.input('password').props.value, 'New-password-41');
  app.button('password', 'hide_password').props.onClick();
  assert.equal(app.input('password').props.type, 'password');
  app.button('password', 'cancel_edit').props.onClick();
  assert.equal(app.values.password, null);
  assert.equal(app.input('password').props.disabled, true);
});

test('saving other fields omits even injected dirty identity values', async () => {
  const app = setup();
  for (const name of identityFields) { app.values[name] = 'injected@example.test'; app.dirtyFields[name] = true; }
  app.values.balance = 25; app.dirtyFields.balance = true;
  await app.save();
  assert.equal(app.calls.length, 1);
  assert.deepEqual(app.calls[0], { id: 41, expected_custom_order_id: null, balance: 25 });
});

test('explicit edits submit independently, including explicitly clearing an inviter', async () => {
  const app = setup();
  app.store.editingUser = { ...app.user, invite_user: { email: 'real-inviter@example.test' } };
  app.render();
  assert.equal(app.input('invite_user_email').props.value, 'real-inviter@example.test');
  app.button('invite_user_email', 'edit_field').props.onClick();
  app.change('invite_user_email', '');
  app.button('password', 'edit_field').props.onClick();
  app.change('password', 'New-password-41');
  await app.save();
  assert.deepEqual(app.calls[0], { id: 41, expected_custom_order_id: null, invite_user_email: null, password: 'New-password-41' });
});

test('an enabled but empty password is never submitted', async () => {
  const app = setup();
  app.button('password', 'edit_field').props.onClick(); app.change('password', '');
  await app.save();
  assert.equal(Object.hasOwn(app.calls[0], 'password'), false);
});

test('an explicit email edit does not submit the other locked identity fields', async () => {
  const app = setup();
  app.button('email', 'edit_field').props.onClick();
  app.change('email', 'changed@example.test');
  app.values.password = 'injected-password'; app.dirtyFields.password = true;
  app.values.invite_user_email = 'injected@example.test'; app.dirtyFields.invite_user_email = true;
  await app.save();
  assert.deepEqual(app.calls[0], { id: 41, expected_custom_order_id: null, email: 'changed@example.test' });
});

test('reopening or switching users resets every lock, draft, and password visibility', () => {
  const app = setup();
  for (const mode of ['reopen', 'switch']) {
    app.button('password', 'edit_field').props.onClick(); app.change('password', 'Draft-password');
    app.button('password', 'show_password').props.onClick();
    app.button('email', 'edit_field').props.onClick(); app.change('email', 'draft@example.test');
    if (mode === 'reopen') { app.store.isOpen = false; app.render(); app.store.isOpen = true; }
    else app.store.editingUser = { ...app.user, id: 42, email: 'second@example.test' };
    app.render();
    for (const name of identityFields) assert.equal(app.input(name).props.disabled, true);
    assert.equal(app.values.password, null);
    assert.equal(app.values.email, app.store.editingUser.email);
    assert.equal(app.input('password').props.type, 'password');
  }
});

test('actual admin update preserves omitted identity fields and supports explicit inviter changes', () => {
  const result = spawnSync('php', ['tests/admin-user-identity-edit.php'], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /admin user identity edit: \d+ checks passed/);
});

test('identity controls use translated labels in the actual user-edit namespace', () => {
  for (const locale of ['zh-CN', 'en-US', 'ko-KR']) {
    const context = { window: {} };
    vm.runInNewContext(fs.readFileSync(path.join(root, `public/assets/admin/locales/${locale}.js`), 'utf8'), context);
    const form = context.window.XBOARD_TRANSLATIONS[locale].user.edit.form;
    for (const key of ['edit_field', 'cancel_edit', 'show_password', 'hide_password', 'password_locked_placeholder']) {
      assert.ok(form[key], `${locale}: user.edit.form.${key} exists`);
    }
  }
});
