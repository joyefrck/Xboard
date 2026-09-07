const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const zlib = require('node:zlib');

const repoRoot = path.resolve(__dirname, '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('invite commission records expose the buyer email and order amount safely', () => {
  const controller = read('app/Http/Controllers/V1/User/InviteController.php');
  const model = read('app/Models/CommissionLog.php');
  const resource = read('app/Http/Resources/ComissionLogResource.php');

  assert.match(controller, /CommissionLog::where\('invite_user_id', \$request->user\(\)->id\)[\s\S]*->with\('user:id,email'\)/);
  assert.match(model, /function user\(\): BelongsTo[\s\S]*belongsTo\(User::class, 'user_id'\)/);
  assert.match(resource, /["']order_amount["']\s*=>\s*\$this\[['"]order_amount['"]\]/);
  assert.match(resource, /["']email["']\s*=>\s*\$this->user\?->email/);
});

test('invite registration endpoint is authenticated, direct-only, paginated, and newest first', () => {
  const routes = read('app/Http/Routes/V1/UserRoute.php');
  const controller = read('app/Http/Controllers/V1/User/InviteController.php');
  const resource = read('app/Http/Resources/InviteRegistrationResource.php');

  assert.match(routes, /'prefix'\s*=>\s*'user'[\s\S]*'middleware'\s*=>\s*'user'[\s\S]*\/invite\/registrations/);
  assert.match(controller, /function registrations\(Request \$request\)/);
  assert.match(controller, /User::where\('invite_user_id', \$request->user\(\)->id\)/);
  assert.match(controller, /orderBy\('created_at', 'DESC'\)/);
  assert.match(controller, /InviteRegistrationResource::collection\(\$registrations\)/);
  assert.match(controller, /'total'\s*=>\s*\$total/);
  assert.match(resource, /["']email["']\s*=>\s*\$this\[['"]email['"]\]/);
  assert.match(resource, /["']created_at["']\s*=>\s*\$this\[['"]created_at['"]\]/);
  assert.doesNotMatch(resource, /password|token|uuid|balance|commission_balance/);
});

test('compiled invite page has tabbed remote records and responsive summary cards', () => {
  const bundle = read('theme/ElephantRoute/assets/umi.js');
  const publicBundle = read('public/theme/ElephantRoute/assets/umi.js');
  const styles = read('theme/ElephantRoute/assets/elephant-route-pages-v2.css');
  const publicStyles = read('public/theme/ElephantRoute/assets/elephant-route-pages-v2.css');

  assert.equal(publicBundle, bundle);
  assert.equal(publicStyles, styles);
  assert.match(bundle, /\/user\/invite\/registrations\?current=/);
  for (const label of ['发放时间', '被邀请人', '消费金额', '佣金', '佣金发放记录', '注册记录', '注册时间']) {
    assert.match(bundle, new RegExp(label));
  }
  assert.match(bundle, /er-invite-commission-card/);
  assert.match(bundle, /er-invite-stats-card/);
  assert.match(bundle, /er-invite-record-tabs/);
  assert.doesNotMatch(bundle, /pe\(n\("(?:佣金发放记录|注册记录)"\)\),3\)/);
  assert.match(bundle, /itemCount/);
  assert.match(bundle, /remote:!0/);
  assert.match(bundle, /scrollX:820/);
  assert.match(bundle, /scrollX:520/);
  assert.match(styles, /data-er-page="invite"[\s\S]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\)/);
  assert.match(styles, /data-er-page="invite"[\s\S]*grid-auto-rows:\s*max-content/);
  assert.match(styles, /data-er-page="invite"[\s\S]*section\.cus-scroll-y > \.n-card[\s\S]*height:\s*auto !important/);
  assert.match(styles, /\.er-invite-record-tab\.is-active/);
  assert.match(styles, /@media \(max-width: 767px\)[\s\S]*data-er-page="invite"[\s\S]*grid-template-columns:\s*minmax\(0, 1fr\)/);
});

test('invite assets are cache-busted and compressed variants match the source bundle', () => {
  const bundle = read('theme/ElephantRoute/assets/umi.js');
  const view = read('theme/ElephantRoute/dashboard.blade.php');
  const publicView = read('public/theme/ElephantRoute/dashboard.blade.php');
  const gzip = zlib.gunzipSync(fs.readFileSync(path.join(repoRoot, 'theme/ElephantRoute/assets/umi.js.gz'))).toString('utf8');
  const brotli = zlib.brotliDecompressSync(fs.readFileSync(path.join(repoRoot, 'theme/ElephantRoute/assets/umi.js.br'))).toString('utf8');

  assert.equal(publicView, view);
  assert.equal(gzip, bundle);
  assert.equal(brotli, bundle);
  assert.match(view, /umi\.js\?v=\{\{\$version\}\}-er20260908inviteRecords1/);
  assert.match(view, /elephant-route-pages-v2\.css\?v=\{\{\$version\}\}-er20260908inviteCardsAutoHeight1/);
});
