const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('app downloads no longer expose an authenticated prepare route or limiter', () => {
  const userRoute = readRepoFile('app/Http/Routes/V1/UserRoute.php');
  const guestRoute = readRepoFile('app/Http/Routes/V1/GuestRoute.php');
  const provider = readRepoFile('app/Providers/RouteServiceProvider.php');

  assert.doesNotMatch(userRoute, /app-downloads|app-download-prepare/);
  assert.doesNotMatch(guestRoute, /app-downloads\/\{artifact\}\/download|signed:relative/);
  assert.doesNotMatch(provider, /app-download-prepare|下载请求过于频繁/);
});

test('download-specific verification files and configuration are removed', () => {
  for (const relativePath of [
    'app/Http/Controllers/V1/User/AppDownloadController.php',
    'app/Services/AppDownloadVerificationService.php',
    'config/app_downloads.php',
  ]) {
    assert.equal(fs.existsSync(path.join(repoRoot, relativePath)), false, `${relativePath} should be removed`);
  }

  const envExample = readRepoFile('.env.example');
  assert.doesNotMatch(envExample, /APP_DOWNLOAD_SIGNED_URL_TTL_SECONDS/);
  assert.equal(fs.existsSync(path.join(repoRoot, 'app/Models/AppDownloadLog.php')), false);
});

test('public download page opens configured URLs directly without login or verification', () => {
  const page = readRepoFile('public/download/index.html');

  assert.match(page, /window\.open\(pkg\.download_url, "_blank", "noopener,noreferrer"\)/);
  assert.doesNotMatch(page, /\/api\/v1\/user\/app-downloads|\/prepare|turnstile|请先登录后下载/);
  assert.doesNotMatch(page, /response\.status === 429|下载请求过于频繁/);
});

test('guest downloads return configured URLs without redirects or logging', () => {
  const guestController = readRepoFile('app/Http/Controllers/V1/Guest/AppDownloadController.php');

  assert.match(guestController, /'download_url'\s*=>\s*\$version->download_url/);
  assert.doesNotMatch(guestController, /AppDownloadLog|redirect\(\)->away|response\(\)->download/);
  assert.doesNotMatch(guestController, /function download\(/);
});
