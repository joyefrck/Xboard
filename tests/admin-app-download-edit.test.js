const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('admin app package exposes a dedicated version update route', () => {
  const routes = readRepoFile('app/Http/Routes/V2/AdminRoute.php');

  assert.match(
    routes,
    /post\('\/versions\/update',\s*\[AppPackageController::class,\s*'updateVersion'\]\)/
  );
});

test('version update only persists version-level editable fields', () => {
  const controller = readRepoFile(
    'app/Http/Controllers/V2/Admin/AppPackageController.php'
  );

  assert.match(controller, /public function updateVersion\(/);
  assert.match(controller, /'id'\s*=>\s*'required\|integer\|exists:v2_app_versions,id'/);
  assert.match(controller, /'version'\s*=>\s*'required\|string\|max:32'/);
  assert.match(controller, /'release_notes'\s*=>\s*'nullable\|string\|max:20000'/);
  assert.match(controller, /'download_url'\s*=>\s*'required\|string\|url\|max:2048'/);
  assert.match(controller, /'file_size_mb'\s*=>\s*'nullable\|numeric\|min:0'/);
  assert.match(controller, /'sha256'\s*=>\s*\['nullable',\s*'string'/);

  [
    'app_id',
    'platform',
    'channel',
    'arch',
    'build_number',
    'min_supported_build',
    'is_force',
    'is_enabled',
    'published_at',
  ].forEach((field) => {
    assert.match(controller, new RegExp(`'${field}'\\s*=>\\s*'prohibited'`));
  });

  assert.match(controller, /normalizeExternalVersionData\(\$data,\s*\$version->app,\s*\$version->platform\)/);
  assert.match(controller, /\$version->update\(\$attributes\)/);
  assert.doesNotMatch(controller, /\$request->file\('artifact'\)/);
});

test('package create endpoint cannot be reused to mutate an existing version', () => {
  const controller = readRepoFile(
    'app/Http/Controllers/V2/Admin/AppPackageController.php'
  );
  const saveVersionStart = controller.indexOf('public function saveVersion(');
  const updateVersionStart = controller.indexOf('public function updateVersion(');
  const saveVersion = controller.slice(saveVersionStart, updateVersionStart);

  assert.match(saveVersion, /'id'\s*=>\s*'prohibited'/);
  assert.doesNotMatch(saveVersion, /AppVersion::findOrFail\(\$data\['id'\]\)/);
  assert.match(saveVersion, /AppVersion::create\(\$data\)/);
});

test('deleting a legacy version still cleans up its stored artifact', () => {
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/AppPackageController.php');

  assert.match(controller, /public function drop\(Request \$request, AppArtifactStorage \$storage\)/);
  assert.match(controller, /if \(\$version->artifact\) \{[\s\S]*\$storage->deleteFile\(\$version->artifact\)/);
});

test('admin version rows open a modal editor before publish and delete actions', () => {
  const page = readRepoFile('resources/views/admin_app_downloads.blade.php');

  assert.match(page, /id="version-edit-modal"/);
  assert.match(page, /id="version-edit-form"/);
  assert.match(page, /name="id"/);
  assert.match(page, /name="version"/);
  assert.match(page, /name="release_notes"/);
  assert.match(page, /name="download_url"/);
  assert.match(page, /name="file_size_mb"/);
  assert.match(page, /name="sha256"/);
  assert.match(page, /id="version-edit-app-type"/);
  assert.match(page, /应用类型/);
  assert.match(page, /当前下载链接/);
  assert.doesNotMatch(page, /累计下载次数|下载次数/);
  assert.match(
    page,
    /String\(scope \|\| "download_only"\) === "official_update"[\s\S]*大象官方 App（支持自动更新）[\s\S]*第三方 App（仅供下载）/
  );
  assert.match(
    page,
    /versionEditAppType\.textContent = formatDistributionScope\([\s\S]*version\.app && version\.app\.distribution_scope/
  );
  assert.match(page, /function openVersionEditor\(version\)/);
  assert.match(page, /request\("\/versions\/update"/);
  assert.match(page, /openVersionEditor\(version\)/);

  const editAction = page.indexOf('actionButton("编辑"');
  const disableAction = page.indexOf('actionButton("下架"');
  const deleteAction = page.indexOf('actionButton("删除"');
  assert.ok(editAction !== -1, 'edit action should exist');
  assert.ok(disableAction !== -1, 'disable action should exist');
  assert.ok(deleteAction !== -1, 'delete action should exist');
  assert.ok(editAction < disableAction, 'edit action should precede publish state actions');
  assert.ok(disableAction < deleteAction, 'publish state actions should precede delete');
});

test('public download cards prefer edited release notes over shared app descriptions', () => {
  const page = readRepoFile('public/download/index.html');

  assert.match(
    page,
    /pkg\.release_notes\s*\|\|\s*app\.description\s*\|\|/
  );
});
