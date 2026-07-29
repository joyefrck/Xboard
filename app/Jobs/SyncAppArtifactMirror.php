<?php

namespace App\Jobs;

use App\Models\AppArtifact;
use App\Services\AppDownloadMirror;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncAppArtifactMirror implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 1800;
    public $backoff = [60, 300];

    public function __construct(
        public readonly int $artifactId,
        public readonly string $expectedSha256
    ) {
        $this->onConnection('redis_mirror');
        $this->onQueue('app_download_mirror');
    }

    public function handle(AppDownloadMirror $mirror): void
    {
        Cache::lock('app-download-mirror:artifact:' . $this->artifactId, 1850)->block(5, function () use ($mirror): void {
            $artifact = AppArtifact::query()->find($this->artifactId);
            if (!$artifact) {
                return;
            }

            if (!hash_equals($this->expectedSha256, $artifact->sha256)) {
                return;
            }

            $oldMirrorPath = $artifact->mirror_path;
            $markedSyncing = AppArtifact::query()
                ->whereKey($artifact->id)
                ->where('sha256', $this->expectedSha256)
                ->update([
                    'mirror_status' => AppArtifact::MIRROR_SYNCING,
                    'mirror_error' => null,
                ]);

            if ($markedSyncing !== 1) {
                return;
            }

            $newMirrorPath = $mirror->sync($artifact);
            $updated = AppArtifact::query()
                ->whereKey($artifact->id)
                ->where('sha256', $this->expectedSha256)
                ->update([
                    'mirror_status' => AppArtifact::MIRROR_READY,
                    'mirror_path' => $newMirrorPath,
                    'mirror_error' => null,
                    'mirrored_at' => now(),
                ]);

            if ($updated !== 1) {
                try {
                    $mirror->delete($newMirrorPath);
                } catch (Throwable $cleanupError) {
                    Log::warning('Failed to delete stale app artifact mirror', [
                        'artifact_id' => $this->artifactId,
                        'error' => $mirror->sanitizeError($cleanupError),
                    ]);
                }

                return;
            }

            if ($oldMirrorPath && $oldMirrorPath !== $newMirrorPath) {
                try {
                    $mirror->delete($oldMirrorPath);
                } catch (Throwable $cleanupError) {
                    Log::warning('Failed to delete replaced app artifact mirror', [
                        'artifact_id' => $this->artifactId,
                        'error' => $mirror->sanitizeError($cleanupError),
                    ]);
                }
            }
        });
    }

    public function failed(Throwable $error): void
    {
        $artifact = AppArtifact::query()->find($this->artifactId);
        if (!$artifact || !hash_equals($this->expectedSha256, $artifact->sha256)) {
            return;
        }

        AppArtifact::query()
            ->whereKey($artifact->id)
            ->where('sha256', $this->expectedSha256)
            ->update([
                'mirror_status' => AppArtifact::MIRROR_FAILED,
                'mirror_error' => app(AppDownloadMirror::class)->sanitizeError($error),
            ]);
    }
}
