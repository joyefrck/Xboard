# Third-Party App Download Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow administrators to publish third-party application packages for public download while making it impossible for those packages to enter Elephant's official automatic update feeds.

**Architecture:** Add an application-level `distribution_scope` with a safe `download_only` default and centralize reserved official identity rules on `DistributionApp`. Enforce the boundary in both admin writes and the guest update query, while leaving the public download catalog and artifact lifecycle unchanged.

**Tech Stack:** Laravel/PHP, Blade with vanilla JavaScript, Laravel migrations/Eloquent, Node.js `node:test` source-contract regression tests.

---

## File Map

- Create `database/migrations/2026_07_18_000001_add_distribution_scope_to_distribution_apps.php`: add and seed the distribution scope safely.
- Modify `app/Models/DistributionApp.php`: define scope constants and the official platform/key map.
- Modify `app/Http/Controllers/V1/Guest/AppUpdateController.php`: exclude download-only apps from all update queries.
- Modify `app/Http/Controllers/V2/Admin/AppPackageController.php`: validate scope, reserved identities, immutable populated-app scope, and official platform/key combinations.
- Modify `resources/views/admin_app_downloads.blade.php`: add the application-type control and synchronize inferred identity behavior.
- Create `tests/app-download-distribution-scope.test.js`: cover migration, model policy, backend validation, update isolation, and unchanged public catalog behavior.
- Modify `tests/admin-app-downloads-autofill.test.js`: cover explicit third-party/official form behavior.

### Task 1: Add the Distribution Scope Model and Migration

**Files:**
- Create: `database/migrations/2026_07_18_000001_add_distribution_scope_to_distribution_apps.php`
- Modify: `app/Models/DistributionApp.php`
- Create: `tests/app-download-distribution-scope.test.js`

- [ ] **Step 1: Write the failing migration and model contract tests**

Create `tests/app-download-distribution-scope.test.js` with the shared reader and these initial tests:

```js
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('distribution apps default to download-only and seed only official identities', () => {
  const migration = readRepoFile(
    'database/migrations/2026_07_18_000001_add_distribution_scope_to_distribution_apps.php'
  );

  assert.match(migration, /string\('distribution_scope',\s*32\)/);
  assert.match(migration, /default\('download_only'\)/);
  assert.match(migration, /whereIn\('app_key',\s*\[/);
  assert.match(migration, /'elephant-route-android'/);
  assert.match(migration, /'elephant-route-desktop'/);
  assert.match(migration, /'elephant-route-mac'/);
  assert.match(migration, /'distribution_scope'\s*=>\s*'official_update'/);
});

test('distribution app model owns scope and reserved identity policy', () => {
  const model = readRepoFile('app/Models/DistributionApp.php');

  assert.match(model, /SCOPE_DOWNLOAD_ONLY\s*=\s*'download_only'/);
  assert.match(model, /SCOPE_OFFICIAL_UPDATE\s*=\s*'official_update'/);
  assert.match(model, /'android'\s*=>\s*'elephant-route-android'/);
  assert.match(model, /'windows'\s*=>\s*'elephant-route-desktop'/);
  assert.match(model, /'macos'\s*=>\s*'elephant-route-mac'/);
  assert.match(model, /public static function officialAppKeyForPlatform/);
  assert.match(model, /public static function isReservedAppKey/);
  assert.match(model, /'distribution_scope'/);
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
node --test tests/app-download-distribution-scope.test.js
```

