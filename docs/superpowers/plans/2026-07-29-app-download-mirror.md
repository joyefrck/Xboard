# App Download Mirror Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Android, macOS, and Windows package bytes to `47.80.3.248` while Xboard keeps authorization, download logging, automatic upload synchronization, and immediate local fallback.

**Architecture:** Xboard stores every package locally as the source of truth and dispatches a dedicated Horizon job that streams an immutable copy to a restricted SFTP account. The existing signed Laravel download route records the download, probes a short-lived signed Nginx URL, and redirects only when the mirror is ready and healthy; otherwise it preserves the current local response.

**Tech Stack:** PHP 8.2, Laravel 12, Horizon 5, Redis, Flysystem SFTP v3/phpseclib, Nginx `secure_link`, Debian 12, Cloudflare DNS-only, Node.js contract tests, Docker Compose.

---

## File map

### Create

- `database/migrations/2026_07_29_000001_add_mirror_fields_to_app_artifacts.php` — persistent mirror lifecycle.
- `app/Services/AppDownloadMirror.php` — target paths, SFTP streaming, atomic promotion, cleanup, and error sanitization.
- `app/Services/AppDownloadMirrorUrlSigner.php` — Nginx-compatible expiring URLs.
- `app/Services/AppDownloadMirrorRouter.php` — readiness and cached health decision for redirects.
- `app/Jobs/SyncAppArtifactMirror.php` — serialized, retryable artifact upload.
- `app/Jobs/DeleteAppArtifactMirror.php` — best-effort remote cleanup after delete.
- `app/Console/Commands/SyncAppDownloadMirrors.php` — initial backfill and operational resync.
- `tests/app-download-mirror.test.js` — repository contracts and signer fixture.

### Modify

- `composer.json`, `composer.lock` — install `league/flysystem-sftp-v3`.
- `.env.example`, `config/app_downloads.php`, `config/filesystems.php` — mirror, signing, health, and SFTP settings.
- `config/queue.php`, `config/horizon.php` — isolated long-running mirror queue.
- `docker-compose.yaml` — read-only SFTP private-key mount for Horizon only.
- `app/Models/AppArtifact.php` — mirror constants, fields, and casts.
- `app/Services/AppArtifactStorage.php` — mark and dispatch sync on create/replace; retain local files.
- `app/Http/Controllers/V1/Guest/AppDownloadController.php` — redirect after logging with local fallback.
- `app/Http/Controllers/V2/Admin/AppPackageController.php` — retry endpoint and remote cleanup dispatch.
- `app/Http/Routes/V2/AdminRoute.php` — protected retry route.
- `resources/views/admin_app_downloads.blade.php` — mirror status, retry action, and upload feedback.

### Production-only

- `/etc/ssh/sshd_config.d/xboard-download-mirror.conf` — SFTP-only account boundary.
- `/etc/ssh/authorized_keys/xboardmirror` — public key from the Xboard host.
- `/etc/nginx/sites-available/download.elephant111.com` — signed download virtual host.
- `/etc/nginx/xboard-download-secret.conf` — root-readable signing secret.
- `/srv/xboard-downloads/files/` — mirrored package tree.
- `/opt/xboard-secrets/app-download-mirror/id_ed25519` — private key on the Xboard host, outside Git.

## Task 1: Add failing mirror contracts

**Files:**
- Create: `tests/app-download-mirror.test.js`
- Read: `docs/superpowers/specs/2026-07-29-app-download-mirror-design.md`

- [ ] **Step 1: Add configuration and schema contracts**

Create a Node test using the repository's existing `readRepoFile()` convention. Require the migration fields, model constants, isolated queue retry window, SFTP adapter, and environment keys:

```js
test('mirror schema and isolated queue are explicit', () => {
  const migration = readRepoFile(
    'database/migrations/2026_07_29_000001_add_mirror_fields_to_app_artifacts.php'
  );
  const model = readRepoFile('app/Models/AppArtifact.php');
  const queue = readRepoFile('config/queue.php');
  const horizon = readRepoFile('config/horizon.php');
  const env = readRepoFile('.env.example');

  for (const field of ['mirror_status', 'mirror_path', 'mirror_error', 'mirrored_at']) {
    assert.match(migration, new RegExp(field));
  }
  for (const status of ['local', 'pending', 'syncing', 'ready', 'failed']) {
    assert.match(model, new RegExp(`MIRROR_${status.toUpperCase()}`));
  }
  assert.match(queue, /'redis_mirror'/);
  assert.match(queue, /'retry_after'\s*=>\s*1900/);
  assert.match(horizon, /'XboardAppDownloadMirror'/);
  assert.match(horizon, /'connection'\s*=>\s*'redis_mirror'/);
  assert.match(env, /APP_DOWNLOAD_MIRROR_ENABLED=false/);
  assert.match(env, /APP_DOWNLOAD_MIRROR_SYNC_ENABLED=false/);
});
```

- [ ] **Step 2: Add sync, redirect, and admin contracts**

In the same test file, assert these invariants:

```js
test('mirror sync is atomic and stale jobs cannot publish', () => {
  const service = readRepoFile('app/Services/AppDownloadMirror.php');
  const job = readRepoFile('app/Jobs/SyncAppArtifactMirror.php');

  assert.match(service, /readStream/);
  assert.match(service, /\.part-/);
  assert.match(service, /->size\(/);
  assert.match(service, /->move\(/);
  assert.match(job, /\$expectedSha256/);
  assert.match(job, /hash_equals\(\$this->expectedSha256,\s*\$artifact->sha256\)/);
  assert.match(job, /Cache::lock/);
  assert.match(job, /public \$tries = 3/);
  assert.match(job, /public \$timeout = 1800/);
});

test('download logs before redirect and always retains local fallback', () => {
  const controller = readRepoFile(
    'app/Http/Controllers/V1/Guest/AppDownloadController.php'
  );
  const logAt = controller.indexOf('AppDownloadLog::create');
  const redirectAt = controller.indexOf('redirect()->away');
  const localAt = controller.indexOf('response()->download');

  assert.ok(logAt !== -1 && redirectAt !== -1 && localAt !== -1);
  assert.ok(logAt < redirectAt);
  assert.ok(redirectAt < localAt);
});

test('admin exposes mirror state and retry without changing upload fields', () => {
  const route = readRepoFile('app/Http/Routes/V2/AdminRoute.php');
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(route, /post\('\/versions\/mirror\/retry'/);
  assert.match(page, /镜像状态/);
  assert.match(page, /重新同步/);
  assert.match(page, /安装包已发布，正在同步到下载服务器/);
  assert.match(page, /name="artifact"/);
});
```

