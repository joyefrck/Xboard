<?php

declare(strict_types=1);

// Isolated fixture: never boots Laravel, loads .env, or connects to a real database/API.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\BlacklistController;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserBlacklistEvent;
use App\Services\DeepSeekTicketReviewer;
use App\Services\TicketModerationService;
use App\Services\UserBlacklistService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal(); $db->bootEloquent();
$container = $db->getContainer();
$config = new Repository($container['config']->toArray());
$config->set('ticket_moderation', ['enabled' => true, 'api_key' => 'fixture-only', 'model' => 'deepseek-flash']);
$container->instance('config', $config);
$container->instance('db', $db->getDatabaseManager());
$container->instance('db.schema', $db->schema());
$container->instance('log', new Psr\Log\NullLogger());
$container->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
    public function __construct() {}
});
Container::setInstance($container); Facade::setFacadeApplication($container);
Request::macro('validate', function (array $rules) {
    $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
    return (new Illuminate\Validation\Factory($translator))->make($this->all(), $rules)->validate();
});
Paginator::currentPageResolver(fn ($name) => (int) request()->input($name, 1));
Paginator::currentPathResolver(fn () => '/fixture/blacklist');

$db->schema()->create('v2_user', function (Blueprint $t) {
    $t->increments('id'); $t->string('email'); $t->boolean('banned')->default(false);
    $t->integer('invite_user_id')->nullable(); $t->integer('custom_pending_order_id')->nullable();
    $t->bigInteger('transfer_enable')->default(1000); $t->integer('created_at'); $t->integer('updated_at');
});
$migration = require dirname(__DIR__) . '/database/migrations/2026_10_23_000001_create_ticket_blacklist.php';
$migration->up();
$db->schema()->create('personal_access_tokens', function (Blueprint $t) {
    $t->increments('id'); $t->string('tokenable_type'); $t->unsignedInteger('tokenable_id');
    $t->string('name'); $t->string('token'); $t->text('abilities')->nullable();
    $t->timestamp('created_at')->nullable(); $t->timestamp('updated_at')->nullable();
});
$db->schema()->create('v2_ticket', function (Blueprint $t) {
    $t->increments('id'); $t->integer('user_id'); $t->string('subject'); $t->integer('status')->default(0);
    $t->integer('created_at'); $t->integer('updated_at');
});
$db->schema()->create('v2_ticket_message', function (Blueprint $t) {
    $t->increments('id'); $t->integer('ticket_id'); $t->integer('user_id'); $t->text('message');
    $t->boolean('is_admin')->default(false); $t->integer('created_at'); $t->integer('updated_at');
});
$db->schema()->create('fixture_submissions', function (Blueprint $t) { $t->increments('id'); $t->string('message'); });

$checks = 0;
$check = function ($actual, $expected, string $label) use (&$checks) {
    $checks++;
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
};
$expectApi = function (callable $run, int $code, string $contains) use ($check) {
    try { $run(); } catch (ApiException $e) {
        $check($e->getCode(), $code, 'API status');
        $check(str_contains($e->getMessage(), $contains), true, 'API explanation');
        return;
    }
    throw new RuntimeException('Expected ApiException');
};
$user = fn ($id) => User::create(['email' => 'fixture' . $id . '@example.test']);
$allow = ['abusive' => false, 'category' => 'none', 'reason' => '正常投诉', 'evidence' => ''];
$deny = ['abusive' => true, 'category' => 'insult', 'reason' => '针对客服的人身辱骂', 'evidence' => '你这个蠢货'];
$wire = fn ($result, $finish = 'stop') => ['choices' => [['finish_reason' => $finish,
    'message' => ['content' => json_encode($result, JSON_UNESCAPED_UNICODE)]]]];
$fake = function ($result) use ($wire) {
    Http::swap(new Factory());
    Http::preventStrayRequests();
    Http::fake(['api.deepseek.com/*' => Http::response($wire($result))]);
};
$assertSent = fn (callable $predicate) => $check(Http::recorded($predicate)->count() > 0, true, 'expected HTTP payload recorded');
$assertSentCount = fn (int $count) => $check(Http::recorded()->count(), $count, 'HTTP request count');
$blacklist = new UserBlacklistService();
$reviewer = new DeepSeekTicketReviewer();
$service = new TicketModerationService($reviewer, $blacklist);
$save = fn () => $db->table('fixture_submissions')->insertGetId(['message' => 'accepted']);

