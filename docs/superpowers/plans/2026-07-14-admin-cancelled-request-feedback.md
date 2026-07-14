# Admin Cancelled Request Feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent normal admin-page request cancellation from showing “未知异常” while giving real request timeouts a specific retry message.

**Architecture:** Keep the change at the existing Axios response-interceptor boundary in the tracked admin bundle. Add a focused static contract test for cancellation, timeout, and existing HTTP-error behavior; deploy only the verified `index.js` asset because no backend, schema, queue, or container topology changes are required.

**Tech Stack:** JavaScript, Axios, Node.js built-in test runner, Laravel Blade-hosted admin bundle, OpenResty, SSH/SCP.

---

## File structure

- Create `tests/admin-request-error-feedback.test.js`: locks the admin bundle's cancellation, timeout, and HTTP-error feedback contracts.
- Modify `public/assets/admin/assets/index.js`: adds cancellation and timeout classification to the existing Axios response interceptor.
- Read `docs/superpowers/specs/2026-07-14-admin-cancelled-request-feedback-design.md`: source of approved scope and production verification requirements.

### Task 1: Add the failing admin request-feedback contract test

**Files:**
- Create: `tests/admin-request-error-feedback.test.js`
- Read: `public/assets/admin/assets/index.js`

- [ ] **Step 1: Write the failing test**

Create `tests/admin-request-error-feedback.test.js` with:

```js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');
const adminBundle = fs.readFileSync(
  path.join(repoRoot, 'public/assets/admin/assets/index.js'),
  'utf8',
);

function extractResponseInterceptor(source) {
  const startMarker = 'Ot.interceptors.response.use(';
  const endMarker = 'const O={get:';
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);

  assert.notEqual(start, -1, 'admin Axios response interceptor exists');
  assert.notEqual(end, -1, 'admin request wrapper follows the interceptor');

  return source.slice(start, end);
}

test('admin request cancellation is rejected without showing a global error', () => {
  const interceptor = extractResponseInterceptor(adminBundle);
  const cancellationGuard =
    'ko.isCancel(s)||s?.code==="ERR_CANCELED"||s?.name==="AbortError"';
  const guardIndex = interceptor.indexOf(cancellationGuard);
  const notificationIndex = interceptor.indexOf('A.error(');

  assert.notEqual(guardIndex, -1, 'Axios and browser cancellation are detected');
  assert.notEqual(notificationIndex, -1, 'global error notification still exists');
  assert.ok(guardIndex < notificationIndex, 'cancellation exits before notification');
  assert.match(
    interceptor,
    /if\(ko\.isCancel\(s\)\|\|s\?\.code==="ERR_CANCELED"\|\|s\?\.name==="AbortError"\)return Promise\.reject\(s\)/,
  );
});

test('admin request timeouts use a specific retry message', () => {
  const interceptor = extractResponseInterceptor(adminBundle);

  assert.match(interceptor, /s\?\.code==="ECONNABORTED"/);
  assert.match(interceptor, /s\?\.code==="ETIMEDOUT"/);
  assert.match(interceptor, /\/timeout\/i\.test\(s\?\.message\|\|""\)/);
  assert.match(interceptor, /A\.error\(l\?"请求超时，请重试":/);
});

test('admin HTTP errors keep existing message precedence and status handling', () => {
  const interceptor = extractResponseInterceptor(adminBundle);

  assert.match(interceptor, /s\.response\?\.data\?\.message/);
  assert.match(interceptor, /\(n===401\|\|n===403\)&&ni\(\)/);
  assert.match(interceptor, /401:"登录已过期"/);
  assert.match(interceptor, /403:"没有权限"/);
  assert.match(interceptor, /404:"资源或接口不存在"/);
  assert.match(interceptor, /t\|\|\{401:/);
});
```

- [ ] **Step 2: Run the targeted test and verify it fails**

Run:

```bash
node --test tests/admin-request-error-feedback.test.js
```

Expected: the cancellation and timeout tests fail because the current interceptor immediately calls `A.error(...)` and contains none of the new guards.

- [ ] **Step 3: Confirm the failure is caused by the missing behavior**

Read the assertion output and verify it mentions the missing cancellation guard or timeout classifiers. If the failure is instead a missing interceptor marker, stop and re-extract the current bundle boundary before editing production code.

### Task 2: Implement the minimal Axios interceptor fix

**Files:**
- Modify: `public/assets/admin/assets/index.js`
- Test: `tests/admin-request-error-feedback.test.js`

- [ ] **Step 1: Replace only the response-interceptor expression**

Replace the existing expression:

```js
Ot.interceptors.response.use(s=>s?.data||{code:-1,message:"未知错误"},s=>{const n=s.response?.status,t=s.response?.data?.message;return(n===401||n===403)&&ni(),A.error(t||{401:"登录已过期",403:"没有权限",404:"资源或接口不存在"}[n]||"未知异常"),Promise.reject(s.response?.data||{data:null,code:-1,message:"未知错误"})});
```

with:

