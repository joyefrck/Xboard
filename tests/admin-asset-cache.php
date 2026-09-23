<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../scripts/version-admin-assets.php';

$checks = 0;
function checkAdminCache(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$root = sys_get_temp_dir() . '/xboard-admin-cache-' . bin2hex(random_bytes(8));
mkdir($root . '/public/assets/admin/assets', 0777, true);
mkdir($root . '/public/assets/admin/locales', 0777, true);
mkdir($root . '/resources/views', 0777, true);

try {
    $fixtures = [
        'assets/index.js' => 'import{r}from"./vendor.js?v=old";import"./index.js?v=old";',
        'assets/vendor.js' => 'import{a}from"./index.js?v=old";export const r=1;',
        'assets/vendor.css' => '@font-face{src:url(./codicon.ttf)}',
        'assets/index.css' => 'body{color:black}',
        'assets/codicon.ttf' => 'font-content',
        'locales/en-US.js' => 'window.en={};',
        'locales/zh-CN.js' => 'window.cn={};',
        'locales/ko-KR.js' => 'window.ko={};',
    ];
    foreach ($fixtures as $path => $content) {
        file_put_contents($root . '/public/assets/admin/' . $path, $content);
    }
    $view = '<link rel="modulepreload" href="/assets/admin/assets/vendor.js?v=old">';
    foreach (array_keys($fixtures) as $path) {
        if (!str_ends_with($path, '.ttf')) {
            $view .= '<script src="/assets/admin/' . $path . '?v=old"></script>';
        }
    }
    file_put_contents($root . '/resources/views/admin.blade.php', $view);

    $first = adminAssetVersionPlan($root);
    checkAdminCache(count($first['changes']) === 4, 'Graph, font and entry URLs must be updated together');
    foreach ($first['changes'] as $path => $content) {
        file_put_contents($root . '/' . $path, $content);
        checkAdminCache(!str_contains($content, '?v=old'), 'No stale dependency URL may remain');
    }
    $second = adminAssetVersionPlan($root);
    checkAdminCache($second['changes'] === [], 'Version generation must be idempotent');
    checkAdminCache($second['version'] === $first['version'], 'Version must not hash itself');

    foreach (['assets/index.js', 'assets/vendor.js', 'assets/index.css', 'locales/zh-CN.js', 'assets/codicon.ttf'] as $path) {
        $file = $root . '/public/assets/admin/' . $path;
        $original = file_get_contents($file);
        file_put_contents($file, $original . 'changed');
        $changed = adminAssetVersionPlan($root);
        checkAdminCache($changed['version'] !== $first['version'], 'Changing ' . $path . ' must invalidate the cache');
        checkAdminCache(count($changed['changes']) === 4, 'Every module URL must move to the new graph version');
        checkAdminCache(file_get_contents($file) === $original . 'changed', 'Planning/checking must be read-only');
        file_put_contents($file, $original);
    }

    $app = new Illuminate\Foundation\Application(dirname(__DIR__));
    $config = require __DIR__ . '/../config/octane.php';
    $policies = $config['static_file_headers'] ?? [];
    foreach (['/assets/admin/assets/index.js?v=test', '/assets/admin/assets/vendor.css?v=test', '/assets/admin/assets/codicon.ttf?v=test', '/assets/admin/locales/zh-CN.js?v=test'] as $url) {
        $request = Illuminate\Http\Request::create($url);
        $matches = array_filter($policies, fn ($headers, $pattern) => $request->is($pattern), ARRAY_FILTER_USE_BOTH);
        checkAdminCache(count($matches) === 1, 'Exactly one cache policy must match ' . $url);
        checkAdminCache(array_values($matches)[0]['Cache-Control'] === 'public, max-age=31536000, immutable', 'Expected one-year cache');
    }
    foreach (['/api/v2/user/info', '/cfc29397', '/assets/admin/index.html', '/assets/admin/locales/private.json', '/storage/attachment.png', '/theme/ElephantRoute/assets/umi.js'] as $url) {
        $request = Illuminate\Http\Request::create($url);
        foreach ($policies as $pattern => $headers) {
            checkAdminCache(!$request->is($pattern), 'Admin cache policy must not affect ' . $url);
        }
    }
    echo 'PASS: ' . $checks . " admin cache checks\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