Expected: FAIL because the migration does not exist and the model lacks scope constants and policy helpers.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_18_000001_add_distribution_scope_to_distribution_apps.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_distribution_apps', 'distribution_scope')) {
            Schema::table('v2_distribution_apps', function (Blueprint $table) {
                $table->string('distribution_scope', 32)
                    ->default('download_only')
                    ->after('description')
                    ->index();
            });
        }

        DB::table('v2_distribution_apps')
            ->whereIn('app_key', [
                'elephant-route-android',
                'elephant-route-desktop',
                'elephant-route-mac',
            ])
            ->update(['distribution_scope' => 'official_update']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_distribution_apps', 'distribution_scope')) {
            Schema::table('v2_distribution_apps', function (Blueprint $table) {
                $table->dropIndex(['distribution_scope']);
                $table->dropColumn('distribution_scope');
            });
        }
    }
};
```

- [ ] **Step 4: Add scope and official identity policy to the model**

Add the following to `DistributionApp` and include `distribution_scope` in `$fillable`:

```php
public const SCOPE_DOWNLOAD_ONLY = 'download_only';
public const SCOPE_OFFICIAL_UPDATE = 'official_update';

private const OFFICIAL_APP_KEYS_BY_PLATFORM = [
    'android' => 'elephant-route-android',
    'windows' => 'elephant-route-desktop',
    'macos' => 'elephant-route-mac',
];

public static function scopes(): array
{
    return [self::SCOPE_DOWNLOAD_ONLY, self::SCOPE_OFFICIAL_UPDATE];
}

public static function officialAppKeyForPlatform(string $platform): ?string
{
    return self::OFFICIAL_APP_KEYS_BY_PLATFORM[strtolower($platform)] ?? null;
}

public static function isReservedAppKey(string $appKey): bool
{
    return in_array(strtolower(trim($appKey)), self::OFFICIAL_APP_KEYS_BY_PLATFORM, true);
}

public function isOfficialUpdate(): bool
{
    return $this->distribution_scope === self::SCOPE_OFFICIAL_UPDATE;
}
```

- [ ] **Step 5: Run the focused test and PHP syntax checks**

Run:

```bash
node --test tests/app-download-distribution-scope.test.js
php -l app/Models/DistributionApp.php
php -l database/migrations/2026_07_18_000001_add_distribution_scope_to_distribution_apps.php
```

Expected: the two Node tests pass and both PHP files report `No syntax errors detected`.

- [ ] **Step 6: Commit the model and migration**

```bash
git add database/migrations/2026_07_18_000001_add_distribution_scope_to_distribution_apps.php \
  app/Models/DistributionApp.php \
  tests/app-download-distribution-scope.test.js
