<?php
/**
 * Temporary recovery diagnostic. Delete this file after the site is healthy.
 * Does not print secrets, absolute paths, DB credentials, or stack traces.
 */
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

$checks = array(
    'admin_login.php',
    'index.php',
    'includes/asset_version.php',
    'includes/asset_safe_url.php',
    'includes/functions.php',
    'includes/theme.php',
    'includes/public_nav.php',
    'includes/home_content.php',
    'components/head.php',
    'components/nav.php',
    'components/footer.php',
    'components/admin_nav.php',
    'assets/css/style.css',
);

echo "cyberleo recovery diag\n";
echo 'php=' . PHP_VERSION . "\n";
echo 'opcache=' . (function_exists('opcache_get_status') ? 'yes' : 'no') . "\n";
if (function_exists('opcache_get_status')) {
    $st = opcache_get_status(false);
    echo 'opcache_enabled=' . (!empty($st['opcache_enabled']) ? '1' : '0') . "\n";
}

foreach ($checks as $rel) {
    $full = __DIR__ . '/' . $rel;
    $exists = is_file($full);
    $size = $exists ? (int) filesize($full) : 0;
    $readable = $exists && is_readable($full);
    echo $rel . ' exists=' . ($exists ? '1' : '0') . ' readable=' . ($readable ? '1' : '0') . ' size=' . $size . "\n";
}

$helper = __DIR__ . '/includes/asset_version.php';
$hasUrl = false;
$hasSafe = false;
if (is_file($helper) && is_readable($helper)) {
    require_once $helper;
    $hasUrl = function_exists('cyberleo_asset_url');
}
$safe = __DIR__ . '/includes/asset_safe_url.php';
if (is_file($safe) && is_readable($safe)) {
    require_once $safe;
    $hasSafe = function_exists('cyberleo_safe_asset_url');
}
echo 'cyberleo_asset_url=' . ($hasUrl ? '1' : '0') . "\n";
echo 'cyberleo_safe_asset_url=' . ($hasSafe ? '1' : '0') . "\n";
if ($hasSafe) {
    $sample = cyberleo_safe_asset_url('assets/css/style.css');
    echo 'sample_style=' . (is_string($sample) ? $sample : 'invalid') . "\n";
}
echo "done\n";
