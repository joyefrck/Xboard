# Homepage Image Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce the seven non-logo homepage images from about 248 KB to at most 80 KiB while preserving the existing visual design and deferring below-fold downloads.

**Architecture:** Generate three full-resolution WebP content images and four `96×96` WebP avatars from the retained legacy assets. Update only `public/landing/index.html` image references and loading attributes, with a Node.js contract that enforces WebP signatures, dimensions, byte budget, retained originals, and HTML behavior.

**Tech Stack:** Static HTML, WebP, `cwebp`, Node.js built-in test runner, Docker Compose, browser runtime inspection

---

### Task 1: Add a failing image-performance contract

**Files:**
- Create: `tests/homepage-image-performance.test.js`

- [ ] **Step 1: Create the performance contract**

Use `apply_patch` to create `tests/homepage-image-performance.test.js` with:

```javascript
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');
const assetRoot = path.join(repoRoot, 'public/landing/assets');

const contentAssets = [
  'pain-google-ip',
  'pain-ai-tools',
  'pain-streaming',
];
const avatarAssets = [
  'avatar-pm',
  'avatar-tiktok',
  'avatar-educator',
  'avatar-freelancer',
];
const optimizedAssets = [...contentAssets, ...avatarAssets];

function assetPath(name, extension) {
  return path.join(assetRoot, `${name}.${extension}`);
}

function readLossyWebpDimensions(buffer) {
  assert.equal(buffer.toString('ascii', 0, 4), 'RIFF');
  assert.equal(buffer.toString('ascii', 8, 12), 'WEBP');
  assert.equal(buffer.toString('ascii', 12, 16), 'VP8 ');

  return {
    width: buffer.readUInt16LE(26) & 0x3fff,
    height: buffer.readUInt16LE(28) & 0x3fff,
  };
}

function imageTagFor(html, source) {
  const escapedSource = source.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = html.match(new RegExp(`<img[^>]*src="${escapedSource}"[^>]*>`, 's'));
  assert.ok(match, `${source} is referenced by an img tag`);
  return match[0];
}

test('optimized WebP assets meet signature, dimension, and byte budgets', () => {
  let optimizedBytes = 0;
  let legacyBytes = 0;

  for (const name of optimizedAssets) {
    const optimizedPath = assetPath(name, 'webp');
    const legacyPath = assetPath(name, 'png');
    assert.equal(fs.existsSync(optimizedPath), true, `${name}.webp exists`);
    assert.equal(fs.existsSync(legacyPath), true, `${name}.png remains available`);

    const buffer = fs.readFileSync(optimizedPath);
    const dimensions = readLossyWebpDimensions(buffer);
    const expectedSize = contentAssets.includes(name) ? 640 : 96;
    assert.deepEqual(dimensions, {width: expectedSize, height: expectedSize});

    optimizedBytes += buffer.length;
    legacyBytes += fs.statSync(legacyPath).size;
  }

  assert.ok(optimizedBytes <= 80 * 1024, `optimized bytes ${optimizedBytes} exceed 80 KiB`);
  assert.ok(optimizedBytes <= legacyBytes * 0.35, `${optimizedBytes} is more than 35% of ${legacyBytes}`);
});

test('homepage uses optimized images with explicit loading behavior', () => {
  const html = fs.readFileSync(path.join(repoRoot, 'public/landing/index.html'), 'utf8');

  for (const name of optimizedAssets) {
    assert.doesNotMatch(html, new RegExp(`/landing/assets/${name}\\.png`));
    const tag = imageTagFor(html, `/landing/assets/${name}.webp`);
    const isContentImage = contentAssets.includes(name);
    assert.match(tag, new RegExp(`width="${isContentImage ? 640 : 48}"`));
    assert.match(tag, new RegExp(`height="${isContentImage ? 640 : 48}"`));
    assert.match(tag, /loading="lazy"/);
    assert.match(tag, /decoding="async"/);
    assert.match(tag, /fetchpriority="low"/);
  }

  const logoTag = imageTagFor(html, '/landing/assets/elephant-route-logo.jpg');
  assert.match(logoTag, /width="40"/);
  assert.match(logoTag, /height="40"/);
  assert.match(logoTag, /decoding="async"/);
  assert.match(logoTag, /fetchpriority="high"/);
  assert.doesNotMatch(logoTag, /loading="lazy"/);
});
```

- [ ] **Step 2: Confirm the contract fails before optimized assets exist**

Run:

```bash
node --test tests/homepage-image-performance.test.js
```

Expected: non-zero exit because the seven `.webp` files and required HTML attributes do not yet exist.

### Task 2: Generate WebP assets and update the homepage

**Files:**
- Create: `public/landing/assets/pain-google-ip.webp`
- Create: `public/landing/assets/pain-ai-tools.webp`
- Create: `public/landing/assets/pain-streaming.webp`
- Create: `public/landing/assets/avatar-pm.webp`
- Create: `public/landing/assets/avatar-tiktok.webp`
- Create: `public/landing/assets/avatar-educator.webp`
- Create: `public/landing/assets/avatar-freelancer.webp`
- Modify: `public/landing/index.html`
- Test: `tests/homepage-image-performance.test.js`

- [ ] **Step 1: Generate deterministic modern assets**

Run:

```bash
for asset_name in pain-google-ip pain-ai-tools pain-streaming; do
  cwebp -quiet -q 80 -metadata none \
    "public/landing/assets/${asset_name}.png" \
    -o "public/landing/assets/${asset_name}.webp"
done
for asset_name in avatar-pm avatar-tiktok avatar-educator avatar-freelancer; do
  cwebp -quiet -q 78 -resize 96 96 -metadata none \
    "public/landing/assets/${asset_name}.png" \
    -o "public/landing/assets/${asset_name}.webp"
done
```

