const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  const absolutePath = path.join(repoRoot, relativePath);

  try {
    return fs.readFileSync(absolutePath, 'utf8');
  } catch (error) {
    if (error && error.code === 'ENOENT') {
      assert.fail(`Expected download-mirror contract source file is missing: ${relativePath}`);
    }

    throw error;
  }
}

function assertAppearsBefore(source, first, second, message) {
  const firstIndex = source.indexOf(first);
  const secondIndex = source.indexOf(second);

  assert.notEqual(firstIndex, -1, `Expected source to contain: ${first}`);
  assert.notEqual(secondIndex, -1, `Expected source to contain: ${second}`);
  assert.ok(firstIndex < secondIndex, message);
}

function extractBlockStartingAt(source, start, description) {
  const bodyStart = source.indexOf('{', start);
  assert.notEqual(bodyStart, -1, `${description} should have a body`);

  let depth = 0;
  for (let index = bodyStart; index < source.length; index += 1) {
    if (source[index] === '{') depth += 1;
    if (source[index] === '}') depth -= 1;
    if (depth === 0) return source.slice(start, index + 1);
  }

  assert.fail(`${description} body was not closed`);
}

function extractPhpMethod(source, name) {
  const start = source.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `Expected method ${name} to exist`);
  return extractBlockStartingAt(source, start, `Method ${name}`);
}

function extractPhpArrayBlock(source, arrayKey) {
  const start = source.indexOf(arrayKey);
  assert.notEqual(start, -1, `Expected array block ${arrayKey} to exist`);

  const bodyStart = source.indexOf('[', start);
  assert.notEqual(bodyStart, -1, `Array block ${arrayKey} should open with [`);

  let depth = 0;
  for (let index = bodyStart; index < source.length; index += 1) {
    if (source[index] === '[') depth += 1;
    if (source[index] === ']') depth -= 1;
    if (depth === 0) return source.slice(start, index + 1);
  }

  assert.fail(`Array block ${arrayKey} was not closed`);
}

function extractIfBlock(source, condition, description) {
  const match = condition.exec(source);
  assert.ok(match, `Expected ${description} conditional branch to exist`);
  return extractBlockStartingAt(source, match.index, description);
}

test('app artifact mirror schema, model, queue, Horizon, and environment contracts exist', () => {
  const migration = readRepoFile(
    'database/migrations/2026_07_29_000001_add_mirror_fields_to_app_artifacts.php'
  );
  const model = readRepoFile('app/Models/AppArtifact.php');
  const queue = readRepoFile('config/queue.php');
  const horizon = readRepoFile('config/horizon.php');
  const environment = readRepoFile('.env.example');
  const mirrorQueue = extractPhpArrayBlock(queue, "'redis_mirror'");
  const mirrorSupervisor = extractPhpArrayBlock(horizon, "'XboardAppDownloadMirror'");

  assert.match(migration, /mirror_status/);
  assert.match(migration, /mirror_path/);
  assert.match(migration, /mirror_error/);
  assert.match(migration, /mirrored_at/);
  assert.match(model, /MIRROR_LOCAL\s*=\s*'local'/);
  assert.match(model, /MIRROR_PENDING\s*=\s*'pending'/);
  assert.match(model, /MIRROR_SYNCING\s*=\s*'syncing'/);
  assert.match(model, /MIRROR_READY\s*=\s*'ready'/);
  assert.match(model, /MIRROR_FAILED\s*=\s*'failed'/);
  assert.match(mirrorQueue, /'retry_after'\s*=>\s*1900/);
  assert.match(mirrorSupervisor, /'connection'\s*=>\s*'redis_mirror'/);
  assert.match(mirrorSupervisor, /'queue'\s*=>\s*\[[^\]]*'app_download_mirror'/);
  assert.match(environment, /^APP_DOWNLOAD_MIRROR_ENABLED=false$/m);
  assert.match(environment, /^APP_DOWNLOAD_MIRROR_SYNC_ENABLED=false$/m);
});

