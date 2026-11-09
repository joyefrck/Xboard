<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderRefund extends Model
{
    protected $table = 'v2_order_refund';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'snapshot' => 'array',
        'amount' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
