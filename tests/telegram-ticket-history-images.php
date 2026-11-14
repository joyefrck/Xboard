<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Services\Telegram\TicketTelegramHandler;
use App\Services\TelegramService;
use App\Services\TicketService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

// Deliberately do not bootstrap Laravel or load .env / real bot credentials.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app = $capsule->getContainer();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('log', new NullLogger());
$root = sys_get_temp_dir() . '/xboard-ticket-bot-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$config = new Repository($app['config']->toArray());
$config->set('filesystems.disks.local', ['driver' => 'local', 'root' => $root, 'throw' => false]);
$app->instance('config', $config);
$app->singleton('filesystem', fn ($app) => new FilesystemManager($app));

$schema = $capsule->schema();
$schema->create('v2_user', function (Blueprint $table): void {
    $table->increments('id');
    $table->string('email');
    $table->bigInteger('telegram_id')->nullable();
    $table->boolean('is_admin')->default(false);
    $table->boolean('is_staff')->default(false);
});
$schema->create('v2_ticket', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('user_id');
    $table->string('subject');
    $table->integer('status')->default(0);
});
$schema->create('v2_ticket_message', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('ticket_id');
    $table->integer('user_id');
    $table->text('message');
    $table->boolean('is_admin')->default(false);
    $table->integer('created_at');
    $table->integer('updated_at');
});
$schema->create('v2_ticket_message_attachment', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('ticket_message_id');
    $table->string('original_name');
    $table->string('disk');
    $table->string('path');
    $table->string('mime_type');
    $table->integer('size');
    $table->integer('created_at');
    $table->integer('updated_at');
});

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$bot = new class extends TelegramService {
    public array $calls = [];
    public function __construct() {}
    public function sendMessage(int $chatId, string $text, string $parseMode = '', array $options = []): void
    {
        $this->calls[] = ['send', $chatId, $text, $options];
    }
    public function editMessageText(int $chatId, int $messageId, string $text, string $parseMode = '', array $options = []): void
    {
        $this->calls[] = ['edit', $chatId, $text, $options, $messageId];
    }
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void
    {
        $this->calls[] = ['answer', $callbackQueryId, $text, $showAlert];
    }
    public function sendImage(int $chatId, string $path, string $mimeType, string $caption = ''): void
    {
        $this->calls[] = ['image', $chatId, $path, $mimeType, $caption];
    }
};
$handler = new TicketTelegramHandler(new TicketService());
(new ReflectionProperty($handler, 'telegram'))->setValue($handler, $bot);
$msg = (object) [
    'message_type' => 'callback_query', 'callback_query_id' => 'test-callback',
    'callback_data' => 'ticket:view:10:1', 'message_id' => 500,
    'chat_id' => 12345, 'from_id' => 12345, 'is_private' => true,
];
$invoke = static function (string $callback) use ($handler, $bot, $msg): array {
    $bot->calls = [];
    $msg->callback_data = $callback;
    $handler->handle($msg);
    return $bot->calls;
};
$ofType = static fn (array $calls, string $type): array => array_values(array_filter($calls, fn ($call) => $call[0] === $type));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$path = 'ticket-attachments/2026/11/11111111-1111-4111-8111-111111111111.png';

