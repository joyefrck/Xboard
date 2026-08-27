# Admin User Traffic Package Grant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an administrator grant a selected independent traffic-package product and a custom positive integer GB amount from the user edit drawer without changing the user's ordinary subscription traffic.

**Architecture:** Extend the existing admin user update request with a paired, operation-only traffic-package product ID and GB amount. Create a new `v2_user_traffic_packages` row through `TrafficPackageService` inside the same database transaction as the ordinary user update, then reuse `syncAccessProfile()` so a usable plan remains authoritative and FIFO package access changes only when appropriate. Extend the compiled admin form and three locale bundles while keeping the ordinary `plan_id` selector and `transfer_enable` field independent.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, SQLite runtime tests, React compiled admin bundle, i18next locale bundles, Node.js built-in test runner.

---

## File Structure

- Modify `app/Http/Requests/Admin/UserUpdate.php`: validate the two paired grant fields and provide precise Chinese validation errors.
- Modify `app/Services/TrafficPackageService.php`: create one independent, orderless user-package record and synchronize access.
- Modify `app/Http/Controllers/V2/Admin/UserController.php`: remove operation fields from user attributes and make user update plus package grant atomic.
- Modify `public/assets/admin/assets/index.js`: load the admin traffic-package catalog, render separated plan/package controls, validate the pair, and submit only explicit grants.
- Modify `public/assets/admin/locales/zh-CN.js`: add Chinese labels and errors.
- Modify `public/assets/admin/locales/en-US.js`: add English labels and errors.
- Modify `public/assets/admin/locales/ko-KR.js`: add Korean labels and errors.
- Create `tests/admin-user-traffic-package-grant.test.js`: source, bundle, locale, and runtime contracts.
- Create `tests/admin-user-traffic-package-grant.php`: SQLite/Eloquent behavior coverage for grant creation and access selection.

### Task 1: Add failing backend and admin-form contracts

**Files:**
- Create: `tests/admin-user-traffic-package-grant.test.js`

- [ ] **Step 1: Write source and UI contract tests**

Create the test with these contracts:

```js
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
    snippets.forEach((snippet) => assert.match(source, new RegExp(snippet)));
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
```

- [ ] **Step 2: Run the focused test and verify the red state**

Run:

```bash
node --test tests/admin-user-traffic-package-grant.test.js
```

Expected: failures for missing request fields, service method, compiled form fields, locale keys, and runtime script.

- [ ] **Step 3: Commit the failing contract test**

```bash
git add tests/admin-user-traffic-package-grant.test.js
git commit -m "test: define admin traffic package grant contract"
```

### Task 2: Implement validated independent package grants

**Files:**
- Modify: `app/Http/Requests/Admin/UserUpdate.php`
- Modify: `app/Services/TrafficPackageService.php`
- Modify: `app/Http/Controllers/V2/Admin/UserController.php`
- Test: `tests/admin-user-traffic-package-grant.test.js`

- [ ] **Step 1: Add paired request validation**

Add these rules to `UserUpdate::rules()`:

```php
'traffic_package_id' => 'nullable|required_with:traffic_package_add_gb|integer|exists:v2_traffic_packages,id',
'traffic_package_add_gb' => 'nullable|required_with:traffic_package_id|integer|min:1|max:8589934591',
```

Add these messages to `UserUpdate::messages()`:

```php
'traffic_package_id.required_with' => '请选择流量包并填写增加流量',
'traffic_package_id.integer' => '流量包格式不正确',
'traffic_package_id.exists' => '流量包不存在',
'traffic_package_add_gb.required_with' => '请选择流量包并填写增加流量',
'traffic_package_add_gb.integer' => '增加流量必须是大于 0 的整数 GB',
'traffic_package_add_gb.min' => '增加流量必须是大于 0 的整数 GB',
'traffic_package_add_gb.max' => '增加流量超出系统支持范围',
```

- [ ] **Step 2: Add the service boundary**

Add this method to `TrafficPackageService`:

```php
public function grantByAdmin(
    User $user,
    TrafficPackage $trafficPackage,
    int $amountGb
): UserTrafficPackage {
    $maxAmountGb = intdiv(PHP_INT_MAX, self::BYTES_PER_GB);
    if ($amountGb < 1 || $amountGb > $maxAmountGb) {
        throw new \InvalidArgumentException('Traffic package grant is outside the supported range.');
    }

    $totalBytes = $amountGb * self::BYTES_PER_GB;
    $package = UserTrafficPackage::create([
        'user_id' => $user->id,
        'order_id' => null,
        'plan_id' => null,
        'traffic_package_id' => $trafficPackage->id,
        'total_bytes' => $totalBytes,
        'remaining_bytes' => $totalBytes,
        'status' => UserTrafficPackage::STATUS_ACTIVE,
    ]);

    $this->syncAccessProfile($user);
    return $package;
}
```

