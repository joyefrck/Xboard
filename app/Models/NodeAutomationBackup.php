<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeAutomationBackup extends Model
{
    protected $table = 'v2_node_automation_backups';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'tested_at' => 'datetime'];
}
