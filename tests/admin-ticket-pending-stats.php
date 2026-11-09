<?php

declare(strict_types=1);
// Reuse the isolated ticket fixture, including replied, pending, closed and legacy rows.
require __DIR__ . '/admin-ticket-sorting.php';

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\{Ticket, User};
use App\Services\UserService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;

$startChecks = $checks;
$db->schema()->table('v2_user', function (Blueprint $table) {
    foreach (['created_at', 't', 'online_count'] as $column) $table->integer($column)->default(0);
});
$db->schema()->create('v2_server', fn (Blueprint $table) => $table->increments('id'));
$db->schema()->create('v2_stat_server', function (Blueprint $table) {
    $table->increments('id'); $table->integer('record_at'); $table->bigInteger('u'); $table->bigInteger('d');
});
$db->schema()->create('v2_order', function (Blueprint $table) {
    $table->increments('id');
    foreach (['created_at','paid_at','status','total_amount','commission_status','commission_balance'] as $column) $table->integer($column)->default(0);
    $table->integer('invite_user_id')->nullable();
});
$db->schema()->create('v2_commission_log', function (Blueprint $table) {
    $table->increments('id'); $table->integer('created_at'); $table->integer('get_amount');
});
$container->instance(UserService::class, new class extends UserService {
    public function getAvailableUsersQuery(?int $timestamp = null): Builder
    {
        return User::query()->whereRaw('1 = 0');
    }
});
$stats = (new ReflectionClass(StatController::class))->newInstanceWithoutConstructor();
$verify = function (int $expected) use ($stats, $check) {
    $check($stats->getStats()['data']['ticketPendingTotal'], $expected, 'dashboard stats counts only open tickets awaiting reply');
    $check($stats->getOverride(new Request())['data']['ticket_pending_total'], $expected, 'legacy overview uses identical pending definition');
};
$db->table('v2_ticket')->where('id',30)->update(['reply_status'=>null]);
$check(Ticket::awaitingAdminReply()->orderBy('id')->pluck('id')->all(), [10,20,30,50], 'pending scope excludes answered-open and closed-pending tickets');
$verify(4);
$db->table('v2_ticket')->where('id',20)->update(['reply_status'=>Ticket::REPLY_ANSWERED]);
$verify(3);
$db->table('v2_ticket')->where('id',50)->update(['status'=>Ticket::STATUS_CLOSED]);
$verify(2);
$db->table('v2_ticket')->where('id',40)->update(['reply_status'=>Ticket::REPLY_PENDING]);
$verify(3);
$db->table('v2_ticket')->update(['status'=>Ticket::STATUS_CLOSED]);
$verify(0);
echo 'admin pending ticket stats: '.($checks-$startChecks)." checks passed\n";
