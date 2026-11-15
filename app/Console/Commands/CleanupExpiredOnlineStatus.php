<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupExpiredOnlineStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:expired-online-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset online_count to 0 for users stale for 5+ minutes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $affected = 0;
            $cutoff = now()->subMinutes(5);
            User::query()
                ->select('id')
                ->where('online_count', '>', 0)
                ->where('last_online_at', '<', $cutoff)
                ->chunkById(1000, function ($users) use (&$affected, $cutoff) {
                    foreach ($users as $user) {
                        // Never hold locks across users, and recheck after a
                        // concurrent online report or row-lock wait completes.
                        $affected += DB::transaction(fn () => User::query()
                            ->whereKey($user->id)
                            ->where('online_count', '>', 0)
                            ->where('last_online_at', '<', $cutoff)
                            ->update(['online_count' => 0]), 3);
                    }
                }, 'id');

            $this->info("Expired online status cleaned. Affected: {$affected}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('CleanupExpiredOnlineStatus failed', ['error' => $e->getMessage()]);
            $this->error('Cleanup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
