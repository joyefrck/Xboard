# Dedicated Ticket Telegram Bot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add a separately configured Telegram bot that exclusively delivers and operates tickets, including contextual user and order views, without changing the general bot's non-ticket behavior.

**Architecture:** Keep `TelegramService` as the bounded HTTP transport but make bot credentials explicit. Route ticket events through a dedicated notifier/job and ticket Webhook through a focused handler; the handler authorizes every action from Telegram `from_id` and resolves user/order data only through the ticket relationship. Extend the compiled admin Telegram settings at its existing component boundary and protect all contracts with focused Node tests plus PHP syntax and route checks.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, Redis queues, Laravel Horizon, Symfony NativeHttpClient, Telegram Bot API, compiled React admin assets, Node.js built-in test runner.

---

### Task 1: Lock the two-bot transport and credential contracts

**Files:**
- Create: `tests/telegram-ticket-bot-isolation.test.js`
- Create: `app/Services/TelegramBotProfile.php`
- Create: `app/Services/TelegramCredentialService.php`
- Modify: `app/Services/TelegramService.php`
- Modify: `app/Jobs/SendTelegramJob.php`

- [x] **Step 1: Write the failing profile and credential contract tests**

```js
test('Telegram bot profiles resolve separate settings without serializing tokens', () => {
  const profile = readRepoFile('app/Services/TelegramBotProfile.php');
  const credentials = readRepoFile('app/Services/TelegramCredentialService.php');
  const job = readRepoFile('app/Jobs/SendTelegramJob.php');
  assert.match(profile, /case GENERAL = 'general'/);
  assert.match(profile, /case TICKET = 'ticket'/);
  assert.match(credentials, /Crypt::encryptString/);
  assert.match(credentials, /telegram_ticket_bot_token/);
  assert.doesNotMatch(job, /botToken|telegram_ticket_bot_token/);
});
```

- [x] **Step 2: Run the new test and verify the missing files fail**

Run: `node --test tests/telegram-ticket-bot-isolation.test.js`

Expected: FAIL because `TelegramBotProfile.php` and `TelegramCredentialService.php` do not exist.

- [x] **Step 3: Implement explicit profiles and encrypted ticket credentials**

```php
enum TelegramBotProfile: string
{
    case GENERAL = 'general';
    case TICKET = 'ticket';

    public function tokenSetting(): string
    {
        return $this === self::TICKET ? 'telegram_ticket_bot_token' : 'telegram_bot_token';
    }
}
```

`TelegramCredentialService` must encrypt/decrypt only the ticket token and Webhook secret, while reading the legacy general token unchanged. `TelegramService` receives `TelegramBotProfile` or an explicit one-time token and never treats a provided token as an `admin_setting()` fallback.

- [x] **Step 4: Run transport tests**

Run: `node --test tests/telegram-ticket-bot-isolation.test.js tests/telegram-http-runtime-safety.test.js`

Expected: all tests PASS and the existing NativeHttpClient/retry assertions remain green.

- [x] **Step 5: Commit the transport boundary**

```bash
git add tests/telegram-ticket-bot-isolation.test.js app/Services/TelegramBotProfile.php app/Services/TelegramCredentialService.php app/Services/TelegramService.php app/Jobs/SendTelegramJob.php
git commit -m "refactor: isolate telegram bot credentials"
```

### Task 2: Add the ticket queue and atomic outbound routing

**Files:**
- Create: `app/Jobs/SendTicketTelegramJob.php`
- Create: `app/Services/Telegram/TicketTelegramNotifier.php`
- Modify: `plugins/Telegram/Plugin.php`
- Modify: `config/horizon.php`
- Modify: `tests/horizon-fixed-workers.test.js`
- Modify: `tests/telegram-ticket-bot-isolation.test.js`

- [x] **Step 1: Add failing queue isolation assertions**

```js
test('ticket delivery has a dedicated bounded queue', () => {
  const job = readRepoFile('app/Jobs/SendTicketTelegramJob.php');
  const horizon = readRepoFile('config/horizon.php');
  assert.match(job, /onQueue\('send_ticket_telegram'\)/);
  assert.match(job, /TelegramBotProfile::TICKET/);
  assert.match(horizon, /'XboardTicketTelegram'/);
  assert.match(horizon, /'queue'\s*=>\s*\['send_ticket_telegram'\]/);
});
```

