# Restore Public Website Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the retired Elephant Route homepage, pricing, terms, privacy, and refund pages, then publish and verify them only in the bind-mounted local Docker environment.

**Architecture:** Restore the last tracked HTML versions directly from Git history and reuse the existing landing assets. Replace the root-login redirect closure with one landing-page response closure that retains the current Host allowlist; keep `/app` unchanged and let Octane serve the four public `.html` files from `public/`.

**Tech Stack:** Laravel routes, static HTML/CSS/JavaScript, Node.js built-in test runner, Docker Compose with Laravel Octane

---

### Task 1: Replace the retired-homepage regression contract

**Files:**
- Modify: `tests/home-login-redirect.test.js`
- Test: `tests/home-login-redirect.test.js`

- [ ] **Step 1: Replace the two retirement tests with the restored-site contract**

Use `apply_patch` so `tests/home-login-redirect.test.js` contains:

```javascript
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('home and legacy welcome routes serve the restored landing page', () => {
  const routes = readRepoFile('routes/web.php');

  assert.match(routes, /\$serveLandingPage = function \(Request \$request\) use \(\$isAllowedAppHost\)/);
  assert.match(routes, /if \(!\$isAllowedAppHost\(\$request\)\) \{\s*abort\(403\);\s*\}/);
  assert.match(routes, /\$landingPagePath = public_path\('landing\/index\.html'\);/);
  assert.match(routes, /if \(!File::exists\(\$landingPagePath\)\) \{\s*abort\(404, 'Landing page not found'\);\s*\}/);
  assert.match(routes, /return response\(File::get\(\$landingPagePath\), 200\)/);
  assert.match(routes, /Route::get\('\/', \$serveLandingPage\);/);
  assert.match(routes, /Route::get\('\/welcome', \$serveLandingPage\);/);
  assert.doesNotMatch(routes, /Route::get\('\/', \$redirectToLogin\);/);
});

test('restored website pages retain their final tracked titles and links', () => {
  const expectedPages = new Map([
    ['public/landing/index.html', '<title>大象网络 - CONNECT THE UNSEEN</title>'],
    ['public/pricing.html', '<title>定价方案 - 大象网络</title>'],
    ['public/terms.html', '<title>服务条款 - 大象网络</title>'],
    ['public/privacy.html', '<title>隐私政策 - 大象网络</title>'],
    ['public/refund.html', '<title>退款说明 - 大象网络</title>'],
  ]);

  for (const [relativePath, expectedTitle] of expectedPages) {
    assert.equal(fs.existsSync(path.join(repoRoot, relativePath)), true, `${relativePath} exists`);
    assert.match(readRepoFile(relativePath), new RegExp(expectedTitle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }

  const landing = readRepoFile('public/landing/index.html');
  assert.match(landing, /href="\/pricing\.html"/);
  assert.match(landing, /href="\/terms\.html"/);
  assert.match(landing, /href="\/privacy\.html"/);
  assert.match(landing, /window\.location\.href = "\/app#\/login"/);
  assert.match(landing, /window\.location\.href = "\/app#\/register"/);

  const pricing = readRepoFile('public/pricing.html');
  assert.match(pricing, /href="\/terms\.html"/);
  assert.match(pricing, /href="\/privacy\.html"/);

  for (const policyPath of ['public/terms.html', 'public/privacy.html', 'public/refund.html']) {
    const policy = readRepoFile(policyPath);
    assert.match(policy, /最近更新日期：2026年3月17日/);
    assert.match(policy, /href="\/"/);
  }
});

test('all local landing-page image references resolve to retained assets', () => {
  const landing = readRepoFile('public/landing/index.html');
  const assetReferences = [...landing.matchAll(/(?:src|content)="(\/landing\/assets\/[^"]+)"/g)]
    .map((match) => match[1]);

  assert.ok(assetReferences.length > 0);
  for (const assetReference of assetReferences) {
    assert.equal(
      fs.existsSync(path.join(repoRoot, 'public', assetReference.replace(/^\//, ''))),
      true,
      `${assetReference} exists`
    );
  }
});
```

