const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const test = require('node:test');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const bundle = fs.readFileSync(path.join(root, 'public/assets/admin/assets/index.js'), 'utf8');
const context = vm.createContext({
  Date,
  Ks: (date, count) => { const result = new Date(date); result.setDate(result.getDate() - count); return result; },
});
vm.runInContext('const ' + bundle.slice(bundle.indexOf('Km=['), bundle.indexOf('function Gm()')) +
  ';globalThis.helpers={Bm,xboardIncomeDefault,xboardIncomePresets,xboardIncomeDateLabel,xboardIncomeChangeBoundary};', context);
const h = context.helpers;
const date = value => new Date(value + 'T12:00:00');
const day = value => `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;

test('period defaults and preset units follow the selected period', () => {
  assert.equal(h.xboardIncomeDefault('day'), '30d');
  assert.equal(h.xboardIncomeDefault('month'), '12m');
  assert.equal(h.xboardIncomeDefault('year'), '5y');
  for (const [period, expected] of [['day', ['7d', '30d', '90d', '180d', '365d', 'custom']], ['month', ['3m', '6m', '12m', '24m', 'custom']], ['year', ['3y', '5y', '10y', 'custom']]]) {
    assert.deepEqual(Array.from(h.xboardIncomePresets(period), item => item.value), expected);
  }
});

test('monthly presets include exactly N calendar months across leap years and month ends', () => {
  let range = h.Bm('12m', null, 'month', date('2024-02-29'));
  assert.equal(day(range.startDate), '2023-03-01');
  assert.equal(day(range.endDate), '2024-02-29');
  range = h.Bm('3m', null, 'month', date('2024-03-31'));
  assert.equal(day(range.startDate), '2024-01-01');
  assert.equal(day(range.endDate), '2024-03-31');
  range = h.Bm('5y', null, 'year', date('2024-02-29'));
  assert.equal(day(range.startDate), '2020-01-01');
  assert.equal(day(range.endDate), '2024-12-31');
});

test('daily default preserves the existing date range', () => {
  const range = h.Bm('30d', null, 'day', date('2026-09-18'));
  assert.equal(day(range.startDate), '2026-08-19');
  assert.equal(day(range.endDate), '2026-09-18');
});

test('custom month and year ranges expand to full calendar periods', () => {
  const custom = { from: date('2023-12-20'), to: date('2024-02-05') };
  const month = h.Bm('custom', custom, 'month');
  assert.equal(day(month.startDate), '2023-12-01');
  assert.equal(day(month.endDate), '2024-02-29');
  const year = h.Bm('custom', custom, 'year');
  assert.equal(day(year.startDate), '2023-01-01');
  assert.equal(day(year.endDate), '2024-12-31');
});

test('custom selectors keep chronological boundaries and ignore incomplete input', () => {
  const range = { from: date('2024-01-01'), to: date('2024-03-31') };
  const changed = h.xboardIncomeChangeBoundary(range, 'from', '2024-05', 'month');
  assert.equal(day(changed.from), '2024-05-01');
  assert.equal(day(changed.to), '2024-05-01');
  const previous = h.xboardIncomeChangeBoundary(range, 'to', '2022', 'year');
  assert.equal(day(previous.from), '2022-01-01');
  assert.equal(day(previous.to), '2022-01-01');
  for (const value of ['', '202', '2024-13', '0001']) {
    assert.equal(h.xboardIncomeChangeBoundary(range, 'from', value, 'month'), range);
  }
});

test('axis labels do not parse ISO strings as UTC dates', () => {
  assert.equal(h.xboardIncomeDateLabel('2024-01-01', 'day', true), '01-01');
  assert.equal(h.xboardIncomeDateLabel('2024-01', 'month', true), '2024-01');
  assert.equal(h.xboardIncomeDateLabel('2024', 'year', true), '2024');
});

test('component includes period in query cache and API parameters, and changes its custom picker', () => {
  const component = bundle.slice(bundle.indexOf('function Gm()'), bundle.indexOf('function we('));
  assert.equal((component.match(/period,start_date:/g) || []).length, 2);
  assert.match(component, /\[period,setPeriod\]=m.useState\("day"\)/);
  assert.match(component, /setPeriod\(value\);l\(preset\);i\(/);
  assert.match(component, /period!=="day"&&e.jsx\(xboardIncomePeriodRange/);
  assert.match(component, /period==="day"&&e.jsxs\(ls/);
});

test('all admin locales translate period controls and plural preset labels', () => {
  for (const locale of ['zh-CN', 'en-US', 'ko-KR']) {
    const ctx = { window: {} };
    vm.runInNewContext(fs.readFileSync(path.join(root, `public/assets/admin/locales/${locale}.js`), 'utf8'), ctx);
    const translations = ctx.window.XBOARD_TRANSLATIONS[locale].dashboard.overview;
    for (const key of ['statisticPeriod', 'period_day', 'period_month', 'period_year', 'lastMonths', 'lastYears', 'startPeriod', 'endPeriod']) {
      assert.ok(translations[key], `${locale} ${key}`);
    }
  }
});

test('real getOrder API aggregates daily records in SQLite', () => {
  const result = spawnSync('php', [path.join(__dirname, 'admin-income-period.php')], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /Income period API regression passed/);
});