- [ ] **Step 3: Add a signer compatibility fixture**

Use Node's crypto implementation to lock the PHP/Nginx formula to one fixture:

```js
test('secure-link formula remains nginx compatible', () => {
  const crypto = require('node:crypto');
  const expires = 1800000000;
  const uri = '/files/artifacts/50/abc/ElephantNetwork-Setup-x64-v1.6.6.exe';
  const key = 'fixture-signing-key';
  const expected = crypto
    .createHash('md5')
    .update(`${expires}${uri} ${key}`)
    .digest('base64url');

  assert.equal(expected, 'vu3Yo33ecUPO1ROEJu4anw');
  const signer = readRepoFile('app/Services/AppDownloadMirrorUrlSigner.php');
  assert.match(signer, /md5\(\$expires \. \$uri \. ' ' \. \$key,\s*true\)/);
  assert.match(signer, /strtr\(\$digest,\s*'\\+\\/',\s*'-_'\)/);
});
```

- [ ] **Step 4: Prove the new contracts fail**

Run:

```bash
node --test tests/app-download-mirror.test.js
```

Expected: FAIL because the migration, services, jobs, route, and UI do not exist yet.

- [ ] **Step 5: Commit the failing contracts**

```bash
git add tests/app-download-mirror.test.js
git commit -m "test: define app download mirror contracts"
```

## Task 2: Add dependency, configuration, schema, and model state

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `.env.example`
- Modify: `config/app_downloads.php`
- Modify: `config/filesystems.php`
- Modify: `config/queue.php`
- Modify: `config/horizon.php`
- Modify: `docker-compose.yaml`
- Create: `database/migrations/2026_07_29_000001_add_mirror_fields_to_app_artifacts.php`
- Modify: `app/Models/AppArtifact.php`
- Test: `tests/app-download-mirror.test.js`

- [ ] **Step 1: Install the SFTP adapter**

Run:

```bash
composer require league/flysystem-sftp-v3:^3.25 --no-interaction
```

Expected: `composer.json` and `composer.lock` contain `league/flysystem-sftp-v3`; Composer resolves phpseclib without changing Laravel's major version.

- [ ] **Step 2: Add mirror environment and config**

Append these non-secret defaults to `.env.example`:

```dotenv
APP_DOWNLOAD_MIRROR_ENABLED=false
APP_DOWNLOAD_MIRROR_SYNC_ENABLED=false
APP_DOWNLOAD_MIRROR_BASE_URL=https://download.elephant111.com
APP_DOWNLOAD_MIRROR_SIGNING_KEY=
APP_DOWNLOAD_MIRROR_URL_TTL_SECONDS=600
APP_DOWNLOAD_MIRROR_HEALTH_CACHE_SECONDS=45
APP_DOWNLOAD_MIRROR_HEALTH_TIMEOUT_SECONDS=2
APP_DOWNLOAD_MIRROR_HOST=47.80.3.248
APP_DOWNLOAD_MIRROR_PORT=22
APP_DOWNLOAD_MIRROR_USERNAME=xboardmirror
APP_DOWNLOAD_MIRROR_PRIVATE_KEY=/run/secrets/app-download-mirror/id_ed25519
APP_DOWNLOAD_MIRROR_ROOT=/files
APP_DOWNLOAD_MIRROR_KEY_DIR=./.docker/.data/app-download-mirror
```

Extend `config/app_downloads.php` with:

```php
'mirror' => [
    'enabled' => (bool) env('APP_DOWNLOAD_MIRROR_ENABLED', false),
    'sync_enabled' => (bool) env('APP_DOWNLOAD_MIRROR_SYNC_ENABLED', false),
    'disk' => 'app_download_mirror',
    'base_url' => rtrim((string) env('APP_DOWNLOAD_MIRROR_BASE_URL', ''), '/'),
    'signing_key' => (string) env('APP_DOWNLOAD_MIRROR_SIGNING_KEY', ''),
    'url_ttl_seconds' => max(60, (int) env('APP_DOWNLOAD_MIRROR_URL_TTL_SECONDS', 600)),
    'health_cache_seconds' => max(1, (int) env('APP_DOWNLOAD_MIRROR_HEALTH_CACHE_SECONDS', 45)),
    'health_timeout_seconds' => max(1, (int) env('APP_DOWNLOAD_MIRROR_HEALTH_TIMEOUT_SECONDS', 2)),
],
```

Add the `app_download_mirror` SFTP disk to `config/filesystems.php`:

```php
'app_download_mirror' => [
    'driver' => 'sftp',
    'host' => env('APP_DOWNLOAD_MIRROR_HOST'),
    'username' => env('APP_DOWNLOAD_MIRROR_USERNAME'),
    'privateKey' => env('APP_DOWNLOAD_MIRROR_PRIVATE_KEY'),
    'port' => (int) env('APP_DOWNLOAD_MIRROR_PORT', 22),
    'root' => env('APP_DOWNLOAD_MIRROR_ROOT', '/files'),
    'timeout' => 30,
    'throw' => true,
],
```

- [ ] **Step 3: Isolate long uploads from the 90-second Redis retry window**

Add `redis_mirror` to `config/queue.php`:

```php
'redis_mirror' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => 'app_download_mirror',
    'retry_after' => 1900,
    'block_for' => null,
],
```

Add a fixed one-process supervisor to `config/horizon.php`:

