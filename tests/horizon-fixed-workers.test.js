const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const horizon = fs.readFileSync(
  path.resolve(__dirname, '..', 'config/horizon.php'),
  'utf8',
);

function supervisor(name) {
  const match = horizon.match(
    new RegExp(`'${name}'\\s*=>\\s*\\[([\\s\\S]*?)\\n\\s{12}\\],`),
  );
  assert.ok(match, `expected ${name} supervisor config`);
  return match[1];
}

function assertFixedSupervisor(name, queues, processes) {
  const config = supervisor(name);

  for (const queue of queues) {
    assert.match(config, new RegExp(`'${queue}'`));
  }

  assert.match(config, /'balance'\s*=>\s*false/);
  assert.match(config, new RegExp(`'minProcesses'\\s*=>\\s*${processes}`));
  assert.match(config, new RegExp(`'maxProcesses'\\s*=>\\s*${processes}`));
}

test('high-frequency queues use isolated fixed Horizon workers', () => {
  assertFixedSupervisor('XboardTraffic', ['traffic_fetch'], 2);
  assertFixedSupervisor('XboardStat', ['stat'], 1);
  assertFixedSupervisor('XboardOnline', ['online_sync'], 1);
  assertFixedSupervisor('XboardOrder', ['order_handle'], 1);
  assertFixedSupervisor('XboardTelegram', ['send_telegram'], 1);
  assert.doesNotMatch(horizon, /'Xboard'\s*=>\s*\[/);
  assert.doesNotMatch(horizon, /'XboardCore'\s*=>\s*\[/);
});

test('Telegram worker is recycled before leaked runtime resources can accumulate', () => {
  const config = supervisor('XboardTelegram');

  assert.match(config, /'maxJobs'\s*=>\s*25/);
  assert.match(config, /'maxTime'\s*=>\s*900/);
  assert.doesNotMatch(supervisor('XboardOrder'), /'send_telegram'/);
});
