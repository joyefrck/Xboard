<?php

return [
    'signed_url_ttl_seconds' => (int) env('APP_DOWNLOAD_SIGNED_URL_TTL_SECONDS', 1800),

    'mirror' => [
        'enabled' => filter_var(env('APP_DOWNLOAD_MIRROR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'sync_enabled' => filter_var(env('APP_DOWNLOAD_MIRROR_SYNC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'disk' => 'app_download_mirror',
        'base_url' => rtrim((string) env('APP_DOWNLOAD_MIRROR_BASE_URL', 'https://download.elephant111.com'), '/'),
        'signing_key' => env('APP_DOWNLOAD_MIRROR_SIGNING_KEY'),
        'url_ttl_seconds' => max(60, (int) env('APP_DOWNLOAD_MIRROR_URL_TTL_SECONDS', 600)),
        'health_cache_seconds' => max(1, (int) env('APP_DOWNLOAD_MIRROR_HEALTH_CACHE_SECONDS', 45)),
        'health_timeout_seconds' => max(1, (int) env('APP_DOWNLOAD_MIRROR_HEALTH_TIMEOUT_SECONDS', 2)),
    ],
];
