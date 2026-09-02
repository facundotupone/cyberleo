<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$subcategory_id = isset($_GET['sub']) ? (int)$_GET['sub'] : 0;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

$categories = get_categories();
$category_name = '';
$subcategory_name = '';

// Si se solicita un producto específico, mostrar solo ese producto
if ($product_id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmt->execute([$product_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
<?php require_once 'components/nav.php'; ?>

<div class="container my-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="index.php" class="text-decoration-none">
                    <i class="bi bi-house-door me-1"></i>Inicio
                </a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                <?php echo htmlspecialchars($category_name); ?>
                <?php if ($subcategory_name): ?>
                    - <?php echo htmlspecialchars($subcategory_name); ?>
                <?php endif; ?>
            </li>
        </ol>
    </nav>

    <!-- Título con información de categoría -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="mb-1">
                <?php echo htmlspecialchars($category_name); ?>
                <?php if ($subcategory_name): ?>
                    <small class="text-muted">- <?php echo htmlspecialchars($subcategory_name); ?></small>
                <?php endif; ?>
            </h1>
            <p class="text-muted mb-0">
                <i class="bi bi-grid-3x3-gap me-1"></i>
                <?php echo count($products); ?> producto<?php echo count($products) !== 1 ? 's' : ''; ?> encontrado<?php echo count($products) !== 1 ? 's' : ''; ?>
            </p>
        </div>
        
        <!-- Filtros de subcategoría si existen -->
        <?php if (!$subcategory_id): 
            $subcategories = get_subcategories($category_id);
            if (!empty($subcategories)): ?>
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-funnel me-1"></i>Filtrar por subcategoría
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="category.php?id=<?= $category_id ?>">Todos los productos</a></li>
                <li><hr class="dropdown-divider"></li>
                <?php foreach ($subcategories as $sub): ?>
                <li><a class="dropdown-item" href="category.php?id=<?= $category_id ?>&sub=<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; endif; ?>
    </div>
    
    <div id="cart-message"></div>
    
    <div class="row g-4">
        <?php if (empty($products)): ?>
        <div class="col-12">
            <div class="alert alert-info">
                No hay productos disponibles en esta categoría.
            </div>
        </div>
        <?php else: ?>
        <?php foreach ($products as $product): 
            // Obtener todas las imágenes del producto
            $stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = ? ORDER BY is_main DESC");
           
            $stmt->execute([$product['id']]);
            $images = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            // Si no hay imágenes en product_images, usar la imagen de products como respaldo
            if (empty($images) && !empty($product['image'])) {
                $images = [$product['image']];
            }
        ?>
        <div class="col-md-4">
            <div class="card product-card h-100">
                <div class="product-media">
                    <?php if (!empty($product['price_sale']) && $product['price_sale'] > 0): ?>
                        <div class="position-absolute top-0 start-0 bg-danger text-white px-2 py-1 rounded-end" style="z-index: 10; font-size: 0.75em; font-weight: bold;">
                            LIQUIDACIÓN
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($images)): ?>
                        <?php if (count($images) > 1): ?>
                            <div id="carousel-<?= $product['id'] ?>" class="carousel slide product-carousel w-100 h-100" data-bs-ride="carousel">
                                <div class="carousel-inner h-100">
                                    <?php foreach($images as $index => $image): ?>
                                        <div class="carousel-item h-100 <?= $index === 0 ? 'active' : '' ?>">
                                            <img src="<?= htmlspecialchars($image) ?>" 
                                                 class="d-block w-100" 
                                                 alt="<?= htmlspecialchars($product['name']) ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="carousel-control-prev" type="button" data-bs-target="#carousel-<?= $product['id'] ?>" data-bs-slide="prev">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                    <span class="visually-hidden">Anterior</span>
                                </button>
                                <button class="carousel-control-next" type="button" data-bs-target="#carousel-<?= $product['id'] ?>" data-bs-slide="next">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                    <span class="visually-hidden">Siguiente</span>
                                </button>
                            </div>
                        <?php else: ?>
                            <img src="<?= htmlspecialchars($images[0]) ?>" 
                                 class="single-product-image" 
                                 alt="<?= htmlspecialchars($product['name']) ?>">
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="product-image-empty" aria-hidden="true">
                            <i class="bi bi-cpu"></i>
                            <span>Sin imagen</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <h5 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h5>
                    <div class="description-container">
                        <?php 
                        $full_description = htmlspecialchars($product['description']);
                        $short_description = mb_substr($full_description, 0, 200);
                        $has_more = strlen($full_description) > 200;
                        ?>
                        <p class="card-text">
                            <span class="short-description" style="white-space: pre-line;"><?php echo $short_description; ?></span>
                            <span class="ellipsis"><?php echo $has_more ? '...' : ''; ?></span>
                            <span class="full-description" style="display: none; white-space: pre-line;"><?php echo $full_description; ?></span>
                            <?php if ($has_more): ?>
                            <button class="btn btn-link p-0 ver-mas" onclick="toggleDescription(this)" data-showing-full="false">Ver más</button>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-auto pt-2 gap-2 flex-wrap">
                        <div class="d-flex align-items-center flex-wrap">
                            <?php if (!empty($product['price_sale']) && $product['price_sale'] > 0): ?>
                                <div class="d-flex flex-column me-2">
                                    <span class="price-old"><?php echo format_price($product['price']); ?></span>
                                    <span class="price-sale"><?php echo format_price($product['price_sale']); ?></span>
                                </div>
                            <?php else: ?>
                                <span class="price me-2"><?php echo format_price($product['price']); ?></span>
                            <?php endif; ?>
                            <small class="text-muted stock-display" data-product-id="<?php echo $product['id']; ?>">
                                (Stock: <?php echo $product['stock']; ?>)
                            </small>
                        </div>
                        <button class="btn btn-primary btn-sm add-to-cart" 
                            data-product-id="<?php echo $product['id']; ?>"
                            data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                            data-product-price="<?php echo (!empty($product['price_sale']) && $product['price_sale'] > 0) ? $product['price_sale'] : $product['price']; ?>"
                            data-product-stock="<?php echo $product['stock']; ?>"
                            data-product-stock-original="<?php echo $product['stock']; ?>"
                            <?php echo ($product['stock'] <= 0) ? 'disabled' : ''; ?>>
                            <i class="bi bi-cart-plus"></i> 
                            <?php echo ($product['stock'] <= 0) ? 'Sin stock' : 'Agregar al carrito'; ?>
                        </button>
                    </div>
                    <!-- Compartir producto -->
                    <div class="mt-3">
                        <div class="d-flex justify-content-center gap-2">
                            <?php
                                $shareUrl = SITE_URL . "/category.php?id={$product['category_id']}&product_id={$product['id']}";
                                $shareText = "¡Mirá este producto! " . htmlspecialchars($product['name']);
                            ?>
                            <a href="https://wa.me/?text=<?= urlencode($shareText . ' ' . $shareUrl) ?>"
                            target="_blank" title="Compartir por WhatsApp" class="btn btn-success btn-whatsapp share-whatsapp btn-sm rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>"
                            target="_blank" title="Compartir en Facebook" class="btn btn-primary btn-sm rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-facebook"></i>
                            </a>
                            <a href="#" onclick="navigator.clipboard.writeText('<?= $shareUrl ?>'); 
                               const toast = document.createElement('div'); 
                               toast.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3'; 
                               toast.style.zIndex = '9999'; 
                               toast.innerHTML = 'Link copiado al portapapeles'; 
                               document.body.appendChild(toast); 
                               setTimeout(() => toast.remove(), 2000); 
                               return false;"
                            title="Copiar link para Instagram" class="btn btn-gradient btn-sm rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: linear-gradient(45deg, #f09433 0%,#e6683c 25%,#dc2743 50%,#cc2366 75%,#bc1888 100%); color: white; border: none;">
                                <i class="bi bi-instagram"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'components/footer.php'; ?>

<a href="cart.php" class="floating-cart">
    <i class="bi bi-cart3" style="font-size: 24px;"></i>
    <span class="cart-count">0</span>
</a>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showCartMessage(msg, type = 'info') {
    const container = document.getElementById('cart-message');
    container.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${msg}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>`;
    setTimeout(() => { container.innerHTML = ''; }, 3000);
}

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

// --- Sincroniza el stock visual al cargar la página ---
function syncStockVisual() {
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    document.querySelectorAll('.add-to-cart').forEach(function(btn) {
        var productId = btn.getAttribute('data-product-id');
        var stockOriginal = parseInt(btn.getAttribute('data-product-stock-original'), 10);
        let item = cart.find(i => i.productId == productId);
        let currentQty = item ? item.quantity : 0;
        let stockVisual = stockOriginal - currentQty;
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

// --- CARRITO: AGREGAR PRODUCTO Y CONTROL DE STOCK ---
document.addEventListener('DOMContentLoaded', function() {
    syncStockVisual();

    document.querySelectorAll('.add-to-cart').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            var productId = btn.getAttribute('data-product-id');
            var productName = btn.getAttribute('data-product-name');
            var productPrice = parseFloat(btn.getAttribute('data-product-price'));
            var stockOriginal = parseInt(btn.getAttribute('data-product-stock-original'), 10);

            let cart = JSON.parse(localStorage.getItem('cart')) || [];
            let index = cart.findIndex(item => item.productId == productId);
            let currentQty = index !== -1 ? cart[index].quantity : 0;

            // Calcular stock visual antes de agregar
            var stockVisual = stockOriginal - currentQty;

            if (stockVisual <= 0) {
                showCartMessage(`No hay suficiente stock. Disponible: ${stockVisual}`, 'danger');
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
            showCartMessage('Producto agregado al carrito', 'success');

            // --- Actualiza el stock visual de todos los botones de ese producto ---
            syncStockVisual();
        });
    });

    updateCartCount();
});

// --- CONTADOR DEL CARRITO ---
function updateCartCount() {
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    const count = cart.reduce((total, item) => total + item.quantity, 0);
    document.querySelectorAll('.cart-count').forEach(el => {
        el.textContent = count;
    });
}
</script>
</body>
</html>