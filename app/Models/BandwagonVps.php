<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BandwagonVps extends Model
{
    protected $table = 'v2_bandwagon_vps';
    protected $guarded = ['id'];
    protected $hidden = ['api_key', 'sync_token'];
    protected $casts = [
        'api_key' => 'encrypted', 'info' => 'array', 'live' => 'array',
        'synced_at' => 'datetime', 'live_synced_at' => 'datetime', 'attempted_at' => 'datetime',
        'queued_at' => 'datetime', 'reset_requested_at' => 'datetime',
    ];
}