test('mirror sync streams through a partial file and atomically moves only after remote size verification', () => {
  const mirror = readRepoFile('app/Services/AppDownloadMirror.php');

  assert.match(mirror, /readStream/);
  assert.match(mirror, /\.part-/);
  assert.match(mirror, /->size\(/);
  assert.match(mirror, /->move\(/);
  assertAppearsBefore(
    mirror,
    '->size(',
    '->move(',
    'remote size must be verified before the partial file is moved into place'
  );
});

test('mirror sync job protects against stale content and concurrent duplicate work', () => {
  const job = readRepoFile('app/Jobs/SyncAppArtifactMirror.php');

  assert.match(job, /expectedSha256/);
  assert.match(job, /hash_equals\(\$this->expectedSha256,\s*\$artifact->sha256\)/);
  assert.match(job, /Cache::lock/);
  assert.match(job, /\$tries\s*=\s*3/);
  assert.match(job, /\$timeout\s*=\s*1800/);
});

test('guest download logs before returning a mirror redirect or local download response', () => {
  const controller = readRepoFile('app/Http/Controllers/V1/Guest/AppDownloadController.php');

  assert.match(controller, /AppDownloadLog::create/);
  assert.match(controller, /redirect\(\)->away/);
  assert.match(controller, /response\(\)->download/);
  assertAppearsBefore(
    controller,
    'AppDownloadLog::create',
    'redirect()->away',
    'download logging must happen before a mirror redirect'
  );
  assertAppearsBefore(
    controller,
    'redirect()->away',
    'response()->download',
    'the ready mirror redirect must be preferred over the local download response'
  );
});

test('admin routes and page expose retryable mirror status without breaking artifact upload fields', () => {
  const routes = readRepoFile('app/Http/Routes/V2/AdminRoute.php');
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(
    routes,
    /\$router->post\('\/versions\/mirror\/retry',\s*\[AppPackageController::class,\s*'retryMirror'\]\)/
  );
  assert.match(page, /镜像状态/);
  assert.match(page, /重新同步/);
  assert.match(page, /安装包已发布，正在同步到下载服务器/);
  assert.match(page, /name="artifact"/);
});

test('secure-link signing fixture is stable and router signer follows the Nginx md5 base64url contract', () => {
  const expires = '1800000000';
  const uri = '/files/artifacts/50/abc/ElephantNetwork-Setup-x64-v1.6.6.exe';
  const key = 'fixture-signing-key';
  const signature = crypto
    .createHash('md5')
    .update(`${expires}${uri} ${key}`)
    .digest('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');

  assert.equal(signature, 'vu3Yo33ecUPO1ROEJu4anw');
  const signer = readRepoFile('app/Services/AppDownloadMirrorUrlSigner.php');

  assert.match(signer, /md5\(\$expires\s*\.\s*\$uri\s*\.\s*' '\s*\.\s*\$key,\s*true\)/);
  assert.match(signer, /base64_encode/);
  assert.match(signer, /rtrim\(\s*strtr\(\s*\$digest\s*,\s*'\+\/'\s*,\s*'-_'\s*\)\s*,\s*'='\s*\)/);
});

test('mirror router only serves healthy ready artifacts and never logs the signed URL', () => {
  const router = readRepoFile('app/Services/AppDownloadMirrorRouter.php');
  const redirectUrl = extractPhpMethod(router, 'redirectUrl');

  assert.match(redirectUrl, /\$artifact->mirror_status\s*!==\s*AppArtifact::MIRROR_READY/);
  assert.match(redirectUrl, /Cache::remember/);
  assert.match(redirectUrl, /connectTimeout\(1\)/);
  assert.match(redirectUrl, /->head\(/i);
  assert.match(redirectUrl, /healthy\s*\?\s*\$url\s*:\s*null/);
  assert.doesNotMatch(redirectUrl, /Log::\w+\([^;]*\$url/s);
  assert.doesNotMatch(redirectUrl, /logger\s*\(\s*\$url\s*\)|logger\s*\(\s*\)\s*->\w+\([^;]*\$url/s);
  assert.doesNotMatch(redirectUrl, /\$(?:logger|log)\s*->\w+\([^;]*\$url|->logger\s*->\w+\([^;]*\$url/s);
});

test('artifact lifecycle queues mirrors only for new artifacts and removes mirrors on admin deletion', () => {
  const storage = readRepoFile('app/Services/AppArtifactStorage.php');
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/AppPackageController.php');
  const deleteJob = readRepoFile('app/Jobs/DeleteAppArtifactMirror.php');
  const store = extractPhpMethod(storage, 'store');
  const updateVersion = extractPhpMethod(storage, 'updateVersion');
  const transactionStart = updateVersion.indexOf('DB::transaction(');
  assert.notEqual(transactionStart, -1, 'updateVersion should use a database transaction');
  const transactionClosure = extractBlockStartingAt(
    updateVersion,
    transactionStart,
    'updateVersion transaction'
  );
  const postTransaction = updateVersion.slice(transactionStart + transactionClosure.length);
  const replaceArtifactAfterTransaction = extractIfBlock(
    postTransaction,
    /if\s*\(\s*\$storedFile\s*\)/g,
    'updateVersion post-transaction new-artifact'
  );
  const queueMirrorSync = extractPhpMethod(storage, 'queueMirrorSync');
  const drop = extractPhpMethod(controller, 'drop');
  const retryMirror = extractPhpMethod(controller, 'retryMirror');

  assert.match(store, /queueMirrorSync/);
  assert.match(replaceArtifactAfterTransaction, /queueMirrorSync/);
  assert.match(
    queueMirrorSync,
    /SyncAppArtifactMirror::dispatch\(\$artifact->id,\s*\$artifact->sha256\)->afterCommit\(\)/
  );
  assert.match(drop, /DeleteAppArtifactMirror::dispatch\([^)]*\)->afterCommit\(\)/);
  assert.match(retryMirror, /\$storage->exists\(\$artifact\)/);
  assert.match(retryMirror, /queueMirrorSync/);
  assert.match(deleteJob, /class DeleteAppArtifactMirror/);
});
