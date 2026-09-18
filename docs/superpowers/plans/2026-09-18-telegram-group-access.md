# Telegram Group Access Implementation Plan

> Execute task-by-task in the current checkout. The user approved the complete flow guide; no additional design approval is required. Keep production rollout and historical data writes separate from local implementation.

**Goal:** Permanent group eligibility from successful purchase or administrator plan grants, with identity-bound join requests approved by the existing general Bot.

**Architecture:** Store permanent entitlements separately from encrypted short-lived invitations and durable join requests. One eligibility service serves the user endpoint, webhook worker and historical backfill. Default the group switch off until the administrator validates the target group and completes historical review.

**Tech Stack:** Laravel 12, Eloquent, Redis locks, a dedicated Horizon Telegram group queue, vanilla dashboard JS/CSS and existing compiled admin settings.

## Tasks

- [x] Add `2026_09_18_000001_create_telegram_group_access_tables.php` and three models for entitlement (unique user), invitation (unique hash, encrypted URL), and request (unique Telegram update, durable status/attempts). Use integer timestamps consistent with Xboard.
- [x] Add `TelegramGroupEligibilityService`: successful nonzero cash/balance orders (completed/discounted), administrator grant detection, first-grant preservation, banned-account check. Wire `OrderService::open`, admin user update/create/batch creation; never infer grant from an unchanged submitted plan or free trial.
- [x] Add dry-run `telegram:group-backfill`; paid/manual-order history can be previewed/applied; ambiguous administrator grants require explicit user IDs, administrator ID and reason. No production write in this task.
- [x] Add `TelegramGroupAccessService`: validate private target and Bot permissions; identity/duplicate binding checks; ten-minute invite reuse under lock; membership read; persist webhook requests; strict source/invitation/date/identity recheck; approve/decline only target group; idempotent retry and revoke after successful approval.
- [x] Add Telegram API wrappers, queue job and periodic recovery command; redact invite URLs and return generic failures. Keep ticket Bot isolated.
- [x] Add authenticated `/telegram/group-status` and `/telegram/join-group`, admin readiness endpoint, validated switch/chat ID and explicit webhook update subscriptions. Remove raw group links from user config in all modes.
- [x] Update dashboard group flow and mirroring/cache markers. Keep async checks followed by explicit user click to open Telegram. Distinguish unavailable, ineligible, binding required, already member, and ready states.
- [x] Add compact controls to existing admin Telegram settings for group ID/switch/readiness. Preserve current Bot and ticket controls and regenerate compressed assets if present.
- [x] Add isolated SQLite/Eloquent runtime tests (purchase/free/admin/expiry/rollback, invite ownership/expiry/target/ban/duplicate/retry), route/config contracts and frontend interaction tests. Use mocked Telegram; never send real requests in tests.
- [x] Run focused Node and PHP tests, PHP/JS syntax, mirror equality, `git diff --check`; browser-test desktop/mobile with synthetic users, document any pre-existing failures separately.
- [x] Update rollout instructions with migration, preview/apply commands, queue reload, group settings prerequisites and rollback boundaries. Do not commit/push/deploy without a new request.

## Validation commands

`php tests/telegram-group-access.php`

`node --test tests/telegram-group-access.test.js tests/elephant-route-dashboard-v2.test.js tests/elephant-route-modal-notify.test.js tests/telegram-ticket-bot-isolation.test.js tests/telegram-http-runtime-safety.test.js tests/admin-ticket-telegram-settings.test.js`

`git diff --check`

## Acceptance

The approved guide's three eligibility sources remain valid after expiry; free trials and unpaid orders never grant access. Forwarded URLs cannot grant another Telegram identity access. Network failures stay pending and recover without treating emitted invitations as successful joins. Historical ambiguous grants are not guessed. No existing group members are removed.
