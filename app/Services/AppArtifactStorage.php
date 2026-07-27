<?php

namespace App\Services;

use App\Models\AppArtifact;
use App\Models\AppVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class AppArtifactStorage
{
    private const DISK = 'app_downloads';
    private const ALLOWED_EXTENSIONS = ['apk', 'dmg', 'pkg', 'exe', 'msi', 'msix', 'zip'];
    private const MIME_TYPES_BY_EXTENSION = [
        'apk' => 'application/vnd.android.package-archive',
    ];
    private const MAX_BYTES = 1024 * 1024 * 1024 * 2;

    public function store(AppVersion $version, UploadedFile $file, ?int $userId): AppArtifact
    {
        $existingArtifact = $version->artifact;
        $storedFile = $this->writeUploadedFile($version, $file, $userId);

        try {
            $artifact = AppArtifact::updateOrCreate(
                ['app_version_id' => $version->id],
                $storedFile
            );
        } catch (Throwable $e) {
            $this->cleanupFailedFile($storedFile, $version->id);
            throw $e;
        }

        if ($existingArtifact && $existingArtifact->path !== $storedFile['path']) {
            $this->cleanupReplacedFile(
                $existingArtifact->disk,
                $existingArtifact->path,
                $version->id
            );
        }

        return $artifact;
    }

    public function updateVersion(
        AppVersion $version,
        array $attributes,
        ?UploadedFile $file,
        ?int $userId
    ): AppVersion {
        $storedFile = $file ? $this->writeUploadedFile($version, $file, $userId) : null;
        $replacedFile = null;

        try {
            $updatedVersion = DB::transaction(function () use (
                $version,
                $attributes,
                $storedFile,
                &$replacedFile
            ) {
                $lockedVersion = AppVersion::query()
                    ->whereKey($version->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $artifact = null;
                if ($storedFile) {
                    $artifact = AppArtifact::query()
                        ->where('app_version_id', $lockedVersion->id)
                        ->lockForUpdate()
                        ->first();

                    if ($artifact) {
                        $replacedFile = [
                            'disk' => $artifact->disk,
                            'path' => $artifact->path,
                        ];
                    }
                }

                $lockedVersion->update($attributes);

                if ($storedFile) {
                    if ($artifact) {
                        $artifact->update($storedFile);
                    } else {
                        AppArtifact::create([
                            'app_version_id' => $lockedVersion->id,
                            ...$storedFile,
                        ]);
                    }
                }

                return $lockedVersion;
            }, 3);
        } catch (Throwable $e) {
            if ($storedFile) {
                $this->cleanupFailedFile($storedFile, $version->id);
            }
            throw $e;
        }

        if ($replacedFile && $replacedFile['path'] !== $storedFile['path']) {
            $this->cleanupReplacedFile(
                $replacedFile['disk'],
                $replacedFile['path'],
                $version->id
            );
        }

        return $updatedVersion;
    }

    public function absolutePath(AppArtifact $artifact): string
    {
        return Storage::disk($artifact->disk)->path($artifact->path);
    }

    public function exists(AppArtifact $artifact): bool
    {
        return Storage::disk($artifact->disk)->exists($artifact->path);
    }

    public function downloadMimeType(AppArtifact $artifact): string
    {
        $extension = strtolower($artifact->extension ?: pathinfo($artifact->original_name, PATHINFO_EXTENSION));

        return self::MIME_TYPES_BY_EXTENSION[$extension]
            ?? $artifact->mime_type
            ?? 'application/octet-stream';
    }

    public function deleteFile(AppArtifact $artifact): void
    {
        Storage::disk($artifact->disk)->delete($artifact->path);
    }

    private function writeUploadedFile(
        AppVersion $version,
        UploadedFile $file,
        ?int $userId
    ): array {
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('仅支持 APK、DMG、PKG、EXE、MSI、MSIX、ZIP 安装包');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('安装包不能超过 2GB');
        }

        $relativePath = implode('/', [
            $version->app_id,
            $version->platform,
            $version->channel,
            $version->build_number . '-' . Str::random(16) . '.' . $extension,
        ]);
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('无法读取上传的安装包');
        }

        try {
            $stored = Storage::disk(self::DISK)->put($relativePath, $stream);
        } finally {
            fclose($stream);
        }

        if (!$stored) {
            throw new InvalidArgumentException('安装包写入失败');
        }

        try {
            $absolutePath = Storage::disk(self::DISK)->path($relativePath);
            $fileSize = filesize($absolutePath);
            $sha256 = hash_file('sha256', $absolutePath);
            if ($fileSize === false || $sha256 === false) {
                throw new InvalidArgumentException('无法读取已上传安装包的文件信息');
            }

            return [
                'disk' => self::DISK,
                'path' => $relativePath,
                'original_name' => $file->getClientOriginalName(),
                'extension' => $extension,
                'mime_type' => self::MIME_TYPES_BY_EXTENSION[$extension] ?? $file->getMimeType(),
                'file_size' => $fileSize,
                'sha256' => $sha256,
                'uploaded_by' => $userId,
            ];
        } catch (Throwable $e) {
            $this->cleanupFailedFile([
                'disk' => self::DISK,
                'path' => $relativePath,
            ], $version->id);
            throw $e;
        }
    }

    private function cleanupFailedFile(array $storedFile, int $versionId): void
    {
        try {
            $this->deleteStoredFile($storedFile['disk'], $storedFile['path']);
        } catch (Throwable $cleanupError) {
            Log::warning('Failed to delete staged app artifact after version update failure', [
                'app_version_id' => $versionId,
                'disk' => $storedFile['disk'],
                'path' => $storedFile['path'],
                'error' => $cleanupError->getMessage(),
            ]);
        }
    }

    private function cleanupReplacedFile(string $disk, string $path, int $versionId): void
    {
        try {
            $this->deleteStoredFile($disk, $path);
        } catch (Throwable $cleanupError) {
            Log::warning('Failed to delete replaced app artifact', [
                'app_version_id' => $versionId,
                'disk' => $disk,
                'path' => $path,
                'error' => $cleanupError->getMessage(),
            ]);
        }
    }

    private function deleteStoredFile(string $disk, string $path): void
    {
        $storage = Storage::disk($disk);
        if ($storage->exists($path) && !$storage->delete($path)) {
            throw new \RuntimeException('Unable to delete app artifact file');
        }
    }
}
