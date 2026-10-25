<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TicketRetentionService
{
    public const RETENTION_DAYS = 14;

    private function expiredTickets(int $cutoff): Builder
    {
        return Ticket::query()
            ->where('created_at', '<', $cutoff)
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('messages', fn ($q) => $q->where(fn ($q) => $q
                ->where('created_at', '>=', $cutoff)->orWhere('updated_at', '>=', $cutoff)));
    }

    public function preview(int $cutoff): array
    {
        $tickets = $this->expiredTickets($cutoff);
        $messages = TicketMessage::whereIn('ticket_id', (clone $tickets)->select('id'));
        $attachments = TicketMessageAttachment::whereIn('ticket_message_id', (clone $messages)->select('id'));
        $orphanMessages = $this->expiredOrphanMessages($cutoff);
        $orphanImages = TicketMessageAttachment::whereIn('ticket_message_id', (clone $orphanMessages)->select('id'));

        return [
            'cutoff' => $cutoff,
            'tickets' => (clone $tickets)->count(),
            'messages' => (clone $messages)->count(),
            'attachments' => (clone $attachments)->count(),
            'bytes' => (int) (clone $attachments)->sum('size'),
            'orphan_messages' => (clone $orphanMessages)->count(),
            'orphan_message_attachments' => (clone $orphanImages)->count(),
            'orphan_message_bytes' => (int) (clone $orphanImages)->sum('size'),
            'pending_attachments' => $this->pendingAttachments()->count(),
        ];
    }

    public function prune(int $cutoff): array
    {
        $result = ['tickets' => 0, 'messages' => 0, 'orphan_messages' => 0, 'attachments' => 0, 'bytes' => 0, 'failed_attachments' => 0];
        $this->expiredTickets($cutoff)->select('id')->chunkById(100, function ($tickets) use ($cutoff, &$result) {
            foreach ($tickets as $candidate) {
                $counts = Ticket::resolveConnection()->transaction(function () use ($candidate, $cutoff) {
                    // Replies take this same lock before inserting a message.
                    $ticket = Ticket::whereKey($candidate->id)->lockForUpdate()->first();
                    if (!$ticket || !$this->expiredTickets($cutoff)->whereKey($ticket->id)->exists()) {
                        return null;
                    }

                    $messages = TicketMessage::where('ticket_id', $ticket->id)->delete();
                    if (!$ticket->delete()) {
                        throw new RuntimeException('Ticket deletion failed');
                    }
                    // Keep attachment rows as a durable file-deletion outbox until after commit.
                    return ['tickets' => 1, 'messages' => $messages];
                });
                if ($counts !== null) {
                    $result['tickets'] += $counts['tickets'];
                    $result['messages'] += $counts['messages'];
                }
            }
        });

        // Also retire legacy messages left behind by earlier ticket-only deletions.
        $this->expiredOrphanMessages($cutoff)->select('id')->chunkById(100, function ($messages) use ($cutoff, &$result) {
            $result['orphan_messages'] += $this->expiredOrphanMessages($cutoff)
                ->whereKey($messages->modelKeys())->delete();
        });

        $attachments = $this->prunePendingAttachments();
        foreach ($attachments as $key => $count) $result[$key] += $count;
        return $result;
    }

    public function prunePendingAttachments(?array $messageIds = null): array
    {
        $result = ['attachments' => 0, 'bytes' => 0, 'failed_attachments' => 0];
        $query = $this->pendingAttachments();
        if ($messageIds !== null) $query->whereIn('ticket_message_id', $messageIds);
        $query->chunkById(100, function ($attachments) use (&$result) {
            foreach ($attachments as $attachment) {
                try {
                    if ($attachment->disk !== TicketAttachmentService::DISK ||
                        !preg_match('#^ticket-attachments/\d{4}/\d{2}/[0-9a-f-]{36}\.(?:jpg|png|webp)$#D', $attachment->path)) {
                        throw new RuntimeException('Unexpected ticket attachment location');
                    }

                    // A duplicate metadata reference must never remove another message's file.
                    $shared = TicketMessageAttachment::where('disk', $attachment->disk)
                        ->where('path', $attachment->path)->where('id', '!=', $attachment->id)->exists();
                    $bytes = 0;
                    if (!$shared) {
                        $disk = Storage::disk($attachment->disk);
                        $bytes = $disk->exists($attachment->path) ? $disk->size($attachment->path) : 0;
                        if (!$disk->delete($attachment->path)) {
                            throw new RuntimeException('Ticket attachment deletion failed');
                        }
                    }
                    if (!$attachment->delete()) {
                        throw new RuntimeException('Ticket attachment metadata deletion failed');
                    }
                    $result['attachments']++;
                    $result['bytes'] += $bytes;
                } catch (Throwable $error) {
                    $result['failed_attachments']++;
                    Log::warning('Ticket retention attachment cleanup failed; will retry', [
                        'attachment_id' => $attachment->id,
                        'error_type' => get_class($error),
                    ]);
                }
            }
        });

        return $result;
    }

    private function pendingAttachments(): Builder
    {
        return TicketMessageAttachment::whereDoesntHave('message');
    }

    private function expiredOrphanMessages(int $cutoff): Builder
    {
        return TicketMessage::whereDoesntHave('ticket')
            ->where('created_at', '<', $cutoff)->where('updated_at', '<', $cutoff);
    }
}
