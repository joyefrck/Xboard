const test = require('node:test');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');

test('P0 PHP security behavior suite passes in the isolated worktree runtime', () => {
  const output = execFileSync('php', [path.join(root, 'tests/security-p0.php')], {
    cwd: root,
    encoding: 'utf8',
  });
  assert.match(output, /security-p0 behavior checks passed/);
});

test('P0 two-process MariaDB contention suite requires and passes a disposable DB', () => {
  assert.ok(process.env.SECURITY_P0_DB_DSN, 'Set SECURITY_P0_DB_DSN to the isolated security_p0 MariaDB fixture; this gate cannot be skipped');
  const output = execFileSync('php', [path.join(root, 'tests/security-p0-concurrency.php')], {
    cwd: root,
    encoding: 'utf8',
    env: process.env,
  });
  assert.match(output, /security-p0 real-service MariaDB concurrency checks passed/);
});
