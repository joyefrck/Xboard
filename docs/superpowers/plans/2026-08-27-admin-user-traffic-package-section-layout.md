# Admin User Traffic Package Section Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorder the admin user editor so ordinary subscription fields appear as subscription plan, expiry, and plan traffic, followed by a clearly separated independent traffic-package section.

**Architecture:** Change only the compiled admin form and its locale bundles; retain all existing form names, request payloads, and backend behavior. Add a source-order contract test that isolates the `Ji` user editor function, then use guarded single-occurrence replacements in the minified bundle so unrelated admin screens remain untouched.

**Tech Stack:** Compiled React JavaScript, i18next locale bundles, Node.js built-in test runner, Docker Compose, Laravel Octane.

---

## File Structure

- Modify `tests/admin-user-traffic-package-grant.test.js`: assert exact field and section order inside the user edit function plus complete locale copy.
- Modify `public/assets/admin/assets/index.js`: move existing controls and insert the independent section heading and description.
- Modify `public/assets/admin/locales/zh-CN.js`: add Chinese section title and description.
- Modify `public/assets/admin/locales/en-US.js`: add English section title and description.
- Modify `public/assets/admin/locales/ko-KR.js`: add Korean section title and description.
- Create then remove `scripts/patch-admin-user-traffic-package-section-layout.js`: guarded mechanical rewrite for the single-line compiled bundle; this helper must not remain in the final commit.

### Task 1: Add a failing layout-order contract

**Files:**
- Modify: `tests/admin-user-traffic-package-grant.test.js`

- [ ] **Step 1: Add exact user-editor ordering assertions**

Extend the existing `admin user drawer separates plan traffic from package grants` test after its current assertions:

```js
  const userEditStart = asset.indexOf('function Ji(){');
  const userEditEnd = asset.indexOf('function Hp(){', userEditStart);
  assert.ok(userEditStart >= 0 && userEditEnd > userEditStart, 'user edit function exists');

  const userEdit = asset.slice(userEditStart, userEditEnd);
  const positions = {
    plan: userEdit.indexOf('name:"plan_id"'),
    expiry: userEdit.indexOf('name:"expired_at"'),
    planTraffic: userEdit.indexOf('name:"transfer_enable"'),
    section: userEdit.indexOf('edit.form.traffic_package_section_title'),
    remaining: userEdit.indexOf('name:"traffic_package_remaining"'),
    product: userEdit.indexOf('name:"traffic_package_id"'),
    amount: userEdit.indexOf('name:"traffic_package_add_gb"'),
    status: userEdit.indexOf('name:"banned"'),
  };

  Object.entries(positions).forEach(([name, position]) => {
    assert.ok(position >= 0, `${name} control exists in user editor`);
  });

  assert.ok(positions.plan < positions.expiry, 'subscription precedes expiry');
  assert.ok(positions.expiry < positions.planTraffic, 'expiry precedes plan traffic');
  assert.ok(positions.planTraffic < positions.section, 'independent section follows plan traffic');
  assert.ok(positions.section < positions.remaining, 'section heading precedes package balance');
  assert.ok(positions.remaining < positions.product, 'package balance precedes product selector');
  assert.ok(positions.product < positions.amount, 'product selector precedes grant amount');
  assert.ok(positions.amount < positions.status, 'package section precedes account status');
  assert.match(userEdit, /border-t/);
  assert.match(userEdit, /edit\.form\.traffic_package_section_description/);
```

- [ ] **Step 2: Extend locale expectations**

Add these exact snippets to the existing per-locale expectation arrays:

```js
// zh-CN
'"traffic_package_section_title": "独立流量包"',
'"traffic_package_section_description": "独立于套餐流量，不修改套餐及到期时间"',

// en-US
'"traffic_package_section_title": "Independent Traffic Package"',
'"traffic_package_section_description": "Separate from plan traffic; does not change the plan or expiry"',

// ko-KR
'"traffic_package_section_title": "독립 트래픽 패키지"',
'"traffic_package_section_description": "요금제 트래픽과 별개이며 요금제 또는 만료 시간을 변경하지 않습니다"',
```

- [ ] **Step 3: Run the focused test and verify red state**

Run:

```bash
node --test tests/admin-user-traffic-package-grant.test.js
```

Expected: the layout test fails because `plan_id` currently follows the package fields and the new section title/description do not exist; locale coverage also fails for the two new keys.

- [ ] **Step 4: Commit the failing test**

```bash
git add tests/admin-user-traffic-package-grant.test.js
git commit -m "test: define admin traffic package section layout"
```

### Task 2: Reorder the compiled user editor and add the visual boundary

**Files:**
- Modify: `public/assets/admin/assets/index.js`
- Create then delete: `scripts/patch-admin-user-traffic-package-section-layout.js`
- Test: `tests/admin-user-traffic-package-grant.test.js`

- [ ] **Step 1: Capture the existing control blocks with unique anchors**

The temporary Node rewrite must read `public/assets/admin/assets/index.js`, isolate the substring from `function Ji(){` to `function Hp(){`, and assert each of these anchors occurs exactly once inside that substring:

```js
const anchors = [
  'name:"transfer_enable"',
  'name:"traffic_package_remaining"',
  'name:"traffic_package_id"',
  'name:"traffic_package_add_gb"',
  'name:"expired_at"',
  'name:"plan_id"',
  'name:"banned"',
];
```

Abort without writing if any anchor is missing or duplicated. This protects the other plan, order, and traffic-package admin screens in the same bundle.

- [ ] **Step 2: Build the target ordinary-subscription order**

Move the existing, unchanged control blocks into this order:

```text
name:"plan_id"
name:"expired_at"
name:"transfer_enable"
```

