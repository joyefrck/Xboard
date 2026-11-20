<?php

// Runs inside the ticket API's isolated HTTP fixture, after its retention checks.
use App\Jobs\SendRefundEmailJob;
use App\Models\{Order, Server, Ticket, User};
use App\Services\{OrderService, ServerService, TicketPlanAccessService, TrafficPackageService, TrafficResetService};
use Illuminate\Support\Facades\{DB, Queue, Schema};

foreach ([
    '2025_01_04_optimize_plan_table',
    '2025_01_10_152139_add_device_limit_column',
    '2026_07_03_000001_create_traffic_packages_table', '2026_07_03_000002_add_traffic_package_order_links',
    '2026_09_21_000001_add_private_plans', '2026_11_09_000001_create_order_refunds',
    '2026_11_09_000002_add_refund_entitlement_snapshots', '2026_11_09_000003_add_refund_receipts_and_mail',
    '2026_11_20_000001_add_ticket_service_actions',
] as $migration) {
    (require database_path("migrations/{$migration}.php"))->up();
}
require database_path('migrations/2025_06_21_000002_create_traffic_reset_logs_table.php');
(new CreateTrafficResetLogsTable())->up();
Schema::table('v2_user', function ($table) {
    $table->integer('next_reset_at')->nullable();
    $table->integer('last_reset_at')->nullable();
    $table->integer('reset_count')->default(0);
});
$operator = bin2hex(random_bytes(32));
$refundOnly = bin2hex(random_bytes(32));
config(['ticket_bot.operators' => [
    'desk' => ['sha256' => hash('sha256', $operator), 'scopes' => ['refund', 'plan_access']],
    'finance' => ['sha256' => hash('sha256', $refundOnly), 'scopes' => ['refund']],
], 'ticket_bot.access_plan_ids' => [1, 2, 3, 4]]);
foreach ([41, 42, 43] as $id) {
    DB::table('v2_ticket')->insert($time + ['id' => $id, 'subject' => '业务测试', 'user_id' => $id === 43 ? 22 : 21]);
}
DB::table('v2_user')->insert($time + ['id' => 22, 'email' => 'other@example.test', 'password' => 'fixture', 'uuid' => 'other', 'token' => 'other']);
foreach ([2, 3, 4] as $tier) {
    DB::table('v2_plan')->insert($time + ['id' => $tier, 'group_id' => $tier, 'name' => "档位 {$tier}", 'transfer_enable' => 20,
        'speed_limit' => 100 * $tier, 'device_limit' => $tier, 'plan_type' => $tier === 4 ? 'exclusive' : 'standard']);
}
$expiry = time() + 12 * 86400;
$user = User::findOrFail(21);
$user->update(['expired_at' => $expiry, 'group_id' => 1]);
$originalTraffic = $user->only(['plan_id', 'u', 'd', 'transfer_enable', 'expired_at', 'balance']);
$subscription = Order::create(['user_id' => 21, 'plan_id' => 1, 'trade_no' => 'sub-41', 'type' => 1, 'status' => 3,
    'period' => 'monthly', 'total_amount' => 3500, 'created_at' => time() - 86400]);
$package = Order::create(['user_id' => 21, 'plan_id' => 1, 'trade_no' => 'pack-41', 'type' => 5, 'status' => 3,
    'period' => 'onetime', 'total_amount' => 4500]);
DB::table('v2_user_traffic_packages')->where('user_id', 21)->update(['order_id' => $package->id]);