- [ ] **Step 2: Run the new contract and confirm the pre-implementation failure**

Run:

```bash
node --test tests/home-login-redirect.test.js
```

Expected: non-zero exit; the route closure and five deleted HTML files do not yet satisfy the restored-site contract.

### Task 2: Restore the final tracked HTML pages and landing routes

**Files:**
- Create: `public/landing/index.html`
- Create: `public/pricing.html`
- Create: `public/terms.html`
- Create: `public/privacy.html`
- Create: `public/refund.html`
- Modify: `routes/web.php`
- Test: `tests/home-login-redirect.test.js`

- [ ] **Step 1: Restore the exact deleted HTML blobs from their last tracked revisions**

Run the source-controlled restore against explicit files only:

```bash
git restore --source='2b92c1892de7a708297e4ae7f5a4df3b3cc76e20^' -- public/landing/index.html
git restore --source='b62ce856f4fd153479022edc2f9f9dbc5462a63e^' -- \
  public/pricing.html \
  public/terms.html \
  public/privacy.html \
  public/refund.html
```

Expected: the five HTML files appear as added files without modifying `public/landing/assets/`.

- [ ] **Step 2: Verify every restored file is byte-identical to its Git source**

Run:

```bash
cmp public/landing/index.html <(git show '2b92c1892de7a708297e4ae7f5a4df3b3cc76e20^:public/landing/index.html')
for public_page in public/pricing.html public/terms.html public/privacy.html public/refund.html; do
  cmp "$public_page" <(git show "b62ce856f4fd153479022edc2f9f9dbc5462a63e^:$public_page") || exit 1
done
```

Expected: exit code `0` and no output.

- [ ] **Step 3: Replace the root redirect closure with the shared landing response**

Use `apply_patch` in `routes/web.php` to replace `$redirectToLogin` and its two routes with:

```php
$serveLandingPage = function (Request $request) use ($isAllowedAppHost) {
    // 检查管理员安全模式设置，保持与 /app 相同的 Host 保护。
    if (!$isAllowedAppHost($request)) {
        abort(403);
    }

    $landingPagePath = public_path('landing/index.html');
    if (!File::exists($landingPagePath)) {
        abort(404, 'Landing page not found');
    }

    return response(File::get($landingPagePath), 200)
        ->header('Content-Type', 'text/html; charset=utf-8');
};

// Public website homepage.
Route::get('/', $serveLandingPage);

// Legacy public website entrypoint.
Route::get('/welcome', $serveLandingPage);
```

- [ ] **Step 4: Run the restored-site contract**

Run:

```bash
node --test tests/home-login-redirect.test.js
```

Expected: `3` tests pass, `0` fail.

- [ ] **Step 5: Check PHP syntax**

Run:

```bash
php -l routes/web.php
```

Expected: `No syntax errors detected in routes/web.php`.

### Task 3: Verify the repository change and commit it

**Files:**
- Modify: `routes/web.php`
- Modify: `tests/home-login-redirect.test.js`
- Create: `public/landing/index.html`
- Create: `public/pricing.html`
- Create: `public/terms.html`
- Create: `public/privacy.html`
- Create: `public/refund.html`

- [ ] **Step 1: Run focused public-page regressions**

Run:

```bash
node --test \
  tests/home-login-redirect.test.js \
  tests/app-download-rate-limit.test.js \
  tests/elephant-route-dashboard-actions.test.js \
  tests/prorated-renewal-order.test.js
```

Expected: all tests pass with zero failures.

- [ ] **Step 2: Audit HTML titles, links, preserved resources, and Git whitespace**

Run:

```bash
for required_file in \
  public/landing/index.html \
  public/pricing.html \
  public/terms.html \
  public/privacy.html \
  public/refund.html \
  public/download/index.html \
  resources/views/support_ai.blade.php; do
  test -f "$required_file" || exit 1
done
git diff --check
git diff --name-status HEAD
```