Do not change their field names, value conversion, calendar behavior, select options, labels, or payload handling.

- [ ] **Step 3: Insert the independent section header**

Immediately after the `transfer_enable` control and before `traffic_package_remaining`, insert the equivalent compiled element structure:

```jsx
<div className="border-t pt-4">
  <div className="text-sm font-medium">
    {t('edit.form.traffic_package_section_title')}
  </div>
  <p className="mt-1 text-xs text-muted-foreground">
    {t('edit.form.traffic_package_section_description')}
  </p>
</div>
```

In the minified bundle, use the existing aliases and translation function from `Ji`:

```js
e.jsxs("div",{className:"border-t pt-4",children:[
  e.jsx("div",{className:"text-sm font-medium",children:s("edit.form.traffic_package_section_title")}),
  e.jsx("p",{className:"mt-1 text-xs text-muted-foreground",children:s("edit.form.traffic_package_section_description")})
]})
```

- [ ] **Step 4: Preserve package and following-field order**

After the new header, keep the existing blocks in this exact order:

```text
name:"traffic_package_remaining"
name:"traffic_package_id"
name:"traffic_package_add_gb"
name:"banned"
```

All fields after `banned` retain their current order. Do not change grant validation, API loading, reset-to-null behavior, read-only balance rendering, or submission exclusion rules.

- [ ] **Step 5: Execute the guarded rewrite and remove the helper**

Run:

```bash
node scripts/patch-admin-user-traffic-package-section-layout.js
node --check public/assets/admin/assets/index.js
```

Expected: rewrite exits 0 and JavaScript syntax is valid. Delete the temporary helper with `apply_patch` before staging:

```text
*** Begin Patch
*** Delete File: scripts/patch-admin-user-traffic-package-section-layout.js
*** End Patch
```

The removal is safe because the helper is a newly created, task-specific temporary file and must not be included in the product.

### Task 3: Add synchronized section copy

**Files:**
- Modify: `public/assets/admin/locales/zh-CN.js`
- Modify: `public/assets/admin/locales/en-US.js`
- Modify: `public/assets/admin/locales/ko-KR.js`
- Test: `tests/admin-user-traffic-package-grant.test.js`

- [ ] **Step 1: Add Chinese copy under `user.edit.form`**

```json
"traffic_package_section_title": "独立流量包",
"traffic_package_section_description": "独立于套餐流量，不修改套餐及到期时间",
```

- [ ] **Step 2: Add English copy under `user.edit.form`**

```json
"traffic_package_section_title": "Independent Traffic Package",
"traffic_package_section_description": "Separate from plan traffic; does not change the plan or expiry",
```

- [ ] **Step 3: Add Korean copy under `user.edit.form`**

```json
"traffic_package_section_title": "독립 트래픽 패키지",
"traffic_package_section_description": "요금제 트래픽과 별개이며 요금제 또는 만료 시간을 변경하지 않습니다",
```

- [ ] **Step 4: Run syntax and focused tests**

```bash
node --check public/assets/admin/assets/index.js
node --check public/assets/admin/locales/zh-CN.js
node --check public/assets/admin/locales/en-US.js
node --check public/assets/admin/locales/ko-KR.js
node --test tests/admin-user-traffic-package-grant.test.js tests/admin-user-traffic-summary.test.js
```

Expected: all syntax commands exit 0 and both focused suites report zero failures.

- [ ] **Step 5: Commit the scoped UI change**

```bash
git add tests/admin-user-traffic-package-grant.test.js public/assets/admin/assets/index.js public/assets/admin/locales/zh-CN.js public/assets/admin/locales/en-US.js public/assets/admin/locales/ko-KR.js
git commit -m "fix: separate traffic packages in user editor"
```

### Task 4: Full verification and local runtime reload

**Files:**
- Verify all modified files above.
- Local Compose file: `docker-compose.yml`

- [ ] **Step 1: Run focused and full regressions**

```bash
node --test tests/admin-user-traffic-package-grant.test.js tests/admin-user-traffic-summary.test.js tests/stacked-traffic-package.test.js
node --test tests/*.test.js
git diff --check
```

Expected: zero failed Node tests and no whitespace errors. Record exact pass counts from fresh output.

- [ ] **Step 2: Review the scoped diff and worktree**

```bash
git diff HEAD~2 -- tests/admin-user-traffic-package-grant.test.js public/assets/admin/assets/index.js public/assets/admin/locales/zh-CN.js public/assets/admin/locales/en-US.js public/assets/admin/locales/ko-KR.js
git status --short
```

Expected: only the approved ordering, divider, title/description, locale keys, and tests differ; no temporary rewrite script remains.

- [ ] **Step 3: Commit this implementation plan**

```bash
git add docs/superpowers/plans/2026-08-27-admin-user-traffic-package-section-layout.md
git commit -m "docs: plan admin traffic package section layout"
```

- [ ] **Step 4: Recreate only the local Web container**

Capture the old Web ID, then run:

```bash
docker compose -f docker-compose.yml up -d --force-recreate --no-deps web
```

Expected: Web gets a new container ID. Redis stays healthy and Horizon remains running because this change touches only static admin assets.

- [ ] **Step 5: Verify local services and served artifact**

```bash
docker compose -f docker-compose.yml ps
docker compose -f docker-compose.yml exec -T redis redis-cli -h 127.0.0.1 ping
docker compose -f docker-compose.yml exec -T web php artisan horizon:status
curl -fsS http://127.0.0.1:7001/assets/admin/assets/index.js | rg 'traffic_package_section_title|traffic_package_section_description'
curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:7001/0e4564c2
```

Expected: all containers are up, Redis returns `PONG`, Horizon reports running, both new keys are present in the served bundle, and the current local admin URL returns HTTP `200`.