- [x] **Step 2: Verify the new queue test fails**

Run: `node --test tests/telegram-ticket-bot-isolation.test.js tests/horizon-fixed-workers.test.js`

Expected: FAIL because the ticket job/supervisor do not exist.

- [x] **Step 3: Implement the ticket job, notifier, and route switch**

```php
if ((bool) admin_setting('telegram_ticket_bot_enable', false)) {
    SendTicketTelegramJob::dispatch($telegramId, $message, $options);
} else {
    SendTelegramJob::dispatch($telegramId, $message, $options);
}
```

The plugin checks its existing `enable_ticket_notify` value before calling the notifier. The notifier sends to admin/staff recipients, selects the queue only from `telegram_ticket_bot_enable`, and never falls back after a ticket-job failure. Configure `XboardTicketTelegram` with one process, `tries=1`, `maxJobs=25`, and `maxTime=900`.

- [x] **Step 4: Run queue and existing ticket notification tests**

Run: `node --test tests/telegram-ticket-bot-isolation.test.js tests/horizon-fixed-workers.test.js tests/telegram-ticket-inline-actions.test.js`

Expected: all tests PASS.

- [x] **Step 5: Commit outbound isolation**

```bash
git add app/Jobs/SendTicketTelegramJob.php app/Services/Telegram/TicketTelegramNotifier.php plugins/Telegram/Plugin.php config/horizon.php tests/horizon-fixed-workers.test.js tests/telegram-ticket-bot-isolation.test.js
git commit -m "feat: route tickets through dedicated telegram queue"
```

### Task 3: Implement the dedicated ticket Webhook and operations

**Files:**
- Create: `app/Http/Controllers/V1/Guest/TicketTelegramController.php`
- Create: `app/Services/Telegram/TelegramUpdateParser.php`
- Create: `app/Services/Telegram/TicketTelegramHandler.php`
- Modify: `app/Http/Routes/V1/GuestRoute.php`
- Modify: `app/Services/TicketService.php`
- Modify: `app/Http/Controllers/V2/Admin/TicketController.php`
- Modify: `plugins/Telegram/Plugin.php`
- Modify: `tests/telegram-ticket-inline-actions.test.js`
- Modify: `tests/telegram-ticket-bot-isolation.test.js`

- [x] **Step 1: Write failing Webhook, authorization, and action tests**

```js
test('ticket webhook verifies the Telegram secret header', () => {
  const controller = readRepoFile('app/Http/Controllers/V1/Guest/TicketTelegramController.php');
  assert.match(controller, /X-Telegram-Bot-Api-Secret-Token/);
  assert.match(controller, /hash_equals/);
  assert.match(controller, /TicketTelegramHandler/);
});

test('ticket actions include contextual user and order callbacks', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');
  assert.match(handler, /ticket:user:\{\$ticketId\}/);
  assert.match(handler, /ticket:orders:\{\$ticketId\}:1/);
  assert.match(handler, /User::where\('telegram_id', \$msg->from_id\)/);
});
```

- [x] **Step 2: Run the action tests and verify failure**

Run: `node --test tests/telegram-ticket-inline-actions.test.js tests/telegram-ticket-bot-isolation.test.js`

Expected: FAIL because the controller/parser/handler are missing.

- [x] **Step 3: Move ticket parsing and operations into focused services**

`TelegramUpdateParser` returns the current normalized message object for `message`, `reply_message`, and `callback_query`. `TicketTelegramHandler` owns `/start`, `/ticket`, reply matching, ticket history, reply prompt, close confirmation, user view, and order view. Every public entry calls:

```php
$operator = User::where('telegram_id', $msg->from_id)
    ->where(fn ($query) => $query->where('is_admin', 1)->orWhere('is_staff', 1))
    ->first();
```

Add `TicketService::closeByAdmin(int $ticketId, int $operatorId): void` and use it from Telegram and the admin controller. General-bot ticket commands return the configured migration message once the ticket profile is enabled.

- [x] **Step 4: Run PHP syntax and focused action tests**

Run: `find app/Services/Telegram app/Http/Controllers/V1/Guest app/Services/TicketService.php plugins/Telegram/Plugin.php -name '*.php' -print0 | xargs -0 -n1 php -l`

