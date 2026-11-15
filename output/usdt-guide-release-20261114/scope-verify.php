<?php
declare(strict_types=1);

require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Ticket, TicketMessageAttachment, User};
use App\Services\Telegram\TicketTelegramHandler;
use App\Services\{TelegramBotProfile, TelegramCredentialService, TelegramService, TicketService};

try {
    $phase = getopt('', ['phase:'])['phase'] ?? 'after';
    $credentials = app(TelegramCredentialService::class);
    $checks = [
        'ticket_bot_enabled' => (bool) admin_setting('telegram_ticket_bot_enable', false),
        'ticket_token_configured' => $credentials->hasToken(TelegramBotProfile::TICKET),
        'webhook_secret_configured' => $credentials->getTicketWebhookSecret() !== '',
        'mime_upload_classes' => class_exists(Symfony\Component\Mime\Part\Multipart\FormDataPart::class),
    ];
    $telegram = new TelegramService(profile: TelegramBotProfile::TICKET);
    $me = $telegram->getMe();
    $webhook = $telegram->getWebhookInfo()->result;
    $checks['telegram_get_me'] = (bool) ($me->ok ?? false);
    $checks['webhook_route'] = parse_url($webhook->url ?? '', PHP_URL_PATH) === '/api/v1/guest/telegram/ticket/webhook';

    $ticket = Ticket::find(3963) ?? Ticket::latest('id')->first();
    $operator = User::whereNotNull('telegram_id')->where(fn ($q) => $q->where('is_admin', 1)->orWhere('is_staff', 1))->first();
    $checks['ticket_available'] = $ticket !== null;
    $checks['operator_available'] = $operator !== null;
    $details = [];
    if ($phase === 'after' && $ticket && $operator) {
        // Capture all outbound operations: no test messages or real attachments are sent.
        $fake = new class extends TelegramService {
            public array $calls = [];
            public function __construct() {}
            public function sendMessage(int $chatId, string $text, string $parseMode = '', array $options = []): void { $this->calls[] = ['send', $text, $options]; }
            public function editMessageText(int $chatId, int $messageId, string $text, string $parseMode = '', array $options = []): void { $this->calls[] = ['edit', $text, $options]; }
            public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void {}
            public function sendImage(int $chatId, string $path, string $mimeType, string $caption = ''): void { $this->calls[] = ['image', is_readable($path), in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)]; }
        };
        $handler = new TicketTelegramHandler(new TicketService());
        (new ReflectionProperty($handler, 'telegram'))->setValue($handler, $fake);
        $msg = (object) ['message_type' => 'callback_query', 'callback_query_id' => '', 'callback_data' => 'ticket:refresh:' . $ticket->id,
            'message_id' => 1, 'chat_id' => (int) $operator->telegram_id, 'from_id' => (int) $operator->telegram_id, 'is_private' => true];
        $handler->handle($msg);
        $rendered = array_values(array_filter($fake->calls, fn ($call) => $call[0] === 'edit'));
        $pages = max(1, (int) ceil($ticket->messages()->count() / 5));
        $checks['refresh_latest_page'] = count($rendered) === 1 && str_contains($rendered[0][1], "({$pages}/{$pages})");
        $checks['refresh_button'] = count($rendered) === 1 && str_contains(json_encode($rendered[0][2]), 'ticket:refresh:' . $ticket->id);
        $checks['no_automatic_image_resend'] = count(array_filter($fake->calls, fn ($call) => $call[0] === 'image')) === 0;
        $attachment = TicketMessageAttachment::whereHas('message', fn ($q) => $q->where('ticket_id', $ticket->id))->latest('id')->first();
        $details['sample_ticket_id'] = $ticket->id;
        $details['sample_message_count'] = $ticket->messages()->count();
        $details['sample_attachment_count'] = TicketMessageAttachment::whereHas('message', fn ($q) => $q->where('ticket_id', $ticket->id))->count();
        if ($attachment) {
            $fake->calls = [];
            $msg->callback_data = 'ticket:image:' . $ticket->id . ':' . $attachment->id;
            $handler->handle($msg);
            $images = array_values(array_filter($fake->calls, fn ($call) => $call[0] === 'image'));
            $checks['real_attachment_readable'] = count($images) === 1 && $images[0][1] && $images[0][2];
        }
        $checks['send_image_method'] = method_exists(TelegramService::class, 'sendImage');
    }
    $ok = !in_array(false, $checks, true);
    echo json_encode(['ok' => $ok, 'phase' => $phase, 'checks' => $checks, 'details' => $details,
        'webhook_pending_updates' => $webhook->pending_update_count ?? null,
        'webhook_error_age_seconds' => isset($webhook->last_error_date) ? time() - $webhook->last_error_date : null,
        'outbound_messages_sent' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error_class' => get_class($e), 'source' => basename($e->getFile()), 'line' => $e->getLine()]) . "\n";
    exit(2);
}
