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