Run: `node --test tests/telegram-ticket-inline-actions.test.js tests/telegram-ticket-bot-isolation.test.js`

Expected: every PHP file reports no syntax errors and all focused tests PASS.

- [x] **Step 5: Commit incoming ticket operations**

```bash
git add app/Http/Controllers/V1/Guest/TicketTelegramController.php app/Services/Telegram/TelegramUpdateParser.php app/Services/Telegram/TicketTelegramHandler.php app/Http/Routes/V1/GuestRoute.php app/Services/TicketService.php app/Http/Controllers/V2/Admin/TicketController.php plugins/Telegram/Plugin.php tests/telegram-ticket-inline-actions.test.js tests/telegram-ticket-bot-isolation.test.js
git commit -m "feat: handle tickets in dedicated telegram bot"
```

### Task 4: Add contextual user and order presentation whitelists

**Files:**
- Modify: `app/Services/Telegram/TicketTelegramHandler.php`
- Modify: `tests/telegram-ticket-bot-isolation.test.js`

- [x] **Step 1: Add failing privacy and pagination assertions**

```js
test('user and order views resolve through ticket and exclude credentials', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');
  assert.match(handler, /Ticket::with\(\['user\.plan'/);
  assert.match(handler, /->where\('user_id', \$ticket->user_id\)/);
  assert.match(handler, /forPage|paginate/);
  assert.doesNotMatch(handler, /subscribe_url|password_algo|password_salt|callback_no/);
});
```

- [x] **Step 2: Run the privacy test and verify failure**

Run: `node --test tests/telegram-ticket-bot-isolation.test.js`

Expected: FAIL until both views and their whitelists are present.

- [x] **Step 3: Implement five-order pages and explicit user fields**

Build user text from individual properties only. Query orders through `Order::with(['plan', 'trafficPackage', 'payment'])->where('user_id', $ticket->user_id)->latest('created_at')`, calculate pages at five items each, and render only trade number, product, type, status, amount, payment method, creation time, and paid time.

- [x] **Step 4: Run focused tests**

Run: `node --test tests/telegram-ticket-bot-isolation.test.js tests/telegram-ticket-inline-actions.test.js`

Expected: all tests PASS.

- [x] **Step 5: Commit contextual views**

```bash
git add app/Services/Telegram/TicketTelegramHandler.php tests/telegram-ticket-bot-isolation.test.js
git commit -m "feat: show ticket user and orders in telegram"
```

### Task 5: Add protected admin configuration and Webhook setup

**Files:**
- Modify: `app/Http/Controllers/V2/Admin/ConfigController.php`
- Modify: `app/Http/Requests/Admin/ConfigSave.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`
- Modify: `app/Services/TelegramService.php`
- Modify: `public/assets/admin/assets/index.js`
- Modify: `public/assets/admin/locales/en-US.js`
- Modify: `public/assets/admin/locales/ko-KR.js`
- Modify: `public/assets/admin/locales/zh-CN.js`
- Create: `tests/admin-ticket-telegram-settings.test.js`

- [x] **Step 1: Write failing configuration and compiled-UI tests**

```js
test('ticket token is write only and ticket webhook setup is separate', () => {
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/ConfigController.php');
  assert.match(controller, /setTicketTelegramToken/);
  assert.match(controller, /setTicketTelegramWebhook/);
  assert.match(controller, /telegram_ticket_bot_configured/);
  assert.doesNotMatch(controller, /'telegram_ticket_bot_token'\s*=>\s*admin_setting/);
});
```

```js
test('compiled admin exposes protected ticket bot controls', () => {
  const admin = readRepoFile('public/assets/admin/assets/index.js');
  const locale = readRepoFile('public/assets/admin/locales/zh-CN.js');
  assert.match(admin, /telegram_ticket_bot_enable/);
  assert.match(admin, /telegram_ticket_bot_token/);
  assert.match(admin, /setTicketTelegramWebhook/);
  assert.match(admin, /type:"password"/);
  assert.match(locale, /工单机器人设置/);
});
```

- [x] **Step 2: Run the admin setting test and verify failure**

Run: `node --test tests/admin-ticket-telegram-settings.test.js`

