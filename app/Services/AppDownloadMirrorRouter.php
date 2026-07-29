<?php

namespace App\Services;

use App\Models\AppArtifact;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class AppDownloadMirrorRouter
{
    public function __construct(private AppDownloadMirrorUrlSigner $signer)
    {
    }

    public function redirectUrl(AppArtifact $artifact): ?string
    {
        if (!config('app_downloads.mirror.enabled', false)) {
            return null;
        }

        if ($artifact->mirror_status !== AppArtifact::MIRROR_READY) {
            return null;
        }

        if ($artifact->mirror_path === null || $artifact->mirror_path === '') {
            return null;
        }

        try {
            $url = $this->sign($artifact);
            $cacheKey = 'app_download_mirror:health:' . $artifact->id . ':' . hash('sha256', $artifact->mirror_path);
            $healthy = Cache::remember(
                $cacheKey,
                (int) config('app_downloads.mirror.health_cache_seconds', 45),
                function () use ($url): bool {
                    return Http::connectTimeout(1)
                        ->timeout((int) config('app_downloads.mirror.health_timeout_seconds', 2))
                        ->head($url)
                        ->successful();
                }
            );

            return $healthy ? $url : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function sign(AppArtifact $artifact): string
    {
        return $this->signer->urlFor($artifact);
    }
}
