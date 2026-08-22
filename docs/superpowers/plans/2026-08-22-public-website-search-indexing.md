# Public Website Search Indexing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the five restored public website pages consistently crawlable, canonical, discoverable, and readable without depending on JavaScript-rendered primary headings.

**Architecture:** Keep the public site static and add a focused Node.js contract for SEO metadata, semantic headings, robots, sitemap, and the legacy redirect. Canonicalize the duplicate homepage at the Laravel route layer, while serving all discovery metadata from static files with no new runtime dependencies or network requests.

**Tech Stack:** Static HTML, XML sitemap, robots.txt, Laravel routes, Node.js built-in test runner, Docker Compose, curl

---

### Task 1: Add failing search-indexing contracts

**Files:**
- Create: `tests/public-website-search-indexing.test.js`
- Modify: `tests/home-login-redirect.test.js`

- [ ] **Step 1: Add the public-page SEO contract**

Create a Node.js test that reads the five public HTML files and asserts each has its exact title, a non-empty description, `index,follow,max-image-preview:large`, its unique absolute canonical URL, matching `og:url`, `og:type=website`, and a non-empty static `<h1>`. Also assert that the homepage contains `WebSite` and `Organization` JSON-LD, that `public/robots.txt` references the production sitemap, and that `public/sitemap.xml` contains exactly these URLs:

```text
https://www.elphantroute.com/
https://www.elphantroute.com/pricing.html
https://www.elphantroute.com/terms.html
https://www.elphantroute.com/privacy.html
https://www.elphantroute.com/refund.html
```

- [ ] **Step 2: Change the legacy-route contract**

Update `tests/home-login-redirect.test.js` so `/` must still use `$serveLandingPage`, while `/welcome` must use a Host-protected closure returning `redirect('/', 301)` instead of rendering a duplicate page.

- [ ] **Step 3: Confirm the contracts fail for the current implementation**

Run:

```bash
node --test tests/public-website-search-indexing.test.js tests/home-login-redirect.test.js
```

Expected: failure because sitemap, per-page metadata, static headings, and the permanent redirect are not implemented.

### Task 2: Add static crawl and canonical signals

**Files:**
- Modify: `public/landing/index.html`
- Modify: `public/pricing.html`
- Modify: `public/terms.html`
- Modify: `public/privacy.html`
- Modify: `public/refund.html`
- Modify: `public/robots.txt`
- Create: `public/sitemap.xml`
- Modify: `routes/web.php`

- [ ] **Step 1: Complete metadata for all public pages**

Add exact absolute canonical and Open Graph URLs for the five pages, plus unique descriptions and this robots directive:

```html
<meta name="robots" content="index,follow,max-image-preview:large" />
```

Keep the homepage canonical at `https://www.elphantroute.com/`, and use the matching `.html` URL for each secondary page.

- [ ] **Step 2: Make primary headings available in source HTML**

Change the homepage heading to:

```html
<h1 id="heroTitle">CONNECT THE <span class="gradient-word">UNSEEN</span></h1>
```

Clear it once at the start of the existing bottom script before the load handler so the current typewriter animation can rebuild it without duplication. Change the pricing page's `.pricing-title` element from `h2` to `h1` without changing its class or styling.

- [ ] **Step 3: Add minimal homepage JSON-LD**

Add a static `application/ld+json` graph for `WebSite` and `Organization`, using `https://www.elphantroute.com/` and the existing absolute logo URL. Do not add dynamic Offer markup.

- [ ] **Step 4: Publish robots and sitemap discovery files**

Set `public/robots.txt` to allow all crawlers and include:

```text
Sitemap: https://www.elphantroute.com/sitemap.xml
```

Create a UTF-8 `public/sitemap.xml` using the standard sitemap namespace and exactly the five canonical URLs.

- [ ] **Step 5: Canonicalize the legacy homepage route**

Replace the duplicate `/welcome` renderer with a closure that applies `$isAllowedAppHost` and returns `redirect('/', 301)` when allowed. Keep the root page and `/app` behavior unchanged.

- [ ] **Step 6: Run focused contracts**

Run:

```bash
node --test tests/public-website-search-indexing.test.js tests/home-login-redirect.test.js
```

Expected: all focused tests pass.

### Task 3: Run public-site regressions

**Files:**
- Test: `tests/public-website-search-indexing.test.js`
- Test: `tests/home-login-redirect.test.js`
- Test: `tests/footer-pricing-navigation.test.js`
- Test: `tests/homepage-image-performance.test.js`

- [ ] **Step 1: Run related static contracts**

Run:

```bash
node --test \
  tests/public-website-search-indexing.test.js \
  tests/home-login-redirect.test.js \
  tests/footer-pricing-navigation.test.js \
  tests/homepage-image-performance.test.js
```

Expected: all tests pass with zero failures.

- [ ] **Step 2: Check syntax, XML, image budget, and whitespace**

Run:

```bash
php -l routes/web.php
xmllint --noout public/sitemap.xml
du -ch public/landing/assets/*.webp | tail -n 1
git diff --check
```

Expected: PHP and XML parse successfully, image total remains within the existing test budget, and no whitespace errors are reported.

- [ ] **Step 3: Commit the implementation locally**

Stage only the eight implementation/test files and commit with:

```bash
git commit -m "feat: make public website search indexable"
```

Do not push a remote or deploy production.

### Task 4: Accept the local Docker runtime

**Files:**
- Runtime: local Docker Compose services

- [ ] **Step 1: Record current container identities and restart only web**

Use `docker compose ps -q` to record web, Horizon, and Redis IDs. Restart only the web service because Laravel routes are loaded by the running Octane worker, then confirm Horizon and Redis identities are unchanged.

- [ ] **Step 2: Verify HTTP behavior**

Use `curl` against `http://127.0.0.1:7001` and require:

- `/`, `/pricing.html`, `/terms.html`, `/privacy.html`, `/refund.html`, `/robots.txt`, and `/sitemap.xml` return `200`.
- `/welcome` returns `301` with `Location: /`.
- The HTML responses contain their expected canonical and robots tags.
- `robots.txt` references the sitemap; sitemap contains the five expected absolute URLs.
- `/app` still returns `200`.

- [ ] **Step 3: Inspect the rendered pages**

Open the local homepage and pricing page in a real browser. Confirm the homepage typewriter animation finishes with `CONNECT THE UNSEEN`, the pricing heading and footer navigation render correctly, no new requests were added by SEO metadata, and the console has no new errors.

- [ ] **Step 4: Report the boundary accurately**

Report that the local Docker test environment is search-engine-ready at the code and HTTP level. Explicitly note that production remains unchanged and actual indexing starts only after production deployment and sitemap submission/crawl; indexing and ranking cannot be guaranteed.
