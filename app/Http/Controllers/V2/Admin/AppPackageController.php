<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use App\Models\DistributionApp;
use App\Services\AppArtifactStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AppPackageController extends Controller
{
    public function apps(Request $request)
    {
        $apps = DistributionApp::query()
            ->withCount('versions')
            ->when($request->input('keyword'), function ($query, $keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('name', 'like', "%{$keyword}%")
                        ->orWhere('app_key', 'like', "%{$keyword}%");
                });
            })
            ->orderBy('name')
            ->get();

        return $this->success($apps);
    }

    public function saveApp(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_distribution_apps,id',
            'name' => 'required|string|max:128',
            'app_key' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
            ],
            'platform' => [
                'nullable',
                'string',
                Rule::in(['android', 'windows', 'macos', 'ios', 'linux']),
            ],
            'description' => 'nullable|string|max:2000',
            'distribution_scope' => [
                'nullable',
                'string',
                Rule::in(DistributionApp::scopes()),
            ],
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['app_key'] = trim((string) ($data['app_key'] ?? ''));
        $data['distribution_scope'] = $data['distribution_scope']
            ?? DistributionApp::SCOPE_DOWNLOAD_ONLY;
        $platform = strtolower(trim((string) ($data['platform'] ?? '')));
        unset($data['platform']);

        $officialAppKey = DistributionApp::officialAppKeyForPlatform($platform);
        $officialAppName = DistributionApp::officialAppNameForPlatform($platform);
        if ($data['distribution_scope'] === DistributionApp::SCOPE_OFFICIAL_UPDATE
            && $officialAppKey
            && $officialAppName) {
            $data['app_key'] = $officialAppKey;
            $data['name'] = $officialAppName;
            // Package publishing resolves identity from the platform. Ignore a
            // stale or forged app id and reuse only the canonical app below.
            unset($data['id']);
        }

        if ($data['app_key'] !== '') {
            $existingByKey = DistributionApp::query()
                ->where('app_key', $data['app_key'])
                ->first();
            if ($existingByKey) {
                $data['id'] = $existingByKey->id;
            }
        }

        if (empty($data['id']) && empty($data['app_key'])) {
            $data['app_key'] = Str::slug($data['name']);
            if (!$data['app_key']) {
                $data['app_key'] = 'app-' . Str::lower(Str::random(8));
            }
        }

        if (empty($data['id']) && empty($request->input('app_key'))) {
            $existingByName = DistributionApp::query()
                ->where('name', $data['name'])
                ->first();
            if ($existingByName) {
                $data['id'] = $existingByName->id;
                $data['app_key'] = $existingByName->app_key;
            }
        }

        if (empty($data['id'])) {
            $existingByKey = DistributionApp::query()
                ->where('app_key', $data['app_key'])
                ->first();
            if ($existingByKey) {
                $data['id'] = $existingByKey->id;
            }
        }

        $existingApp = empty($data['id']) ? null : DistributionApp::findOrFail($data['id']);
        if ($existingApp
            && $existingApp->distribution_scope !== $data['distribution_scope']
            && $existingApp->versions()->exists()) {
            return $this->fail([400, '已有版本的应用不能修改应用类型']);
        }

        $isReservedKey = DistributionApp::isReservedAppKey($data['app_key']);
        if ($data['distribution_scope'] === DistributionApp::SCOPE_DOWNLOAD_ONLY && $isReservedKey) {
            return $this->fail([400, '第三方应用不能使用大象官方保留标识']);
        }
        if ($data['distribution_scope'] === DistributionApp::SCOPE_OFFICIAL_UPDATE && !$isReservedKey) {
            return $this->fail([400, '大象官方应用必须使用平台对应的官方标识']);
        }

        $app = empty($data['id'])
            ? DistributionApp::create($data)
            : tap($existingApp)->update($data);

        return $this->success($app);
    }

    public function dropApp(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_distribution_apps,id']);
        $app = DistributionApp::withCount('versions')->findOrFail($request->input('id'));
        if ($app->versions_count > 0) {
            return $this->fail([400, '请先删除该应用下的版本包']);
        }

        $app->delete();

        return $this->success(true);
    }

    public function versions(Request $request)
    {
        $versions = AppVersion::query()
            ->with('app')
            ->whereNotNull('app_id')
            ->when($request->input('app_id'), fn ($query, $appId) => $query->where('app_id', $appId))
            ->when($request->input('platform'), fn ($query, $platform) => $query->where('platform', strtolower($platform)))
            ->when($request->input('channel'), fn ($query, $channel) => $query->where('channel', strtolower($channel)))
            ->orderByDesc('published_at')
            ->orderByDesc('build_number')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20));

        return $this->paginate($versions);
    }

    public function saveVersion(Request $request)
    {
        $request->merge([
            'arch' => $request->input('arch') ?: null,
            'sha256' => $request->input('sha256') ?: null,
            'channel' => $request->input('channel') ?: 'stable',
        ]);

        $data = $request->validate([
            'id' => 'prohibited',
            'app_id' => 'required|integer|exists:v2_distribution_apps,id',
            'platform' => ['required', 'string', 'max:32', Rule::in(['android', 'windows', 'macos', 'ios', 'linux'])],
            'channel' => ['nullable', 'string', 'max:32', Rule::in(['stable', 'beta'])],
            'arch' => 'nullable|string|max:32',
            'version' => 'required|string|max:32',
            'build_number' => 'required|integer|min:1',
            'min_supported_build' => 'nullable|integer|min:0',
            'release_notes' => 'nullable|string|max:20000',
            'is_force' => 'nullable|boolean',
            'is_enabled' => 'nullable|boolean',
            'published_at' => 'nullable|integer|min:0',
            'download_url' => 'required|string|url|max:2048',
            'file_size_mb' => 'nullable|numeric|min:0',
            'sha256' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);

        $data['platform'] = strtolower($data['platform']);
        $data['channel'] = strtolower($data['channel'] ?? 'stable');
        $data['arch'] = $data['arch'] ? strtolower($data['arch']) : null;
        $data['min_supported_build'] = (int) ($data['min_supported_build'] ?? 0);
        $data['is_force'] = (bool) ($data['is_force'] ?? false);
        $data['is_enabled'] = (bool) ($data['is_enabled'] ?? false);
        $data['published_at'] = $data['published_at'] ?? ($data['is_enabled'] ? time() : null);

        $app = DistributionApp::findOrFail($data['app_id']);
        if ($app->isOfficialUpdate()) {
            $officialAppKey = DistributionApp::officialAppKeyForPlatform($data['platform']);
            if (!$officialAppKey || $app->app_key !== $officialAppKey) {
                return $this->fail([400, '官方应用标识与发布平台不匹配']);
            }
        } elseif (DistributionApp::isReservedAppKey($app->app_key)) {
            return $this->fail([400, '第三方应用不能使用大象官方保留标识']);
        }

        try {
            $data = $this->normalizeExternalVersionData($data, $app, $data['platform']);
            $version = AppVersion::create($data);
        } catch (InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Throwable $e) {
            \Log::error($e);
            return $this->fail([500, '保存失败，请检查应用、平台、渠道、架构和构建号是否重复']);
        }

        return $this->success($version->load('app'));
    }

    public function updateVersion(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_app_versions,id',
            'version' => 'required|string|max:32',
            'release_notes' => 'nullable|string|max:20000',
            'download_url' => 'required|string|url|max:2048',
            'file_size_mb' => 'nullable|numeric|min:0',
            'sha256' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'app_id' => 'prohibited',
            'platform' => 'prohibited',
            'channel' => 'prohibited',
            'arch' => 'prohibited',
            'build_number' => 'prohibited',
            'min_supported_build' => 'prohibited',
            'is_force' => 'prohibited',
            'is_enabled' => 'prohibited',
            'published_at' => 'prohibited',
        ]);

        $version = AppVersion::with('app')->findOrFail($data['id']);

        try {
            $attributes = $this->normalizeExternalVersionData($data, $version->app, $version->platform);
            unset($attributes['id']);
            $version->update($attributes);
        } catch (InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Throwable $e) {
            \Log::error($e);
            return $this->fail([500, '更新版本失败，原版本和安装包未更改']);
        }

        return $this->success(true);
    }

    public function publish(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_app_versions,id']);
        $version = AppVersion::with('app')->findOrFail($request->input('id'));
        try {
            $this->assertExternalVersionMetadata(
                (string) $version->download_url,
                $version->sha256,
                $version->app,
                $version->platform
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail([400, $e->getMessage()]);
        }

        $version->update([
            'is_enabled' => true,
            'published_at' => $version->published_at ?: time(),
        ]);

        return $this->success(true);
    }

    public function disable(Request $request)
    {
        $request->validate(['id' => 'required|integer|exists:v2_app_versions,id']);
        AppVersion::findOrFail($request->input('id'))->update(['is_enabled' => false]);

        return $this->success(true);
    }

    public function drop(Request $request, AppArtifactStorage $storage)
    {
        $request->validate(['id' => 'required|integer|exists:v2_app_versions,id']);
        $version = AppVersion::with('artifact')->findOrFail($request->input('id'));
        if ($version->isPublished()) {
            return $this->fail([400, '已发布版本不能直接删除，请先下架']);
        }

        if ($version->artifact) {
            $storage->deleteFile($version->artifact);
        }
        $version->delete();

        return $this->success(true);
    }

    private function normalizeExternalVersionData(
        array $data,
        DistributionApp $app,
        string $platform
    ): array {
        $downloadUrl = trim((string) $data['download_url']);
        $sha256 = strtolower(trim((string) ($data['sha256'] ?? '')));
        $sha256 = $sha256 !== '' ? $sha256 : null;

        $this->assertExternalVersionMetadata($downloadUrl, $sha256, $app, $platform);

        $fileSizeMb = $data['file_size_mb'] ?? null;
        $data['download_url'] = $downloadUrl;
        $data['file_size'] = $fileSizeMb === null || $fileSizeMb === ''
            ? null
            : (int) round((float) $fileSizeMb * 1024 * 1024);
        $data['sha256'] = $sha256;
        unset($data['file_size_mb']);

        return $data;
    }

    private function assertExternalVersionMetadata(
        string $downloadUrl,
        ?string $sha256,
        DistributionApp $app,
        string $platform
    ): void {
        $parts = parse_url($downloadUrl);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])) {
            throw new InvalidArgumentException('下载链接必须是完整的 HTTPS 地址');
        }

        if ($sha256 !== null && !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new InvalidArgumentException('SHA256 必须是 64 位十六进制字符串');
        }

        if ($app->isOfficialUpdate() && strtolower($platform) === 'macos' && $sha256 === null) {
            throw new InvalidArgumentException('macOS 官方更新必须填写有效的 SHA256');
        }
    }
}
