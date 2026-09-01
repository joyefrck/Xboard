const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');
const vm = require('node:vm');
const zlib = require('node:zlib');

const repoRoot = path.resolve(__dirname, '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function loadAdminLocale(locale) {
  const context = { window: {} };
  vm.runInNewContext(read(`public/assets/admin/locales/${locale}.js`), context);
  return JSON.parse(JSON.stringify(context.window.XBOARD_TRANSLATIONS[locale]));
}

test('ticket attachments use a dedicated indexed metadata table', () => {
  const migration = read('database/migrations/2026_09_01_000001_create_ticket_message_attachments_table.php');
  const model = read('app/Models/TicketMessageAttachment.php');
  const message = read('app/Models/TicketMessage.php');

  assert.match(migration, /create\('v2_ticket_message_attachment'/);
  assert.match(migration, /integer\('ticket_message_id'\)->index\(\)/);
  assert.match(migration, /string\('original_name',\s*255\)/);
  assert.match(migration, /string\('disk',\s*32\)/);
  assert.match(migration, /string\('path',\s*512\)/);
  assert.match(migration, /string\('mime_type',\s*64\)/);
  assert.match(migration, /unsignedInteger\('size'\)/);
  assert.match(migration, /dropIfExists\('v2_ticket_message_attachment'\)/);
  assert.match(model, /class TicketMessageAttachment extends Model/);
  assert.match(model, /protected \$hidden\s*=\s*\[[\s\S]*['"]disk['"][\s\S]*['"]path['"]/);
  assert.match(model, /protected \$appends\s*=\s*\[['"]name['"]\]/);
  assert.match(message, /function attachments\(\): HasMany/);
});

test('ticket message resources expose safe attachment metadata only', () => {
  const resource = read('app/Http/Resources/MessageResource.php');
  const attachmentResource = read('app/Http/Resources/TicketMessageAttachmentResource.php');

  assert.match(resource, /TicketMessageAttachmentResource::collection\(\$this->attachments\)/);
  assert.match(attachmentResource, /['"]id['"]\s*=>\s*\$this\[['"]id['"]\]/);
  assert.match(attachmentResource, /['"]name['"]\s*=>\s*\$this\[['"]original_name['"]\]/);
  assert.match(attachmentResource, /['"]mime_type['"]\s*=>\s*\$this\[['"]mime_type['"]\]/);
  assert.match(attachmentResource, /['"]size['"]\s*=>\s*\$this\[['"]size['"]\]/);
  assert.doesNotMatch(attachmentResource, /['"](?:disk|path)['"]\s*=>/);
});

test('ticket messages persist their sender role independently from account ids', () => {
  const migration = read('database/migrations/2026_09_01_000002_add_is_admin_to_ticket_messages_table.php');
  const message = read('app/Models/TicketMessage.php');
  const service = read('app/Services/TicketService.php');

  assert.match(migration, /boolean\('is_admin'\)->default\(false\)/);
  assert.match(migration, /whereColumn\('v2_ticket_message\.user_id',\s*'!=',\s*'v2_ticket\.user_id'\)/);
  assert.match(migration, /dropColumn\('is_admin'\)/);
  assert.match(message, /['"]is_admin['"]\s*=>\s*['"]boolean['"]/);
  assert.match(message, /getIsFromUserAttribute\(\): bool[\s\S]*return !\$this->is_admin/);
  assert.match(message, /getIsFromAdminAttribute\(\): bool[\s\S]*return \$this->is_admin/);
  assert.match(service, /function reply\([\s\S]*['"]is_admin['"]\s*=>\s*false/);
  assert.match(service, /function replyByAdmin\([\s\S]*['"]is_admin['"]\s*=>\s*true/);
  assert.match(service, /function createTicket\([\s\S]*['"]is_admin['"]\s*=>\s*false/);
  assert.doesNotMatch(service, /\$userId\s*!==\s*\$ticket->user_id/);
});

test('user and admin ticket requests enforce the same image limits', () => {
  const request = read('app/Http/Requests/User/TicketSave.php');
  const userController = read('app/Http/Controllers/V1/User/TicketController.php');
  const adminController = read('app/Http/Controllers/V2/Admin/TicketController.php');

  for (const source of [request, userController, adminController]) {
    assert.match(source, /['"]attachments['"]\s*=>\s*['"](?:sometimes\|array\|max:3|nullable\|array\|max:3)['"]/);
    assert.match(source, /['"]attachments\.\*['"]\s*=>\s*\[[\s\S]*['"]file['"][\s\S]*['"]image['"][\s\S]*['"]mimes:jpg,jpeg,png,webp['"][\s\S]*['"]max:1024['"]/);
  }

  assert.match(userController, /\$request->file\('attachments',\s*\[\]\)/);
  assert.match(adminController, /\$request->file\('attachments',\s*\[\]\)/);
  assert.match(userController, /['"]message['"]\s*=>\s*['"]required\|string['"]/);
  assert.match(adminController, /['"]message['"]\s*=>\s*['"]required\|string['"]/);
});

test('user ticket replies can be sent consecutively with per-message attachment limits', () => {
  const userController = read('app/Http/Controllers/V1/User/TicketController.php');

  assert.doesNotMatch(userController, /Please wait for the technical enginneer to reply/);
  assert.doesNotMatch(userController, /getLastMessage/);
  assert.match(userController, /where\('user_id',\s*\$request->user\(\)->id\)/);
  assert.match(userController, /if \(\$ticket->status\)[\s\S]*The ticket is closed and cannot be replied/);
  assert.match(
    userController,
    /\$ticketService->reply\([\s\S]*\$request->input\('message'\)[\s\S]*\$request->file\('attachments',\s*\[\]\)/
  );
});

test('ticket service persists files transactionally and keeps Telegram calls compatible', () => {
  const service = read('app/Services/TicketService.php');
  const storage = read('app/Services/TicketAttachmentService.php');
  const telegram = read('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(service, /function createTicket\([^)]*array \$attachments = \[\]/s);
  assert.match(service, /function reply\([^)]*array \$attachments = \[\]/s);
  assert.match(service, /function replyByAdmin\([^)]*array \$attachments = \[\]/s);
  assert.match(service, /storeForMessage\(\$ticketMessage,\s*\$attachments\)/);
  assert.match(service, /cleanupStoredFiles\(\$storedAttachments\)/);
  assert.match(storage, /ticket-attachments\/['"]\s*\.\s*now\(\)->format\(['"]Y\/m['"]\)/);
  assert.match(storage, /Str::uuid\(\)/);
  assert.match(storage, /preg_replace\(['"]\/\[\\x00-\\x1F\\x7F\]\/u['"]/);
  assert.match(storage, /mb_substr\(\$name,\s*0,\s*255\)/);
  assert.match(storage, /Storage::disk\(self::DISK\)->put/);
  assert.match(storage, /cleanupStoredFiles/);
  assert.match(telegram, /replyByAdmin\(\$ticketId,\s*\$message,\s*\$operator->id\)/);
});

test('attachment reads are authenticated and never expose storage paths', () => {
  const userRoutes = read('app/Http/Routes/V1/UserRoute.php');
  const adminRoutes = read('app/Http/Routes/V2/AdminRoute.php');
  const userController = read('app/Http/Controllers/V1/User/TicketController.php');
  const adminController = read('app/Http/Controllers/V2/Admin/TicketController.php');

  assert.match(userRoutes, /get\('\/ticket\/attachment\/\{attachment\}'/);
  assert.match(adminRoutes, /get\('\/attachment\/\{attachment\}'/);
  assert.match(userController, /whereHas\('message\.ticket',[\s\S]*user_id/);
  assert.match(userController, /abort\(404\)/);
  assert.match(adminController, /TicketMessageAttachment::find\(\$attachment\)/);

  const storage = read('app/Services/TicketAttachmentService.php');
  assert.match(storage, /Content-Disposition['"]\s*=>\s*\$disposition/);
  assert.match(storage, /\$disposition\s*=\s*['"]inline;/);
  assert.match(storage, /Cache-Control['"]\s*=>\s*['"]private, no-store/);
  assert.match(storage, /X-Content-Type-Options['"]\s*=>\s*['"]nosniff/);
});

test('ElephantRoute ticket UI uploads and opens authenticated attachment links', () => {
  const theme = read('theme/ElephantRoute/assets/umi.js');
  const publicTheme = read('public/theme/ElephantRoute/assets/umi.js');
  const gzipTheme = zlib.gunzipSync(fs.readFileSync(path.join(repoRoot, 'theme/ElephantRoute/assets/umi.js.gz'))).toString('utf8');
  const brotliTheme = zlib.brotliDecompressSync(fs.readFileSync(path.join(repoRoot, 'theme/ElephantRoute/assets/umi.js.br'))).toString('utf8');

  assert.match(theme, /xboardValidateTicketFiles/);
  assert.match(theme, /xboardTicketFormData/);
  assert.match(theme, /xboardOpenTicketAttachment/);
  assert.match(theme, /attachments\[\]/);
  assert.match(theme, /accept:"image\/jpeg,image\/png,image\/webp"/);
  assert.match(theme, /multiple:""/);
  assert.match(theme, /\/user\/ticket\/attachment\//);
  assert.match(theme, /单张不超过 1MB/);
  assert.doesNotMatch(theme, /height:"calc\(100% - 70px\)"/);
  assert.match(theme, /W9e=\{class:"relative min-h-0 flex-1"\}/);
  assert.match(theme, /class:"flex h-full min-h-0 flex-col"/);
  assert.match(theme, /class:"shrink-0 pt-4"/);
  assert.equal(publicTheme, theme);
  assert.equal(gzipTheme, theme);
  assert.equal(brotliTheme, theme);
});

test('ElephantRoute ticket messages use role-based sides, labels and colors', () => {
  const theme = read('theme/ElephantRoute/assets/umi.js');

  assert.match(theme, /xboardTicketSenderLabel=e=>e\?"您":"管理员"/);
  assert.match(theme, /xboardTicketMessageRowStyle=e=>\(\{display:"flex",justifyContent:e\?"flex-end":"flex-start",marginBottom:"10px"\}\)/);
  assert.match(theme, /xboardTicketMessageBubbleStyle=e=>/);
  assert.match(theme, /background:e\?"#E8F8F5":"#EEF4FF"/);
  assert.match(theme, /border:e\?"1px solid #BFE9DF":"1px solid #D8E5FF"/);
  assert.match(theme, /ref_key:"scrollContainerRef",ref:c,style:\{paddingRight:"18px",boxSizing:"border-box"\}/);
  assert.match(theme, /xboardTicketSenderLabel\(x\.is_me\)/);
  assert.match(theme, /xboardRenderTicketAttachmentLinks\(x\.attachments\)/);
});

test('ElephantRoute ticket polling is single-instance and stops after leaving details', () => {
  const theme = read('theme/ElephantRoute/assets/umi.js');

  assert.match(
    theme,
    /async function f\(\)\{d\.value&&\(clearInterval\(d\.value\),d\.value=null\),await s\(\),await Ht\(\),u\(\),d\.value=setInterval\(s,2e3\)\}/
  );
  assert.match(theme, /La\(\(\)=>\{d\.value&&\(clearInterval\(d\.value\),d\.value=null\)\}\)/);
  assert.equal((theme.match(/setInterval\(s,2e3\)/g) || []).length, 1);
});

test('admin ticket UI uploads, lists and opens authenticated attachments', () => {
  const bundle = read('public/assets/admin/assets/index.js');

  assert.match(bundle, /xboardAdminValidateTicketFiles/);
  assert.match(bundle, /xboardAdminTicketFormData/);
  assert.match(bundle, /xboardAdminOpenTicketAttachment/);
  assert.match(bundle, /attachments\[\]/);
  assert.match(bundle, /accept:"image\/jpeg,image\/png,image\/webp"/);
  assert.match(bundle, /\/ticket\/attachment\//);
  assert.match(bundle, /attachments\.hint/);
  assert.match(bundle, /attachments\.add/);
  assert.match(bundle, /attachments\.open_failed/);
  assert.match(bundle, /responseType:"arraybuffer"/);
  assert.match(bundle, /xboardAdminPrepareTicketAttachmentWindow/);
  assert.match(bundle, /new Blob\(\[a\],\{type:t\}\)/);
  assert.match(bundle, /i\.src=r/);
  assert.doesNotMatch(bundle, /t\.location\.href=i/);
  assert.match(
    bundle,
    /style:\{color:"#2563eb",fontSize:"14px",textDecoration:"underline",textUnderlineOffset:"3px"\}/
  );
});

test('admin ticket messages keep users right and administrators left with matching colors', () => {
  const bundle = read('public/assets/admin/assets/index.js');

  assert.match(bundle, /xboardAdminTicketMessageRowClass=s=>s\?"flex justify-start":"flex justify-end"/);
  assert.match(bundle, /xboardAdminTicketMessageBubbleStyle=s=>\(\{background:s\?"#EEF4FF":"#E8F8F5"/);
  assert.match(bundle, /border:s\?"1px solid #D8E5FF":"1px solid #BFE9DF"/);
  assert.match(bundle, /de\.is_from_admin\?t\("detail\.sender_admin"\):t\("detail\.sender_user"\)/);
  assert.match(bundle, /xboardAdminTicketAttachmentLinks,\{attachments:de\.attachments\}/);
});

test('admin ticket attachment translations are complete', () => {
  const expected = {
    'zh-CN': ['添加图片', '已选 {{count}}/3，单张不超过 1MB', '附件打开失败'],
    'en-US': ['Add images', '{{count}}/3 selected, max 1 MB each', 'Failed to open attachment'],
    'ko-KR': ['이미지 추가', '{{count}}/3개 선택됨, 파일당 최대 1MB', '첨부 파일을 열 수 없습니다']
  };

  for (const [locale, values] of Object.entries(expected)) {
    const attachments = loadAdminLocale(locale).ticket.attachments;
    assert.equal(attachments.add, values[0]);
    assert.equal(attachments.hint, values[1]);
    assert.equal(attachments.open_failed, values[2]);
  }
});

test('admin ticket sender translations are complete', () => {
  const expected = {
    'zh-CN': ['用户', '管理员'],
    'en-US': ['User', 'Administrator'],
    'ko-KR': ['사용자', '관리자']
  };

  for (const [locale, values] of Object.entries(expected)) {
    const detail = loadAdminLocale(locale).ticket.detail;
    assert.equal(detail.sender_user, values[0]);
    assert.equal(detail.sender_admin, values[1]);
  }
});

test('ticket attachment storage enforces the byte boundary and rollback cleanup at runtime', () => {
  const result = spawnSync('php', [path.join(repoRoot, 'tests/ticket-image-attachments.php')], {
    cwd: repoRoot,
    encoding: 'utf8'
  });

  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /runtime test passed/);
});
