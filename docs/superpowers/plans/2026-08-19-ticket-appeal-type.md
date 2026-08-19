# Ticket Appeal Type Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace ticket priority with a required three-choice appeal type while keeping promotion commission withdrawals as a distinct system-only type.

**Architecture:** Preserve the `level` storage/API key for compatibility and centralize its new numeric contract on the `Ticket` model. Migrate legacy data, patch the ticket-specific mappings in the compiled user/admin assets, and lock the cross-layer contract with focused Node source tests plus PHP syntax checks.

**Tech Stack:** PHP 8.2, Laravel 12, MySQL migrations, compiled Vue/React JavaScript assets, Node.js built-in test runner

---

### Task 1: Add failing contract coverage

**Files:**
- Create: `tests/ticket-appeal-types.test.js`

- [ ] **Step 1: Write source-contract tests**

Assert that the model exposes `TYPE_NODE_ISSUE = 0`, `TYPE_REFUND = 1`, `TYPE_USAGE_GUIDE = 2`, and `TYPE_COMMISSION_WITHDRAWAL = 3`; manual validation only accepts the first three values; the withdrawal controller uses the system constant; the migration makes `level` nullable and separates historical withdrawal rows; user/admin bundles render all four labels and preserve blank `NULL` values.

- [ ] **Step 2: Run the focused test and confirm red state**

Run: `node --test tests/ticket-appeal-types.test.js`

Expected: FAIL because the appeal-type constants, migration, and UI mappings do not exist yet.

### Task 2: Implement the backend type contract and migration

**Files:**
- Modify: `app/Models/Ticket.php`
- Modify: `app/Http/Requests/User/TicketSave.php`
- Modify: `app/Http/Controllers/V1/User/TicketController.php`
- Modify: `database/migrations/2023_03_19_000000_create_v2_tables.php`
- Create: `database/migrations/2026_08_19_000001_convert_ticket_level_to_appeal_type.php`
- Modify: `resources/lang/zh-CN.json`
- Modify: `resources/lang/zh-TW.json`
- Modify: `resources/lang/en-US.json`

- [ ] **Step 1: Define the numeric contract on `Ticket`**

Add the four `TYPE_*` constants and a manual-type list returning only `0`, `1`, and `2`. Update the model property comment from priority to nullable appeal type.

- [ ] **Step 2: Enforce manual-only types**

Change `TicketSave` validation to `required|integer|in:0,1,2` and replace priority error messages with appeal-type wording in all three locale JSON files.

- [ ] **Step 3: Keep withdrawal creation system-only**

Replace the withdrawal controller's literal `2` with `Ticket::TYPE_COMMISSION_WITHDRAWAL`.

- [ ] **Step 4: Migrate schema and historical rows**

Make `v2_ticket.level` nullable in the base schema. In the upgrade migration, make the column nullable, set all rows to `NULL`, then assign `3` to the exact English, simplified-Chinese, and traditional-Chinese system withdrawal subjects. In `down()`, map `3` to `2`, map remaining `NULL` to `0`, and restore the non-null column.

- [ ] **Step 5: Run backend syntax checks**

Run `php -l` on every modified PHP file and the new migration.

Expected: every command reports `No syntax errors detected`.

### Task 3: Update the ElephantRoute user interface

**Files:**
- Modify: `theme/ElephantRoute/assets/umi.js`
- Modify: `theme/ElephantRoute/assets/umi.js.gz`
- Modify: `theme/ElephantRoute/assets/umi.js.br`
- Modify: `public/theme/ElephantRoute/assets/umi.js`
- Modify: `theme/ElephantRoute/assets/elephant-route-dashboard.js`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-dashboard.js`
- Modify: `tests/elephant-route-dashboard-actions.test.js`

- [ ] **Step 1: Patch ticket options and null-safe rendering**

Change the ticket mapping to `节点问题`, `退款`, `使用方法`, and `推广佣金提现`; expose only the first three options in the manual select; render `NULL` as an empty string instead of indexing type `0`; keep the theme and public bundles identical.

- [ ] **Step 2: Update ticket copy normalization**

Replace `申诉级别` and priority placeholders with `申诉类型` and `请选择申诉类型` in both override copies.

- [ ] **Step 3: Regenerate compressed theme assets**

Regenerate deterministic gzip and Brotli variants from the patched theme bundle using available system tooling, then verify decompressed content equals `umi.js`.

- [ ] **Step 4: Extend and run the ElephantRoute regression test**

Run: `node --test tests/elephant-route-dashboard-actions.test.js tests/ticket-appeal-types.test.js`

Expected: all assertions pass and the public/theme override copies remain synchronized.

### Task 4: Update the compiled admin ticket interface

**Files:**
- Modify: `public/assets/admin/assets/index.js`
- Modify: `public/assets/admin/locales/zh-CN.js`
- Modify: `public/assets/admin/locales/en-US.js`
- Modify: `public/assets/admin/locales/ko-KR.js`

- [ ] **Step 1: Add a null-safe type-label mapping**

At the ticket enum boundary, map `0/1/2/3` to the existing locale-key namespace and return no key for `NULL`. Use it in the ticket table, side list, and detail badge so historical values remain blank and type `3` is not displayed as the third manual type.

- [ ] **Step 2: Update the admin filter**

Expose all four stored values in the admin type filter, including the system-only withdrawal type.

- [ ] **Step 3: Update admin locale labels**

Rename the column to appeal type and replace low/medium/high labels with node issue/refund/usage guide, adding promotion commission withdrawal in Chinese, English, and Korean.

- [ ] **Step 4: Run focused admin contract tests**

Run: `node --test tests/admin-ticket-row-click.test.js tests/ticket-appeal-types.test.js`

Expected: all assertions pass.

### Task 5: Verify the complete change set

**Files:**
- Verify all files listed above.

- [ ] **Step 1: Run all focused ticket tests**

Run: `node --test tests/ticket-appeal-types.test.js tests/elephant-route-dashboard-actions.test.js tests/admin-ticket-row-click.test.js tests/telegram-ticket-inline-actions.test.js`

Expected: zero failures.

- [ ] **Step 2: Re-run PHP syntax checks and JSON parsing**

Run `php -l` for the modified PHP files and parse all modified locale JSON files with PHP or Node.

Expected: no syntax or JSON errors.

- [ ] **Step 3: Inspect repository integrity**

Run `git diff --check`, compare synchronized user assets, inspect `git status --short`, `git diff --stat`, and the full scoped diff.

Expected: no whitespace errors, synchronized assets match, and only intended ticket/design/plan files are changed.
