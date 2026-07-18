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
  assert.match(model, /'macos'\s*=>\s*'elephant-route-desktop'/);
  assert.match(model, /LEGACY_OFFICIAL_APP_KEYS/);
  assert.match(model, /'elephant-route-mac'/);
  assert.match(model, /public static function officialAppKeyForPlatform/);
  assert.match(model, /public static function isReservedAppKey/);
  assert.match(model, /'distribution_scope'/);
});

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
