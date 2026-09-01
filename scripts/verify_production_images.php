<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/images.php';

$counts = ['total'=>0, 'correct'=>0, 'missing'=>0, 'unsafe'=>0, 'main_inconsistencies'=>0];
$imageRoot = (getenv('APP_ENV') === 'test' && getenv('IMAGE_STORAGE_ROOT'))
    ? (string)getenv('IMAGE_STORAGE_ROOT') : dirname(__DIR__);
$check = static function (?string $path, string $scope) use (&$counts, $imageRoot): void {
    if ($path === null || $path === '') return;
    $counts['total']++;
    $resolved = resolve_safe_stored_image_path($path, $imageRoot, $scope);
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
