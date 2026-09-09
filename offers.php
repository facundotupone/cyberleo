<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/images.php';
require_once 'includes/catalog_display.php';

$categories = get_categories();
$storeSettings = get_store_settings();
$catalogDisplay = resolve_catalog_display_settings($storeSettings);
$colsClass = catalog_column_class($catalogDisplay['catalog_columns'] ?? '4');
$emptyText = (string) ($catalogDisplay['catalog_empty_text'] ?? 'No hay productos disponibles en esta categoría.');

$products = [];
try {
    $stmt = $pdo->query(
        'SELECT p.id, p.name, p.description, p.price, p.price_sale, p.stock, p.image, p.is_active, p.category_id, p.subcategory_id, c.name AS category_name
         FROM products p
         JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1 AND p.price_sale IS NOT NULL AND p.price_sale > 0
         ORDER BY p.name'
    );
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('cyberleo offers: ' . $e->getMessage());
    $products = [];
}

$productIds = array_column($products, 'id');
$images_by_product = [];
if ($productIds) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("SELECT product_id, image_path FROM product_images WHERE product_id IN ($placeholders) ORDER BY is_main DESC");
    $stmt->execute(array_map('intval', $productIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $images_by_product[$row['product_id']][] = $row['image_path'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php require_once 'components/head.php'; ?>
</head>
<body>
<?php require_once 'components/nav.php'; ?>

<main class="container my-4">
    <h1 class="h2 mb-1">Ofertas</h1>
    <p class="text-muted mb-4">Productos con precio promocional.</p>
    <div id="cart-message"></div>
    <section class="row g-4 product-grid <?= htmlspecialchars($colsClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="Ofertas">
        <?php if (empty($products)): ?>
        <div class="col-12">
            <div class="alert alert-info"><?= htmlspecialchars($emptyText) ?></div>
        </div>
        <?php else: ?>
        <?php foreach ($products as $product):
            $images = $images_by_product[$product['id']] ?? [];
            if (empty($images) && !empty($product['image'])) {
                $images = [$product['image']];
            }
            $categoryName = (string) ($product['category_name'] ?? '');
            $cardContext = 'catalog';
            require 'components/product_card.php';
        endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<?php require_once 'components/footer.php'; ?>
<a href="cart.php" class="floating-cart">
    <i class="bi bi-cart" style="font-size: 24px;" aria-hidden="true"></i>
    <span class="cart-count">0</span>
</a>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= htmlspecialchars(function_exists('cyberleo_safe_asset_url') ? cyberleo_safe_asset_url('assets/js/catalog-cards.js') : 'assets/js/catalog-cards.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
