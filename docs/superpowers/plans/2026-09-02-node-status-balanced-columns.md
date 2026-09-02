# Node Status Balanced Columns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the node-status page stable, readable right-side columns so status, rate, and tag labels never collapse into vertical text.

**Architecture:** Add semantic classes to the compiled node-list markup, then define the approved A-layout as page-scoped CSS in the existing ElephantRoute pages stylesheet. Keep data and rendering logic unchanged, mirror all browser assets, and update both JS and stylesheet cache keys.

**Tech Stack:** Compiled Vue/Naive UI bundle, ElephantRoute CSS, Node.js contract tests, gzip, Brotli, local Docker runtime.

---

### Task 1: Lock the balanced-column contract

**Files:**
- Modify: `tests/elephant-route-dashboard-actions.test.js`

- [ ] **Step 1: Extend the node-status test with semantic layout assertions**

Assert the node bundle includes `er-node-row`, `er-node-region`, `er-node-name`, `er-node-meta`, `er-node-status`, `er-node-rate`, and `er-node-tags`. Assert the pages stylesheet defines `grid-template-columns: 88px 88px minmax(140px, 1fr)` and `white-space: nowrap` for the three right-side cells.

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `node --test --test-name-pattern='node status shows regions' tests/elephant-route-dashboard-actions.test.js`

Expected: FAIL because the semantic node layout classes and balanced-column CSS do not exist yet.

### Task 2: Implement the approved A layout

**Files:**
- Modify: `theme/ElephantRoute/assets/umi.js`
- Modify: `theme/ElephantRoute/assets/elephant-route-pages-v2.css`
- Modify: `public/theme/ElephantRoute/assets/umi.js`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-pages-v2.css`

- [ ] **Step 1: Replace the node module's generic flex classes with semantic classes**

Use the existing compiled constants to emit:

```text
er-node-row
er-node-region
er-node-name
er-node-meta
er-node-status
er-node-rate
er-node-tags
```

Do not change region classification, status logic, rate values, tags, or tooltips.

- [ ] **Step 2: Add page-scoped balanced grid rules**

Add CSS under `body[data-er-page="node"]` with an outer grid for region, name, and metadata, plus:

```css
grid-template-columns: 88px 88px minmax(140px, 1fr);
white-space: nowrap;
```

Use a minimum readable row width on narrow screens so content gains controlled horizontal space instead of collapsing vertically.

- [ ] **Step 3: Mirror the source bundle and stylesheet**

Copy the updated `theme/ElephantRoute/assets/umi.js` and `elephant-route-pages-v2.css` byte-for-byte to the matching public asset paths.

- [ ] **Step 4: Run the focused test and verify it passes**

Run: `node --test --test-name-pattern='node status shows regions' tests/elephant-route-dashboard-actions.test.js`

Expected: PASS.

### Task 3: Refresh compressed assets and cache keys

**Files:**
- Modify: `theme/ElephantRoute/assets/umi.js.gz`
- Modify: `theme/ElephantRoute/assets/umi.js.br`
- Modify: `theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/dashboard.blade.php`

- [ ] **Step 1: Regenerate gzip and Brotli variants**

Generate both compressed files from the final `theme/ElephantRoute/assets/umi.js`, then verify decompression reproduces the source exactly.

- [ ] **Step 2: Update cache-busting keys in both Blade mirrors**

Change the UMI suffix to `er20260902nodeBalancedColumns1` and the pages stylesheet suffix to `er20260902nodeBalancedColumns1` in both Blade files.

- [ ] **Step 3: Update the cache-key regression assertion**

Require both new suffixes in `tests/elephant-route-dashboard-actions.test.js`.

### Task 4: Verify code and runtime behavior

**Files:**
- Test: `tests/elephant-route-dashboard-actions.test.js`
- Test: `tests/ticket-image-attachments.test.js`

- [ ] **Step 1: Run focused and full Node tests**

Run the focused dashboard test, then `node --test tests/*.test.js`.

Expected: all tests pass with zero failures.

- [ ] **Step 2: Verify mirrors, compressed variants, and whitespace**

Require theme/public asset equality, gzip/Brotli decompression equality, and `git diff --check` success.

- [ ] **Step 3: Perform browser acceptance at the local node route**

Open `http://127.0.0.1:7001/app#/node`, perform a clean reload, and confirm computed styles keep the status and rate columns at 88px, tags at least 140px, header/status text on one line, and no new console errors.

Per user instruction, do not create a branch, commit, push, or deploy.
