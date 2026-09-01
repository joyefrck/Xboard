<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class TicketService
{
    public function reply($ticket, $message, $userId, array $attachments = [])
    {
        $attachmentService = new TicketAttachmentService();
        $storedAttachments = [];
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message,
                'is_admin' => false
            ]);
            $storedAttachments = $attachmentService->storeForMessage($ticketMessage, $attachments);
            $ticket->reply_status = Ticket::STATUS_CLOSED;
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();
            return $ticketMessage;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $attachmentService->cleanupStoredFiles($storedAttachments);
            return false;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId, array $attachments = []): void
    {
        $ticket = Ticket::where('id', $ticketId)
            ->first();
        if (!$ticket) {
            throw new ApiException('工单不存在');
        }
        $ticket->status = Ticket::STATUS_OPENING;
        $attachmentService = new TicketAttachmentService();
        $storedAttachments = [];
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message,
                'is_admin' => true
            ]);
            $storedAttachments = $attachmentService->storeForMessage($ticketMessage, $attachments);
            $ticket->reply_status = Ticket::STATUS_OPENING;
            if (!$ticketMessage || !$ticket->save()) {
                throw new ApiException('工单回复失败');
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $attachmentService->cleanupStoredFiles($storedAttachments);
            throw $e;
        }
        HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function closeByAdmin(int $ticketId, int $operatorId): void
    {
        $ticket = Ticket::find($ticketId);
        if (!$ticket) {
            throw new ApiException('工单不存在');
        }
        if ($ticket->status === Ticket::STATUS_CLOSED) {
            return;
        }

        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            throw new ApiException('关闭失败');
        }

        Log::info('管理员关闭工单', [
            'ticket_id' => $ticketId,
            'operator_id' => $operatorId,
        ]);
    }

    public function createTicket($userId, $subject, $level, $message, array $attachments = [])
    {
        $attachmentService = new TicketAttachmentService();
        $storedAttachments = [];
        try {
            DB::beginTransaction();
            if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level
            ]);
            if (!$ticket) {
                throw new ApiException('工单创建失败');
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message,
                'is_admin' => false
            ]);
            if (!$ticketMessage) {
                throw new ApiException('工单消息创建失败');
            }
            $storedAttachments = $attachmentService->storeForMessage($ticketMessage, $attachments);
            DB::commit();
            return $ticket;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $attachmentService->cleanupStoredFiles($storedAttachments);
            throw $e;
        }
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . admin_setting('app_name', 'XBoard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
        }
    }
}
