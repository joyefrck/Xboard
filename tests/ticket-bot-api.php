<?php

declare(strict_types=1);

use App\Http\Resources\TicketResource;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Services\TicketService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/xboard-ticket-api-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->useEnvironmentPath($root);
$app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($root) {
    $app['config']->set([
        'app.key' => 'base64:' . base64_encode(str_repeat('t', 32)), 'app.env' => 'testing', 'app.debug' => true, 'logging.default' => 'null',
        'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
        'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
        'cache.default' => 'array', 'cache.stores.redis' => ['driver' => 'array'],
        'filesystems.disks.local.root' => $root, 'ticket_bot.token_sha256' => null,
    ]);
    // Optional disposable MySQL fixture for two independent PHP workers testing row locks.
    if ($socket = getenv('TICKET_BOT_TEST_MYSQL_SOCKET')) {
        $app['config']->set([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.unix_socket' => $socket,
            'database.connections.mysql.database' => 'ticket_bot_fixture',
            'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => '',
        ]);
    }
});
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error . PHP_EOL);
    exit(1);
});
Queue::fake();
$count = 0;
function check(bool $ok, string $name): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException($name);
    }
    $count++;
}

$token = bin2hex(random_bytes(32));
function api(string $method, string $path, array $body = [], ?string $key = null, bool $auth = true, int $expected = 200)
{
    global $kernel, $token;
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($auth) {
        $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    if ($key !== null) {
        $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
    }
    $request = Request::create('/api/v1/ticket-bot/tickets' . $path, $method, [], [], [], $headers, json_encode($body));
    $response = $kernel->handle($request);
    check($response->getStatusCode() === $expected, "$method $path expected $expected, got " . $response->getStatusCode() . ' ' . substr((string) $response->getContent(), 0, 1000));
    if ($response instanceof BinaryFileResponse) {
        return $response;
    }
    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
}

try {
    if (($argv[1] ?? null) === '--refund-race-worker') {
        config(['ticket_bot.operators' => ['desk' => ['sha256' => hash('sha256', $token), 'scopes' => ['refund']]]]);
        echo "ready\n";
        fflush(STDOUT);
        $body = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
        echo json_encode(api('POST', '/42/refunds', $body, 'race-refund'), JSON_THROW_ON_ERROR) . PHP_EOL;
        return;
    }
    if (($argv[1] ?? null) === '--race-worker') {
        config(['ticket_bot.token_sha256' => hash('sha256', $token)]);
        echo "ready\n";
        fflush(STDOUT);
        $response = api('POST', '/32/replies', ['version' => $argv[2], 'message' => '并发回复'], $argv[3], expected: (int) $argv[4]);
        echo json_encode($response, JSON_THROW_ON_ERROR) . PHP_EOL;
        return;
    }
    if (getenv('TICKET_BOT_TEST_MYSQL_SOCKET') && Schema::getTables('ticket_bot_fixture') !== []) {
        throw new RuntimeException('ticket_bot_fixture must be an empty disposable database');
    }
    foreach ([
        '2023_03_19_000000_create_v2_tables', '2025_01_18_140511_create_plugins_table',
        '2026_06_19_000001_create_user_traffic_packages_table', '2026_08_22_000001_add_remarks_to_v2_ticket_table',
        '2026_09_01_000001_create_ticket_message_attachments_table', '2026_09_01_000002_add_is_admin_to_ticket_messages_table',
        '2026_11_16_000001_create_ticket_bot_actions',
    ] as $migration) {
        (require database_path("migrations/{$migration}.php"))->up();
    }
    $time = ['created_at' => 1710000000, 'updated_at' => 1710000000];
    DB::table('v2_plan')->insert($time + ['id' => 1, 'name' => '标准套餐', 'group_id' => 1, 'transfer_enable' => 1]);
    DB::table('v2_user')->insert($time + [
        'id' => 21, 'email' => 'alex@example.test', 'password' => 'private_password', 'token' => 'private_token',
        'uuid' => 'private_uuid', 'plan_id' => 1, 'u' => 20, 'd' => 30, 'transfer_enable' => 300, 'expired_at' => null,
    ]);
    DB::table('v2_user_traffic_packages')->insert($time + [
        'user_id' => 21, 'plan_id' => 1, 'remaining_bytes' => 10, 'total_bytes' => 20,
    ]);
    DB::table('v2_ticket')->insert($time + ['id' => 31, 'subject' => '测试工单', 'remarks' => '人工备注', 'user_id' => 21]);
    DB::table('v2_ticket')->insert($time + ['id' => 32, 'subject' => '另一工单', 'user_id' => 21]);
    $message = TicketMessage::create(['ticket_id' => 31, 'user_id' => 21, 'message' => '请看截图']);
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $filePath = 'ticket-attachments/2026/11/11111111-1111-4111-8111-111111111111.png';
    Storage::disk('local')->put($filePath, $image);
    $file = TicketMessageAttachment::create([
        'ticket_message_id' => $message->id, 'original_name' => '截图.png', 'mime_type' => 'image/png',
        'size' => strlen($image), 'path' => $filePath, 'disk' => 'local',
    ]);

    api('GET', '', expected: 403);
    config(['ticket_bot.token_sha256' => hash('sha256', $token)]);
    api('GET', '', auth: false, expected: 401);
    config(['ticket_bot.token_sha256' => hash('sha256', 'a-different-token')]);
    api('GET', '/31/user-context', expected: 401);
    config(['ticket_bot.token_sha256' => hash('sha256', $token)]);
    api('POST', '/31/close', auth: false, expected: 401);
    $page = api('GET', '?limit=1');
    check(array_column($page['data'], 'id') === [31] && $page['next_after_id'] === 31, 'first cursor page');
    $page = api('GET', '?limit=1&after_id=31');
    check(array_column($page['data'], 'id') === [32] && $page['next_after_id'] === null, 'last cursor page');
    api('GET', '?state=bad', expected: 422);
    $detail = api('GET', '/31')['data'];
    $version = $detail['version'];
    $metadata = $detail['messages'][0]['attachments'][0];
    check($metadata['name'] === '截图.png' && !isset($metadata['path']) && !isset($metadata['disk']), 'private attachment metadata');
    $download = api('GET', '/31/attachments/' . $file->id);
    check(file_get_contents($download->getFile()->getPathname()) === $image && $download->headers->get('Content-Type') === 'image/png', 'exact image bytes');
    api('GET', '/32/attachments/' . $file->id, expected: 404);
    api('GET', '/31/attachments/' . $file->id, auth: false, expected: 401);
    $file->update(['path' => '../outside.png']);
    api('GET', '/31/attachments/' . $file->id, expected: 404);
    $context = api('GET', '/31/user-context')['data'];
    check($context['plan_name'] === '标准套餐' && $context['remaining_bytes'] === 260 && $context['registered_at'] === 1710000000, 'registered account, plan and package balance');
    check($context['masked_email'] === 'a***@example.test' && !str_contains(json_encode($context), 'private_') && !isset($context['email']), 'private account fields excluded');
    api('GET', '/999/user-context', expected: 404);
    api('POST', '/31/notes', ['version' => $version, 'note' => '诊断中'], expected: 422);
    $note = ['version' => $version, 'note' => '诊断中'];
    $added = api('POST', '/31/notes', $note, 'note-1');
    check(api('POST', '/31/notes', $note, 'note-1') === $added, 'note replay');
    api('POST', '/31/notes', ['version' => $version, 'note' => '替换'], 'note-1', expected: 409);
    check(api('GET', '/31')['data']['remarks'] === "人工备注\n诊断中", 'append without overwriting');
    check(!array_key_exists('remarks', (new TicketResource(Ticket::find(31)))->resolve()), 'user cannot see remarks');
    check(Queue::pushed(SendEmailJob::class)->isEmpty(), 'notes do not notify');
    $version = $added['data']['version'];
    api('POST', '/31/notes', ['version' => $version, 'note' => str_repeat('字', 1000)], 'long-note', expected: 422);
    DB::beginTransaction();
    try {
        api('POST', '/31/replies', ['version' => $version, 'message' => '将回滚'], 'rollback');
        check(Queue::pushed(SendEmailJob::class)->isEmpty(), 'notification waits for outer commit');
    } finally {
        DB::rollBack();
    }
    check(!TicketMessage::where('message', '将回滚')->exists() && DB::table('v2_ticket_bot_action')->count() === 1, 'rollback removes message and receipt together');
    check(Queue::pushed(SendEmailJob::class)->isEmpty(), 'rolled back replies do not notify');
    app(TicketService::class)->replyByAdmin(31, '人工客服已接手', 77);
    $stale = api('POST', '/31/close', ['version' => $version], 'stale-close', expected: 409);
    check($stale['message'] === 'ticket_changed', 'staff reply prevents stale close');
    $reply = ['version' => api('GET', '/31')['data']['version'], 'message' => '机器人回复'];
    Cache::forget('ticket_sendEmailNotify_21');
    $sent = api('POST', '/31/replies', $reply, 'reply-1');
    check($sent === api('POST', '/31/replies', $reply, 'reply-1'), 'reply replay');
    check(TicketMessage::where('message', '机器人回复')->count() === 1 && Queue::pushed(SendEmailJob::class)->count() === 2, 'one bot message and one notification job in addition to staff reply');
    check(api('GET', '/31')['data']['state'] === 'answered', 'bot reply moves out of pending');
    app(TicketService::class)->reply(Ticket::find(31), '用户补充', 21);
    check(api('GET', '?state=pending')['data'][0]['id'] === 31, 'replied old ticket re-enters queue');
    api('POST', '/31/close', ['version' => $sent['data']['version']], 'close-1', expected: 409);
    $close = ['version' => api('GET', '/31')['data']['version']];
    $closed = api('POST', '/31/close', $close, 'close-1');
    check(api('POST', '/31/close', $close, 'close-1') === $closed, 'close replay');
    $denied = api('POST', '/31/replies', ['version' => $closed['data']['version'], 'message' => '迟到回复'], 'late', expected: 409);
    check($denied['message'] === 'ticket_closed' && api('GET', '/31')['data']['state'] === 'closed', 'bot cannot reopen closed ticket');
    check(DB::table('v2_ticket_bot_action')->where('actor', 'ticket-bot')->count() === 3, 'one audit row per successful action');
    if (getenv('TICKET_BOT_TEST_MYSQL_SOCKET')) {
        $raceVersion = api('GET', '/32')['data']['version'];
        $workers = [];
        DB::beginTransaction();
        try {
            Ticket::lockForUpdate()->findOrFail(32);
            foreach ([1, 2] as $i) {
                $proc = proc_open([PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', __FILE__, '--race-worker', $raceVersion, 'same-key', '200'],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
                check(trim(fgets($pipes[1])) === 'ready', 'concurrent worker booted');
                $workers[] = [$proc, $pipes[1]];
            }
        } finally {
            DB::commit();
        }
        $results = [];
        foreach ($workers as [$proc, $pipe]) {
            $results[] = json_decode(stream_get_contents($pipe), true, flags: JSON_THROW_ON_ERROR);
            fclose($pipe);
            check(proc_close($proc) === 0, 'concurrent worker completed');
        }
        check($results[0] === $results[1] && TicketMessage::where('ticket_id', 32)->count() === 1, 'concurrent requests share one committed response');
        Ticket::find(32)->delete();
    }
    Ticket::find(31)->delete();
    check(DB::table('v2_ticket_bot_action')->count() === 0, 'retention cascades to bot receipts');
    require __DIR__ . '/fixtures/ticket-service-actions.php';
    (require database_path('migrations/2026_11_16_000001_create_ticket_bot_actions.php'))->down();
    echo "工单 Bot API 通过 {$count} 项 HTTP 与业务断言。\n";
} finally {
    (new Illuminate\Filesystem\Filesystem())->deleteDirectory($root);
}
