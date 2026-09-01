<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessageAttachment;
use App\Services\TicketAttachmentService;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    private function applyFiltersAndSorts(Request $request, $builder)
    {
        if ($request->has('filter')) {
            collect($request->input('filter'))->each(function ($filter) use ($builder) {
                $key = $filter['id'];
                $value = $filter['value'];
                $builder->where(function ($query) use ($key, $value) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else {
                        $query->where($key, 'like', "%{$value}%");
                    }
                });
            });
        }

        if ($request->has('sort')) {
            collect($request->input('sort'))->each(function ($sort) use ($builder) {
                $key = $sort['id'];
                $value = $sort['desc'] ? 'DESC' : 'ASC';
                $builder->orderBy($key, $value);
            });
        }
    }
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            return $this->fetchTicketById($request);
        } else {
            return $this->fetchTickets($request);
        }
    }

    /**
     * Summary of fetchTicketById
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function fetchTicketById(Request $request)
    {
        $ticket = Ticket::with('messages.attachments', 'user')->find($request->input('id'));

        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }
        $result = $ticket->toArray();
        $result['user'] = UserController::transformUserData($ticket->user);

        return $this->success($result);
    }

    /**
     * Summary of fetchTickets
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    private function fetchTickets(Request $request)
    {
        $request->validate([
            'email' => 'sometimes|nullable|string|max:255'
        ]);

        $email = trim((string) $request->input('email', ''));

        $ticketModel = Ticket::with('user')
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->has('reply_status'), function ($query) use ($request) {
                $query->whereIn('reply_status', $request->input('reply_status'));
            })
            ->when($email !== '', function ($query) use ($email) {
                $query->whereHas('user', function ($q) use ($email) {
                    $q->where('email', 'like', "%{$email}%");
                });
            });

        $this->applyFiltersAndSorts($request, $ticketModel);
        $tickets = $ticketModel
            ->latest('updated_at')
            ->paginate(
                perPage: $request->integer('pageSize', 10),
                page: $request->integer('current', 1)
            );

        // 获取items然后映射转换
        $items = collect($tickets->items())->map(function ($ticket) {
            $ticketData = $ticket->toArray();
            $ticketData['user'] = UserController::transformUserData($ticket->user);
            return $ticketData;
        })->all();

        return response([
            'data' => $items,
            'total' => $tickets->total()
        ]);
    }

    public function reply(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric',
            'message' => 'required|string',
            'attachments' => 'sometimes|array|max:3',
            'attachments.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:1024',
            ],
        ], [
            'id.required' => '工单ID不能为空',
            'message.required' => '消息不能为空',
            'attachments.max' => '每轮最多上传3张图片',
            'attachments.*.image' => '附件必须是图片',
            'attachments.*.mimes' => '图片仅支持 JPG、PNG、WEBP 格式',
            'attachments.*.max' => '单张图片不能超过1MB',
        ]);
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $request->input('message'),
            $request->user()->id,
            $request->file('attachments', [])
        );
        return $this->success(true);
    }

    public function attachment(int $attachment)
    {
        $attachmentModel = TicketMessageAttachment::find($attachment);
        if (!$attachmentModel) {
            abort(404);
        }

        return (new TicketAttachmentService())->inlineResponse($attachmentModel);
    }

    public function updateRemarks(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'remarks' => 'present|nullable|string|max:1000'
        ], [
            'id.required' => '工单ID不能为空',
            'remarks.present' => '备注参数不能为空',
            'remarks.max' => '备注不能超过1000个字符'
        ]);

        $ticket = Ticket::find($request->input('id'));
        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }

        $remarks = $request->input('remarks') === null
            ? null
            : trim($request->input('remarks'));
        $remarks = $remarks === '' ? null : $remarks;

        $ticket->timestamps = false;
        $ticket->remarks = $remarks;
        $ticket->save();

        return $this->success([
            'remarks' => $ticket->remarks
        ]);
    }

    public function close(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '工单ID不能为空'
        ]);
        try {
            $ticketService = new TicketService();
            $ticketService->closeByAdmin(
                (int) $request->input('id'),
                (int) $request->user()->id
            );
            return $this->success(true);
        } catch (\Exception $e) {
            return $this->fail([500101, $e->getMessage() ?: '关闭失败']);
        }
    }

    public function show($ticketId)
    {
        $ticket = Ticket::with([
            'user',
            'messages' => function ($query) {
                $query->with(['user', 'attachments']); // 如果需要用户信息
            }
        ])->findOrFail($ticketId);

        // 自动包含 is_me 属性
        return response()->json([
            'data' => $ticket
        ]);
    }
}
