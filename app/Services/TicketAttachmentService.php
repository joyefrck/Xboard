<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TicketAttachmentService
{
    public const DISK = 'local';

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param array<int, UploadedFile> $files
     * @return array<int, TicketMessageAttachment>
     */
    public function storeForMessage(TicketMessage $message, array $files): array
    {
        if (count($files) > 3) {
            throw new ApiException('每轮最多上传3张图片');
        }

        $storedFiles = [];
        $attachments = [];

        try {
            foreach ($files as $file) {
                if (!$file instanceof UploadedFile || !$file->isValid()) {
                    throw new ApiException('无效的工单图片附件');
                }

                $mimeType = strtolower((string) $file->getMimeType());
                $extension = self::MIME_EXTENSIONS[$mimeType] ?? null;
                if ($extension === null || $file->getSize() > 1024 * 1024) {
                    throw new ApiException('工单图片附件格式或大小不符合要求');
                }

                $directory = 'ticket-attachments/' . now()->format('Y/m');
                $path = $directory . '/' . Str::uuid() . '.' . $extension;
                $stream = fopen($file->getRealPath(), 'rb');
                if ($stream === false) {
                    throw new ApiException('无法读取工单图片附件');
                }

                try {
                    $stored = Storage::disk(self::DISK)->put($path, $stream);
                } finally {
                    fclose($stream);
                }

                if (!$stored) {
                    throw new ApiException('工单图片附件写入失败');
                }

                $storedFiles[] = ['disk' => self::DISK, 'path' => $path];
                $attachments[] = TicketMessageAttachment::create([
                    'ticket_message_id' => $message->id,
                    'original_name' => $this->sanitizeOriginalName($file, $extension),
                    'disk' => self::DISK,
                    'path' => $path,
                    'mime_type' => $mimeType,
                    'size' => (int) $file->getSize(),
                ]);
            }
        } catch (Throwable $e) {
            $this->cleanupStoredFiles($storedFiles);
            throw $e;
        }

        return $attachments;
    }

    /**
     * @param iterable<int, TicketMessageAttachment|array{disk: string, path: string}> $attachments
     */
    public function cleanupStoredFiles(iterable $attachments): void
    {
        foreach ($attachments as $attachment) {
            $disk = $attachment instanceof TicketMessageAttachment ? $attachment->disk : $attachment['disk'];
            $path = $attachment instanceof TicketMessageAttachment ? $attachment->path : $attachment['path'];

            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $cleanupError) {
                Log::warning('Failed to delete ticket attachment after rollback', [
                    'disk' => $disk,
                    'path' => $path,
                    'error' => $cleanupError->getMessage(),
                ]);
            }
        }
    }

    public function inlineResponse(TicketMessageAttachment $attachment): BinaryFileResponse
    {
        $disk = Storage::disk($attachment->disk);
        if (!$disk->exists($attachment->path)) {
            abort(404);
        }

        $extension = self::MIME_EXTENSIONS[$attachment->mime_type] ?? 'img';
        $fallbackName = 'ticket-attachment-' . $attachment->id . '.' . $extension;
        $disposition = 'inline; filename="' . $fallbackName . '"; filename*=UTF-8\'\''
            . rawurlencode($attachment->original_name);

        $response = response()->file($disk->path($attachment->path), [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function sanitizeOriginalName(UploadedFile $file, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            $name = 'image.' . $extension;
        }

        return mb_substr($name, 0, 255);
    }
}
