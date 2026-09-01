<?php

declare(strict_types=1);

use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Services\TicketAttachmentService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__) . '/vendor/autoload.php';

$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$storageRoot = sys_get_temp_dir() . '/xboard-ticket-attachments-' . bin2hex(random_bytes(8));
mkdir($storageRoot, 0700, true);

$container = $capsule->getContainer();
$config = new Repository($container['config']->toArray());
$config->set('filesystems', [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => $storageRoot,
            'throw' => false,
        ],
    ],
]);
$container->instance('config', $config);
$container->singleton('filesystem', fn ($app) => new FilesystemManager($app));
$container->instance(ResponseFactoryContract::class, new class extends ResponseFactory {
    public function __construct()
    {
    }
});
Container::setInstance($container);
Facade::setFacadeApplication($container);

$schema = $capsule->schema();
$schema->create('v2_ticket', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('user_id');
    $table->string('subject');
    $table->integer('level');
    $table->integer('status')->default(0);
    $table->integer('reply_status')->default(0);
    $table->integer('created_at');
    $table->integer('updated_at');
});
$schema->create('v2_ticket_message', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('ticket_id');
    $table->integer('user_id');
    $table->text('message');
    $table->integer('created_at');
    $table->integer('updated_at');
});
$schema->create('v2_ticket_message_attachment', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('ticket_message_id')->index();
    $table->string('original_name', 255);
    $table->string('disk', 32);
    $table->string('path', 512);
    $table->string('mime_type', 64);
    $table->unsignedInteger('size');
    $table->integer('created_at');
    $table->integer('updated_at');
});

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
};
$assertTrue = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pngBytes = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    true
);
if ($pngBytes === false) {
    throw new RuntimeException('failed to prepare PNG fixture');
}

$uploadPaths = [];
$makeUpload = static function (int $size, string $name) use ($pngBytes, &$uploadPaths): UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'xboard-ticket-upload-');
    if ($path === false) {
        throw new RuntimeException('failed to create upload fixture');
    }
    $uploadPaths[] = $path;

    file_put_contents($path, $pngBytes);
    $stream = fopen($path, 'c+b');
    if ($stream === false || !ftruncate($stream, $size)) {
        throw new RuntimeException('failed to size upload fixture');
    }
    fclose($stream);

    return new UploadedFile($path, $name, 'image/png', null, true);
};
$makeRawUpload = static function (string $bytes, string $name) use (&$uploadPaths): UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'xboard-ticket-upload-');
    if ($path === false) {
        throw new RuntimeException('failed to create raw upload fixture');
    }
    $uploadPaths[] = $path;
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, 'image/png', null, true);
};

$capsule->table('v2_ticket')->insert([
    'id' => 10,
    'user_id' => 20,
    'subject' => 'runtime attachment ticket',
    'level' => 0,
    'status' => 0,
    'reply_status' => 0,
    'created_at' => time(),
    'updated_at' => time(),
]);

$message = TicketMessage::create([
    'ticket_id' => 10,
    'user_id' => 20,
    'message' => 'runtime attachment test',
]);
$service = new TicketAttachmentService();

