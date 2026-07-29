<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppArtifact extends Model
{
    public const MIRROR_LOCAL = 'local';
    public const MIRROR_PENDING = 'pending';
    public const MIRROR_SYNCING = 'syncing';
    public const MIRROR_READY = 'ready';
    public const MIRROR_FAILED = 'failed';

    public const MIRROR_STATUSES = [
        self::MIRROR_LOCAL,
        self::MIRROR_PENDING,
        self::MIRROR_SYNCING,
        self::MIRROR_READY,
        self::MIRROR_FAILED,
    ];

    protected $table = 'v2_app_artifacts';

    protected $fillable = [
        'app_version_id',
        'disk',
        'path',
        'original_name',
        'extension',
        'mime_type',
        'file_size',
        'sha256',
        'uploaded_by',
        'mirror_status',
        'mirror_path',
        'mirror_error',
        'mirrored_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'mirrored_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'app_version_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function downloadLogs(): HasMany
    {
        return $this->hasMany(AppDownloadLog::class, 'app_artifact_id');
    }
}