- [ ] **Step 3: Make the controller update atomic**

Add imports:

```php
use App\Models\TrafficPackage;
use App\Services\TrafficPackageService;
```

Immediately after validation, extract and remove the operation-only fields:

```php
$trafficPackageId = $params['traffic_package_id'] ?? null;
$trafficPackageAddGb = $params['traffic_package_add_gb'] ?? null;
unset($params['traffic_package_id'], $params['traffic_package_add_gb']);

$trafficPackage = $trafficPackageId !== null
    ? TrafficPackage::find($trafficPackageId)
    : null;
if ($trafficPackageId !== null && !$trafficPackage) {
    return $this->fail([400202, '流量包不存在']);
}
```

Replace the existing bare update `try` block with:

```php
try {
    DB::transaction(function () use (
        $user,
        $params,
        $trafficPackage,
        $trafficPackageAddGb
    ): void {
        $user->update($params);

        if ($trafficPackage && $trafficPackageAddGb !== null) {
            app(TrafficPackageService::class)->grantByAdmin(
                $user->refresh(),
                $trafficPackage,
                (int) $trafficPackageAddGb
            );
        }
    });
} catch (\Throwable $e) {
    Log::error($e);
    return $this->fail([500, '保存失败']);
}
```

The `refresh()` is required because ordinary edits in the same request may change plan, traffic, expiry, ban, or access attributes before access synchronization.

- [ ] **Step 4: Run syntax and source contracts**

Run:

```bash
php -l app/Http/Requests/Admin/UserUpdate.php
php -l app/Services/TrafficPackageService.php
php -l app/Http/Controllers/V2/Admin/UserController.php
node --test --test-name-pattern="admin user update|admin package grant creates" tests/admin-user-traffic-package-grant.test.js
```

Expected: PHP syntax checks pass and the two backend source tests pass; UI, locale, and runtime tests remain red.

- [ ] **Step 5: Commit the backend implementation**

```bash
git add app/Http/Requests/Admin/UserUpdate.php app/Services/TrafficPackageService.php app/Http/Controllers/V2/Admin/UserController.php
git commit -m "feat: grant traffic packages from user admin"
```

### Task 3: Add SQLite runtime coverage

**Files:**
- Create: `tests/admin-user-traffic-package-grant.php`
- Modify: `tests/admin-user-traffic-package-grant.test.js`

- [ ] **Step 1: Build the isolated runtime fixture**

Create an in-memory SQLite harness following `tests/traffic-package-access-switching.php`. Define `v2_plan`, `v2_traffic_packages`, `v2_user`, and `v2_user_traffic_packages` with the same columns used by their models. Insert:

```php
$gib = 1073741824;
$now = time();

Capsule::table('v2_plan')->insert([
    'id' => 4,
    'name' => 'Base Plan',
    'group_id' => 1,
    'speed_limit' => 100,
    'device_limit' => 2,
    'transfer_enable' => 100,
    'created_at' => $now,
    'updated_at' => $now,
]);

Capsule::table('v2_traffic_packages')->insert([
    ['id' => 10, 'name' => 'Premium Package', 'group_id' => 3, 'speed_limit' => 500, 'device_limit' => 5, 'transfer_enable' => 180, 'created_at' => $now, 'updated_at' => $now],
    ['id' => 11, 'name' => 'Backup Package', 'group_id' => 2, 'speed_limit' => 200, 'device_limit' => 3, 'transfer_enable' => 50, 'created_at' => $now, 'updated_at' => $now],
]);
```

Create one user with an active 100 GiB plan and nonzero `u`/`d`. Grant 25 GiB from product 10 and assert:

```php
$before = $user->only(['plan_id', 'transfer_enable', 'u', 'd', 'expired_at']);
$granted = $service->grantByAdmin($user, TrafficPackage::findOrFail(10), 25);

$assertSame($before, $user->refresh()->only(['plan_id', 'transfer_enable', 'u', 'd', 'expired_at']), 'plan state stays unchanged');
$assertSame(null, $granted->order_id, 'admin grant has no order');
$assertSame(null, $granted->plan_id, 'admin grant is not a legacy plan');
$assertSame(10, $granted->traffic_package_id, 'grant keeps selected product');
$assertSame(25 * $gib, $granted->total_bytes, 'grant stores requested total');
$assertSame(25 * $gib, $granted->remaining_bytes, 'grant starts fully available');
$assertSame(1, $user->refresh()->group_id, 'usable plan remains the access source');
```

Add an older active package, grant another product, exhaust the plan, call `syncAccessProfile()`, and assert the oldest active package wins. Create a second user without usable plan balance, grant product 10, and assert access immediately becomes group 3. Call `grantByAdmin()` with zero and `intdiv(PHP_INT_MAX, $gib) + 1`, assert `InvalidArgumentException`, and assert no additional rows were created.

