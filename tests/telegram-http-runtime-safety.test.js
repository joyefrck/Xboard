const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('Telegram API requests avoid the Swoole curl transport', () => {
  const service = readRepoFile('app/Services/TelegramService.php');

  assert.match(service, /use Symfony\\Component\\HttpClient\\NativeHttpClient;/);
  assert.match(service, /use Symfony\\Contracts\\HttpClient\\HttpClientInterface;/);
  assert.match(service, /new NativeHttpClient\(/);
  assert.match(service, /\?HttpClientInterface \$http = null/);
  assert.doesNotMatch(service, /Illuminate\\Support\\Facades\\Http/);
  assert.doesNotMatch(service, /PendingRequest/);
});

test('Telegram retries are bounded and respect retry_after', () => {
  const service = readRepoFile('app/Services/TelegramService.php');
  const job = readRepoFile('app/Jobs/SendTelegramJob.php');

  assert.match(service, /private const MAX_ATTEMPTS = 3;/);
  assert.match(service, /private const MAX_RETRY_AFTER_SECONDS = 5;/);
  assert.match(service, /->parameters->retry_after/);
  assert.match(service, /min\(\s*self::MAX_RETRY_AFTER_SECONDS,/);
  assert.match(service, /private function sleepBeforeRetry\(int \$seconds\): void/);
  assert.match(job, /public \$tries = 1;/);
  assert.match(job, /public \$timeout = 50;/);
});
