# ElephantRoute Brand Logo Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace every user-facing ElephantRoute logo and browser tab icon with assets derived from the latest client SVG.

**Architecture:** Keep `clients/elephant-route-deprecated/assets/images/logo.svg` as the sole brand source. Mirror browser-served assets into both ElephantRoute theme trees, reference them from the shared `/app` Blade shell and public landing page, and enforce the source, mirroring, markup, and file-format contracts with one focused Node test.

**Tech Stack:** SVG, PNG, ICO, Laravel Blade, static HTML, vanilla JavaScript, Node.js `node:test`, `rsvg-convert`, `sips`, Chrome runtime verification.

---

### Task 1: Add failing brand-asset contracts

**Files:**
- Create: `tests/elephant-route-brand-assets.test.js`

- [ ] **Step 1: Add source and mirror assertions**

Create a `node:test` suite that reads repository files as buffers and asserts:

```js
const clientLogo = readBuffer('clients/elephant-route-deprecated/assets/images/logo.svg');
assert.deepEqual(readBuffer('theme/ElephantRoute/assets/client-logo.svg'), clientLogo);
assert.deepEqual(readBuffer('public/theme/ElephantRoute/assets/client-logo.svg'), clientLogo);

for (const name of ['favicon.svg', 'favicon-32x32.png', 'favicon-16x16.png', 'apple-touch-icon.png', 'favicon.ico']) {
  assert.deepEqual(
    readBuffer(`public/theme/ElephantRoute/assets/${name}`),
    readBuffer(`theme/ElephantRoute/assets/${name}`)
  );
}
```

- [ ] **Step 2: Add format and dimension assertions**

Parse PNG headers and assert the generated assets are exactly `32x32`, `16x16`, and `180x180`. Assert every PNG uses RGBA color type `6`, `favicon.ico` begins with the ICO header `00 00 01 00`, and `favicon.svg` is byte-identical to the client SVG.

- [ ] **Step 3: Add page-reference assertions**

Assert both theme Blade files include SVG, PNG, ICO and Apple Touch Icon links with cache suffix `er20260826brandLogo1`; the auth script uses only `/theme/ElephantRoute/assets/client-logo.svg`; the landing page visible brand uses that SVG; and its Open Graph and JSON-LD logo URLs use `/theme/ElephantRoute/assets/apple-touch-icon.png`. Assert the auth script and landing brand metadata no longer contain `/login_logo.jpeg`, `/home_logo.jpeg`, or `/landing/assets/elephant-route-logo.jpg`.

- [ ] **Step 4: Run the focused test and confirm failure**

Run:

```bash
node --test tests/elephant-route-brand-assets.test.js
```

Expected: FAIL because the favicon assets and new references do not exist yet.

### Task 2: Generate and expose the canonical assets

**Files:**
- Create: `theme/ElephantRoute/assets/favicon.svg`
- Create: `theme/ElephantRoute/assets/favicon-32x32.png`
- Create: `theme/ElephantRoute/assets/favicon-16x16.png`
- Create: `theme/ElephantRoute/assets/apple-touch-icon.png`
- Create: `theme/ElephantRoute/assets/favicon.ico`
- Create: matching files under `public/theme/ElephantRoute/assets/`
- Modify: `public/theme/.gitignore`

- [ ] **Step 1: Copy the canonical SVG**

Copy the client SVG to the theme favicon path, then mirror it to the public theme:

```bash
cp clients/elephant-route-deprecated/assets/images/logo.svg theme/ElephantRoute/assets/favicon.svg
cp theme/ElephantRoute/assets/favicon.svg public/theme/ElephantRoute/assets/favicon.svg
```

- [ ] **Step 2: Render transparent PNG fallbacks**

Use the SVG renderer without a background color:

```bash
rsvg-convert -w 32 -h 32 -o theme/ElephantRoute/assets/favicon-32x32.png clients/elephant-route-deprecated/assets/images/logo.svg
rsvg-convert -w 16 -h 16 -o theme/ElephantRoute/assets/favicon-16x16.png clients/elephant-route-deprecated/assets/images/logo.svg
rsvg-convert -w 180 -h 180 -o theme/ElephantRoute/assets/apple-touch-icon.png clients/elephant-route-deprecated/assets/images/logo.svg
sips -s format ico theme/ElephantRoute/assets/favicon-32x32.png --out theme/ElephantRoute/assets/favicon.ico
```

