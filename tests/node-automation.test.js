const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('automation state machine reconciles crashes and failures using an isolated database', () => {
  const run = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'node-automation.php')], { encoding: 'utf8', timeout: 30000 });
  assert.equal(run.status, 0, run.stdout + run.stderr);
  assert.match(run.stdout, /integration checks passed/);
});

test('Cloudflare adapter rejects ambiguous DNS and distinguishes definite rejection from unknown writes', () => {
  const run = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'node-automation-cloudflare.php')], { encoding: 'utf8', timeout: 30000 });
  assert.equal(run.status, 0, run.stdout + run.stderr);
  assert.match(run.stdout, /Cloudflare: .* checks passed/);
});

test('admin API persists backup controls, protects active settings and deduplicates operations', () => {
  const run = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'node-automation-api.php')], { encoding: 'utf8', timeout: 30000 });
  assert.equal(run.status, 0, run.stdout + run.stderr);
  assert.match(run.stdout, /API: .* checks passed/);
});

test('common-account upgrade preserves only unambiguous users and guards unfinished DNS operations', () => {
  const run = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'node-automation-settings.php')], { encoding: 'utf8', timeout: 30000 });
  assert.equal(run.status, 0, run.stdout + run.stderr);
  assert.match(run.stdout, /settings: .* checks passed/);
});

test('admin has fourth node menu, registered route, translation and readable module', () => {
  const bundle = read('public/assets/admin/assets/index.js');
  assert.match(bundle, /href:"\/server\/route"[^}]*\}\)\},\{title:"nav:automationConfiguration"/);
  assert.match(bundle, /path:"automation",lazy:/);
  assert.match(bundle, /import\("\.\/node-automation\.js\?v=/);
  for (const locale of ['zh-CN', 'en-US', 'ko-KR']) assert.match(read(`public/assets/admin/locales/${locale}.js`), /"automationConfiguration"/);
  assert.match(read('resources/views/admin.blade.php'), /node-automation\.css/);
});

test('queue lease exceeds worker deadline and is isolated from business queues', () => {
  assert.match(read('config/queue.php'), /'node_automation' => \[[\s\S]*?'retry_after' => 660/);
  assert.match(read('config/horizon.php'), /'XboardNodeAutomation' => \[[\s\S]*?'queue' => \['node_automation'\]/);
  assert.match(read('app/Console/Kernel.php'), /node-automation:check.*everyMinute\(\).*onOneServer\(\).*withoutOverlapping/);
});

test('probe runtime is fixed and independent watchdog stops orphan processes', () => {
  assert.match(read('scripts/install-node-automation-probe.sh'), /version=1\.14\.2/);
  assert.match(read('scripts/install-node-automation-probe.sh'), /actual.*digest/);
  const probe = read('app/Services/NodeAutomation/ProxyProbe.php');
  assert.match(probe, /'listen' => '127\.0\.0\.1'/);
  assert.match(probe, /chmod\(\$path, 0600\)/);
  assert.match(probe, /timeout_binary.*'-s', 'TERM', '-k', '2', '25'/);
  assert.doesNotMatch(probe, /getErrorOutput\(\)|Log::|verify' => false/);
});
