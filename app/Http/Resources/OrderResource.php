<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        // Admin notes, operator IDs, mail payloads and balance history stay private.
        unset($data['balance_refund'], $data['balance_refund_entry']);
        $refund = $this->resource->relationLoaded('balanceRefund') ? $this->resource->balanceRefund : null;
        $entry = $this->resource->relationLoaded('balanceRefundEntry') ? $this->resource->balanceRefundEntry : null;
        return [
            ...$data,
            'refund' => (int) $this->status === Order::STATUS_REFUNDED && $refund ? [
                'amount' => $entry?->amount ?? ((int) $refund->order_id === (int) $this->id ? $refund->amount : 0),
                'created_at' => $refund->created_at,
                'destination' => 'balance',
                'group_total' => $refund->amount,
                'group_order_count' => count($refund->snapshot['order_ids'] ?? [$refund->order_id]),
            ] : null,
            'period' => PlanService::getLegacyPeriod((string)$this->period),
            'plan' => $this->whenLoaded('plan', fn() => PlanResource::make($this->plan)),
            'traffic_package' => $this->whenLoaded('trafficPackage', fn() => TrafficPackageResource::make($this->trafficPackage)),
            'payment' => $this->whenLoaded('payment', fn() => $this->payment ? [
                'id' => $this->payment->id,
                'name' => $this->payment->name,
                'payment' => $this->payment->payment,
                'icon' => $this->payment->icon,
            ] : null),
        ];
    }
}
