const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('admin link form removes file upload and filename inference', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.doesNotMatch(page, /type="file"/);
  assert.doesNotMatch(page, /autofillPackageFromArtifact|detectPlatform|inferVersion|inferAppName/);
  assert.match(page, /name="download_url" type="url" required/);
  assert.match(page, /name="file_size_mb" type="number"/);
  assert.match(page, /name="sha256"/);
});

test('admin publish form defaults to third-party and locks official identity', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /<select name="distribution_scope" required>/);
  assert.match(page, /<option value="download_only" selected>/);
  assert.match(page, /<option value="official_update">/);
  assert.match(page, /distribution_scope:\s*distributionScope/);
  assert.match(page, /appKeyInput\.readOnly\s*=\s*isOfficial/);
  assert.match(page, /appNameInput\.readOnly\s*=\s*isOfficial/);
  assert.match(page, /appNameInput\.value\s*=\s*officialAppNameForPlatform\(platformInput\.value\)/);
  assert.match(page, /android:\s*"elephant-route-android"/);
  assert.match(page, /windows:\s*"elephant-route-desktop"/);
  assert.match(page, /macos:\s*"elephant-route-desktop"/);
});

test('admin publish form resynchronizes identity on scope, platform and reset', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /function syncAppIdentityControls\(\)/);
  assert.match(page, /\[name="distribution_scope"\][\s\S]*addEventListener\("change", syncAppIdentityControls\)/);
  assert.match(page, /\[name="platform"\][\s\S]*addEventListener\("change", syncAppIdentityControls\)/);
  assert.match(page, /resetPackageButton\.addEventListener\("click"[\s\S]*syncAppIdentityControls\(\)/);
});

test('admin publish form only reuses applications from the selected scope', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /findExistingAppByKey\(appKey,\s*distributionScope\)/);
  assert.match(page, /distributionScope === "download_only"\s*\?\s*findExistingAppByName\(appName,\s*distributionScope\)/);
});

test('admin app download publish form exposes app identity and version fields', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /<label>应用名称<input name="app_name"/);
  assert.match(page, /<label>应用标识<input name="app_key"/);
  assert.match(page, /Windows\/macOS 共用 elephant-route-desktop/);
  assert.match(page, /platform: packageForm\.querySelector\('\[name="platform"\]'\)\.value/);
  assert.match(page, /<label>版本号<input name="version"/);
  assert.doesNotMatch(page, /<input type="hidden" name="app_key"/);
  assert.doesNotMatch(page, /<input type="hidden" name="version"/);
});

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

test('admin app package save uses app key as primary software identity', () => {
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/AppPackageController.php');

  assert.match(controller, /if \(\$existingByKey\) \{[\s\S]*\$data\['id'\]\s*=\s*\$existingByKey->id;[\s\S]*\}/);
  assert.match(controller, /DistributionApp::officialAppKeyForPlatform\(\$platform\)/);
  assert.match(controller, /DistributionApp::officialAppNameForPlatform\(\$platform\)/);
  assert.match(controller, /\$data\['name'\]\s*=\s*\$officialAppName/);
  assert.match(controller, /\$data\['distribution_scope'\]\s*===\s*DistributionApp::SCOPE_OFFICIAL_UPDATE/);
  assert.match(controller, /unset\(\$data\['id'\]\)/);
  assert.match(controller, /if \(empty\(\$data\['id'\]\) && empty\(\$data\['app_key'\]\)\)/);
  assert.doesNotMatch(controller, /应用标识已存在，请更换应用名称或标识/);
});
