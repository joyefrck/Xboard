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

test('admin ticket fetch validates, trims, and fuzzy-matches user email', () => {
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/TicketController.php');

  assert.match(controller, /'email'\s*=>\s*'sometimes\|nullable\|string\|max:255'/);
  assert.match(controller, /\$email\s*=\s*trim\(\(string\)\s*\$request->input\('email',\s*''\)\)/);
  assert.match(controller, /->when\(\$email !== '',\s*function \(\$query\) use \(\$email\)/);
  assert.match(controller, /whereHas\('user',[\s\S]*where\('email',\s*'like',\s*"%\{\$email\}%"\)/);
  assert.match(controller, /latest\('updated_at'\)/);
});

test('admin ticket table shows username immediately after the subject', () => {
  const bundle = readRepoFile('public/assets/admin/assets/index.js');
  const ticketTable = getTicketTableSection(bundle);
  const subjectIndex = ticketTable.indexOf('accessorKey:"subject"');
  const usernameIndex = ticketTable.indexOf('id:"email"');
  const levelIndex = ticketTable.indexOf('accessorKey:"level"');

  assert.ok(subjectIndex >= 0, 'subject column exists');
  assert.ok(usernameIndex > subjectIndex, 'username column follows subject');
  assert.ok(levelIndex > usernameIndex, 'username column precedes appeal type');
  assert.match(ticketTable, /title:n\("columns\.username"\)/);
  assert.match(ticketTable, /t\.original\.user\?\.email\?\?"-"/);
  assert.match(ticketTable, /id:"email"[\s\S]*?enableSorting:!1/);
});

test('email search spans ticket statuses and clearing it restores the selected tab', () => {
  const bundle = readRepoFile('public/assets/admin/assets/index.js');

  assert.match(bundle, /function Yp\(\{table:s,emailKeyword:t,onEmailKeywordChange:l\}\)/);
  assert.match(bundle, /placeholder:n\("filter\.email_placeholder"\),value:t,onChange:a=>l\(a\.target\.value\)/);
  assert.match(bundle, /p=E\.trim\(\),g=p!==""\?a\.filter\(v=>v\.id!=="status"\):a/);
  assert.match(bundle, /queryKey:\["orderList",x,a,r,p\]/);
  assert.match(bundle, /filter:g,sort:r,\.\.\.p\?\{email:p\}:\{\}/);
  assert.match(bundle, /f=v=>\{T\(v\),u\(w=>\(\{\.\.\.w,pageIndex:0\}\)\)\}/);
  assert.match(bundle, /e\.jsx\(Yp,\{table:h,emailKeyword:E,onEmailKeywordChange:f\}\)/);
});

test('ticket username search translations are complete', () => {
  const expected = {
    'zh-CN': ['用户名', '按用户名搜索（用户邮箱）'],
    'en-US': ['Username', 'Search by username (user email)'],
    'ko-KR': ['사용자 이름', '사용자 이름으로 검색 (사용자 이메일)']
  };

  for (const [locale, [column, placeholder]] of Object.entries(expected)) {
    const ticket = loadAdminLocale(locale).ticket;

    assert.equal(ticket.columns.username, column);
    assert.equal(ticket.filter.email_placeholder, placeholder);
  }
});
