const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('external download migrations disable versions without links and remove statistics', () => {
  const migrationPath = path.join(
    repoRoot,
    'database/migrations/2026_09_07_000001_switch_app_downloads_to_external_links.php'
  );
  assert.equal(fs.existsSync(migrationPath), true);

  const migration = fs.readFileSync(migrationPath, 'utf8');
  const dropMigration = readRepoFile(
    'database/migrations/2026_09_07_000002_drop_app_download_logs.php'
  );
  assert.match(migration, /nullable\(\)->change\(\)/);
  assert.match(migration, /whereNotNull\('app_id'\)/);
  assert.match(migration, /whereNull\('download_url'\)/);
  assert.match(migration, /TRIM\(download_url\) = ''/);
  assert.match(migration, /'is_enabled'\s*=>\s*false/);
  assert.match(dropMigration, /Schema::dropIfExists\('v2_app_download_logs'\)/);
  assert.doesNotMatch(migration, /Storage::|delete\(/);
});

test('app versions no longer expose download statistics', () => {
  const version = readRepoFile('app/Models/AppVersion.php');
  const artifact = readRepoFile('app/Models/AppArtifact.php');

  assert.doesNotMatch(version, /HasMany|downloadLogs|AppDownloadLog/);
  assert.doesNotMatch(artifact, /HasMany|downloadLogs|AppDownloadLog/);
  assert.equal(fs.existsSync(path.join(repoRoot, 'app/Models/AppDownloadLog.php')), false);
});

test('admin creates and edits external link versions without uploaded files', () => {
  const controller = readRepoFile(
    'app/Http/Controllers/V2/Admin/AppPackageController.php'
  );
  const saveStart = controller.indexOf('public function saveVersion(');
  const updateStart = controller.indexOf('public function updateVersion(');
  const publishStart = controller.indexOf('public function publish(');
  const saveVersion = controller.slice(saveStart, updateStart);
  const updateVersion = controller.slice(updateStart, publishStart);

  assert.match(saveVersion, /'download_url'/);
  assert.match(saveVersion, /'file_size_mb'/);
  assert.match(saveVersion, /'sha256'/);
  assert.match(saveVersion, /normalizeExternalVersionData/);
  assert.doesNotMatch(saveVersion, /hasFile\('artifact'\)|request->file\('artifact'\)|\$storage->store/);

  assert.match(updateVersion, /'download_url'/);
  assert.match(updateVersion, /'file_size_mb'/);
  assert.match(updateVersion, /'sha256'/);
  assert.match(updateVersion, /normalizeExternalVersionData/);
  assert.doesNotMatch(updateVersion, /request->file\('artifact'\)|updateVersion\(\s*\$version/);

  assert.match(controller, /private function normalizeExternalVersionData/);
  assert.match(controller, /parse_url\(/);
  assert.match(controller, /strtolower\(\(string\)\s*\(\$parts\['scheme'\]\s*\?\?\s*''\)\)\s*!==\s*'https'/);
  assert.match(controller, /macOS 官方更新必须填写有效的 SHA256/);
});

test('public downloads expose configured external links directly', () => {
  const guest = readRepoFile('app/Http/Controllers/V1/Guest/AppDownloadController.php');
  const update = readRepoFile('app/Http/Controllers/V1/Guest/AppUpdateController.php');

  assert.match(guest, /whereNotNull\('download_url'\)/);
  assert.match(guest, /'version_id'\s*=>\s*\$version->id/);
  assert.match(guest, /'artifact_id'\s*=>\s*\$version->id/);
  assert.match(guest, /'download_url'\s*=>\s*\$version->download_url/);
  assert.doesNotMatch(guest, /AppDownloadLog|redirect\(\)->away|response\(\)->download/);

  assert.match(update, /whereNotNull\('download_url'\)/);
  assert.doesNotMatch(update, /whereHas\('artifact'\)|\$latest->artifact->id/);
  assert.match(update, /\$latestPayload\s*=\s*\$latest->toClientArray\(\)/);
  assert.doesNotMatch(update, /temporarySignedRoute|download_handle/);
});

test('admin page exposes links and metadata without upload controls', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /<label>下载链接<input[^>]+name="download_url"[^>]+type="url"/);
  assert.match(page, /name="file_size_mb"/);
  assert.match(page, /name="sha256"/);
  assert.match(page, /<th>下载链接<\/th>/);
  assert.doesNotMatch(page, /type="file"|uploadRequest|上传中|上传安装包|替换并删除旧文件/);
});
