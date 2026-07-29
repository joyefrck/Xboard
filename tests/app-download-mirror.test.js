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
  let state = 'code';
  for (let index = bodyStart; index < source.length; index += 1) {
    const character = source[index];
    const nextCharacter = source[index + 1];

    if (state === 'line-comment') {
      if (character === '\n') state = 'code';
      continue;
    }
    if (state === 'block-comment') {
      if (character === '*' && nextCharacter === '/') {
        state = 'code';
        index += 1;
      }
      continue;
    }
    if (state === 'single-quote' || state === 'double-quote') {
      if (character === '\\') {
        index += 1;
      } else if ((state === 'single-quote' && character === "'")
        || (state === 'double-quote' && character === '"')) {
        state = 'code';
      }
      continue;
    }

    if (character === '/' && nextCharacter === '/') {
      state = 'line-comment';
      index += 1;
      continue;
    }
    if (character === '/' && nextCharacter === '*') {
      state = 'block-comment';
      index += 1;
      continue;
    }
    if (character === '#') {
      state = 'line-comment';
      continue;
    }
    if (character === "'") {
      state = 'single-quote';
      continue;
    }
    if (character === '"') {
      state = 'double-quote';
      continue;
    }
    if (character === '{') depth += 1;
    if (character === '}') depth -= 1;
    if (depth === 0) return source.slice(start, index + 1);
  }

  assert.fail(`${description} body was not closed`);
}