```php
'XboardAppDownloadMirror' => [
    'connection' => 'redis_mirror',
    'queue' => ['app_download_mirror'],
    'balance' => false,
    'minProcesses' => 1,
    'maxProcesses' => 1,
    'tries' => 3,
    'timeout' => 1800,
    'maxJobs' => 10,
    'maxTime' => 3600,
],
```

Mount the production-selectable key directory only into Horizon in `docker-compose.yaml`:

```yaml
  horizon:
    volumes:
      - ./.docker/.data/redis/:/data/
      - ./:/www/
      - ${APP_DOWNLOAD_MIRROR_KEY_DIR:-./.docker/.data/app-download-mirror}:/run/secrets/app-download-mirror:ro
```

- [ ] **Step 4: Add the mirror columns**

Create the migration:

```php
Schema::table('v2_app_artifacts', function (Blueprint $table) {
    $table->string('mirror_status', 16)->default('local')->index()->after('uploaded_by');
    $table->string('mirror_path', 1024)->nullable()->after('mirror_status');
    $table->text('mirror_error')->nullable()->after('mirror_path');
    $table->timestamp('mirrored_at')->nullable()->after('mirror_error');
});
```

Its `down()` drops the index and the four fields in one `Schema::table()` call.

- [ ] **Step 5: Define model state**

Add constants and fields to `AppArtifact.php`:

```php
public const MIRROR_LOCAL = 'local';
public const MIRROR_PENDING = 'pending';
public const MIRROR_SYNCING = 'syncing';
public const MIRROR_READY = 'ready';
public const MIRROR_FAILED = 'failed';

public const MIRROR_STATUSES = [
    self::MIRROR_LOCAL,
    self::MIRROR_PENDING,
    self::MIRROR_SYNCING,
    self::MIRROR_READY,
    self::MIRROR_FAILED,
];
```

Add `mirror_status`, `mirror_path`, `mirror_error`, and `mirrored_at` to `$fillable`; cast `mirrored_at` to `datetime`.

- [ ] **Step 6: Verify configuration and schema**

Run:

```bash
php -l config/app_downloads.php
php -l config/filesystems.php
php -l config/queue.php
php -l config/horizon.php
php -l database/migrations/2026_07_29_000001_add_mirror_fields_to_app_artifacts.php
php -l app/Models/AppArtifact.php
composer validate --no-check-publish
docker compose -f docker-compose.yaml config --quiet
node --test tests/app-download-mirror.test.js
```

Expected: syntax, Composer, and Compose checks pass; mirror tests still fail only for services, jobs, controller, route, and UI that are deliberately not implemented yet.

- [ ] **Step 7: Commit the foundation**

```bash
git add composer.json composer.lock .env.example config/app_downloads.php config/filesystems.php config/queue.php config/horizon.php docker-compose.yaml database/migrations/2026_07_29_000001_add_mirror_fields_to_app_artifacts.php app/Models/AppArtifact.php
git commit -m "feat: add app download mirror foundation"
```

## Task 3: Implement deterministic signing and health-gated routing

**Files:**
- Create: `app/Services/AppDownloadMirrorUrlSigner.php`
- Create: `app/Services/AppDownloadMirrorRouter.php`
- Test: `tests/app-download-mirror.test.js`

- [ ] **Step 1: Implement the Nginx signer**

Create `AppDownloadMirrorUrlSigner` with these public methods:

```php
public function url(string $mirrorPath, ?CarbonInterface $expiresAt = null): string
{
    $baseUrl = (string) config('app_downloads.mirror.base_url');
    $key = (string) config('app_downloads.mirror.signing_key');
    if ($baseUrl === '' || $key === '') {
        throw new RuntimeException('App download mirror signing is not configured');
    }

    $path = collect(explode('/', ltrim($mirrorPath, '/')))
        ->map(fn (string $segment) => rawurlencode($segment))
        ->implode('/');
    $uri = '/files/' . $path;
    $expires = ($expiresAt ?? now()->addSeconds(
        (int) config('app_downloads.mirror.url_ttl_seconds', 600)
    ))->getTimestamp();
    $digest = base64_encode(md5($expires . $uri . ' ' . $key, true));
    $signature = rtrim(strtr($digest, '+/', '-_'), '=');

    return $baseUrl . $uri . '?md5=' . $signature . '&expires=' . $expires;
}
```

Also add `public function urlFor(AppArtifact $artifact): string`, which rejects an empty `mirror_path` and calls `url($artifact->mirror_path)`.

- [ ] **Step 2: Implement readiness and cached health**

Create `AppDownloadMirrorRouter`:

```php
public function redirectUrl(AppArtifact $artifact): ?string
{
    if (!(bool) config('app_downloads.mirror.enabled', false)
        || $artifact->mirror_status !== AppArtifact::MIRROR_READY
        || !$artifact->mirror_path) {
        return null;
    }

    try {
        $url = $this->signer->urlFor($artifact);
        $healthy = Cache::remember(
            'app-download-mirror:health:' . $artifact->id . ':' . sha1($artifact->mirror_path),
            now()->addSeconds((int) config('app_downloads.mirror.health_cache_seconds', 45)),
            fn () => Http::connectTimeout(1)
                ->timeout((int) config('app_downloads.mirror.health_timeout_seconds', 2))
                ->head($url)
                ->successful()
        );

        return $healthy ? $url : null;
    } catch (Throwable $e) {
        Log::warning('App download mirror health check failed', [
            'artifact_id' => $artifact->id,
            'exception' => $e::class,
        ]);
        return null;
    }
}
```

Inject `AppDownloadMirrorUrlSigner` through the constructor. Do not log the signed URL or secret.

- [ ] **Step 3: Add signer and router source contracts**

Extend `tests/app-download-mirror.test.js` to require:

```js
assert.match(router, /mirror_status\s*!==\s*AppArtifact::MIRROR_READY/);
assert.match(router, /Cache::remember/);
assert.match(router, /->connectTimeout\(1\)/);
assert.match(router, /->head\(\$url\)/);
assert.match(router, /return \$healthy \? \$url : null/);
assert.doesNotMatch(router, /Log::.*\$url/);
```

- [ ] **Step 4: Verify and commit**

Run:

