<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/images.php';
require_once 'includes/catalog_display.php';

$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$subcategory_id = isset($_GET['sub']) ? (int)$_GET['sub'] : 0;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

$categories = get_categories();
$category_name = '';
$subcategory_name = '';
$storeSettings = get_store_settings();
$catalogDisplay = resolve_catalog_display_settings($storeSettings);

if ($product_id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$product_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($products) {
        $category_id = (int) $products[0]['category_id'];
        foreach ($categories as $cat) {
            if ((int) $cat['id'] === $category_id) {
                $category_name = $cat['name'];
                break;
            }
        }
    }
} else {
    foreach ($categories as $cat) {
        if ($cat['id'] == $category_id) {
            $category_name = $cat['name'];
            if ($subcategory_id) {
                $subcategories = get_subcategories($category_id);
                foreach ($subcategories as $sub) {
                    if ($sub['id'] == $subcategory_id) {
                        $subcategory_name = $sub['name'];
                        break;
                    }
                }
            }
            break;
        }
    }

    if (!$category_name) {
        header('Location: index.php');
        exit;
    }

    $products = $subcategory_id ?
        get_products_by_subcategory($category_id, $subcategory_id) :
        get_products_by_category($category_id);
}

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
$showBreadcrumbs = ($catalogDisplay['catalog_show_breadcrumbs'] ?? '1') === '1';
$showCount = ($catalogDisplay['catalog_show_product_count'] ?? '1') === '1';
$showFilter = ($catalogDisplay['catalog_show_subcategory_filter'] ?? '1') === '1';
$emptyText = (string) ($catalogDisplay['catalog_empty_text'] ?? 'No hay productos disponibles en esta categoría.');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php require_once 'components/head.php'; ?>
</head>
<body>
<?php require_once 'components/nav.php'; ?>

<main class="container my-4">
    <?php if ($showBreadcrumbs): ?>
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="index.php" class="text-decoration-none">
                    <i class="bi bi-house-door me-1" aria-hidden="true"></i>Inicio
                </a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                <?= htmlspecialchars($category_name) ?>
                <?php if ($subcategory_name): ?>
                    - <?= htmlspecialchars($subcategory_name) ?>
                <?php endif; ?>
            </li>
        </ol>
    </nav>
    <?php endif; ?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="mb-1 h2">
                <?= htmlspecialchars($category_name) ?>
                <?php if ($subcategory_name): ?>
                    <small class="text-muted">- <?= htmlspecialchars($subcategory_name) ?></small>
                <?php endif; ?>
            </h1>
            <?php if ($showCount): ?>
            <p class="text-muted mb-0">
                <i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>
                <?= count($products) ?> producto<?= count($products) !== 1 ? 's' : '' ?> encontrado<?= count($products) !== 1 ? 's' : '' ?>
            </p>
            <?php endif; ?>
        </div>

        <?php if ($showFilter && !$subcategory_id && !$product_id):
            $subcategories = get_subcategories($category_id);
            if (!empty($subcategories)): ?>
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar por subcategoría
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="category.php?id=<?= $category_id ?>">Todos los productos</a></li>
                <li><hr class="dropdown-divider"></li>
                <?php foreach ($subcategories as $sub): ?>
                <li><a class="dropdown-item" href="category.php?id=<?= $category_id ?>&sub=<?= (int) $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; endif; ?>
    </div>

    <div id="cart-message"></div>

    <section class="row g-4 product-grid <?= htmlspecialchars($colsClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="Productos">
        <?php if (empty($products)): ?>
        <div class="col-12">
            <div class="alert alert-info">
                <?= htmlspecialchars($emptyText) ?>
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($products as $product):
            $images = $images_by_product[$product['id']] ?? [];
            if (empty($images) && !empty($product['image'])) {
                $images = [$product['image']];
            }
            $categoryName = $category_name;
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
<script src="<?= htmlspecialchars(function_exists('cyberleo_asset_url') ? cyberleo_asset_url('assets/js/catalog-cards.js') : 'assets/js/catalog-cards.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
