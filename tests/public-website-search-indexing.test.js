const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');
const siteOrigin = 'https://www.elphantroute.com';

const publicPages = [
  {
    file: 'public/landing/index.html',
    title: '大象网络 - CONNECT THE UNSEEN',
    description: '专为极客打造的下一代全球网络加速服务。突破物理边界，重塑数字自由。',
    canonical: `${siteOrigin}/`,
    heading: 'CONNECT THE UNSEEN',
  },
  {
    file: 'public/pricing.html',
    title: '定价方案 - 大象网络',
    description: '透明定价，无隐形消费。为您的跨界连接提供最优质的线路。',
    canonical: `${siteOrigin}/pricing.html`,
    heading: '透明定价，无隐形消费',
  },
  {
    file: 'public/terms.html',
    title: '服务条款 - 大象网络',
    description: '查看大象网络的服务使用规范、账户安全、责任限制及服务变更说明。',
    canonical: `${siteOrigin}/terms.html`,
    heading: '服务条款 (Terms of Service)',
  },
  {
    file: 'public/privacy.html',
    title: '隐私政策 - 大象网络',
    description: '了解大象网络的无日志承诺、必要信息收集范围、数据用途及安全措施。',
    canonical: `${siteOrigin}/privacy.html`,
    heading: '隐私政策 (Privacy Policy)',
  },
  {
    file: 'public/refund.html',
    title: '退款说明 - 大象网络',
    description: '查看大象网络数字服务的退款条件、不予退款情形和申请处理流程。',
    canonical: `${siteOrigin}/refund.html`,
    heading: '退款说明 (Refund Policy)',
  },
];

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function staticHeadingText(html) {
  const match = html.match(/<h1(?:\s[^>]*)?>([\s\S]*?)<\/h1>/i);
  assert.ok(match, 'page contains an h1');
  return match[1].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
}

test('public pages expose unique crawlable metadata and static primary headings', () => {
  for (const page of publicPages) {
    const html = readRepoFile(page.file);
    const label = page.file;

    assert.match(html, new RegExp(`<title>${escapeRegex(page.title)}</title>`), `${label} title`);
    assert.match(
      html,
      new RegExp(`<meta name="description" content="${escapeRegex(page.description)}" \\/>`),
      `${label} description`
    );
    assert.match(
      html,
      /<meta name="robots" content="index,follow,max-image-preview:large" \/>/,
      `${label} robots`
    );
    assert.match(
      html,
      new RegExp(`<link rel="canonical" href="${escapeRegex(page.canonical)}" \\/>`),
      `${label} canonical`
    );
    assert.match(html, /<meta property="og:type" content="website" \/>/, `${label} og:type`);
    assert.match(
      html,
      new RegExp(`<meta property="og:url" content="${escapeRegex(page.canonical)}" \\/>`),
      `${label} og:url`
    );
    assert.equal(staticHeadingText(html), page.heading, `${label} static h1`);
  }
});

test('homepage publishes WebSite and Organization structured data', () => {
  const html = readRepoFile('public/landing/index.html');
  const match = html.match(/<script type="application\/ld\+json">\s*([\s\S]*?)\s*<\/script>/);
  assert.ok(match, 'homepage contains JSON-LD');

  const data = JSON.parse(match[1]);
  assert.equal(data['@context'], 'https://schema.org');
  assert.deepEqual(data['@graph'].map((item) => item['@type']), ['WebSite', 'Organization']);
  assert.equal(data['@graph'][0].url, `${siteOrigin}/`);
  assert.equal(data['@graph'][1].logo.url, `${siteOrigin}/landing/assets/elephant-route-logo.jpg`);
});

test('robots file advertises the production sitemap without blocking pages', () => {
  const robots = readRepoFile('public/robots.txt');

  assert.match(robots, /^User-agent: \*$/m);
  assert.match(robots, /^Allow: \/$/m);
  assert.doesNotMatch(robots, /^Disallow:\s*\/$/m);
  assert.match(robots, new RegExp(`^Sitemap: ${escapeRegex(siteOrigin)}/sitemap\\.xml$`, 'm'));
});

test('sitemap lists exactly the five canonical public URLs', () => {
  const sitemap = readRepoFile('public/sitemap.xml');
  const urls = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
  const expectedUrls = publicPages.map((page) => page.canonical);

  assert.match(sitemap, /<urlset xmlns="http:\/\/www\.sitemaps\.org\/schemas\/sitemap\/0\.9">/);
  assert.deepEqual(urls, expectedUrls);
  assert.equal(new Set(urls).size, expectedUrls.length);
  assert.doesNotMatch(sitemap, /\/welcome<\/loc>/);
  assert.doesNotMatch(sitemap, /\/app<\/loc>/);
});
