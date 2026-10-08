<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeAutomationConnection extends Model
{
    protected $table = 'v2_node_automation_connections';
    protected $guarded = ['id'];
    protected $hidden = ['api_token'];
    protected $casts = ['api_token' => 'encrypted', 'verified_at' => 'datetime'];
}
