<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeAutomationSetting extends Model
{
    protected $table = 'v2_node_automation_settings';
    protected $guarded = ['id'];
    protected $hidden = ['probe_user_id'];
    protected $casts = ['probe_user_id' => 'integer', 'probe_users_by_group' => 'array'];
}
