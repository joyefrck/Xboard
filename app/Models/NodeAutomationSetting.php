<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeAutomationSetting extends Model
{
    protected $table = 'v2_node_automation_settings';
    protected $guarded = ['id'];
    protected $casts = ['probe_user_id' => 'integer'];

    public function probeUser() { return $this->belongsTo(User::class, 'probe_user_id'); }
}
