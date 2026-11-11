const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { JSDOM } = require('jsdom');
const security = require('../theme/ElephantRoute/assets/elephant-route-security.js');

const settle = async () => { for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve)); };
function fixture() {
  const dom = new JSDOM('<div class="er-profile-grid"><section class="er-profile-password"><form><input><input><input><button type="submit">保存密码</button></form></section><section class="er-profile-danger">注销账号</section></div>', { url: 'https://fixture.example.test/app#/profile' });
  const w = dom.window;
  w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
  w.HTMLDialogElement.prototype.close = function () { this.open = false; };
  w.localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer fixture-only' }));
  const state = { rows: [
    { id: '1', is_current: true, client_label: 'Chrome', client_type: 'browser', client_os: 'macOS', login_ip: '203.0.113.10', last_ip: '203.0.113.10', created_at: '2026-11-11T00:00:00Z' },
    { id: '2', client_label: 'App 客户端', client_type: 'app', client_os: 'Android', login_ip: '2001:db8::1' },
    { id: '3', client_label: '<img src=x onerror=alert(1)>', client_type: 'unknown' }
  ], calls: [], changed: false, redirects: [] };
  const win = {
    document: w.document, localStorage: w.localStorage, Event: w.Event, MutationObserver: w.MutationObserver,
    location: { hash: '#/profile', pathname: '/app', replace: url => state.redirects.push(url) },
    addEventListener: w.addEventListener.bind(w), removeEventListener: w.removeEventListener.bind(w),
    setInterval: w.setInterval.bind(w), clearInterval: w.clearInterval.bind(w),
    fetch: async (path, options) => {
      const body = options.body ? JSON.parse(options.body) : null;
      state.calls.push({ path, options, body });
      let status = 200; let data = true; let error;
      if (path.endsWith('/getActiveSession')) data = state.rows;
      if (path.endsWith('/removeActiveSession')) state.rows = state.rows.filter(row => row.id !== body.session_id);
      if (path.endsWith('/removeAllActiveSessions')) state.rows = [];
      if (path.endsWith('/sendPasswordChangeCode')) data = { masked_email: 'f***@example.test', cooldown_seconds: 60 };
      if (path.endsWith('/changePassword')) {
        if (!body.email_code) { status = 428; error = { code: 'EMAIL_VERIFICATION_REQUIRED', masked_email: 'f***@example.test' }; }
        else if (body.email_code !== '123456') status = 400;
        else state.changed = true;
      }
      return { ok: status === 200, status, json: async () => ({ status: status === 200 ? 'success' : 'fail', data, error, message: status === 400 ? '邮箱验证码错误' : '需要邮箱验证' }) };
    }
  };
  const unmount = security.mount(win);
  const cleanup = () => { unmount(); dom.window.close(); };
  return { win, doc: w.document, w, state, cleanup };
}

test('sessions are mounted before account deletion, escaped and not duplicated', async () => {
  const f = fixture();
  try {
    await settle();
    assert.equal(f.doc.querySelectorAll('.er-session-card').length, 1);
    assert.equal(f.doc.querySelector('.er-session-card').nextElementSibling.className, 'er-profile-danger');
    assert.equal(f.doc.querySelectorAll('.er-session-row').length, 3);
    assert.match(f.doc.querySelector('.er-session-card').textContent, /当前设备/);
    assert.match(f.doc.querySelector('.er-session-card').textContent, /App 客户端/);
    assert.equal(f.doc.querySelectorAll('img').length, 0);
    assert.equal(f.state.calls[0].options.headers.Authorization, 'Bearer fixture-only');
    f.doc.body.appendChild(f.doc.createElement('p')); await settle();
    assert.equal(f.state.calls.length, 1);
  } finally { f.cleanup(); }
});

test('single App logout revokes only that session and reloads the list', async () => {
  const f = fixture();
  try {
    await settle();
    f.doc.querySelectorAll('.er-session-row button')[1].click();
    f.doc.querySelector('.er-security-dialog .er-security-danger').click();
    await settle();
    assert.equal(f.state.rows.length, 2);
    assert.ok(!f.state.rows.find(row => row.id === '2'));
    assert.equal(f.doc.querySelectorAll('.er-session-row').length, 2);
    assert.equal(f.state.redirects.length, 0);
  } finally { f.cleanup(); }
});