```bash
php -l app/Services/AppDownloadMirrorUrlSigner.php
php -l app/Services/AppDownloadMirrorRouter.php
node --test tests/app-download-mirror.test.js
```

Expected: signer and router assertions pass; sync/admin/controller assertions remain red.

```bash
git add app/Services/AppDownloadMirrorUrlSigner.php app/Services/AppDownloadMirrorRouter.php tests/app-download-mirror.test.js
git commit -m "feat: sign and probe app download mirrors"
```

## Task 4: Implement atomic SFTP synchronization

**Files:**
- Create: `app/Services/AppDownloadMirror.php`
- Create: `app/Jobs/SyncAppArtifactMirror.php`
- Create: `app/Jobs/DeleteAppArtifactMirror.php`
- Create: `app/Console/Commands/SyncAppDownloadMirrors.php`
- Test: `tests/app-download-mirror.test.js`

- [ ] **Step 1: Implement immutable target paths**

In `AppDownloadMirror`, generate a safe path whose URL basename is also the downloaded filename:

```php
public function targetPath(AppArtifact $artifact): string
{
    $name = Str::ascii($artifact->original_name);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: '';
    $name = trim($name, '._-');
    if ($name === '') {
        $name = 'app.' . strtolower($artifact->extension ?: 'bin');
    }

    return implode('/', [
        'artifacts',
        (string) $artifact->id,
        strtolower($artifact->sha256),
        $name,
    ]);
}
```

- [ ] **Step 2: Stream, verify, and atomically promote**

Implement `sync(AppArtifact $artifact): string`:

```php
$source = Storage::disk($artifact->disk)->readStream($artifact->path);
if ($source === false) {
    throw new RuntimeException('Unable to open local app artifact');
}

$target = $this->targetPath($artifact);
$temporary = $target . '.part-' . Str::uuid();
$remote = Storage::disk(config('app_downloads.mirror.disk', 'app_download_mirror'));

try {
    $remote->put($temporary, $source);
    if ((int) $remote->size($temporary) !== (int) $artifact->file_size) {
        throw new RuntimeException('Mirrored app artifact size mismatch');
    }
    if ($remote->exists($target)) {
        $remote->delete($target);
    }
    $remote->move($temporary, $target);
    return $target;
} finally {
    if (is_resource($source)) {
        fclose($source);
    }
    if ($remote->exists($temporary)) {
        $remote->delete($temporary);
    }
}
```

Add `delete(?string $path): void` with a strict `artifacts/` prefix check. Add `sanitizeError(Throwable $e): string` that strips newlines, replaces values following `password`, `passphrase`, and `privateKey` with `[redacted]`, replaces the configured private-key path with `[redacted]`, and limits the result to 500 characters.

- [ ] **Step 3: Implement stale-safe serialized sync**

Create `SyncAppArtifactMirror` with `artifactId` and `expectedSha256`, queue connection `redis_mirror`, queue `app_download_mirror`, `$tries = 3`, `$timeout = 1800`, and `backoff()` returning `[60, 300]`.

Inside `handle(AppDownloadMirror $mirror)`:

```php
Cache::lock('app-download-mirror:artifact:' . $this->artifactId, 1850)
    ->block(5, function () use ($mirror) {
        $artifact = AppArtifact::find($this->artifactId);
        if (!$artifact || !hash_equals($this->expectedSha256, $artifact->sha256)) {
            return;
        }

        $artifact->update([
            'mirror_status' => AppArtifact::MIRROR_SYNCING,
            'mirror_error' => null,
        ]);
        $oldPath = $artifact->mirror_path;
        $newPath = $mirror->sync($artifact);

        $updated = AppArtifact::query()
            ->whereKey($artifact->id)
            ->where('sha256', $this->expectedSha256)
            ->update([
                'mirror_status' => AppArtifact::MIRROR_READY,
                'mirror_path' => $newPath,
                'mirror_error' => null,
                'mirrored_at' => now(),
            ]);

        if ($updated === 1 && $oldPath && $oldPath !== $newPath) {
            try {
                $mirror->delete($oldPath);
            } catch (Throwable $e) {
                Log::warning('Failed to delete replaced app mirror', [
                    'artifact_id' => $artifact->id,
                    'error' => $mirror->sanitizeError($e),
                ]);
            }
        }
    });
```

In `failed(Throwable $e)`, update to `failed` only when the current row still has `expectedSha256`.

- [ ] **Step 4: Implement best-effort deletion**

Create `DeleteAppArtifactMirror` with a string path, the same connection/queue, `$tries = 3`, `$timeout = 120`, and:

```php
public function handle(AppDownloadMirror $mirror): void
{
    $mirror->delete($this->path);
}
```

- [ ] **Step 5: Add the initial sync command**

Create `SyncAppDownloadMirrors`:

```php
protected $signature = 'app-downloads:sync-mirrors
    {--artifact=* : Only queue these artifact ids}
    {--force : Queue artifacts that are already ready}';
```

Query artifacts with local files, filter optional IDs, skip `ready` unless `--force`, set each row to `pending`, clear `mirror_error`, dispatch `SyncAppArtifactMirror::dispatch($id, $sha256)->afterCommit()`, and print queued/skipped counts. Return failure when mirror sync is disabled.

- [ ] **Step 6: Verify and commit**

Run:

```bash
php -l app/Services/AppDownloadMirror.php
php -l app/Jobs/SyncAppArtifactMirror.php
php -l app/Jobs/DeleteAppArtifactMirror.php
php -l app/Console/Commands/SyncAppDownloadMirrors.php
node --test tests/app-download-mirror.test.js
```

Expected: sync and queue assertions pass; controller, admin route, and UI assertions remain red.

```bash
git add app/Services/AppDownloadMirror.php app/Jobs/SyncAppArtifactMirror.php app/Jobs/DeleteAppArtifactMirror.php app/Console/Commands/SyncAppDownloadMirrors.php tests/app-download-mirror.test.js
git commit -m "feat: synchronize app download mirrors"
```

## Task 5: Connect upload, replacement, deletion, and manual retry