$first = $user(1); $other = $user(2); $manual = $user(3);
$fake($allow);
$check($service->submit($first->id, '我要退款', '你们服务太差了，要求退款', null, $save), 1, 'normal complaint accepted');
$check(UserBlacklistEvent::count(), 0, 'no penalty for accepted text');
$assertSent(fn ($request) => $request->url() === 'https://api.deepseek.com/chat/completions'
    && $request['model'] === 'deepseek-flash' && $request['response_format']['type'] === 'json_object'
    && count($request['messages']) === 2 && !isset($request['tools']));

$fake($deny);
$before = time();
$expectApi(fn () => $service->submit($first->id, '测试', '你这个蠢货', null, $save), 403, '再次违规将永久封禁');
$first->refresh();
$check($first->banned, false, 'first offense only mutes tickets');
$check($first->ticket_abuse_count, 1, 'first strike recorded');
$check(abs($first->ticket_muted_until - $before - 7 * 86400) <= 2, true, 'mute lasts exactly seven days');
$check($first->transfer_enable, 1000, 'subscription allowance untouched');
$check($db->table('fixture_submissions')->count(), 1, 'rejected message never reaches save callback');
$check(UserBlacklistEvent::count(), 1, 'penalty survives exception after commit');
$check(UserBlacklistEvent::first()->message, '你这个蠢货', 'evidence stored independently');
$check(UserBlacklistEvent::first()->source, 'ai', 'AI source stored');
$check(UserBlacklistEvent::first()->model, 'deepseek-flash', 'model stored');
$check(abs(UserBlacklistEvent::first()->toArray()['created_at'] - time()) <= 2, true, 'event JSON exposes Unix seconds, not the year');
$expectApi(fn () => $service->submit($first->id, '测试', '你这个蠢货', null, $save), 403, '工单已禁言');
$assertSentCount(1);
$check(UserBlacklistEvent::count(), 1, 'duplicate request during mute is not a second offense');
$check($first->fresh()->ticket_abuse_count, 1, 'mute bypass does not increase strikes');

$first->update(['ticket_muted_until' => time()]);
$blacklist->assertCanSubmit($first->refresh());
$fake($allow);
$check($service->submit($first->id, '恢复', '请问怎样连接', null, $save), 2, 'expiration boundary automatically allows normal submissions');
$check($first->fresh()->ticket_abuse_count, 1, 'expiration retains offense history');
$first->tokens()->create(['name' => 'fixture', 'token' => hash('sha256', 'fixture-token')]);
$other->tokens()->create(['name' => 'fixture', 'token' => hash('sha256', 'other-token')]);
$fake($deny);
$expectApi(fn () => $service->submit($first->id, '测试', '你这个蠢货', null, $save), 403, '永久封禁');
$check($first->fresh()->banned, true, 'second offense bans account');
$check($first->fresh()->ticket_abuse_count, 2, 'second strike recorded');
$check($first->tokens()->count(), 0, 'AI ban revokes all sessions');
$check($other->tokens()->count(), 1, 'other account sessions retained');
$check(UserBlacklistEvent::where('user_id', $first->id)->orderByDesc('id')->first()->action, 'account_ban', 'permanent penalty audited');

$manual->tokens()->create(['name' => 'fixture', 'token' => hash('sha256', 'manual-token')]);
$blacklist->manualBan($manual->id, '管理员测试封禁', 99);
$check($manual->fresh()->banned, true, 'manual ban still works');
$check($manual->tokens()->count(), 0, 'manual ban revokes sessions');
$check($manual->fresh()->ticket_abuse_count, 0, 'manual ban does not invent an AI offense');
$event = UserBlacklistEvent::where('user_id', $manual->id)->first();
$check([$event->source, $event->operator_id, $event->reason], ['manual', 99, '管理员测试封禁'], 'manual reason and operator audited');
$blacklist->manualBan($manual->id, '重复请求', 99);
$check(UserBlacklistEvent::where('user_id', $manual->id)->count(), 1, 'manual retry is idempotent');
$blacklist->manualUnban($manual->id, 99);
$check($manual->fresh()->banned, false, 'existing administrator unban preserved');