api('GET', '/41/orders', expected: 403);
$token = $refundOnly;
api('GET', '/41/plan-access', expected: 403);
$token = $operator;
check(api('GET', '/41/orders?limit=1')['next_before_id'] === $package->id, 'orders use descending cursor');
api('POST', '/43/refunds/preview', ['trade_no' => 'pack-41'], expected: 404);
$targets = api('GET', '/41/plan-access')['data']['allowed_plans'];
check(array_column($targets, 'id') === [1, 2, 3], 'private plans excluded');
api('POST', '/41/plan-access/preview', ['target_plan_id' => 4], expected: 422);
$accessQuote = fn ($target) => api('POST', '/41/plan-access/preview', ['target_plan_id' => $target])['data'];
$bodyFor = fn ($p) => ['version' => $p['version'], 'quote' => $p['quote'], 'reason' => '工单测试申请'];
$change = function ($target, $key) use ($accessQuote, $bodyFor) {
    return api('POST', '/41/plan-access/changes', $bodyFor($accessQuote($target)), $key)['data'];
};
api('POST', '/41/plan-access/preview', ['target_plan_id' => 2, 'version' => str_repeat('0', 64)], expected: 409);
$grant = $bodyFor($accessQuote(2));
DB::table('v2_plan')->where('id', 2)->update(['speed_limit' => 201]);
api('POST', '/41/plan-access/changes', $grant, 'stale-profile', expected: 409);
DB::table('v2_plan')->where('id', 2)->update(['speed_limit' => 200]);
$staleTicket = $grant;
Ticket::whereKey(41)->update(['remarks' => '客服补充信息']);
api('POST', '/41/plan-access/changes', $staleTicket, 'stale-ticket', expected: 409);
$grant = $bodyFor($accessQuote(2));
api('POST', '/42/plan-access/changes', $grant, 'wrong-ticket', expected: 409);
$result = api('POST', '/41/plan-access/changes', $grant, 'grant');
check($result === api('POST', '/41/plan-access/changes', $grant, 'grant'), 'one grant receipt on retry');
api('POST', '/41/plan-access/changes', array_merge($grant, ['reason' => '另一请求']), 'grant', expected: 409);
check($user->refresh()->only(array_keys($originalTraffic)) === $originalTraffic && $user->group_id === 2, 'grant preserves purchased rights and accounting');
$change(3, 'upgrade-again');
check($user->refresh()->device_limit === 3 && $user->speed_limit === 300, 'flagship profile is effective');
$usersOn = fn (int $group) => ServerService::getAvailableUsers((new Server())->forceFill(['group_ids' => [(string) $group]]));
check($usersOn(3)->contains('id', 21) && !$usersOn(1)->contains('id', 21), 'node receives new access group only');
$change(1, 'restore');
check(!$user->refresh()->access_override_plan_id && $user->group_id === 1, 'restore purchased tier');
$change(2, 'renew-setup');
$renewal = Order::create(['user_id' => 21, 'plan_id' => 1, 'trade_no' => 'renew-41', 'type' => 2, 'status' => 1,
    'period' => 'monthly', 'total_amount' => 3500]);
(new OrderService($renewal))->open();
check($user->refresh()->expired_at > $expiry && $user->access_override_until === $expiry
    && $user->group_id === 2 && $user->speed_limit === 200, 'real renewal keeps override and original cutoff');
$user->update(['u' => $user->transfer_enable]);
app(TrafficPackageService::class)->syncAccessProfile($user);
check($user->refresh()->group_id === 1, 'independent package supplies its own profile');
$deferred = $accessQuote(3);
check(!$deferred['applies_to_current_traffic'], 'exhausted plan leaves package in control');
check(app(TrafficResetService::class)->performReset($user), 'real traffic reset succeeds');
check($user->refresh()->group_id === 2, 'plan balance restores override');
$user->update(['access_override_until' => time() - 1]);
app(TicketPlanAccessService::class)->expire();
check(!$user->refresh()->access_override_plan_id && $user->group_id === 1, 'expiry restores original profile after renewal');
$change(3, 'before-refund');

$preview = api('POST', '/41/refunds/preview', ['trade_no' => 'pack-41'])['data'];
check($preview['amount'] === 2250 && $preview['effect'] === 'reclaim_package_remainder' && !isset($preview['email']), 'safe proportional package preview');
$refund = $bodyFor($preview) + ['expected_amount' => $preview['amount']];
$token = $refundOnly;
api('POST', '/41/refunds', $refund, 'wrong-actor', expected: 409);
$token = $operator;
api('POST', '/41/refunds', array_merge($refund, ['expected_amount' => 4500]), 'wrong-amount', expected: 409);
$other = api('POST', '/42/refunds/preview', ['trade_no' => 'pack-41'])['data'];
DB::beginTransaction();
try {
    api('POST', '/41/refunds', $refund, 'rollback');
    check(Queue::pushed(SendRefundEmailJob::class)->isEmpty(), 'refund notification waits for outer commit');
} finally {
    DB::rollBack();
}
check((int) $user->refresh()->balance === 0 && DB::table('v2_order_refund')->count() === 0, 'rollback removes refund and credit');
$credit = api('POST', '/41/refunds', $refund, 'refund-package');
check($credit === api('POST', '/41/refunds', $refund, 'refund-package'), 'replayed refund returns original credit');
api('POST', '/42/refunds', $bodyFor($other) + ['expected_amount' => 2250], 'duplicate', expected: 409);
check($user->refresh()->balance === 2250 && $user->group_id === 3 && Queue::pushed(SendRefundEmailJob::class)->count() === 1,
    'one package credit and one notification; active subscription remains');
