# Xboard Dashboard Commission Rate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Display the effective backend-configured invitation commission rate beside the “邀请好友赚钱” heading.

**Architecture:** Reuse the existing `/api/v1/user/invite/fetch` request and read its effective rate from `data.stat[3]`, which already includes the backend user-specific override. Add a deterministic formatting helper, render a hidden-by-default heading badge only for valid numeric values, and preserve the existing invite card layout.

**Tech Stack:** Vanilla JavaScript, CSS, Laravel Blade cache keys, Node.js `node:test`, Chrome runtime verification.

---

### Task 1: Add commission formatting and rendering regressions

**Files:**
- Modify: `tests/elephant-route-dashboard-v2.test.js`

- [ ] **Step 1: Add helper behavior tests**

Require the Dashboard V2 module and assert:

```js
assert.equal(helpers.buildInviteCommissionLabel({ stat: [0, 0, 0, 10] }), '佣金 10%');
assert.equal(helpers.buildInviteCommissionLabel({ stat: [0, 0, 0, 18] }), '佣金 18%');
assert.equal(helpers.buildInviteCommissionLabel({ stat: [0, 0, 0, 0] }), '佣金 0%');
assert.equal(helpers.buildInviteCommissionLabel({ stat: [] }), '');
assert.equal(helpers.buildInviteCommissionLabel(null), '');
```

- [ ] **Step 2: Add source and style contracts**

Assert that the invite heading contains a hidden `data-field="commission-rate"` badge, `renderInvite()` calls `buildInviteCommissionLabel(invite)`, the script does not hardcode `佣金 10%`, and `.er-v2-commission-rate` uses `var(--er-v2-font-body)`.

- [ ] **Step 3: Run the focused test and confirm failure**

Run:

```bash
node --test tests/elephant-route-dashboard-v2.test.js
```

Expected: the new commission helper or badge assertions fail before implementation.

### Task 2: Render the effective commission rate

**Files:**
- Modify: `theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`
- Modify: `theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`

- [ ] **Step 1: Add the formatting helper**

Implement:

```js
function buildInviteCommissionLabel(invite) {
  var stat = invite && Array.isArray(invite.stat) ? invite.stat : [];
  if (stat.length < 4 || stat[3] === '' || stat[3] === null || stat[3] === undefined) return '';
  var rate = Number(stat[3]);
  if (!Number.isFinite(rate) || rate < 0) return '';
  return '佣金 ' + String(rate) + '%';
}
```

Export it through `testApi`.

- [ ] **Step 2: Add the hidden heading badge**

Add this element after the invite heading wrapper:

```html
<span class="er-v2-commission-rate" data-field="commission-rate" hidden></span>
```

- [ ] **Step 3: Bind the badge in `renderInvite()`**

Before handling invitation codes, compute the label from the current invite response. Set the badge text and show it when non-empty; otherwise clear and hide it. This makes request failures and malformed payloads show no fabricated percentage.

- [ ] **Step 4: Style the badge**

Use a 14px (`var(--er-v2-font-body)`) pill with a restrained warm-gold border/background, at least 28px height, and medium weight. Keep the existing heading flex alignment so the pill sits on the right; allow natural wrapping below 768px.

- [ ] **Step 5: Run the focused test and confirm success**

Run:

```bash
node --test tests/elephant-route-dashboard-v2.test.js
```

Expected: all Dashboard 2.0 tests pass.

### Task 3: Mirror assets and verify the runtime value

**Files:**
- Modify: `theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css`

- [ ] **Step 1: Bump the V2 cache suffix**

Change both V2 asset suffixes from `er20260826dashboardV2Aurora11` to `er20260826dashboardV2Aurora12`.

- [ ] **Step 2: Mirror theme assets to public**

Run:

```bash
cp theme/ElephantRoute/assets/elephant-route-dashboard-v2.js public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js
cp theme/ElephantRoute/assets/elephant-route-dashboard-v2.css public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css
cp theme/ElephantRoute/dashboard.blade.php public/theme/ElephantRoute/dashboard.blade.php
```

- [ ] **Step 3: Verify in the existing Chrome session**

Reload `http://127.0.0.1:7001/app#/dashboard`, fetch `/api/v1/user/invite/fetch` with the current session, and confirm the visible badge text equals `佣金 ${data.stat[3]}%`. Confirm its computed font size is `14px`, the Dashboard root overflow remains `0`, and the console has no new errors.

- [ ] **Step 4: Run complete regression and consistency checks**

Run:

```bash
node --test tests/elephant-route-dashboard-v2.test.js tests/elephant-route-dashboard-actions.test.js tests/app-download-rate-limit.test.js tests/ticket-appeal-types.test.js tests/telegram-http-runtime-safety.test.js tests/telegram-ticket-bot-isolation.test.js tests/stacked-traffic-package.test.js
node --check theme/ElephantRoute/assets/elephant-route-dashboard-v2.js
cmp -s theme/ElephantRoute/assets/elephant-route-dashboard-v2.js public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.js
cmp -s theme/ElephantRoute/assets/elephant-route-dashboard-v2.css public/theme/ElephantRoute/assets/elephant-route-dashboard-v2.css
cmp -s theme/ElephantRoute/dashboard.blade.php public/theme/ElephantRoute/dashboard.blade.php
git diff --check
```

Expected: all tests pass and every command exits `0`.
