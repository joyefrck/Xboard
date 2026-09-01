<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use App\Services\TicketAttachmentService;
use App\Services\TicketService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->where('user_id', $request->user()->id)
                ->first();
            if (!$ticket) {
                return $this->fail([400, __('Ticket does not exist')]);
            }
            $ticket['message'] = TicketMessage::with('attachments')
                ->where('ticket_id', $ticket->id)
                ->get();
            $ticket['message']->each(function ($message) use ($ticket) {
                $message['is_me'] = ($message['user_id'] == $ticket->user_id);
            });
            return $this->success(TicketResource::make($ticket)->additional(['message' => true]));
        }
        $ticket = Ticket::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();
        return $this->success(TicketResource::collection($ticket));
    }

    public function save(TicketSave $request)
    {
        $ticketService = new TicketService();
        $ticket = $ticketService->createTicket(
            $request->user()->id,
            $request->input('subject'),
            $request->input('level'),
            $request->input('message'),
            $request->file('attachments', [])
        );
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);

    }

    public function reply(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'message' => 'required|string',
            'attachments' => 'sometimes|array|max:3',
            'attachments.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:1024',
            ],
        ], [
            'id.required' => __('Invalid parameter'),
            'message.required' => __('Message cannot be empty'),
            'attachments.max' => '每轮最多上传3张图片',
            'attachments.*.image' => '附件必须是图片',
            'attachments.*.mimes' => '图片仅支持 JPG、PNG、WEBP 格式',
            'attachments.*.max' => '单张图片不能超过1MB',
        ]);
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        if ($ticket->status) {
            return $this->fail([400, __('The ticket is closed and cannot be replied')]);
        }
        $ticketService = new TicketService();
        if (
            !$ticketService->reply(
                $ticket,
                $request->input('message'),
                $request->user()->id,
                $request->file('attachments', [])
            )
        ) {
            return $this->fail([400, __('Ticket reply failed')]);
        }
        HookManager::call('ticket.reply.user.after', $ticket);
        return $this->success(true);
    }

    public function attachment(Request $request, int $attachment)
    {
        $attachmentModel = TicketMessageAttachment::whereKey($attachment)
            ->whereHas('message.ticket', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->first();

        if (!$attachmentModel) {
            abort(404);
        }

        return (new TicketAttachmentService())->inlineResponse($attachmentModel);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            return $this->fail([500, __('Close failed')]);
        }
        return $this->success(true);
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int) admin_setting('withdraw_close_enable', 0)) {
            return $this->fail([400, 'Unsupported withdraw']);
        }
        if (
            !in_array(
                $request->input('withdraw_method'),
                admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT)
            )
        ) {
            return $this->fail([422, __('Unsupported withdrawal method')]);
        }
        $user = User::find($request->user()->id);
        $limit = admin_setting('commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            return $this->fail([422, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit])]);
        }
        try {
            $ticketService = new TicketService();
            $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
            $message = sprintf(
                "%s\r\n%s",
                __('Withdrawal method') . "：" . $request->input('withdraw_method'),
                __('Withdrawal account') . "：" . $request->input('withdraw_account')
            );
            $ticket = $ticketService->createTicket(
                $request->user()->id,
                $subject,
                Ticket::TYPE_COMMISSION_WITHDRAWAL,
                $message
            );
        } catch (\Exception $e) {
            throw $e;
        }
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);
    }
}
