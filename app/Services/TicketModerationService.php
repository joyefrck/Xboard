<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TicketModerationService
{
    public function __construct(private DeepSeekTicketReviewer $reviewer, private UserBlacklistService $blacklist) {}

    public function submit(int $userId, string $subject, string $message, ?int $ticketId, callable $save): mixed
    {
        $this->blacklist->assertCanSubmit(User::findOrFail($userId));
        $context = [];
        if ($ticketId === null && Ticket::where('user_id', $userId)->where('status', Ticket::STATUS_OPENING)->exists()) {
            throw new ApiException('存在未关闭的工单');
        }
        if ($ticketId !== null) {
            $ticket = Ticket::whereKey($ticketId)->where('user_id', $userId)->first();
            if (!$ticket || (int) $ticket->status === Ticket::STATUS_CLOSED) {
                throw new ApiException('工单不存在或已关闭');
            }
            $context = ['subject' => $ticket->subject, 'messages' => TicketMessage::where('ticket_id', $ticketId)
                ->orderByDesc('id')->limit(6)->get()->reverse()->values()->map(fn ($item) => [
                    'role' => $item->is_admin ? 'staff' : 'user',
                    'message' => mb_substr($item->message, 0, 2000),
                ])->all()];
        }
        // Historic titles are context only: never punish a reply for an old title.
        $currentSubject = $ticketId === null ? $subject : '';
        $review = config('ticket_moderation.enabled', false)
            ? $this->reviewer->review($currentSubject, $message, $context)
            : ['abusive' => false];

        $result = DB::transaction(function () use ($userId, $subject, $message, $ticketId, $save, $review, $context) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->blacklist->assertCanSubmit($user);
            if ($review['abusive']) {
                return ['blocked' => $this->blacklist->punish($user, $review, [
                    'ticket_id' => $ticketId, 'subject' => $subject, 'message' => $message, 'context' => $context,
                ])];
            }
            return ['value' => $save()];
        });
        // Throw outside the transaction so the penalty and evidence remain committed.
        if (isset($result['blocked'])) {
            throw new ApiException($result['blocked'], 403);
        }
        return $result['value'];
    }
}
