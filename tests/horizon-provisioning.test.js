const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const horizonPath = path.join(root, 'config/horizon.php');
function check(extra = []) {
  const run = spawnSync(process.env.PHP_BINARY || 'php', [
    path.join(root, 'scripts/check-horizon-provisioning.php'),
    ...(extra.some(arg => arg.startsWith('--environment=')) ? [] : ['--environment=local']),
    ...extra,
  ], { encoding: 'utf8', timeout: 30000 });
  assert.ok(run.stdout.trim().startsWith('{'), run.stdout + run.stderr);
  return { status: run.status, report: JSON.parse(run.stdout) };
}
function fixture(t, source) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'xboard-horizon-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  const file = path.join(dir, 'horizon.php');
  fs.writeFileSync(file, source);
  return [`--horizon-config=${file}`];
}

test('actual Horizon provisioning preserves every business queue with automation enabled', () => {
  const { status, report } = check();
  assert.equal(status, 0, JSON.stringify(report));
  assert.equal(report.ok, true);
  assert.deepEqual(report.missing_queues, []);
  assert.deepEqual(report.invalid_worker_leases, []);
});

test('release gate rejects the historical wildcard shadowing regression', t => {
  const source = `<?php
  $c = require ${JSON.stringify(horizonPath)};
  $automation = $c['environments']['local']['XboardNodeAutomation']
      ?? $c['environments']['*']['XboardNodeAutomation'];
  $c['environments'] = ['*' => ['XboardNodeAutomation' => $automation]] + $c['environments'];
  return $c;`;
  const { status, report } = check(fixture(t, source));
  assert.equal(status, 1);
  assert.ok(report.missing_queues.includes('redis:send_telegram'));
  assert.ok(report.missing_queues.includes('redis:send_email'));
  assert.ok(report.missing_queues.includes('redis:order_handle'));
  assert.ok(report.provisioned_queues.includes('node_automation:node_automation'));
});

test('release gate rejects a configured business worker with zero processes', t => {
  const source = `<?php
  $c = require ${JSON.stringify(horizonPath)};
  $c['environments']['local']['XboardTelegram']['maxProcesses'] = 0;
  return $c;`;
  const { status, report } = check(fixture(t, source));
  assert.equal(status, 1);
  assert.ok(report.missing_queues.includes('redis:send_telegram'));
});

test('release gate rejects worker timeout reaching the Redis retry lease', t => {
  const source = `<?php
  $c = require ${JSON.stringify(horizonPath)};
  $c['environments']['local']['XboardTelegram']['timeout'] = 90;
  return $c;`;
  const { status, report } = check(fixture(t, source));
  assert.equal(status, 1);
  assert.ok(report.invalid_worker_leases.includes('release-check:XboardTelegram'));
});

test('release gate rejects an environment without the required business queues', () => {
  const { status, report } = check(['--environment=production']);
  assert.equal(status, 1);
  assert.equal(report.environment, 'production');
  assert.ok(report.missing_queues.includes('redis:send_telegram'));
});

test('release gate can verify the actual environment after a deliberate environment migration', t => {
  const source = `<?php
  $c = require ${JSON.stringify(horizonPath)};
  $c['environments']['production'] = $c['environments']['local'];
  unset($c['environments']['local']);
  return $c;`;
  const { status, report } = check([...fixture(t, source), '--environment=production']);
  assert.equal(status, 0, JSON.stringify(report));
  assert.equal(report.environment, 'production');
});