git commit -m "feat: add app distribution scopes"
```

### Task 2: Enforce Download-Only Isolation in Update Checks

**Files:**
- Modify: `app/Http/Controllers/V1/Guest/AppUpdateController.php`
- Modify: `tests/app-download-distribution-scope.test.js`

- [ ] **Step 1: Add the failing update isolation contract test**

Append:

```js
test('guest update checks only resolve official-update applications', () => {
  const controller = readRepoFile(
    'app/Http/Controllers/V1/Guest/AppUpdateController.php'
  );

  assert.match(
    controller,
    /where\('distribution_scope',\s*DistributionApp::SCOPE_OFFICIAL_UPDATE\)/
  );
  assert.match(
    controller,
    /whereHas\('app',[\s\S]*distribution_scope[\s\S]*SCOPE_OFFICIAL_UPDATE/
  );
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
node --test tests/app-download-distribution-scope.test.js
```

Expected: FAIL because `AppUpdateController` only filters by `app_key` and `is_active`.

- [ ] **Step 3: Filter both keyed and fallback update queries**

Change the keyed application lookup to:

```php
$app = DistributionApp::where('app_key', $appKey)
    ->where('distribution_scope', DistributionApp::SCOPE_OFFICIAL_UPDATE)
    ->where('is_active', true)
    ->first();
```

Change the active-app relation filter to:

```php
->whereHas('app', function ($query) {
    $query->where('distribution_scope', DistributionApp::SCOPE_OFFICIAL_UPDATE)
        ->where('is_active', true);
})
```

This covers normal client requests with `app_key` and legacy requests that omit it.

- [ ] **Step 4: Run focused update tests**

Run:

```bash
node --test tests/app-download-distribution-scope.test.js tests/android-app-update.test.js
php -l app/Http/Controllers/V1/Guest/AppUpdateController.php
```

Expected: all Node tests pass and PHP syntax is valid.

- [ ] **Step 5: Commit update isolation**

```bash
git add app/Http/Controllers/V1/Guest/AppUpdateController.php \
  tests/app-download-distribution-scope.test.js
git commit -m "fix: isolate official app update feeds"
```

### Task 3: Enforce Admin-Side Scope and Identity Rules

**Files:**
- Modify: `app/Http/Controllers/V2/Admin/AppPackageController.php`
- Modify: `tests/app-download-distribution-scope.test.js`

Applications with existing versions must keep their current distribution scope; the admin upload flow cannot promote or demote their complete release history.

- [ ] **Step 1: Add failing backend validation contract tests**

Append:

```js
test('admin app saves validate distribution scope and reserved keys', () => {
  const controller = readRepoFile(
    'app/Http/Controllers/V2/Admin/AppPackageController.php'
  );

  assert.match(controller, /'distribution_scope'\s*=>\s*\[/);
  assert.match(controller, /Rule::in\(DistributionApp::scopes\(\)\)/);
  assert.match(controller, /DistributionApp::SCOPE_DOWNLOAD_ONLY/);
  assert.match(controller, /DistributionApp::isReservedAppKey/);
  assert.match(controller, /第三方应用不能使用大象官方保留标识/);
  assert.match(controller, /已有版本的应用不能修改应用类型/);
});

test('official versions require the platform reserved app key', () => {
  const controller = readRepoFile(
    'app/Http/Controllers/V2/Admin/AppPackageController.php'
  );

  assert.match(controller, /officialAppKeyForPlatform\(\$data\['platform'\]\)/);
  assert.match(controller, /官方应用标识与发布平台不匹配/);
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run:

```bash
node --test tests/app-download-distribution-scope.test.js
```

Expected: FAIL because the admin controller accepts neither scope nor reserved identity rules.

- [ ] **Step 3: Validate and normalize application scope in `saveApp`**

Add this validation field:

```php
'distribution_scope' => [
    'nullable',
    'string',
    Rule::in(DistributionApp::scopes()),
],
```

Normalize absent values safely:

```php
$data['distribution_scope'] = $data['distribution_scope']
    ?? DistributionApp::SCOPE_DOWNLOAD_ONLY;
```

After finalizing or generating `app_key`, enforce:

```php
$isReservedKey = DistributionApp::isReservedAppKey($data['app_key']);
if ($data['distribution_scope'] === DistributionApp::SCOPE_DOWNLOAD_ONLY && $isReservedKey) {
    return $this->fail([400, '第三方应用不能使用大象官方保留标识']);
}
if ($data['distribution_scope'] === DistributionApp::SCOPE_OFFICIAL_UPDATE && !$isReservedKey) {
    return $this->fail([400, '大象官方应用必须使用平台对应的官方标识']);
}
```

Before updating an existing application, protect populated histories:

```php
$existingApp = empty($data['id']) ? null : DistributionApp::findOrFail($data['id']);
if ($existingApp
    && $existingApp->distribution_scope !== $data['distribution_scope']
    && $existingApp->versions()->exists()) {
    return $this->fail([400, '已有版本的应用不能修改应用类型']);
}
```

Reuse `$existingApp` for the update branch so the same record is not loaded twice.

- [ ] **Step 4: Validate official scope against version platform in `saveVersion`**

After normalizing `platform`, load the application and enforce:

```php
$app = DistributionApp::findOrFail($data['app_id']);
if ($app->isOfficialUpdate()) {
    $officialAppKey = DistributionApp::officialAppKeyForPlatform($data['platform']);
    if (!$officialAppKey || $app->app_key !== $officialAppKey) {
        return $this->fail([400, '官方应用标识与发布平台不匹配']);
    }
} elseif (DistributionApp::isReservedAppKey($app->app_key)) {
    return $this->fail([400, '第三方应用不能使用大象官方保留标识']);
}
```

- [ ] **Step 5: Run focused controller tests and syntax checks**

Run:

```bash
node --test tests/app-download-distribution-scope.test.js tests/admin-app-downloads-autofill.test.js
php -l app/Http/Controllers/V2/Admin/AppPackageController.php
```

Expected: all tests pass and PHP syntax is valid.

- [ ] **Step 6: Commit backend publishing policy**

```bash
git add app/Http/Controllers/V2/Admin/AppPackageController.php \
  tests/app-download-distribution-scope.test.js
git commit -m "feat: validate app publishing scope"
```

### Task 4: Add Explicit Official and Third-Party Admin Controls

**Files:**
- Modify: `resources/views/admin_app_downloads.blade.php`
- Modify: `tests/admin-app-downloads-autofill.test.js`

- [ ] **Step 1: Add failing admin UI behavior tests**

Extend `loadAdminAppDownloadHelpers()` to extract `officialAppKeyForPlatform`, then replace the old official-Android inference test with:

```js
test('admin app identity separates third-party and official package keys', () => {
  const {
    detectPlatform,
    inferAppKey,
    inferAppName,
    officialAppKeyForPlatform,
  } = loadAdminAppDownloadHelpers();
  const filename = 'elephant-route-android-release-arm64-v1.1.apk';
  const platform = detectPlatform(filename);
  const appName = inferAppName(filename);

  assert.equal(inferAppKey(filename, appName, platform, 'download_only'), 'elephant-route');
  assert.equal(
    inferAppKey(filename, appName, platform, 'official_update'),
    'elephant-route-android'
  );
  assert.equal(officialAppKeyForPlatform('windows'), 'elephant-route-desktop');
  assert.equal(officialAppKeyForPlatform('macos'), 'elephant-route-mac');
});

test('admin publish form defaults to third-party and submits distribution scope', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /<select name="distribution_scope"/);
  assert.match(page, /<option value="download_only" selected>/);
  assert.match(page, /<option value="official_update">/);
  assert.match(page, /distribution_scope:\s*distributionScope/);
  assert.match(page, /appKeyInput\.readOnly\s*=\s*isOfficial/);
});
```

- [ ] **Step 2: Run the admin-page test and verify it fails**

Run:

```bash
node --test tests/admin-app-downloads-autofill.test.js
```

Expected: FAIL because the page has no scope selector or scope-aware identity helpers.

- [ ] **Step 3: Add the application-type field and identity help text**

Add before the application name field:

```html
<label>应用类型
  <select name="distribution_scope" required>
    <option value="download_only" selected>第三方 App（仅供下载）</option>
    <option value="official_update">大象官方 App（支持自动更新）</option>
  </select>
</label>
```

Change the identity hint to explain that third-party keys are internal grouping identifiers and cannot use official reserved values.

- [ ] **Step 4: Make identity inference scope-aware**

Add:

```js
function officialAppKeyForPlatform(platform) {
  return {
    android: 'elephant-route-android',
    windows: 'elephant-route-desktop',
    macos: 'elephant-route-mac'
  }[String(platform || '').toLowerCase()] || '';
}
```

Change `inferAppKey` to:

```js
function inferAppKey(filename, appName, platform, distributionScope) {
  if (distributionScope === 'official_update') {
    return officialAppKeyForPlatform(platform);
  }
  return slugifyAppKey(appName);
}
```

Add `syncAppIdentityControls()` that reads the current scope and platform, sets the reserved key for official applications, makes the key read-only for official applications, and restores a non-reserved slug for third-party applications:

```js
function syncAppIdentityControls() {
  var scopeInput = packageForm.querySelector('[name="distribution_scope"]');
  var platformInput = packageForm.querySelector('[name="platform"]');
  var appNameInput = packageForm.querySelector('[name="app_name"]');
  var appKeyInput = packageForm.querySelector('[name="app_key"]');
  var isOfficial = scopeInput.value === 'official_update';

  appKeyInput.readOnly = isOfficial;
  if (isOfficial) {
    appKeyInput.value = officialAppKeyForPlatform(platformInput.value);
    return;
  }

  if (!appKeyInput.value || officialAppKeyForPlatform(platformInput.value) === appKeyInput.value) {
    appKeyInput.value = slugifyAppKey(appNameInput.value);
  }
}
```

Call it after artifact autofill and on scope/platform changes. Pass the current scope into `inferAppKey` during autofill.

- [ ] **Step 5: Include scope in the application save request**

Add:

```js
var distributionScope = packageForm
  .querySelector('[name="distribution_scope"]')
  .value;
```

Then include it in `appPayload`:

```js
var appPayload = {
  name: appName,
  app_key: appKey,
  distribution_scope: distributionScope,
  description: packageForm.querySelector('[name="release_notes"]').value || '',
  is_active: 1
};
```

- [ ] **Step 6: Run the focused UI tests**

Run:

```bash
node --test tests/admin-app-downloads-autofill.test.js tests/app-download-distribution-scope.test.js
```

Expected: all tests pass, including third-party default and official identity locking.

- [ ] **Step 7: Commit the admin UI**

```bash
git add resources/views/admin_app_downloads.blade.php \
  tests/admin-app-downloads-autofill.test.js
git commit -m "feat: distinguish official and third-party packages"
```

### Task 5: Protect Public Catalog Compatibility and Run Full Verification

**Files:**
- Modify: `tests/app-download-distribution-scope.test.js`
- Verify: `app/Http/Controllers/V1/Guest/AppDownloadController.php`

The public catalog contract, artifact download path, and download logs remain unchanged for both scopes.

- [ ] **Step 1: Add the public catalog compatibility test**

Append:

```js
test('public downloads retain both distribution scopes', () => {
  const controller = readRepoFile(
    'app/Http/Controllers/V1/Guest/AppDownloadController.php'
  );

  assert.match(controller, /where\('is_enabled',\s*true\)/);
  assert.match(controller, /whereHas\('app',[\s\S]*is_active/);
  assert.doesNotMatch(controller, /distribution_scope/);
});
```

- [ ] **Step 2: Run the focused compatibility test**

Run:

```bash
node --test tests/app-download-distribution-scope.test.js
```

Expected: PASS without modifying `AppDownloadController`.

- [ ] **Step 3: Run all relevant PHP syntax checks**

Run:

```bash
php -l app/Models/DistributionApp.php
php -l app/Http/Controllers/V1/Guest/AppUpdateController.php
php -l app/Http/Controllers/V2/Admin/AppPackageController.php
php -l database/migrations/2026_07_18_000001_add_distribution_scope_to_distribution_apps.php
```

Expected: every command reports `No syntax errors detected`.

- [ ] **Step 4: Run the complete repository Node test suite**

Run:

```bash
node --test tests/*.test.js
```

Expected: exit code 0 with zero failed tests.

- [ ] **Step 5: Review the final diff against the approved design**

Run:

```bash
git diff --check
git status --short
git diff --stat HEAD~4..HEAD
```

Expected: no whitespace errors; only the migration, model, two controllers, admin Blade page, and two test files are changed by the implementation commits.

- [ ] **Step 6: Commit the compatibility test if it was not included earlier**

```bash
git add tests/app-download-distribution-scope.test.js
git commit -m "test: protect download catalog compatibility"
```

Skip this commit only if the exact public-catalog test is already included in a prior implementation commit and the working tree is clean.

## Manual Acceptance Check

After deployment to a test environment with migrations applied:

1. Upload `Clash-Verge-Windows-2.5.1.exe` using the default third-party type and publish it.
2. Confirm it appears in the Windows public download catalog and can be downloaded.
3. Request `/api/v1/app/update` with `app_key=elephant-route-desktop`, `platform=windows`, `channel=stable`, `version=1.6.3`, and `arch=x64`.
4. Confirm the response never identifies the Clash Verge application or artifact.
5. Try saving a third-party app with `app_key=elephant-route-desktop`; confirm the admin API returns `第三方应用不能使用大象官方保留标识`.
6. Disable the third-party version and confirm it disappears from the public catalog without affecting the official Windows update result.
