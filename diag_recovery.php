<?php
/**
 * Temporary recovery diagnostic. Delete after the site is healthy.
 * Never prints secrets. Always ends with "done" when possible.
 *
 * Optional: /diag_recovery.php?opcache=reset
 */
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

register_shutdown_function(static function () {
    // If a fatal killed the probe, still hint the operator.
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    $msg = preg_replace('#(?:[A-Za-z]:)?[\\\\/][^\s:]+#', '[path]', (string) $err['message']);
    $msg = preg_replace('/\s+/', ' ', $msg);
    echo "\nfatal_during_diag=1\n";
    echo 'fatal_detail=' . substr(trim((string) $msg), 0, 200) . "\n";
    echo "done\n";
});

$checks = array(
    'admin_login.php',
    'emergency_admin_login.php',
    'index.php',
    'includes/asset_version.php',
    'includes/asset_safe_url.php',
    'includes/functions.php',
    'includes/security.php',
    'includes/config.php',
    'includes/config.local.php',
    'includes/db.php',
    'includes/theme.php',
    'includes/public_nav.php',
    'includes/home_content.php',
    'includes/catalog_display.php',
    'includes/checkout_display.php',
    'components/head.php',
    'components/nav.php',
    'components/footer.php',
    'components/admin_nav.php',
    'components/home_featured.php',
    'components/product_card.php',
    'assets/css/style.css',
    'diag_recovery.php',
);

echo "cyberleo recovery diag\n";
echo 'package_build=index-resilient-20260909' . "\n";
echo 'php=' . PHP_VERSION . "\n";
echo 'opcache=' . (function_exists('opcache_get_status') ? 'yes' : 'no') . "\n";
if (function_exists('opcache_get_status')) {
    $st = @opcache_get_status(false);
    echo 'opcache_enabled=' . (!empty($st['opcache_enabled']) ? '1' : '0') . "\n";
}

if (isset($_GET['opcache']) && $_GET['opcache'] === 'reset') {
    $didReset = '0';
    if (function_exists('opcache_reset')) {
        $didReset = @opcache_reset() ? '1' : '0';
    }
    echo 'opcache_reset=' . $didReset . "\n";
}

foreach ($checks as $rel) {
    $full = __DIR__ . '/' . $rel;
    $exists = is_file($full);
    $size = $exists ? (int) filesize($full) : 0;
    $readable = $exists && is_readable($full);
    echo $rel . ' exists=' . ($exists ? '1' : '0') . ' readable=' . ($readable ? '1' : '0') . ' size=' . $size . "\n";
}

// Expected sizes for the current recovery package (detect partial uploads).
$expected = array(
    'index.php' => (int) @filesize(__DIR__ . '/index.php'),
    'includes/functions.php' => (int) @filesize(__DIR__ . '/includes/functions.php'),
    'includes/asset_safe_url.php' => (int) @filesize(__DIR__ . '/includes/asset_safe_url.php'),
    'includes/theme.php' => (int) @filesize(__DIR__ . '/includes/theme.php'),
    'diag_recovery.php' => (int) @filesize(__DIR__ . '/diag_recovery.php'),
);
echo 'fingerprint_index=' . $expected['index.php'] . "\n";
echo 'fingerprint_functions=' . $expected['includes/functions.php'] . "\n";
echo 'fingerprint_diag=' . $expected['diag_recovery.php'] . "\n";

$configOk = '0';
$dbConsts = '0';
try {
    if (is_file(__DIR__ . '/includes/config.php') && is_readable(__DIR__ . '/includes/config.php')) {
        require_once __DIR__ . '/includes/config.php';
        $configOk = '1';
        if (defined('DB_HOST') && defined('DB_USER') && defined('DB_NAME')
            && DB_HOST !== '' && DB_USER !== '' && DB_NAME !== '') {
            $dbConsts = '1';
        }
    }
} catch (Throwable $e) {
    $configOk = '0';
}
echo 'config_loaded=' . $configOk . "\n";
echo 'db_constants_present=' . $dbConsts . "\n";
echo 'config_local_present=' . (is_file(__DIR__ . '/includes/config.local.php') ? '1' : '0') . "\n";
echo 'app_secret_present=' . ((defined('APP_SECRET') && APP_SECRET !== '') ? '1' : '0') . "\n";