**Files:**
- Modify: `app/Services/AppArtifactStorage.php`
- Modify: `app/Http/Controllers/V2/Admin/AppPackageController.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`
- Test: `tests/app-download-mirror.test.js`

- [ ] **Step 1: Mark and queue every new local file**

In `AppArtifactStorage`, add:

```php
public function queueMirrorSync(AppArtifact $artifact): void
{
    if (!(bool) config('app_downloads.mirror.sync_enabled', false)) {
        $artifact->update([
            'mirror_status' => AppArtifact::MIRROR_LOCAL,
            'mirror_error' => null,
        ]);
        return;
    }

    $artifact->update([
        'mirror_status' => AppArtifact::MIRROR_PENDING,
        'mirror_error' => null,
        'mirrored_at' => null,
    ]);
    SyncAppArtifactMirror::dispatch($artifact->id, $artifact->sha256)->afterCommit();
}
```

Call it after `store()` persists the artifact and after `updateVersion()` commits a replacement. When updating the artifact, do not clear `mirror_path`; the successful job needs it to clean the old mirror. Do not dispatch when only version notes change.

- [ ] **Step 2: Preserve remote cleanup on version deletion**

Before deleting the model in `AppPackageController::drop()`:

```php
$mirrorPath = $version->artifact?->mirror_path;
if ($version->artifact) {
    $storage->deleteFile($version->artifact);
}
$version->delete();
if ($mirrorPath) {
    DeleteAppArtifactMirror::dispatch($mirrorPath)->afterCommit();
}
```

The current “published versions must be disabled first” guard remains unchanged.

- [ ] **Step 3: Add an authorized manual retry endpoint**

Add this route inside the existing protected `app-package` group:

```php
$router->post('/versions/mirror/retry', [AppPackageController::class, 'retryMirror']);
```

Add the controller method:

```php
public function retryMirror(Request $request, AppArtifactStorage $storage)
{
    $data = $request->validate([
        'artifact_id' => 'required|integer|exists:v2_app_artifacts,id',
    ]);
    $artifact = AppArtifact::findOrFail($data['artifact_id']);
    if (!$storage->exists($artifact)) {
        return $this->fail([404, '本地安装包不存在，无法重新同步']);
    }

    $storage->queueMirrorSync($artifact);
    return $this->success(true);
}
```

Import `AppArtifact` and `DeleteAppArtifactMirror`.

- [ ] **Step 4: Extend lifecycle tests**

Require `queueMirrorSync()` after create and replacement, the SHA-aware dispatch, retained old `mirror_path`, the delete job, and the retry route. Assert notes-only edits do not call `queueMirrorSync()`.

- [ ] **Step 5: Verify and commit**

Run:

```bash
php -l app/Services/AppArtifactStorage.php
php -l app/Http/Controllers/V2/Admin/AppPackageController.php
php -l app/Http/Routes/V2/AdminRoute.php
node --test tests/app-download-mirror.test.js tests/admin-app-download-edit.test.js tests/app-download-rate-limit.test.js
```

Expected: PHP syntax and lifecycle contracts pass; UI assertions remain red.

```bash
git add app/Services/AppArtifactStorage.php app/Http/Controllers/V2/Admin/AppPackageController.php app/Http/Routes/V2/AdminRoute.php tests/app-download-mirror.test.js
git commit -m "feat: queue mirrors from app package lifecycle"
```

## Task 6: Redirect downloads only after logging

**Files:**
- Modify: `app/Http/Controllers/V1/Guest/AppDownloadController.php`
- Test: `tests/app-download-mirror.test.js`
- Test: `tests/app-download-rate-limit.test.js`

- [ ] **Step 1: Inject the router and keep local validation**

Change the download signature:

```php
public function download(
    Request $request,
    AppArtifact $artifact,
    AppArtifactStorage $storage,
    AppDownloadMirrorRouter $mirror
)
```

Keep the existing published/app-active/local-file checks and `AppDownloadLog::create()` in their current order.

- [ ] **Step 2: Add redirect before the unchanged local response**

Immediately after the log:

```php
$mirrorUrl = $mirror->redirectUrl($artifact);
if ($mirrorUrl !== null) {
    return redirect()->away($mirrorUrl);
}

return response()->download(
    $downloadPath,
    $artifact->original_name,
    ['Content-Type' => $storage->downloadMimeType($artifact)]
);
```

- [ ] **Step 3: Verify ordering and fallback**

Run:

```bash
php -l app/Http/Controllers/V1/Guest/AppDownloadController.php
node --test tests/app-download-mirror.test.js tests/app-download-rate-limit.test.js tests/android-app-update.test.js
```

Expected: all focused tests pass and the existing signed route contract is unchanged.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/V1/Guest/AppDownloadController.php tests/app-download-mirror.test.js
git commit -m "feat: redirect ready app downloads to mirror"
```

## Task 7: Add mirror status and retry to the admin page

**Files:**
- Modify: `resources/views/admin_app_downloads.blade.php`
- Test: `tests/app-download-mirror.test.js`
- Test: `tests/admin-app-download-edit.test.js`
- Test: `tests/admin-app-download-counts.test.js`

- [ ] **Step 1: Add the table column and state renderer**

Insert `<th>镜像状态</th>` before the existing status column. Add:

```js
function mirrorStatusBadge(artifact) {
  if (!artifact) return '<span class="badge off">未上传</span>';
  var state = String(artifact.mirror_status || "local");
  var labels = {
    local: ["本地直出", "off"],
    pending: ["等待同步", "warn"],
    syncing: ["同步中", "warn"],
    ready: ["已同步", "ok"],
    failed: ["同步失败", "danger"]
  };
  var item = labels[state] || labels.local;
  var title = artifact.mirror_error
    ? ' title="' + escapeHtml(artifact.mirror_error) + '"'
    : "";
  var error = artifact.mirror_error
    ? '<br><span class="muted">' + escapeHtml(
        String(artifact.mirror_error).slice(0, 160)
      ) + '</span>'
    : "";
  return '<span class="badge ' + item[1] + '"' + title + '>'
    + item[0] + '</span>' + error;
}
```

Add matching styles beside the existing badge styles:

```css
.badge.warn {
  background: #fef3c7;
  color: #92400e;
}

