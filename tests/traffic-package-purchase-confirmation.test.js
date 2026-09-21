const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const frontendBundles = [
  'public/assets/umi.js',
  'theme/Xboard/assets/umi.js',
  'theme/ElephantRoute/assets/umi.js',
  'public/theme/ElephantRoute/assets/umi.js',
];

test('one-time traffic package purchase does not show subscription replacement warning', () => {
  for (const bundlePath of frontendBundles) {
    if (!fs.existsSync(path.join(repoRoot, bundlePath))) {
      continue;
    }

    const bundle = readRepoFile(bundlePath);
    const expression = bundle.match(/R=\(\)=>([^;]{0,700}?),q=\(\)=>\{window\.\$dialog/)?.[1];
    assert.ok(expression, `${bundlePath} must provide a replacement confirmation guard`);
    const warns = new Function('b', 'n', 'i', 'a', `return Boolean(${expression});`);
    const current = {plan_id: 1, expired_at: Math.floor(Date.now()/1000)+86400,
      userInfo: {current_plan_type:'exclusive'}};
    assert.equal(warns({value:'onetime_price'}, current, {value:2}, {value:{plan_type:'standard'}}), false);
    assert.equal(warns({value:'month_price'}, current, {value:2}, {value:{plan_type:'standard'}}), true);
    assert.match(
      bundle,
      /请注意，变更订阅会导致当前订阅被覆盖。/,
      `${bundlePath} should keep the replacement warning for normal plan changes`
    );
  }
});
