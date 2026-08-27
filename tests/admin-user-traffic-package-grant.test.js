const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');
const read = (relativePath) => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('admin user update validates an independent paired traffic package grant', () => {
  const request = read('app/Http/Requests/Admin/UserUpdate.php');
  assert.match(request, /'traffic_package_id'/);
  assert.match(request, /required_with:traffic_package_add_gb/);
  assert.match(request, /exists:v2_traffic_packages,id/);
  assert.match(request, /'traffic_package_add_gb'/);
  assert.match(request, /required_with:traffic_package_id/);
  assert.match(request, /integer/);
  assert.match(request, /min:1/);
  assert.match(request, /max:8589934591/);
});

test('admin package grant creates a new balance and is atomic with user updates', () => {
  const service = read('app/Services/TrafficPackageService.php');
  const controller = read('app/Http/Controllers/V2/Admin/UserController.php');

  assert.match(service, /function grantByAdmin\(\s*User \$user,\s*TrafficPackage \$trafficPackage,\s*int \$amountGb\s*\): UserTrafficPackage/);
  assert.match(service, /intdiv\(PHP_INT_MAX, self::BYTES_PER_GB\)/);
  assert.match(service, /'order_id'\s*=>\s*null/);
  assert.match(service, /'plan_id'\s*=>\s*null/);
  assert.match(service, /'traffic_package_id'\s*=>\s*\$trafficPackage->id/);
  assert.match(service, /'total_bytes'\s*=>\s*\$totalBytes/);
  assert.match(service, /'remaining_bytes'\s*=>\s*\$totalBytes/);
  assert.match(service, /syncAccessProfile\(\$user\)/);

  assert.match(controller, /use App\\Models\\TrafficPackage;/);
  assert.match(controller, /use App\\Services\\TrafficPackageService;/);
  assert.match(controller, /unset\(\$params\['traffic_package_id'\], \$params\['traffic_package_add_gb'\]\)/);
  assert.match(controller, /DB::transaction\(function \(\) use/);
  assert.match(controller, /grantByAdmin\(\s*\$user->refresh\(\),\s*\$trafficPackage,\s*\(int\) \$trafficPackageAddGb\s*\)/);
});

test('admin user drawer separates plan traffic from package grants', () => {
  const asset = read('public/assets/admin/assets/index.js');
  assert.match(asset, /traffic-package\/fetch/);
  assert.match(asset, /traffic_package_remaining/);
  assert.match(asset, /traffic_package_id/);
  assert.match(asset, /traffic_package_add_gb/);
  assert.match(asset, /edit\.form\.plan_traffic/);
  assert.match(asset, /edit\.form\.current_traffic_package_remaining/);
  assert.match(asset, /edit\.form\.traffic_package_product/);
  assert.match(asset, /edit\.form\.traffic_package_add_gb/);
  assert.match(asset, /Number\.isInteger/);
  assert.match(asset, /traffic_package_grant_pair_required/);
});

test('admin package grant copy exists in all bundled locales', () => {
  const expected = {
    'public/assets/admin/locales/zh-CN.js': [
      '"plan_traffic": "套餐流量"',
      '"current_traffic_package_remaining": "当前流量包余额"',
      '"traffic_package_product": "增加流量包"',
      '"traffic_package_add_gb": "增加流量"',
      '"traffic_package_grant_pair_required"',
    ],
    'public/assets/admin/locales/en-US.js': [
      '"plan_traffic": "Plan Traffic"',
      '"current_traffic_package_remaining": "Current Traffic Package Balance"',
      '"traffic_package_product": "Traffic Package to Add"',
      '"traffic_package_add_gb": "Traffic to Add"',
      '"traffic_package_grant_pair_required"',
    ],
    'public/assets/admin/locales/ko-KR.js': [
      '"plan_traffic": "요금제 트래픽"',
      '"current_traffic_package_remaining": "현재 트래픽 패키지 잔액"',
      '"traffic_package_product": "추가할 트래픽 패키지"',
      '"traffic_package_add_gb": "추가 트래픽"',
      '"traffic_package_grant_pair_required"',
    ],
  };

  for (const [relativePath, snippets] of Object.entries(expected)) {
    const source = read(relativePath);
    snippets.forEach((snippet) => assert.ok(source.includes(snippet), `${relativePath} is missing ${snippet}`));
  }
});

test('admin package grant runtime behavior remains independent from plan traffic', () => {
  const result = spawnSync('php', ['tests/admin-user-traffic-package-grant.php'], {
    cwd: repoRoot,
    encoding: 'utf8',
  });
  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /admin user traffic package grant runtime test passed/);
});