End with:

```php
echo "admin user traffic package grant runtime test passed\n";
```

- [ ] **Step 2: Run the runtime and focused suite**

Run:

```bash
php -l tests/admin-user-traffic-package-grant.php
php tests/admin-user-traffic-package-grant.php
node --test --test-name-pattern="admin package grant" tests/admin-user-traffic-package-grant.test.js
```

Expected: runtime output ends with `admin user traffic package grant runtime test passed`; backend and runtime Node tests pass while UI/locale tests remain red.

- [ ] **Step 3: Commit runtime coverage**

```bash
git add tests/admin-user-traffic-package-grant.php tests/admin-user-traffic-package-grant.test.js
git commit -m "test: cover admin traffic package grants"
```

### Task 4: Extend the compiled admin user drawer

**Files:**
- Modify: `public/assets/admin/assets/index.js`
- Modify: `public/assets/admin/locales/zh-CN.js`
- Modify: `public/assets/admin/locales/en-US.js`
- Modify: `public/assets/admin/locales/ko-KR.js`
- Test: `tests/admin-user-traffic-package-grant.test.js`

- [ ] **Step 1: Add a traffic-package catalog client**

Beside the existing plan and user API clients, add one client that uses the same secure path:

```js
const xboardAdminTrafficPackageApi={
  getList:()=>O.get(`${window?.settings?.secure_path}/traffic-package/fetch`)
};
```

- [ ] **Step 2: Extend form state without making grants sticky**

Add these fields to the user edit schema:

```js
traffic_package_remaining:Y().default(0),
traffic_package_id:Y().nullable().default(null),
traffic_package_add_gb:Y().nullable().default(null)
```

Load `xboardAdminTrafficPackageApi.getList()` when the drawer opens. On every editing-user reset, explicitly override the two operation fields:

```js
u.reset({
  ...h,
  invite_user_email:o||null,
  password:null,
  u:h.u?(h.u/1024/1024/1024).toFixed(3):"",
  d:h.d?(h.d/1024/1024/1024).toFixed(3):"",
  traffic_package_id:null,
  traffic_package_add_gb:null
})
```

This prevents a grant from being repeated after switching users or reopening the drawer.

- [ ] **Step 3: Render separated plan and package controls**

Change the existing `transfer_enable` label to `edit.form.plan_traffic`. After that field, render:

```jsx
<FormItem>
  <FormLabel>{t("edit.form.current_traffic_package_remaining")}</FormLabel>
  <div className="flex">
    <Input readOnly value={(form.getValues("traffic_package_remaining")/1024/1024/1024).toFixed(2)} className="rounded-r-none bg-muted" />
    <div className="rounded-r-md border border-l-0 border-input px-3 py-1">GB</div>
  </div>
</FormItem>
<FormField control={form.control} name="traffic_package_id" render={({field})=>(
  <FormItem>
    <FormLabel>{t("edit.form.traffic_package_product")}</FormLabel>
    <Select value={field.value===null?"":String(field.value)} onValueChange={value=>field.onChange(value?Number(value):null)}>
      <SelectTrigger><SelectValue placeholder={t("edit.form.traffic_package_product_placeholder")} /></SelectTrigger>
      <SelectContent>{trafficPackages.map(item=><SelectItem value={String(item.id)} key={item.id}>{item.name}</SelectItem>)}</SelectContent>
    </Select>
  </FormItem>
)} />
<FormField control={form.control} name="traffic_package_add_gb" render={({field})=>(
  <FormItem>
    <FormLabel>{t("edit.form.traffic_package_add_gb")}</FormLabel>
    <div className="flex">
      <Input type="number" min="1" step="1" value={field.value??""} onChange={event=>field.onChange(event.target.value===""?null:Number(event.target.value))} className="rounded-r-none" />
      <div className="rounded-r-md border border-l-0 border-input px-3 py-1">GB</div>
    </div>
  </FormItem>
)} />
```

Translate the JSX into the bundle's existing minified helper calls and component aliases; do not introduce new runtime imports.

- [ ] **Step 4: Validate and submit an explicit one-time grant**

Before building the update payload, add:

```js
const packageId=values.traffic_package_id;
const packageGb=values.traffic_package_add_gb;
const hasProduct=packageId!==null;
const hasAmount=packageGb!==null;
if(hasProduct!==hasAmount){
  A.error(t("edit.form.traffic_package_grant_pair_required"));
  return;
}
if(hasAmount&&(!Number.isInteger(packageGb)||packageGb<1)){
  A.error(t("edit.form.traffic_package_grant_positive_integer"));
  return;
}
```