try {
    $capsule->table('v2_user')->insert([
        ['id' => 1, 'email' => 'admin@example.test', 'telegram_id' => 12345, 'is_admin' => 1],
        ['id' => 2, 'email' => 'user@example.test', 'telegram_id' => null, 'is_admin' => 0],
    ]);
    $capsule->table('v2_ticket')->insert([
        ['id' => 10, 'user_id' => 2, 'subject' => 'Image ticket'],
        ['id' => 20, 'user_id' => 2, 'subject' => 'Other ticket'],
    ]);
    $first = TicketMessage::create(['ticket_id' => 10, 'user_id' => 2, 'message' => 'First message']);
    Storage::disk('local')->put($path, $png);
    $attachment = TicketMessageAttachment::create([
        'ticket_message_id' => $first->id, 'original_name' => 'private-image.png',
        'disk' => 'local', 'path' => $path, 'mime_type' => 'image/png', 'size' => strlen($png),
    ]);

    $calls = $invoke('ticket:view:10:1');
    $edit = $ofType($calls, 'edit')[0];
    $keyboard = json_encode($edit[3], JSON_UNESCAPED_UNICODE);
    check(str_contains($keyboard, '刷新记录') && str_contains($keyboard, 'ticket:refresh:10'), 'single-page history has refresh button');
    check(str_contains($edit[2], '图片附件：1 张') && str_contains($keyboard, 'ticket:image:10:' . $attachment->id), 'history exposes message image entry');
    check($edit[4] === 500 && $ofType($calls, 'image') === [], 'history edits original message without automatically resending images');

    for ($i = 2; $i <= 6; $i++) {
        TicketMessage::create(['ticket_id' => 10, 'user_id' => 2, 'message' => "Message {$i}"]);
    }
    $calls = $invoke('ticket:refresh:10');
    $edit = $ofType($calls, 'edit')[0];
    check(str_contains($edit[2], '(2/2)') && str_contains($edit[2], 'Message 6'), 'refresh requeries messages and jumps to latest page');
    check(!str_contains(json_encode($edit[3]), 'ticket:image:'), 'latest page does not show images from other pages');
    check(str_contains(json_encode($edit[3]), 'ticket:view:10:1'), 'latest page retains previous-page navigation');
    $again = $ofType($invoke('ticket:refresh:10'), 'edit')[0];
    check($again[2] === $edit[2] && $ofType($bot->calls, 'image') === [], 'repeated refresh retains text without sending images');
    $calls = $invoke('ticket:view:10:1');
    check(str_contains(json_encode($ofType($calls, 'edit')[0][3]), 'ticket:image:10:' . $attachment->id), 'pagination restores image entry');

    $latest = TicketMessage::where('ticket_id', 10)->orderByDesc('id')->first();
    $latestImage = $attachment->replicate();
    $latestImage->ticket_message_id = $latest->id;
    $latestImage->save();
    $secondImage = $latestImage->replicate();
    $secondImage->save();
    $latestEdit = $ofType($invoke('ticket:refresh:10'), 'edit')[0];
    $latestKeyboard = json_encode($latestEdit[3], JSON_UNESCAPED_UNICODE);
    check(str_contains($latestEdit[2], '第6条') && str_contains($latestEdit[2], '图片附件：2 张'), 'new attachments appear immediately on refresh');
    check(str_contains($latestKeyboard, '第6条·查看图片1') && str_contains($latestKeyboard, '第6条·查看图片2'), 'multiple image entries identify message and image sequence');
    foreach ($latestEdit[3]['reply_markup']['inline_keyboard'] as $row) {
        foreach ($row as $button) {
            check(strlen($button['callback_data']) <= 64, 'callback payload respects Telegram byte limit');
        }
    }

    $calls = $invoke('ticket:image:10:' . $attachment->id);
    $image = $ofType($calls, 'image')[0];
    check($image[1] === 12345 && $image[2] === Storage::disk('local')->path($path), 'authorized operator receives the private file');
    check(str_contains($image[4], '工单 #10') && str_contains($image[4], '消息 #' . $first->id), 'image caption identifies source ticket and message');
    $calls = $invoke('ticket:image:20:' . $attachment->id);
    check($ofType($calls, 'image') === [] && str_contains($ofType($calls, 'send')[0][2], '图片附件不存在'), 'attachment from another ticket is denied');
    check($ofType($invoke('ticket:image:10:99999'), 'image') === [], 'deleted attachment metadata is denied');
    check($ofType($invoke('ticket:image:10:0'), 'image') === [], 'invalid attachment ID is denied');
    check($ofType($invoke('ticket:image:99999:' . $attachment->id), 'image') === [], 'deleted ticket is denied');
    $msg->is_private = false;
    check($ofType($invoke('ticket:image:10:' . $attachment->id), 'image') === [], 'group access denied');
    $msg->is_private = true;
    $capsule->table('v2_user')->where('id', 1)->update(['is_admin' => 0]);
    check($ofType($invoke('ticket:image:10:' . $attachment->id), 'image') === [], 'revoked operator cannot use old image callbacks');
    check($ofType($invoke('ticket:refresh:10'), 'edit') === [], 'revoked operator cannot refresh old history');
    $capsule->table('v2_user')->where('id', 1)->update(['is_staff' => 1]);
    check(count($ofType($invoke('ticket:image:10:' . $attachment->id), 'image')) === 1, 'staff may view images');
    $attachment->update(['path' => '../private.png']);
    check($ofType($invoke('ticket:image:10:' . $attachment->id), 'image') === [], 'invalid stored path is denied');
    $attachment->update(['path' => $path, 'disk' => 'public']);
    check($ofType($invoke('ticket:image:10:' . $attachment->id), 'image') === [], 'unexpected storage disk is denied');
    $attachment->update(['disk' => 'local']);
    Storage::disk('local')->delete($path);
    $calls = $invoke('ticket:image:10:' . $attachment->id);
    check($ofType($calls, 'image') === [] && str_contains($ofType($calls, 'send')[0][2], '已清理'), 'missing file produces clear error');
    Storage::disk('local')->put($path, $png);

    // Inspect actual Symfony multipart requests, including complete bytes on retries.
    $requests = [];
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
        $body = $options['body'] ?? '';
        if (is_callable($body)) {
            $content = '';
            while ('' !== ($chunk = $body(16384))) {
                $content .= $chunk;
            }
        } elseif (is_iterable($body)) {
            $content = implode('', iterator_to_array($body));
        } else {
            $content = (string) $body;
        }
        $requests[] = [$method, $url, $content, $options];
        return new MockResponse('{"ok":true,"result":true}');
    });
    $service = new TelegramService(token: 'test-token', http: $http);
    $service->sendImage(12345, Storage::disk('local')->path($path), 'image/png', 'private caption');
    check($requests[0][0] === 'POST' && str_ends_with($requests[0][1], '/sendPhoto'), 'normal PNG uses multipart sendPhoto');
    check(str_contains($requests[0][2], $png) && str_contains($requests[0][2], 'name="photo"'), 'multipart contains full original image bytes');
    check(str_contains($requests[0][2], 'private caption') && !str_contains($requests[0][1], 'private caption'), 'caption is sent in body, not URL');
    $service->sendImage(12345, Storage::disk('local')->path($path), 'image/webp', 'webp caption');
    check(str_ends_with($requests[1][1], '/sendDocument') && str_contains($requests[1][2], 'name="document"'), 'WebP is sent as original file');
    // Rewrite PNG dimensions to exercise Telegram's extreme screenshot boundaries.
    $widePng = substr_replace($png, pack('NN', 1000, 1), 16, 8);
    Storage::disk('local')->put($path, $widePng);
    $service->sendImage(12345, Storage::disk('local')->path($path), 'image/png');
    check(str_ends_with($requests[2][1], '/sendDocument'), 'extreme aspect ratio uses original file');
    Storage::disk('local')->put($path, substr_replace($png, pack('NN', 6000, 6000), 16, 8));
    $service->sendImage(12345, Storage::disk('local')->path($path), 'image/png');
    check(str_ends_with($requests[3][1], '/sendDocument'), 'dimensions exceeding photo limit use original file');
    Storage::disk('local')->put($path, $png);
    try {
        $service->sendImage(12345, Storage::disk('local')->path($path), 'text/plain');
        check(false, 'unsupported MIME type must fail');
    } catch (ApiException) {
        check(count($requests) === 4, 'unsupported MIME type never reaches Telegram');
    }

    $retryBodies = [];
    $retryHttp = new MockHttpClient(static function (string $method, string $url, array $options) use (&$retryBodies): MockResponse {
        $body = $options['body'];
        $content = '';
        while ('' !== ($chunk = $body(16384))) {
            $content .= $chunk;
        }
        $retryBodies[] = $content;
        return count($retryBodies) === 1
            ? new MockResponse('{"ok":false}', ['http_code' => 500])
            : new MockResponse('{"ok":true,"result":true}');
    });
    (new TelegramService(token: 'test-token', http: $retryHttp))->sendImage(12345, Storage::disk('local')->path($path), 'image/png');
    check(count($retryBodies) === 2 && str_contains($retryBodies[0], $png) && str_contains($retryBodies[1], $png), 'retry resends complete multipart image');

    $unchanged = new TelegramService(token: 'test-token', http: new MockHttpClient([
        new MockResponse('{"ok":false,"error_code":400,"description":"Bad Request: message is not modified: specified new message content and reply markup are exactly the same"}', ['http_code' => 400]),
    ]));
    $unchanged->editMessageText(12345, 500, 'same text');
    check(true, 'unchanged refresh is successful');
    $bad = new TelegramService(token: 'test-token', http: new MockHttpClient([
        new MockResponse('{"ok":false,"error_code":400,"description":"Bad Request: message to edit not found"}', ['http_code' => 400]),
    ]));
    try {
        $bad->editMessageText(12345, 500, 'text');
        check(false, 'unrelated Telegram errors must still fail');
    } catch (ApiException) {
        check(true, 'unrelated Telegram errors still fail');
    }
    $unchangedSend = new TelegramService(token: 'test-token', http: new MockHttpClient([
        new MockResponse('{"ok":false,"error_code":400,"description":"Bad Request: message is not modified"}', ['http_code' => 400]),
    ]));
    try {
        $unchangedSend->sendMessage(12345, 'text');
        check(false, 'only edits may treat message-not-modified as success');
    } catch (ApiException) {
        check(true, 'message-not-modified handling is limited to edits');
    }
    $redacted = (new ReflectionMethod($service, 'redactParams'))->invoke($service, ['photo' => 'private path', 'document' => 'private bytes', 'caption' => 'private text']);
    check(count(array_filter($redacted, fn ($value) => $value === '[REDACTED]')) === 3, 'upload logging hides file and caption data');
    echo "Telegram ticket history/images: {$checks} checks passed\n";
} finally {
    Storage::disk('local')->deleteDirectory('ticket-attachments');
    rmdir($root);
}
