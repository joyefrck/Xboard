const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function loadAdminLocale(locale) {
  const context = { window: {} };
  vm.runInNewContext(readRepoFile(`public/assets/admin/locales/${locale}.js`), context);
  return JSON.parse(JSON.stringify(context.window.XBOARD_TRANSLATIONS[locale]));
}

function getTicketTableSection(bundle) {
  const end = bundle.indexOf('function dg()');
  const start = bundle.lastIndexOf('cg=s=>', end);

  assert.notEqual(start, -1, 'ticket table column definition exists');
  assert.notEqual(end, -1, 'ticket table component exists');
  return bundle.slice(start, end);
}

test('ticket remarks use a nullable text column without changing time fields', () => {
  const migration = readRepoFile('database/migrations/2026_08_22_000001_add_remarks_to_v2_ticket_table.php');
  const model = readRepoFile('app/Models/Ticket.php');
  const resource = readRepoFile('app/Http/Resources/TicketResource.php');

  assert.match(migration, /text\('remarks'\)->nullable\(\)/);
  assert.match(migration, /dropColumn\('remarks'\)/);
  assert.match(model, /@property string\|null \$remarks/);
  assert.match(resource, /"created_at"\s*=>\s*\$this\['created_at'\]/);
  assert.match(resource, /"updated_at"\s*=>\s*\$this\['updated_at'\]/);
  assert.doesNotMatch(resource, /["']remarks["']/);
});

test('admin can update and clear remarks without moving the ticket in the queue', () => {
  const routes = readRepoFile('app/Http/Routes/V2/AdminRoute.php');
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/TicketController.php');

  assert.match(routes, /post\('\/remarks',\s*\[TicketController::class,\s*'updateRemarks'\]\)/);
  assert.match(controller, /function updateRemarks\(Request \$request\)/);
  assert.match(controller, /'remarks'\s*=>\s*'present\|nullable\|string\|max:1000'/);
  assert.match(controller, /trim\(\$request->input\('remarks'\)\)/);
  assert.match(controller, /\$remarks === '' \? null : \$remarks/);
  assert.match(controller, /\$ticket->timestamps = false/);
  assert.match(controller, /'remarks'\s*=>\s*\$ticket->remarks/);
  assert.match(controller, /latest\('updated_at'\)/);
});

test('admin ticket table replaces time columns with a remarks dialog', () => {
  const bundle = readRepoFile('public/assets/admin/assets/index.js');
  const ticketTable = getTicketTableSection(bundle);

  assert.doesNotMatch(ticketTable, /accessorKey:"updated_at"/);
  assert.doesNotMatch(ticketTable, /accessorKey:"created_at"/);
  assert.match(ticketTable, /accessorKey:"remarks"/);
  assert.match(bundle, /updateRemarks:s=>O\.post\(wa\+"\/ticket\/remarks",s\)/);
  assert.match(bundle, /remarks\.dialog_title/);
  assert.match(bundle, /remarks\.add/);
  assert.match(bundle, /remarks\.edit/);
  assert.match(bundle, /maxLength:1e3/);
  assert.match(bundle, /zt\.updateRemarks\(/);
  assert.match(bundle, /N\?\.created_at/);
});

test('ticket remarks translations are complete and list-only time labels are removed', () => {
  const expected = {
    'zh-CN': {
      column: '备注',
      add: '添加备注',
      edit: '编辑备注',
      title: '工单备注',
      placeholder: '请输入备注，最多 1000 个字符',
      success: '备注已保存'
    },
    'en-US': {
      column: 'Remarks',
      add: 'Add Remark',
      edit: 'Edit Remark',
      title: 'Ticket Remark',
      placeholder: 'Enter a remark, up to 1000 characters',
      success: 'Remark saved'
    },
    'ko-KR': {
      column: '비고',
      add: '비고 추가',
      edit: '비고 수정',
      title: '티켓 비고',
      placeholder: '비고를 입력하세요. 최대 1000자',
      success: '비고가 저장되었습니다'
    }
  };

  for (const [locale, translations] of Object.entries(expected)) {
    const ticket = loadAdminLocale(locale).ticket;

    assert.equal(ticket.columns.remarks, translations.column);
    assert.equal(ticket.remarks.add, translations.add);
    assert.equal(ticket.remarks.edit, translations.edit);
    assert.equal(ticket.remarks.dialog_title, translations.title);
    assert.equal(ticket.remarks.placeholder, translations.placeholder);
    assert.equal(ticket.remarks.save_success, translations.success);
    assert.equal(ticket.columns.updated_at, undefined);
    assert.equal(ticket.columns.created_at, undefined);
    assert.ok(ticket.detail.created_at, `${locale} keeps the detail creation-time label`);
  }
});
