<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Ticket, User};
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Crypt, DB};

class TicketServiceActions
{
    public function __construct(
        private TicketBotService $bot,
        private OrderRefundService $refunds,
        private TicketPlanAccessService $access,
    ) {}

    public function preview(int $ticketId, string $action, string $actor, string|int $target, ?string $expectedVersion = null): array
    {
        return DB::transaction(function () use ($ticketId, $action, $actor, $target, $expectedVersion) {
            $ticket = Ticket::lockForUpdate()->findOrFail($ticketId);
            $user = $this->userForOpenTicket($ticket);
            $version = TicketBotService::version($ticket, (int) $ticket->messages()->max('id'));
            abort_if($expectedVersion !== null && !hash_equals($version, $expectedVersion), 409, 'ticket_changed');
            $payload = [
                'ticket' => $ticketId, 'user' => (int) $user->id, 'action' => $action, 'actor' => $actor,
                'version' => $version, 'target' => $target, 'expires_at' => time() + 300,
            ];
            if ($action === 'refund') {
                $user->orders()->where('trade_no', $target)->firstOrFail();
                $preview = $this->refunds->preview($target);
                $payload['refund_quote'] = $preview['quote'];
                $payload['balance_before'] = $preview['balance_before'];
                $payload['expires_at'] = $preview['expires_at'];
                $details = Arr::only($preview, ['trade_no', 'amount', 'gross_amount', 'commission_deduction',
                    'kind', 'label', 'rule', 'related_orders', 'balance_before', 'balance_after']);
                $details['effect'] = $preview['kind'] === 'subscription' ? 'end_subscription' : 'reclaim_package_remainder';
            } else {
                $details = $this->access->preview($user, $target);
                $payload['state'] = $this->accessState($user, $details);
            }
            return $details + ['version' => $version, 'expires_at' => $payload['expires_at'],
                'quote' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))];
        }, 3);
    }

    public function confirm(int $ticketId, string $action, string $actor, string $key, array $input): array
    {
        return $this->bot->record($ticketId, $action, $key, $input['version'], $actor, [$actor, $action, $input],
            function (Ticket $ticket) use ($actor, $input, $action) {
                $user = $this->userForOpenTicket($ticket);
                try {
                    $quote = json_decode(Crypt::decryptString($input['quote']), true, flags: JSON_THROW_ON_ERROR);
                } catch (DecryptException|\JsonException) {
                    abort(422, 'invalid_quote');
                }
                abort_unless(is_array($quote) && ($quote['ticket'] ?? null) === (int) $ticket->id
                    && ($quote['user'] ?? null) === (int) $user->id && ($quote['action'] ?? null) === $action
                    && ($quote['actor'] ?? null) === $actor && ($quote['version'] ?? null) === $input['version'], 409, 'quote_mismatch');
                abort_if($quote['expires_at'] <= time(), 409, 'quote_expired');
                if ($action === 'refund') {
                    abort_if((int) $user->balance !== $quote['balance_before'], 409, 'balance_changed');
                    try {
                        $refund = $this->refunds->refundForTicket($quote['target'], $ticket->id, $user->id, $actor,
                            $input['expected_amount'], $input['reason'], $quote['refund_quote']);
                    } catch (ApiException $e) {
                        abort(409, $e->getMessage());
                    }
                    $details = $refund->only(['trade_no', 'amount', 'balance_before', 'balance_after'])
                        + ['refund_id' => $refund->id, 'related_orders' => $refund->snapshot['related_orders']];
                } else {
                    $details = $this->access->preview($user, $quote['target']);
                    abort_unless(hash_equals($quote['state'], $this->accessState($user, $details)), 409, 'plan_access_changed');
                    $this->access->apply($user, $details);
                }
                $id = DB::table('v2_ticket_service_action')->insertGetId([
                    'ticket_id' => $ticket->id, 'user_id' => $user->id, 'action' => $action, 'actor' => $actor,
                    'reason' => $input['reason'], 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => time(),
                ]);
                return ['action_id' => $id, 'action' => $action, 'ticket_id' => $ticket->id, 'version' => $input['version']] + $details;
            }, lockUser: true);
    }

    private function userForOpenTicket(Ticket $ticket): User
    {
        abort_if((int) $ticket->status === Ticket::STATUS_CLOSED, 409, 'ticket_closed');
        $user = $ticket->relationLoaded('user') ? $ticket->user : $ticket->user()->lockForUpdate()->first();
        abort_unless($user, 404);
        return $user;
    }

    private function accessState(User $user, array $details): string
    {
        $revision = DB::table('v2_ticket_service_action')->where('user_id', $user->id)->where('action', 'plan_access')
            ->orderByDesc('id')->lockForUpdate()->value('id');
        return hash('sha256', json_encode([$details, $revision], JSON_THROW_ON_ERROR));
    }
}
