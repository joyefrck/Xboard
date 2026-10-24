const assert = require('node:assert/strict');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');

test('admin tickets sort by latest user message before pagination at runtime', () => {
  const result = spawnSync('php', ['tests/admin-ticket-sorting.php'], {
    cwd: path.resolve(__dirname, '..'), encoding: 'utf8'
  });
  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /admin ticket sorting: \d+ checks passed/);
});
