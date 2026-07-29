<?php

namespace App\Jobs;

use App\Services\AppDownloadMirror;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteAppArtifactMirror implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [60, 300];

    public function __construct(public readonly string $path)
    {
        $this->onConnection('redis_mirror');
        $this->onQueue('app_download_mirror');
    }

    public function handle(AppDownloadMirror $mirror): void
    {
        $mirror->delete($this->path);
    }
}