- [ ] **Step 3: Mirror generated assets**

Copy the four generated binary files to `public/theme/ElephantRoute/assets/`, preserving byte identity.

- [ ] **Step 4: Make public assets deployable**

Add explicit negate rules for the five new filenames to `public/theme/.gitignore` so Git tracks the files under the otherwise ignored public theme tree.

### Task 3: Replace old page references

**Files:**
- Modify: `theme/ElephantRoute/dashboard.blade.php`
- Modify: `public/theme/ElephantRoute/dashboard.blade.php`
- Modify: `theme/ElephantRoute/assets/elephant-route-auth.js`
- Modify: `public/theme/ElephantRoute/assets/elephant-route-auth.js`
- Modify: `public/landing/index.html`

- [ ] **Step 1: Add favicon links to the shared `/app` head**

Insert the following after `<title>` in the theme Blade file:

```html
<link rel="icon" type="image/svg+xml" href="/theme/{{$theme}}/assets/favicon.svg?v={{$version}}-er20260826brandLogo1">
<link rel="icon" type="image/png" sizes="32x32" href="/theme/{{$theme}}/assets/favicon-32x32.png?v={{$version}}-er20260826brandLogo1">
<link rel="icon" type="image/png" sizes="16x16" href="/theme/{{$theme}}/assets/favicon-16x16.png?v={{$version}}-er20260826brandLogo1">
<link rel="icon" href="/theme/{{$theme}}/assets/favicon.ico?v={{$version}}-er20260826brandLogo1" sizes="any">
<link rel="apple-touch-icon" sizes="180x180" href="/theme/{{$theme}}/assets/apple-touch-icon.png?v={{$version}}-er20260826brandLogo1">
```

- [ ] **Step 2: Use the SVG directly on authentication pages**

Replace `LOGO_CANDIDATES` and `preloadLogo()` with:

```js
var BRAND_LOGO_URL = '/theme/ElephantRoute/assets/client-logo.svg';

function applyBrandLogo(img) {
  img.src = BRAND_LOGO_URL;
}
```

Call `applyBrandLogo()` when creating the authentication brand block. Keep the existing home link, alt text, and route handling intact.

- [ ] **Step 3: Update the landing page**

Add the same five favicon declarations with static `er20260826brandLogo1` query strings. Change the visible header image to `/theme/ElephantRoute/assets/client-logo.svg`, set `alt="大象网络"`, and remove the circular crop. Change Open Graph and JSON-LD logo URLs to `https://www.elphantroute.com/theme/ElephantRoute/assets/apple-touch-icon.png`.

- [ ] **Step 4: Mirror theme runtime files**

Copy the changed authentication script and Blade file from `theme/ElephantRoute/` to `public/theme/ElephantRoute/`.

- [ ] **Step 5: Run the focused test and confirm success**

Run:

```bash
node --test tests/elephant-route-brand-assets.test.js
```

Expected: PASS.

### Task 4: Verify regressions and rendered pages

**Files:**
- Verify all files changed above.

- [ ] **Step 1: Run focused frontend regression tests**

Run:

```bash
node --test tests/elephant-route-brand-assets.test.js tests/elephant-route-dashboard-v2.test.js tests/elephant-route-pages-v2.test.js tests/elephant-route-dashboard-actions.test.js
node --check theme/ElephantRoute/assets/elephant-route-auth.js
```

Expected: all tests pass and JavaScript syntax validation exits `0`.

- [ ] **Step 2: Verify mirror and repository integrity**

Run `cmp -s` for the Blade file, authentication script, client SVG, and each favicon asset across the theme/public-theme pair. Run `git diff --check` and verify only the approved frontend assets, focused test, ignore rules, and plan are changed.

- [ ] **Step 3: Verify real browser rendering**

Start or reuse the local Xboard runtime. Force-reload `/`, `/app#/login`, `/app#/register`, `/app#/forgetpassword`, and `/app#/dashboard`. For each route, verify the active favicon URL points to the new theme asset; verify the homepage and authentication brand images resolve to the client SVG; check desktop and mobile layouts for clipping or overflow; and confirm no new console errors.

- [ ] **Step 4: Commit the verified implementation**

Stage only the approved source, mirrored assets, focused test, ignore rules, and implementation plan. Commit with:

```bash
git commit -m "feat: unify ElephantRoute frontend logos"
```
