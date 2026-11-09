<?php
declare(strict_types=1);

// Read-only production acceptance. Never prints identities, payloads or credentials.
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Order, Ticket};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};
try {
    $args = getopt('', ['since:']);
    $since = strtotime($args['since'] ?? '-10 minutes');
    $checks = [];
    foreach (['v2_order_refund', 'v2_order_refund_order', 'v2_order_refund_mail'] as $table) {
        $checks['schema_'.$table] = Schema::hasTable($table);
    }
    $checks['refund_receipt_columns'] = Schema::hasColumns('v2_order_refund_order', ['amount','order_amount','trade_no','order_created_at']);
    $checks['big_integer_dependency'] = class_exists(Brick\Math\BigInteger::class);
    $pending = Ticket::awaitingAdminReply()->count();
    $stats = (new ReflectionClass(App\Http\Controllers\V2\Admin\StatController::class))->newInstanceWithoutConstructor();
    $checks['dashboard_pending_count'] = $stats->getStats()['data']['ticketPendingTotal'] === $pending;
    $checks['legacy_pending_count'] = $stats->getOverride(new Request())['data']['ticket_pending_total'] === $pending;
    $request = new Request(['pageSize'=>100]);
    $app->instance('request', $request);
    $response = app(App\Http\Controllers\V2\Admin\TicketController::class)->fetch($request);
    $tickets = json_decode($response->getContent(), true)['data'];
    $last = null;
    foreach ($tickets as $ticket) {
        $group = $ticket['status'] == 0 && ($ticket['reply_status'] === null || $ticket['reply_status'] != 0) ? 0 : 1;
        $key = [$group, -(int)$ticket['id']];
        if ($last !== null && $key < $last) throw new RuntimeException('ticket_order');
        $last = $key;
    }
    $checks['ticket_pending_then_id_desc'] = true;
    $response = app(App\Http\Controllers\V2\Admin\OrderController::class)->fetch(new Request(['pageSize'=>10]));
    $checks['admin_order_query'] = $response->getStatusCode() === 200;
    $userOrder = Order::with(['balanceRefund','balanceRefundEntry'])->latest('id')->first();
    $public = (new App\Http\Resources\OrderResource($userOrder))->toArray(new Request());
    $checks['user_order_resource'] = isset($public['trade_no']) && !isset($public['balance_refund']);
    $routes = $app['router']->getRoutes(); $refundRoutes = [];
    foreach ($routes as $route) {
        if (in_array($route->getActionMethod(), ['refundPreview','refund'], true)) {
            $refundRoutes[] = ['method'=>$route->getActionMethod(),'middleware'=>$route->gatherMiddleware()];
        }
    }
    $checks['two_refund_routes'] = count($refundRoutes) === 2;
    $schedule = $app->make(Illuminate\Console\Scheduling\Schedule::class);
    $checks['mail_retry_scheduled'] = false;
    foreach ($schedule->events() as $event) {
        if (str_contains($event->command ?? '', 'refund:retry-mail')) {
            $checks['mail_retry_scheduled'] = $event->expression === '* * * * *' && $event->onOneServer && $event->withoutOverlapping;
        }
    }
    $jobs = []; $evidence = [];
    $repo = app(Laravel\Horizon\Contracts\JobRepository::class);
    for ($page=0; $page<50; $page++) {
        $rows = $repo->getCompleted($page*50);
        foreach ($rows as $job) {
            if ((float)($job->completed_at ?? 0) < $since) continue;
            $key = ($job->queue ?? 'unknown').':'.($job->name ?? 'unknown');
            $jobs[$key] = ($jobs[$key] ?? 0)+1;
            if (count($evidence)<100) $evidence[]=['id'=>$job->id,'name'=>$job->name,'queue'=>$job->queue,'status'=>$job->status,'completed_at'=>$job->completed_at];
        }
        if (count($rows)<50) break;
    }
    $result = ['checks'=>$checks,'all_pass'=>!in_array(false,$checks,true),
        'tickets'=>['pending'=>$pending,'all_open'=>Ticket::where('status',0)->count()],
        'refund_routes'=>$refundRoutes,'completed_jobs'=>$jobs,'completed_job_evidence'=>$evidence,
        'registered'=>App\Models\User::where('created_at','>=',$since)->count(),
        'paid_orders'=>Order::where('paid_at','>=',$since)->count(),
        'paid_not_opened'=>Order::where('paid_at','>=',$since)->where('status',1)->count(),
        'mail'=>['accepted'=>App\Models\MailLog::where('created_at','>=',$since)->where(fn($q)=>$q->whereNull('error')->orWhere('error',''))->count(),
            'failed'=>App\Models\MailLog::where('created_at','>=',$since)->whereNotNull('error')->where('error','!=','')->count()],
        'ticket_messages'=>App\Models\TicketMessage::where('created_at','>=',$since)->count(),
        'traffic_age_seconds'=>time()-(int)App\Models\User::max('t'),
        'stat_age_seconds'=>time()-(int)App\Models\StatUser::max('record_at'),
        'refund_mail_pending'=>App\Models\OrderRefundMail::whereNull('sent_at')->count()];
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    exit($result['all_pass'] ? 0 : 1);
} catch (Throwable $error) {
    echo json_encode(['all_pass'=>false,'error_class'=>get_class($error)])."\n";
    exit(2);
}
