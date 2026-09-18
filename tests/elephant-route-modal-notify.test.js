const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../theme/ElephantRoute/assets/elephant-route-dashboard-v2.js'), 'utf8');
const notifySource = source.slice(source.indexOf('  function notify('), source.indexOf('  function navigate('));

function notifyFixture(modalOpen, nativeMessages = true) {
  const toasts = [];
  const nativeCalls = [];
  const timers = [];
  const context = {
    getModal: () => modalOpen === null ? null : { getAttribute: () => String(!modalOpen) },
    document: {
      getElementById: () => null,
      createElement: () => ({ setAttribute(name, value) { this[name] = value; }, remove() { this.removed = true; } }),
      body: { appendChild: (toast) => toasts.push(toast) }
    },
    global: {
      $message: nativeMessages ? { success: (message) => nativeCalls.push(message), error: (message) => nativeCalls.push(message) } : null,
      setTimeout: (callback) => timers.push(callback)
    }
  };
  vm.runInNewContext(notifySource + '; this.notify = notify;', context);
  return { ...context, toasts, nativeCalls, timers };
}

test('An open dashboard modal uses a visible toast for success and failure instead of the obscured native message', () => {
  for (const type of ['success', 'error']) {
    const fixture = notifyFixture(true);
    fixture.notify(type, '复制结果');
    assert.deepEqual(fixture.nativeCalls, []);
    assert.equal(fixture.toasts.length, 1);
    assert.match(fixture.toasts[0].className, /\bis-visible\b/);
    assert.equal(fixture.toasts[0].role, type === 'error' ? 'alert' : 'status');
    assert.equal(fixture.toasts[0].textContent, '复制结果');
    fixture.timers[0]();
    assert.equal(fixture.toasts[0].removed, true);
  }
});

test('Closed or absent modals preserve native messages, while the fallback toast is visible', () => {
  for (const modalOpen of [false, null]) {
    const fixture = notifyFixture(modalOpen);
    fixture.notify('success', '复制成功');
    assert.deepEqual(fixture.nativeCalls, ['复制成功']);
    assert.equal(fixture.toasts.length, 0);
  }
  const fallback = notifyFixture(false, false);
  fallback.notify('success', '复制成功');
  assert.match(fallback.toasts[0].className, /\bis-visible\b/);
});
