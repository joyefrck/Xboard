const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('home serves the restored landing page and legacy welcome permanently redirects', () => {
  const routes = readRepoFile('routes/web.php');

  assert.match(routes, /\$serveLandingPage = function \(Request \$request\) use \(\$isAllowedAppHost\)/);
  assert.match(routes, /if \(!\$isAllowedAppHost\(\$request\)\) \{\s*abort\(403\);\s*\}/);
  assert.match(routes, /\$landingPagePath = public_path\('landing\/index\.html'\);/);
  assert.match(routes, /if \(!File::exists\(\$landingPagePath\)\) \{\s*abort\(404, 'Landing page not found'\);\s*\}/);
  assert.match(routes, /return response\(File::get\(\$landingPagePath\), 200\)/);
  assert.match(routes, /Route::get\('\/', \$serveLandingPage\);/);
  assert.match(
    routes,
    /Route::get\('\/welcome', function \(Request \$request\) use \(\$isAllowedAppHost\) \{[\s\S]*?if \(!\$isAllowedAppHost\(\$request\)\) \{\s*abort\(403\);\s*\}[\s\S]*?return redirect\('\/', 301\);[\s\S]*?\}\);/
  );
  assert.doesNotMatch(routes, /Route::get\('\/welcome', \$serveLandingPage\);/);
  assert.doesNotMatch(routes, /Route::get\('\/', \$redirectToLogin\);/);
});

test('restored website pages retain their final tracked titles and links', () => {
  const expectedPages = new Map([
    ['public/landing/index.html', '<title>大象网络 - CONNECT THE UNSEEN</title>'],
    ['public/pricing.html', '<title>定价方案 - 大象网络</title>'],
    ['public/terms.html', '<title>服务条款 - 大象网络</title>'],
    ['public/privacy.html', '<title>隐私政策 - 大象网络</title>'],
    ['public/refund.html', '<title>退款说明 - 大象网络</title>'],
  ]);

  for (const [relativePath, expectedTitle] of expectedPages) {
    assert.equal(fs.existsSync(path.join(repoRoot, relativePath)), true, `${relativePath} exists`);
    assert.match(readRepoFile(relativePath), new RegExp(expectedTitle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }

  const landing = readRepoFile('public/landing/index.html');
  assert.match(landing, /href="\/pricing\.html"/);
  assert.match(landing, /href="\/terms\.html"/);
  assert.match(landing, /href="\/privacy\.html"/);
  assert.match(landing, /window\.location\.href = "\/app#\/login"/);
  assert.match(landing, /window\.location\.href = "\/app#\/register"/);

  const pricing = readRepoFile('public/pricing.html');
  assert.match(pricing, /href="\/terms\.html"/);
  assert.match(pricing, /href="\/privacy\.html"/);

  for (const policyPath of ['public/terms.html', 'public/privacy.html', 'public/refund.html']) {
    const policy = readRepoFile(policyPath);
    assert.match(policy, /最近更新日期：2026年3月17日/);
    assert.match(policy, /href="\/"/);
  }
});

test('all local landing-page image references resolve to retained assets', () => {
  const landing = readRepoFile('public/landing/index.html');
  const assetReferences = [...landing.matchAll(/(?:src|content)="(\/landing\/assets\/[^"]+)"/g)]
    .map((match) => match[1]);

  assert.ok(assetReferences.length > 0);
  for (const assetReference of assetReferences) {
    assert.equal(
      fs.existsSync(path.join(repoRoot, 'public', assetReference.replace(/^\//, ''))),
      true,
      `${assetReference} exists`
    );
  }
});
