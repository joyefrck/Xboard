const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');

test('default compose file cannot pull the upstream image', () => {
  assert.doesNotMatch(read('docker-compose.yml'), /ghcr\.io\/cedar2025/);
});

test('local evidence is ignored because the repository is public', () => {
  const ignored = read('.gitignore').split('\n');
  for (const rule of ['/output/', '/.playwright-cli/', '*.pem']) assert.ok(ignored.includes(rule), rule);
});

test('deploy.sh publishes tracked changes and never untracked files', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'deploy-safety-'));
  try {
    const repo = path.join(dir, 'repo');
    const git = (...args) => execFileSync('git', args, { cwd: repo, encoding: 'utf8' });
    execFileSync('git', ['init', '-q', '--bare', path.join(dir, 'remote.git')]);
    execFileSync('git', ['init', '-q', '-b', 'master', repo]);
    git('config', 'user.email', 'fixture@example.test');
    git('config', 'user.name', 'Fixture');
    git('config', 'commit.gpgsign', 'false');
    git('remote', 'add', 'origin', path.join(dir, 'remote.git'));
    fs.writeFileSync(path.join(repo, 'code.txt'), 'old');
    git('add', 'code.txt');
    git('commit', '-qm', 'initial');
    fs.writeFileSync(path.join(repo, 'code.txt'), 'new');
    fs.writeFileSync(path.join(repo, 'private.log'), 'private');
    execFileSync('bash', [path.join(root, 'deploy.sh')], { cwd: repo, input: 'message\ny\n', stdio: ['pipe', 'pipe', 'pipe'] });
    assert.equal(git('show', '--name-only', '--format=', 'HEAD').trim(), 'code.txt');
    assert.match(git('status', '--short'), /\?\? private\.log/);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
