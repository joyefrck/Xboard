# Wallet Recharge Implementation Plan

> **For agentic workers:** Execute the following tasks inline in this session; user approval covers implementation and verification.

**Goal:** Add fixed-amount wallet recharge using existing payment channels, fees, callbacks and order lifecycle.

**Architecture:** Add a recharge discriminator to existing orders and a dedicated create endpoint. Credit the locked user exactly once inside order completion. Add a readable, dependency-injected Vue module for recharge and header balance, connected to the existing bundled router and profile.

**Tech Stack:** Laravel/Eloquent, PHP, Vue 3/Naive UI bundled theme, Node test runner, isolated SQLite fixtures, Playwright browser QA.

---

### Task 1: Recharge order lifecycle

Files: `app/Models/Order.php`, `app/Services/OrderService.php`, `app/Http/Controllers/V1/User/OrderController.php`, `app/Http/Routes/V1/UserRoute.php`, `app/Services/TelegramGroupEligibilityService.php`, `tests/wallet-recharge.php`.

- [x] Define `TYPE_RECHARGE = 6`, `PERIOD_RECHARGE = 'recharge'`, `RECHARGE_AMOUNTS = [10000, 20000, 50000]`, and `isRecharge(): bool` matching both type and period.
- [x] Add `createRechargeFromRequest(User $user, int $amount): Order`: validate amount; transactionally lock user; reject pending/processing orders; create planless order with no discounts, balance deduction or commission.
- [x] Add `POST /user/order/recharge` with `amount` validated as `required|integer|in:10000,20000,50000`. Return existing trade number response shape.
- [x] Permit recharge detail without a plan. In `OrderService::open`, branch recharge before plan handling; validate amount and empty balance deduction, increase user balance and complete order in one transaction. Reuse existing `paid`, `checkout`, `cancel` and order worker.
- [x] Exclude recharge from `isPaidOrder`, `paidOrders` and manual-operation entitlement grants.
- [x] Run `php tests/wallet-recharge.php`; cover all amounts, invalid amounts, pending order guard, repeated callback/task, cancel, rollback, ownership, original fee calculation and unchanged subscription fields.

### Task 2: User recharge flow

Files: `theme/ElephantRoute/assets/elephant-route-wallet.js`, `theme/ElephantRoute/assets/elephant-route-wallet.css`, `theme/ElephantRoute/assets/umi.js`, theme/public Blade views and mirror assets.

- [x] Create a UMD module exposing `createComponents` with Vue/runtime dependencies supplied by the existing bundle. Export fee calculation helpers for tests. Fee is `Math.round(amount * Number(method.handling_fee_percent || 0) / 100 + Number(method.handling_fee_fixed || 0))`.
- [x] Create a Vue recharge page with 100/200/500 yuan buttons, ordered payment choices, retry state, summary and disabled/busy submit. Create one order, call existing checkout using selected channel, and navigate to existing order detail or provider URL; pass QR checkout data to existing order detail without another checkout call.
- [x] Add hidden `wallet/recharge` child route to the profile shell. Add wallet entry and a header component immediately before the avatar. Refresh authenticated user state on mount/focus and unregister listeners on unmount.
- [x] Adapt shared order detail/list labels and amount calculation for recharge; suppress product-flow and tutorial text for recharge. Keep existing subscription behavior.
- [x] Mirror resources into `public/theme/ElephantRoute`, allowlist new public files, bump cache versions and regenerate the gzip/Brotli siblings of modified tracked bundles.
- [x] Run `node --test tests/wallet-recharge.test.js tests/elephant-route-*.test.js` and resolve regressions.

### Task 3: Admin compatibility

Files: `public/assets/admin/assets/index.js`, admin cache entrypoints as required by `scripts/version-admin-assets.php`.

- [x] Label type 6 and recharge period as `余额充值` in order records/details/filter options; handle absent plan name without synthesizing a plan association.
- [x] Verify recharge statistics/commission consumers cannot grant duplicate commission or count recharge as a subscription entitlement. Keep existing payment income reporting behavior.
- [x] Run `php tests/admin-order-user-filter.php` and related admin asset tests with Node.

### Task 4: Browser and completion checks

Files: `tests/wallet-recharge-preview.cjs`, `tests/wallet-recharge.test.js` and disposable browser outputs under `output/wallet-recharge/`.

- [x] Start a localhost fixture serving real theme assets and in-memory APIs. Verify profile/dashboard entries, direct route reload, channel sort, amounts/fees, duplicate-submit prevention, no channels, failure/retry, QR and redirect, paid-state balance refresh, desktop/mobile layout.
- [x] Run focused PHP lifecycle tests and existing payment/concurrency fixtures that can execute locally. Run `node --test tests/elephant-route-*.test.js tests/wallet-recharge.test.js tests/stacked-traffic-package.test.js tests/traffic-package-purchase-confirmation.test.js`.
- [x] Run `git diff --check`, PHP syntax checks for changed PHP files, JS syntax checks and theme/public byte comparison. Report limitations separately, with no production release implied.

## Verification result — 2026-11-05

- `php tests/wallet-recharge.php`: 103 checks passed, including real order controller and callback paths with the external provider mocked.
- Focused Node regression suite: 123 tests passed, zero failures.
- Existing security, Telegram eligibility and admin order-filter PHP checks passed.
- Desktop and 320px/390px browser checks passed: both recharge entries, ordered methods, channel retry, payment retry, provider redirect, square QR, completion and balance refresh.
- PHP/JS syntax, admin asset content version, compressed bundle decompression and theme/public byte equality passed.
- No production deployment or real payment performed. SQLite tests do not establish production MySQL concurrency behavior.
- Also excluded recharge from first-purchase commission history and Telegram entitlement backfill; added recharge labels to ticket order history.
