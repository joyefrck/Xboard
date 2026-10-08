<?php

namespace App\Console\Commands;

use App\Jobs\NodeAutomationJob;
use App\Models\{NodeAutomationGroup, NodeAutomationLog};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, Schema};

class NodeAutomationCheck extends Command
{
    protected $signature = 'node-automation:check';
    protected $description = '派发节点自动化检测与中断操作恢复';

    public function handle(): int
    {
        if (!Schema::hasTable('v2_node_automation_groups')) return self::SUCCESS;
        $pending = NodeAutomationLog::whereIn('status', ['queued', 'running', 'prepared', 'verifying', 'dns_unknown'])->pluck('group_id');
        $ids = NodeAutomationGroup::where('enabled', true)->orWhereIn('id', $pending)->pluck('id');
        foreach ($ids as $id) {
            // Limit queue growth if the worker is down; use a per-minute dedupe key.
            $minute = intdiv(time(), 60);
            if (!Cache::add("node-automation:dispatch:{$id}:{$minute}", true, 120)) continue;
            $event = NodeAutomationLog::where('group_id', $id)
                ->whereIn('status', ['queued', 'running', 'prepared', 'verifying', 'dns_unknown'])->oldest('id')->first();
            NodeAutomationJob::dispatch((int) $id, time(), $event?->id);
        }
        return self::SUCCESS;
    }
}
