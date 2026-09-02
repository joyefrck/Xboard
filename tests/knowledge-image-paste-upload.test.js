const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('admin knowledge image upload route is scoped under the knowledge admin routes', () => {
  const route = readRepoFile('app/Http/Routes/V2/AdminRoute.php');

  assert.match(route, /prefix'\s*=>\s*'knowledge'[\s\S]*post\('\/upload-image',\s*\[KnowledgeController::class,\s*'uploadImage'\]\)/);
});

test('knowledge image upload validates image type and stores random public files', () => {
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/KnowledgeController.php');

  assert.match(controller, /public function uploadImage\(Request \$request\)/);
  assert.match(controller, /'file'\s*=>\s*\[[\s\S]*'required'[\s\S]*'file'[\s\S]*'image'[\s\S]*'mimes:jpg,jpeg,png,gif,webp'[\s\S]*'max:5120'[\s\S]*\]/);
  assert.doesNotMatch(controller, /svg/);
  assert.match(controller, /Storage::disk\('public'\)/);
  assert.match(controller, /knowledge-images\/'\s*\.\s*now\(\)->format\('Y\/m'\)/);
  assert.match(controller, /Str::uuid\(\)/);
  assert.match(controller, /'url'\s*=>\s*'\/knowledge-images\/'\s*\.\s*\$publicPath/);
});

test('knowledge content normalizes legacy storage image URLs on admin and user reads', () => {
  const adminController = readRepoFile('app/Http/Controllers/V2/Admin/KnowledgeController.php');
  const userController = readRepoFile('app/Http/Controllers/V1/User/KnowledgeController.php');

  assert.match(adminController, /private function normalizeKnowledgeImageUrls\(string \$body\): string/);
  assert.match(adminController, /str_replace\('\/storage\/knowledge-images\/',\s*'\/knowledge-images\/',\s*\$body\)/);
  assert.match(adminController, /\$knowledge\['body'\]\s*=\s*\$this->normalizeKnowledgeImageUrls\(\$knowledge\['body'\]\)/);
  assert.match(adminController, /\$params\['body'\]\s*=\s*\$this->normalizeKnowledgeImageUrls\(\$params\['body'\]\)/);

  assert.match(userController, /private function normalizeKnowledgeImageUrls\(string \$body\): string/);
  assert.match(userController, /str_replace\('\/storage\/knowledge-images\/',\s*'\/knowledge-images\/',\s*\$body\)/);
  assert.match(userController, /\$knowledge\['body'\]\s*=\s*\$this->normalizeKnowledgeImageUrls\(\$knowledge\['body'\]\)/);
});

test('knowledge images have public web routes that do not depend on the storage symlink', () => {
  const webRoutes = readRepoFile('routes/web.php');

  assert.match(webRoutes, /use Illuminate\\Support\\Facades\\Storage;/);
  assert.match(webRoutes, /\$serveKnowledgeImage\s*=\s*function\s*\(string \$path\)/);
  assert.match(webRoutes, /Route::get\('\/knowledge-images\/\{path\}',\s*\$serveKnowledgeImage\)/);
  assert.match(webRoutes, /Route::get\('\/storage\/knowledge-images\/\{path\}',\s*\$serveKnowledgeImage\)/);
  assert.match(webRoutes, /where\('path',\s*'\.\*'\)/);
  assert.match(webRoutes, /Storage::disk\('public'\)/);
  assert.match(webRoutes, /\$storagePath\s*=\s*'knowledge-images\/'\s*\.\s*\$path/);
  assert.match(webRoutes, /preg_match\('[^']*jpe\?g[^']*png[^']*gif[^']*webp[^']*',\s*\$path\)/);
  assert.match(webRoutes, /Cache-Control.*max-age=31536000/);
  assert.match(webRoutes, /Content-Disposition.*inline/);
});

test('admin knowledge page keeps one shared compressed upload pipeline', () => {
  const blade = readRepoFile('resources/views/admin.blade.php');

  assert.match(blade, /function isKnowledgeRoute\(\)/);
  assert.match(blade, /\^#\\\/\?\(\?:config\\\/\)\?knowledge/);
  assert.match(blade, /\(\?:\[\\\/\?#\]\|\$\)/);
  assert.match(blade, /document\.addEventListener\("paste"/);
  assert.match(blade, /clipboardData\.items/);
  assert.match(blade, /function compressKnowledgeImage\(file\)/);
  assert.match(blade, /KNOWLEDGE_IMAGE_TARGET_BYTES\s*=\s*1024\s*\*\s*1024/);
  assert.match(blade, /KNOWLEDGE_IMAGE_WEBP_QUALITIES\s*=\s*\[0\.82,\s*0\.74,\s*0\.65\]/);
  assert.match(blade, /file\.type\s*===\s*"image\/gif"/);
  assert.match(blade, /createImageBitmap\(file\)/);
  assert.match(blade, /canvas\.toBlob\(resolve,\s*mimeType,\s*quality\)/);
  assert.match(blade, /function encodeKnowledgeImage\(canvas\)/);
  assert.match(blade, /KNOWLEDGE_IMAGE_WEBP_QUALITIES\.reduce/);
  assert.match(blade, /blob\.size\s*<=\s*KNOWLEDGE_IMAGE_TARGET_BYTES/);
  assert.match(blade, /targetBlob\s*\|\|\s*smallestBlob/);
  assert.match(blade, /canvas\.width\s*=\s*bitmap\.width/);
  assert.match(blade, /canvas\.height\s*=\s*bitmap\.height/);
  assert.match(blade, /drawImage\(bitmap,\s*0,\s*0,\s*bitmap\.width,\s*bitmap\.height\)/);
  assert.match(blade, /"image\/webp"/);
  assert.match(blade, /blob\.size\s*>=\s*file\.size/);
  assert.match(blade, /new File\(\[blob\]/);
  assert.match(blade, /compressKnowledgeImage\(file\)\.then\(function \(uploadFile\)/);
  assert.match(blade, /new FormData\(\)/);
  assert.match(blade, /formData\.append\("file",\s*uploadFile\)/);
  assert.match(blade, /\/knowledge\/upload-image/);
  assert.match(blade, /XBOARD_ACCESS_TOKEN/);
  assert.match(blade, /authorization/);
  assert.match(blade, /access_token/);
  assert.match(blade, /JSON\.parse\(token\)/);
  assert.match(blade, /parsedToken\.value/);
  assert.doesNotMatch(blade, /KNOWLEDGE_IMAGE_MAX_SIDE/);
  assert.doesNotMatch(blade, /var scale\s*=/);
  assert.doesNotMatch(blade, /Math\.round\(bitmap\.(?:width|height)\s*\*\s*scale\)/);
});

test('knowledge image toolbar uses one multiple image picker instead of inserting an empty markdown link', () => {
  const blade = readRepoFile('resources/views/admin.blade.php');

  assert.match(blade, /KNOWLEDGE_IMAGE_ACCEPT\s*=\s*"image\/jpeg,image\/png,image\/gif,image\/webp"/);
  assert.match(blade, /function createKnowledgeImagePicker\(\)/);
  assert.match(blade, /knowledgeImageInput\.type\s*=\s*"file"/);
  assert.match(blade, /knowledgeImageInput\.accept\s*=\s*KNOWLEDGE_IMAGE_ACCEPT/);
  assert.match(blade, /knowledgeImageInput\.multiple\s*=\s*true/);
  assert.match(blade, /document\.body\.appendChild\(knowledgeImageInput\)/);
  assert.match(blade, /closest\("\.button-type-image"\)/);
  assert.match(blade, /event\.stopImmediatePropagation\(\)/);
  assert.match(blade, /knowledgeImageInput\.click\(\)/);
  assert.match(blade, /knowledgeImageInput\.addEventListener\("change"/);
  assert.doesNotMatch(blade, /!\[\]\(\)/);
});

test('knowledge toolbar, paste, and drop events share the same scoped upload queue', () => {
  const blade = readRepoFile('resources/views/admin.blade.php');

  assert.match(blade, /function getKnowledgeEditorContext\(target\)/);
  assert.match(blade, /closest\("\.rc-md-editor"\)/);
  assert.match(blade, /querySelector\("\.sec-md textarea"\)/);
  assert.match(blade, /function queueKnowledgeImages\(files, context, savedSelection\)/);
  assert.match(blade, /document\.addEventListener\("click"/);
  assert.match(blade, /document\.addEventListener\("paste"/);
  assert.match(blade, /document\.addEventListener\("dragover"/);
  assert.match(blade, /document\.addEventListener\("dragleave"/);
  assert.match(blade, /document\.addEventListener\("drop"/);
  assert.match(blade, /queueKnowledgeImages\(files, context, savedSelection\)/);
  assert.match(blade, /xboard-knowledge-image-dragover/);
  assert.match(blade, /xboard-knowledge-image-uploading/);
  assert.equal((blade.match(/knowledgeImageInput\.addEventListener\("change"/g) || []).length, 1);
  assert.equal((blade.match(/document\.addEventListener\("paste"/g) || []).length, 1);
  assert.equal((blade.match(/document\.addEventListener\("dragover"/g) || []).length, 1);
  assert.equal((blade.match(/document\.addEventListener\("dragleave"/g) || []).length, 1);
  assert.equal((blade.match(/document\.addEventListener\("drop"/g) || []).length, 1);
});

test('knowledge image queue preserves order and reports failed files without inserting empty markdown', () => {
  const blade = readRepoFile('resources/views/admin.blade.php');

  assert.match(blade, /acceptedFiles\.reduce\(function \(chain, file, index\)/);
  assert.match(blade, /failedFiles\.push\(\{/);
  assert.match(blade, /notifyKnowledgeUploadFailures\(failedFiles\)/);
  assert.match(blade, /!\[\$\{altText\}\]\(\$\{url\}\)/);
  assert.match(blade, /setRangeText/);
  assert.match(blade, /execCommand\("insertText"/);
  assert.doesNotMatch(blade, /var markdown\s*=\s*`!\[\$\{altText\}\]\(\$\{url\}\)`[^;]*;\s*insertMarkdown\([^;]+\);\s*}\);\s*}\);\s*}, Promise\.resolve\(\)\)\.catch/);
});
