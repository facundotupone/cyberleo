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
    $themeSettings = resolve_theme_settings($storeSettings);
    $homeContent = resolve_home_content_settings($storeSettings);
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- CONTADOR DEL CARRITO ---
            function updateCartCount() {
                const cart = JSON.parse(localStorage.getItem('cart')) || [];
                const count = cart.reduce((total, item) => total + item.quantity, 0);
                document.querySelectorAll('.cart-count').forEach(el => {
                    el.textContent = count;
                });
            }

            // --- Sincroniza el stock visual al cargar la página ---
            function syncStockVisual() {
                const cart = JSON.parse(localStorage.getItem('cart')) || [];
                document.querySelectorAll('.add-to-cart').forEach(function(btn) {
                    var productId = btn.getAttribute('data-product-id');
                    var stockOriginal = parseInt(btn.getAttribute('data-product-stock-original'), 10);

                    // Sumar todas las cantidades del mismo producto.
                    let totalQuantity = 0;
                    cart.forEach(item => {
                        if (item.productId == productId) {
                            totalQuantity += parseInt(item.quantity) || 0;
                        }
                    });

                    let stockVisual = stockOriginal - totalQuantity;
                    btn.setAttribute('data-product-stock', stockVisual);

                    // Actualizar el texto de stock
                    const stockDisplay = btn.closest('.card-body').querySelector('.stock-display');
                    if (stockDisplay) {
                        stockDisplay.textContent = `(Stock: ${stockVisual})`;
                    }

                    // Actualizar el botón
                    if (stockVisual <= 0) {
                        btn.disabled = true;
                        btn.innerHTML = '<i class="bi bi-cart-plus"></i> Sin stock';
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-cart-plus"></i> Agregar al carrito';
                    }
                });
            }

            // Ejecutar al cargar la página
            syncStockVisual();
            updateCartCount();

            // SISTEMA ÚNICO DE CARRITO - REEMPLAZAR el sistema de cart.js con el sistema de category.php
            document.querySelectorAll('.add-to-cart').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    var productId = btn.getAttribute('data-product-id');
                    var productName = btn.getAttribute('data-product-name');
                    var productPrice = parseFloat(btn.getAttribute('data-product-price'));
                    var stockOriginal = parseInt(btn.getAttribute('data-product-stock-original'), 10);

                    let cart = JSON.parse(localStorage.getItem('cart')) || [];
                    let index = cart.findIndex(item => item.productId == productId);
                    let currentQty = index !== -1 ? cart[index].quantity : 0;

                    // Calcular total de ese producto en el carrito
                    let totalQuantity = 0;
                    cart.forEach(item => {
                        if (item.productId == productId) {
                            totalQuantity += parseInt(item.quantity) || 0;
                        }
                    });

                    // Verificar stock disponible
                    if (totalQuantity >= stockOriginal) {
                        alert(`No hay suficiente stock. Disponible: ${stockOriginal - totalQuantity}`);
                        return;
                    }

                    // Agregar al carrito
                    if (index !== -1) {
                        cart[index].quantity += 1;
                    } else {
                        cart.push({
                            productId: productId,
                            productName: productName,
                            productPrice: productPrice,
                            quantity: 1,
                        });
                    }
                    localStorage.setItem('cart', JSON.stringify(cart));
                    updateCartCount();

                    // Mostrar mensaje de éxito
                    const notification = document.createElement('div');
                    notification.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3';
                    notification.style.zIndex = '9999';
                    notification.innerHTML = 'Producto agregado al carrito';
                    document.body.appendChild(notification);
                    setTimeout(() => notification.remove(), 2000);

                    // --- Actualiza el stock visual de todos los botones de ese producto ---
                    syncStockVisual();
                });
            });
        });

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
                                resultsContainer.innerHTML = '<div class="p-2">No se encontraron productos</div>';
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

        function toggleDescription(button) {
            const container = button.closest('.description-container');
            const shortDesc = container.querySelector('.short-description');
            const fullDesc = container.querySelector('.full-description');
            const ellipsis = container.querySelector('.ellipsis');
            const isShowingFull = button.getAttribute('data-showing-full') === 'true';

            if (isShowingFull) {
                shortDesc.style.display = '';
                ellipsis.style.display = '';
                fullDesc.style.display = 'none';
                button.textContent = 'Ver más';
                button.setAttribute('data-showing-full', 'false');
            } else {
                shortDesc.style.display = 'none';
                ellipsis.style.display = 'none';
                fullDesc.style.display = '';
                button.textContent = 'Ver menos';
                button.setAttribute('data-showing-full', 'true');
            }
        }
    </script>
</body>
</html>
