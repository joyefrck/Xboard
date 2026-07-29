<?php

namespace App\Console\Commands;

use App\Jobs\SyncAppArtifactMirror;
use App\Models\AppArtifact;
use App\Services\AppArtifactStorage;
use Illuminate\Console\Command;

class SyncAppDownloadMirrors extends Command
{
    protected $signature = 'app-downloads:sync-mirrors
        {--artifact=* : Only queue these artifact ids}
        {--force : Queue artifacts that are already ready}';

    protected $description = 'Queue local app artifacts for mirror synchronization';

    public function handle(AppArtifactStorage $storage): int
    {
        if (!config('app_downloads.mirror.sync_enabled')) {
            $this->error('App download mirror synchronization is disabled.');

            return self::FAILURE;
        }

        $ids = $this->artifactIds();
        if ($ids === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $query = AppArtifact::query()->whereHas('version')->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $query->each(function (AppArtifact $artifact) use ($storage, $force): void {
            if (!$storage->exists($artifact)) {
                $this->warn('Skipped artifact #' . $artifact->id . ': local file is missing.');

                return;
            }

            if (!$force && $artifact->mirror_status === AppArtifact::MIRROR_READY) {
                $this->line('Skipped artifact #' . $artifact->id . ': mirror is already ready.');

                return;
            }

            $sha256 = (string) $artifact->sha256;
            $queued = AppArtifact::query()
                ->whereKey($artifact->id)
                ->where('sha256', $sha256)
                ->update([
                    'mirror_status' => AppArtifact::MIRROR_PENDING,
                    'mirror_error' => null,
                    'mirrored_at' => null,
                ]);

            if ($queued !== 1) {
                $this->warn('Skipped artifact #' . $artifact->id . ': artifact changed while queuing.');

                return;
            }

            SyncAppArtifactMirror::dispatch($artifact->id, $sha256)->afterCommit();
            $this->info('Queued artifact #' . $artifact->id . '.');
        });

        return self::SUCCESS;
    }

    private function artifactIds(): ?array
    {
        $ids = [];
        foreach ((array) $this->option('artifact') as $value) {
            $value = trim((string) $value);
            if (!preg_match('/^[1-9][0-9]*$/', $value)) {
                $this->error('Artifact ids must be positive integers.');

                return null;
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }
}
