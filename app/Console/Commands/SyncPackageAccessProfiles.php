<?php

namespace App\Console\Commands;

use App\Services\TrafficPackageService;
use Illuminate\Console\Command;

class SyncPackageAccessProfiles extends Command
{
    protected $signature = 'traffic:sync-package-access {--dry-run : Report candidates without updating users} {--user-id=* : Restrict to user IDs}';
    protected $description = 'Synchronize stale package access after periodic plan expiry or exhaustion';

    public function __construct(private TrafficPackageService $packages)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = $this->packages->getPackageAccessSyncCandidatesQuery();
        $ids = $this->option('user-id');
        foreach ($ids as $id) {
            if (!ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('Each --user-id must be a positive integer.');
                return self::FAILURE;
            }
        }
        if ($ids) {
            $query->whereIn('v2_user.id', array_map('intval', $ids));
        }
        $candidates = (clone $query)->count();
        $updated = $failed = $checked = 0;
        if (!$this->option('dry-run')) {
            $query->chunkById(100, function ($users) use (&$updated, &$failed, &$checked) {
                foreach ($users as $user) {
                    $checked++;
                    try {
                        $updated += (int) $this->packages->syncPackageAccessForUser($user->id);
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error('Access sync failed for user_id=' . $user->id . ' (' . get_class($e) . ')');
                    }
                }
            }, 'v2_user.id', 'id');
        }
        $this->info("candidates={$candidates} checked={$checked} updated={$updated} failed={$failed}");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
