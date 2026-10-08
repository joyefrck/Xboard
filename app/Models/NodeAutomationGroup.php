<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeAutomationGroup extends Model
{
    protected $table = 'v2_node_automation_groups';
    protected $guarded = ['id'];
    protected $hidden = ['probe_users']; // Retired per-node settings are never exposed or used.
    protected $casts = [
        'probe_users' => 'array', 'enabled' => 'boolean', 'failures' => 'integer',
        'last_checked_at' => 'datetime', 'last_switched_at' => 'datetime',
        'cooldown_until' => 'datetime', 'propagation_until' => 'datetime',
    ];
    public function connection() { return $this->belongsTo(NodeAutomationConnection::class, 'connection_id'); }
    public function backups() { return $this->hasMany(NodeAutomationBackup::class, 'group_id')->orderBy('priority')->orderBy('id'); }
}
