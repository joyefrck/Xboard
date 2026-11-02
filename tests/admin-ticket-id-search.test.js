const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const bundle = fs.readFileSync(path.join(root, 'public/assets/admin/assets/index.js'), 'utf8');
const table = bundle.slice(bundle.indexOf('function dg()'), bundle.indexOf('function mg()'));
const toolbar = bundle.slice(bundle.indexOf('function Yp('), bundle.indexOf('function Jp()'));
const jsx = (type, props) => ({ type, props });

function setup() {
  const states = [];
  let index = 0;
  let query, options, payload;
  const context = {
    e: { jsx, jsxs: jsx },
    m: { useState(initial) {
      const slot = index++;
      if (!(slot in states)) states[slot] = initial;
      return [states[slot], value => { states[slot] = typeof value === 'function' ? value(states[slot]) : value; }];
    } },
    M: () => ({ t: key => key }),
    oe: value => { query = value; value.queryFn(); return { refetch() {}, data: { data: [], total: 0 } }; },
    zt: { getList: value => { payload = JSON.parse(JSON.stringify(value)); } },
    es: value => { options = value; return { getColumn: () => ({ getFilterValue: () => '0' }) }; },
    cg: () => [], ds: 'Table', k: 'Input', Dt: 'Tabs', xt: 'TabList', Xe: 'Tab', Wa: 'Filter',
    Wp: 'Low', Ki: 'Medium', Bi: 'High', js: { LOW: 0, MIDDLE: 1, HIGH: 2 },
    ...Object.fromEntries(['ss', 'ws', 'is', 'Ns', 'zs', 'Os'].map(name => [name, () => () => {}]))
  };
  vm.createContext(context);
  vm.runInContext(`${toolbar}${table}`, context);
  return {
    render() { index = 0; return context.dg().props.children[0].props; },
    toolbar: props => context.Yp(props),
    get payload() { return payload; },
    get queryKey() { return JSON.parse(JSON.stringify(query.queryKey)); },
    get options() { return options; }
  };
}

function nodes(tree) {
  if (Array.isArray(tree)) return tree.flatMap(nodes);
  return tree && typeof tree === 'object' ? [tree, ...nodes(tree.props?.children)] : [];
}

test('ticket number searches across statuses, resets pagination and restores selected status when cleared', () => {
  const app = setup();
  let props = app.render();
  app.options.onPaginationChange({ pageIndex: 4, pageSize: 20 });
  app.options.onColumnFiltersChange([{ id: 'status', value: '1' }, { id: 'level', value: ['2'] }]);
  props = app.render();
  props.onTicketIdKeywordChange('3145');
  props = app.render();
  assert.equal(app.payload.ticket_id, '3145');
  assert.equal(app.payload.id, undefined, 'list search must not invoke the detail endpoint');
  assert.equal(app.payload.current, 1);
  assert.deepEqual(app.payload.filter, [{ id: 'level', value: ['2'] }]);
  assert.ok(app.queryKey.includes('3145'), 'query cache includes the ticket number');
  props.onTicketIdKeywordChange('');
  app.render();
  assert.equal(app.payload.ticket_id, undefined);
  assert.deepEqual(app.payload.filter, [{ id: 'status', value: '1' }, { id: 'level', value: ['2'] }]);
});

test('ticket number and email filters combine and retain cross-status search until both are cleared', () => {
  const app = setup();
  let props = app.render();
  props.onEmailKeywordChange(' owner@example.test ');
  props.onTicketIdKeywordChange('60');
  props = app.render();
  assert.equal(app.payload.email, 'owner@example.test');
  assert.equal(app.payload.ticket_id, '60');
  props.onTicketIdKeywordChange('');
  props = app.render();
  assert.deepEqual(app.payload.filter, []);
  props.onEmailKeywordChange('');
  app.render();
  assert.deepEqual(app.payload.filter, [{ id: 'status', value: '0' }]);
});

test('toolbar exposes an accessible ticket number input wired to search state', () => {
  const app = setup();
  let props = app.render();
  const input = nodes(app.toolbar(props)).find(node => node.type === 'Input' && node.props.placeholder === 'filter.ticket_id_placeholder');
  assert.ok(input);
  assert.equal(input.props['aria-label'], 'filter.ticket_id_placeholder');
  assert.equal(input.props.inputMode, 'numeric');
  input.props.onChange({ target: { value: '3145' } });
  props = app.render();
  assert.equal(props.ticketIdKeyword, '3145');
});

test('ticket number search translations are available in all admin locales', () => {
  for (const [locale, expected] of Object.entries({ 'zh-CN': '按工单号搜索（全部状态）', 'en-US': 'Ticket number (all statuses)', 'ko-KR': '티켓 번호 검색 (모든 상태)' })) {
    const context = { window: {} };
    vm.runInNewContext(fs.readFileSync(path.join(root, `public/assets/admin/locales/${locale}.js`), 'utf8'), context);
    assert.equal(context.window.XBOARD_TRANSLATIONS[locale].ticket.filter.ticket_id_placeholder, expected);
  }
});
