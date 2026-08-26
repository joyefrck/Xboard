const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('Aurora user-page assets are mirrored and cache-busted in both theme views', () => {
  const themeScript = readRepoFile('theme/ElephantRoute/assets/elephant-route-pages-v2.js');
  const publicScript = readRepoFile('public/theme/ElephantRoute/assets/elephant-route-pages-v2.js');
  const themeStyles = readRepoFile('theme/ElephantRoute/assets/elephant-route-pages-v2.css');
  const publicStyles = readRepoFile('public/theme/ElephantRoute/assets/elephant-route-pages-v2.css');
  const themeView = readRepoFile('theme/ElephantRoute/dashboard.blade.php');
  const publicView = readRepoFile('public/theme/ElephantRoute/dashboard.blade.php');

  assert.equal(publicScript, themeScript);
  assert.equal(publicStyles, themeStyles);
  assert.equal(publicView, themeView);

  for (const view of [themeView, publicView]) {
    assert.match(view, /elephant-route-pages-v2\.css\?v=\{\{\$version\}\}-er20260826pagesV2Aurora9/);
    assert.match(view, /elephant-route-pages-v2\.js\?v=\{\{\$version\}\}-er20260826pagesV2Aurora9/);
  }

  const publicIgnore = readRepoFile('public/theme/.gitignore');
  assert.match(publicIgnore, /!ElephantRoute\/assets\/elephant-route-pages-v2\.css/);
  assert.match(publicIgnore, /!ElephantRoute\/assets\/elephant-route-pages-v2\.js/);
});

test('Aurora user-page route markers are deterministic and exclude unrelated pages', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-pages-v2.js'));
  const expected = {
    '#/plan': 'plan',
    '#/invite': 'invite',
    '#/knowledge': 'knowledge',
    '#/order': 'order',
    '#/profile': 'profile',
    '#/ticket': 'ticket',
    '#/node': 'node'
  };

  for (const [hash, page] of Object.entries(expected)) {
    assert.equal(helpers.resolvePageKey(hash), page);
    assert.equal(helpers.resolvePageKey(`${hash}?from=test`), page);
  }

  for (const hash of ['#/dashboard', '#/login', '#/register', '#/download', '#/unknown']) {
    assert.equal(helpers.resolvePageKey(hash), '');
  }

  assert.equal(helpers.resolvePageKey('#/plan/2'), 'checkout');
  assert.equal(helpers.resolvePageKey('#/plan/2?period=month'), 'checkout');
  assert.equal(helpers.resolvePageKey('#/order/2026082610081951220190389'), 'order-detail');

  assert.equal(helpers.isUserShellRoute('#/dashboard'), true);
  assert.equal(helpers.isUserShellRoute('#/profile'), true);
  assert.equal(helpers.isUserShellRoute('#/plan/2'), true);
  assert.equal(helpers.isUserShellRoute('#/order/2026082610081951220190389'), true);
  assert.equal(helpers.isUserShellRoute('#/login'), false);
  assert.equal(helpers.isUserShellRoute('#/register'), false);
});

test('Aurora user-page runtime mounts and clears body markers idempotently', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-pages-v2.js'));
  const attributes = new Map();
  const listeners = new Map();
  let observedMutation;
  let observerDisconnected = false;
  const body = {
    setAttribute(name, value) { attributes.set(name, value); },
    removeAttribute(name) { attributes.delete(name); }
  };
  const fakeWindow = {
    location: { hash: '#/plan' },
    document: { body, documentElement: {} },
    MutationObserver: class {
      constructor(handler) { observedMutation = handler; }
      observe() {}
      disconnect() { observerDisconnected = true; }
    },
    addEventListener(name, handler) { listeners.set(name, handler); },
    removeEventListener(name) { listeners.delete(name); }
  };

  const firstUnmount = helpers.mount(fakeWindow);
  const secondUnmount = helpers.mount(fakeWindow);
  assert.equal(attributes.get('data-er-page'), 'plan');
  assert.equal(attributes.get('data-er-user-shell'), 'true');
  assert.equal(listeners.size, 2);

  // Vue Router can update the hash through history state without emitting
  // hashchange. A subsequent DOM render must still synchronize the marker.
  fakeWindow.location.hash = '#/profile';
  observedMutation();
  assert.equal(attributes.get('data-er-page'), 'profile');

  fakeWindow.location.hash = '#/dashboard';
  listeners.get('hashchange')();
  assert.equal(attributes.has('data-er-page'), false);
  assert.equal(attributes.get('data-er-user-shell'), 'true');

  fakeWindow.location.hash = '#/login';
  listeners.get('hashchange')();
  assert.equal(attributes.has('data-er-page'), false);
  assert.equal(attributes.has('data-er-user-shell'), false);

  secondUnmount();
  firstUnmount();
  assert.equal(listeners.size, 0);
  assert.equal(attributes.size, 0);
  assert.equal(observerDisconnected, true);
});

