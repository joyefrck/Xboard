# Xboard Dashboard Card Patterns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the `DASHBOARD 2.0` topbar label and add two distinct, low-opacity SVG background patterns to the official-announcement and shortcut cards without changing layout.

**Architecture:** Extend the existing `SOLID_ICONS` map with two decorative SVG symbols and render each through a shared card-watermark container. Keep all decorative nodes non-interactive and below the existing card content, then mirror the theme assets to `public` and bump the Blade cache key.

**Tech Stack:** Vanilla JavaScript, CSS, Laravel Blade asset loading, Node.js `node:test`, Chrome runtime verification.

---

### Task 1: Add regression contracts

**Files:**
- Modify: `tests/elephant-route-dashboard-v2.test.js`

- [ ] **Step 1: Write the failing tests**

Add assertions that the topbar template no longer contains `DASHBOARD 2.0`, that `noticePattern` and `shortcutPattern` are different SVG paths, and that both cards render an `aria-hidden="true"` watermark using distinct modifier classes. Assert the CSS includes `pointer-events: none`, card-content stacking, and separate cyan and violet color rules.

- [ ] **Step 2: Run the focused test and confirm the red state**

Run:

```bash
node --test tests/elephant-route-dashboard-v2.test.js
```

Expected: the new topbar and decorative-pattern test fails before implementation while existing tests continue to run.

### Task 2: Implement the topbar and card patterns

**Files:**
- Modify: `theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`
- Modify: `theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`

- [ ] **Step 1: Add two semantic decorative SVGs**

Add `noticePattern` to `SOLID_ICONS` using a center signal dot with three broadcast arcs. Add `shortcutPattern` using four rounded modules and connector bars. Keep the path data different and render both through the existing `solidIcon()` helper.

- [ ] **Step 2: Remove the topbar eyebrow**

Change the `ensureTopbarGreeting()` markup to:

```js
existing.innerHTML = '<strong><span data-er-v2-topbar-field="greeting">欢迎回来</span>，<span data-er-v2-topbar-field="email">用户</span></strong>';
```

Keep the existing greeting and email data fields unchanged.

- [ ] **Step 3: Add decorative nodes to the two cards**

Insert these non-interactive nodes as the first children of their respective cards:

```js
'<div class="er-v2-card-watermark er-v2-card-watermark-notice" aria-hidden="true">' + solidIcon('noticePattern') + '</div>'
'<div class="er-v2-card-watermark er-v2-card-watermark-shortcut" aria-hidden="true">' + solidIcon('shortcutPattern') + '</div>'
```

- [ ] **Step 4: Style the shared watermark layer**

Create a shared absolute layer around `112px`, placed at the lower-right edge with `pointer-events: none` and `z-index: 0`. Set the announcement variant to cyan at about `0.045` opacity and the shortcut variant to blue-violet at about `0.04` opacity. Set the cards' headings, notice list, shortcut list, and error text to `position: relative; z-index: 1` so the patterns never obscure content.

- [ ] **Step 5: Run the focused test and confirm green**

Run:

```bash
node --test tests/elephant-route-dashboard-v2.test.js
```

Expected: all Dashboard 2.0 tests pass.

### Task 3: Publish mirrored assets and refresh cache keys

**Files:**
- Modify: `theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`

- [ ] **Step 1: Bump both V2 asset keys**

Change the V2 CSS and JavaScript suffix from `er20260826dashboardV2Aurora10` to `er20260826dashboardV2Aurora11`.

- [ ] **Step 2: Mirror the browser-served files**

Run:

```bash
cp theme/ElephantRoute/assets/elephant-route-dashboard-v2.js public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js
cp theme/ElephantRoute/assets/elephant-route-dashboard-v2.css public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css
cp theme/ElephantRoute/dashboard.blade.php public/theme/ElephantRoute/dashboard.blade.php
```

- [ ] **Step 3: Verify byte equality and syntax**

Run:

```bash
node --check theme/ElephantRoute/assets/elephant-route-dashboard-v2.js
cmp -s theme/ElephantRoute/assets/elephant-route-dashboard-v2.js public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js
cmp -s theme/ElephantRoute/assets/elephant-route-dashboard-v2.css public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css
cmp -s theme/ElephantRoute/dashboard.blade.php public/theme/ElephantRoute/dashboard.blade.php
```

Expected: all commands exit `0`.

### Task 4: Runtime and full regression verification

**Files:**
- Verify: `theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`
- Verify: `theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`

- [ ] **Step 1: Verify the live dashboard in the existing Chrome tab**

Reload `http://127.0.0.1:7001/app#/dashboard` and confirm:

- no element or visible text contains `DASHBOARD 2.0`;
- the two watermark SVG path values are different;
- both watermark containers report `pointer-events: none` and are below card content;
- announcement and shortcut text remains readable;
- Dashboard root overflow remains `0` at the current viewport;
- the console contains no new errors.

- [ ] **Step 2: Capture the final dashboard screenshot**

Capture the whole current Chrome viewport with the page at `#/dashboard` and visually inspect both background patterns at normal scale.

- [ ] **Step 3: Run the complete related regression suite**

Run:

```bash
node --test tests/elephant-route-dashboard-v2.test.js tests/elephant-route-dashboard-actions.test.js tests/app-download-rate-limit.test.js tests/ticket-appeal-types.test.js tests/telegram-http-runtime-safety.test.js tests/telegram-ticket-bot-isolation.test.js tests/stacked-traffic-package.test.js
git diff --check
```

Expected: all tests pass with zero failures and `git diff --check` exits `0`.
