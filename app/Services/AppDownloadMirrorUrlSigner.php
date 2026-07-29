<?php

namespace App\Services;

use App\Models\AppArtifact;
use Carbon\CarbonInterface;
use RuntimeException;

class AppDownloadMirrorUrlSigner
{
    public function url(string $mirrorPath, ?CarbonInterface $expiresAt = null): string
    {
        $baseUrl = $this->baseUrl();
        $key = (string) config('app_downloads.mirror.signing_key', '');

        if ($key === '') {
            throw new RuntimeException('App download mirror signing is not configured.');
        }

        $path = ltrim($mirrorPath, '/');
        if ($path === '') {
            throw new RuntimeException('App download mirror path is invalid.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('//u', $segment) !== 1) {
                throw new RuntimeException('App download mirror path is invalid.');
            }
        }

        $signingUri = '/files/' . implode('/', $segments);
        $encodedUri = '/files/' . implode('/', array_map('rawurlencode', $segments));
        $expires = $expiresAt?->getTimestamp()
            ?? now()->addSeconds((int) config('app_downloads.mirror.url_ttl_seconds', 600))->getTimestamp();
        $digest = base64_encode(md5($expires . $signingUri . ' ' . $key, true));
        $signature = rtrim(strtr($digest, '+/', '-_'), '=');

        return $baseUrl . $encodedUri . '?' . http_build_query([
            'md5' => $signature,
            'expires' => $expires,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function urlFor(AppArtifact $artifact): string
    {
        if ($artifact->mirror_path === null || $artifact->mirror_path === '') {
            throw new RuntimeException('App download mirror path is not configured.');
        }

        return $this->url($artifact->mirror_path);
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('app_downloads.mirror.base_url', ''), '/');
        $parts = parse_url($baseUrl);

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')) {
            throw new RuntimeException('App download mirror base URL is invalid.');
        }

        return $baseUrl;
    }
}
