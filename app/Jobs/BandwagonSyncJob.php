<?php

namespace App\Jobs;

use App\Models\BandwagonVps;
use App\Services\Bandwagon\{InventoryService, BandwagonException};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

class BandwagonSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 55;
    public function __construct(public int $vpsId, public string $token)
    {
        $this->onConnection('redis')->onQueue('bandwagon');
    }
    public function handle(InventoryService $service): void
    {
        try { $service->sync($this->vpsId, $this->token); }
        catch (BandwagonException $e) { $this->release(15); }
    }
    public function failed(?\Throwable $e): void
    {
        BandwagonVps::where('id', $this->vpsId)->where('sync_token', $this->token)->update([
            'queued_at' => null, 'sync_token' => null, 'last_error' => '同步任务未完成，请检查队列后重试',
        ]);
    }
}
