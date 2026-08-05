<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DistributionApp extends Model
{
    public const SCOPE_DOWNLOAD_ONLY = 'download_only';

    public const SCOPE_OFFICIAL_UPDATE = 'official_update';

    private const OFFICIAL_APP_KEYS_BY_PLATFORM = [
        'android' => 'elephant-route-android',
        'windows' => 'elephant-route-desktop',
        'macos' => 'elephant-route-desktop',
    ];

    private const OFFICIAL_APP_NAMES_BY_PLATFORM = [
        'android' => '大象网络官方App安卓版',
        'windows' => '大象网络官方App桌面版',
        'macos' => '大象网络官方App桌面版',
    ];

    private const LEGACY_OFFICIAL_APP_KEYS = [
        'elephant-route-mac',
    ];

    protected $table = 'v2_distribution_apps';

    protected $fillable = [
        'name',
        'app_key',
        'description',
        'distribution_scope',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function scopes(): array
    {
        return [self::SCOPE_DOWNLOAD_ONLY, self::SCOPE_OFFICIAL_UPDATE];
    }

    public static function officialAppKeyForPlatform(string $platform): ?string
    {
        return self::OFFICIAL_APP_KEYS_BY_PLATFORM[strtolower($platform)] ?? null;
    }

    public static function officialAppNameForPlatform(string $platform): ?string
    {
        return self::OFFICIAL_APP_NAMES_BY_PLATFORM[strtolower($platform)] ?? null;
    }

    public static function isReservedAppKey(string $appKey): bool
    {
        $normalized = strtolower(trim($appKey));

        return in_array($normalized, self::OFFICIAL_APP_KEYS_BY_PLATFORM, true)
            || in_array($normalized, self::LEGACY_OFFICIAL_APP_KEYS, true);
    }

    public function isOfficialUpdate(): bool
    {
        return $this->distribution_scope === self::SCOPE_OFFICIAL_UPDATE;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AppVersion::class, 'app_id');
    }
}
