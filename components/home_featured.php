<?php
/**
 * Featured products section fragment for the homepage.
 * Expects: $featured_products, $images_by_product, $themeSettings, $catalogDisplay (optional)
 */
require_once __DIR__ . '/../includes/catalog_display.php';
if (!isset($catalogDisplay)) {
    $catalogDisplay = resolve_catalog_display_settings($storeSettings ?? get_store_settings());
}
if (($themeSettings['show_featured_products'] ?? '1') !== '1') {
    return;
}
$colsClass = catalog_column_class($catalogDisplay['featured_columns'] ?? '3');
$title = (string) ($catalogDisplay['featured_section_title'] ?? 'Productos Destacados');
$emptyText = (string) ($catalogDisplay['featured_empty_text'] ?? 'No hay productos destacados disponibles.');
?>
        <h2 id="productos-destacados" class="text-center mb-3 mt-2 h4 fw-bold"><?= htmlspecialchars($title) ?></h2>
        <section class="row g-4 product-grid <?= htmlspecialchars($colsClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($title) ?>">
        <?php if (empty($featured_products)): ?>
        <div class="col-12">
            <div class="alert alert-info">
                <?= htmlspecialchars($emptyText) ?>
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($featured_products as $product):
            $images = isset($images_by_product[$product['id']]) ? $images_by_product[$product['id']] : [];
            if (empty($images) && !empty($product['image'])) {
                $images = [$product['image']];
            }
            $categoryName = (string) ($product['category_name'] ?? '');
            $cardContext = 'featured';
            require __DIR__ . '/product_card.php';
        ?>
        <?php endforeach; ?>
        <?php endif; ?>
        </section>
