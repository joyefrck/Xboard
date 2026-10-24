<?php

declare(strict_types=1);

// Isolated runtime fixture: never boots Laravel, reads .env, or contacts production.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Controllers\V2\Admin\TicketController;
use App\Models\Ticket;
use App\Services\TicketService;
use App\Support\Setting;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();
$container = $db->getContainer();
$container->instance('app', $container);
$container->instance('db', $db->getDatabaseManager());
$container->instance(Setting::class, new class extends Setting {
    public function __construct() {}
    public function get(string $key, mixed $default = null): mixed
    {
        return $key === 'app_url' ? 'https://fixture.example.test' : $default;
    }
});
$routes = new RouteCollection();
$routes->add((new Route('GET', '/subscribe', fn () => null))->name('client.subscribe'));
$container->instance('url', new UrlGenerator($routes, Request::create('https://fixture.example.test')));
$container->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
    public function __construct() {}
});
Container::setInstance($container);
Facade::setFacadeApplication($container);
Request::macro('validate', function (array $rules) {
    $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
    return (new Illuminate\Validation\Factory($translator))->make($this->all(), $rules)->validate();
});

$db->schema()->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->string('email'); $t->string('token');
    $t->integer('balance')->default(0); $t->integer('commission_balance')->default(0);
    $t->integer('plan_id')->nullable();
});
$db->schema()->create('v2_ticket', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->string('subject');
    $t->integer('level')->default(0); $t->integer('status')->default(0);
    $t->integer('reply_status')->nullable()->default(1); $t->text('remarks')->nullable();
    $t->integer('created_at'); $t->integer('updated_at');
});
$db->schema()->create('v2_ticket_message', function (Blueprint $t) {
    $t->increments('id'); $t->integer('ticket_id')->index(); $t->integer('user_id');
    $t->text('message'); $t->boolean('is_admin')->default(false);
    $t->integer('created_at'); $t->integer('updated_at');
});
$db->table('v2_user')->insert(['id' => 1, 'email' => 'owner@example.test', 'token' => 'fixture']);
$ticket = function (int $id, int $created, int $updated, int $status = 0) use ($db) {
    $db->table('v2_ticket')->insert(['id' => $id, 'user_id' => 1, 'subject' => 'fixture',
        'created_at' => $created, 'updated_at' => $updated, 'status' => $status]);
};
$message = function (int $ticketId, int $created, bool $isAdmin = false) use ($db) {
    $db->table('v2_ticket_message')->insert(['ticket_id' => $ticketId, 'user_id' => $isAdmin ? 99 : 1,
        'message' => 'fixture', 'is_admin' => $isAdmin, 'created_at' => $created, 'updated_at' => $created]);
};
$ticket(10, 100, 9900); $message(10, 100); $message(10, 9800, true);
$ticket(20, 200, 200); $message(20, 200); $message(20, 700);
$ticket(30, 600, 600); $message(30, 600);
$ticket(40, 600, 600); $message(40, 600); $message(40, 10000, true);
$ticket(50, 500, 9999); // Missing historical messages fall back to ticket creation time.
$message(50, 12500, true); // Admin-only history also uses the creation fallback.
$ticket(60, 800, 800, 1); $message(60, 900);
// Staff replying on their own ticket must still be excluded based on is_admin.
$db->table('v2_ticket_message')->where('ticket_id', 40)->where('is_admin', true)->update(['user_id' => 1]);

$controller = new TicketController();
$fetch = function (array $params = []) use ($controller): array {
    $response = $controller->fetch(new Request(array_replace([
        'filter' => [['id' => 'status', 'value' => '0']], 'pageSize' => 2, 'current' => 1,
    ], $params)));
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
};
$checks = 0;
$check = function ($actual, $expected, string $label) use (&$checks) {
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . json_encode($actual));
    }
};
$ids = fn (array $result) => array_column($result['data'], 'id');
$first = $fetch();
$check($ids($first), [20, 40], 'latest user message sorts globally before pagination, with stable ties');
$check($first['total'], 5, 'message aggregation does not duplicate tickets or alter filtered total');
$check($ids($fetch(['current' => 2])), [30, 50], 'second page uses the same user-message ordering and creation fallback');
$check($ids($fetch(['current' => 3])), [10], 'admin reply and updated_at do not promote old user activity');
$check($ids($fetch(['filter' => [['id' => 'status', 'value' => '1']]])), [60], 'closed tickets use the same sorting');
$check($ids($fetch(['filter' => [], 'email' => ' owner@ ', 'pageSize' => 20])), [20, 40, 30, 50, 10, 60], 'email search across statuses preserves pending priority and time ordering');
$check($ids($fetch(['sort' => [['id' => 'id', 'desc' => false]]])), [10, 20], 'explicit column sorting remains available');
$check($first['data'][0]['user']['email'], 'owner@example.test', 'ticket user response remains intact');
$check(array_key_exists('last_user_reply_at', $first['data'][0]), false, 'sorting helper is not exposed in ticket response');

// Older pending tickets must precede newer answered tickets across page boundaries.
$db->table('v2_ticket')->whereIn('id', [20, 40])->update(['reply_status' => 0]);
$db->table('v2_ticket')->where('id', 30)->update(['reply_status' => null]);
$check($ids($fetch()), [30, 50], 'pending tickets, including legacy null reply status, sort before newer answered tickets');
$check($ids($fetch(['current' => 2])), [10, 20], 'pending priority is applied globally before pagination');
$check($ids($fetch(['current' => 3])), [40], 'answered tickets follow pending tickets in user-message time order');
$check($ids($fetch(['filter' => [], 'email' => 'owner@', 'pageSize' => 20])), [30, 50, 10, 60, 20, 40], 'closed tickets are not treated as pending when searching all statuses');
$check($ids($fetch(['sort' => [['id' => 'id', 'desc' => false]], 'current' => 2])), [50, 20], 'explicit sorting stays within pending and answered groups');
$db->table('v2_ticket')->where('id', 20)->update(['reply_status' => 1]);
$db->table('v2_ticket')->where('id', 30)->update(['reply_status' => 1]);

try {
    Carbon::setTestNow(Carbon::createFromTimestampUTC(11000));
    $service = new TicketService();
    $check($service->reply(Ticket::find(10), 'user follows up', 1) !== false, true, 'user reply succeeds');
    Carbon::setTestNow(Carbon::createFromTimestampUTC(12000));
    $check($service->reply(Ticket::find(20), 'user follows up twice', 1) !== false, true, 'consecutive user reply succeeds');
    $check(Ticket::find(20)->updated_at, 200, 'regression fixture reproduces unchanged ticket timestamp');
    $check($ids($fetch()), [20, 10], 'consecutive user replies move tickets to the top using message timestamps');
    foreach (['  admin note  ', null] as $remarks) {
        $controller->updateRemarks(new Request(['id' => 10, 'remarks' => $remarks]));
        $check($ids($fetch()), [20, 10], 'adding or clearing admin remarks preserves user-message order');
    }
} finally {
    Carbon::setTestNow();
}

echo "admin ticket sorting: {$checks} checks passed\n";