// A server error, invalid schema, hallucinated evidence and truncation never punish users.
foreach ([
    ['abusive' => 'true', 'category' => 'insult', 'reason' => 'invalid', 'evidence' => '你这个蠢货'],
    ['abusive' => true, 'category' => 'insult', 'reason' => 'old content', 'evidence' => '仅在历史中存在'],
    ['abusive' => false, 'category' => 'insult', 'reason' => 'inconsistent', 'evidence' => ''],
] as $invalid) {
    $fake($invalid);
    $expectApi(fn () => $service->submit($other->id, '测试', '请帮助我连接', null, $save), 503, '不会计为违规');
    $assertSentCount(2);
    $check($other->fresh()->ticket_abuse_count, 0, 'invalid response cannot punish');
}
Http::swap(new Factory()); Http::preventStrayRequests();
Http::fake(['api.deepseek.com/*' => Http::response(['error' => 'fixture'], 503)]);
$expectApi(fn () => $service->submit($other->id, '测试', '连接失败', null, $save), 503, '本次未提交');
$assertSentCount(2);
Http::swap(new Factory()); Http::preventStrayRequests();
Http::fake(['api.deepseek.com/*' => Http::failedConnection()]);
$expectApi(fn () => $service->submit($other->id, '测试', '连接失败', null, $save), 503, '本次未提交');
$assertSentCount(2);
Http::swap(new Factory()); Http::preventStrayRequests();
Http::fake(['api.deepseek.com/*' => Http::response($wire($deny, 'length'))]);
$expectApi(fn () => $service->submit($other->id, '测试', '你这个蠢货', null, $save), 503, '本次未提交');
$check($other->fresh()->ticket_abuse_count, 0, 'incomplete provider verdict cannot punish');
$config->set('ticket_moderation.api_key', '');
$expectApi(fn () => $service->submit($other->id, '测试', '连接失败', null, $save), 503, '本次未提交');
$config->set('ticket_moderation.api_key', 'fixture-only');

// Reply context is sent as data, with only the current message used as evidence.
$ticket = Ticket::create(['user_id' => $other->id, 'subject' => '历史标题含你这个蠢货', 'status' => 0]);
for ($i = 0; $i < 8; $i++) $db->table('v2_ticket_message')->insert([
    'ticket_id' => $ticket->id, 'user_id' => $other->id, 'message' => '历史消息' . $i,
    'is_admin' => $i % 2, 'created_at' => time(), 'updated_at' => time(),
]);
$fake($allow);
$service->submit($other->id, $ticket->subject, '这次是正常问题', $ticket->id, $save);
$assertSent(function ($request) use ($check) {
    $payload = json_decode($request['messages'][1]['content'], true);
    $check($payload['current']['subject'], '', 'old title is not re-moderated as current input');
    $check(count($payload['context']['messages']), 6, 'context limited to six messages');
    $check($payload['context']['messages'][0]['message'], '历史消息2', 'context chronological');
    $check($payload['context']['messages'][1]['role'], 'staff', 'context distinguishes customer and staff');
    return true;
});
$fake($deny);
$expectApi(fn () => $service->submit($other->id, $ticket->subject, '正常问题', $ticket->id, $save), 503, '不会计为违规');
$check($other->fresh()->ticket_abuse_count, 0, 'old abusive title cannot trigger a new offense');
$ticket->update(['status' => 1]);
$expectApi(fn () => $service->submit($other->id, '', '正常问题', $ticket->id, $save), 400, '已关闭');

// Simulate a ban committed by another request while the model is running.
Http::swap(new Factory()); Http::preventStrayRequests();
Http::fake(function () use ($blacklist, $other, $wire, $allow) {
    $blacklist->manualBan($other->id, '并发封禁', 99);
    return Http::response($wire($allow));
});
$count = $db->table('fixture_submissions')->count();
$expectApi(fn () => $service->submit($other->id, '', '正常问题', null, $save), 403, '账号已被封禁');
$check($db->table('fixture_submissions')->count(), $count, 'post-AI user lock prevents bypassing a concurrent ban');
$blacklist->manualUnban($other->id, 99);

