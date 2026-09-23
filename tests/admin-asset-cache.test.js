const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('every admin entry resource has the verified content version', () => {
  const result = spawnSync('php', ['scripts/version-admin-assets.php', '--check'], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const urls = [...read('resources/views/admin.blade.php').matchAll(/(?:src|href)="(\/assets\/admin\/[^" ]+)"/g)].map(match => match[1]);
  assert.equal(urls.length, 7);
  for (const url of urls) assert.match(url, /\?v=admin-[a-f0-9]{20}$/);
  assert.equal(new Set(urls.map(url => url.split('?v=')[1])).size, 1);
});

test('preload and module import use the identical vendor URL', () => {
  const view = read('resources/views/admin.blade.php');
  const preloaded = view.match(/rel="modulepreload" crossorigin href="([^"]+)"/)[1];
  const imported = read('public/assets/admin/assets/index.js').match(/from"(\.\/vendor\.js[^" ]*)"/)[1];
  assert.equal(new URL(imported, 'https://example.org/assets/admin/assets/index.js').pathname + new URL(imported, 'https://example.org/assets/admin/assets/index.js').search, preloaded);
  assert.ok(view.indexOf('rel="modulepreload"') < view.indexOf('type="module"'));
});

test('deferred translations preserve their order and precede the application module', () => {
  const view = read('resources/views/admin.blade.php');
  const scripts = [...view.matchAll(/<script([^>]*\bsrc="[^"]+"[^>]*)>/g)].map(match => match[1]);
  assert.equal(scripts.length, 4);
  ['en-US', 'zh-CN', 'ko-KR'].forEach((locale, index) => {
    assert.match(scripts[index], /\bdefer\b/);
    assert.ok(scripts[index].includes(`/locales/${locale}.js?v=`));
    assert.doesNotMatch(scripts[index], /\basync\b/);
  });
  assert.match(scripts[3], /type="module"/);
});