test('logout all clears local authentication and returns to login', async () => {
  const f = fixture();
  try {
    await settle();
    f.doc.querySelector('.er-session-controls .er-security-danger').click();
    f.doc.querySelector('.er-security-dialog .er-security-danger').click();
    await settle();
    assert.equal(f.state.rows.length, 0);
    assert.equal(f.win.localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN'), null);
    assert.deepEqual(f.state.redirects, ['/app#/login']);
  } finally { f.cleanup(); }
});

test('new IP change requires modal code and survives an incorrect attempt', async () => {
  const f = fixture();
  try {
    await settle();
    const form = f.doc.querySelector('.er-profile-password form');
    const inputs = form.querySelectorAll('input');
    ['fixture-old-password', 'fixture-new-password', 'fixture-new-password'].forEach((value, i) => { inputs[i].value = value; });
    form.dispatchEvent(new f.w.Event('submit', { bubbles: true, cancelable: true }));
    await settle();
    const modal = f.doc.querySelector('.er-security-dialog');
    assert.ok(modal.open);
    assert.equal(modal.querySelector('h2').textContent, '安全校验');
    assert.doesNotMatch(modal.textContent, /\bIP\b|可信|30\s*天|有效期|触发/i);
    assert.match(modal.textContent, /f\*\*\*@example.test/);
    modal.querySelector('.er-security-code-line button').click(); await settle();
    assert.match(modal.querySelector('.er-security-code-line button').textContent, /60 秒后重发/);
    assert.doesNotMatch(modal.textContent, /\bIP\b|可信|30\s*天|有效期|触发/i);
    const code = modal.querySelector('input');
    const codeForm = modal.querySelector('form');
    code.value = '999999'; codeForm.dispatchEvent(new f.w.Event('submit', { bubbles: true, cancelable: true }));
    await settle();
    assert.equal(f.state.changed, false);
    assert.match(modal.querySelector('.er-security-status').textContent, /验证码错误/);
    code.value = '123456'; codeForm.dispatchEvent(new f.w.Event('submit', { bubbles: true, cancelable: true }));
    await settle();
    assert.equal(f.state.changed, true);
    assert.equal(f.doc.querySelector('dialog'), null);
    assert.equal(f.win.localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN'), null);
    assert.equal(f.win.localStorage.length, 0);
    assert.deepEqual(f.state.redirects, ['/app#/login']);
    assert.ok(Array.from(inputs).every(input => !input.value));
  } finally { f.cleanup(); }
});

test('cancel and leaving profile never submit a password change', async () => {
  const f = fixture();
  try {
    await settle();
    const form = f.doc.querySelector('form');
    ['fixture-old-password', 'fixture-new-password', 'fixture-new-password'].forEach((value, i) => { form.querySelectorAll('input')[i].value = value; });
    form.dispatchEvent(new f.w.Event('submit', { bubbles: true, cancelable: true })); await settle();
    f.doc.querySelector('.er-security-dialog-actions button').click(); await settle();
    assert.equal(f.state.changed, false);
    assert.equal(f.doc.activeElement, form.querySelector('button[type="submit"]'));
    assert.equal(f.state.calls.filter(call => call.body?.email_code).length, 0);
    f.win.location.hash = '#/dashboard'; f.w.dispatchEvent(new f.w.Event('hashchange')); await settle();
    assert.equal(f.doc.querySelector('.er-session-card'), null);
  } finally { f.cleanup(); }
});

test('security assets are mirrored and explicitly cache busted', () => {
  for (const file of ['elephant-route-security.js', 'elephant-route-security.css']) {
    assert.equal(fs.readFileSync(`theme/ElephantRoute/assets/${file}`, 'utf8'), fs.readFileSync(`public/theme/ElephantRoute/assets/${file}`, 'utf8'));
    assert.match(fs.readFileSync('theme/ElephantRoute/dashboard.blade.php', 'utf8'), new RegExp(file.replaceAll('.', '\\.') + '\\?v=.*er20261111security2'));
  }
  assert.equal(fs.readFileSync('theme/ElephantRoute/dashboard.blade.php', 'utf8'), fs.readFileSync('public/theme/ElephantRoute/dashboard.blade.php', 'utf8'));
});

test('late unauthorized responses cannot log out or overwrite another account', async () => {
  const f = fixture();
  try {
    await settle();
    let release;
    let calls = 0;
    f.win.fetch = async () => {
      if (++calls === 1) return new Promise(resolve => { release = () => resolve({ ok: false, status: 403, json: async () => ({ status: 'fail', message: 'old session expired' }) }); });
      return { ok: true, status: 200, json: async () => ({ status: 'success', data: [{ id: '900', client_label: 'New account browser' }] }) };
    };
    f.doc.querySelector('.er-session-controls button').click();
    f.win.localStorage.setItem('VUE_NAIVE_ACCESS_TOKEN', JSON.stringify({ value: 'Bearer new-fixture' }));
    f.doc.body.appendChild(f.doc.createElement('p')); await settle();
    release(); await settle();
    assert.equal(f.state.redirects.length, 0);
    assert.match(f.win.localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN'), /new-fixture/);
    assert.match(f.doc.querySelector('.er-session-card').textContent, /New account browser/);
    assert.equal(f.doc.querySelectorAll('.er-session-row').length, 1);
  } finally { f.cleanup(); }
});
