const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');

const root = path.resolve(__dirname, '..');

test('ticket retention preserves recent activity and retries failed image deletion at runtime', () => {
  const result = spawnSync('php', ['tests/ticket-retention.php'], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /ticket retention: \d+ checks passed/);
});

test('ticket retention is scheduled daily in Shanghai with overlap prevention', () => {
  const kernel = fs.readFileSync(path.join(root, 'app/Console/Kernel.php'), 'utf8');
  assert.match(kernel, /command\('tickets:prune'\)->dailyAt\('03:30'\)->timezone\('Asia\/Shanghai'\)->onOneServer\(\)->withoutOverlapping\(360\)/);
});
