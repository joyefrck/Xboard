const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const script = read('theme/ElephantRoute/assets/elephant-route-dashboard.js');

function menu(appleMobile = false, desktop = true) {
  const dom = new JSDOM('<ul><li class="n-list-item"><div><svg></svg><span>复制订阅地址</span></div></li><li class="n-list-item"><div><img><span>导入到 Clash Meta</span></div></li><li class="n-list-item"><div><img><span>导入到 Hiddify</span></div></li></ul>');
  const window = { settings: {}, location: { href: '' } };
  const url = 'https://example.test/订阅?token=fixture&flag=meta#测试';
  const source = script.slice(script.indexOf('  function getThemeAssetUrl('), script.indexOf('  function findSubscribeShortcut('));
  const helpers = new Function('window', 'document', 'getNodeText', 'replaceText', 'isAppleMobilePlatform', 'isDesktopPlatform', 'fetchSubscribeInfo', 'notify', 'FLCLASH_ICON_PATH', 'CLASH_MI_ICON_PATH', 'CLASH_VERGE_ICON_PATH', source + '; return { enhanceFlClashSubscribeOption, enhanceClashMiSubscribeOption, enhanceDesktopClashVergeSubscribeOption, buildFlClashImportUrl, buildClashMiImportUrl };')(
    window, dom.window.document, node => node.textContent.trim(),
    (node, old, value) => { const walker = dom.window.document.createTreeWalker(node, 4); let text; while ((text = walker.nextNode())) text.nodeValue = text.nodeValue.replace(old, value); },
    () => appleMobile, () => desktop, async () => ({ subscribeUrl: url, title: '大象 & 测试' }),
    () => assert.fail('unexpected error'), 'images/flclash.png', 'images/clash-mi.png', 'images/clash-verge.png'
  );
  function enhance() {
    helpers.enhanceDesktopClashVergeSubscribeOption();
    helpers.enhanceFlClashSubscribeOption();
    helpers.enhanceClashMiSubscribeOption();
  }
  return { dom, window, helpers, enhance, url };
}

test('desktop menu places FlClash and Clash Mi after Clash Verge without duplicates or Karing', () => {
  const { dom, enhance } = menu();
  enhance(); enhance();
  const rows = [...dom.window.document.querySelectorAll('li')];
  assert.deepEqual(rows.map(row => row.textContent), ['复制订阅地址', '导入到 Clash Verge', '导入到 FlClash', '导入到 Clash Mi', '导入到 Hiddify']);
  for (const name of ['FlClash', 'Clash Mi']) {
    const row = rows.find(row => row.textContent === '导入到 ' + name);
    assert.equal(row.querySelector('img').alt, name);
    assert.equal(row.querySelectorAll('img').length, 1);
    assert.equal(row.querySelector('svg'), null);
  }
});

test('Android and Linux keep both new import choices', () => {
  const { dom, enhance } = menu(false, false);
  enhance();
  assert.ok(dom.window.document.querySelector('[aria-label="导入到 FlClash"]'));
  assert.ok(dom.window.document.querySelector('[aria-label="导入到 Clash Mi"]'));
});

test('iOS retains Clash Mi without offering unsupported FlClash', () => {
  const { dom, enhance } = menu(true, false);
  enhance(); enhance();
  assert.equal(dom.window.document.querySelector('[aria-label="导入到 FlClash"]'), null);
  assert.equal(dom.window.document.querySelectorAll('[aria-label="导入到 Clash Mi"]').length, 1);
});

test('new rows launch their dedicated scheme with the complete subscription URL', async () => {
  for (const [name, scheme] of [['FlClash', 'flclash:'], ['Clash Mi', 'clashmi:']]) {
    const { dom, enhance, window, url } = menu();
    enhance();
    dom.window.document.querySelector('[aria-label="导入到 ' + name + '"]').click();
    await Promise.resolve();
    const target = new URL(window.location.href);
    assert.equal(target.protocol, scheme);
    assert.equal(target.host, 'install-config');
    assert.equal(target.searchParams.get('url'), url);
  }
});

test('Clash Verge intercepts the original shared scheme even when clicking its icon', async () => {
  const { dom, enhance, window, url } = menu();
  const originalRow = dom.window.document.querySelectorAll('li')[1];
  let originalClicks = 0;
  originalRow.addEventListener('click', () => {
    originalClicks += 1;
    window.location.href = 'clash://install-config?url=' + encodeURIComponent(url);
  });
  enhance(); enhance();
  originalRow.querySelector('img').click();
  await Promise.resolve();
  const target = new URL(window.location.href);
  assert.equal(target.protocol, 'clash-verge:');
  assert.equal(target.host, 'install-config');
  assert.equal(target.searchParams.get('url'), url);
  assert.equal(target.searchParams.get('name'), '大象 & 测试');
  // Verge reads the subscription from url= through the end of the query string.
  assert.equal(decodeURIComponent(target.search.split('url=')[1]), url);
  assert.equal(originalClicks, 0);
});

test('mobile Clash entry keeps its original click behavior', () => {
  const { dom, enhance } = menu(false, false);
  const originalRow = dom.window.document.querySelectorAll('li')[1];
  let clicks = 0;
  originalRow.addEventListener('click', () => { clicks += 1; });
  enhance();
  originalRow.querySelector('img').click();
  assert.equal(clicks, 1);
  assert.equal(originalRow.textContent, '导入到 Clash Meta');
});

test('new logo and menu assets are mirrored into the served theme', () => {
  for (const file of ['images/flclash.png', 'images/clash-mi.png', 'elephant-route-dashboard.js', 'elephant-route-dashboard.css', 'elephant-route-pages-v2.css']) {
    assert.deepEqual(fs.readFileSync(path.join(__dirname, '../theme/ElephantRoute/assets', file)), fs.readFileSync(path.join(__dirname, '../public/theme/ElephantRoute/assets', file)));
  }
});