// Save callback failure rolls back its writes, without changing user restrictions.
$fake($allow);
try {
    $service->submit($other->id, '', '正常问题', null, function () use ($db) {
        $db->table('fixture_submissions')->insert(['message' => 'rollback']);
        throw new RuntimeException('fixture save failed');
    });
    throw new LogicException('Expected failure');
} catch (RuntimeException $e) { $check($e->getMessage(), 'fixture save failed', 'save error propagated'); }
$check($db->table('fixture_submissions')->count(), $count, 'failed save rolls back all submission writes');

// Actual admin list/detail endpoints: no credentials or subscription tokens in responses.
$adminRequest = function (array $params) use ($container) {
    $request = Request::create('/fixture', 'GET', $params);
    $request->setUserResolver(fn () => (object) ['id' => 99]);
    $container->instance('request', $request);
    return $request;
};
$controller = new BlacklistController();
$result = $controller->fetch($adminRequest(['status' => 'banned']))->getData(true)['data'];
$check(count($result['data']), 1, 'banned filter returns banned accounts');
$check($result['data'][0]['id'], $first->id, 'correct banned user returned');
$check($result['data'][0]['latest']['source'], 'ai', 'latest source returned');
$check(abs($result['data'][0]['latest']['created_at'] - time()) <= 2, true, 'admin list serializes a valid event timestamp');
$check(isset($result['review']['api_key']), false, 'admin list never returns AI credential');
$check(isset($result['data'][0]['token']), false, 'admin list never returns subscription credential');
$result = $controller->fetch($adminRequest(['search' => 'does-not-exist']))->getData(true)['data'];
$check($result['total'], 0, 'email search applied');
$result = $controller->detail($adminRequest(['user_id' => $first->id]))->getData(true)['data'];
$check($result['total'], 2, 'admin sees durable penalty history');
$check($result['data'][1]['message'], '你这个蠢货', 'admin sees original rejected text');

// Existing user-management entry points retain their behavior and share the audit/session rules.
$edited = $user(4);
$edited->tokens()->create(['name' => 'fixture', 'token' => hash('sha256', 'edited-token')]);
$editRequest = new class extends App\Http\Requests\Admin\UserUpdate {
    public function validated($key = null, $default = null) { return $this->all(); }
};
$editRequest->setMethod('POST');
$editRequest->replace(['id' => $edited->id, 'banned' => true]);
$editRequest->setUserResolver(fn () => (object) ['id' => 99]);
$userController = new App\Http\Controllers\V2\Admin\UserController();
$check($userController->update($editRequest)->getStatusCode(), 200, 'legacy user-edit ban succeeds');
$check($edited->fresh()->banned, true, 'legacy user-edit bans account');
$check($edited->tokens()->count(), 0, 'legacy user-edit revokes sessions');
$check(UserBlacklistEvent::where('user_id', $edited->id)->first()->source, 'manual', 'legacy user-edit records manual audit');
$editRequest->replace(['id' => $edited->id, 'banned' => false]);
$check($userController->update($editRequest)->getStatusCode(), 200, 'legacy user-edit unban succeeds');
$check($edited->fresh()->banned, false, 'legacy user-edit unbans account');
$batchRequest = $adminRequest(['filter' => [['id' => 'id', 'value' => [$edited->id]]]]);
$check($userController->ban($batchRequest)->getStatusCode(), 200, 'legacy filtered batch ban succeeds');
$check($edited->fresh()->banned, true, 'batch ban affects selected account');
$check($other->fresh()->banned, false, 'batch ban preserves unselected accounts');
$check(UserBlacklistEvent::where('user_id', $edited->id)->count(), 3, 'edit, unban and batch actions all audited');

