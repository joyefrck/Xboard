# Ticket Message Bubbles Implementation Plan

> **For agentic workers:** Execute these steps inline in the current checkout. Do not create a branch, commit, push, or deploy because the user has not authorized those actions.

**Goal:** Make the current viewer's messages appear on the right in both ticket interfaces, with the other party on the left, explicit sender labels, and distinct role colors.

**Architecture:** Persist an explicit administrator-sender flag so role identity does not depend on user IDs, expose the existing derived API flags from that field, and apply role-specific layout and visual tokens in both compiled renderers. Mirror and compress the user bundle after rendering changes.

**Tech Stack:** Laravel resource contracts, compiled Vue bundle, compiled React admin bundle, Node.js regression tests, gzip, Brotli.

---

### Task 1: Define the rendering contract with failing tests

**Files:**
- Modify: `tests/ticket-image-attachments.test.js`

- [ ] Add assertions that the user bundle renders `您` for `is_me`, `管理员` otherwise, and assigns the current user to the right and administrators to the left.
- [ ] Add assertions that the admin bundle maps `is_from_admin` to the right-side administrator bubble and customer messages to the left-side user bubble.
- [ ] Add locale assertions for `detail.sender_user` and `detail.sender_admin` in Chinese, English, and Korean.
- [ ] Run `node --test tests/ticket-image-attachments.test.js` and confirm the new assertions fail before implementation.

### Task 2: Persist the sender role

**Files:**
- Create: `database/migrations/2026_09_01_000002_add_is_admin_to_ticket_messages_table.php`
- Modify: `app/Models/TicketMessage.php`
- Modify: `app/Services/TicketService.php`
- Modify: `tests/ticket-image-attachments.test.js`

- [ ] Add a non-null boolean `is_admin` field with a default of `false` and backfill historical rows whose author differs from the ticket owner.
- [ ] Make `is_from_user` and `is_from_admin` derive from the persisted role field.
- [ ] Write `false` from user creation/reply paths and `true` from `replyByAdmin`, including Telegram calls that already use that service method.
- [ ] Set reply status from the entrypoint role rather than comparing account IDs.
- [ ] Run the focused regression test, migrate the local database, and verify the model schema is active.

### Task 3: Update the ElephantRoute message renderer

**Files:**
- Modify: `theme/ElephantRoute/assets/umi.js`
- Modify: `public/theme/ElephantRoute/assets/umi.js`
- Modify: `theme/ElephantRoute/assets/umi.js.gz`
- Modify: `theme/ElephantRoute/assets/umi.js.br`
- Modify: `theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/dashboard.blade.php`

- [ ] Replace the timestamp-only row and shared gray bubble with a role-aware container, sender/time metadata, and distinct user/admin surfaces.
- [ ] Keep the existing message text and `xboardRenderTicketAttachmentLinks(x.attachments)` inside the role-aware bubble.
- [ ] Copy the source bundle to the public mirror, regenerate gzip and Brotli files, and increment the shared cache-busting version in both Blade files.
- [ ] Run the focused test and verify the user-side assertions pass.

### Task 4: Update the admin message renderer and locales

**Files:**
- Modify: `public/assets/admin/assets/index.js`
- Modify: `public/assets/admin/locales/zh-CN.js`
- Modify: `public/assets/admin/locales/en-US.js`
- Modify: `public/assets/admin/locales/ko-KR.js`

- [ ] Change the admin message mapping so customer messages use right alignment and administrator messages use left alignment.
- [ ] Add the sender/time metadata row and apply the same semantic colors used by ElephantRoute.
- [ ] Keep `xboardAdminTicketAttachmentLinks` inside the bubble and preserve authenticated Blob opening.
- [ ] Add `detail.sender_user` and `detail.sender_admin` translations to all three locales.
- [ ] Run the focused test and confirm every new assertion passes.

### Task 5: Verify behavior and visual quality

**Files:**
- Test: `tests/ticket-image-attachments.test.js`
- Test: `tests/elephant-route-dashboard-actions.test.js`

- [ ] Run `node --check` for both JavaScript bundles.
- [ ] Run `node --test tests/*.test.js` and require zero failures.
- [ ] Verify theme/public equality and gzip/Brotli decompression equality.
- [ ] Run `git diff --check`.
- [ ] Restart the local Web container and inspect both active ticket pages at desktop width, confirming user-right/admin-left, correct labels, distinct colors, readable attachments, and no reply-area overlap.
