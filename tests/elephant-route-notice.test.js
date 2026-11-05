const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function renderer() {
  const dom = new JSDOM('', { runScripts: 'outside-only', url: 'https://example.test/app' });
  for (const file of ['notice-marked.umd.js', 'notice-purify.min.js']) {
    dom.window.eval(read('theme/ElephantRoute/assets/' + file));
  }
  const script = read('theme/ElephantRoute/assets/elephant-route-dashboard-v2.js');
  const source = script.slice(script.indexOf('  function renderNoticeContent('), script.indexOf('  function copyTextWithExecCommand('));
  const render = new Function('global', source + '; return renderNoticeContent;')(dom.window);
  const content = dom.window.document.createElement('div');
  return { dom, content, render };
}

test('notice renders Markdown structure and preserves client names', () => {
  const { content, render } = renderer();
  render(content, '亲爱的用户：\n\n**高级套餐已恢复。**\n\n## VLESS 更新\n\n- 使用 ClashMi\n- Hiddify / Surge\n\n[下载](/download)');
  assert.equal(content.querySelector('h2').textContent, 'VLESS 更新');
  assert.equal(content.querySelector('strong').textContent, '高级套餐已恢复。');
  assert.equal(content.querySelectorAll('li').length, 2);
  assert.ok(content.textContent.includes('ClashMi'));
  assert.equal(content.querySelector('a').getAttribute('href'), '/download');
  assert.equal(content.querySelector('img'), null);
});

test('notice keeps safe legacy HTML and removes executable content and unsafe links', () => {
  const { content, render } = renderer();
  render(content, '<h2>旧公告</h2><p><strong>通知</strong><img src="/banner.png" onerror="alert(1)"></p><script>alert(1)</script><iframe src="https://evil.test"></iframe><a href="javascript:alert(1)">坏链接</a><form><input name="password"></form>');
  assert.equal(content.querySelector('h2').textContent, '旧公告');
  assert.equal(content.querySelector('strong').textContent, '通知');
  assert.equal(content.querySelector('img').getAttribute('src'), '/banner.png');
  assert.equal(content.querySelector('[onerror], script, iframe, form, input, [href^="javascript:"]'), null);
});

test('notice safely falls back to text if a rendering dependency is unavailable', () => {
  const { dom, content, render } = renderer();
  dom.window.DOMPurify = null;
  render(content, '**公告** <img src=x onerror=alert(1)>');
  assert.equal(content.textContent, '**公告** <img src=x onerror=alert(1)>');
  assert.equal(content.children.length, 0);
});

test('subscription enhancements only select real list items and never announcement content', () => {
  const dom = new JSDOM('<div class="er-v2-notice-content">ClashMi / Hiddify / Surge</div><div class="n-list-item"><span>导入到 Clash</span></div>');
  const script = read('theme/ElephantRoute/assets/elephant-route-dashboard.js');
  const source = script.slice(script.indexOf('  function findSubscribeMenuItem('), script.indexOf('  function hideUnsupportedSurgeOption('));
  const helpers = new Function('document', 'findTextElement', 'closestShortcut', 'getNodeText', source + '; return { findSubscribeListItem, findSubscribeMenuItem };')(
    dom.window.document,
    () => dom.window.document.querySelector('.er-v2-notice-content'),
    element => element,
    element => element.textContent.replace(/\s+/g, ' ').trim()
  );
  const notice = dom.window.document.querySelector('.er-v2-notice-content');
  assert.equal(helpers.findSubscribeListItem('Clash'), dom.window.document.querySelector('.n-list-item'));
  assert.equal(helpers.findSubscribeListItem('Hiddify'), null);
  assert.equal(helpers.findSubscribeMenuItem('Surge'), null);
  assert.equal(notice.textContent, 'ClashMi / Hiddify / Surge');
  dom.window.document.querySelector('.n-list-item').remove();
  assert.equal(helpers.findSubscribeListItem('Clash'), null);
});
