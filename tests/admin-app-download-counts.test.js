const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('admin app package versions no longer include download counts', () => {
  const versionModel = readRepoFile('app/Models/AppVersion.php');
  const artifactModel = readRepoFile('app/Models/AppArtifact.php');
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/AppPackageController.php');

  assert.doesNotMatch(versionModel, /HasMany|downloadLogs|AppDownloadLog/);
  assert.doesNotMatch(artifactModel, /HasMany|downloadLogs|AppDownloadLog/);
  assert.doesNotMatch(controller, /withCount\('downloadLogs'\)|AppDownloadLog/);
});

test('admin app download table omits statistics and verification controls', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');
  const routes = readRepoFile('app/Http/Routes/V2/AdminRoute.php');

  assert.match(page, /<th>下载链接<\/th>/);
  assert.doesNotMatch(page, /下载次数|download_logs_count|downloadCount/);
  assert.doesNotMatch(page, /Download Verification|Cloudflare Turnstile|download-settings/);
  assert.doesNotMatch(routes, /app-packages\/settings|app-packages\/logs/);
});
