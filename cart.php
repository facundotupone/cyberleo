<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Obtener las categorías para el menú
$categories = get_categories();

// Obtener los datos de los productos para el carrito (incluyendo stock e imágenes)
$stmt = $pdo->prepare("SELECT id, name, price, price_sale, stock, image FROM products");
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener imágenes adicionales de product_images
$productImages = [];
$stmtImages = $pdo->prepare("SELECT product_id, image_path FROM product_images ORDER BY is_main DESC");
$stmtImages->execute();
foreach ($stmtImages->fetchAll(PDO::FETCH_ASSOC) as $row) {
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

<div class="container my-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex align-items-center mb-4">
                <i class="bi bi-cart3 fs-2 text-primary me-3"></i>
                <h1 class="mb-0">Carrito de Compras</h1>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light border-0">
                    <h5 class="mb-0">Productos en tu carrito</h5>
                </div>
                <div class="card-body p-0">
                    <div id="cart-items">
                        <!-- Los items del carrito se cargarán dinámicamente aquí -->
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Resumen del pedido</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">Total:</h4>
                        <h4 id="cart-total" class="mb-0 text-primary">$0.00</h4>
                    </div>
                    
                    <div class="bg-light p-3 rounded mb-3">
                        <h6 class="mb-2"><i class="bi bi-info-circle me-2"></i>Información de envío</h6>
                        <p class="small mb-0">Envíanos tu consulta y te responderemos a la brevedad para coordinar envío o retiro.</p>
                    </div>
                    
                    <div class="mb-3">
                        <h6><i class="bi bi-credit-card me-2"></i>Métodos de pago:</h6>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-success">Efectivo</span>
                            <span class="badge bg-info">Transferencia</span>
                            <span class="badge bg-warning text-dark">Mercado Pago</span>
                        </div>
                        <small class="text-muted">Abonas al recibir tu pedido</small>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="#" id="whatsapp-order" class="btn btn-success btn-lg" target="_blank">
                            <i class="bi bi-whatsapp me-2"></i>Enviar Pedido por WhatsApp
                        </a>
                        <a href="index.php" class="btn btn-outline-primary">
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
const productData = <?php echo json_encode($products); ?>;
const productImages = <?php echo json_encode($productImages); ?>;

// Función para obtener los datos de un producto por su ID
function getProductById(productId) {
    return productData.find(product => product.id == productId);
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

// Función para generar el HTML de la imagen del producto
function getProductImageHtml(productId, productName) {
    const imageSrc = getProductImage(productId);
    
    if (imageSrc) {
        return `<img src="${imageSrc}" 
                     alt="${productName}" 
                     class="cart-product-image"
                     onerror="this.parentElement.innerHTML='<div class=\\'product-image-placeholder\\'><i class=\\'bi bi-image\\'></i></div>'">`;
    } else {
        return `<div class="product-image-placeholder">
                    <i class="bi bi-image"></i>
                </div>`;
    }
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
    const cartItems = JSON.parse(localStorage.getItem('cart')) || [];
    const cartContainer = document.getElementById('cart-items');
    const cartTotal = document.getElementById('cart-total');
    let total = 0;
    let hasOutOfStockItems = false;

    if (cartItems.length === 0) {
        cartContainer.innerHTML = `
            <div class="text-center py-5">
                <i class="bi bi-cart-x fs-1 text-muted mb-3"></i>
                <h5 class="text-muted">Tu carrito está vacío</h5>
                <p class="text-muted">Agrega algunos productos para comenzar</p>
                <a href="index.php" class="btn btn-primary">
                    <i class="bi bi-shop me-2"></i>Explorar productos
                </a>
            </div>`;
        cartTotal.textContent = '$0.00';
        return;
    }

    let html = '';

    cartItems.forEach((item, idx) => {
        const product = getProductById(parseInt(item.productId));
        if (product) {
            const effectivePrice = getEffectivePrice(product);
            const subtotal = effectivePrice * item.quantity;
            total += subtotal;
            const isOutOfStock = product.stock < item.quantity;

            if (isOutOfStock) {
                hasOutOfStockItems = true;
            }

            // Determinar si hay precio de liquidación
            const hasLiquidation = product.price_sale && parseFloat(product.price_sale) > 0;
            const originalPrice = parseFloat(product.price);
            const currentPrice = effectivePrice;

            html += `
                <div class="border-bottom p-3 cart-item ${isOutOfStock ? 'bg-warning bg-opacity-10' : ''}">
                    <div class="row align-items-center">
                        <div class="col-lg-5 col-md-12 mb-3 mb-lg-0">
                            <div class="d-flex align-items-center">
                                <div class="me-3 flex-shrink-0">
                                    ${getProductImageHtml(product.id, product.name)}
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">${product.name}</h6>
                                    <div class="d-flex align-items-center">
                                        ${hasLiquidation ? 
                                            `<span class="badge bg-danger me-2">LIQUIDACIÓN</span>
                                             <span class="text-decoration-line-through text-muted me-2">$${originalPrice.toFixed(2)}</span>
                                             <span class="fw-bold text-danger">$${currentPrice.toFixed(2)}</span>` : 
                                            `<span class="fw-bold text-primary">$${currentPrice.toFixed(2)}</span>`
                                        }
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-3 mb-lg-0">
                            <div class="d-flex align-items-center justify-content-center">
                                <div class="input-group" style="max-width: 130px;">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="decreaseQuantity(${item.productId})" style="padding: 0.25rem 0.5rem;">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <input type="number" class="form-control form-control-sm text-center update-quantity" 
                                        value="${item.quantity}" min="1" max="${product.stock}"
                                        data-product-id="${item.productId}" style="padding: 0.25rem;">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="increaseQuantity(${item.productId})" style="padding: 0.25rem 0.5rem;">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                <button class="btn btn-outline-danger btn-sm ms-2 remove-from-cart" 
                                        data-product-id="${item.productId}" title="Eliminar producto">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-3 mb-2 mb-lg-0">
                            <div class="text-center">
                                <strong>$${subtotal.toFixed(2)}</strong>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-3">
                            <div class="text-center">
                                ${isOutOfStock ? 
                                `<small class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Solo ${product.stock} disponibles</small>` : 
                                `<small class="text-success"><i class="bi bi-check-circle me-1"></i>Disponible</small>`}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
    });

    cartContainer.innerHTML = html;
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

    addCartEventListeners();
}

function addCartEventListeners() {
    // Event listeners para actualizar cantidad
    document.querySelectorAll('.update-quantity').forEach(input => {
        input.addEventListener('change', function() {
            const productId = this.dataset.productId;
            const quantity = parseInt(this.value);
            updateCartItemQuantity(productId, quantity);
        });
    });

    // Event listeners para eliminar productos
    document.querySelectorAll('.remove-from-cart').forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.dataset.productId;
            removeFromCart(productId);
        });
    });

}

function updateCartItemQuantity(productId, quantity) {
    if (quantity < 1) return;

    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    const index = cart.findIndex(item => item.productId == productId);
    
    if (index !== -1) {
        cart[index].quantity = quantity;
        localStorage.setItem('cart', JSON.stringify(cart));
        loadCartItems();
        updateCartCount();
    }
}

// Funciones para aumentar y disminuir cantidad
function increaseQuantity(productId) {
    const input = document.querySelector(`input[data-product-id="${productId}"]`);
    const currentQuantity = parseInt(input.value);
    const maxQuantity = parseInt(input.max);
    
    if (currentQuantity < maxQuantity) {
        input.value = currentQuantity + 1;
        updateCartItemQuantity(productId, currentQuantity + 1);
    }
}

function decreaseQuantity(productId) {
    const input = document.querySelector(`input[data-product-id="${productId}"]`);
    const currentQuantity = parseInt(input.value);
    
    if (currentQuantity > 1) {
        input.value = currentQuantity - 1;
        updateCartItemQuantity(productId, currentQuantity - 1);
    }
}

function removeFromCart(productId) {
    let cart = JSON.parse(localStorage.getItem('cart')) || [];
    cart = cart.filter(item => item.productId != productId);
    localStorage.setItem('cart', JSON.stringify(cart));
    loadCartItems();
    updateCartCount();
}

function updateCartCount() {
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    const count = cart.reduce((total, item) => total + item.quantity, 0);
    document.querySelectorAll('.cart-count').forEach(el => {
        el.textContent = count;
    });
}

// Registra la solicitud y reserva stock antes de abrir WhatsApp.
document.getElementById('whatsapp-order').addEventListener('click', async function(e) {
    e.preventDefault();
    const button = this;
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    if (!cart.length || button.classList.contains('disabled')) return;

    button.classList.add('disabled');
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Registrando pedido...';
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
        button.innerHTML = '<i class="bi bi-whatsapp me-2"></i>Enviar Pedido por WhatsApp';
    }
});

document.addEventListener('DOMContentLoaded', () => {
    loadCartItems();
    updateCartCount();
});



</script>

</body>
</html>