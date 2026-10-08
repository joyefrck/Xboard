<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Latest observation per VPS, separate from durable DNS operation history. */
class NodeAutomationCheck extends Model
{
    protected $table = 'v2_node_automation_checks';
    protected $guarded = ['id'];
    protected $casts = ['nodes' => 'array', 'checked_at' => 'datetime'];
}
