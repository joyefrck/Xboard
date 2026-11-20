<?php

namespace App\Http\Controllers\V1;

use App\Models\{Order, Ticket};
use App\Services\{TicketPlanAccessService, TicketServiceActions};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Validator};

class TicketServiceActionController
{
    public function __construct(private TicketServiceActions $actions) {}

    public function orders(Request $request, int $ticket): JsonResponse
    {
        $input = $request->validate(['before_id' => 'sometimes|integer|min:1', 'limit' => 'sometimes|integer|between:1,100']);
        $user = Ticket::findOrFail($ticket)->user()->firstOrFail();
        $query = $user->orders()->with(['plan:id,name', 'trafficPackage:id,name'])->orderByDesc('id');
        if (isset($input['before_id'])) {
            $query->where('id', '<', $input['before_id']);
        }
        $limit = (int) ($input['limit'] ?? 30);
        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);
        return response()->json(['data' => $page->map(fn (Order $order) =>
            $order->only(['id', 'trade_no', 'period', 'type', 'status', 'total_amount', 'balance_amount', 'handling_amount', 'created_at'])
            + ['product_name' => $order->trafficPackage?->name ?? $order->plan?->name ?? ($order->isRecharge() ? '余额充值' : null)]),
            'next_before_id' => $rows->count() > $limit ? $page->last()->id : null]);
    }

    public function access(int $ticket, TicketPlanAccessService $access): JsonResponse
    {
        $data = DB::transaction(function () use ($ticket, $access) {
            $user = Ticket::findOrFail($ticket)->user()->lockForUpdate()->firstOrFail();
            return $access->context($user);
        }, 3);
        return response()->json(['data' => $data]);
    }

    public function refundPreview(Request $request, int $ticket): JsonResponse
    {
        $input = $request->validate(['trade_no' => 'required|string|max:36', 'version' => 'sometimes|required|string|size:64']);
        return response()->json(['data' => $this->actions->preview($ticket, 'refund', $request->attributes->get('ticket_service_actor'), $input['trade_no'], $input['version'] ?? null)]);
    }

    public function accessPreview(Request $request, int $ticket): JsonResponse
    {
        $input = $request->validate(['target_plan_id' => 'required|integer|min:1', 'version' => 'sometimes|required|string|size:64']);
        return response()->json(['data' => $this->actions->preview($ticket, 'plan_access', $request->attributes->get('ticket_service_actor'), (int) $input['target_plan_id'], $input['version'] ?? null)]);
    }

    public function refund(Request $request, int $ticket): JsonResponse
    {
        return $this->confirm($request, $ticket, 'refund');
    }

    public function changeAccess(Request $request, int $ticket): JsonResponse
    {
        return $this->confirm($request, $ticket, 'plan_access');
    }

    private function confirm(Request $request, int $ticket, string $action): JsonResponse
    {
        $rules = ['version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'quote' => 'required|string|max:16384', 'reason' => 'required|string|min:2|max:500'];
        if ($action === 'refund') {
            $rules['expected_amount'] = 'required|integer|min:1';
        }
        $input = $request->validate($rules);
        $key = $request->header('Idempotency-Key');
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{1,128}$/D']])->validate();
        return response()->json(['data' => $this->actions->confirm($ticket, $action, $request->attributes->get('ticket_service_actor'), $key, $input)]);
    }
}
