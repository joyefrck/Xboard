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
  const masked = codeView(source);
  const bodyStart = masked.indexOf('{', start);
  assert.notEqual(bodyStart, -1, `${description} should have a body`);

  let depth = 0;
  for (let index = bodyStart; index < masked.length; index += 1) {
    if (masked[index] === '{') depth += 1;
    if (masked[index] === '}') depth -= 1;
    if (depth === 0) return source.slice(start, index + 1);
  }

  assert.fail(`${description} body was not closed`);
}

function extractLockCallback(source) {
  const lockStart = source.indexOf('Cache::lock(');
  assert.notEqual(lockStart, -1, 'Expected Cache::lock in the critical section');
  const lockEnd = extractParenthesized(source, source.indexOf('(', lockStart), 'Cache::lock');
  const blockStart = source.indexOf('->block(', lockEnd.end);
  assert.notEqual(blockStart, -1, 'Expected Cache::lock to invoke block');
  assert.match(source.slice(lockEnd.end, blockStart), /^\s*$/, 'Cache lock block must be the same direct call chain');
  const blockArguments = extractParenthesized(source, source.indexOf('(', blockStart), 'Cache lock block');
  const callbackStart = blockArguments.body.indexOf('function');
  assert.notEqual(callbackStart, -1, 'Expected Cache lock block callback');
  return extractBlockStartingAt(source, blockArguments.start + 1 + callbackStart, 'Cache lock callback');
}

function extractParenthesized(source, open, description) {
  assert.notEqual(open, -1, `${description} should have arguments`);
  const view = codeView(source);
  let depth = 0;
  for (let index = open; index < view.length; index += 1) {
    if (view[index] === '(') depth += 1;
    if (view[index] === ')') depth -= 1;
    if (depth === 0) return { start: open, end: index + 1, body: source.slice(open + 1, index) };
  }
  assert.fail(`${description} arguments were not closed`);
}

