const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const zlib = require('node:zlib');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function loadAdminLocale(locale) {
  const context = { window: {} };
  vm.runInNewContext(readRepoFile(`public/assets/admin/locales/${locale}.js`), context);
  return JSON.parse(JSON.stringify(context.window.XBOARD_TRANSLATIONS[locale]));
}

test('ticket appeal types use a stable backend numeric contract', () => {
  const model = readRepoFile('app/Models/Ticket.php');
  const request = readRepoFile('app/Http/Requests/User/TicketSave.php');
  const controller = readRepoFile('app/Http/Controllers/V1/User/TicketController.php');

  assert.match(model, /TYPE_NODE_ISSUE\s*=\s*0/);
  assert.match(model, /TYPE_OTHER\s*=\s*1/);
  assert.match(model, /TYPE_REFUND\s*=\s*self::TYPE_OTHER/);
  assert.match(model, /TYPE_USAGE_GUIDE\s*=\s*2/);
  assert.match(model, /TYPE_COMMISSION_WITHDRAWAL\s*=\s*3/);
  assert.match(model, /MANUAL_TYPES\s*=\s*\[\s*self::TYPE_NODE_ISSUE,\s*self::TYPE_OTHER,\s*self::TYPE_USAGE_GUIDE\s*\]/s);
  assert.match(request, /Rule::in\(Ticket::MANUAL_TYPES\)/);
  assert.match(controller, /Ticket::TYPE_COMMISSION_WITHDRAWAL/);
  assert.doesNotMatch(controller, /\$subject,\s*2,\s*\$message/s);
});

test('ticket appeal migration clears legacy priorities and preserves withdrawal identity', () => {
  const baseMigration = readRepoFile('database/migrations/2023_03_19_000000_create_v2_tables.php');
  const migration = readRepoFile('database/migrations/2026_08_19_000001_convert_ticket_level_to_appeal_type.php');

  assert.match(baseMigration, /\$table->integer\('level'\)->nullable\(\)/);
  assert.match(migration, /\$table->integer\('level'\)->nullable\(\)->change\(\)/);
  assert.match(migration, /DB::table\('v2_ticket'\)->update\(\['level' => null\]\)/);
  assert.match(migration, /\[提现申请\] 本工单由系统发出/);
  assert.match(migration, /\[提現申請\] 本工單由系統發出/);
  assert.match(migration, /\[Commission Withdrawal Request\] This ticket is opened by the system/);
  assert.match(migration, /whereIn\('subject', \$withdrawalSubjects\)/);
  assert.match(migration, /update\(\['level' => 3\]\)/);
  assert.match(migration, /where\('level', 3\).*update\(\['level' => 2\]\)/s);
  assert.match(migration, /whereNull\('level'\).*update\(\['level' => 0\]\)/s);
});

test('manual ticket validation reports appeal-type errors in API locales', () => {
  const request = readRepoFile('app/Http/Requests/User/TicketSave.php');
  assert.match(request, /Ticket appeal type cannot be empty/);
  assert.match(request, /Incorrect ticket appeal type format/);

  const locales = {
    'zh-CN': ['申诉类型不能为空', '申诉类型参数有误'],
    'zh-TW': ['申訴類型不能為空', '申訴類型參數有誤'],
    'en-US': ['Ticket appeal type cannot be empty', 'Incorrect ticket appeal type format']
  };

  for (const [locale, expected] of Object.entries(locales)) {
    const messages = JSON.parse(readRepoFile(`resources/lang/${locale}.json`));
    assert.equal(messages['Ticket appeal type cannot be empty'], expected[0]);
    assert.equal(messages['Incorrect ticket appeal type format'], expected[1]);
  }
});

test('ElephantRoute exposes three manual types and renders system and legacy values safely', () => {
  const themeBundle = readRepoFile('theme/ElephantRoute/assets/umi.js');
  const publicBundle = readRepoFile('public/theme/ElephantRoute/assets/umi.js');
  const gzipBundle = zlib.gunzipSync(fs.readFileSync(path.join(repoRoot, 'theme/ElephantRoute/assets/umi.js.gz'))).toString('utf8');
  const brotliBundle = zlib.brotliDecompressSync(fs.readFileSync(path.join(repoRoot, 'theme/ElephantRoute/assets/umi.js.br'))).toString('utf8');
  const themeOverride = readRepoFile('theme/ElephantRoute/assets/elephant-route-dashboard.js');
  const publicOverride = readRepoFile('public/theme/ElephantRoute/assets/elephant-route-dashboard.js');

  assert.equal(publicBundle, themeBundle);
  assert.equal(gzipBundle, themeBundle);
  assert.equal(brotliBundle, themeBundle);
  assert.equal(publicOverride, themeOverride);
  assert.match(themeBundle, /n=\[\{label:"节点问题",value:0\},\{label:"其他",value:1\},\{label:"使用方法",value:2\},\{label:"推广佣金提现",value:3\}\]/);
  assert.doesNotMatch(themeBundle, /\{label:"退款",value:1\}/);
  assert.match(themeBundle, /render\(h\)\{return n\[h\.level\]\?n\[h\.level\]\.label:""\}/);
  assert.match(themeBundle, /options:n\.slice\(0,3\)/);
  assert.match(themeOverride, /\['工单级别', '申诉类型'\]/);
  assert.match(themeOverride, /\['工单等级', '申诉类型'\]/);
  assert.match(themeOverride, /\['请选项工单等级', '请选择申诉类型'\]/);
});

test('admin ticket UI maps all stored types, filters manual types, and leaves legacy null blank', () => {
  const bundle = readRepoFile('public/assets/admin/assets/index.js');
  const helperDefinition = /xboardTicketTypeKey=s=>s===js\.LOW\?"low":s===js\.MIDDLE\?"medium":s===js\.HIGH\?"high":s===3\?"withdrawal":null/;

  assert.match(bundle, helperDefinition);
  assert.ok((bundle.match(/xboardTicketTypeKey\(/g) || []).length >= 3);
  assert.match(bundle, /options:\[\{label:n\("level\.low"\),value:js\.LOW,icon:Wp,color:"gray"\},\{label:n\("level\.medium"\),value:js\.MIDDLE,icon:Ki,color:"yellow"\},\{label:n\("level\.high"\),value:js\.HIGH,icon:Bi,color:"red"\}\]\}/);
  assert.doesNotMatch(bundle, /options:\[[^\]]*label:n\("level\.withdrawal"\),value:3/);
  assert.match(bundle, /==null\?"":/);

  const expected = {
    'zh-CN': ['申诉类型', '节点问题', '其他', '使用方法', '推广佣金提现'],
    'en-US': ['Appeal Type', 'Node Issue', 'Other', 'Usage Guide', 'Promotion Commission Withdrawal'],
    'ko-KR': ['이의 신청 유형', '노드 문제', '기타', '사용 방법', '프로모션 수수료 출금']
  };

  for (const [locale, labels] of Object.entries(expected)) {
    const ticket = loadAdminLocale(locale).ticket;
    assert.deepEqual(
      [ticket.columns.level, ticket.level.low, ticket.level.medium, ticket.level.high, ticket.level.withdrawal],
      labels
    );
  }
});
