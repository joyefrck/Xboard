const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'public/assets/admin/assets/user-blacklist.js'), 'utf8');
const moduleReady = import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));

// Minimal hook runner exercises read-only blacklist actions and API contracts without a DOM.
function harness(initial = []) {
  let cursor = 0;
  const state = [...initial];
  const effects = [];
  const React = {
    createElement(type, props, ...children) { return { type, props: { ...props, children: children.flat() } }; },
    useState(value) {
      const index = cursor++;
      if (!(index in state)) state[index] = value;
      return [state[index], next => { state[index] = typeof next === 'function' ? next(state[index]) : next; }];
    },
    useEffect(effect) { effects.push(effect); },
    useRef() { return { current: null }; },
    useId() { return 'fixture-id'; },
  };
  return { React, effects, state, render(Component, props) { cursor = 0; effects.length = 0; return Component(props); } };
}
function find(node, predicate) {
  if (!node || typeof node !== 'object') return null;
  if (predicate(node)) return node;
  for (const child of node.props?.children || []) {
    const result = find(child, predicate);
    if (result) return result;
  }
  return null;
}
const layout = { Root: 'root', Header: 'header', Main: 'main', Search: 'search', Language: 'language', User: 'user' };
function setupWindow() { global.window = { settings: { secure_path: 'fixture-admin' }, confirm: () => true }; }

test('blacklist timestamps consistently show the Shanghai unlock time', async () => {
  const { formatTime, statusLabels } = await moduleReady;
  assert.equal(formatTime(null), '—');
  assert.match(formatTime(Date.UTC(2026, 9, 23, 0) / 1000), /2026\/10\/23 08:00:00/);
  assert.equal(statusLabels.muted, '工单禁言中');
});

test('blacklist page requests filters through the authenticated admin client', async () => {
  setupWindow();
  const { createBlacklistPage } = await moduleReady;
  const hook = harness(['', 'sample@example.test', 'muted', 2]);
  const requests = [];
  const api = { async get(url, config) { requests.push({ url, config }); return { data: { data: [], total: 0 } }; } };
  const Page = createBlacklistPage({ React: hook.React, api, layout });
  hook.render(Page);
  const cleanup = hook.effects[0]();
  await new Promise(resolve => setImmediate(resolve));
  cleanup();
  assert.equal(requests[0].url, 'fixture-admin/blacklist/fetch');
  assert.deepEqual(requests[0].config.params, { search: 'sample@example.test', status: 'muted', page: 2 });
  assert.equal(find(hook.render(Page), n => n.props.role === 'status' && n.props.children[0] === '正在读取黑名单…'), null, 'request finishes loading');
});

test('blacklist keeps evidence actions and has no manual account controls', async () => {
  setupWindow();
  const { createBlacklistPage } = await moduleReady;
  const user = { id: 1, email: 'fixture@example.test', status: 'muted', banned: false,
    ticket_abuse_count: 1, ticket_muted_until: 1800000000 };
  const result = { data: [user], total: 1, current_page: 1, last_page: 1,
    review: { enabled: true, configured: true, model: 'fixture-model' } };
  const hook = harness(['', '', 'all', 1, 0, result, false, '']);
  const Page = createBlacklistPage({ React: hook.React, api: {}, layout });
  let tree = hook.render(Page);
  assert.equal(find(tree, n => n.type === 'button' && /封号|封禁账号/.test(n.props.children[0])), null);
  const history = find(tree, n => n.type === 'button' && n.props.children[0] === '查看记录');
  assert.ok(history, 'evidence action remains available');
  history.props.onClick();
  tree = hook.render(Page);
  assert.equal(find(tree, n => typeof n.type === 'function' && n.type.name === 'History').props.user, user);
});

test('admin route and every shipped locale expose the blacklist entry', () => {
  const bundle = fs.readFileSync(path.join(root, 'public/assets/admin/assets/index.js'), 'utf8');
  assert.ok(bundle.includes('path:"blacklist",lazy:async()=>'));
  assert.ok(bundle.includes('href:"/user/blacklist"'));
  for (const locale of ['zh-CN', 'en-US', 'ko-KR']) {
    const context = { window: {} };
    require('node:vm').runInNewContext(fs.readFileSync(path.join(root, 'public/assets/admin/locales/' + locale + '.js'), 'utf8'), context);
    const translations = context.window.XBOARD_TRANSLATIONS[locale];
    assert.ok(translations.nav.blacklistManagement);
    assert.ok(translations.user.columns.status_text.ticket_muted);
    assert.ok(translations.user.status.ticket_muted);
  }
  assert.doesNotMatch(source, /sk-[a-zA-Z0-9]{10,}/);
  assert.doesNotMatch(source, /dangerouslySetInnerHTML|innerHTML\s*=/, 'evidence renders as text');
});
