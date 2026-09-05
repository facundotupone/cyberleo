<?php
/**
 * Optional cache-busting resolver. Safe to load without DB/config.
 * Compatible with PHP 8.0+.
 *
 * @param string $relativePath
 * @return string
 */
function cyberleo_safe_asset_url($relativePath)
{
    $relativePath = is_string($relativePath) ? trim($relativePath) : '';
    if ($relativePath === '') {
        return 'assets/css/style.css';
    }

    static $loggedUnavailable = false;

    if (!function_exists('cyberleo_asset_url')) {
        $helper = __DIR__ . '/asset_version.php';
        if (!is_file($helper) || !is_readable($helper)) {
            if (!$loggedUnavailable) {
                error_log('cyberleo asset version helper unavailable; using unversioned assets');
                $loggedUnavailable = true;
            }
            return $relativePath;
        }
        require_once $helper;
    }

    if (!function_exists('cyberleo_asset_url')) {
        if (!$loggedUnavailable) {
            error_log('cyberleo asset version function missing; using unversioned assets');
            $loggedUnavailable = true;
        }
        return $relativePath;
    }

    try {
        $url = cyberleo_asset_url($relativePath);
        if (!is_string($url) || $url === '') {
            return $relativePath;
        }
        return $url;
    } catch (Throwable $e) {
        if (!$loggedUnavailable) {
            error_log('cyberleo asset version failed; using unversioned assets');
            $loggedUnavailable = true;
        }
        return $relativePath;
    }
}
