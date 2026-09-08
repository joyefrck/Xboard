# Official App Version Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent official Elephant App releases from advertising date-based metadata and make update eligibility use build number when semantic versions are equal.

**Architecture:** Keep download-only catalog defaults in the Blade form, but make official release metadata explicit and validated at `AppPackageController::saveVersion`. Keep semantic version precedence in `AppUpdateController`, adding build comparison only for equal or unparseable versions.

**Tech Stack:** Laravel 12/PHP 8.2, Blade/vanilla JavaScript, Node.js built-in test runner

---

### Task 1: Define failing admin release-contract tests

**Files:**
- Modify: `tests/admin-app-downloads-autofill.test.js`
- Modify: `tests/app-download-external-links.test.js`

- [ ] **Step 1: Add the failing form contract test**

Add assertions requiring a visible numeric build field, generated-default tracking, and scope-aware metadata synchronization:

```js
test('official app publishing requires explicit package version metadata', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /<label>构建号<input name="build_number" type="number"/);
  assert.doesNotMatch(page, /<input type="hidden" name="build_number">/);
  assert.match(page, /function syncReleaseMetadataControls\(\)/);
  assert.match(page, /scopeInput\.value === "official_update"/);
  assert.match(page, /versionInput\.dataset\.generatedDefault/);
  assert.match(page, /buildInput\.dataset\.generatedDefault/);
  assert.match(page, /版本号和构建号必须与安装包一致/);
});
```

- [ ] **Step 2: Add the failing server validation contract test**

Add assertions around the official-only validation call and its operator-facing error:

```js
test('official app versions reject calendar metadata at the server boundary', () => {
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/AppPackageController.php');

  assert.match(controller, /assertOfficialVersionMetadata\(\$data,\s*\$app\)/);
  assert.match(controller, /private function assertOfficialVersionMetadata\(/);
  assert.match(controller, /\^v\?\\d\+\\\.\\d\+\\\.\\d\+/);
  assert.match(controller, /\^v\?20\\d\{2\}\\\.\\d\{2\}\\\.\\d\{2\}/);
  assert.match(controller, /官方 App 版本号必须与安装包一致/);
});
```

- [ ] **Step 3: Run the focused tests and confirm failure**

Run:

```bash
node --test tests/admin-app-downloads-autofill.test.js tests/app-download-external-links.test.js
```

Expected: the new assertions fail because build number is hidden, no scope-aware metadata helper exists, and official metadata is not validated.

### Task 2: Make official release metadata explicit and validated

**Files:**
- Modify: `resources/views/admin_app_downloads.blade.php`
- Modify: `app/Http/Controllers/V2/Admin/AppPackageController.php`
- Test: `tests/admin-app-downloads-autofill.test.js`
- Test: `tests/app-download-external-links.test.js`

- [ ] **Step 1: Expose build number and operator guidance**

Replace the hidden build field with:

```html
<label>构建号<input name="build_number" type="number" min="1" step="1" required placeholder="例如 20006"></label>
<p class="muted" id="release-metadata-help">第三方 App 可使用系统生成的目录版本；大象官方 App 的版本号和构建号必须与安装包一致。</p>
```

- [ ] **Step 2: Track generated defaults without overwriting manual values**

Add form helpers that mark only system-generated values and clear them when the operator switches to `official_update`:

```js
function applyGeneratedReleaseMetadata() {
  var versionInput = packageForm.querySelector('[name="version"]');
  var buildInput = packageForm.querySelector('[name="build_number"]');
  versionInput.value = defaultVersion();
  buildInput.value = Math.floor(Date.now() / 1000);
  versionInput.dataset.generatedDefault = "1";
  buildInput.dataset.generatedDefault = "1";
}

function syncReleaseMetadataControls() {
  var scopeInput = packageForm.querySelector('[name="distribution_scope"]');
  var versionInput = packageForm.querySelector('[name="version"]');
  var buildInput = packageForm.querySelector('[name="build_number"]');
  var isOfficial = scopeInput.value === "official_update";

  if (isOfficial) {
    if (versionInput.dataset.generatedDefault === "1") versionInput.value = "";
    if (buildInput.dataset.generatedDefault === "1") buildInput.value = "";
    versionInput.dataset.generatedDefault = "0";
    buildInput.dataset.generatedDefault = "0";
  } else if (!versionInput.value && !buildInput.value) {
    applyGeneratedReleaseMetadata();
  }
}
```

Register `input` listeners that set the corresponding `generatedDefault` flag to `"0"`, invoke the helper from scope changes and resets, and keep the existing identity synchronization.

- [ ] **Step 3: Validate official semantic metadata in the controller**

Call a new helper inside the existing `try` block before normalizing the external URL:

```php
$this->assertOfficialVersionMetadata($data, $app);
$data = $this->normalizeExternalVersionData($data, $app, $data['platform']);
```

Implement the helper with official-only checks:

```php
private function assertOfficialVersionMetadata(array $data, DistributionApp $app): void
{
    if (!$app->isOfficialUpdate()) {
        return;
    }

    $version = trim((string) ($data['version'] ?? ''));
    $isSemantic = preg_match('/^v?\d+\.\d+\.\d+(?:[+-][a-z0-9][a-z0-9._-]*)?$/i', $version) === 1;
    $isCalendarVersion = preg_match('/^v?20\d{2}\.\d{2}\.\d{2}(?:[+-]|$)/i', $version) === 1;

    if (!$isSemantic || $isCalendarVersion) {
        throw new InvalidArgumentException('官方 App 版本号必须与安装包一致，例如 2.0.6，不能使用发布日期');
    }
}
```

The existing positive-integer Laravel rule continues to validate `build_number`; removing the form-generated timestamp ensures it is operator supplied for official Apps.

- [ ] **Step 4: Run the focused tests**

Run:

```bash
node --test tests/admin-app-downloads-autofill.test.js tests/app-download-external-links.test.js
```

Expected: all focused tests pass.

- [ ] **Step 5: Commit the admin contract change**

```bash
git add resources/views/admin_app_downloads.blade.php app/Http/Controllers/V2/Admin/AppPackageController.php tests/admin-app-downloads-autofill.test.js tests/app-download-external-links.test.js
git commit -m "fix: validate official app release versions"
```

### Task 3: Correct equal-version update eligibility

**Files:**
- Modify: `tests/android-app-update.test.js`
- Modify: `app/Http/Controllers/V1/Guest/AppUpdateController.php`

- [ ] **Step 1: Replace the old static assertion with the desired decision table**

Require explicit positive, equal, negative, and unparseable branches:

```js
test('guest app update endpoint uses build number only for equal or invalid versions', () => {
  const controller = readRepoFile('app/Http/Controllers/V1/Guest/AppUpdateController.php');

  assert.match(controller, /if \(\$versionComparison === null \|\| \$versionComparison === 0\)/);
  assert.match(controller, /\$hasUpdate = \$latest->build_number > \$currentBuild/);
  assert.match(controller, /else \{\s*\$hasUpdate = \$versionComparison > 0;/);
});
```

- [ ] **Step 2: Run the focused test and confirm failure**

Run:

```bash
node --test tests/android-app-update.test.js
```

Expected: the new update-decision assertion fails against the ternary that ignores build number for equal semantic versions.

- [ ] **Step 3: Implement the decision table**

Replace the current ternary with:

```php
$versionComparison = $this->compareVersions($latest->version, $currentVersion);
if ($versionComparison === null || $versionComparison === 0) {
    $hasUpdate = $latest->build_number > $currentBuild;
} else {
    $hasUpdate = $versionComparison > 0;
}
```

- [ ] **Step 4: Run the focused test**

Run:

```bash
node --test tests/android-app-update.test.js
```

Expected: all tests in the file pass.

- [ ] **Step 5: Commit the update-comparison change**

```bash
git add app/Http/Controllers/V1/Guest/AppUpdateController.php tests/android-app-update.test.js
git commit -m "fix: compare builds for equal app versions"
```

### Task 4: Verify the complete backend change

**Files:**
- Verify: all modified files

- [ ] **Step 1: Run PHP syntax checks**

```bash
php -l app/Http/Controllers/V1/Guest/AppUpdateController.php
php -l app/Http/Controllers/V2/Admin/AppPackageController.php
```

Expected: both files report `No syntax errors detected`.

- [ ] **Step 2: Run the focused App distribution suite**

```bash
node --test tests/android-app-update.test.js tests/admin-app-downloads-autofill.test.js tests/app-download-distribution-scope.test.js tests/app-download-external-links.test.js tests/admin-app-download-edit.test.js
```

Expected: zero failed tests.

- [ ] **Step 3: Run the full repository test suite**

```bash
node --test tests/*.test.js
```

Expected: zero failed tests.

- [ ] **Step 4: Review the final diff and worktree scope**

```bash
git diff --check HEAD~2..HEAD
git status --short
git log -3 --oneline
```

Expected: no whitespace errors; only the known unrelated `storage/app/public/knowledge-images/2026/09/` directory remains untracked; commits contain only the design, plan, controllers, Blade form, and focused tests.

- [ ] **Step 5: Record the production follow-up without performing it**

Report that source verification is complete but production remains unchanged. The production Windows row `2026.09.08`/`1788801302` must be disabled and replaced with the actual `2.0.6` version/build before the already-released client stops prompting.
