<?php
declare(strict_types=1);

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/images.php';
require_once 'includes/catalog_display.php';
require_once 'includes/catalog_taxonomy.php';

$categories = get_categories();
$storeSettings = get_store_settings();
$catalogDisplay = resolve_catalog_display_settings($storeSettings);
$products = get_offer_products();

$productIds = array_column($products, 'id');
$images_by_product = [];
if ($productIds) {
    $ids = implode(',', array_map('intval', $productIds));
    $stmt = $pdo->query("SELECT product_id, image_path FROM product_images WHERE product_id IN ($ids) ORDER BY is_main DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $images_by_product[$row['product_id']][] = $row['image_path'];
    }
}

$colsClass = catalog_column_class($catalogDisplay['catalog_columns'] ?? '3');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php require_once 'components/head.php'; ?>
</head>
<body>
<?php require_once 'components/nav.php'; ?>

<main class="container my-4">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="index.php" class="text-decoration-none">
                    <i class="bi bi-house-door me-1" aria-hidden="true"></i>Inicio
                </a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">Ofertas</li>
        </ol>
    </nav>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="mb-1 h2">Ofertas</h1>
            <p class="text-muted mb-0">
                Productos con precio promocional vigente
                (<?= count($products) ?> encontrado<?= count($products) !== 1 ? 's' : '' ?>).
            </p>
        </div>
    </div>

    <div id="cart-message"></div>

    <section class="row g-4 product-grid <?= htmlspecialchars($colsClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="Ofertas">
        <?php if ($products === []): ?>
        <div class="col-12">
            <div class="alert alert-info">
                No hay ofertas activas en este momento.
            </div>
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
<script src="<?= htmlspecialchars('assets/js/catalog-cards.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