.badge.danger {
  background: #fee2e2;
  color: #991b1b;
}
```

Add the eighth `<td>` in each row and render `mirrorStatusBadge(artifact)` before published/disabled state.

- [ ] **Step 2: Add retry only for failed mirrors**

After the edit action:

```js
if (artifact && artifact.mirror_status === "failed") {
  actions.appendChild(actionButton("重新同步", function () {
    mutate("/versions/mirror/retry", { artifact_id: artifact.id });
  }, "primary"));
}
```

- [ ] **Step 3: Adjust upload and replacement feedback**

Use these exact success messages:

```js
updateUploadProgress(100, "上传完成，正在同步到下载服务器");
showStatus("安装包已发布，正在同步到下载服务器", true);
```

For replacement:

```js
updateVersionEditProgress(
  100,
  replacementFile ? "安装包已替换，正在同步到下载服务器" : "版本信息已更新"
);
showStatus(
  replacementFile ? "版本和安装包已更新，正在同步到下载服务器" : "版本信息已更新",
  true
);
```

- [ ] **Step 4: Verify the complete local suite**

Run:

```bash
node --test tests/app-download-mirror.test.js tests/admin-app-download-edit.test.js tests/admin-app-download-counts.test.js tests/admin-app-downloads-autofill.test.js
node --test tests/*.test.js
```

Expected: all focused and repository Node tests pass.

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin_app_downloads.blade.php tests/app-download-mirror.test.js
git commit -m "feat: show app download mirror state"
```

## Task 8: Provision the independent download server

**Systems:**
- Xboard host: `47.238.145.117`
- Download host: `47.80.3.248`
- DNS zone: `elephant111.com` on Cloudflare

- [ ] **Step 1: Re-check the exact server boundary**

SSH interactively as root without embedding the supplied password in shell history:

```bash
ssh -p 22 root@47.80.3.248
```

Run:

```bash
cat /etc/os-release
ss -lntup
df -h /
free -h
systemctl is-active ssh
nginx -t 2>/dev/null || true
```

Expected: Debian 12, at least 20 GB free, SSH active, and no existing local Nginx listener on 80/443. Stop if ports 80/443 have a real local owner; do not disturb sing-box or Docker DNS.

- [ ] **Step 2: Install Nginx and create the restricted filesystem**

Run on `47.80.3.248`:

```bash
apt-get update
apt-get install -y nginx certbot python3-certbot-nginx
groupadd --system xboard-downloads
useradd --system --gid xboard-downloads --home-dir /files --shell /usr/sbin/nologin xboardmirror
install -d -o root -g root -m 0755 /srv/xboard-downloads
install -d -o xboardmirror -g xboard-downloads -m 0750 /srv/xboard-downloads/files
usermod -aG xboard-downloads www-data
install -d -o root -g root -m 0755 /etc/ssh/authorized_keys
```

Expected: `id xboardmirror` shows no privileged groups; `/srv/xboard-downloads` remains root-owned as required by OpenSSH chroot.

- [ ] **Step 3: Generate the deployment key on the Xboard host**

On `47.238.145.117`, outside the Git worktree:

```bash
install -d -o root -g root -m 0700 /opt/xboard-secrets/app-download-mirror
ssh-keygen -t ed25519 -N '' \
  -C xboard-app-download-mirror \
  -f /opt/xboard-secrets/app-download-mirror/id_ed25519
chmod 0600 /opt/xboard-secrets/app-download-mirror/id_ed25519
```

Copy only the `.pub` content to `/etc/ssh/authorized_keys/xboardmirror` on `47.80.3.248`, then:

```bash
chown root:root /etc/ssh/authorized_keys/xboardmirror
chmod 0600 /etc/ssh/authorized_keys/xboardmirror
```

- [ ] **Step 4: Restrict the SFTP account**

Create `/etc/ssh/sshd_config.d/xboard-download-mirror.conf`:

```sshconfig
Match User xboardmirror
    ChrootDirectory /srv/xboard-downloads
    ForceCommand internal-sftp -d /files -u 0027
    AuthorizedKeysFile /etc/ssh/authorized_keys/%u
    PasswordAuthentication no
    PubkeyAuthentication yes
    PermitTTY no
    AllowTcpForwarding no
    X11Forwarding no
```

Validate before reload:

```bash
sshd -t
systemctl reload ssh
```

From the Xboard host, verify file access through the restricted account:

```bash
printf '%s\n' \
  'put /etc/hostname .connection-probe' \
  'ls -l .connection-probe' \
  'rm .connection-probe' \
  'bye' \
  | sftp -b - \
      -i /opt/xboard-secrets/app-download-mirror/id_ed25519 \
      -P 22 xboardmirror@47.80.3.248
```

Expected: SFTP uploads, lists, and removes the probe. From the Xboard host,
`ssh -i /opt/xboard-secrets/app-download-mirror/id_ed25519 xboardmirror@47.80.3.248`
cannot obtain a shell.

- [ ] **Step 5: Create DNS without Cloudflare proxying**

In the Cloudflare `elephant111.com` zone create:

```text
Type: A
Name: download
IPv4: 47.80.3.248
Proxy status: DNS only
TTL: Auto
```

Verify:

```bash
dig +short A download.elephant111.com @1.1.1.1
```

Expected: exactly `47.80.3.248`. DNS-only is mandatory because this design deliberately avoids CDN proxying.

- [ ] **Step 6: Generate one signing key on the Xboard host**

Generate the secret outside Git and copy it to the download server without printing it:

```bash
install -m 0600 /dev/null /opt/xboard-secrets/app-download-mirror/signing_key
openssl rand -hex 32 > /opt/xboard-secrets/app-download-mirror/signing_key
scp -P 22 /opt/xboard-secrets/app-download-mirror/signing_key \
  root@47.80.3.248:/root/xboard-download-signing-key
```

On the download server:

```bash
umask 077
MIRROR_SIGNING_KEY="$(cat /root/xboard-download-signing-key)"
install -m 0600 /dev/null /etc/nginx/xboard-download-secret.conf
printf 'set $mirror_signing_key "%s";\n' "$MIRROR_SIGNING_KEY" \
  > /etc/nginx/xboard-download-secret.conf
unset MIRROR_SIGNING_KEY
shred -u /root/xboard-download-signing-key
```

- [ ] **Step 7: Configure signed Nginx downloads**

Create `/etc/nginx/sites-available/download.elephant111.com`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name download.elephant111.com;
    root /srv/xboard-downloads;

    include /etc/nginx/xboard-download-secret.conf;

    location /files/ {
        limit_except GET HEAD { deny all; }
        secure_link $arg_md5,$arg_expires;
        secure_link_md5 "$secure_link_expires$uri $mirror_signing_key";
        if ($secure_link = "") { return 403; }
        if ($secure_link = "0") { return 410; }

        sendfile on;
        tcp_nopush on;
        add_header Accept-Ranges bytes always;
        add_header X-Content-Type-Options nosniff always;
    }

    location / {
        return 404;
    }
}
```

Enable and validate:

```bash
ln -s /etc/nginx/sites-available/download.elephant111.com \
  /etc/nginx/sites-enabled/download.elephant111.com
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx
certbot --nginx -d download.elephant111.com \
  --non-interactive --agree-tos --register-unsafely-without-email --redirect
nginx -t
systemctl reload nginx
systemctl is-enabled certbot.timer
```

Expected: Nginx config passes, HTTPS uses a valid certificate, and the timer is enabled. If public 80/443 cannot connect while local Nginx listens, open only TCP 80/443 in the Alibaba Cloud security group.

- [ ] **Step 8: Write production mirror settings without exposing the secret**

On the Xboard host, update non-secret values in `.env`, then insert the signing key from its root-only file with a non-printing PHP command:

```dotenv
APP_DOWNLOAD_MIRROR_SYNC_ENABLED=true
APP_DOWNLOAD_MIRROR_ENABLED=false
APP_DOWNLOAD_MIRROR_BASE_URL=https://download.elephant111.com
APP_DOWNLOAD_MIRROR_HOST=47.80.3.248
APP_DOWNLOAD_MIRROR_PORT=22
APP_DOWNLOAD_MIRROR_USERNAME=xboardmirror
APP_DOWNLOAD_MIRROR_PRIVATE_KEY=/run/secrets/app-download-mirror/id_ed25519
APP_DOWNLOAD_MIRROR_ROOT=/files
APP_DOWNLOAD_MIRROR_KEY_DIR=/opt/xboard-secrets/app-download-mirror
```

From `/opt/1panel/apps/openresty/openresty/www/sites/xboard/index`, run:

```bash
export MIRROR_SIGNING_KEY="$(
  cat /opt/xboard-secrets/app-download-mirror/signing_key
)"
php -r '
$path = ".env";
$secret = getenv("MIRROR_SIGNING_KEY");
$text = file_get_contents($path);
$line = "APP_DOWNLOAD_MIRROR_SIGNING_KEY=".$secret;
$text = preg_match("/^APP_DOWNLOAD_MIRROR_SIGNING_KEY=.*/m", $text)
    ? preg_replace("/^APP_DOWNLOAD_MIRROR_SIGNING_KEY=.*/m", $line, $text)
    : rtrim($text).PHP_EOL.$line.PHP_EOL;
