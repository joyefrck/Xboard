<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramGroupRequest extends Model
{
    protected $table = 'v2_telegram_group_requests';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'requested_at' => 'integer',
        'attempts' => 'integer',
    ];
}