function assertNoSensitiveMirrorLogFields(source) {
  const logCall = /(?:Log::\w+|logger\s*\(\s*\)\s*->\w+|logger\s*\(|\$(?:logger|log)\s*->\w+|\$this->logger\s*->\w+)\(([\s\S]*?)\);/g;
  const sensitiveField = /['"](?:url|uri|location|signature|md5|signer)['"]\s*=>|\$\w*(?:url|uri|location|signature|md5|signer)\w*/i;

  for (const match of source.matchAll(logCall)) {
    assert.doesNotMatch(
      match[1],
      sensitiveField,
      'mirror logs may include artifact_id and exceptions, but not signed URL material'
    );
  }
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function assertMirrorSyncRunsInsideCacheLock(handle) {
  const callbackWithMirrorSync = String.raw`->block\([\s\S]*?(?:function\s*\([^)]*\)|fn\s*\([^)]*\)\s*=>)[\s\S]*?(?:\$this->mirror|\$mirror)->sync\(`;
  const directLock = new RegExp(`Cache::lock\\([\\s\\S]*?${callbackWithMirrorSync}`);
  if (directLock.test(handle)) return;

  const assignedLock = handle.match(/(\$[A-Za-z_]\w*)\s*=\s*Cache::lock\(/);
  assert.ok(assignedLock, 'handle should acquire a cache lock before mirror synchronization');
  assert.match(
    handle,
    new RegExp(`${escapeRegExp(assignedLock[1])}\\s*${callbackWithMirrorSync}`),
    'the acquired cache lock block callback must contain mirror synchronization'
  );
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

function extractIfBlocks(source, condition, description) {
  const branches = [];
  const flags = condition.flags.includes('g') ? condition.flags : `${condition.flags}g`;
  const matcher = new RegExp(condition.source, flags);
  let match;

  while ((match = matcher.exec(source)) !== null) {
    branches.push({
      start: match.index,
      body: extractBlockStartingAt(source, match.index, description),
    });
  }

  return branches;
}

test('PHP block extraction ignores braces in strings and comments', () => {
  const source = "function fixture() { $single = '}'; $double = \"{\"; // }\n# {\n/* } */\nif (true) { return '}'; }\n} trailing";
  const block = extractBlockStartingAt(source, 0, 'fixture');

  assert.equal(block, source.slice(0, source.lastIndexOf('}') + 1));
});

test('app artifact mirror migration persists mirror fields', () => {
  const migration = readRepoFile(
    'database/migrations/2026_07_29_000001_add_mirror_fields_to_app_artifacts.php'
  );

  assert.match(migration, /mirror_status/);
  assert.match(migration, /mirror_path/);
  assert.match(migration, /mirror_error/);
  assert.match(migration, /mirrored_at/);
});

test('app artifact model owns mirror states', () => {
  const model = readRepoFile('app/Models/AppArtifact.php');

  assert.match(model, /MIRROR_LOCAL\s*=\s*'local'/);
  assert.match(model, /MIRROR_PENDING\s*=\s*'pending'/);
  assert.match(model, /MIRROR_SYNCING\s*=\s*'syncing'/);
  assert.match(model, /MIRROR_READY\s*=\s*'ready'/);
  assert.match(model, /MIRROR_FAILED\s*=\s*'failed'/);
});

test('mirror queue and Horizon supervisor are isolated', () => {
  const queue = readRepoFile('config/queue.php');
  const horizon = readRepoFile('config/horizon.php');
  const mirrorQueue = extractPhpArrayBlock(queue, "'redis_mirror'");
  const mirrorSupervisor = extractPhpArrayBlock(horizon, "'XboardAppDownloadMirror'");

  assert.match(mirrorQueue, /'retry_after'\s*=>\s*1900/);
  assert.match(mirrorSupervisor, /'connection'\s*=>\s*'redis_mirror'/);
  assert.match(mirrorSupervisor, /'queue'\s*=>\s*\[[^\]]*'app_download_mirror'/);
});

test('mirror feature flags default to disabled', () => {
  const environment = readRepoFile('.env.example');

  assert.match(environment, /^APP_DOWNLOAD_MIRROR_ENABLED=false$/m);
  assert.match(environment, /^APP_DOWNLOAD_MIRROR_SYNC_ENABLED=false$/m);
});

test('mirror sync streams through a partial file and atomically moves only after remote size verification', () => {
  const mirror = readRepoFile('app/Services/AppDownloadMirror.php');
  const sync = extractPhpMethod(mirror, 'sync');

  assert.match(sync, /readStream/);
  assert.match(sync, /\.part-/);
  assert.match(sync, /->size\(/);
  assert.match(sync, /->move\(/);
  assert.match(sync, /if\s*\([^)]*(?:size|Size)[^)]*(?:!==|!=)[^)]*\)\s*\{\s*(?:throw|return)/);
  assertAppearsBefore(
    sync,
    '->size(',
    '->move(',
    'remote size must be verified before the partial file is moved into place'
  );
});

test('mirror sync job protects against stale content and concurrent duplicate work', () => {
  const job = readRepoFile('app/Jobs/SyncAppArtifactMirror.php');
  const handle = extractPhpMethod(job, 'handle');

  assert.match(job, /expectedSha256/);
  assert.match(
    handle,
    /if\s*\(\s*!\s*hash_equals\(\$this->expectedSha256,\s*\$artifact->sha256\)\s*\)\s*\{\s*return;/
  );
  assertMirrorSyncRunsInsideCacheLock(handle);
  assert.match(job, /\$tries\s*=\s*3/);
  assert.match(job, /\$timeout\s*=\s*1800/);
});

test('guest download logs before returning a mirror redirect or local download response', () => {
  const controller = readRepoFile('app/Http/Controllers/V1/Guest/AppDownloadController.php');
  const download = extractPhpMethod(controller, 'download');

  assert.match(download, /AppDownloadLog::create/);
  assert.match(download, /redirect\(\)->away/);
  assert.match(download, /response\(\)->download/);
  assertAppearsBefore(
    download,
    'AppDownloadLog::create',
    'redirect()->away',
    'download logging must happen before a mirror redirect'
  );
  assertAppearsBefore(
    download,
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

  assert.match(
    redirectUrl,
    /if\s*\(\s*\$artifact->mirror_status\s*!==\s*AppArtifact::MIRROR_READY\s*\)\s*\{\s*return null;/
  );
  assert.match(redirectUrl, /Cache::remember/);
  assert.match(redirectUrl, /connectTimeout\(1\)/);
  assert.match(redirectUrl, /->head\(/i);
  assert.match(redirectUrl, /healthy\s*\?\s*\$url\s*:\s*null/);
  assertNoSensitiveMirrorLogFields(redirectUrl);
});

test('artifact lifecycle queues mirrors only for new artifacts and removes mirrors on admin deletion', () => {
  const storage = readRepoFile('app/Services/AppArtifactStorage.php');
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/AppPackageController.php');
  const deleteJob = readRepoFile('app/Jobs/DeleteAppArtifactMirror.php');
  const store = extractPhpMethod(storage, 'store');
  const updateVersion = extractPhpMethod(storage, 'updateVersion');
  const transactionStart = updateVersion.indexOf('DB::transaction(');
  assert.notEqual(transactionStart, -1, 'updateVersion should use a database transaction');
  const cleanupFailedFileStart = updateVersion.indexOf('cleanupFailedFile', transactionStart);
  assert.notEqual(
    cleanupFailedFileStart,
    -1,
    'updateVersion should retain its catch cleanup before mirror synchronization'
  );
  const storedFileBranches = extractIfBlocks(
    updateVersion,
    /if\s*\(\s*\$storedFile\s*\)/g,
    'updateVersion new-artifact'
  );
  const queueMirrorSync = extractPhpMethod(storage, 'queueMirrorSync');
  const drop = extractPhpMethod(controller, 'drop');
  const retryMirror = extractPhpMethod(controller, 'retryMirror');

  assert.match(store, /queueMirrorSync/);
  assert.ok(
    storedFileBranches.some(({ start, body }) => (
      start > transactionStart
      && start > cleanupFailedFileStart
      && /queueMirrorSync/.test(body)
    )),
    'updateVersion should queue a mirror only in a post-transaction, post-cleanup new-artifact branch'
  );
  assert.equal(
    (updateVersion.match(/->queueMirrorSync\(/g) || []).length,
    1,
    'updateVersion should queue exactly once when replacing an artifact'
  );
  assert.match(
    queueMirrorSync,
    /SyncAppArtifactMirror::dispatch\(\$artifact->id,\s*\$artifact->sha256\)->afterCommit\(\)/
  );
  assert.match(drop, /DeleteAppArtifactMirror::dispatch\([^)]*\)->afterCommit\(\)/);
  assertAppearsBefore(
    drop,
    '$version->delete()',
    'DeleteAppArtifactMirror::dispatch',
    'mirror deletion must be dispatched after the version is deleted'
  );
  assert.match(retryMirror, /\$storage->exists\(\$artifact\)/);
  assert.match(retryMirror, /queueMirrorSync/);
  assert.match(deleteJob, /class DeleteAppArtifactMirror/);
});
