<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBlacklistEvent extends Model
{
    protected $table = 'v2_user_blacklist_event';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'context' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'muted_until' => 'integer',
    ];
}
