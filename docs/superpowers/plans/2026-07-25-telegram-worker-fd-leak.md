# Telegram Worker FD Leak Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the production Telegram worker's Swoole cURL FD leak and isolate Telegram failures from order processing.

**Architecture:** Send Telegram API requests through Symfony `NativeHttpClient`, with one bounded retry layer that understands Telegram 429 responses. Run `order_handle` and `send_telegram` in separate fixed Horizon supervisors, and recycle only the Telegram worker after a bounded job count or lifetime.

**Tech Stack:** PHP 8.2, Laravel 12, Horizon 5, Symfony HttpClient, Node.js contract tests, Docker Compose.

---

### Task 1: Add failing runtime-safety contracts

**Files:**
- Create: `tests/telegram-http-runtime-safety.test.js`
- Modify: `tests/horizon-fixed-workers.test.js`
- Modify: `tests/send-email-job-failure-reporting.test.js`

- [ ] **Step 1: Require native transport and bounded retries**

Add assertions that `TelegramService.php` imports and constructs
`NativeHttpClient`, contains no Laravel HTTP facade, reads `retry_after`, and caps the
delay. Require `SendTelegramJob` to use one queue attempt and a 50-second timeout.

- [ ] **Step 2: Require queue isolation**

Replace the `XboardCore` contract with one-worker `XboardOrder` and
`XboardTelegram` contracts. Require only `XboardTelegram` to contain
`maxJobs => 25` and `maxTime => 900`.

- [ ] **Step 3: Verify the contracts fail**

Run:

```bash
node --test tests/telegram-http-runtime-safety.test.js tests/horizon-fixed-workers.test.js tests/send-email-job-failure-reporting.test.js
```

Expected: failures report the missing native transport and missing split supervisors.

### Task 2: Replace the leaking HTTP path

**Files:**
- Modify: `app/Services/TelegramService.php`
- Modify: `app/Jobs/SendTelegramJob.php`
- Test: `tests/telegram-http-runtime-safety.test.js`

- [ ] **Step 1: Construct an injectable native HTTP client**

Replace `PendingRequest` with `HttpClientInterface`; default it to
`NativeHttpClient` configured with `Accept: application/json`, a 10-second inactivity
timeout, and a 10-second maximum request duration.

- [ ] **Step 2: Add one bounded retry loop**

Send query parameters through the native client, fully consume and decode the response,
retry transport errors and 5xx after 1 second, and retry 429 after
`min(max(retry_after, 1), 5)` seconds. Stop after 3 attempts and preserve the existing
`ApiException` and MySQL logging boundary.

- [ ] **Step 3: Remove multiplicative queue retries**

Set `SendTelegramJob::$tries` to 1 and `$timeout` to 50. The service remains the single
owner of request attempts.

- [ ] **Step 4: Run focused verification**

Run:

```bash
php -l app/Services/TelegramService.php
php -l app/Jobs/SendTelegramJob.php
node --test tests/telegram-http-runtime-safety.test.js tests/telegram-ticket-inline-actions.test.js
```

Expected: PHP syntax checks and all focused Node tests pass.

### Task 3: Isolate and recycle the Telegram worker

**Files:**
- Modify: `config/horizon.php`
- Test: `tests/horizon-fixed-workers.test.js`
- Test: `tests/send-email-job-failure-reporting.test.js`

- [ ] **Step 1: Replace the shared Core supervisor**

Create `XboardOrder` for `order_handle` and `XboardTelegram` for `send_telegram`.
Both remain fixed at one process with balancing disabled. Add `maxJobs => 25` and
`maxTime => 900` only to `XboardTelegram`.

- [ ] **Step 2: Run configuration verification**

Run:

```bash
php -l config/horizon.php
node --test tests/horizon-fixed-workers.test.js tests/send-email-job-failure-reporting.test.js
```

Expected: syntax is valid and all configuration contracts pass.

- [ ] **Step 3: Run the full repository Node suite**

Run:

```bash
node --test tests/*.test.js
```

Expected: zero failing tests.

### Task 4: Commit, deploy, and prove runtime recovery

**Files:**
- Production source: `/opt/1panel/apps/openresty/openresty/www/sites/xboard/index`
- Production backup: `/root/xboard-telegram-fd-backup-<timestamp>/`

- [ ] **Step 1: Commit and push only scoped files**

Stage the two docs, three application/config files, and three focused tests. Commit on
the current `master` branch and push `origin/master`, preserving unrelated client work.

- [ ] **Step 2: Back up and fast-forward production**

Verify the live tree's scoped files are clean, copy the three production files into the
timestamped backup directory, fetch `origin/master`, and use `git merge --ff-only
origin/master`.

- [ ] **Step 3: Recreate only Horizon**

Run:

```bash
docker compose -f docker-compose.yaml up -d --force-recreate horizon
```

Do not recreate Web, Redis, MariaDB, or Docker.

- [ ] **Step 4: Verify supervisors and FD stability**

Confirm Horizon is running, both new supervisors have one worker, queue lengths remain
near zero, and a native-client request loop keeps its process FD count stable. Sample
the live Telegram worker PID, FD, RSS, and Telegram `CLOSE_WAIT` count.

- [ ] **Step 5: Verify failure and endpoint health**

Confirm no new `No file descriptors available` rows appear after deployment, the
internal guest endpoint returns 200, and the public site responds normally.
