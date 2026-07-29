<?php

namespace App\Services;

use App\Models\AppArtifact;
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
        $remote = Storage::disk((string) config('app_downloads.mirror.disk'));
        $stream = Storage::disk($artifact->disk)->readStream($artifact->path);

        if ($stream === false) {
            throw new RuntimeException('Unable to read app artifact stream.');
        }

        $completed = false;

        try {
            if ($remote->exists($target) && $remote->size($target) === $expectedSize) {
                return $target;
            }

            if (!$remote->put($temporary, $stream)) {
                throw new RuntimeException('Unable to upload app artifact mirror.');
            }

            if ($remote->size($temporary) !== $expectedSize) {
                throw new RuntimeException('App artifact mirror size verification failed.');
            }

            if ($remote->exists($target) && !$remote->delete($target)) {
                throw new RuntimeException('Unable to replace app artifact mirror.');
            }

            if (!$remote->move($temporary, $target)) {
                throw new RuntimeException('Unable to finalize app artifact mirror.');
            }

            $completed = true;

            return $target;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (!$completed) {
                try {
                    if ($remote->exists($temporary)) {
                        $remote->delete($temporary);
                    }
                } catch (Throwable) {
                    // A cleanup failure must not hide the upload failure.
                }
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

        return Str::limit(trim($message), 500, '...');
    }

    private function isSafeMirrorPath(string $path): bool
    {
        if (!str_starts_with($path, 'artifacts/') || str_contains($path, '\\')) {
            return false;
        }

        $segments = explode('/', $path);

        return count($segments) >= 4
            && !in_array('', $segments, true)
            && !in_array('.', $segments, true)
            && !in_array('..', $segments, true);
    }
}