$sub = api('POST', '/41/refunds/preview', ['trade_no' => 'sub-41'])['data'];
check($sub['effect'] === 'end_subscription' && count($sub['related_orders']) === 2 && $sub['amount'] > 0, 'subscription preview includes renewal');
$subBody = $bodyFor($sub) + ['expected_amount' => $sub['amount']];
$ended = api('POST', '/41/refunds', $subBody, 'refund-sub');
check(!$user->refresh()->plan_id && !$user->access_override_plan_id && !$user->group_id
    && $user->balance === 2250 + $sub['amount'], 'subscription refund revokes override and credits one total');
// Formally buying another plan ends any support adjustment for this user.
$second = User::findOrFail(22);
$second->update(['plan_id' => 1, 'expired_at' => $expiry, 'transfer_enable' => 400, 'u' => 0, 'd' => 0]);
$grant22 = api('POST', '/43/plan-access/preview', ['target_plan_id' => 3])['data'];
api('POST', '/43/plan-access/changes', $bodyFor($grant22), 'grant-22');
$buy = Order::create(['user_id' => 22, 'plan_id' => 2, 'trade_no' => 'formal-upgrade', 'type' => 3,
    'period' => 'monthly', 'status' => 1, 'total_amount' => 3500]);
(new OrderService($buy))->open();
check($second->refresh()->plan_id === 2 && !$second->access_override_plan_id && $second->group_id === 2,
    'paid switch ends the override');
Ticket::whereKey(41)->update(['status' => Ticket::STATUS_CLOSED]);
check($ended === api('POST', '/41/refunds', $subBody, 'refund-sub'), 'replay survives ticket closure');
api('POST', '/41/refunds/preview', ['trade_no' => 'sub-41'], expected: 409);
Ticket::findOrFail(41)->delete();
check(DB::table('v2_ticket_service_action')->where('ticket_id', 41)->where('actor', 'desk')->where('action', 'refund')->count() === 2,
    'financial audit survives ticket pruning');

if (getenv('TICKET_BOT_TEST_MYSQL_SOCKET')) {
    $raceOrder = Order::create(['user_id' => 21, 'plan_id' => 1, 'trade_no' => 'race-pack', 'type' => 5,
        'status' => 3, 'period' => 'onetime', 'total_amount' => 1200]);
    DB::table('v2_user_traffic_packages')->insert($time + ['user_id' => 21, 'order_id' => $raceOrder->id,
        'plan_id' => 1, 'total_bytes' => 100, 'remaining_bytes' => 50]);
    $quote = api('POST', '/42/refunds/preview', ['trade_no' => 'race-pack'])['data'];
    $before = $user->refresh()->balance;
    $workers = [];
    DB::beginTransaction();
    try {
        Ticket::lockForUpdate()->findOrFail(42);
        foreach ([1, 2] as $i) {
            $proc = proc_open([PHP_BINARY, '-d', 'error_reporting=24575', dirname(__DIR__) . '/ticket-bot-api.php',
                '--refund-race-worker', json_encode($bodyFor($quote) + ['expected_amount' => 600], JSON_THROW_ON_ERROR)],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
            check(trim(fgets($pipes[1])) === 'ready', 'refund worker booted');
            $workers[] = [$proc, $pipes[1]];
        }
    } finally {
        DB::commit();
    }
    $responses = [];
    foreach ($workers as [$proc, $pipe]) {
        $responses[] = json_decode(stream_get_contents($pipe), true, flags: JSON_THROW_ON_ERROR);
        fclose($pipe);
        check(proc_close($proc) === 0, 'refund worker completed');
    }
    check($responses[0] === $responses[1] && $user->refresh()->balance === $before + 600,
        'concurrent refund on MySQL credits once and returns the same receipt');
}
