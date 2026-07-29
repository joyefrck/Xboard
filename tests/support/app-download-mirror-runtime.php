<?php

declare(strict_types=1);

use App\Models\AppArtifact;
use App\Services\AppDownloadMirror;
use App\Services\AppDownloadMirrorRouter;
use App\Services\AppDownloadMirrorUrlSigner;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function mirrorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mirrorArtifact(int $id, string $mirrorPath): AppArtifact
{
    $artifact = new AppArtifact();
    $artifact->id = $id;
    $artifact->mirror_status = AppArtifact::MIRROR_READY;
    $artifact->mirror_path = $mirrorPath;

    return $artifact;
}

function configureMirror(string $baseUrl = 'https://mirror.test', string $key = 'fixture-signing-key'): void
{
    config([
        'app_downloads.mirror.enabled' => true,
        'app_downloads.mirror.base_url' => $baseUrl,
        'app_downloads.mirror.signing_key' => $key,
        'app_downloads.mirror.url_ttl_seconds' => 600,
        'app_downloads.mirror.health_cache_seconds' => 60,
        'app_downloads.mirror.health_timeout_seconds' => 1,
    ]);
}

class MirrorFailingPromotionFilesystem extends FilesystemAdapter
{
    public bool $failPromotion = true;

    public function move($from, $to)
    {
        if ($this->failPromotion && str_contains($from, '.part-')) {
            $this->failPromotion = false;

            return false;
        }

        return parent::move($from, $to);
    }
}

function mirrorSyncArtifact(string $contents): AppArtifact
{
    $artifact = new AppArtifact();
    $artifact->id = 91;
    $artifact->disk = 'mirror_runtime_source';
    $artifact->path = 'source/package.bin';
    $artifact->original_name = 'package.bin';
    $artifact->extension = 'bin';
    $artifact->file_size = strlen($contents);
    $artifact->sha256 = hash('sha256', $contents);

    return $artifact;
}

function configureMirrorStorage(): void
{
    Storage::fake('mirror_runtime_source');
    Storage::fake('mirror_runtime_remote');
    config(['app_downloads.mirror.disk' => 'mirror_runtime_remote']);
}

