const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const bundle = fs.readFileSync(path.join(__dirname, '../public/assets/admin/assets/index.js'), 'utf8');
const component = (name, next) => bundle.slice(bundle.indexOf(`function ${name}(`), bundle.indexOf(`function ${next}(`));
const jsx = (type, props) => ({ type, props });

test('user tabs preserve filters and sorting, reset selection/page and key the server request by type', () => {
  const state = [];
  let cursor = 0, query;
  const m = {
    useState(initial) {
      const index = cursor++;
      if (!(index in state)) state[index] = initial;
      return [state[index], value => { state[index] = typeof value === 'function' ? value(state[index]) : value; }];
    },
    useEffect() {},
  };
  const render = new Function('m', 'e', 'Gr', 'oe', 'Ys', 'Ft', 'Ts', 'Yi', 'Kp', 'Ji',
    component('Hp', 'Kp') + ';return Hp;')(
    m, { jsx, jsxs: jsx }, () => [new URLSearchParams()],
    options => { query = options; return {}; }, { getList: params => params }, {}, {}, 'provider', 'table', 'dialog'
  );
  const draw = () => { cursor = 0; return render().props.children[0].props; };
  let table = draw();
  assert.equal(table.planType, 'all');
  const filters = [{ id: 'email', value: 'example.test' }, { id: 'banned', value: 'eq:0' }];
  const sorting = [{ id: 'id', desc: true }];
  table.setColumnFilters(filters);
  table.setSorting(sorting);
  table.setPagination({ pageIndex: 4, pageSize: 50 });
  table.setRowSelection({ 2: true });
  table = draw();
  for (const type of ['custom', 'exclusive', 'standard', 'all']) {
    table.onPlanTypeChange(type);
    table = draw();
    assert.equal(table.planType, type);
    assert.deepEqual(table.pagination, { pageIndex: 0, pageSize: 50 });
    assert.deepEqual(table.rowSelection, {});
    assert.deepEqual(query.queryFn(), { pageSize: 50, current: 1, filter: filters, sort: sorting, plan_type: type });
    assert.equal(query.queryKey.at(-1), type);
  }
  table.setColumnFilters([]);
  draw();
  assert.deepEqual(query.queryFn().filter, []);
});

test('table renders accessible type tabs and shares active type with existing actions', () => {
  let options;
  const deps = { e: { jsx, jsxs: jsx }, il: () => ({}), es: config => { options = config; return { options: config }; }, qp: () => [], Dt: 'tabs', xt: 'tablist', Xe: 'tab', ks: 'panel', Tp: 'toolbar', ds: 'table' };
  for (const name of ['ss', 'ws', 'is', 'Ns', 'zs', 'Os']) deps[name] = () => {};
  const render = new Function(...Object.keys(deps), component('Kp', 'Bp') + ';return Kp;')(...Object.values(deps));
  const change = () => {};
  const tree = render({ planType: 'custom', onPlanTypeChange: change, data: [], columnFilters: [] });
  assert.equal(tree.props.value, 'custom');
  assert.equal(tree.props.onValueChange, change);
  const [list, panel] = tree.props.children;
  assert.equal(list.props['aria-label'], '用户套餐类型');
  assert.deepEqual(list.props.children.map(tab => [tab.props.value, tab.props.children]), [
    ['all', '全部用户'], ['standard', '普通套餐'], ['custom', '私人定制'], ['exclusive', '用户专属'],
  ]);
  assert.equal(panel.props.value, 'custom');
  assert.equal(options.meta.planType, 'custom');
  assert.equal(options.manualPagination, true);
  assert.equal(options.manualFiltering, true);
  for (const action of ['dumpCSV', 'batchBan', 'sendMail']) {
    const start = bundle.indexOf(`Ys.${action}({`);
    const payload = bundle.slice(start, bundle.indexOf('sort:', start));
    assert.match(payload, /plan_type:[st]\.options\.meta\?\.planType\?\?"all"/);
    assert.match(payload, /filter:[st]\.getState\(\)\.columnFilters/);
  }
});
