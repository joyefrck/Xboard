const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');
const publicPages = [
  'public/landing/index.html',
  'public/pricing.html',
];

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function section(html, tagName) {
  const match = html.match(new RegExp(`<${tagName}>[\\s\\S]*?<\\/${tagName}>`));
  assert.ok(match, `${tagName} exists`);
  return match[0];
}

test('pricing navigation appears only in the footer on public website pages', () => {
  for (const relativePath of publicPages) {
    const html = readRepoFile(relativePath);
    const header = section(html, 'header');
    const footer = section(html, 'footer');

    assert.doesNotMatch(header, /href="\/pricing\.html"/);
    assert.doesNotMatch(html, /\.footer-pricing-link\s*\{\s*display:\s*none;/);

    const pricingIndex = footer.indexOf('<a href="/pricing.html" class="footer-pricing-link">定价方案</a>');
    const termsIndex = footer.indexOf('<a href="/terms.html">服务条款</a>');
    const privacyIndex = footer.indexOf('<a href="/privacy.html">隐私政策</a>');

    assert.ok(pricingIndex >= 0, `${relativePath} footer contains pricing`);
    assert.ok(pricingIndex < termsIndex, `${relativePath} pricing precedes terms`);
    assert.ok(termsIndex < privacyIndex, `${relativePath} terms precedes privacy`);
  }
});
