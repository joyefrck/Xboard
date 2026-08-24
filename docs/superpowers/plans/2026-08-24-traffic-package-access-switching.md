# Traffic Package Access Switching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Switch a user's effective node permissions to the traffic package currently funding traffic after the timed plan is exhausted, and switch back when plan traffic is restored.

**Architecture:** Keep `v2_user.group_id`, `speed_limit`, and `device_limit` as the effective access snapshot used by subscriptions and node polling. Centralize source selection and snapshot synchronization in `TrafficPackageService`, invoke it inside purchase, consumption, reset, and authenticated-access paths, and preserve the existing plan-first/FIFO-package deduction order.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, Horizon, Node.js built-in test runner, MariaDB 11, Docker Compose.

---

### Task 1: Add failing access-switching contracts

**Files:**
- Modify: `tests/stacked-traffic-package.test.js`
- Create: `tests/traffic-package-access-switching.php`

- [ ] **Step 1: Add source-level regression assertions**

Add this focused contract test:

```js
test('effective access follows the product currently funding traffic', () => {
  const trafficPackageService = read('app/Services/TrafficPackageService.php');
  const trafficResetService = read('app/Services/TrafficResetService.php');
  const userService = read('app/Services/UserService.php');

  assert.match(trafficPackageService, /function hasUsablePlanBalance\(User \$user\): bool/);
  assert.match(trafficPackageService, /function syncAccessProfile\(User \$user/);
  assert.match(trafficPackageService, /function getFirstActivePackage\(User \$user/);
  assert.match(trafficPackageService, /'group_id'\s*=>\s*\$source->group_id/);
  assert.match(trafficPackageService, /'speed_limit'\s*=>\s*\$source->speed_limit/);
  assert.match(trafficPackageService, /'device_limit'\s*=>\s*\$source->device_limit/);
  assert.match(trafficPackageService, /syncAccessProfile\(\$user,\s*\$packages,\s*\$planAvailable\)/);
  assert.match(trafficResetService, /TrafficPackageService::class\)->syncAccessProfile\(\$user\)/);
  assert.match(userService, /hasUsablePlanBalance\(\$user\)/);
  assert.match(userService, /syncAccessProfile\(\$user\)/);
});
```

Extend purchase assertions so both package creation methods create the balance before calling `syncAccessProfile()`. Extend availability assertions so an exhausted plan without package balance is unavailable.

Add a standalone SQLite/Eloquent runtime test that creates one timed plan, two FIFO standalone packages, and one legacy package. It must assert the exact plan boundary switch, next-package switch, reset switch-back, legacy source resolution, and that unrelated dirty user fields are not persisted.

- [ ] **Step 2: Run the focused test and confirm red state**

Run `node --test tests/stacked-traffic-package.test.js`.

Expected: failure because the effective-access methods and integration calls do not exist.

### Task 2: Centralize effective access selection and synchronization

**Files:**
- Modify: `app/Services/TrafficPackageService.php`
- Test: `tests/stacked-traffic-package.test.js`

- [ ] **Step 1: Separate timed-plan validity from usable plan balance**

Keep `hasActivePlan()` for product validity and add:

```php
public function hasUsablePlanBalance(User $user): bool
{
    return $this->getActivePlanRemainingBytes($user) > 0;
}
```

- [ ] **Step 2: Add FIFO package resolution**

Add a helper that can reuse an already locked collection:

```php
private function getFirstActivePackage(User $user, ?Collection $packages = null): ?UserTrafficPackage
{
    if ($packages !== null) {
        return $packages->first(fn(UserTrafficPackage $package): bool =>
            $package->status === UserTrafficPackage::STATUS_ACTIVE
            && (int) $package->remaining_bytes > 0
        );
    }

    return UserTrafficPackage::with(['trafficPackage', 'plan'])
        ->where('user_id', $user->id)
        ->where('status', UserTrafficPackage::STATUS_ACTIVE)
        ->where('remaining_bytes', '>', 0)
        ->orderBy('id')
        ->first();
}
```

