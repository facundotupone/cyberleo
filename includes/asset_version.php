<?php
declare(strict_types=1);

/**
 * Stable cache-busting for first-party CSS/JS.
 * Uses content hash (not filemtime) so ZIP timestamps cannot keep stale browsers.
 */

const CYBERLEO_ASSET_VERSION_FALLBACK = 'refinamiento-hotfix-20260905';

/**
 * @var array<string,string>
 */
function cyberleo_asset_version_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Short content hash for a public-root-relative asset path.
 */
function cyberleo_asset_version(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return CYBERLEO_ASSET_VERSION_FALLBACK;
    }

    $cache = &cyberleo_asset_version_cache();
    if (isset($cache[$relativePath])) {
        return $cache[$relativePath];
    }

    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($full) && !is_link($full)) {
        $hash = hash_file('sha256', $full);
        if (is_string($hash) && $hash !== '') {
            $cache[$relativePath] = substr($hash, 0, 12);
            return $cache[$relativePath];
        }
    }

    $cache[$relativePath] = CYBERLEO_ASSET_VERSION_FALLBACK;
    return $cache[$relativePath];
}

/**
 * Build a versioned relative URL: path?v=abcdef123456
 */
function cyberleo_asset_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $version = cyberleo_asset_version($relativePath);
    return $relativePath . '?v=' . rawurlencode($version);
}
