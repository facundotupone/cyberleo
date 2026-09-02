<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/images.php';

// Obtener las categorías para el menú
$categories = get_categories();
$storeSettings = get_store_settings();
$paymentMethods = array_filter(array_map('trim', explode(',', $storeSettings['payment_methods'])));

// Obtener los datos de los productos para el carrito (incluyendo stock e imágenes)
$stmt = $pdo->prepare("SELECT id, name, price, price_sale, stock, image FROM products");
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($products as &$product) {
    if (!is_safe_product_image_path($product['image'])) $product['image'] = null;
}
unset($product);

// Obtener imágenes adicionales de product_images
$productImages = [];
$stmtImages = $pdo->prepare("SELECT product_id, image_path FROM product_images ORDER BY is_main DESC");
$stmtImages->execute();
foreach ($stmtImages->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!is_safe_product_image_path($row['image_path'])) continue;
    if (!isset($productImages[$row['product_id']])) {
        $productImages[$row['product_id']] = [];
    }
    $productImages[$row['product_id']][] = $row['image_path'];
}

?>
<!DOCTYPE html>
<html lang="es">
<meta charset="UTF-8">
<head>
<?php require_once 'components/head.php'; ?>
<style>
.product-image-placeholder {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 2px dashed #dee2e6;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    color: #6c757d;
    font-size: 24px;
}

.cart-product-image {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}

/* Estilos para móvil - centrar imagen y nombre del producto */
@media (max-width: 767px) {
    .cart-item .row > .col-lg-5 {
        text-align: center;
        margin-bottom: 20px;
    }

    .cart-item .d-flex.align-items-center {
        flex-direction: column;
        align-items: center !important;
        text-align: center;
    }

    .cart-item .me-3.flex-shrink-0 {
        margin-right: 0 !important;
        margin-bottom: 15px;
    }

    .cart-item .flex-grow-1 {
        text-align: center;
        width: 100%;
    }

    .cart-item h6 {
        margin-bottom: 10px;
        text-align: center;
    }

    .cart-item .d-flex.align-items-center:not(.justify-content-center) {
        justify-content: center;
    }

}
</style>
</head>
<body>
<?php require_once 'components/nav.php'; ?>