- [ ] **Step 3: Add idempotent access snapshot synchronization**

Add a public method with an optional projected plan balance:

```php
public function syncAccessProfile(
    User $user,
    ?Collection $packages = null,
    ?int $planRemainingBytes = null
): void {
    if ($user->banned) {
        return;
    }

    $planRemainingBytes ??= $this->getActivePlanRemainingBytes($user);
    $source = null;

    if ($planRemainingBytes > 0 && $user->plan_id) {
        $source = $user->relationLoaded('plan') ? $user->plan : Plan::find($user->plan_id);
    } else {
        $package = $this->getFirstActivePackage($user, $packages);
        $source = $package?->trafficPackage ?? $package?->plan;
        $source ??= $user->plan_id ? Plan::find($user->plan_id) : null;
    }

    $attributes = $source ? [
        'group_id' => $source->group_id,
        'speed_limit' => $source->speed_limit,
        'device_limit' => $source->device_limit,
    ] : [
        'group_id' => null,
        'speed_limit' => null,
        'device_limit' => null,
    ];

    $user->fill($attributes);
    $dirtyAttributes = array_intersect_key($user->getDirty(), $attributes);
    if ($dirtyAttributes) {
        User::whereKey($user->getKey())->update($dirtyAttributes);
        $user->syncOriginalAttributes(array_keys($dirtyAttributes));
    }
}
```

Use the targeted query above instead of `$user->save()` so an order carrying unrelated unsaved fields cannot persist them as a side effect of permission synchronization.

The locked package query in `consume()` must eager-load `trafficPackage` and `plan`.

- [ ] **Step 4: Run syntax and focused tests**

Run `php -l app/Services/TrafficPackageService.php && node --test tests/stacked-traffic-package.test.js`.

Expected: resolver assertions pass; purchase, reset, and access integration assertions remain red until later tasks.

### Task 3: Synchronize purchases and traffic-source transitions

**Files:**
- Modify: `app/Services/TrafficPackageService.php`
- Verify: `app/Jobs/TrafficFetchJob.php`
- Test: `tests/stacked-traffic-package.test.js`

- [ ] **Step 1: Synchronize after creating either package balance**

Change both creation methods to create the balance first, synchronize, and return it:

```php
$package = UserTrafficPackage::create([...]);
$this->syncAccessProfile($user);
return $package;
```

Remove the old pre-create access methods after all callers are gone.

- [ ] **Step 2: Synchronize after every consumption path**

Before the early plan-only return call `$this->syncAccessProfile($user, null, $planAvailable);`.

After FIFO package balances are updated call `$this->syncAccessProfile($user, $packages, $planAvailable);`.

The projected `$planAvailable` is required because `TrafficFetchJob` increments `u` and `d` after `consume()` returns, while the outer per-user transaction keeps both changes atomic.

- [ ] **Step 3: Run focused verification**

Run `php -l app/Services/TrafficPackageService.php && php -l app/Jobs/TrafficFetchJob.php && node --test tests/stacked-traffic-package.test.js`.

Expected: purchase and consumption switching assertions pass.

### Task 4: Restore plan access on reset and reconcile authenticated access

**Files:**
- Modify: `app/Services/TrafficResetService.php`
- Modify: `app/Services/UserService.php`
- Test: `tests/stacked-traffic-package.test.js`

- [ ] **Step 1: Restore plan permissions in the reset transaction**

Immediately after resetting `u` and `d`, refresh and synchronize:

```php
$user->refresh();
app(TrafficPackageService::class)->syncAccessProfile($user);
```

Keep this before cache clearing and the `traffic.reset.after` hook.

- [ ] **Step 2: Match availability to real remaining traffic and reconcile snapshots**

Refactor `UserService::isAvailable()` to:

