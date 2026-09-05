<?php
declare(strict_types=1);

/**
 * Stable cache-busting for first-party CSS/JS.
 * Uses content hash (not filemtime) so ZIP timestamps cannot keep stale browsers.
 */

const CYBERLEO_ASSET_VERSION_FALLBACK = 'refinamientohotfix20260905';

/**
 * Only first-party storefront/admin assets under these prefixes may be versioned.
 *
 * @return list<string>
 */
function cyberleo_asset_allowed_prefixes(): array
{
    return [
        'assets/css/',
        'assets/js/',
    ];
}

/**
 * Normalize and validate a relative asset path. Returns '' when rejected.
 */
function cyberleo_asset_normalize_path(string $relativePath): string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '') {
        return '';
    }
    // Reject absolute and scheme-like paths before any trimming.
    if ($relativePath[0] === '/' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $relativePath) === 1) {
        return '';
    }
    if (str_contains($relativePath, "\0") || str_contains($relativePath, '..')) {
        return '';
    }
    // Only safe path characters.
    if (preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._/-]*$#', $relativePath) !== 1) {
        return '';
    }
    $relativePath = ltrim($relativePath, '/');
    $allowed = false;
    foreach (cyberleo_asset_allowed_prefixes() as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        return '';
    }
    // Must end with a known static extension.
    if (preg_match('/\.(css|js)$/i', $relativePath) !== 1) {
        return '';
    }
    return $relativePath;
}

/**
 * Short content hash for a public-root-relative asset path.
 * Returns '' for rejected/missing/unreadable paths (never warns, never leaks FS paths).
 */
function cyberleo_asset_version(string $relativePath): string
{
    static $cache = [];

    $relativePath = cyberleo_asset_normalize_path($relativePath);
    if ($relativePath === '') {
        return '';
    }

    if (array_key_exists($relativePath, $cache)) {
        return $cache[$relativePath];
    }

    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $version = '';

    // is_file/is_readable/hash_file can warn on some hosts; suppress and keep empty.
    if (@is_file($full) && !@is_link($full) && @is_readable($full)) {
        $hash = @hash_file('sha256', $full);
        if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
            $candidate = substr($hash, 0, 12);
            if (preg_match('/^[a-zA-Z0-9]+$/', $candidate) === 1) {
                $version = $candidate;
            }
        }
    }

    $cache[$relativePath] = $version;
    return $cache[$relativePath];
}

/**
 * Build a versioned relative URL: path?v=abcdef123456
 * Uses alphanumeric fallback when the hash cannot be computed.
 */
function cyberleo_asset_url(string $relativePath): string
{
    $normalized = cyberleo_asset_normalize_path($relativePath);
    if ($normalized === '') {
        // Safe no-op relative placeholder; callers should not use rejected paths.
        return 'assets/css/style.css?v=' . rawurlencode(CYBERLEO_ASSET_VERSION_FALLBACK);
    }
    $version = cyberleo_asset_version($normalized);
    if ($version === '' || preg_match('/^[a-zA-Z0-9]+$/', $version) !== 1) {
        $version = CYBERLEO_ASSET_VERSION_FALLBACK;
    }
    return $normalized . '?v=' . rawurlencode($version);
}
