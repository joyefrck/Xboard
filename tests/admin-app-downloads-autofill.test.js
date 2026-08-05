const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function extractFunction(source, name) {
  const signature = `function ${name}(`;
  const start = source.indexOf(signature);
  assert.notEqual(start, -1, `${name} should exist`);

  const bodyStart = source.indexOf('{', start);
  assert.notEqual(bodyStart, -1, `${name} should have a body`);

  let depth = 0;
  for (let index = bodyStart; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') depth -= 1;
    if (depth === 0) {
      return source.slice(start, index + 1);
    }
  }

  throw new Error(`${name} body was not closed`);
}

function loadAdminAppDownloadHelpers() {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');
  const context = {};

  vm.createContext(context);
  vm.runInContext([
    extractFunction(page, 'stripKnownExtension'),
    extractFunction(page, 'detectPlatform'),
    extractFunction(page, 'inferVersion'),
    extractFunction(page, 'slugifyAppKey'),
    extractFunction(page, 'officialAppKeyForPlatform'),
    extractFunction(page, 'officialAppNameForPlatform'),
    extractFunction(page, 'inferAppKey'),
    extractFunction(page, 'titleCase'),
    extractFunction(page, 'inferAppName'),
    extractFunction(page, 'hasLegacyDecimalSpacing'),
    extractFunction(page, 'displayAppName'),
    'this.detectPlatform = detectPlatform;'
      + 'this.inferVersion = inferVersion;'
      + 'this.officialAppKeyForPlatform = officialAppKeyForPlatform;'
      + 'this.officialAppNameForPlatform = officialAppNameForPlatform;'
      + 'this.inferAppKey = inferAppKey;'
      + 'this.inferAppName = inferAppName;'
      + 'this.hasLegacyDecimalSpacing = hasLegacyDecimalSpacing;'
      + 'this.displayAppName = displayAppName;'
  ].join('\n'), context);

  return context;
}

test('admin app download autofill splits dotted app version labels from names', () => {
  const { inferAppName, inferVersion } = loadAdminAppDownloadHelpers();

  assert.equal(
    inferAppName('大象网络官方App Prd V1.0.apk'),
    '大象网络官方App Prd'
  );
  assert.equal(
    inferVersion('大象网络官方App Prd V1.0.apk'),
    '1.0'
  );
});

test('admin app download autofill still removes ordinary dotted package versions', () => {
  const { inferAppName } = loadAdminAppDownloadHelpers();

  assert.equal(
    inferAppName('Clash-Verge-2.5.1-x64.dmg'),
    'Clash Verge'
  );
});

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

  assert.equal(platform, 'android');
  assert.equal(appName, 'Elephant Route');
  assert.equal(inferAppKey(filename, appName, platform, 'download_only'), 'elephant-route');
  assert.equal(
    inferAppKey(filename, appName, platform, 'official_update'),
    'elephant-route-android'
  );
  assert.equal(officialAppKeyForPlatform('windows'), 'elephant-route-desktop');
  assert.equal(officialAppKeyForPlatform('macos'), 'elephant-route-desktop');
});

test('admin app identity keeps official application names stable by platform', () => {
  const { officialAppNameForPlatform } = loadAdminAppDownloadHelpers();

  assert.equal(officialAppNameForPlatform('android'), '大象网络官方App安卓版');
  assert.equal(officialAppNameForPlatform('windows'), '大象网络官方App桌面版');
  assert.equal(officialAppNameForPlatform('macos'), '大象网络官方App桌面版');
  assert.equal(officialAppNameForPlatform('ios'), '');
});

test('admin publish form defaults to third-party and submits distribution scope', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /<select name="distribution_scope" required>/);
  assert.match(page, /<option value="download_only" selected>/);
  assert.match(page, /<option value="official_update">/);
  assert.match(page, /distribution_scope:\s*distributionScope/);
  assert.match(page, /appKeyInput\.readOnly\s*=\s*isOfficial/);
  assert.match(page, /appNameInput\.readOnly\s*=\s*isOfficial/);
  assert.match(page, /appNameInput\.value\s*=\s*officialAppNameForPlatform\(platformInput\.value\)/);
});

test('admin publish form resynchronizes identity on scope, platform, upload and reset', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /function syncAppIdentityControls\(\)/);
  assert.match(page, /\[name="distribution_scope"\][\s\S]*addEventListener\("change", syncAppIdentityControls\)/);
  assert.match(page, /\[name="platform"\][\s\S]*addEventListener\("change", syncAppIdentityControls\)/);
  assert.match(page, /function autofillPackageFromArtifact\(\)[\s\S]*syncAppIdentityControls\(\)/);
  assert.match(page, /resetPackageButton\.addEventListener\("click"[\s\S]*syncAppIdentityControls\(\)/);
});

test('admin publish form only reuses applications from the selected scope', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /findExistingAppByKey\(inferredAppKey,\s*distributionScope\)\s*\|\|\s*findExistingAppByGuess/);
  assert.match(page, /findExistingAppByKey\(appKey,\s*distributionScope\)/);
  assert.match(page, /distributionScope === "download_only"\s*\?\s*findExistingAppByName\(appName,\s*distributionScope\)/);
});

test('admin app download page keeps stored app names when the version is split separately', () => {
  const { hasLegacyDecimalSpacing, displayAppName } = loadAdminAppDownloadHelpers();

  assert.equal(
    hasLegacyDecimalSpacing('大象网络官方App Prd V1 0', '大象网络官方App Prd'),
    false
  );
  assert.equal(
    displayAppName({
      app: { name: '大象网络官方App Prd V1 0' },
      artifact: { original_name: '大象网络官方App Prd V1.0.apk' }
    }),
    '大象网络官方App Prd V1 0'
  );
});

test('admin app download publish form exposes app identity and version fields', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /<label>应用名称<input name="app_name"/);
  assert.match(page, /<label>应用标识<input name="app_key"/);
  assert.match(page, /Windows\/macOS 共用 elephant-route-desktop/);
  assert.match(page, /appKeyInput\.readOnly\s*=\s*isOfficial/);
  assert.match(page, /platform: packageForm\.querySelector\('\[name="platform"\]'\)\.value/);
  assert.match(page, /distribution_scope:\s*distributionScope/);
  assert.match(page, /<label>版本号<input name="version"/);
  assert.doesNotMatch(page, /<input type="hidden" name="app_key"/);
  assert.doesNotMatch(page, /<input type="hidden" name="version"/);
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
