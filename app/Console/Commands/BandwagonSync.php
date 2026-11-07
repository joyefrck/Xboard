<?php

namespace App\Console\Commands;

use App\Models\BandwagonVps;
use App\Services\Bandwagon\{InventoryService, BandwagonException, SyncSettings};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BandwagonSync extends Command
{
    protected $signature = 'bandwagon:sync';
    protected $description = 'Queue cached Bandwagon VPS information refreshes';

    public function handle(InventoryService $service): int
    {
        if (!Schema::hasTable('v2_bandwagon_vps') || !SyncSettings::enabled()) return self::SUCCESS;
        BandwagonVps::select('id')->chunkById(100, function ($rows) use ($service) {
            foreach ($rows as $row) {
                try { $service->enqueue($row->id, true); } catch (BandwagonException $e) { /* Next scheduled pass retries. */ }
            }
        });
        return self::SUCCESS;
    }
}
