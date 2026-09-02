# Ticket Attachment UI Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Execute these checkbox steps inline in the current workspace. Do not commit, push, or deploy.

**Goal:** Match admin attachment-link styling to the user dashboard and prevent the user reply attachment controls from being clipped.

**Architecture:** Keep all upload and authenticated-open behavior unchanged. Convert the ElephantRoute ticket detail card body to a flex column so the message scroller consumes remaining space and the composer grows naturally; change only the visual properties of the admin attachment button.

**Tech Stack:** Compiled Vue/Naive UI theme bundle, compiled React/Tailwind admin bundle, Node test runner, gzip, Brotli.

---

### Task 1: Add focused failing assertions

**Files:**
- Modify: `tests/ticket-image-attachments.test.js`

- [ ] Assert that the user bundle no longer contains `height:"calc(100% - 70px)"`.
- [ ] Assert that the user message region uses `relative min-h-0 flex-1` and the composer is inside a `shrink-0` region.
- [ ] Assert that the admin attachment link uses `#2563eb`, `14px`, underline, and a `3px` underline offset.
- [ ] Run `node --test tests/ticket-image-attachments.test.js` and confirm the new assertions fail before implementation.

### Task 2: Implement the two visual fixes

**Files:**
- Modify: `theme/ElephantRoute/assets/umi.js`
- Modify: `public/assets/admin/assets/index.js`

- [ ] Replace the fixed-height user message wrapper with `relative min-h-0 flex-1`.
- [ ] Wrap the message region and reply controls in `flex h-full min-h-0 flex-col`.
- [ ] Wrap the input group and file picker in `shrink-0 pt-4`.
- [ ] Apply the user-link visual properties to the admin attachment button without changing its message layout.
- [ ] Run the focused test and confirm it passes.

### Task 3: Synchronize generated delivery assets

**Files:**
- Modify: `public/theme/ElephantRoute/assets/umi.js`
- Modify: `theme/ElephantRoute/assets/umi.js.gz`
- Modify: `theme/ElephantRoute/assets/umi.js.br`
- Modify: `theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/dashboard.blade.php`

- [ ] Copy the theme bundle byte-for-byte to the public mirror.
- [ ] Regenerate deterministic gzip and Brotli files from the updated bundle.
- [ ] Increment the ticket-attachment cache suffix in both Blade mirrors.
- [ ] Verify source/public equality and decompressed gzip/Brotli equality.

### Task 4: Regression and browser verification

**Files:**
- Test: `tests/ticket-image-attachments.test.js`
- Test: `tests/elephant-route-dashboard-actions.test.js`

- [ ] Run the two focused Node test files.
- [ ] Run `node --check` for both modified JavaScript bundles.
- [ ] Run `git diff --check`.
- [ ] Reload the user ticket and admin ticket views in Chrome.
- [ ] Verify the admin attachment computed style matches the user link and the user add-image button remains inside the card at desktop and mobile viewport sizes.

