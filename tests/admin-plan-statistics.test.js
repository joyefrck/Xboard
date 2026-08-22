const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');
const read = (relativePath) => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('admin plan statistics merge current plans and legacy package balances', () => {
  const controller = read('app/Http/Controllers/V2/Admin/PlanController.php');

  assert.match(controller, /use App\\Models\\UserTrafficPackage;/);
  assert.match(controller, /\$timestamp\s*=\s*time\(\);/);
  assert.match(controller, /DB::table\('v2_user'\)/);
  assert.match(controller, /COALESCE\(u, 0\) \+ COALESCE\(d, 0\) < COALESCE\(transfer_enable, 0\)/);
  assert.match(controller, /expired_at IS NULL OR expired_at > \?/);
  assert.match(controller, /DB::table\('v2_user_traffic_packages as package_balance'\)/);
  assert.match(controller, /join\('v2_user as package_user'/);
  assert.match(controller, /UserTrafficPackage::STATUS_ACTIVE/);
  assert.match(controller, /package_balance\.remaining_bytes > 0/);
  assert.match(controller, /unionAll\(\$packageHolders\)/);
  assert.match(controller, /fromSub\(\$planHolders, 'plan_holders'\)/);
  assert.match(controller, /COUNT\(DISTINCT user_id\) AS users_count/);
  assert.match(controller, /COUNT\(DISTINCT CASE WHEN is_active = 1 THEN user_id END\) AS active_users_count/);
  assert.match(controller, /setAttribute\('users_count', \(int\) \(\$planStatistics->users_count \?\? 0\)\)/);
  assert.match(controller, /setAttribute\('active_users_count', \(int\) \(\$planStatistics->active_users_count \?\? 0\)\)/);
  assert.doesNotMatch(controller, /->withCount\(/);
});

test('admin plan statistics explain associated and currently usable users', () => {
  const asset = read('public/assets/admin/assets/index.js');

  assert.match(asset, /children:"关联用户"/);
  assert.match(asset, /当前归属该套餐，或持有过该流量包的去重用户（包括已过期或已耗尽）/);
  assert.match(asset, /children:"当前可用用户"/);
  assert.match(asset, /未封禁且套餐仍可用，或流量包仍有余额的用户/);
  assert.match(asset, /children:\["可用率：",i,"%"\]/);
  assert.doesNotMatch(asset, /children:"总用户数"/);
  assert.doesNotMatch(asset, /children:"有效期内用户"/);
  assert.doesNotMatch(asset, /children:\["活跃率：",i,"%"\]/);
});
