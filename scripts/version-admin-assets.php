<?php

/** Keep the compiled admin module graph and its HTML entry on one content version. */
function adminAssetVersionPlan(string $root): array
{
    $files = [];
    foreach (['assets', 'locales'] as $directory) {
        foreach (glob($root . '/public/assets/admin/' . $directory . '/*') as $path) {
            if (is_file($path) && in_array(pathinfo($path, PATHINFO_EXTENSION), ['js', 'css', 'ttf'], true)) {
                $files[substr($path, strlen($root) + 1)] = file_get_contents($path);
            }
        }
    }
    ksort($files);

    // index and vendor import each other. Normalize their URLs to avoid hashing
    // the previous version into the next one (and changing it on every run).
    $moduleReference = '~((?:\./)?(?:index|vendor)\.js)\?v=[A-Za-z0-9_-]+~';
    $fontReference = '~(\./codicon\.ttf)(?:\?v=[A-Za-z0-9_-]+)?~';
    $hash = hash_init('sha256');
    foreach ($files as $path => $content) {
        $normalized = preg_replace($moduleReference, '$1?v=VERSION', $content);
        if (str_ends_with($path, '/vendor.css')) {
            $normalized = preg_replace($fontReference, '$1?v=VERSION', $normalized);
        }
        hash_update($hash, $path . "\0" . $normalized . "\0");
    }
    $version = 'admin-' . substr(hash_final($hash), 0, 20);
    $changes = [];
    foreach (['assets/index.js', 'assets/vendor.js', 'assets/vendor.css'] as $asset) {
        $path = 'public/assets/admin/' . $asset;
        if (!isset($files[$path])) {
            throw new RuntimeException('Missing admin asset: ' . $path);
        }
        $content = $files[$path];
        $pattern = str_ends_with($asset, '.css') ? $fontReference : $moduleReference;
        $updated = preg_replace($pattern, '$1?v=' . $version, $content, -1, $count);
        if ($count === 0) {
            throw new RuntimeException('Expected versioned dependency in ' . $path);
        }
        if ($updated !== $content) {
            $changes[$path] = $updated;
        }
    }

    $viewPath = 'resources/views/admin.blade.php';
    $view = file_get_contents($root . '/' . $viewPath);
    $updated = preg_replace(
        '~(/assets/admin/(?:assets|locales)/[^"\s?]+\.(?:js|css))(?:\?v=[A-Za-z0-9_-]+)?~',
        '$1?v=' . $version,
        $view,
        -1,
        $count
    );
    if ($count < 7) {
        throw new RuntimeException('Missing admin entry assets or module preload');
    }
    if ($updated !== $view) {
        $changes[$viewPath] = $updated;
    }

    return ['version' => $version, 'changes' => $changes];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $check = in_array('--check', $argv, true);
    $root = dirname(__DIR__);
    try {
        $plan = adminAssetVersionPlan($root);
        if ($check && $plan['changes']) {
            fwrite(STDERR, "Admin assets changed. Run: php scripts/version-admin-assets.php\n");
            exit(1);
        }
        foreach ($plan['changes'] as $path => $content) {
            if (file_put_contents($root . '/' . $path, $content) === false) {
                throw new RuntimeException('Cannot update ' . $path);
            }
            echo 'Updated ' . $path . PHP_EOL;
        }
        echo $plan['version'] . ($check ? ' verified' : ' ready') . PHP_EOL;
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
