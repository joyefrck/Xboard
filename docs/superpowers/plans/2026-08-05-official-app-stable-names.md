# Official App Stable Names Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep official Android and desktop application names stable while leaving third-party names editable.

**Architecture:** Put the authoritative platform-to-name mapping in `DistributionApp`, enforce it in the admin save endpoint, mirror it in the upload form for immediate feedback, and normalize existing official rows with a migration. Preserve the existing shared `elephant-route-desktop` identity and platform-filtered version lookup.

**Tech Stack:** Laravel/PHP, Blade/JavaScript, Laravel migrations, Node.js `node:test`

---

### Task 1: Add regression coverage

**Files:**
- Modify: `tests/admin-app-downloads-autofill.test.js`
- Modify: `tests/app-download-distribution-scope.test.js`

- [ ] Assert the frontend helper returns the fixed Android and desktop names.
- [ ] Assert official name and key fields are both locked and synchronized.
- [ ] Assert the model owns the canonical name mapping and the controller enforces it.
- [ ] Assert the normalization migration updates both official identities.
- [ ] Run `node --test tests/admin-app-downloads-autofill.test.js tests/app-download-distribution-scope.test.js` and confirm the new assertions fail before implementation.

### Task 2: Enforce canonical names

**Files:**
- Modify: `app/Models/DistributionApp.php`
- Modify: `app/Http/Controllers/V2/Admin/AppPackageController.php`
- Modify: `resources/views/admin_app_downloads.blade.php`

- [ ] Add `officialAppNameForPlatform()` with Android and shared desktop names.
- [ ] Set both `app_key` and `name` from the platform for official saves.
- [ ] Add the matching frontend helper and make the official name field read-only.
- [ ] Update the form help text to explain fixed official names.

### Task 3: Normalize existing data

**Files:**
- Create: `database/migrations/2026_08_05_000001_normalize_official_distribution_app_names.php`

- [ ] Update the official Android row to `大象网络官方App安卓版`.
- [ ] Update the official desktop row to `大象网络官方App桌面版`.
- [ ] Keep `down()` non-destructive because prior free-form names cannot be reconstructed safely.

### Task 4: Verify

**Files:**
- Test: `tests/admin-app-downloads-autofill.test.js`
- Test: `tests/app-download-distribution-scope.test.js`

- [ ] Run the two focused test files and require exit code 0.
- [ ] Run `node --test tests/*.test.js` and report any unrelated baseline failure separately.
- [ ] Inspect `git diff --check`, the final diff, and repository status before handoff.

