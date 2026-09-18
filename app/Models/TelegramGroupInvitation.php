<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramGroupInvitation extends Model
{
    protected $table = 'v2_telegram_group_invitations';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $hidden = ['invite_link', 'link_hash'];
    protected $casts = [
        'invite_link' => 'encrypted',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'expires_at' => 'integer',
        'used_at' => 'integer',
        'revoked_at' => 'integer',
    ];
}