test('Aurora invite label normalizer shortens only the invite copy-link action', () => {
  const helpers = require(path.join(repoRoot, 'theme/ElephantRoute/assets/elephant-route-pages-v2.js'));
  const labels = ['复制链接', '生成邀请码', '复制链接'];
  const buttons = labels.map((text) => ({
    textContent: text,
    attributes: new Map(),
    querySelector() { return null; },
    setAttribute(name, value) { this.attributes.set(name, value); }
  }));
  const fakeWindow = {
    location: { hash: '#/invite' },
    document: { querySelectorAll() { return buttons; } }
  };

  helpers.normalizeInviteCopyLabels(fakeWindow);
  assert.deepEqual(buttons.map((button) => button.textContent), ['复制', '生成邀请码', '复制']);
  assert.equal(buttons[0].attributes.get('aria-label'), '复制邀请码');

  buttons[0].textContent = '复制链接';
  fakeWindow.location.hash = '#/profile';
  helpers.normalizeInviteCopyLabels(fakeWindow);
  assert.equal(buttons[0].textContent, '复制链接');
});

test('Aurora styles are scoped to route markers and cover all requested surfaces', () => {
  const styles = readRepoFile('theme/ElephantRoute/assets/elephant-route-pages-v2.css');

  assert.match(styles, /body\[data-er-page\]/);
  assert.match(styles, /body\[data-er-page="plan"\]/);
  assert.match(styles, /body\[data-er-page="invite"\]/);
  assert.match(styles, /body\[data-er-page="knowledge"\]/);
  assert.match(styles, /body\[data-er-page="order"\]/);
  assert.match(styles, /body\[data-er-page="profile"\]/);
  assert.match(styles, /body\[data-er-page="ticket"\]/);
  assert.match(styles, /body\[data-er-page="node"\]/);
  assert.match(styles, /body\[data-er-page="checkout"\]/);
  assert.match(styles, /body\[data-er-page="order-detail"\]/);

  for (const selector of ['.n-card', '.n-data-table', '.n-list', '.n-input', '.n-switch']) {
    assert.match(styles, new RegExp(selector.replace('.', '\\.')));
  }

  assert.match(styles, /body\[data-er-user-shell="true"\][\s\S]*\.n-dialog\.n-modal/);
  assert.match(styles, /body\[data-er-user-shell="true"\][\s\S]*\.n-card\.n-modal/);
  assert.match(styles, /\.n-modal-mask/);
  assert.match(styles, /backdrop-filter: blur\(20px\)/);
  assert.match(styles, /font-size: 14px/);
  assert.match(styles, /--n-padding: 0 14px/);
  assert.match(styles, /data-er-page="invite"[\s\S]*\.n-data-table-td:has\(\.n-button\)/);
  assert.match(styles, /data-er-page="order"[\s\S]*\.n-data-table-td:last-child \.n-button/);
  assert.match(styles, /data-er-page="ticket"[\s\S]*\.n-data-table-td:last-child \.n-button/);
  assert.match(styles, /background: transparent !important;[\s\S]*box-shadow: none;/);
  assert.match(styles, /\.n-radio-button::after/);
  assert.match(styles, /data-er-page="plan"[\s\S]*\.n-radio-group \{[\s\S]*overflow: visible;[\s\S]*isolation: isolate;/);
  assert.match(styles, /\.n-radio-group \{[\s\S]*box-sizing: border-box;[\s\S]*height: auto !important;[\s\S]*min-height: 46px;[\s\S]*align-items: center;/);
  assert.match(styles, /\.n-radio-button \{[\s\S]*height: 36px !important;[\s\S]*min-height: 36px;/);
  assert.match(styles, /section\.card-container \{[\s\S]*display: grid !important;[\s\S]*grid-template-columns: repeat\(3, minmax\(0, 1fr\)\);/);
  assert.match(styles, /section\.card-container \.card-item \{[\s\S]*width: 100% !important;[\s\S]*min-width: 0;/);
  assert.match(styles, /@media \(max-width: 767px\)[\s\S]*section\.card-container \{[\s\S]*display: flex !important;/);
  assert.match(styles, /\.n-dialog__icon[\s\S]*display: inline-flex !important;[\s\S]*align-items: center;[\s\S]*justify-content: center;/);
  assert.match(styles, /body\[data-er-user-shell="true"\] \.n-card\.n-modal \{/);
  assert.match(styles, /data-er-page="checkout"[\s\S]*\.bg-gray-800/);
  assert.match(styles, /data-er-page="order-detail"[\s\S]*\.bg-gray-800/);
  assert.match(styles, /\.bg-gray-800 \.text-white:not\(\.n-button\)/);
  assert.match(styles, /\.bg-gray-800 \.text-gray-200/);
  assert.match(styles, /input\[placeholder="有优惠券？"\]/);
  assert.match(styles, /\[class~="color-#f8f9fa"\]/);
  assert.match(styles, /\[class~="color-#f8f9fa41"\]/);
  assert.match(styles, /@media \(prefers-reduced-motion: reduce\)/);
  assert.doesNotMatch(styles, /body:not\(/);
  assert.doesNotMatch(styles, /html\.dark|\.dark\s/);
});