$hasUrl = false;
$hasSafe = false;
try {
    $helper = __DIR__ . '/includes/asset_version.php';
    if (is_file($helper) && is_readable($helper)) {
        require_once $helper;
        $hasUrl = function_exists('cyberleo_asset_url');
    }
    $safe = __DIR__ . '/includes/asset_safe_url.php';
    if (is_file($safe) && is_readable($safe)) {
        require_once $safe;
        $hasSafe = function_exists('cyberleo_safe_asset_url');
    }
} catch (Throwable $e) {
    // keep flags false
}
echo 'cyberleo_asset_url=' . ($hasUrl ? '1' : '0') . "\n";
echo 'cyberleo_safe_asset_url=' . ($hasSafe ? '1' : '0') . "\n";
if ($hasSafe) {
    try {
        $sample = cyberleo_safe_asset_url('assets/css/style.css');
        echo 'sample_style=' . (is_string($sample) ? $sample : 'invalid') . "\n";
    } catch (Throwable $e) {
        echo "sample_style=error\n";
    }
}

$loginProbe = '0';
try {
    if (!function_exists('start_secure_session') && is_file(__DIR__ . '/includes/security.php')) {
        require_once __DIR__ . '/includes/security.php';
    }
    if (function_exists('start_secure_session')) {
        start_secure_session();
        $loginProbe = '1';
    }
} catch (Throwable $e) {
    $loginProbe = '0';
}
echo 'login_session_probe=' . $loginProbe . "\n";

$sanitize = static function ($raw) {
    $raw = preg_replace('#(?:[A-Za-z]:)?[\\\\/][^\s:]+#', '[path]', (string) $raw);
    $raw = preg_replace('/(?i)\b(password|passwd|pwd|secret|token|authorization)=([^\s&]+)/', '$1=[redacted]', (string) $raw);
    $raw = preg_replace('/\s+/', ' ', (string) $raw);
    return substr(trim((string) $raw), 0, 200);
};

$dbConnect = '0';
$indexBootstrap = '0';
$indexError = '';
// Do NOT require includes/db.php here: it exit()s on failure and aborts the diag.
try {
    if ($dbConsts !== '1') {
        throw new RuntimeException('db constants missing');
    }
    $pdoProbe = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        defined('DB_PASS') ? DB_PASS : '',
        array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $dbConnect = '1';

    try {
        $pdoProbe->query('SELECT 1 FROM categories LIMIT 1');
        echo "table_categories=1\n";
    } catch (Throwable $e) {
        echo "table_categories=0\n";
    }
    try {
        $pdoProbe->query('SELECT 1 FROM products LIMIT 1');
        echo "table_products=1\n";
    } catch (Throwable $e) {
        echo "table_products=0\n";
    }
    try {
        $pdoProbe->query('SELECT 1 FROM subcategories LIMIT 1');
        echo "table_subcategories=1\n";
    } catch (Throwable $e) {
        echo "table_subcategories=0\n";
    }
    try {
        $pdoProbe->query('SELECT 1 FROM product_images LIMIT 1');
        echo "table_product_images=1\n";
    } catch (Throwable $e) {
        echo "table_product_images=0\n";
    }
    try {
        $pdoProbe->query('SELECT destacados FROM products LIMIT 1');
        echo "column_products_destacados=1\n";
    } catch (Throwable $e) {
        echo "column_products_destacados=0\n";
        $indexError = $sanitize('missing products.destacados: ' . $e->getMessage());
    }
    try {
        $pdoProbe->query('SELECT 1 FROM store_settings LIMIT 1');
        echo "table_store_settings=1\n";
    } catch (Throwable $e) {
        echo "table_store_settings=0\n";
    }

    // Featured query used by index — isolate without loading db.php exit paths.
    try {
        $pdoProbe->query('SELECT p.id, c.name AS category_name FROM products p JOIN categories c ON p.category_id = c.id WHERE p.destacados > 0 AND p.is_active = 1 ORDER BY p.destacados ASC LIMIT 1');
        echo "query_featured=1\n";
    } catch (Throwable $e) {
        echo "query_featured=0\n";
        if ($indexError === '') {
            $indexError = $sanitize('featured query: ' . $e->getMessage());
        }
    }

    if ($indexError === '') {
        $indexBootstrap = '1';
    }
} catch (Throwable $e) {
    if ($indexError === '') {
        $indexError = $sanitize(get_class($e) . ': ' . $e->getMessage());
    }
}

echo 'db_connect=' . $dbConnect . "\n";
echo 'index_bootstrap=' . $indexBootstrap . "\n";
if ($indexError !== '') {
    echo 'index_error=' . $indexError . "\n";
}
echo "done\n";