file_put_contents($path, $text);
'
unset MIRROR_SIGNING_KEY
```

Confirm `git status --short` does not show `.env`, private keys, or secret files.

## Task 9: Deploy, migrate the three packages, and enable redirects

**Production source:**
- `/opt/1panel/apps/openresty/openresty/www/sites/xboard/index`

- [ ] **Step 1: Run final local verification and push**

Use `superpowers:verification-before-completion`, then run:

```bash
git diff --check
composer validate --no-check-publish
php -l app/Services/AppDownloadMirror.php
php -l app/Services/AppDownloadMirrorUrlSigner.php
php -l app/Services/AppDownloadMirrorRouter.php
php -l app/Jobs/SyncAppArtifactMirror.php
php -l app/Jobs/DeleteAppArtifactMirror.php
php -l app/Console/Commands/SyncAppDownloadMirrors.php
php -l app/Http/Controllers/V1/Guest/AppDownloadController.php
php -l app/Http/Controllers/V2/Admin/AppPackageController.php
node --test tests/*.test.js
docker compose -f docker-compose.yaml config --quiet
git status --short --branch
```

Expected: every command passes and only intentional commits are ahead of `origin/master`.

Push the current branch:

```bash
git push origin master
```

- [ ] **Step 2: Back up and fast-forward production**

On the Xboard host:

```bash
cd /opt/1panel/apps/openresty/openresty/www/sites/xboard/index
git status --short --branch
git fetch origin master
git merge --ff-only origin/master
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
php artisan config:clear
```

Expected: production has the new migration and dependency without overwriting unrelated local files.

- [ ] **Step 3: Recreate only Web and Horizon**

The Web process needs the new redirect code; Horizon needs the SFTP dependency, isolated supervisor, and key mount:

```bash
docker compose -f docker-compose.yaml up -d --force-recreate web horizon
docker compose -f docker-compose.yaml ps
docker compose -f docker-compose.yaml exec horizon php artisan horizon:status
docker compose -f docker-compose.yaml exec horizon php artisan tinker \
  --execute='$d=Storage::disk("app_download_mirror"); $d->put(".adapter-probe","ok"); dump($d->size(".adapter-probe")); $d->delete(".adapter-probe");'
```

Expected: Web and Horizon are running; Redis is not recreated; Horizon lists `XboardAppDownloadMirror`; the adapter prints `2` and removes its probe.

- [ ] **Step 4: Queue and monitor the three current artifacts**

Run:

```bash
docker compose -f docker-compose.yaml exec web \
  php artisan app-downloads:sync-mirrors
docker compose -f docker-compose.yaml exec web \
  php artisan tinker --execute='App\Models\AppArtifact::query()->select("id","original_name","file_size","sha256","mirror_status","mirror_path","mirror_error")->orderBy("id")->get()->each(fn ($a) => dump($a->toArray()));'
```

Poll without changing state until artifact IDs `36`, `37`, and `50` are all `ready`. If a row is `failed`, inspect its sanitized `mirror_error` and Horizon logs, fix the exact boundary, then use the admin retry action or:

```bash
docker compose -f docker-compose.yaml exec web \
  php artisan app-downloads:sync-mirrors --artifact=36 --artifact=37 --artifact=50
```

- [ ] **Step 5: Verify remote size and SHA-256**

On the download server, calculate hashes only for the three database `mirror_path` values:

```bash
find /srv/xboard-downloads/files/artifacts -type f ! -name '*.part-*' \
  -exec stat -c '%s %n' {} \;
find /srv/xboard-downloads/files/artifacts -type f ! -name '*.part-*' \
  -exec sha256sum {} \;
```

Expected sizes:

```text
65033062  elephant-route-android-release-arm64-v1.6.1.apk
25843947  ElephantRoute-macos-arm64-1.6.2.dmg
72733072  ElephantNetwork-Setup-x64-v1.6.6.exe
```

Compare each SHA-256 to `v2_app_artifacts.sha256`; all three must match before redirects are enabled.

- [ ] **Step 6: Verify signed URL and Range behavior while redirects are off**

Generate a URL without exposing the signing key:

```bash
SIGNED_URL="$(
  docker compose -f docker-compose.yaml exec -T web php artisan tinker \
    --execute='$a=App\Models\AppArtifact::findOrFail(50); echo app(App\Services\AppDownloadMirrorUrlSigner::class)->urlFor($a), PHP_EOL;' \
  | tail -n 1
)"
```

Use the returned URL:

```bash
curl -fsSI "$SIGNED_URL"
curl -fsS -D /tmp/xboard-mirror-range.headers \
  -H 'Range: bytes=0-1048575' \
  -o /tmp/xboard-mirror-range.bin \
  "$SIGNED_URL"
wc -c /tmp/xboard-mirror-range.bin
rg -i 'HTTP/|content-range|accept-ranges|content-length' \
  /tmp/xboard-mirror-range.headers
INVALID_URL="$(printf '%s' "$SIGNED_URL" | sed -E 's/md5=./md5=x/')"
curl -sS -o /dev/null -w 'invalid=%{http_code}\n' "$INVALID_URL"
EXPIRED_URL="$(
  docker compose -f docker-compose.yaml exec -T web php artisan tinker \
    --execute='$a=App\Models\AppArtifact::findOrFail(50); echo app(App\Services\AppDownloadMirrorUrlSigner::class)->url($a->mirror_path, now()->subMinute()), PHP_EOL;' \
  | tail -n 1
)"
curl -sS -o /dev/null -w 'expired=%{http_code}\n' "$EXPIRED_URL"
```

Expected: `HEAD` is 200, range is 206, body is 1,048,576 bytes, and `Content-Range` is `bytes 0-1048575/72733072`. A URL with changed `md5` returns 403; an intentionally expired signer fixture returns 410.

- [ ] **Step 7: Enable redirects and verify logging**

Set only:

```dotenv
APP_DOWNLOAD_MIRROR_ENABLED=true
```

Then:

```bash
docker compose -f docker-compose.yaml exec web php artisan config:clear
docker compose -f docker-compose.yaml restart web
```

Generate the existing signed Laravel route and inspect without downloading the body:

```bash
LARAVEL_SIGNED_URL="$(
  docker compose -f docker-compose.yaml exec -T web php artisan tinker \
    --execute='$a=App\Models\AppArtifact::findOrFail(50); $p=Illuminate\Support\Facades\URL::temporarySignedRoute("app-downloads.download", now()->addMinutes(10), ["artifact"=>$a->id], false); echo rtrim(config("app.url"), "/").$p, PHP_EOL;' \
  | tail -n 1
)"
curl -sS -D /tmp/xboard-download.headers -o /dev/null "$LARAVEL_SIGNED_URL"
rg -i 'HTTP/|location:' /tmp/xboard-download.headers
```

Expected: Xboard returns 302 and `Location` starts with `https://download.elephant111.com/files/`. Confirm `v2_app_download_logs` increments for the artifact before the redirect.

- [ ] **Step 8: Measure throughput and client compatibility**

For artifact IDs `36`, `37`, and `50`, regenerate `LARAVEL_SIGNED_URL` with the command above by changing only the ID, then run:

```bash
curl -L --fail --output /dev/null \
  --write-out 'status=%{http_code} bytes=%{size_download} speed=%{speed_download} time=%{time_total}\n' \
  "$LARAVEL_SIGNED_URL"
```

Measure from at least two independent networks. Confirm Android update, public web download, macOS download, and Windows download follow the same existing Xboard URL and complete successfully.

- [ ] **Step 9: Prove automatic fallback**

First disable only the redirect flag:

```dotenv
APP_DOWNLOAD_MIRROR_ENABLED=false
```

Clear config/restart Web and request a fresh Laravel signed URL. Expected: HTTP 200 from `www.elephant111.com`, no `Location`, and the local package downloads.

Re-enable the flag, stop Nginx for one cached-health interval, and request a fresh URL:

```bash
systemctl stop nginx
```

Expected after 45–60 seconds: Xboard returns local HTTP 200. Immediately restore:

```bash
systemctl start nginx
```

Confirm mirror redirects resume only after a successful health probe.

- [ ] **Step 10: Record the production evidence**

Record without secrets:

- deployed Git commit;
- migration status;
- three artifact IDs, names, sizes, hashes, and `ready` states;
- valid/invalid/expired signature status;
- `HEAD` and `Range` response headers;
- pre/post throughput from two networks;
- download-log increment;
- disabled-mirror and stopped-Nginx fallback results;
- `nginx -t`, certificate dates, Horizon status, disk free space.

Do not record passwords, private keys, signing keys, signed URLs, or `.env` contents.
