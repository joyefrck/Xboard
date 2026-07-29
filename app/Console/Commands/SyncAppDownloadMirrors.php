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
        $queuedCount = 0;
        $skippedCount = 0;
        $query = AppArtifact::query()->whereHas('version')->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $query->each(function (AppArtifact $artifact) use ($storage, $force, &$queuedCount, &$skippedCount): void {
            if (!$storage->exists($artifact)) {
                $skippedCount++;
                $this->warn('Skipped artifact #' . $artifact->id . ': local file is missing.');

                return;
            }

            if (!$force && $artifact->mirror_status === AppArtifact::MIRROR_READY) {
                $skippedCount++;
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
                $skippedCount++;
                $this->warn('Skipped artifact #' . $artifact->id . ': artifact changed while queuing.');

                return;
            }

            SyncAppArtifactMirror::dispatch($artifact->id, $sha256)->afterCommit();
            $queuedCount++;
            $this->info('Queued artifact #' . $artifact->id . '.');
        });

        $this->line(sprintf('Queued: %d; skipped: %d.', $queuedCount, $skippedCount));

        return self::SUCCESS;
    }

    private function artifactIds(): ?array
    {
        $ids = [];
        foreach ((array) $this->option('artifact') as $value) {
            $value = (string) $value;
            $id = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => PHP_INT_MAX,
                ],
            ]);

            if ($value !== trim($value) || $id === false || (string) $id !== $value) {
                $this->error('Artifact ids must be positive integers.');

                return null;
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
