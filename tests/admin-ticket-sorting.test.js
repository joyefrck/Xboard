const assert = require('node:assert/strict');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');

test('admin tickets prioritize pending replies and sort by ticket number before pagination', () => {
  const result = spawnSync('php', ['tests/admin-ticket-sorting.php'], {
    cwd: path.resolve(__dirname, '..'), encoding: 'utf8'
  });
  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /admin ticket sorting: \d+ checks passed/);
});

test('both dashboard APIs count only unclosed tickets awaiting an admin reply', () => {
  const result = spawnSync('php', ['tests/admin-ticket-pending-stats.php'], {
    cwd: path.resolve(__dirname, '..'), encoding: 'utf8'
  });
  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /admin pending ticket stats: \d+ checks passed/);
});
