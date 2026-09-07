<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;

class AppDownloadController extends Controller
{
    public function index()
    {
        $versions = AppVersion::query()
            ->with('app')
            ->whereNotNull('app_id')
            ->where('is_enabled', true)
            ->whereNotNull('published_at')
            ->whereNotNull('download_url')
            ->where('download_url', '<>', '')
            ->where('download_url', 'like', 'https://%')
            ->whereHas('app', fn ($query) => $query->where('is_active', true))
            ->orderBy('platform')
            ->orderBy('app_id')
            ->orderByDesc('build_number')
            ->orderByDesc('published_at')
            ->get()
            ->filter(fn (AppVersion $version) => $version->hasSecureDownloadUrl())
            ->values();

        $platforms = $versions
            ->groupBy('platform')
            ->map(function ($platformVersions, $platform) {
                return [
                    'platform' => $platform,
                    'apps' => $platformVersions
                        ->groupBy('app_id')
                        ->map(function ($appVersions) {
                            $app = $appVersions->first()->app;
                            return [
                                'id' => $app->id,
                                'name' => $app->name,
                                'app_key' => $app->app_key,
                                'description' => $app->description,
                                'packages' => $appVersions->map(fn (AppVersion $version) => [
                                    'version_id' => $version->id,
                                    'artifact_id' => $version->id,
                                    'platform' => $version->platform,
                                    'channel' => $version->channel,
                                    'arch' => $version->arch,
                                    'version' => $version->version,
                                    'build_number' => $version->build_number,
                                    'min_supported_build' => $version->min_supported_build,
                                    'file_size' => $version->file_size,
                                    'sha256' => $version->sha256,
                                    'download_url' => $version->download_url,
                                    'release_notes' => $version->release_notes,
                                    'is_force' => $version->is_force,
                                    'published_at' => $version->published_at,
                                ])->values(),
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        return $this->success([
            'platforms' => $platforms,
        ]);
    }
}
