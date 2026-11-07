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
    // Old serialized payloads have no origin: treat them as automatic work.
    public bool $automatic = true;

    public function __construct(public int $vpsId, public string $token, bool $automatic = false)
    {
        $this->automatic = $automatic;
        $this->onConnection('redis')->onQueue('bandwagon');
    }
    public function handle(InventoryService $service): void
    {
        try { $service->sync($this->vpsId, $this->token, $this->automatic); }
        catch (BandwagonException $e) { $this->release(15); }
    }
    public function failed(?\Throwable $e): void
    {
        BandwagonVps::where('id', $this->vpsId)->where('sync_token', $this->token)->update([
            'queued_at' => null, 'sync_token' => null, 'last_error' => '同步任务未完成，请检查队列后重试',
        ]);
    }
}
