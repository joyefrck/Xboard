const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const moduleAt = require('./admin-module-loader.cjs');
const traffic = moduleAt('bandwagon-traffic.js');
const management = moduleAt('bandwagon-vps.js');

test('provider import, sync, password and API boundaries use isolated SQLite/fake HTTP', () => {
  const run = spawnSync('php', ['tests/bandwagon-vps.php'], { cwd: root, encoding: 'utf8', timeout: 30000 });
  assert.equal(run.status, 0, run.stdout + run.stderr);
  assert.match(run.stdout, /Bandwagon: \d+ isolated integration checks passed/);
});

test('binary sizes preserve zero and reject missing/invalid provider counters', async () => {
  const { formatBytes } = await traffic;
  assert.equal(formatBytes(0), '0 B');
  assert.equal(formatBytes(1024 ** 3), '1 GiB');
  for (const value of [undefined, null, NaN, Infinity, -1]) assert.equal(formatBytes(value), '—');
});

test('usage thresholds distinguish both boundaries', async () => {
  const { usageTone } = await traffic;
  assert.equal(usageTone(79.99), 'normal');
  assert.equal(usageTone(80), 'warning');
  assert.equal(usageTone(94.99), 'warning');
  assert.equal(usageTone(95), 'danger');
});

test('traffic meter handles conflicts, missing quota and above-quota percentages accessibly', async () => {
  const { createTrafficMeter } = await traffic;
  const R = { createElement: (type, props, ...children) => ({ type, props, children }), Fragment: 'fragment' };
  const Meter = createTrafficMeter(R);
  assert.match(JSON.stringify(Meter({ traffic:{status:'conflict'} })), /匹配冲突/);
  assert.match(JSON.stringify(Meter({ traffic:{status:'unmatched'} })), /未关联搬瓦工/);
  assert.match(JSON.stringify(Meter({ traffic:{status:'matched',usage:null} })), /暂无流量数据/);
  const rendered = Meter({ traffic:{status:'matched',usage:{percent:125,total_bytes:100,used_bytes:125},stale:true} });
  const flat = [];
  function visit(node) { if (node && typeof node === 'object') { flat.push(node); (node.children || []).flat().forEach(visit); } }
  visit(rendered);
  const progress = flat.find(node => node.props?.role === 'progressbar');
  assert.equal(progress.props['aria-valuenow'], 100);
  assert.match(JSON.stringify(rendered), /125%/);
  assert.match(JSON.stringify(rendered), /数据待更新/);
});

test('power state, import summary and expired queue state remain explicit', async () => {
  const { vpsState, previewSummary, syncPending } = await management;
  assert.equal(vpsState({info:{suspended:true}}).label, '已暂停');
  assert.equal(vpsState({info:{},synced_at:null}).label, '待同步');
  assert.equal(vpsState({synced_at:'2026-11-02',live:{ve_status:'Stopped'}}).label, '已关机');
  assert.equal(vpsState({synced_at:'2026-11-02',live:{}}).label, '状态未知');
  assert.deepEqual(previewSummary(['valid','invalid','terminated','duplicate','valid'].map(status=>({status}))), {valid:2,invalid:1,terminated:1,duplicate:1});
  const now = Date.parse('2026-11-02T12:00:00Z');
  assert.equal(syncPending({queued_at:'2026-11-02T11:55:00Z'},now), true);
  assert.equal(syncPending({queued_at:'2026-11-02T11:49:59Z'},now), false);
});

test('new admin modules, compiled entry and locales parse as JavaScript modules', () => {
  for (const file of ['assets/index.js','assets/node-automation.js','assets/bandwagon-vps.js','assets/bandwagon-traffic.js',
    'locales/zh-CN.js','locales/en-US.js','locales/ko-KR.js']) {
    const run = spawnSync('node', ['--input-type=module','--check'], { input:fs.readFileSync(path.join(root,'public/assets/admin',file),'utf8'), encoding:'utf8' });
    assert.equal(run.status, 0, file + ': ' + run.stderr.slice(-1000));
  }
});

test('Horizon gives provider polling its own workers below the Redis lease', () => {
  const horizon = fs.readFileSync(path.join(root,'config/horizon.php'),'utf8');
  const config = horizon.match(/'XboardBandwagon'\s*=>\s*\[([\s\S]*?)\n\s{12}\],/)[1];
  assert.match(config, /'queue' => \['bandwagon'\]/);
  assert.match(config, /'balance' => false/);
  assert.match(config, /'timeout' => 55/);
  assert.match(config, /'minProcesses' => 2/);
  assert.match(config, /'maxProcesses' => 2/);
});