Expected: seven WebP files are created; content images remain `640×640` and avatars are `96×96`.

- [ ] **Step 2: Update image sources and loading attributes**

Use `apply_patch` in `public/landing/index.html`. Preserve the logo's existing inline style while adding these attributes:

```html
<img
    src="/landing/assets/elephant-route-logo.jpg"
    alt="Logo"
    width="40"
    height="40"
    decoding="async"
    fetchpriority="high"
    style="
        width: 40px;
        height: 40px;
        border-radius: 50%;
        margin-right: 10px;
    "
/>
```

Replace the three pain-image tags with:

```html
<img src="/landing/assets/pain-google-ip.webp" alt="低风控IP注册Google账号" width="640" height="640" loading="lazy" decoding="async" fetchpriority="low" />
<img src="/landing/assets/pain-ai-tools.webp" alt="稳定访问ChatGPT Claude等AI工具" width="640" height="640" loading="lazy" decoding="async" fetchpriority="low" />
<img src="/landing/assets/pain-streaming.webp" alt="解锁Netflix等主流流媒体" width="640" height="640" loading="lazy" decoding="async" fetchpriority="low" />
```

Replace the four avatar tags with:

```html
<img src="/landing/assets/avatar-pm.webp" alt="李先生" class="testimonial-avatar" width="48" height="48" loading="lazy" decoding="async" fetchpriority="low" />
<img src="/landing/assets/avatar-tiktok.webp" alt="赵女士" class="testimonial-avatar" width="48" height="48" loading="lazy" decoding="async" fetchpriority="low" />
<img src="/landing/assets/avatar-educator.webp" alt="王老师" class="testimonial-avatar" width="48" height="48" loading="lazy" decoding="async" fetchpriority="low" />
<img src="/landing/assets/avatar-freelancer.webp" alt="林同学" class="testimonial-avatar" width="48" height="48" loading="lazy" decoding="async" fetchpriority="low" />
```

- [ ] **Step 3: Run the new performance contract**

Run:

```bash
node --test tests/homepage-image-performance.test.js
```

Expected: `2` tests pass, `0` fail.

### Task 3: Run regressions and commit the optimization

**Files:**
- Modify: `public/landing/index.html`
- Create: `tests/homepage-image-performance.test.js`
- Create: seven `.webp` files under `public/landing/assets/`

- [ ] **Step 1: Run homepage and related public-page tests**

Run:

```bash
node --test \
  tests/homepage-image-performance.test.js \
  tests/home-login-redirect.test.js \
  tests/app-download-rate-limit.test.js \
  tests/elephant-route-dashboard-actions.test.js
```

Expected: all tests pass with zero failures.

- [ ] **Step 2: Verify actual assets and repository whitespace**

Run:

```bash
file public/landing/assets/*.webp
du -ch \
  public/landing/assets/pain-google-ip.webp \
  public/landing/assets/pain-ai-tools.webp \
  public/landing/assets/pain-streaming.webp \
  public/landing/assets/avatar-pm.webp \
  public/landing/assets/avatar-tiktok.webp \
  public/landing/assets/avatar-educator.webp \
  public/landing/assets/avatar-freelancer.webp | tail -n 1
git diff --check
git diff --name-status HEAD
```

Expected: `file` reports WebP with the planned dimensions, total disk usage remains below 80 KiB, and the diff contains only the homepage, new test, and seven WebP files.

- [ ] **Step 3: Commit locally without pushing**

Run:

```bash
git add -- \
  public/landing/index.html \
  public/landing/assets/*.webp \
  tests/homepage-image-performance.test.js
git diff --cached --check
git commit -m "perf: optimize homepage images"
```

Expected: one local performance commit; no remote push.

### Task 4: Accept the bind-mounted local Docker site

**Files:**
- Runtime: `http://127.0.0.1:7001/`

- [ ] **Step 1: Verify optimized assets over HTTP**

Run:

```bash
for asset_name in \
  pain-google-ip pain-ai-tools pain-streaming \
  avatar-pm avatar-tiktok avatar-educator avatar-freelancer; do
  curl -fsSI "http://127.0.0.1:7001/landing/assets/${asset_name}.webp" \
    | rg 'HTTP/1.1 200 OK|Content-Type: image/webp|Content-Length:'
done
```

Expected: every asset returns `200`, `Content-Type: image/webp`, and a non-zero content length.

- [ ] **Step 2: Verify public-route compatibility and container health**

Run:

```bash
for route_path in / /pricing.html /terms.html /privacy.html /refund.html /app; do
  test "$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:7001$route_path")" = "200"
done
docker compose -f docker-compose.yml ps
test "$(docker exec xboard-redis-1 redis-cli ping)" = "PONG"
```

Expected: all public routes return `200`, all services run, and Redis returns `PONG`.

- [ ] **Step 3: Perform browser rendering and lazy-image acceptance**

Reload `http://127.0.0.1:7001/`, verify the desktop hero layout is unchanged, scroll through the pain and testimonial sections, and inspect every image's `currentSrc`, `naturalWidth`, `naturalHeight`, and completion state. Read browser console error/warn logs.

Expected: the Logo uses the JPEG, all seven below-fold images use WebP with non-zero dimensions, there are no broken images, and the console has no errors or warnings.

- [ ] **Step 4: Verify final Git state**

Run:

```bash
git status --short --branch
git log -3 --oneline
```

Expected: the worktree is clean, `master` contains separate design, plan, and performance commits, and the commits remain local.