try {
    $capsule->getConnection()->beginTransaction();
    $stored = $service->storeForMessage($message, [
        $makeUpload(1024 * 1024, "../error\x01.png"),
    ]);
    $capsule->getConnection()->commit();

    $assertSame(1, count($stored), '1 MiB image is accepted');
    $attachment = $stored[0];
    $assertSame('error.png', $attachment->original_name, 'original filename is sanitized');
    $assertSame('image/png', $attachment->mime_type, 'detected MIME is persisted');
    $assertSame(1024 * 1024, $attachment->size, 'exact byte size is persisted');
    $assertTrue(
        preg_match('#^ticket-attachments/\d{4}/\d{2}/[0-9a-f-]{36}\.png$#', $attachment->path) === 1,
        'storage path is date-scoped and randomized'
    );
    $assertTrue(Storage::disk('local')->exists($attachment->path), 'stored image exists');
    $response = $service->inlineResponse($attachment);
    $assertSame('image/png', $response->headers->get('Content-Type'), 'inline response keeps real MIME');
    $cacheControl = (string) $response->headers->get('Cache-Control');
    $assertTrue(str_contains($cacheControl, 'private'), 'inline response is private');
    $assertTrue(str_contains($cacheControl, 'no-store'), 'inline response disables storage');
    $assertTrue(!str_contains($cacheControl, 'public'), 'inline response is never public');
    $assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'inline response disables sniffing');
    $assertTrue(
        str_starts_with((string) $response->headers->get('Content-Disposition'), 'inline;'),
        'inline response does not force a download'
    );
    $serializedKeys = array_keys($attachment->toArray());
    sort($serializedKeys);
    $assertSame(['id', 'mime_type', 'name', 'size'], $serializedKeys, 'serialized metadata is safe');
    $assertTrue(
        TicketMessageAttachment::whereKey($attachment->id)
            ->whereHas('message.ticket', fn ($query) => $query->where('user_id', 20))
            ->exists(),
        'ticket owner can resolve attachment metadata'
    );
    $assertTrue(
        !TicketMessageAttachment::whereKey($attachment->id)
            ->whereHas('message.ticket', fn ($query) => $query->where('user_id', 21))
            ->exists(),
        'another user cannot resolve attachment metadata'
    );

    $three = $service->storeForMessage($message, [
        $makeUpload(1024, 'one.png'),
        $makeUpload(1024, 'two.png'),
        $makeUpload(1024, 'three.png'),
    ]);
    $assertSame(3, count($three), 'three images are accepted in one round');

    try {
        $service->storeForMessage($message, [
            $makeUpload(1024, 'one.png'),
            $makeUpload(1024, 'two.png'),
            $makeUpload(1024, 'three.png'),
            $makeUpload(1024, 'four.png'),
        ]);
        throw new RuntimeException('four images were unexpectedly accepted');
    } catch (Throwable $error) {
        if ($error->getMessage() === 'four images were unexpectedly accepted') {
            throw $error;
        }
    }

    foreach ([
        $makeRawUpload("GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'forged.png'),
        $makeRawUpload('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'forged.png'),
    ] as $forgedUpload) {
        try {
            $service->storeForMessage($message, [$forgedUpload]);
            throw new RuntimeException('forged image was unexpectedly accepted');
        } catch (Throwable $error) {
            if ($error->getMessage() === 'forged image was unexpectedly accepted') {
                throw $error;
            }
        }
    }

    $rowCount = TicketMessageAttachment::count();
    try {
        $capsule->getConnection()->beginTransaction();
        $service->storeForMessage($message, [
            $makeUpload(1024, 'first.png'),
            $makeUpload(1024 * 1024 + 1, 'too-large.png'),
        ]);
        throw new RuntimeException('oversized image was unexpectedly accepted');
    } catch (Throwable $error) {
        if ($capsule->getConnection()->transactionLevel() > 0) {
            $capsule->getConnection()->rollBack();
        }
        if ($error->getMessage() === 'oversized image was unexpectedly accepted') {
            throw $error;
        }
    }
    $assertSame($rowCount, TicketMessageAttachment::count(), 'failed round rolls back attachment rows');
    $assertSame(4, count(Storage::disk('local')->allFiles()), 'failed round removes files already written');

    $service->cleanupStoredFiles([$attachment, ...$three]);
    $assertTrue(!Storage::disk('local')->exists($attachment->path), 'explicit rollback cleanup removes file');
} finally {
    foreach (Storage::disk('local')->allFiles() as $path) {
        Storage::disk('local')->delete($path);
    }
    Storage::disk('local')->deleteDirectory('ticket-attachments');
    @rmdir($storageRoot);
    foreach ($uploadPaths as $path) {
        @unlink($path);
    }
}

echo "ticket image attachment runtime test passed\n";
