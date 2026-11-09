<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderRefundOrder extends Model
{
    protected $table = 'v2_order_refund_order';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'integer', 'order_amount' => 'integer', 'balance_payment' => 'integer', 'order_created_at' => 'integer'];
}