Expected: FAIL because the routes, endpoints, and UI fields do not exist.

- [x] **Step 3: Implement sanitized endpoints and compiled settings controls**

Add authenticated endpoints to replace/validate the encrypted ticket token, register the Webhook with `secret_token` and `allowed_updates`, fetch sanitized status, and test-send to the authenticated administrator's Telegram ID. Patch the compiled Telegram settings component and all shipped locales at the smallest stable string/component boundary. Change both token inputs to password type without returning the stored ticket token.

- [x] **Step 4: Run admin contracts and PHP syntax checks**

Run: `node --test tests/admin-ticket-telegram-settings.test.js tests/telegram-ticket-bot-isolation.test.js`

Run: `php -l app/Http/Controllers/V2/Admin/ConfigController.php && php -l app/Http/Requests/Admin/ConfigSave.php && php -l app/Http/Routes/V2/AdminRoute.php`

Expected: all tests PASS and every file reports no syntax errors.

- [x] **Step 5: Commit administration support**

```bash
git add app/Http/Controllers/V2/Admin/ConfigController.php app/Http/Requests/Admin/ConfigSave.php app/Http/Routes/V2/AdminRoute.php app/Services/TelegramService.php public/assets/admin/assets/index.js public/assets/admin/locales/en-US.js public/assets/admin/locales/ko-KR.js public/assets/admin/locales/zh-CN.js tests/admin-ticket-telegram-settings.test.js tests/telegram-ticket-bot-isolation.test.js
git commit -m "feat: configure dedicated ticket telegram bot"
```

### Task 6: Complete regression and repository verification

**Files:**
- Modify: `tests/telegram-ticket-bot-isolation.test.js`
- Modify: `tests/telegram-ticket-inline-actions.test.js`
- Modify: `tests/admin-ticket-telegram-settings.test.js`
- Modify: `docs/superpowers/plans/2026-08-19-dedicated-ticket-telegram-bot.md`

- [x] **Step 1: Add cutover and secret-leak assertions**

```js
test('ticket cutover never falls back or exposes credentials', () => {
  const notifier = readRepoFile('app/Services/Telegram/TicketTelegramNotifier.php');
  const legacyPlugin = readRepoFile('plugins/Telegram/Plugin.php');
  const job = readRepoFile('app/Jobs/SendTicketTelegramJob.php');
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/ConfigController.php');
  assert.match(notifier, /telegram_ticket_bot_enable/);
  assert.doesNotMatch(notifier, /catch[\s\S]*SendTelegramJob::dispatch/);
  assert.match(legacyPlugin, /工单功能已迁移/);
  assert.doesNotMatch(job, /token|secret/i);
  assert.doesNotMatch(controller, /'telegram_ticket_bot_token'\s*=>\s*admin_setting/);
  assert.doesNotMatch(controller, /'telegram_ticket_webhook_secret'\s*=>/);
});
```

- [x] **Step 2: Run all repository Node tests**

Run: `node --test tests/*.test.js`

Expected: all tests PASS with zero failures.

Verification note: the dedicated ticket-bot suites pass. The repository-wide run reports 160/161 passing; the sole failure is the pre-existing macOS beta packaging contract (`APP_DISTRIBUTION_URL`) and is unrelated to Telegram files.

- [x] **Step 3: Run complete PHP syntax verification for changed PHP files**

Run: `git diff --name-only HEAD~5 -- '*.php' | xargs -n1 php -l`

Expected: every changed PHP file reports `No syntax errors detected`.

- [x] **Step 4: Verify Laravel route registration and diff hygiene**

Run: `php artisan route:list --path=telegram`

Expected: both general and ticket Webhook routes plus the ticket administration endpoints appear.

Run: `git diff --check && git status --short`

Expected: no whitespace errors and only the intended implementation/plan changes are present.

- [x] **Step 5: Mark this plan's completed checkboxes and commit verification metadata**

```bash
git add docs/superpowers/plans/2026-08-19-dedicated-ticket-telegram-bot.md tests/telegram-ticket-bot-isolation.test.js tests/telegram-ticket-inline-actions.test.js tests/admin-ticket-telegram-settings.test.js
git commit -m "test: verify dedicated ticket telegram bot"
```
