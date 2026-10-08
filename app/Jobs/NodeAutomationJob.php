<?php

namespace App\Jobs;

use App\Services\NodeAutomation\{AutomationService, AutomationException};
use App\Models\NodeAutomationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class NodeAutomationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 540;
    public $backoff = 15;
    public $uniqueFor = 660;
    public function uniqueId(): string { return 'node-automation:' . $this->groupId; }
    public function __construct(public int $groupId, public int $scheduledAt, public ?int $eventId = null)
    {
        $this->onConnection('node_automation')->onQueue('node_automation');
    }
    public function handle(AutomationService $service): void
    {
        try {
            if ($this->eventId) $service->runOperation($this->eventId);
            else $service->tick($this->groupId, $this->scheduledAt);
        } catch (AutomationException $e) {
            $this->release(15);
        }
    }
    public function failed(?\Throwable $exception): void
    {
        if (!$this->eventId) return;
        // Prepared writes stay recoverable. Do not erase DNS intent on worker death.
        NodeAutomationLog::where('id', $this->eventId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'message' => '检测任务未完成，请检查队列或探针配置']);
    }
}