try {
    config(['cache.default' => 'array']);
    $app->forgetInstance('cache');
    $app->forgetInstance('cache.store');
    Cache::clearResolvedInstances();
    Cache::flush();

    $signer = new AppDownloadMirrorUrlSigner();
    $router = new AppDownloadMirrorRouter($signer);
    $expiresAt = Carbon::createFromTimestampUTC(1800000000);

    configureMirror();
    mirrorAssert(
        $signer->url('artifacts/50/abc/ElephantNetwork-Setup-x64-v1.6.6.exe', $expiresAt)
            === 'https://mirror.test/files/artifacts/50/abc/ElephantNetwork-Setup-x64-v1.6.6.exe?md5=vu3Yo33ecUPO1ROEJu4anw&expires=1800000000',
        'ASCII signing fixture did not match'
    );
    mirrorAssert(
        $signer->url('artifacts/中文/space file.apk', $expiresAt)
            === 'https://mirror.test/files/artifacts/%E4%B8%AD%E6%96%87/space%20file.apk?md5=6XcsXovNYemRQaD8vL4z7g&expires=1800000000',
        'UTF-8 signing fixture did not match'
    );
    mirrorAssert(
        str_contains($signer->url('artifacts/%2F/file.apk', $expiresAt), '/files/artifacts/%252F/file.apk?'),
        'percent-encoded slash was not kept inside one path segment'
    );
    mirrorAssert(
        str_contains($signer->url('artifacts/?/#', $expiresAt), '/files/artifacts/%3F/%23?'),
        'reserved path characters were not URL-encoded'
    );

    foreach (['', 'a//b', 'a/', './a', '../a', "a/\xFF"] as $invalidPath) {
        $rejected = false;
        try {
            $signer->url($invalidPath, $expiresAt);
        } catch (RuntimeException) {
            $rejected = true;
        }
        mirrorAssert($rejected, 'Invalid path was accepted: ' . bin2hex($invalidPath));
    }

    foreach ([
        'http://mirror.test',
        'https://',
        'https://user@mirror.test',
        'https://mirror.test/path',
        'https://mirror.test?query=1',
        'https://mirror.test#fragment',
    ] as $invalidBaseUrl) {
        configureMirror($invalidBaseUrl);
        $rejected = false;
        try {
            $signer->url('artifacts/test.apk', $expiresAt);
        } catch (RuntimeException) {
            $rejected = true;
        }
        mirrorAssert($rejected, 'Invalid base URL was accepted: ' . $invalidBaseUrl);
    }

    configureMirror();
    $requestCount = 0;
    Http::swap(new Factory());
    Http::fake(['*' => function () use (&$requestCount) {
        $requestCount++;
        return Http::response('', 200);
    }]);
    $artifact = mirrorArtifact(1, 'artifacts/router.apk');
    mirrorAssert($router->redirectUrl($artifact) !== null, 'Direct 200 mirror probe did not redirect');
    mirrorAssert($router->redirectUrl($artifact) !== null, 'Cached 200 mirror probe did not redirect');
    mirrorAssert($requestCount === 1, 'Cached health check repeated the HTTP request');

    configureMirror('https://second-mirror.test');
    mirrorAssert($router->redirectUrl($artifact) !== null, 'Changed mirror origin did not redirect');
    mirrorAssert($requestCount === 2, 'Changed mirror origin reused the old health cache entry');

    configureMirror('https://second-mirror.test', 'rotated-fixture-signing-key');
    mirrorAssert($router->redirectUrl($artifact) !== null, 'Rotated mirror key did not redirect');
    mirrorAssert($requestCount === 3, 'Rotated mirror key reused the old health cache entry');

    foreach ([302, 404, 410] as $status) {
        $statusRequestCount = 0;
        Http::swap(new Factory());
        Http::fake(['*' => function () use (&$statusRequestCount, $status) {
            $statusRequestCount++;
            return Http::response('', $status);
        }]);
        mirrorAssert($router->redirectUrl(mirrorArtifact(1000 + $status, "artifacts/status-{$status}.apk")) === null, "HTTP {$status} was healthy");
        mirrorAssert($statusRequestCount === 1, "HTTP {$status} was not probed exactly once");
    }

    Http::swap(new Factory());
    Http::fake(['*' => function (): void {
        throw new ConnectionException('fixture connection failure');
    }]);
    mirrorAssert($router->redirectUrl(mirrorArtifact(2001, 'artifacts/connection.apk')) === null, 'Connection failure did not fail closed');

    Http::swap(new Factory());
    Http::fake(['*' => function (): void {
        throw new RuntimeException('fixture unexpected failure');
    }]);
    mirrorAssert($router->redirectUrl(mirrorArtifact(2002, 'artifacts/throwable.apk')) === null, 'Throwable did not fail closed');

    configureMirror('http://mirror.test');
    $invalidBaseRequestCount = 0;
    Http::swap(new Factory());
    Http::fake(['*' => function () use (&$invalidBaseRequestCount) {
        $invalidBaseRequestCount++;
        return Http::response('', 200);
    }]);
    mirrorAssert($router->redirectUrl(mirrorArtifact(2003, 'artifacts/invalid-base.apk')) === null, 'Invalid base URL did not fail closed in router');
    mirrorAssert($invalidBaseRequestCount === 0, 'Invalid base URL issued a health request');

    configureMirrorStorage();
    $mirror = new AppDownloadMirror();
    $artifact = mirrorSyncArtifact('new');
    Storage::disk($artifact->disk)->put($artifact->path, 'new');
    $target = $mirror->targetPath($artifact);
    Storage::disk('mirror_runtime_remote')->put($target, 'old');
    $mirror->sync($artifact);
    mirrorAssert(
        Storage::disk('mirror_runtime_remote')->get($target) === 'new',
        'Same-size remote content with a different SHA256 was treated as ready'
    );

    configureMirrorStorage();
    $artifact = mirrorSyncArtifact('new');
    Storage::disk($artifact->disk)->put($artifact->path, 'new');
    $target = $mirror->targetPath($artifact);
    $baseRemote = Storage::disk('mirror_runtime_remote');
    $baseRemote->put($target, 'old');
    $failingRemote = new MirrorFailingPromotionFilesystem(
        $baseRemote->getDriver(),
        $baseRemote->getAdapter(),
        $baseRemote->getConfig()
    );
    Storage::set('mirror_runtime_remote', $failingRemote);
    $promotionFailed = false;
    try {
        $mirror->sync($artifact);
    } catch (RuntimeException) {
        $promotionFailed = true;
    }
    mirrorAssert($promotionFailed, 'Promotion failure was not surfaced');
    mirrorAssert($failingRemote->get($target) === 'old', 'Promotion failure did not restore the old target');
    mirrorAssert(
        array_filter($failingRemote->allFiles('artifacts'), fn (string $path): bool => str_contains($path, '.part-')) === [],
        'Promotion failure left a temporary mirror file behind'
    );

    $invalidDeletePath = false;
    try {
        $mirror->delete('artifacts/91/short/package.bin');
    } catch (RuntimeException) {
        $invalidDeletePath = true;
    }
    mirrorAssert($invalidDeletePath, 'Mirror delete accepted a non-SHA256 path');

    Cache::flush();
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]) . PHP_EOL;
    exit(1);
}
