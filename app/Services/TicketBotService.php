<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class TicketBotService
{
    // Service replies use the explicit is_admin flag and never impersonate a staff account.
    private const OPERATOR_ID = 0;

    public function __construct(private TicketService $tickets) {}

    public static function version(Ticket $ticket, int $lastMessageId): string
    {
        return hash('sha256', json_encode([
            (int) $ticket->id, (int) $ticket->status, $ticket->reply_status,
            $lastMessageId, $ticket->remarks,
        ], JSON_THROW_ON_ERROR));
    }

    public function write(int $ticketId, string $action, string $key, string $version, ?string $text): array
    {
        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', json_encode([$action, $version, $text], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($ticketId, $action, $keyHash, $requestHash, $version, $text) {
            $ticket = Ticket::lockForUpdate()->findOrFail($ticketId);
            $receipts = DB::table('v2_ticket_bot_action');
            $receipt = (clone $receipts)->where('ticket_id', $ticketId)->where('key_hash', $keyHash)->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $requestHash), 409, 'idempotency_key_reused');
                return json_decode($receipt->result, true, flags: JSON_THROW_ON_ERROR);
            }

            $lastMessageId = (int) $ticket->messages()->max('id');
            abort_unless(hash_equals(self::version($ticket, $lastMessageId), $version), 409, 'ticket_changed');
            $result = ['ticket_id' => $ticketId, 'action' => $action];
            switch ($action) {
                case 'reply':
                    abort_if((int) $ticket->status === Ticket::STATUS_CLOSED, 409, 'ticket_closed');
                    $reply = $this->tickets->replyByAdmin($ticketId, $text, self::OPERATOR_ID);
                    $result['message_id'] = $lastMessageId = (int) $reply->id;
                    $ticket->refresh();
                    break;
                case 'note':
                    $remarks = filled($ticket->remarks) ? $ticket->remarks . "\n" . $text : $text;
                    abort_if(mb_strlen($remarks) > 1000, 422, 'remarks_limit_exceeded');
                    $ticket->timestamps = false;
                    $ticket->remarks = $remarks;
                    $ticket->saveOrFail();
                    break;
                case 'close':
                    $this->tickets->closeByAdmin($ticketId, self::OPERATOR_ID);
                    $ticket->refresh();
                    break;
                default:
                    throw new \LogicException('Unknown ticket bot action');
            }

            $result['version'] = self::version($ticket, $lastMessageId);
            $receipts->insert([
                'ticket_id' => $ticketId, 'key_hash' => $keyHash, 'request_hash' => $requestHash,
                'actor' => 'ticket-bot', 'action' => $action,
                'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => time(),
            ]);
            return $result;
        }, 3);
    }
}
