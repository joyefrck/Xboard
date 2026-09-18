<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramGroupEntitlement extends Model
{
    protected $table = 'v2_telegram_group_entitlements';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
