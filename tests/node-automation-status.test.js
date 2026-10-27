const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/admin/assets/node-automation.js'), 'utf8');
const moduleReady = import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const group = (state, backups = []) => ({ state, current_ip: '198.51.100.1', backups, domain: 'node.example.test', nodes: [] });

test('green healthy status requires at least one enabled standby distinct from the current IP', async () => {
  const { groupStatus, standbyCount } = await moduleReady;
  const current = { enabled: true, ip: '198.51.100.1' };
  const disabled = { enabled: false, ip: '198.51.100.2' };
  const standby = { enabled: true, ip: '198.51.100.3', state: 'unchecked' };
  const empty = group('healthy', [current, disabled]);
  assert.equal(standbyCount(empty), 0);
  assert.deepEqual(groupStatus(empty), { key: 'healthy_no_backup', label: '正常 · 无备用' });
  assert.equal(groupStatus({ state: 'healthy' }).key, 'healthy_no_backup');
  const protectedGroup = group('healthy', [current, disabled, standby]);
  assert.equal(standbyCount(protectedGroup), 1);
  assert.deepEqual(groupStatus(protectedGroup), { key: 'healthy', label: '正常' });
});

test('all group states retain specific labels while mapping to consistent filter categories', async () => {
  const { groupStatus, stateLabels, statusFilters } = await moduleReady;
  const categories = {
    paused: 'paused', unconfigured: 'unconfigured', suspect: 'error', no_backup: 'error',
    probe_error: 'error', config_error: 'error', dns_conflict: 'error',
    switching: 'processing', propagating: 'processing', dns_unknown: 'processing',
  };
  for (const [state, key] of Object.entries(categories)) {
    assert.deepEqual(groupStatus(group(state)), { key, label: stateLabels[state] });
    assert.ok(statusFilters.some(item => item.key === key));
  }
  assert.deepEqual(groupStatus(group('future_state')), { key: 'other', label: 'future_state' });
  assert.deepEqual(groupStatus(group(null)), { key: 'other', label: '未知状态' });
});

test('status multiselect forms a union, combines with search and preserves node order', async () => {
  const { filterGroups } = await moduleReady;
  const rows = [
    { ...group('healthy', [{ enabled: true, ip: '198.51.100.2' }]), domain: 'sg.example.test', nodes: [{ id: 1, display_id: 207, name: '新加坡专线' }] },
    { ...group('paused'), domain: 'jp.example.test', nodes: [{ id: 2, display_id: 208, name: '东京专线' }] },
    { ...group('config_error'), domain: 'hk.example.test', nodes: [{ id: 3, display_id: 209, name: '香港专线' }] },
    { ...group('healthy'), domain: 'us.example.test', nodes: [{ id: 4, name: '美国专线' }] },
  ];
  assert.deepEqual(filterGroups(rows), rows);
  assert.deepEqual(filterGroups(rows, '', ['error', 'healthy']), [rows[0], rows[2]]);
  assert.deepEqual(filterGroups(rows, ' 专线 ', ['error', 'paused']), [rows[1], rows[2]]);
  assert.deepEqual(filterGroups(rows, ' JP.EXAMPLE '), [rows[1]]);
  assert.deepEqual(filterGroups(rows, '208', ['healthy', 'paused']), [rows[1]]);
  assert.deepEqual(filterGroups(rows, '4', ['healthy_no_backup']), [rows[3]]);
  assert.deepEqual(filterGroups(rows, 'missing', ['error']), []);
  assert.deepEqual(filterGroups(rows, '', ['healthy_no_backup']), [rows[3]]);
  assert.deepEqual(filterGroups(rows), rows); // Clearing both controls restores every domain.
});
