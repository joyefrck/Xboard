<?php

namespace App\Services\Bandwagon;

use App\Models\Setting;
use Illuminate\Support\Facades\{Cache, DB};

class SyncSettings
{
    public const KEY = 'bandwagon_auto_sync_enabled';

    public static function enabled(): bool
    {
        // Read fresh for every automatic operation, including long-running workers.
        return (int) (Setting::where('name', self::KEY)->value('value') ?? 1) === 1;
    }

    public static function save(bool $enabled): bool
    {
        $lock = Cache::lock('bandwagon:auto-sync-settings', 10);
        if (!$lock->get()) throw new BandwagonException('自动同步设置正在保存，请稍后重试');
        try {
            return DB::transaction(function () use ($enabled) {
                admin_setting([self::KEY => $enabled ? '1' : '0']);
                return self::enabled();
            });
        } finally { $lock->release(); }
    }
}
