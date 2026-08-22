# Footer Pricing Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the desktop header pricing link and show pricing, terms, and privacy together in the footer on both public website pages.

**Architecture:** Keep the existing footer DOM and Flex layout, because it already contains the requested links in the correct order. Remove the duplicate header anchor and change the footer pricing link from mobile-only to always visible; no JavaScript or route changes are needed.

**Tech Stack:** Static HTML/CSS, Node.js built-in test runner, Docker bind-mounted local runtime

---

### Task 1: Add a failing footer-navigation contract

**Files:**
- Create: `tests/footer-pricing-navigation.test.js`

- [ ] **Step 1: Create the focused contract**

Use `apply_patch` to create:

```javascript
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');
const publicPages = [
  'public/landing/index.html',
  'public/pricing.html',
];

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function section(html, tagName) {
  const match = html.match(new RegExp(`<${tagName}>[\\s\\S]*?<\\/${tagName}>`));
  assert.ok(match, `${tagName} exists`);
  return match[0];
}

test('pricing navigation appears only in the footer on public website pages', () => {
  for (const relativePath of publicPages) {
    const html = readRepoFile(relativePath);
    const header = section(html, 'header');
    const footer = section(html, 'footer');

    assert.doesNotMatch(header, /href="\/pricing\.html"/);
    assert.doesNotMatch(html, /\.footer-pricing-link\s*\{\s*display:\s*none;/);

    const pricingIndex = footer.indexOf('<a href="/pricing.html" class="footer-pricing-link">定价方案</a>');
    const termsIndex = footer.indexOf('<a href="/terms.html">服务条款</a>');
    const privacyIndex = footer.indexOf('<a href="/privacy.html">隐私政策</a>');

    assert.ok(pricingIndex >= 0, `${relativePath} footer contains pricing`);
    assert.ok(pricingIndex < termsIndex, `${relativePath} pricing precedes terms`);
    assert.ok(termsIndex < privacyIndex, `${relativePath} terms precedes privacy`);
  }
});
```

- [ ] **Step 2: Confirm the current desktop layout fails**

Run:

```bash
node --test tests/footer-pricing-navigation.test.js
```

Expected: non-zero exit because each header still contains `/pricing.html` and each base stylesheet hides `.footer-pricing-link`.

### Task 2: Move pricing navigation to the footer

**Files:**
- Modify: `public/landing/index.html`
- Modify: `public/pricing.html`
- Test: `tests/footer-pricing-navigation.test.js`

- [ ] **Step 1: Make the existing footer pricing link visible by default**

In both HTML files, replace:

```css
.footer-pricing-link {
    display: none;
}
```

with:

```css
.footer-pricing-link {
    display: inline;
}
```

Remove the duplicate mobile media-query block that already sets the same class to `display: inline`.

- [ ] **Step 2: Remove the duplicate header pricing anchors**

Delete this exact anchor from both HTML headers:

```html
<a href="/pricing.html" class="nav-link">定价方案</a>
```

Keep the existing footer markup unchanged:

```html
<div class="footer-links">
    <a href="/pricing.html" class="footer-pricing-link">定价方案</a>
    <a href="/terms.html">服务条款</a>
    <a href="/privacy.html">隐私政策</a>
</div>
```

- [ ] **Step 3: Run the focused contract**

Run:

```bash
node --test tests/footer-pricing-navigation.test.js
```

Expected: `1` test passes, `0` fail.

### Task 3: Verify, commit, and accept locally

**Files:**
- Modify: `public/landing/index.html`
- Modify: `public/pricing.html`
- Create: `tests/footer-pricing-navigation.test.js`

- [ ] **Step 1: Run public-site regressions**

Run:

```bash
node --test \
  tests/footer-pricing-navigation.test.js \
  tests/homepage-image-performance.test.js \
  tests/home-login-redirect.test.js \
  tests/app-download-rate-limit.test.js
```

Expected: all tests pass with zero failures.

- [ ] **Step 2: Audit and commit only the scoped files**

Run:

```bash
git diff --check
git diff --name-status HEAD
git add -- \
  public/landing/index.html \
  public/pricing.html \
  tests/footer-pricing-navigation.test.js
git diff --cached --check
git commit -m "feat: move pricing link to footer"
```

Expected: one local commit containing two HTML files and one test; no remote push.

- [ ] **Step 3: Verify the bind-mounted local site**

Run:

```bash
for route_path in / /pricing.html /terms.html /privacy.html; do
  test "$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:7001$route_path")" = "200"
done
```

Expected: all four routes return `200` without restarting Octane.

- [ ] **Step 4: Perform browser layout and navigation acceptance**

Reload the homepage and pricing page at desktop width. Verify each header has no pricing link and each footer has exactly three same-row links in the order pricing, terms, privacy. Click the homepage footer pricing link and confirm navigation to `/pricing.html`; read console error/warn logs.

Expected: layout and navigation match the design and the console remains clean.

- [ ] **Step 5: Verify final Git state**

Run:

```bash
git status --short --branch
git log -3 --oneline
```

Expected: the worktree is clean and the design, plan, and implementation commits remain local.
