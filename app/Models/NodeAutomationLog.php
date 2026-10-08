<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeAutomationLog extends Model
{
    protected $table = 'v2_node_automation_logs';
    protected $guarded = ['id'];
    protected $casts = ['record_before' => 'array', 'result' => 'array'];
}