Expected: exit code `0`; the diff contains only the route, regression test, and five restored HTML pages because this implementation plan is committed before execution begins.

- [ ] **Step 3: Commit the tested restoration without pushing it**

Run:

```bash
git add -- \
  routes/web.php \
  tests/home-login-redirect.test.js \
  public/landing/index.html \
  public/pricing.html \
  public/terms.html \
  public/privacy.html \
  public/refund.html
git commit -m "feat: restore public website"
```

Expected: one local commit containing the route, contract, and five restored pages; no remote push.

### Task 4: Publish and accept the local Docker test environment

**Files:**
- Runtime: `docker-compose.yml` service `web`
- Verify: `http://127.0.0.1:7001`

- [ ] **Step 1: Record the current non-web container identities**

Run:

```bash
docker inspect xboard-horizon-1 xboard-redis-1 \
  --format '{{.Name}} {{.Id}} {{.State.StartedAt}}'
```

Expected: two lines recording the Horizon and Redis container IDs/start times before the web restart.

- [ ] **Step 2: Restart only Laravel Octane web**

Run:

```bash
docker compose restart web
```

Expected: `xboard-web-1` restarts successfully; Horizon and Redis are not restarted.

- [ ] **Step 3: Wait for the local web endpoint and verify every public route**

Run:

```bash
for attempt_number in {1..20}; do
  if curl -fsS --max-time 5 http://127.0.0.1:7001/ >/dev/null; then
    break
  fi
  sleep 1
done

for route_path in / /welcome /pricing.html /terms.html /privacy.html /refund.html /app; do
  curl -sS --max-time 10 -o /dev/null \
    -w "$route_path status=%{http_code} redirects=%{num_redirects} type=%{content_type}\n" \
    "http://127.0.0.1:7001$route_path"
done
```

Expected: every route returns `200` without an HTTP redirect; HTML routes report an HTML content type.

- [ ] **Step 4: Verify rendered titles, key links, and landing images over HTTP**

Run:

```bash
curl -fsS http://127.0.0.1:7001/ | rg '<title>大象网络 - CONNECT THE UNSEEN</title>|href="/(pricing|terms|privacy)\.html"'
curl -fsS http://127.0.0.1:7001/pricing.html | rg '<title>定价方案 - 大象网络</title>'
curl -fsS http://127.0.0.1:7001/terms.html | rg '<title>服务条款 - 大象网络</title>'
curl -fsS http://127.0.0.1:7001/privacy.html | rg '<title>隐私政策 - 大象网络</title>'
curl -fsS http://127.0.0.1:7001/refund.html | rg '<title>退款说明 - 大象网络</title>'
curl -fsS -o /dev/null http://127.0.0.1:7001/landing/assets/elephant-route-logo.jpg
```

Expected: every command exits `0` and prints the expected page title or homepage links.

- [ ] **Step 5: Verify container isolation and service health**

Run:

```bash
docker compose ps
docker inspect xboard-horizon-1 xboard-redis-1 \
  --format '{{.Name}} {{.Id}} {{.State.StartedAt}}'
docker exec xboard-redis-1 redis-cli ping
```

Expected: `web` and `horizon` are running, Redis is healthy, `redis-cli` prints `PONG`, and the Horizon/Redis IDs and start times match Step 1.

- [ ] **Step 6: Perform a Chrome visual acceptance pass**

Open `http://127.0.0.1:7001/` in Chrome, verify the restored hero/header/footer render without missing images, then follow the page links to pricing, terms, and privacy. Open `/refund.html` directly and verify its heading and return-home link.

Expected: all five page designs render, navigation works, and Chrome shows no broken images.

- [ ] **Step 7: Run final repository verification**

Run:

```bash
git status --short --branch
git log -3 --oneline
```

Expected: `master` has no uncommitted implementation changes and contains separate design, plan, and restoration commits; nothing has been pushed by this workflow.
