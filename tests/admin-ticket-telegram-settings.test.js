const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('ticket bot configuration is sanitized and uses separate actions', () => {
  const controller = readRepoFile('app/Http/Controllers/V2/Admin/ConfigController.php');
  const routes = readRepoFile('app/Http/Routes/V2/AdminRoute.php');

  assert.match(controller, /setTicketTelegramToken/);
  assert.match(controller, /setTicketTelegramWebhook/);
  assert.match(controller, /testTicketTelegram/);
  assert.match(controller, /telegram_ticket_bot_configured/);
  assert.match(controller, /telegram_ticket_webhook_configured/);
  assert.match(controller, /telegram_ticket_webhook_configured_at/);
  assert.doesNotMatch(controller, /'telegram_ticket_bot_token'\s*=>\s*admin_setting/);
  assert.doesNotMatch(controller, /'telegram_ticket_webhook_secret'\s*=>/);
  assert.match(routes, /setTicketTelegramToken/);
  assert.match(routes, /setTicketTelegramWebhook/);
  assert.match(routes, /testTicketTelegram/);
});

test('compiled admin exposes protected ticket bot controls', () => {
  const admin = readRepoFile('public/assets/admin/assets/index.js');
  const locale = readRepoFile('public/assets/admin/locales/zh-CN.js');

  assert.match(admin, /telegram_ticket_bot_enable/);
  assert.match(admin, /telegram_ticket_notify_enable/);
  assert.match(admin, /telegram_ticket_bot_token/);
  assert.match(admin, /telegram_ticket_bot_token:h,\.\.\.D/);
  assert.match(admin, /setTicketTelegramToken/);
  assert.match(admin, /setTicketTelegramWebhook/);
  assert.match(admin, /testTicketTelegram/);
  assert.match(admin, /type:"password"/);
  assert.match(locale, /工单机器人设置/);
  assert.match(locale, /工单机器人令牌/);
});

test('all shipped admin locales include ticket bot labels', () => {
  for (const locale of ['en-US', 'ko-KR', 'zh-CN']) {
    const content = readRepoFile(`public/assets/admin/locales/${locale}.js`);
    assert.match(content, /ticket_bot/);
    assert.match(content, /ticket_webhook/);
    assert.match(content, /ticket_test/);
    assert.match(content, /webhook_ready/);
  }
});