// The existing user status selector owns normal / ticket mute / account ban.
$statusUser = $user(5);
$statusUser->tokens()->create(['name' => 'fixture', 'token' => hash('sha256', 'status-user-token')]);
$editRequest->replace(['id' => $statusUser->id, 'account_status' => 'ticket_muted', 'banned' => true]);
$check($userController->update($editRequest)->getStatusCode(), 200, 'status selector accepts ticket mute');
$statusUser->refresh();
$check($statusUser->banned, false, 'ticket mute overrides a stale banned field without banning login');
$check(abs($statusUser->ticket_muted_until - time() - 7 * 86400) <= 2, true, 'manual status mute lasts seven days');
$check($statusUser->ticket_abuse_count, 1, 'manual mute preserves escalation history');
$check($statusUser->tokens()->count(), 1, 'manual ticket mute retains login sessions');
$check(UserBlacklistEvent::where('user_id', $statusUser->id)->first()->source, 'manual', 'status mute audits administrator source');
$expectApi(fn () => $blacklist->assertCanSubmit($statusUser), 403, '工单已禁言');
$deadline = $statusUser->ticket_muted_until;
$editRequest->replace(['id' => $statusUser->id, 'account_status' => 'ticket_muted']);
$userController->update($editRequest);
$check($statusUser->fresh()->ticket_muted_until, $deadline, 'saving unchanged mute does not extend the deadline');
$check(UserBlacklistEvent::where('user_id', $statusUser->id)->count(), 1, 'unchanged status does not duplicate penalties');
$editRequest->replace(['id' => $statusUser->id, 'account_status' => 'normal']);
$check($userController->update($editRequest)->getStatusCode(), 200, 'normal status releases manual ticket mute');
$check($statusUser->fresh()->ticket_muted_until, null, 'normal status clears the ticket restriction');
$check($statusUser->fresh()->ticket_abuse_count, 1, 'normal status retains prior offense history');
$blacklist->assertCanSubmit($statusUser->fresh());
$check(UserBlacklistEvent::where('user_id', $statusUser->id)->orderByDesc('id')->first()->action, 'ticket_unmute', 'manual release is audited');
$editRequest->replace(['id' => $statusUser->id, 'account_status' => 'banned']);
$check($userController->update($editRequest)->getStatusCode(), 200, 'status selector retains full account ban');
$check($statusUser->fresh()->banned, true, 'banned selector bans whole account');
$check($statusUser->tokens()->count(), 0, 'banned selector revokes sessions');
$editRequest->replace(['id' => $statusUser->id, 'account_status' => 'ticket_muted']);
$userController->update($editRequest);
$check($statusUser->fresh()->banned, false, 'moving from banned to muted restores login');
$check($statusUser->fresh()->ticket_muted_until > time(), true, 'moving from banned to muted restricts tickets');
$statusUser->update(['ticket_muted_until' => time()]);
$fake($deny);
$expectApi(fn () => $service->submit($statusUser->id, '', '你这个蠢货', null, $save), 403, '永久封禁');
$check($statusUser->fresh()->banned, true, 'AI attack after manual mute expires permanently bans account');

// Administrators can override an AI account ban through the same normal-status selector.
$editRequest->replace(['id' => $statusUser->id, 'account_status' => 'normal']);
$check($userController->update($editRequest)->getStatusCode(), 200, 'administrator can release an AI account ban in user management');
$statusUser->refresh();
$check($statusUser->banned, false, 'administrator normal status releases AI ban');
$check($statusUser->ticket_muted_until, null, 'AI ban release also clears old ticket mute deadline');
$check($statusUser->ticket_abuse_count, 2, 'administrator AI-ban release retains violation history');
$check($statusUser->tokens()->count(), 0, 'unbanning does not restore revoked login sessions');
$release = UserBlacklistEvent::where('user_id', $statusUser->id)->orderByDesc('id')->first();
$check([$release->action, $release->source, $release->operator_id], ['account_unban', 'manual', 99], 'AI-ban release audits the administrator');
$check(UserBlacklistEvent::where('user_id', $statusUser->id)->where('source', 'ai')->where('action', 'account_ban')->exists(), true,
    'administrator release preserves original AI penalty evidence');
$fake($allow);
$check($service->submit($statusUser->id, '', '请协助检查连接问题', null, $save) > 0, true, 'normal ticket works after administrator releases AI ban');
$check($statusUser->fresh()->banned, false, 'old violations alone do not immediately reban released account');
$fake($deny);
$expectApi(fn () => $service->submit($statusUser->id, '', '你这个蠢货', null, $save), 403, '永久封禁');
$check($statusUser->fresh()->banned, true, 'new attack after administrator release follows existing repeat-offense rule');