<div class="container my-3">
    <div class="row">
        <div class="col-12">
            <div class="d-flex align-items-center mb-3">
                <i class="bi bi-cart3 fs-3 text-primary me-2" aria-hidden="true"></i>
                <h1 class="cart-page-title">Carrito de Compras</h1>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-0">
                    <h5 class="mb-0 text-navy">Productos en tu carrito</h5>
                </div>
                <div class="card-body p-0">
                    <div id="cart-items">
                        <!-- Los items del carrito se cargarán dinámicamente aquí -->
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4 cart-summary-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Resumen del pedido</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">Total:</h4>
                        <h4 id="cart-total" class="mb-0 text-primary">$0.00</h4>
                    </div>

                    <div class="bg-light p-3 rounded mb-3 border">
                        <h6 class="mb-2"><i class="bi bi-info-circle me-2"></i>Información de envío</h6>
                        <p class="small mb-0">Envíanos tu consulta y te responderemos a la brevedad para coordinar envío o retiro.</p>
                    </div>

                    <div class="mb-3">
                        <h6><i class="bi bi-credit-card me-2"></i>Métodos de pago:</h6>
                        <div class="d-flex flex-wrap gap-2"><?php foreach ($paymentMethods as $method): ?><span class="badge bg-info"><?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></div>
                        <small class="text-muted">Abonas al recibir tu pedido</small>
                    </div>

                    <div class="d-grid gap-2">
                        <a href="#" id="whatsapp-order" class="btn btn-success btn-whatsapp btn-lg" target="_blank">
                            <i class="bi bi-whatsapp me-2"></i>Enviar Pedido por WhatsApp
                        </a>
                        <a href="index.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left me-2"></i>Seguir Comprando
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'components/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
const productData = <?php echo json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?>;
const productImages = <?php echo json_encode($productImages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?>;

// Función para obtener los datos de un producto por su ID
function getProductById(productId) {
    return productData.find(product => String(product.id) === String(productId));
}

// Convierte cualquier valor persistido a la única forma aceptada por el carrito:
// IDs existentes, cantidades enteras positivas y una sola fila por producto.
function normalizedCart() {
    let stored;
    try {
        stored = JSON.parse(localStorage.getItem('cart'));
    } catch {
        stored = [];
    }
    const quantities = new Map();
    if (Array.isArray(stored)) {
        stored.forEach(item => {
            if (!item || typeof item !== 'object') return;
            const id = String(item.productId ?? '');
            const quantity = Number(item.quantity);
            if (!/^[1-9]\d*$/.test(id)
                || !Number.isSafeInteger(quantity)
                || quantity < 1
                || !getProductById(id)) return;
            const combined = (quantities.get(id) || 0) + quantity;
            if (Number.isSafeInteger(combined)) quantities.set(id, combined);
        });
    }
    const cart = Array.from(quantities, ([productId, quantity]) => ({productId, quantity}));
    if (cart.length > 0) {
        localStorage.setItem('cart', JSON.stringify(cart));
    } else {
        localStorage.removeItem('cart');
    }
    return cart;
}

// Función para obtener la imagen principal de un producto
function getProductImage(productId) {
    // Primero buscar en product_images
    if (productImages[productId] && productImages[productId].length > 0) {
        return productImages[productId][0];
    }

    // Si no hay en product_images, usar la imagen del producto
    const product = getProductById(productId);
    if (product && product.image) {
        return product.image;
    }

    // Retornar null si no hay imagen disponible
    return null;
}

function isSafeLocalImagePath(imagePath) {
    return typeof imagePath === 'string'
        && /^assets\/images\/products\/(?:[a-f0-9]{13}|[a-f0-9]{32})\.(?:jpg|jpeg|png|webp)$/i.test(imagePath);
}

function createImagePlaceholder() {
    const placeholder = document.createElement('div');
    placeholder.className = 'product-image-placeholder';
    const icon = document.createElement('i');
    icon.className = 'bi bi-image';
    placeholder.append(icon);
    return placeholder;
}

function createProductImage(productId, productName) {
    const imagePath = getProductImage(productId);
    if (!isSafeLocalImagePath(imagePath)) {
        return createImagePlaceholder();
    }

    const image = document.createElement('img');
    image.src = imagePath;
    image.alt = String(productName);
    image.className = 'cart-product-image';
    image.addEventListener('error', () => image.replaceWith(createImagePlaceholder()), { once: true });
    return image;
}

// Función para obtener el precio efectivo (price_sale si existe y > 0, sino price)
function getEffectivePrice(product) {
    if (product.price_sale && parseFloat(product.price_sale) > 0) {
        return parseFloat(product.price_sale);
    }
    return parseFloat(product.price);
}

// Cargar los items del carrito
function loadCartItems() {
    const cartItems = normalizedCart();

    const cartContainer = document.getElementById('cart-items');
    const cartTotal = document.getElementById('cart-total');
    let total = 0;
    let hasOutOfStockItems = false;

    if (cartItems.length === 0) {
        const emptyCart = document.createElement('div');
        emptyCart.className = 'cart-empty-state';
        const icon = document.createElement('i');
        icon.className = 'bi bi-cart-x fs-1 mb-3 d-block';
        const title = document.createElement('h5');
        title.className = 'text-muted';
        title.textContent = 'Tu carrito está vacío';
        const message = document.createElement('p');
        message.className = 'text-muted';
        message.textContent = 'Agrega algunos productos para comenzar';
        const link = document.createElement('a');
        link.href = 'index.php';
        link.className = 'btn btn-primary';
        const linkIcon = document.createElement('i');
        linkIcon.className = 'bi bi-shop me-2';
        link.append(linkIcon, document.createTextNode('Explorar productos'));
        emptyCart.append(icon, title, message, link);
        cartContainer.replaceChildren(emptyCart);
        cartTotal.textContent = '$0.00';
        return;
    }

    const cartElements = [];

    cartItems.forEach(item => {
        const product = getProductById(parseInt(item.productId));
        const quantity = Number.parseInt(item.quantity, 10);
        if (product && Number.isInteger(quantity) && quantity > 0) {
            const effectivePrice = getEffectivePrice(product);
            const subtotal = effectivePrice * quantity;
            total += subtotal;
            const isOutOfStock = product.stock < quantity;

            if (isOutOfStock) {
                hasOutOfStockItems = true;
            }

            // Determinar si hay precio de liquidación
            const hasLiquidation = product.price_sale && parseFloat(product.price_sale) > 0;
            const originalPrice = parseFloat(product.price);
            const currentPrice = effectivePrice;

            const cartItem = document.createElement('div');
            cartItem.className = `border-bottom p-3 cart-item${isOutOfStock ? ' bg-warning bg-opacity-10' : ''}`;
            const row = document.createElement('div');
            row.className = 'row align-items-center';

            const productColumn = document.createElement('div');
            productColumn.className = 'col-lg-5 col-md-12 mb-3 mb-lg-0';
            const productDetails = document.createElement('div');
            productDetails.className = 'd-flex align-items-center';
            const imageContainer = document.createElement('div');
            imageContainer.className = 'me-3 flex-shrink-0';
            imageContainer.append(createProductImage(product.id, product.name));
            const productText = document.createElement('div');
            productText.className = 'flex-grow-1';
            const productName = document.createElement('h6');
            productName.className = 'mb-1';
            productName.textContent = String(product.name);
            const prices = document.createElement('div');
            prices.className = 'd-flex align-items-center';
            if (hasLiquidation) {
                const liquidation = document.createElement('span');
                liquidation.className = 'badge bg-danger me-2';
                liquidation.textContent = 'LIQUIDACIÓN';
                const original = document.createElement('span');
                original.className = 'text-decoration-line-through text-muted me-2';
                original.textContent = `$${originalPrice.toFixed(2)}`;
                const current = document.createElement('span');
                current.className = 'fw-bold text-danger';
                current.textContent = `$${currentPrice.toFixed(2)}`;
                prices.append(liquidation, original, current);
            } else {
                const current = document.createElement('span');
                current.className = 'fw-bold text-primary';
                current.textContent = `$${currentPrice.toFixed(2)}`;
                prices.append(current);
            }
            productText.append(productName, prices);
            productDetails.append(imageContainer, productText);
            productColumn.append(productDetails);

            const quantityColumn = document.createElement('div');
            quantityColumn.className = 'col-lg-3 col-md-6 mb-3 mb-lg-0';
            const quantityControls = document.createElement('div');
            quantityControls.className = 'd-flex align-items-center justify-content-center';
            const inputGroup = document.createElement('div');
            inputGroup.className = 'input-group';
            inputGroup.style.maxWidth = '130px';
            const quantityInput = document.createElement('input');
            quantityInput.type = 'number';
            quantityInput.className = 'form-control form-control-sm text-center update-quantity';
            quantityInput.value = String(quantity);
            quantityInput.min = '1';
            quantityInput.max = String(product.stock);
            quantityInput.dataset.productId = String(product.id);
            quantityInput.style.padding = '0.25rem';
            quantityInput.addEventListener('change', () => updateCartItemQuantity(product.id, Number.parseInt(quantityInput.value, 10)));

            const createQuantityButton = (iconClass, handler) => {
                const button = document.createElement('button');
                button.className = 'btn btn-outline-secondary btn-sm';
                button.type = 'button';
                button.style.padding = '0.25rem 0.5rem';
                const buttonIcon = document.createElement('i');
                buttonIcon.className = iconClass;
                button.append(buttonIcon);
                button.addEventListener('click', handler);
                return button;
            };
            const decreaseButton = createQuantityButton('bi bi-dash', () => {
                if (quantity > 1) updateCartItemQuantity(product.id, quantity - 1);
            });
            const increaseButton = createQuantityButton('bi bi-plus', () => {
                if (quantity < Number(product.stock)) updateCartItemQuantity(product.id, quantity + 1);
            });
            inputGroup.append(decreaseButton, quantityInput, increaseButton);
            const removeButton = document.createElement('button');
            removeButton.className = 'btn btn-outline-danger btn-sm ms-2 remove-from-cart';
            removeButton.type = 'button';
            removeButton.dataset.productId = String(product.id);
            removeButton.title = 'Eliminar producto';
            const removeIcon = document.createElement('i');
            removeIcon.className = 'bi bi-trash';
            removeButton.append(removeIcon);
            removeButton.addEventListener('click', () => removeFromCart(product.id));
            quantityControls.append(inputGroup, removeButton);
            quantityColumn.append(quantityControls);

            const subtotalColumn = document.createElement('div');
            subtotalColumn.className = 'col-lg-2 col-md-3 mb-2 mb-lg-0';
            const subtotalText = document.createElement('div');
            subtotalText.className = 'text-center';
            const subtotalValue = document.createElement('strong');
            subtotalValue.textContent = `$${subtotal.toFixed(2)}`;
            subtotalText.append(subtotalValue);
            subtotalColumn.append(subtotalText);

            const availabilityColumn = document.createElement('div');
            availabilityColumn.className = 'col-lg-2 col-md-3';
            const availability = document.createElement('div');
            availability.className = 'text-center';
            const availabilityText = document.createElement('small');
            availabilityText.className = isOutOfStock ? 'text-danger' : 'text-success';
            const availabilityIcon = document.createElement('i');
            availabilityIcon.className = isOutOfStock ? 'bi bi-exclamation-triangle me-1' : 'bi bi-check-circle me-1';
            availabilityText.append(availabilityIcon, document.createTextNode(isOutOfStock ? `Solo ${product.stock} disponibles` : 'Disponible'));
            availability.append(availabilityText);
            availabilityColumn.append(availability);
            row.append(productColumn, quantityColumn, subtotalColumn, availabilityColumn);
            cartItem.append(row);
            cartElements.push(cartItem);
        }
    });

    cartContainer.replaceChildren(...cartElements);
    cartTotal.textContent = `$${total.toFixed(2)}`;

    // Deshabilitar botón de WhatsApp si hay productos sin stock
    const whatsappBtn = document.getElementById('whatsapp-order');
    if (hasOutOfStockItems) {
        whatsappBtn.classList.add('disabled');
        whatsappBtn.setAttribute('title', 'Ajusta las cantidades antes de enviar el pedido');
    } else {
        whatsappBtn.classList.remove('disabled');
        whatsappBtn.removeAttribute('title');
    }
}

function updateCartItemQuantity(productId, quantity) {
    if (!Number.isSafeInteger(quantity) || quantity < 1) return;

    const cart = normalizedCart();
    const index = cart.findIndex(item => String(item.productId) === String(productId));

    if (index !== -1) {
        cart[index].quantity = quantity;
        localStorage.setItem('cart', JSON.stringify(cart));
        loadCartItems();
        updateCartCount();
    }
}

function removeFromCart(productId) {
    let cart = normalizedCart();
    cart = cart.filter(item => String(item.productId) !== String(productId));
    localStorage.setItem('cart', JSON.stringify(cart));
    loadCartItems();
    updateCartCount();
}

function updateCartCount() {
    const cart = normalizedCart();
    const count = cart.reduce((total, item) => total + item.quantity, 0);
    document.querySelectorAll('.cart-count').forEach(el => {
        el.textContent = count;
    });
}

// Registra la solicitud y reserva stock antes de abrir WhatsApp.
document.getElementById('whatsapp-order').addEventListener('click', async function(e) {
    e.preventDefault();
    const button = this;
    const cart = normalizedCart();
    if (!cart.length || button.classList.contains('disabled')) return;

    button.classList.add('disabled');
    const spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm me-2';
    spinner.setAttribute('aria-hidden', 'true');
    button.replaceChildren(spinner, document.createTextNode('Registrando pedido...'));
    const whatsappWindow = window.open('', '_blank');
    const idempotencyKey = sessionStorage.getItem('checkout_idempotency_key') || crypto.randomUUID().replace(/-/g, '') + crypto.randomUUID().replace(/-/g, '');
    sessionStorage.setItem('checkout_idempotency_key', idempotencyKey);
    try {
        const response = await fetch('create_order.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({items: cart, idempotencyKey})
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'No se pudo registrar el pedido.');
        localStorage.removeItem('cart'); sessionStorage.removeItem('checkout_idempotency_key');
        if (result.whatsappUrl) whatsappWindow.location = result.whatsappUrl; else whatsappWindow.close();
        loadCartItems();
        updateCartCount();
        alert(`Pedido #${result.orderId} registrado. Te llevamos a WhatsApp para coordinarlo.`);
    } catch (error) {
        if (whatsappWindow) whatsappWindow.close();
        alert(error.message);
        button.classList.remove('disabled');
        const whatsappIcon = document.createElement('i');
        whatsappIcon.className = 'bi bi-whatsapp me-2';
        button.replaceChildren(whatsappIcon, document.createTextNode('Enviar Pedido por WhatsApp'));
    }
});

document.addEventListener('DOMContentLoaded', () => {
    loadCartItems();
    updateCartCount();
});



</script>

</body>
</html>
