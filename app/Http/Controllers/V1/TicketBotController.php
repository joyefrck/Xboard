<?php

namespace App\Http\Controllers\V1;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Services\Auth\PasswordSecurityService;
use App\Services\TicketAttachmentService;
use App\Services\TicketBotService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TicketBotController
{
    public function __construct(private TicketBotService $bot) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => 'sometimes|required|in:pending,answered,closed,all',
            'after_id' => 'sometimes|required|integer|min:0',
            'limit' => 'sometimes|required|integer|between:1,100',
        ]);
        $query = Ticket::query();
        match ($filters['state'] ?? 'pending') {
            'pending' => $query->awaitingAdminReply(),
            'answered' => $query->where('status', Ticket::STATUS_OPENING)->where('reply_status', Ticket::REPLY_ANSWERED),
            'closed' => $query->where('status', Ticket::STATUS_CLOSED),
            'all' => $query,
        };
        $limit = (int) ($filters['limit'] ?? 50);
        $rows = $query->where('id', '>', $filters['after_id'] ?? 0)->orderBy('id')->limit($limit + 1)->get();
        $items = $rows->take($limit);
        return response()->json([
            'data' => $items->map(fn (Ticket $ticket) => $this->summary($ticket)),
            'next_after_id' => $rows->count() > $limit ? $items->last()->id : null,
        ]);
    }

    public function show(int $ticket): JsonResponse
    {
        $data = DB::transaction(function () use ($ticket) {
            $item = Ticket::sharedLock()->findOrFail($ticket);
            $messages = $item->messages()->with('attachments')->orderBy('id')->get();
            return $this->summary($item) + [
                'remarks' => $item->remarks,
                'version' => TicketBotService::version($item, (int) $messages->last()?->id),
                'messages' => $messages->map(fn (TicketMessage $message) => [
                    'id' => $message->id,
                    'role' => $message->is_admin ? 'staff' : 'user',
                    'message' => $message->message,
                    'created_at' => $message->created_at,
                    'attachments' => $message->attachments->map(fn ($file) => $file->only(['id', 'name', 'mime_type', 'size'])),
                ]),
            ];
        });
        return response()->json(['data' => $data]);
    }

    public function userContext(int $ticket, UserService $users): JsonResponse
    {
        $user = Ticket::findOrFail($ticket)->user()->with('plan:id,name')->firstOrFail();
        $traffic = $users->getTrafficSummary($user);
        return response()->json(['data' => [
            'user_id' => $user->id,
            'masked_email' => str_contains($user->email, '@') ? PasswordSecurityService::maskedEmail($user->email) : null,
            'registered_at' => $user->created_at,
            'account_status' => $user->banned ? 'banned' : 'normal',
            'plan_id' => $user->plan_id,
            'plan_name' => $user->plan?->name,
            'expires_at' => $user->expired_at,
            'active_product_type' => $traffic['active_product_type'],
            'active_product_name' => $traffic['active_product_name'],
            'plan_used_bytes' => $traffic['plan_used_traffic'],
            'plan_remaining_bytes' => $traffic['plan_remaining_traffic'],
            'package_remaining_bytes' => $traffic['traffic_package_remaining'],
            'remaining_bytes' => $traffic['effective_remaining_traffic'],
        ]]);
    }

    public function attachment(int $ticket, int $attachment, TicketAttachmentService $files)
    {
        $file = TicketMessageAttachment::whereHas('message.ticket', fn ($query) => $query->whereKey($ticket))
            ->findOrFail($attachment);
        abort_unless($files->hasPrivateImagePath($file), 404);
        return $files->inlineResponse($file);
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        return $this->write($request, $ticket, 'reply', 'message', 10000);
    }

    public function note(Request $request, int $ticket): JsonResponse
    {
        return $this->write($request, $ticket, 'note', 'note', 1000);
    }

    public function close(Request $request, int $ticket): JsonResponse
    {
        return $this->write($request, $ticket, 'close');
    }

    private function write(Request $request, int $ticket, string $action, ?string $field = null, int $max = 0): JsonResponse
    {
        $rules = ['version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
        if ($field !== null) {
            $rules[$field] = "required|string|max:{$max}";
        }
        $input = $request->validate($rules);
        $key = $request->header('Idempotency-Key');
        Validator::make(['Idempotency-Key' => $key], [
            'Idempotency-Key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{1,128}$/D'],
        ])->validate();
        return response()->json(['data' => $this->bot->write(
            $ticket, $action, $key, $input['version'], $field === null ? null : $input[$field]
        )]);
    }

    private function summary(Ticket $ticket): array
    {
        $state = match (true) {
            (int) $ticket->status === Ticket::STATUS_CLOSED => 'closed',
            $ticket->reply_status !== null && (int) $ticket->reply_status === Ticket::REPLY_ANSWERED => 'answered',
            default => 'pending',
        };
        return $ticket->only(['id', 'subject', 'level', 'created_at', 'updated_at']) + ['state' => $state];
    }
}
