# Horizon Fixed Workers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the burst-sensitive shared Horizon auto-scaler with isolated fixed worker pools for the production node-reporting queues.

**Architecture:** Keep queue producers and job implementations unchanged. Split `traffic_fetch`, `stat`, `online_sync`, and the low-volume core queues into dedicated Horizon supervisors with `balance` disabled and equal minimum/maximum process counts, so Horizon cannot create and retire workers in response to short queue bursts.

**Tech Stack:** Laravel Horizon 5.40, PHP configuration, Node.js contract tests, Docker Compose production deployment.

---

### Task 1: Add a failing Horizon configuration contract

**Files:**
- Create: `tests/horizon-fixed-workers.test.js`

- [ ] **Step 1: Write the failing test**

```js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const horizon = fs.readFileSync(
  path.resolve(__dirname, '..', 'config/horizon.php'),
  'utf8',
);

function supervisor(name) {
  const match = horizon.match(
    new RegExp(`'${name}'\\\\s*=>\\\\s*\\\\[([\\\\s\\\\S]*?)\\\\n\\\\s{12}\\\\],`),
  );
  assert.ok(match, `expected ${name} supervisor config`);
  return match[1];
}

function assertFixedSupervisor(name, queues, processes) {
  const config = supervisor(name);
  for (const queue of queues) {
    assert.match(config, new RegExp(`'${queue}'`));
  }
  assert.match(config, /'balance'\s*=>\s*false/);
  assert.match(config, new RegExp(`'minProcesses'\\\\s*=>\\\\s*${processes}`));
  assert.match(config, new RegExp(`'maxProcesses'\\\\s*=>\\\\s*${processes}`));
}

test('high-frequency queues use isolated fixed Horizon workers', () => {
  assertFixedSupervisor('XboardTraffic', ['traffic_fetch'], 2);
  assertFixedSupervisor('XboardStat', ['stat'], 1);
  assertFixedSupervisor('XboardOnline', ['online_sync'], 1);
  assertFixedSupervisor('XboardCore', ['order_handle', 'send_telegram'], 1);
  assert.doesNotMatch(horizon, /'Xboard'\s*=>\s*\[/);
});
```

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
node --test tests/horizon-fixed-workers.test.js
```

Expected: FAIL because `XboardTraffic` does not exist.

### Task 2: Replace the shared auto-scaling supervisor

**Files:**
- Modify: `config/horizon.php`
- Test: `tests/horizon-fixed-workers.test.js`
- Test: `tests/send-email-job-failure-reporting.test.js`

- [ ] **Step 1: Add the fixed supervisors**

Replace the `Xboard` configuration with:

```php
'XboardTraffic' => [
    'connection' => 'redis',
    'queue' => ['traffic_fetch'],
    'balance' => false,
    'minProcesses' => 2,
    'maxProcesses' => 2,
    'tries' => 1,
],
'XboardStat' => [
    'connection' => 'redis',
    'queue' => ['stat'],
    'balance' => false,
    'minProcesses' => 1,
    'maxProcesses' => 1,
    'tries' => 1,
],
'XboardOnline' => [
    'connection' => 'redis',
    'queue' => ['online_sync'],
    'balance' => false,
    'minProcesses' => 1,
    'maxProcesses' => 1,
    'tries' => 1,
],
'XboardCore' => [
    'connection' => 'redis',
    'queue' => [
        'order_handle',
        'send_telegram',
    ],
    'balance' => false,
    'minProcesses' => 1,
    'maxProcesses' => 1,
    'tries' => 1,
],
```

- [ ] **Step 2: Update the existing email isolation test**

Change the assertion that searches for the removed `Xboard` supervisor so it instead verifies that none of `XboardTraffic`, `XboardStat`, `XboardOnline`, or `XboardCore` contains either email queue.

- [ ] **Step 3: Run focused verification**

Run:

```bash
node --test tests/horizon-fixed-workers.test.js tests/send-email-job-failure-reporting.test.js
php -l config/horizon.php
```

Expected: all Node tests pass and PHP reports no syntax errors.

- [ ] **Step 4: Run the full Node regression suite**

Run:

```bash
node --test tests/*.test.js
```

Expected: all tests pass.

- [ ] **Step 5: Commit and push only the scoped files**

```bash
git add config/horizon.php tests/horizon-fixed-workers.test.js tests/send-email-job-failure-reporting.test.js
git commit -m "perf: stabilize Horizon queue workers"
git push origin master
```

### Task 3: Deploy and verify production

**Files:**
- Production source: `/opt/1panel/apps/openresty/openresty/www/sites/xboard/index`
- Production backup: `/root/xboard-horizon-backup-<timestamp>/config/horizon.php`

- [ ] **Step 1: Confirm the remote tree can fast-forward safely**

Run read-only checks for the current commit, branch, status of scoped files, Compose services, and Horizon health.

- [ ] **Step 2: Back up the live Horizon configuration**

Create a timestamped backup directory under `/root` and copy only `config/horizon.php` into it.

- [ ] **Step 3: Fast-forward the production source**

Fetch `origin/master`, verify the only incoming queue change is the approved commit, then fast-forward with:

```bash
git merge --ff-only origin/master
```

- [ ] **Step 4: Recreate only Horizon**

```bash
docker compose -f docker-compose.yaml up -d --force-recreate horizon
```

- [ ] **Step 5: Verify runtime behavior**

Confirm:

- `php artisan horizon:status` reports running;
- `horizon:supervisors` shows exactly the fixed supervisors and worker counts;
- worker PIDs remain stable during repeated samples;
- queue sizes remain near zero;
- failed jobs do not increase;
- repeated `docker stats` and `mpstat` samples show the sustained CPU effect.

- [ ] **Step 6: Roll back on failure**

If Horizon fails to start or queues grow continuously, restore the backed-up configuration and recreate only `horizon`.