Exclude `traffic_package_remaining`, `traffic_package_id`, and `traffic_package_add_gb` from the generic dirty-field loop. When both values exist, explicitly add:

```js
payload.traffic_package_id=packageId;
payload.traffic_package_add_gb=packageGb;
```

- [ ] **Step 5: Add all locale keys**

Add under `user.edit.form`:

```json
// zh-CN
"plan_traffic": "套餐流量",
"current_traffic_package_remaining": "当前流量包余额",
"traffic_package_product": "增加流量包",
"traffic_package_product_placeholder": "请选择流量包商品",
"traffic_package_add_gb": "增加流量",
"traffic_package_grant_pair_required": "请选择流量包并填写增加流量",
"traffic_package_grant_positive_integer": "增加流量必须是大于 0 的整数 GB"

// en-US
"plan_traffic": "Plan Traffic",
"current_traffic_package_remaining": "Current Traffic Package Balance",
"traffic_package_product": "Traffic Package to Add",
"traffic_package_product_placeholder": "Select a traffic package product",
"traffic_package_add_gb": "Traffic to Add",
"traffic_package_grant_pair_required": "Select a traffic package and enter the traffic to add",
"traffic_package_grant_positive_integer": "Traffic to add must be a positive whole number of GB"

// ko-KR
"plan_traffic": "요금제 트래픽",
"current_traffic_package_remaining": "현재 트래픽 패키지 잔액",
"traffic_package_product": "추가할 트래픽 패키지",
"traffic_package_product_placeholder": "트래픽 패키지 상품을 선택하세요",
"traffic_package_add_gb": "추가 트래픽",
"traffic_package_grant_pair_required": "트래픽 패키지를 선택하고 추가 트래픽을 입력하세요",
"traffic_package_grant_positive_integer": "추가 트래픽은 0보다 큰 정수 GB여야 합니다"
```

- [ ] **Step 6: Run focused UI and locale tests**

Run:

```bash
node --test tests/admin-user-traffic-package-grant.test.js tests/admin-user-traffic-summary.test.js
```

Expected: all tests in both files pass.

- [ ] **Step 7: Commit the admin UI**

```bash
git add public/assets/admin/assets/index.js public/assets/admin/locales/zh-CN.js public/assets/admin/locales/en-US.js public/assets/admin/locales/ko-KR.js tests/admin-user-traffic-package-grant.test.js
git commit -m "feat: add traffic package grants to user editor"
```

### Task 5: Full verification and scoped handoff

**Files:**
- Verify all files listed in the File Structure section.

- [ ] **Step 1: Run PHP syntax and runtime checks**

```bash
php -l app/Http/Requests/Admin/UserUpdate.php
php -l app/Services/TrafficPackageService.php
php -l app/Http/Controllers/V2/Admin/UserController.php
php -l tests/admin-user-traffic-package-grant.php
php tests/admin-user-traffic-package-grant.php
php tests/traffic-package-access-switching.php
```

Expected: every syntax command exits 0 and both runtime scripts print their success sentinel.

- [ ] **Step 2: Run focused and full Node suites**

```bash
node --test tests/admin-user-traffic-package-grant.test.js tests/admin-user-traffic-summary.test.js tests/stacked-traffic-package.test.js
node --test tests/*.test.js
```

Expected: zero failed tests. Record exact pass/skip counts from fresh output.

- [ ] **Step 3: Run repository integrity checks**

```bash
git diff --check
git status --short
git diff --stat HEAD~3..HEAD
```

Expected: no whitespace errors; only the approved request, service, controller, admin assets, locales, tests, spec, and plan are present.

- [ ] **Step 4: Attempt the repository PHP test harness separately**

```bash
vendor/bin/phpunit
```

Record the actual result. If the existing harness is unavailable or misconfigured, do not report PHPUnit as passing; keep the SQLite runtime and Node results separate.

- [ ] **Step 5: Review the requirement checklist**

Confirm from code and test evidence:

```text
[ ] ordinary plan selector remains independent
[ ] transfer_enable still edits plan traffic only
[ ] current package balance is read-only
[ ] a product and positive integer GB are both required for a grant
[ ] each grant creates a new orderless package record
[ ] existing packages and plan dates are unchanged
[ ] plan-first and FIFO package access remain intact
[ ] reopening the drawer cannot repeat a prior grant
[ ] zh-CN, en-US, and ko-KR are synchronized
```

- [ ] **Step 6: Commit the plan and any final scoped test corrections**

```bash
git add docs/superpowers/plans/2026-08-27-admin-user-traffic-package-grant.md
git commit -m "docs: plan admin traffic package grants"
```

This plan stops after local implementation and verification. Pushing, CI, production deployment, product-data changes, and live-account mutations require a separate explicit request.
