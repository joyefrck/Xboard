# Knowledge Image Adaptive Compression Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compress knowledge-base images adaptively without changing their original pixel dimensions.

**Architecture:** Keep the existing Blade-based shared upload queue and replace only its Canvas encoding policy. A small encoder tries WebP qualities in order, stops at the first result at or below 1 MiB, otherwise returns the smallest attempted result, and the caller still falls back to the original file unless compression is genuinely smaller.

**Tech Stack:** Laravel Blade, browser Canvas and `createImageBitmap`, Node.js contract tests, Docker Compose with Laravel Octane.

---

### Task 1: Lock the no-resize adaptive compression contract

**Files:**
- Modify: `tests/knowledge-image-paste-upload.test.js:59-86`

- [x] **Step 1: Replace the old resize assertions with adaptive-compression assertions**

Assert that the Blade source contains `KNOWLEDGE_IMAGE_TARGET_BYTES = 1024 * 1024`, qualities `[0.82, 0.74, 0.65]`, a dedicated `encodeKnowledgeImage` function, one-to-one Canvas dimensions, target-size early selection, smallest-result fallback, and the existing smaller-than-original guard. Assert that `KNOWLEDGE_IMAGE_MAX_SIDE`, `scale`, and dimension rounding are absent.

- [x] **Step 2: Run the focused test and confirm the new contract fails against the old implementation**

Run: `node --test tests/knowledge-image-paste-upload.test.js`

Expected: the compression-contract test fails because the current source still contains `KNOWLEDGE_IMAGE_MAX_SIDE = 2560` and lacks the adaptive quality constants.

### Task 2: Implement original-dimension adaptive WebP encoding

**Files:**
- Modify: `resources/views/admin.blade.php:145-357`

- [x] **Step 1: Replace resize constants with target and quality constants**

Use:

```js
var KNOWLEDGE_IMAGE_TARGET_BYTES = 1024 * 1024;
var KNOWLEDGE_IMAGE_WEBP_QUALITIES = [0.82, 0.74, 0.65];
```

- [x] **Step 2: Add the sequential adaptive encoder**

Add `encodeKnowledgeImage(canvas)`. It must call `canvasToBlob` sequentially, keep the smallest non-null blob, stop encoding after the first blob at or below `KNOWLEDGE_IMAGE_TARGET_BYTES`, and return the smallest attempted blob if no quality reaches the target.

- [x] **Step 3: Preserve source dimensions in the compressor**

Set `canvas.width = bitmap.width` and `canvas.height = bitmap.height`, draw the bitmap at those same dimensions, and pass the Canvas to `encodeKnowledgeImage`. Preserve the GIF bypass, unsupported-browser fallback, bitmap cleanup, WebP filename conversion, and `blob.size >= file.size` original-file fallback.

- [x] **Step 4: Run JavaScript syntax and focused tests**

Run:

```bash
sed -n '145,526p' resources/views/admin.blade.php | node --check -
node --test tests/knowledge-image-paste-upload.test.js
```

Expected: JavaScript syntax exits 0 and all eight focused tests pass.

### Task 3: Verify integration and reload the local service

**Files:**
- Verify: `resources/views/admin.blade.php`
- Verify: `tests/knowledge-image-paste-upload.test.js`

- [x] **Step 1: Run complete repository verification**

Run:

```bash
node --test tests/*.test.js
php artisan view:cache
git diff --check
```

Expected: 244 or more Node tests pass with zero failures, Blade templates cache successfully, and `git diff --check` exits 0. The existing PHP 8.5 PDO deprecation may still be printed.

- [x] **Step 2: Restart only the local Web container**

Run: `docker compose -f docker-compose.yml restart web`

Do not restart Horizon or Redis because this is a Blade-only runtime change.

- [x] **Step 3: Verify runtime health and mounted source**

Run Octane status, Horizon status, Redis `PING`, and an HTTP request to `/api/v1/guest/comm/config`. Confirm the mounted Blade contains `KNOWLEDGE_IMAGE_WEBP_QUALITIES` and does not contain `KNOWLEDGE_IMAGE_MAX_SIDE`.

- [x] **Step 4: Report the working-tree result without committing**

List all modified and untracked paths, explicitly preserve `storage/app/public/knowledge-images/2026/09/`, and do not commit, push, or deploy.
