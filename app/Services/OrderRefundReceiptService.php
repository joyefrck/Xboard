<?php

namespace App\Services;

use Brick\Math\BigInteger;

class OrderRefundReceiptService
{
    /** Allocate a single credit without repeating its total on every source order. */
    public static function allocate(int $amount, array $scope): array
    {
        $total = BigInteger::zero();
        $weights = [];
        foreach ($scope['state']['orders'] as $order) {
            $weights[$order['id']] = max(0, (int) $order['total_amount'] + (int) $order['balance_amount']
                + (int) $order['surplus_amount'] - (int) $order['refund_amount']);
            $total = $total->plus($weights[$order['id']]);
        }
        if ($total->isZero()) throw new \RuntimeException('退款分配基数无效');
        $tradeNumbers = array_column($scope['related_orders'], 'trade_no', 'id');
        $rows = []; $remaining = $amount;
        foreach ($scope['state']['orders'] as $order) {
            [$value, $remainder] = BigInteger::of($amount)->multipliedBy($weights[$order['id']])->quotientAndRemainder($total);
            $rows[] = [
                'order_id' => (int) $order['id'], 'trade_no' => $tradeNumbers[$order['id']],
                'order_amount' => (int) $order['total_amount'], 'balance_payment' => (int) $order['balance_amount'],
                'order_created_at' => (int) $order['created_at'], 'amount' => $value->toInt(), 'remainder' => $remainder,
            ];
            $remaining -= $value->toInt();
        }
        usort($rows, fn ($a, $b) => $b['remainder']->compareTo($a['remainder']) ?: $a['order_id'] <=> $b['order_id']);
        foreach ($rows as &$row) {
            if ($remaining > 0) { $row['amount']++; $remaining--; }
            unset($row['remainder']);
        }
        unset($row);
        usort($rows, fn ($a, $b) => $a['order_id'] <=> $b['order_id']);
        return $rows;
    }
}
