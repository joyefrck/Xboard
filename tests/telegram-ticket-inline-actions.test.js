const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const repoRoot = path.resolve(__dirname, '..');

function readRepoFile(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

test('Telegram webhook parses callback query ticket action payloads', () => {
  const parser = readRepoFile('app/Services/Telegram/TelegramUpdateParser.php');

  assert.match(parser, /\$data\['callback_query'\]/);
  assert.match(parser, /parseCallback/);
  assert.match(parser, /callback_query_id/);
  assert.match(parser, /callback_data/);
  assert.match(parser, /from_id/);
  assert.match(parser, /message_type'\s*=>\s*'callback_query'/);
});

test('Telegram service and queue job support structured message options', () => {
  const service = readRepoFile('app/Services/TelegramService.php');
  const job = readRepoFile('app/Jobs/SendTelegramJob.php');

  assert.match(service, /function sendMessage\(int \$chatId, string \$text, string \$parseMode = '', array \$options = \[\]\)/);
  assert.match(service, /array_filter\(\s*array_merge\(/);
  assert.match(service, /answerCallbackQuery/);
  assert.match(job, /protected array \$options/);
  assert.match(job, /__construct\(int \$telegramId, string \$text, array \$options = \[\]\)/);
  assert.match(job, /sendMessage\(\$this->telegramId, \$this->text, 'markdown', \$this->options\)/);
});

test('Telegram payment notification labels first and repeat valid payments', () => {
  const plugin = readRepoFile('plugins/Telegram/Plugin.php');

  assert.match(plugin, /private function getUserValidPaymentCount\(Order \$order\): int/);
  assert.match(plugin, /Order::where\('user_id',\s*\$order->user_id\)/);
  assert.match(plugin, /->whereNotNull\('paid_at'\)/);
  assert.match(plugin, /->whereNotIn\('status',\s*\[Order::STATUS_PENDING,\s*Order::STATUS_CANCELLED\]\)/);
  assert.match(plugin, /->count\(\)/);
  assert.match(plugin, /\$paymentCount\s*=\s*\$this->getUserValidPaymentCount\(\$order\)/);
  assert.match(plugin, /\$paymentCountLabel\s*=\s*\$paymentCount === 1\s*\?\s*'首次'\s*:\s*"第\{\$paymentCount\}次"/);
  assert.match(plugin, /本次支付金额: `%s元（%s）`/);
  assert.match(plugin, /\$order->total_amount \/ 100,\s*\n\s*\$paymentCountLabel,\s*\n\s*\$todayPaidTotal \/ 100/);
});

test('Telegram ticket reminder includes inline action buttons', () => {
  const notifier = readRepoFile('app/Services/Telegram/TicketTelegramNotifier.php');

  assert.match(notifier, /ticketActionKeyboard\(int \$ticketId\)/);
  assert.match(notifier, /inline_keyboard/);
  assert.match(notifier, /ticket:view:\{\$ticketId\}:1/);
  assert.match(notifier, /ticket:reply:\{\$ticketId\}/);
  assert.match(notifier, /ticket:close:\{\$ticketId\}/);
  assert.match(notifier, /ticket:user:\{\$ticketId\}/);
  assert.match(notifier, /ticket:orders:\{\$ticketId\}:1/);
  assert.match(notifier, /SendTicketTelegramJob::dispatch/);
});

test('Telegram ticket actions verify admin or staff identity by callback sender', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(handler, /private function getTicketOperator\(object \$msg\): User/);
  assert.match(handler, /User::where\('telegram_id', \$msg->from_id\)/);
  assert.match(handler, /empty\(\$msg->is_private\)/);
  assert.match(handler, /where\(fn\(\$query\) => \$query->where\('is_admin', 1\)->orWhere\('is_staff', 1\)\)/);
  assert.doesNotMatch(handler, /User::where\('telegram_id', \$msg->chat_id\)/);
});

test('Telegram ticket history is paginated with compact callback data', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(handler, /private const TICKET_HISTORY_PAGE_SIZE = 5/);
  assert.match(handler, /handleCallback\(object \$msg\)/);
  assert.match(handler, /showTicketHistory\(object \$msg, int \$ticketId, int \$page\)/);
  assert.match(handler, /forPage\(\$page, self::TICKET_HISTORY_PAGE_SIZE\)/);
  assert.match(handler, /ticket:view:\{\$ticketId\}:" \. \(\$page - 1\)/);
  assert.match(handler, /ticket:view:\{\$ticketId\}:" \. \(\$page \+ 1\)/);
});

test('Telegram ticket history can be requested without inline buttons', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(handler, /'\/ticket' => \$this->handleTicketCommand\(\$msg\)/);
  assert.match(handler, /private function handleTicketCommand\(object \$msg\): void/);
  assert.match(handler, /showTicketHistory\(\$msg, \$ticketId, \$page\)/);
  assert.match(handler, /用法：\/ticket 工单ID/);
  assert.match(handler, /\$msg->message_type === 'callback_query' && \$msg->message_id/);
});

test('Telegram ticket reply uses ForceReply and writes through TicketService', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(handler, /requestTicketReply\(object \$msg, int \$ticketId\)/);
  assert.match(handler, /请回复这条消息发送工单回复/);
  assert.match(handler, /force_reply/);
  assert.match(handler, /工单ID: \{\$ticketId\}/);
  assert.match(handler, /replyByAdmin\(\$ticketId, \$message, \$operator->id\)/);
});

test('Telegram ticket close action requires confirmation before closing', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(handler, /confirmTicketClose\(object \$msg, int \$ticketId\)/);
  assert.match(handler, /ticket:close_confirm:\{\$ticketId\}/);
  assert.match(handler, /ticket:close_cancel:\{\$ticketId\}/);
  assert.match(handler, /closeTicket\(object \$msg, int \$ticketId\)/);
  assert.match(handler, /closeByAdmin\(\$ticketId, \$operator->id\)/);
});

test('Telegram contextual user and order views stay scoped to the ticket owner', () => {
  const handler = readRepoFile('app/Services/Telegram/TicketTelegramHandler.php');

  assert.match(handler, /showUser\(object \$msg, int \$ticketId\)/);
  assert.match(handler, /showOrders\(object \$msg, int \$ticketId, int \$page\)/);
  assert.match(handler, /where\('user_id', \$ticket->user_id\)/);
  assert.match(handler, /private const ORDER_PAGE_SIZE = 5/);
  assert.doesNotMatch(handler, /subscribe_url|password_algo|password_salt|callback_no/);
});

test('Ticket messages expose their author for Telegram history labels', () => {
  const model = readRepoFile('app/Models/TicketMessage.php');

  assert.match(model, /function user\(\): BelongsTo/);
  assert.match(model, /belongsTo\(User::class, 'user_id', 'id'\)/);
});
