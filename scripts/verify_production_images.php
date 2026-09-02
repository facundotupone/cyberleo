<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

/**
 * @return never
 */
function root_usage(string $message = ''): void {
    if ($message !== '') fwrite(STDERR, $message . "\n");
    fwrite(STDERR, "Uso: php scripts/verify_production_images.php --root /ruta/absoluta/real/public_html\n");
    exit(2);
}

function cli_path_has_symlink(string $path): bool {
    $current = DIRECTORY_SEPARATOR;
    foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
        if ($segment === '') continue;
        $current .= ($current === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR) . $segment;
        if (is_link($current)) return true;
    }
    return false;
}

$arguments = array_slice($argv, 1);
$rootArgument = '';
if (count($arguments) === 2 && $arguments[0] === '--root') {
    $rootArgument = $arguments[1];
} elseif (count($arguments) === 1 && str_starts_with($arguments[0], '--root=')) {
    $rootArgument = substr($arguments[0], strlen('--root='));
} else {
    root_usage('La opción --root es obligatoria y no admite argumentos adicionales.');
}
if ($rootArgument === '' || !str_starts_with($rootArgument, DIRECTORY_SEPARATOR)) {
    root_usage('--root debe ser una ruta absoluta.');
}
$realRoot = realpath($rootArgument);
if ($realRoot === false || !is_dir($realRoot) || $realRoot === DIRECTORY_SEPARATOR
    || $rootArgument !== $realRoot || cli_path_has_symlink($rootArgument)) {
    root_usage('--root debe ser un directorio real canónico, distinto de / y sin enlaces simbólicos.');
}
foreach (['products', 'settings'] as $scope) {
    $directory = $realRoot . '/assets/images/' . $scope;
    if (!is_dir($directory) || is_link($directory) || cli_path_has_symlink($directory)
        || realpath($directory) !== $directory) {
        root_usage("--root debe contener assets/images/{$scope} como directorio real sin enlaces simbólicos.");
    }
}

require_once $realRoot . '/includes/config.php';
require_once $realRoot . '/includes/db.php';
require_once $realRoot . '/includes/images.php';

$counts = ['total'=>0, 'correct'=>0, 'missing'=>0, 'unsafe'=>0, 'main_inconsistencies'=>0];
$check = static function (?string $path, string $scope) use (&$counts, $realRoot): void {
    if ($path === null || $path === '') return;
    $counts['total']++;
    $resolved = resolve_safe_stored_image_path($path, $realRoot, $scope);
    if ($resolved['status'] === 'resolved') $counts['correct']++;
    elseif ($resolved['status'] === 'missing_file') $counts['missing']++;
    else $counts['unsafe']++;
};

try {
    foreach ($pdo->query("SELECT image FROM products WHERE image IS NOT NULL AND image <> ''") as $row) $check($row['image'], 'products');
    foreach ($pdo->query("SELECT image_path FROM product_images WHERE image_path <> ''") as $row) $check($row['image_path'], 'products');
    $settings = $pdo->query("SELECT setting_key, setting_value FROM store_settings WHERE setting_key IN ('hero_background','body_background') AND setting_value <> ''");
    foreach ($settings as $row) $check($row['setting_value'], 'settings');

    $products = $pdo->query("SELECT p.id,p.image,COUNT(pi.id) image_count,SUM(pi.is_main=1) main_count,
        MAX(CASE WHEN pi.is_main=1 THEN pi.image_path END) main_path
        FROM products p LEFT JOIN product_images pi ON pi.product_id=p.id GROUP BY p.id,p.image");
    foreach ($products as $product) {
        $expectedCount = (int)$product['image_count'] > 0 ? 1 : 0;
        if ((int)$product['main_count'] !== $expectedCount
            || (($product['image'] ?: null) !== ($product['main_path'] ?: null))) {
            $counts['main_inconsistencies']++;
        }
    }
} catch (Throwable $e) {
    error_log('Image inventory verification failed: ' . $e->getMessage());
    $counts['unsafe']++;
}

foreach ($counts as $name => $value) echo "{$name}: {$value}\n";
exit(($counts['missing'] + $counts['unsafe'] + $counts['main_inconsistencies']) === 0 ? 0 : 1);
