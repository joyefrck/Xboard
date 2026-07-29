<?php

namespace App\Services;

use App\Models\AppArtifact;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AppDownloadMirror
{
    public function targetPath(AppArtifact $artifact): string
    {
        $id = (int) $artifact->id;
        $sha256 = strtolower((string) $artifact->sha256);

        if ($id <= 0 || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new RuntimeException('Invalid app artifact mirror target.');
        }

        $filename = trim((string) Str::ascii((string) $artifact->original_name));
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? '';
        $filename = trim($filename, '._-');

        if ($filename === '') {
            $extension = strtolower(trim((string) $artifact->extension));
            $extension = preg_match('/^[a-z0-9]+$/', $extension) ? $extension : 'bin';
            $filename = 'app.' . $extension;
        }

        $target = 'artifacts/' . $id . '/' . $sha256 . '/' . $filename;
        if (!$this->isSafeMirrorPath($target)) {
            throw new RuntimeException('Invalid app artifact mirror target.');
        }

        return $target;
    }

    public function sync(AppArtifact $artifact): string
    {
        $target = $this->targetPath($artifact);
        $temporary = $target . '.part-' . Str::uuid();
        $expectedSize = (int) $artifact->file_size;
        $expectedSha256 = strtolower((string) $artifact->sha256);
        $remote = Storage::disk((string) config('app_downloads.mirror.disk'));
        $stream = Storage::disk($artifact->disk)->readStream($artifact->path);

        if ($stream === false) {
            throw new RuntimeException('Unable to read app artifact stream.');
        }

        try {
            if (
                $remote->exists($target)
                && $remote->size($target) === $expectedSize
                && hash_equals($expectedSha256, $this->remoteSha256($remote, $target))
            ) {
                return $target;
            }

            if (!$remote->put($temporary, $stream)) {
                throw new RuntimeException('Unable to upload app artifact mirror.');
            }

            if ($remote->size($temporary) !== $expectedSize) {
                throw new RuntimeException('App artifact mirror size verification failed.');
            }

            if (!hash_equals($expectedSha256, $this->remoteSha256($remote, $temporary))) {
                throw new RuntimeException('App artifact mirror SHA256 verification failed.');
            }

            $this->promoteSafely($remote, $temporary, $target);

            return $target;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            try {
                if ($remote->exists($temporary)) {
                    $remote->delete($temporary);
                }
            } catch (Throwable) {
                // A cleanup failure must not hide the upload failure.
            }
        }
    }

    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (!$this->isSafeMirrorPath($path)) {
            throw new RuntimeException('Invalid app artifact mirror path.');
        }

        $remote = Storage::disk((string) config('app_downloads.mirror.disk'));
        if ($remote->exists($path) && !$remote->delete($path)) {
            throw new RuntimeException('Unable to delete app artifact mirror.');
        }
    }

    public function sanitizeError(Throwable $error): string
    {
        $message = preg_replace('/\s+/', ' ', $error->getMessage()) ?? 'App artifact mirror failed.';
        $privateKey = (string) config('filesystems.disks.' . config('app_downloads.mirror.disk') . '.privateKey', '');

        if ($privateKey !== '') {
            $message = str_replace($privateKey, '[redacted]', $message);
        }

        $message = preg_replace(
            '/((?:password|passphrase|private[ _-]?key)\s*(?:=|:)\s*)(?:"[^"]*"|\'[^\']*\'|\S+)/i',
            '$1[redacted]',
            $message
        ) ?? 'App artifact mirror failed.';

        return Str::limit(trim($message), 497, '...');
    }

    private function remoteSha256(FilesystemAdapter $remote, string $path): string
    {
        try {
            $stream = $remote->readStream($path);
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to verify app artifact mirror.', 0, $error);
        }

        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to verify app artifact mirror.');
        }

        try {
            $context = hash_init('sha256');
            if (hash_update_stream($context, $stream) === false) {
                throw new RuntimeException('Unable to verify app artifact mirror.');
            }

            return hash_final($context);
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to verify app artifact mirror.', 0, $error);
        } finally {
            fclose($stream);
        }
    }

    private function promoteSafely(FilesystemAdapter $remote, string $temporary, string $target): void
    {
        if (!$remote->exists($target)) {
            if (!$remote->move($temporary, $target)) {
                throw new RuntimeException('Unable to finalize app artifact mirror.');
            }

            return;
        }

        $backup = $target . '.backup-' . Str::uuid();
        try {
            if (!$remote->move($target, $backup)) {
                throw new RuntimeException('Unable to stage existing app artifact mirror.');
            }
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to stage existing app artifact mirror.', 0, $error);
        }

        try {
            if (!$remote->move($temporary, $target)) {
                throw new RuntimeException('Unable to finalize app artifact mirror.');
            }
        } catch (Throwable $error) {
            try {
                if ($remote->exists($target)) {
                    $remote->delete($target);
                }
                if ($remote->exists($backup)) {
                    $remote->move($backup, $target);
                }
            } catch (Throwable) {
                // Preserve the promotion failure if rollback itself also fails.
            }

            throw new RuntimeException('Unable to finalize app artifact mirror.', 0, $error);
        }

        try {
            if ($remote->exists($backup)) {
                $remote->delete($backup);
            }
        } catch (Throwable) {
            // A backup cleanup failure must not change a successful promotion.
        }
    }

    private function isSafeMirrorPath(string $path): bool
    {
        return (bool) preg_match(
            '/\Aartifacts\/[1-9][0-9]*\/[a-f0-9]{64}\/[A-Za-z0-9][A-Za-z0-9._-]*\z/',
            $path
        );
    }
}