```php
$trafficPackageService = app(TrafficPackageService::class);
$available = !$user->banned && (
    $trafficPackageService->hasUsablePlanBalance($user)
    || ($user->id && $trafficPackageService->hasActivePackageBalance($user->id))
);

if ($available) {
    $trafficPackageService->syncAccessProfile($user);
}

return $available;
```

This is the authenticated-access fallback for natural expiry and stale snapshots; node polling remains on indexed `v2_user.group_id`.

- [ ] **Step 3: Run focused verification**

Run `php -l app/Services/TrafficResetService.php && php -l app/Services/UserService.php && node --test tests/stacked-traffic-package.test.js`.

Expected: all focused tests pass.

### Task 5: Full local verification and implementation commit

**Files:**
- Verify: `app/Services/TrafficPackageService.php`
- Verify: `app/Services/TrafficResetService.php`
- Verify: `app/Services/UserService.php`
- Verify: `app/Jobs/TrafficFetchJob.php`
- Verify: `tests/stacked-traffic-package.test.js`

- [ ] **Step 1: Run complete checks**

Run:

```bash
php -l app/Services/TrafficPackageService.php
php -l app/Services/TrafficResetService.php
php -l app/Services/UserService.php
php -l app/Jobs/TrafficFetchJob.php
php -l tests/traffic-package-access-switching.php
node --test tests/stacked-traffic-package.test.js
node --test tests/*.test.js
git diff --check
vendor/bin/phpunit
```

Record PHPUnit's actual result separately if the repository harness is unavailable or misconfigured.

- [ ] **Step 2: Review and commit only scoped files**

Run:

```bash
git diff -- app/Services/TrafficPackageService.php app/Services/TrafficResetService.php app/Services/UserService.php app/Jobs/TrafficFetchJob.php tests/stacked-traffic-package.test.js tests/traffic-package-access-switching.php
git status --short
git add app/Services/TrafficPackageService.php app/Services/TrafficResetService.php app/Services/UserService.php tests/stacked-traffic-package.test.js tests/traffic-package-access-switching.php docs/superpowers/plans/2026-08-24-traffic-package-access-switching.md
git commit -m "fix: switch access with active traffic source"
```

Expected: one implementation commit containing only the approved behavior, tests, and plan.

### Task 6: CI, production backup, release, and live acceptance

**Files:**
- Production source: `/opt/1panel/apps/openresty/openresty/www/sites/xboard/index`
- Production containers: `index-web-1`, `index-horizon-1`

- [ ] **Step 1: Push and wait for CI**

Verify remotes and ancestry, push the current `master` branch to the user repository, and wait for the Docker workflow to succeed. Record target SHA and workflow URL.

- [ ] **Step 2: Inventory production and create rollback evidence**

Read current SHA, worktree, container health, Compose mounts, and exact rows for the base plan, both 180G sources, and Tokyo nodes. Create a timestamped backup containing a repository bundle, `.env`, Compose file, old SHA, and target product-row snapshot.

- [ ] **Step 3: Update code and product configuration**

Fast-forward production to the verified target SHA without deleting untracked artifacts. After checking id, name, price, and capacity, update only the standalone 180G catalog row from group 1 to group 3. Clear Laravel caches and recreate both Web and Horizon.

- [ ] **Step 4: Perform live acceptance**

Verify Octane/Horizon, internal HTTP health, public routes, recent errors, and deployed source SHA. Confirm anonymously:

```text
base plan group = 1
legacy 180G group = 3
standalone 180G group = 3
Tokyo peak visible to group 1 = 0
Tokyo peak visible to group 3 = 5
```

Within an explicitly rolled-back database transaction, exercise plan remaining, plan exhausted with package balance, and plan reset profile resolution using a controlled synthetic or anonymized state. Verify no account or balance mutation persists.

- [ ] **Step 5: Confirm final state and rollback readability**

Confirm local, origin, and production SHAs match; Web and Horizon use the same mounted source; tracked worktrees contain no unexpected differences; and the rollback directory is readable.
