const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('Telegram bot profiles resolve separate settings without serializing tokens', () => {
  const profile = readRepoFile('app/Services/TelegramBotProfile.php');
  const credentials = readRepoFile('app/Services/TelegramCredentialService.php');
  const service = readRepoFile('app/Services/TelegramService.php');

  assert.match(profile, /case GENERAL = 'general'/);
  assert.match(profile, /case TICKET = 'ticket'/);
  assert.match(credentials, /Crypt::encryptString/);
  assert.match(profile, /telegram_ticket_bot_token/);
  assert.match(service, /TelegramBotProfile\s+\$profile\s*=\s*TelegramBotProfile::GENERAL/);
  assert.match(service, /redactParams\(\$params\)/);
  assert.match(service, /\$token \?\? \$credentials->getToken\(\$profile\)/);
});

test('ticket delivery has a dedicated bounded queue', () => {
  const job = readRepoFile('app/Jobs/SendTicketTelegramJob.php');
  const horizon = readRepoFile('config/horizon.php');

  assert.match(job, /onQueue\('send_ticket_telegram'\)/);
  assert.match(job, /TelegramBotProfile::TICKET/);
  assert.match(job, /protected int \$xboardUserId/);
  assert.doesNotMatch(job, /botToken|telegram_ticket_bot_token|secret/i);
  assert.match(horizon, /'XboardTicketTelegram'/);
  assert.match(horizon, /'queue'\s*=>\s*\['send_ticket_telegram'\]/);
  assert.match(horizon, /'maxJobs'\s*=>\s*25/);
  assert.match(horizon, /'maxTime'\s*=>\s*900/);
});

test('ticket webhook verifies a separate Telegram secret header', () => {
  const controller = readRepoFile('app/Http/Controllers/V1/Guest/TicketTelegramController.php');
  const routes = readRepoFile('app/Http/Routes/V1/GuestRoute.php');

  assert.match(controller, /X-Telegram-Bot-Api-Secret-Token/);
  assert.match(controller, /hash_equals/);
  assert.match(controller, /TicketTelegramHandler/);
  assert.match(routes, /telegram\/ticket\/webhook/);
});

test('ticket actions include contextual user and order callbacks', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(handler, /ticket:user:\{\$ticketId\}/);
  assert.match(handler, /ticket:orders:\{\$ticketId\}:1/);
  assert.match(handler, /User::where\('telegram_id', \$msg->from_id\)/);
  assert.match(handler, /where\('user_id', \$ticket->user_id\)/);
  assert.match(handler, /private const ORDER_PAGE_SIZE = 5/);
  assert.doesNotMatch(handler, /subscribe_url|password_algo|password_salt|callback_no/);
});

test('ticket close is shared by Telegram and admin controllers', () => {
  const service = readRepoFile('app/Services/TicketService.php');
  const admin = readRepoFile('app/Http/Controllers/V2/Admin/TicketController.php');
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(service, /function closeByAdmin\(int \$ticketId, int \$operatorId\): void/);
  assert.match(admin, /closeByAdmin\(/);
  assert.match(handler, /closeByAdmin\(/);
});

test('general bot refuses ticket operations after dedicated cutover', () => {
  const plugin = readRepoFile('plugins/Telegram/Plugin.php');

  assert.match(plugin, /telegram_ticket_bot_enable/);
  assert.match(plugin, /telegram_ticket_notify_enable/);
  assert.match(plugin, /工单功能已迁移至/);
  assert.match(plugin, /TicketTelegramNotifier/);
});
