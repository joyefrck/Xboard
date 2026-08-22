# Admin Plan User Statistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Repair admin plan statistics so periodic plans and legacy traffic packages share accurate deduplicated holder and currently-usable user counts.

**Architecture:** Build two normalized query-builder sources from `v2_user` and `v2_user_traffic_packages`, union them as `(plan_id, user_id, is_active)`, and aggregate distinct users once per request. Preserve `users_count` and `active_users_count`, then update the compiled admin tooltip copy to describe the new contract.

**Tech Stack:** PHP 8.2, Laravel 12 Query Builder, MariaDB, Node.js built-in test runner, compiled React admin asset.

---

### Task 1: Add the failing statistics contract test

**Files:**
- Create: `tests/admin-plan-statistics.test.js`
- Read: `app/Http/Controllers/V2/Admin/PlanController.php`
- Read: `public/assets/admin/assets/index.js`

- [x] **Step 1: Write the failing test**

Create a Node test that requires the controller to use `v2_user_traffic_packages`, `unionAll`, `fromSub`, distinct user aggregation, the active package status constant, a shared timestamp, and integer zero fallbacks. Assert that the admin asset contains `关联用户`, `当前可用用户`, `可用率：`, and the approved explanatory copy, while the previous `总用户数`/`有效期内用户` tooltip strings are absent.

```js
test('admin plan statistics merge current plans and legacy package balances', () => {
  const controller = read('app/Http/Controllers/V2/Admin/PlanController.php');
  assert.match(controller, /DB::table\('v2_user_traffic_packages as package_balance'\)/);
  assert.match(controller, /unionAll\(\$packageHolders\)/);
  assert.match(controller, /fromSub\(\$planHolders, 'plan_holders'\)/);
  assert.match(controller, /COUNT\(DISTINCT user_id\) AS users_count/);
  assert.match(controller, /COUNT\(DISTINCT CASE WHEN is_active = 1 THEN user_id END\) AS active_users_count/);
  assert.match(controller, /UserTrafficPackage::STATUS_ACTIVE/);
});
```

- [x] **Step 2: Run the focused test and confirm red state**

Run: `node --test tests/admin-plan-statistics.test.js`

Expected: FAIL because the controller still uses `withCount()` and the asset still contains the old copy.

### Task 2: Implement the deduplicated backend aggregation

**Files:**
- Modify: `app/Http/Controllers/V2/Admin/PlanController.php:5-34`
- Test: `tests/admin-plan-statistics.test.js`

- [x] **Step 1: Add the package model import and normalized holder queries**

Use one `$timestamp = time()` for both sources. The `v2_user` source marks a user active only when unbanned, transfer is positive and not exhausted, and expiry is still valid. The package source joins its user, requires `status = UserTrafficPackage::STATUS_ACTIVE`, positive balance, and an unbanned owner.

```php
$timestamp = time();
$currentPlanHolders = DB::table('v2_user')
    ->select('plan_id')
    ->selectRaw('id AS user_id')
    ->selectRaw('CASE WHEN banned = 0 AND COALESCE(transfer_enable, 0) > 0 AND COALESCE(u, 0) + COALESCE(d, 0) < COALESCE(transfer_enable, 0) AND (expired_at IS NULL OR expired_at > ?) THEN 1 ELSE 0 END AS is_active', [$timestamp])
    ->whereNotNull('plan_id');

$packageHolders = DB::table('v2_user_traffic_packages as package_balance')
    ->join('v2_user as package_user', 'package_user.id', '=', 'package_balance.user_id')
    ->select('package_balance.plan_id', 'package_balance.user_id')
    ->selectRaw('CASE WHEN package_user.banned = 0 AND package_balance.status = ? AND package_balance.remaining_bytes > 0 THEN 1 ELSE 0 END AS is_active', [UserTrafficPackage::STATUS_ACTIVE])
    ->whereNotNull('package_balance.plan_id');
```

- [x] **Step 2: Aggregate, attach integer attributes, and remove `withCount()`**

```php
$planHolders = $currentPlanHolders->unionAll($packageHolders);
$statistics = DB::query()
    ->fromSub($planHolders, 'plan_holders')
    ->select('plan_id')
    ->selectRaw('COUNT(DISTINCT user_id) AS users_count')
    ->selectRaw('COUNT(DISTINCT CASE WHEN is_active = 1 THEN user_id END) AS active_users_count')
    ->groupBy('plan_id')
    ->get()
    ->keyBy('plan_id');

$plans->each(function (Plan $plan) use ($statistics): void {
    $planStatistics = $statistics->get($plan->id);
    $plan->setAttribute('users_count', (int) ($planStatistics->users_count ?? 0));
    $plan->setAttribute('active_users_count', (int) ($planStatistics->active_users_count ?? 0));
});
```

- [x] **Step 3: Run backend syntax and focused tests**

Run: `php -l app/Http/Controllers/V2/Admin/PlanController.php && node --test tests/admin-plan-statistics.test.js`

Expected: backend assertions pass; frontend-copy assertions remain red until Task 3.

### Task 3: Update admin statistics wording

**Files:**
- Modify: `public/assets/admin/assets/index.js`
- Test: `tests/admin-plan-statistics.test.js`

- [x] **Step 1: Replace the tooltip contract**

Replace only the five approved literals in the compiled plan table:

```text
总用户数 -> 关联用户
所有使用该套餐的用户（包括已过期） -> 当前归属该套餐，或持有过该流量包的去重用户（包括已过期或已耗尽）
有效期内用户 -> 当前可用用户
当前仍在有效期内的活跃用户 -> 未封禁且套餐仍可用，或流量包仍有余额的用户
活跃率： -> 可用率：
```

- [x] **Step 2: Run the focused regression test**

Run: `node --test tests/admin-plan-statistics.test.js`

Expected: all focused subtests pass.

### Task 4: Verify the complete local change

**Files:**
- Verify: `app/Http/Controllers/V2/Admin/PlanController.php`
- Verify: `public/assets/admin/assets/index.js`
- Verify: `tests/admin-plan-statistics.test.js`

- [x] **Step 1: Verify syntax, focused behavior, and the full repository Node suite**

Run:

```bash
php -l app/Http/Controllers/V2/Admin/PlanController.php
node --test tests/admin-plan-statistics.test.js
node --test tests/*.test.js
git diff --check
```

Expected: PHP reports no syntax errors, all Node tests pass with zero failures, and `git diff --check` exits 0.

- [x] **Step 2: Inspect the final diff and worktree scope**

Run: `git diff --stat && git diff -- app/Http/Controllers/V2/Admin/PlanController.php tests/admin-plan-statistics.test.js && git status --short`

Expected: changes are limited to the approved design correction, implementation plan, controller, admin asset, and regression test; pre-existing unrelated untracked production artifacts are not staged.

- [x] **Step 3: Commit the verified implementation**

```bash
git add app/Http/Controllers/V2/Admin/PlanController.php public/assets/admin/assets/index.js tests/admin-plan-statistics.test.js docs/superpowers/specs/2026-08-22-admin-plan-user-statistics-design.md docs/superpowers/plans/2026-08-22-admin-plan-user-statistics.md
git commit -m "fix: correct admin plan user statistics"
```