$rollbackUser = $user(6);
$editRequest->replace(['id' => $rollbackUser->id, 'account_status' => 'ticket_muted', 'expected_custom_order_id' => 999]);
$expectApi(fn () => $userController->update($editRequest), 400, '状态已变更');
$check($rollbackUser->fresh()->ticket_muted_until, null, 'failed user edit rolls back status mutation');
$check($rollbackUser->fresh()->ticket_abuse_count, 0, 'failed user edit rolls back strike count');
$check(UserBlacklistEvent::where('user_id', $rollbackUser->id)->count(), 0, 'failed user edit rolls back audit event');

$config->set('ticket_moderation.enabled', false);
$expectApi(fn () => $service->submit($first->id, '', '正常问题', null, $save), 403, '账号已被封禁');
$check($service->submit($other->id, '', '正常问题', null, $save) > 0, true, 'disabling AI preserves ordinary ticket flow');
$check($first->fresh()->ticket_abuse_count, 2, 'disabling AI does not erase prior restrictions');

// Restriction chronology owns the global order before pagination, across account statuses.
$sortUsers = [];
$sortEpoch = 1700000000;
for ($i = 0; $i < 25; $i++) {
    $sortUsers[] = $sortUser = User::create([
        'email' => 'sort-fixture-' . $i . '@example.test', 'banned' => $i % 2 === 0,
        'ticket_muted_until' => $i % 2 ? time() + 86400 + $i : null, 'ticket_abuse_count' => 1,
    ]);
    UserBlacklistEvent::create([
        'user_id' => $sortUser->id, 'email' => $sortUser->email,
        'action' => $i % 2 === 0 ? 'account_ban' : 'ticket_mute',
        'source' => 'manual', 'reason' => 'sorting fixture',
        'created_at' => $sortEpoch + (24 - $i) * 60, 'updated_at' => $sortEpoch,
    ]);
}
$legacySort = User::create(['email' => 'sort-fixture-legacy@example.test', 'banned' => true]);
$oldestSort = $sortUsers[24];
$oldestSort->update(['banned' => false]);
UserBlacklistEvent::create([
    'user_id' => $oldestSort->id, 'email' => $oldestSort->email,
    'action' => 'account_unban', 'source' => 'manual', 'reason' => 'later release is not a new restriction',
    'created_at' => $sortEpoch + 999999, 'updated_at' => $sortEpoch + 999999,
]);
// A tie must stay deterministic across repeated page reads.
UserBlacklistEvent::where('user_id', $sortUsers[3]->id)->update(['created_at' => $sortEpoch + 22 * 60]);
$expectedSort = array_map(fn ($u) => $u->id, $sortUsers);
[$expectedSort[2], $expectedSort[3]] = [$expectedSort[3], $expectedSort[2]];
$firstSortPage = $controller->fetch($adminRequest(['search' => 'sort-fixture-', 'page' => 1]))->getData(true)['data'];
$secondSortPage = $controller->fetch($adminRequest(['search' => 'sort-fixture-', 'page' => 2]))->getData(true)['data'];
$check(array_column($firstSortPage['data'], 'id'), array_slice($expectedSort, 0, 20), 'newest restriction first across banned and muted users before pagination');
$check(array_column($secondSortPage['data'], 'id'), [...array_slice($expectedSort, 20), $legacySort->id], 'older restrictions then unknown historical ban on the next page');
$check($firstSortPage['total'], 26, 'sorting preserves pagination total');
$check($secondSortPage['data'][4]['latest']['reason'], 'later release is not a new restriction', 'later release remains visible without moving the old restriction to the top');
$mutedSort = $controller->fetch($adminRequest(['search' => 'sort-fixture-', 'status' => 'muted']))->getData(true)['data'];
$expectedMuted = array_map(fn ($u) => $u->id, array_values(array_filter(
    $sortUsers, fn ($u, $i) => $i % 2 === 1, ARRAY_FILTER_USE_BOTH
)));
$check(array_column($mutedSort['data'], 'id'), $expectedMuted, 'muted filter retains restriction-time order');

$migration->down();
$check($db->schema()->hasColumn('v2_user', 'ticket_muted_until'), false, 'migration rollback removes added columns');
$check($db->schema()->hasTable('v2_user_blacklist_event'), false, 'migration rollback removes event table');
echo "ticket-moderation: {$checks} checks passed\n";
