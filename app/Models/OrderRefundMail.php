<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderRefundMail extends Model
{
    protected $table = 'v2_order_refund_mail';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['params'];
    protected $casts = ['params' => 'array', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