function codeView(source) {
  let output = '';
  let state = 'code';
  for (let index = 0; index < source.length; index += 1) {
    const c = source[index];
    const n = source[index + 1];
    const blank = () => { output += c === '\n' ? '\n' : ' '; };
    if (typeof state === 'object' && state.heredoc) {
      const lineEnd = source.indexOf('\n', index);
      const line = source.slice(index, lineEnd === -1 ? source.length : lineEnd);
      if (new RegExp(`^${state.heredoc};?$`).test(line.trim())) {
        const end = lineEnd === -1 ? source.length : lineEnd + 1;
        output += source.slice(index, end).replace(/[^\n]/g, ' ');
        index = end - 1;
        state = 'code';
        continue;
      }
      blank();
      continue;
    }
    if (state === 'line') { blank(); if (c === '\n') state = 'code'; continue; }
    if (state === 'block') { blank(); if (c === '*' && n === '/') { output += ' '; index += 1; state = 'code'; } continue; }
    if (typeof state === 'object') { blank(); if (c === '\\') { if (n) { output += n === '\n' ? '\n' : ' '; index += 1; } } else if (c === state.quote) state = 'code'; continue; }
    if (c === '/' && n === '/') { output += '  '; index += 1; state = 'line'; continue; }
    if (c === '/' && n === '*') { output += '  '; index += 1; state = 'block'; continue; }
    if (c === '<' && source.slice(index).match(/^<<<\s*['"]?([A-Za-z_]\w*)['"]?[^\n]*\n/)) {
      const match = source.slice(index).match(/^<<<\s*['"]?([A-Za-z_]\w*)['"]?[^\n]*\n/);
      output += ' '.repeat(match[0].length - 1) + '\n';
      index += match[0].length - 1;
      state = { heredoc: match[1] };
      continue;
    }
    if (c === '#') { blank(); state = 'line'; continue; }
    if (c === "'" || c === '"' || c === '`') { blank(); state = { quote: c }; continue; }
    output += c;
  }
  return output;
}

function extractCatchBlock(source) {
  const catchStart = source.indexOf('catch ');
  assert.notEqual(catchStart, -1, 'Expected catch cleanup branch');
  return { start: catchStart, body: extractBlockStartingAt(source, catchStart, 'catch branch') };
}

function assertNoRouterLogging(source) {
  assert.doesNotMatch(source, /(?:Log::(?:debug|info|notice|warning|error|critical|alert|emergency|log)|\b(?:logger|debug|info|notice|warning|error)\s*\(|->(?:debug|info|notice|warning|error|critical|alert|emergency|log)\s*\()/);
}

function hasBoundHealthReturn(source) {
  const health = source.match(/(\$\w+)\s*=\s*Cache::remember\(/)?.[1];
  const signed = source.match(/(\$\w+)\s*=\s*[^;]*->(?:sign|redirectUrl)\(/)?.[1];
  if (!health || !signed) return false;
  const escapedHealth = health.replace('$', '\\$');
  const escapedSigned = signed.replace('$', '\\$');
  return new RegExp(`return\\s+${escapedHealth}\\s*\\?\\s*${escapedSigned}\\s*:\\s*null`).test(source)
    || new RegExp(`if\\s*\\(\\s*!${escapedHealth}\\s*\\)\\s*\\{\\s*return null;\\s*\\}[\\s\\S]*?return\\s+${escapedSigned}\\s*;`).test(source);
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
  const source = "function fixture() { $single = '}'; $double = \"{\"; $command = `{`; // }\n# {\n/* } */\nif (true) { return '}'; }\n} trailing";
  const block = extractBlockStartingAt(source, 0, 'fixture');

  assert.equal(block, source.slice(0, source.lastIndexOf('}') + 1));
});

test('code view ignores heredoc and nowdoc non-code braces', () => {
  const source = "<<<TXT\n} Log::error('x')\nTXT;\n<<<'RAW'\n}\nRAW;\nrun();";
  const view = codeView(source);

  assert.doesNotMatch(view, /Log::error|}/);
  assert.match(view, /run\(\)/);
});

test('method extraction reaches final brace after heredoc and nowdoc content', () => {
  const source = "function heredocFixture() { $a = <<<TXT\n}\nTXT;\nwork(); } function nowdocFixture() { $b = <<<'RAW'\n}\nRAW;\nwork(); }";

  assert.match(extractPhpMethod(source, 'heredocFixture'), /work\(\);\s*}$/);
  assert.match(extractPhpMethod(source, 'nowdocFixture'), /work\(\);\s*}$/);
});

test('lock callback extraction excludes sync outside an empty block', () => {
  const source = 'Cache::lock("x")->block(5, function () { }); $mirror->sync();';

  assert.doesNotMatch(extractLockCallback(source), /->sync\(/);
  assert.throws(() => extractLockCallback('Cache::lock("x")->block(5); run(function () { $mirror->sync(); });'));
  assert.throws(() => extractLockCallback('Cache::lock("x"); $runner->block(5, function () { $mirror->sync(); });'));
});

test('router log and health helpers reject unbound security paths without false positives', () => {
  assert.throws(() => assertNoRouterLogging('$target = $signed; info($target);'));
  assert.doesNotThrow(() => assertNoRouterLogging('$response->successful();'));
  assert.equal(hasBoundHealthReturn('if ($artifact->mirror_status !== AppArtifact::MIRROR_READY) { return null; } return $url;'), false);
  assert.equal(hasBoundHealthReturn(codeView('$healthy = Cache::remember("x", fn () => true); $url = $signer->sign(); // return $healthy ? $url : null;\nreturn $url;')), false);
  assert.equal(hasBoundHealthReturn('$healthy = Cache::remember("x", fn () => true); $url = $signer->sign(); return $healthy ? $url : null;'), true);
});

test('catch branch fixture distinguishes cleanup from a forbidden mirror queue', () => {
  const source = 'function x() { try { work(); } catch (Throwable $e) { cleanup(); } if ($storedFile) { $this->queueMirrorSync($artifact); } }';
  assert.doesNotMatch(extractCatchBlock(source).body, /->queueMirrorSync\(/);
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

test('app artifact mirror migration restores a missing status index independently', () => {
  const migration = readRepoFile(
    'database/migrations/2026_07_29_000001_add_mirror_fields_to_app_artifacts.php'
  );

  assert.match(
    migration,
    /if\s*\(\s*!Schema::hasIndex\('v2_app_artifacts',\s*'v2_app_artifacts_mirror_status_index'\)\s*\)/
  );
  assert.match(
    migration,
    /\$table->index\('mirror_status',\s*'v2_app_artifacts_mirror_status_index'\)/
  );
  assert.match(
    migration,
    /if\s*\(\s*Schema::hasIndex\('v2_app_artifacts',\s*'v2_app_artifacts_mirror_status_index'\)\s*\)/
  );
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

test('mirror key defaults stay outside the project bind mount and web service', () => {
  const environment = readRepoFile('.env.example');
  const compose = readRepoFile('docker-compose.yaml');
  const web = compose.match(/^  web:\n[\s\S]*?(?=^  [a-zA-Z0-9_-]+:\n|^networks:)/m)?.[0];

  assert.match(environment, /^APP_DOWNLOAD_MIRROR_KEY_DIR=\.\.\/xboard-secrets\/app-download-mirror$/m);
  assert.doesNotMatch(environment, /^APP_DOWNLOAD_MIRROR_KEY_DIR=\.\//m);
  assert.match(
    compose,
    /\$\{APP_DOWNLOAD_MIRROR_KEY_DIR:-\.\.\/xboard-secrets\/app-download-mirror\}:\/run\/secrets\/app-download-mirror:ro/
  );
  assert.ok(web, 'expected web service block');
  assert.doesNotMatch(web, /APP_DOWNLOAD_MIRROR_KEY_DIR|\/run\/secrets\/app-download-mirror/);
});

test('mirror sync streams through a partial file and atomically moves only after remote size verification', () => {
  const mirror = readRepoFile('app/Services/AppDownloadMirror.php');
  const sync = extractPhpMethod(mirror, 'sync');

  assert.match(sync, /readStream/);
  assert.match(sync, /\.part-/);
  const syncCode = codeView(sync);
  assert.match(syncCode, /if\s*\(\s*\$remote->size\(\$temporary\)\s*!==\s*\$expectedSize\s*\)\s*\{\s*throw/);
  assertAppearsBefore(syncCode, '->size($temporary)', '->move($temporary, $target)', 'size mismatch guard must precede atomic move');
});

test('mirror sync job protects against stale content and concurrent duplicate work', () => {
  const job = readRepoFile('app/Jobs/SyncAppArtifactMirror.php');
  const handle = extractPhpMethod(job, 'handle');

  assert.match(job, /expectedSha256/);
  assert.match(
    handle,
    /if\s*\(\s*!\s*hash_equals\(\$this->expectedSha256,\s*\$artifact->sha256\)\s*\)\s*\{\s*return;/
  );
  assert.match(extractLockCallback(handle), /(?:\$this->mirror|\$mirror)->sync\(/);
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

test('secure-link signing fixture is stable and signer hashes the decoded URI but returns an encoded URL', () => {
  const expires = '1800000000';
  const signingUri = '/files/artifacts/50/abc/ElephantNetwork-Setup-x64-v1.6.6.exe';
  const key = 'fixture-signing-key';
  const signature = crypto
    .createHash('md5')
    .update(`${expires}${signingUri} ${key}`)
    .digest('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');

  assert.equal(signature, 'vu3Yo33ecUPO1ROEJu4anw');
  const signer = readRepoFile('app/Services/AppDownloadMirrorUrlSigner.php');

  assert.match(signer, /\$signingUri\s*=\s*'\/files\/'\s*\.\s*implode\(\s*'\/'\s*,\s*\$segments\s*\)/);
  assert.match(signer, /\$encodedUri\s*=\s*'\/files\/'\s*\.\s*implode\(\s*'\/'\s*,\s*array_map\(\s*'rawurlencode'\s*,\s*\$segments\s*\)\s*\)/);
  assert.match(signer, /md5\(\$expires\s*\.\s*\$signingUri\s*\.\s*' '\s*\.\s*\$key,\s*true\)/);
  assert.match(signer, /return\s+\$baseUrl\s*\.\s*\$encodedUri\s*\./);
  assert.match(signer, /base64_encode/);
  assert.match(signer, /rtrim\(\s*strtr\(\s*\$digest\s*,\s*'\+\/'\s*,\s*'-_'\s*\)\s*,\s*'='\s*\)/);
});

test('secure-link signing preserves non-ASCII and spaces for md5 while URL-encoding only the response URI', () => {
  const expires = '1800000000';
  const path = 'artifacts/中文/space file.apk';
  const key = 'fixture-signing-key';
  const signingUri = `/files/${path}`;
  const encodedUri = `/files/${path.split('/').map(encodeURIComponent).join('/')}`;
  const signature = crypto
    .createHash('md5')
    .update(`${expires}${signingUri} ${key}`)
    .digest('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');

  assert.equal(signature, '6XcsXovNYemRQaD8vL4z7g');
  assert.equal(encodedUri, '/files/artifacts/%E4%B8%AD%E6%96%87/space%20file.apk');
});

test('mirror signer rejects empty path segments after trimming permitted leading slashes', () => {
  const signer = readRepoFile('app/Services/AppDownloadMirrorUrlSigner.php');

  assert.match(signer, /\$segment\s*===\s*''/);
  assert.match(signer, /\$segment\s*===\s*'\.'/);
  assert.match(signer, /\$segment\s*===\s*'\.\.'/);
});

test('mirror router only serves healthy ready artifacts and never logs the signed URL', () => {
  const router = readRepoFile('app/Services/AppDownloadMirrorRouter.php');
  const redirectUrl = extractPhpMethod(router, 'redirectUrl');
  const redirectCode = codeView(redirectUrl);

  assert.match(
    redirectCode,
    /if\s*\(\s*\$artifact->mirror_status\s*!==\s*AppArtifact::MIRROR_READY\s*\)\s*\{\s*return null;/
  );
  assert.match(redirectUrl, /Cache::remember/);
  assert.match(redirectUrl, /connectTimeout\(1\)/);
  assert.match(redirectUrl, /->head\(/i);
  assert.ok(hasBoundHealthReturn(redirectCode), 'health status and signed URL must be bound in the return path');
  assertNoRouterLogging(redirectCode);
});

test('artifact lifecycle queues mirrors only for new artifacts and removes mirrors on admin deletion', () => {
  const storage = readRepoFile('app/Services/AppArtifactStorage.php');
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/AppPackageController.php');
  const deleteJob = readRepoFile('app/Jobs/DeleteAppArtifactMirror.php');
  const store = extractPhpMethod(storage, 'store');
  const updateVersion = extractPhpMethod(storage, 'updateVersion');
  const catchBranch = extractCatchBlock(updateVersion);
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
      && start > cleanupFailedFileStart && start > catchBranch.start + catchBranch.body.length
      && /queueMirrorSync/.test(body)
    )),
    'updateVersion should queue a mirror only in a post-transaction, post-cleanup new-artifact branch'
  );
  assert.doesNotMatch(catchBranch.body, /->queueMirrorSync\(/);
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
