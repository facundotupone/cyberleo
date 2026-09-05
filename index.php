<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

$categories = get_categories();
$featured_products = get_featured_products();

// Optimización: obtener todo lo necesario en bloque para evitar consultas dentro de bucles
$featured_ids = array_column($featured_products, 'id');
$featured_ids_str = implode(',', array_map('intval', $featured_ids));

// 1. Todas las imágenes de productos destacados
$images_by_product = [];
if (!empty($featured_ids_str)) {
    $stmt = $pdo->query("SELECT product_id, image_path FROM product_images WHERE product_id IN ($featured_ids_str) ORDER BY is_main DESC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $images_by_product[$row['product_id']][] = $row['image_path'];
    }
}

// 2. Todas las subcategorías de todas las categorías
$all_subcategories = [];
$stmt = $pdo->query("SELECT * FROM subcategories ORDER BY category_id, name");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $all_subcategories[$row['category_id']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<?php require_once 'components/head.php'; ?>

<style>
    .product-carousel {
        height: 100%;
        overflow: hidden;
        position: relative;
    }
    .product-carousel .carousel-inner {
        height: 100%;
    }
    .product-carousel .carousel-item {
        height: 100%;
        text-align: center;
    }
    .product-carousel .carousel-item img {
        height: 100%;
        width: auto;
        max-width: 100%;
        object-fit: contain;
    }
    .carousel-control-prev, .carousel-control-next {
        width: 30px;
        background: rgba(0,0,0,0.2);
        border-radius: 50%;
        height: 30px;
        top: 50%;
        transform: translateY(-50%);
    }
    .single-product-image {
        height: 100%;
        object-fit: contain;
        width: 100%;
    }
</style>
</head>
<body>
    <?php
    require_once 'includes/theme.php';
    require_once 'includes/home_content.php';
    require_once 'includes/catalog_display.php';
    $themeSettings = resolve_theme_settings($storeSettings);
    $homeContent = resolve_home_content_settings($storeSettings);
    $catalogDisplay = resolve_catalog_display_settings($storeSettings);
    $heroClasses = ['hero-section'];
    $heroClasses[] = 'hero-height-' . $themeSettings['hero_height'];
    $heroClasses[] = 'hero-align-' . $themeSettings['hero_alignment'];
    if (!empty($storeSettings['hero_background']) && is_safe_settings_image_path($storeSettings['hero_background'])) {
        $heroClasses[] = 'hero-has-image';
        $heroClasses[] = 'hero-overlay-' . $themeSettings['hero_overlay'];
    }
    $heroButtonUrl = is_safe_local_theme_url($themeSettings['hero_button_url'])
        ? $themeSettings['hero_button_url']
        : '#productos-destacados';
    ?>
    <?php require_once 'components/nav.php'; ?>
    <main class="container mt-3">
        <section class="<?= htmlspecialchars(implode(' ', $heroClasses)) ?>" aria-label="<?= htmlspecialchars($storeSettings['store_name']) ?>">
            <div class="hero-content text-white<?= $themeSettings['hero_alignment'] === 'left' ? '' : ' text-center' ?>">
                <h1 class="text-white"><?= htmlspecialchars($storeSettings['hero_title']) ?></h1>
                <p class="hero-subtitle mb-0"><?= htmlspecialchars($storeSettings['hero_subtitle']) ?></p>
                <a href="<?= htmlspecialchars($heroButtonUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary hero-cta mt-3">
                    <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> <?= htmlspecialchars($themeSettings['hero_button_text']) ?>
                </a>
            </div>
        </section>
        <?php if ($themeSettings['show_search'] === '1'): ?>
        <section class="row justify-content-center search-section" aria-label="Buscador de productos">
            <div class="col-md-7 col-lg-6 position-relative">
                <div class="input-group">
                    <input type="search" id="searchProducts" class="form-control" placeholder="Buscar productos..." aria-label="Buscar productos">
                    <button type="button" class="btn btn-search" aria-label="Buscar">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </button>
                </div>
                <div id="searchResults" class="position-absolute bg-white shadow-sm rounded p-2" style="display: none; z-index: 1000; width: 100%;"></div>
            </div>
        </section>
        <?php endif; ?>

        <?php
        $homeSections = home_content_ordered_sections($homeContent);
        foreach ($homeSections as $sectionToken) {
            switch ($sectionToken) {
                case 'featured':
                    require 'components/home_featured.php';
                    break;
                case 'promo':
                    require 'components/promo_banner.php';
                    break;
                case 'categories':
                    require 'components/home_categories.php';
                    break;
                case 'benefits':
                    require 'components/benefits.php';
                    break;
            }
        }
        ?>

    </main>

    <?php require_once 'components/footer.php'; ?>
    <a href="cart.php" class="floating-cart">
        <i class="bi bi-cart" style="font-size: 24px;"></i>
        <span class="cart-count">0</span>
    </a>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= htmlspecialchars(cyberleo_asset_url('assets/js/catalog-cards.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script>
        // Buscador de productos (solo si show_search renderizó ambos nodos)
        (function initProductSearch() {
            const searchInput = document.getElementById('searchProducts');
            const resultsContainer = document.getElementById('searchResults');
            if (!searchInput || !resultsContainer) {
                return;
            }

            let searchTimeout;
            searchInput.addEventListener('input', function(e) {
                const searchTerm = e.target.value.trim();
                clearTimeout(searchTimeout);
                if (searchTerm.length < 2) {
                    resultsContainer.style.display = 'none';
                    return;
                }
                searchTimeout = setTimeout(() => {
                    fetch(`search_products.php?q=${encodeURIComponent(searchTerm)}`)
                        .then(response => response.json())
                        .then(products => {
                            if (products.length > 0) {
                                resultsContainer.replaceChildren();
                                products.forEach(product => {
                                    const link = document.createElement('a');
                                    link.href = `category.php?id=${encodeURIComponent(product.category_id)}&sub=${encodeURIComponent(product.subcategory_id || '')}`;
                                    link.className = 'search-result p-2 border-bottom text-decoration-none text-dark d-block';
                                    const row = document.createElement('div'); row.className = 'd-flex align-items-center';
                                    if (typeof product.image === 'string' && /^assets\/images\/[a-zA-Z0-9_./-]+$/.test(product.image)) {
                                        const image = document.createElement('img');
                                        image.src = product.image; image.alt = product.name || ''; image.style.cssText = 'width:50px;height:50px;object-fit:cover;margin-right:10px;';
                                        row.appendChild(image);
                                    }
                                    const content = document.createElement('div');
                                    const name = document.createElement('h6'); name.className = 'mb-0'; name.textContent = product.name || '';
                                    const category = document.createElement('small'); category.className = 'text-muted'; category.textContent = product.category_name || '';
                                    const prices = document.createElement('div'); prices.className = 'd-flex justify-content-between';
                                    const price = document.createElement('span'); price.className = 'text-primary'; price.textContent = `$${Number(product.price).toFixed(2)}`;
                                    const stock = document.createElement('small'); stock.className = Number(product.stock) > 0 ? 'text-success' : 'text-danger'; stock.textContent = Number(product.stock) > 0 ? 'Disponible' : 'Sin stock';
                                    prices.append(price, stock); content.append(name, category, prices); row.appendChild(content); link.appendChild(row); resultsContainer.appendChild(link);
                                });
                                resultsContainer.style.display = 'block';
                            } else {
                                resultsContainer.replaceChildren();
                                const empty = document.createElement('div');
                                empty.className = 'p-2';
                                empty.textContent = 'No se encontraron productos';
                                resultsContainer.appendChild(empty);
                                resultsContainer.style.display = 'block';
                            }
                        });
                }, 300);
            });

            document.addEventListener('click', function(e) {
                if (!resultsContainer.contains(e.target) && e.target !== searchInput) {
                    resultsContainer.style.display = 'none';
                }
            });
        })();
    </script>
</body>
</html>