```js
Ot.interceptors.response.use(s=>s?.data||{code:-1,message:"未知错误"},s=>{if(ko.isCancel(s)||s?.code==="ERR_CANCELED"||s?.name==="AbortError")return Promise.reject(s);const n=s.response?.status,t=s.response?.data?.message,l=s?.code==="ECONNABORTED"||s?.code==="ETIMEDOUT"||/timeout/i.test(s?.message||"");return(n===401||n===403)&&ni(),A.error(l?"请求超时，请重试":t||{401:"登录已过期",403:"没有权限",404:"资源或接口不存在"}[n]||"未知异常"),Promise.reject(s.response?.data||{data:null,code:-1,message:"未知错误"})});
```

Use an exact single-occurrence patch. Abort if the old expression is not found exactly once.

- [ ] **Step 2: Run the targeted test and verify it passes**

Run:

```bash
node --test tests/admin-request-error-feedback.test.js
```

Expected: 3 tests pass, 0 fail.

- [ ] **Step 3: Verify the modified bundle parses**

Run:

```bash
node --check public/assets/admin/assets/index.js
```

Expected: exit code 0 with no syntax error.

- [ ] **Step 4: Run all repository Node contract tests**

Run:

```bash
node --test tests/*.test.js
```

Expected: all tests pass with 0 failures.

- [ ] **Step 5: Review the diff scope**

Run:

```bash
git diff --check
git diff --stat
git diff -- tests/admin-request-error-feedback.test.js public/assets/admin/assets/index.js
```

Expected: only the new test and the single interceptor expression are changed; `git diff --check` reports no whitespace errors.

- [ ] **Step 6: Commit the implementation**

Run:

```bash
git add tests/admin-request-error-feedback.test.js public/assets/admin/assets/index.js
git commit -m "fix: ignore cancelled admin requests"
```

Expected: one commit containing only the test and admin bundle change.

### Task 3: Integrate, deploy, and verify production

**Files:**
- Integrate: `tests/admin-request-error-feedback.test.js`
- Deploy: `public/assets/admin/assets/index.js`
- Preserve: all unrelated Android client and production-host changes

- [ ] **Step 1: Fast-forward the main worktree to the verified implementation branch**

Run from the main worktree:

```bash
git merge --ff-only codex/admin-cancel-feedback
```

Expected: the branch fast-forwards without modifying or staging unrelated Android files. If Git reports an overlap, stop instead of stashing or resetting user changes.

- [ ] **Step 2: Re-run verification from the main worktree**

Run:

```bash
node --test tests/admin-request-error-feedback.test.js
node --check public/assets/admin/assets/index.js
```

Expected: 3 tests pass and the bundle syntax check exits 0.

- [ ] **Step 3: Back up the live production asset**

On `47.238.145.117`, run:

```bash
cd /opt/1panel/apps/openresty/openresty/www/sites/xboard/index
backup="public/assets/admin/assets/index.js.pre-cancel-feedback-$(date +%Y%m%d_%H%M%S)"
cp -a public/assets/admin/assets/index.js "$backup"
sha256sum public/assets/admin/assets/index.js "$backup"
```

Expected: both hashes match before deployment. Do not alter other tracked or untracked production files.

- [ ] **Step 4: Upload only the verified admin bundle**

Run from the local main worktree using the authenticated SSH session:

```bash
scp public/assets/admin/assets/index.js root@47.238.145.117:/opt/1panel/apps/openresty/openresty/www/sites/xboard/index/public/assets/admin/assets/index.js
```

Expected: the transfer succeeds. No container recreation, migration, or Horizon restart is performed.

- [ ] **Step 5: Verify local, remote, and public asset contents match**

Run locally and remotely:

```bash
shasum -a 256 public/assets/admin/assets/index.js
ssh root@47.238.145.117 'sha256sum /opt/1panel/apps/openresty/openresty/www/sites/xboard/index/public/assets/admin/assets/index.js'
curl -fsS https://www.elephant111.com/assets/admin/assets/index.js | grep -o '请求超时，请重试' | head -n 1
curl -fsS https://www.elephant111.com/assets/admin/assets/index.js | grep -o 'ERR_CANCELED' | head -n 1
```

Expected: local and remote SHA-256 values match, and both production markers are returned.

- [ ] **Step 6: Verify the authenticated admin flow in Chrome**

Using the existing signed-in Chrome tab:

1. Reload `https://www.elephant111.com/cfc29397#/finance/order`.
2. Navigate immediately to the dashboard.
3. Confirm no “未知异常” notification appears.
4. Confirm the eight dashboard summary cards, income overview, traffic rankings, queue status, and system status finish loading.

Expected: the dashboard renders current values with no cancellation notification and no new console error.

- [ ] **Step 7: Verify production access logs and service health**

On the production host, run:

```bash
docker exec index-web-1 php artisan octane:status
docker exec index-horizon-1 php artisan horizon:status
tail -n 300 /opt/1panel/www/sites/www.elephant111.com/log/access.log | grep '/api/v2/' | tail -n 80
```

Expected: Octane and Horizon report running, dashboard API requests return HTTP 200, and no new 5xx response appears during verification.

- [ ] **Step 8: Roll back only if live verification fails**

If and only if the production asset fails syntax/content checks or the admin page regresses, restore the backup created in Step 3:

```bash
cd /opt/1panel/apps/openresty/openresty/www/sites/xboard/index
cp -a "$(ls -1t public/assets/admin/assets/index.js.pre-cancel-feedback-* | head -n 1)" public/assets/admin/assets/index.js
```

Expected: the previous asset is restored without changing application code, database state, queues, or containers.
