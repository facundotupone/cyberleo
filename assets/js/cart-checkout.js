(function () {
    'use strict';

    function readJsonBoot(id) {
        var el = document.getElementById(id);
        if (!el) return null;
        try {
            return JSON.parse(el.textContent || 'null');
        } catch (e) {
            return null;
        }
    }

    var productData = readJsonBoot('cart-products-boot');
    var productImages = readJsonBoot('cart-images-boot');
    var checkout = readJsonBoot('cart-checkout-boot') || {};
    if (!Array.isArray(productData)) productData = [];
    if (!productImages || typeof productImages !== 'object') productImages = {};

    function cfg(key, fallback) {
        var value = checkout[key];
        return value === undefined || value === null || value === '' ? fallback : String(value);
    }

    function cfgOn(key) {
        return cfg(key, '0') === '1';
    }

    function formatMoney(amount) {
        var n = Number(amount);
        if (!Number.isFinite(n)) n = 0;
        var fixed = Math.round(n * 100) / 100;
        var parts = fixed.toFixed(2).split('.');
        var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return '$' + intPart + ',' + parts[1];
    }

    function applyTemplate(template, vars) {
        var out = String(template || '');
        Object.keys(vars).forEach(function (key) {
            out = out.split('{' + key + '}').join(String(vars[key]));
        });
        return out;
    }

    function getProductById(productId) {
        return productData.find(function (product) {
            return String(product.id) === String(productId);
        });
    }

    function normalizedCart() {
        var stored;
        try {
            stored = JSON.parse(localStorage.getItem('cart'));
        } catch (e) {
            stored = [];
        }
        var quantities = new Map();
        if (Array.isArray(stored)) {
            stored.forEach(function (item) {
                if (!item || typeof item !== 'object') return;
                var id = String(item.productId ?? '');
                var quantity = Number(item.quantity);
                if (!/^[1-9]\d*$/.test(id)
                    || !Number.isSafeInteger(quantity)
                    || quantity < 1
                    || !getProductById(id)) return;
                var combined = (quantities.get(id) || 0) + quantity;
                if (Number.isSafeInteger(combined)) quantities.set(id, combined);
            });
        }
        var cart = Array.from(quantities, function (entry) {
            return { productId: entry[0], quantity: entry[1] };
        });
        try {
            if (cart.length > 0) {
                localStorage.setItem('cart', JSON.stringify(cart));
            } else {
                localStorage.removeItem('cart');
            }
        } catch (e) {
            // storage blocked — continue with in-memory cart
        }
        return cart;
    }

    function getProductImage(productId) {
        if (productImages[productId] && productImages[productId].length > 0) {
            return productImages[productId][0];
        }
        var product = getProductById(productId);
        if (product && product.image) return product.image;
        return null;
    }

    function isSafeLocalImagePath(imagePath) {
        return typeof imagePath === 'string'
            && /^assets\/images\/products\/(?:[a-f0-9]{13}|[a-f0-9]{32})\.(?:jpg|jpeg|png|webp)$/i.test(imagePath);
    }

    function createImagePlaceholder() {
        var placeholder = document.createElement('div');
        placeholder.className = 'product-image-placeholder';
        var icon = document.createElement('i');
        icon.className = 'bi bi-image';
        icon.setAttribute('aria-hidden', 'true');
        placeholder.append(icon);
        return placeholder;
    }

    function createProductImage(productId, productName) {
        var imagePath = getProductImage(productId);
        if (!isSafeLocalImagePath(imagePath)) {
            return createImagePlaceholder();
        }
        var image = document.createElement('img');
        image.src = imagePath;
        image.alt = String(productName || '');
        image.className = 'cart-product-image';
        image.loading = 'lazy';
        image.decoding = 'async';
        var fit = cfg('cart_image_fit', 'cover');
        if (fit === 'contain' || fit === 'cover') {
            image.style.objectFit = fit;
        }
        image.addEventListener('error', function () {
            image.replaceWith(createImagePlaceholder());
        }, { once: true });
        return image;
    }

    function getEffectivePrice(product) {
        if (product.price_sale && parseFloat(product.price_sale) > 0) {
            return parseFloat(product.price_sale);
        }
        return parseFloat(product.price);
    }

    function setOrderButtonEnabled(enabled) {
        var button = document.getElementById('whatsapp-order');
        if (!button) return;
        if (enabled) {
            button.classList.remove('disabled');
            button.removeAttribute('aria-disabled');
            button.removeAttribute('tabindex');
            button.removeAttribute('title');
        } else {
            button.classList.add('disabled');
            button.setAttribute('aria-disabled', 'true');
            button.setAttribute('tabindex', '-1');
        }
    }

    function restoreOrderButtonLabel() {
        var button = document.getElementById('whatsapp-order');
        if (!button) return;
        var icon = document.createElement('i');
        icon.className = 'bi bi-whatsapp me-2';
        icon.setAttribute('aria-hidden', 'true');
        button.replaceChildren(icon, document.createTextNode(cfg('cart_order_button_text', 'Enviar Pedido por WhatsApp')));
    }

    function renderEmptyState(cartContainer, cartTotal) {
        var emptyCart = document.createElement('div');
        emptyCart.className = 'cart-empty-state';
        var icon = document.createElement('i');
        icon.className = 'bi bi-cart-x fs-1 mb-3 d-block';
        icon.setAttribute('aria-hidden', 'true');
        var title = document.createElement('h5');
        title.className = 'text-muted';
        title.textContent = cfg('cart_empty_title', 'Tu carrito está vacío');
        var message = document.createElement('p');
        message.className = 'text-muted';
        message.textContent = cfg('cart_empty_text', 'Agrega algunos productos para comenzar');
        var link = document.createElement('a');
        link.href = 'index.php';
        link.className = 'btn btn-primary';
        var linkIcon = document.createElement('i');
        linkIcon.className = 'bi bi-shop me-2';
        linkIcon.setAttribute('aria-hidden', 'true');
        link.append(linkIcon, document.createTextNode(cfg('cart_empty_button_text', 'Explorar productos')));
        emptyCart.append(icon, title, message, link);
        cartContainer.replaceChildren(emptyCart);
        cartTotal.textContent = formatMoney(0);
        setOrderButtonEnabled(false);
    }

    function loadCartItems() {
        var cartItems = normalizedCart();
        var cartContainer = document.getElementById('cart-items');
        var cartTotal = document.getElementById('cart-total');
        if (!cartContainer || !cartTotal) return;

        var total = 0;
        var hasOutOfStockItems = false;
        var showImages = cfgOn('cart_show_images');
        var showBadge = cfgOn('cart_show_sale_badge');
        var showOld = cfgOn('cart_show_old_price');
        var showStock = cfgOn('cart_show_stock_status');
        var saleBadgeText = cfg('sale_badge_text', 'LIQUIDACIÓN');

        if (cartItems.length === 0) {
            renderEmptyState(cartContainer, cartTotal);
            return;
        }

        var cartElements = [];
        cartItems.forEach(function (item) {
            var product = getProductById(item.productId);
            var quantity = Number.parseInt(item.quantity, 10);
            if (!product || !Number.isInteger(quantity) || quantity < 1) return;

            var effectivePrice = getEffectivePrice(product);
            var subtotal = effectivePrice * quantity;
            total += subtotal;
            var isOutOfStock = Number(product.stock) < quantity;
            if (isOutOfStock) hasOutOfStockItems = true;

            var hasLiquidation = product.price_sale && parseFloat(product.price_sale) > 0;
            var originalPrice = parseFloat(product.price);
            var currentPrice = effectivePrice;

            var cartItem = document.createElement('div');
            cartItem.className = 'border-bottom p-3 cart-item' + (isOutOfStock ? ' bg-warning bg-opacity-10' : '');
            var row = document.createElement('div');
            row.className = 'row align-items-center';

            var productColumn = document.createElement('div');
            productColumn.className = 'col-lg-5 col-md-12 mb-3 mb-lg-0';
            var productDetails = document.createElement('div');
            productDetails.className = 'd-flex align-items-center';
            if (showImages) {
                var imageContainer = document.createElement('div');
                imageContainer.className = 'me-3 flex-shrink-0';
                imageContainer.append(createProductImage(product.id, product.name));
                productDetails.append(imageContainer);
            }
            var productText = document.createElement('div');
            productText.className = 'flex-grow-1';
            var productName = document.createElement('h6');
            productName.className = 'mb-1';
            productName.textContent = String(product.name || '');
            var prices = document.createElement('div');
            prices.className = 'd-flex align-items-center flex-wrap gap-1';
            if (hasLiquidation) {
                if (showBadge) {
                    var liquidation = document.createElement('span');
                    liquidation.className = 'badge bg-danger me-1';
                    liquidation.textContent = saleBadgeText;
                    prices.append(liquidation);
                }
                if (showOld) {
                    var original = document.createElement('span');
                    original.className = 'text-decoration-line-through text-muted me-1';
                    original.textContent = formatMoney(originalPrice);
                    prices.append(original);
                }
                var current = document.createElement('span');
                current.className = 'fw-bold text-danger';
                current.textContent = formatMoney(currentPrice);
                prices.append(current);
            } else {
                var only = document.createElement('span');
                only.className = 'fw-bold text-primary';
                only.textContent = formatMoney(currentPrice);
                prices.append(only);
            }
            productText.append(productName, prices);
            productDetails.append(productText);
            productColumn.append(productDetails);

            var quantityColumn = document.createElement('div');
            quantityColumn.className = 'col-lg-3 col-md-6 mb-3 mb-lg-0';
            var quantityControls = document.createElement('div');
            quantityControls.className = 'd-flex align-items-center justify-content-center';
            var inputGroup = document.createElement('div');
            inputGroup.className = 'input-group';
            inputGroup.style.maxWidth = '130px';
            var quantityInput = document.createElement('input');
            quantityInput.type = 'number';
            quantityInput.className = 'form-control form-control-sm text-center update-quantity';
            quantityInput.value = String(quantity);
            quantityInput.min = '1';
            quantityInput.max = String(product.stock);
            quantityInput.dataset.productId = String(product.id);
            quantityInput.setAttribute('aria-label', 'Cantidad de ' + String(product.name || 'producto'));
            quantityInput.style.padding = '0.25rem';
            quantityInput.addEventListener('change', function () {
                updateCartItemQuantity(product.id, Number.parseInt(quantityInput.value, 10));
            });

            function createQuantityButton(iconClass, label, handler) {
                var button = document.createElement('button');
                button.className = 'btn btn-outline-secondary btn-sm';
                button.type = 'button';
                button.style.padding = '0.25rem 0.5rem';
                button.setAttribute('aria-label', label);
                var buttonIcon = document.createElement('i');
                buttonIcon.className = iconClass;
                buttonIcon.setAttribute('aria-hidden', 'true');
                button.append(buttonIcon);
                button.addEventListener('click', handler);
                return button;
            }
            var decreaseButton = createQuantityButton('bi bi-dash', 'Disminuir cantidad', function () {
                if (quantity > 1) updateCartItemQuantity(product.id, quantity - 1);
            });
            var increaseButton = createQuantityButton('bi bi-plus', 'Aumentar cantidad', function () {
                if (quantity < Number(product.stock)) updateCartItemQuantity(product.id, quantity + 1);
            });
            inputGroup.append(decreaseButton, quantityInput, increaseButton);
            var removeButton = document.createElement('button');
            removeButton.className = 'btn btn-outline-danger btn-sm ms-2 remove-from-cart';
            removeButton.type = 'button';
            removeButton.dataset.productId = String(product.id);
            removeButton.title = 'Eliminar producto';
            removeButton.setAttribute('aria-label', 'Eliminar ' + String(product.name || 'producto'));
            var removeIcon = document.createElement('i');
            removeIcon.className = 'bi bi-trash';
            removeIcon.setAttribute('aria-hidden', 'true');
            removeButton.append(removeIcon);
            removeButton.addEventListener('click', function () {
                removeFromCart(product.id);
            });
            quantityControls.append(inputGroup, removeButton);
            quantityColumn.append(quantityControls);

            var subtotalColumn = document.createElement('div');
            subtotalColumn.className = 'col-lg-2 col-md-3 mb-2 mb-lg-0';
            var subtotalText = document.createElement('div');
            subtotalText.className = 'text-center';
            var subtotalValue = document.createElement('strong');
            subtotalValue.textContent = formatMoney(subtotal);
            subtotalText.append(subtotalValue);
            subtotalColumn.append(subtotalText);

            var availabilityColumn = document.createElement('div');
            availabilityColumn.className = 'col-lg-2 col-md-3';
            if (showStock) {
                var availability = document.createElement('div');
                availability.className = 'text-center';
                var availabilityText = document.createElement('small');
                availabilityText.className = isOutOfStock ? 'text-danger' : 'text-success';
                var availabilityIcon = document.createElement('i');
                availabilityIcon.className = isOutOfStock ? 'bi bi-exclamation-triangle me-1' : 'bi bi-check-circle me-1';
                availabilityIcon.setAttribute('aria-hidden', 'true');
                var stockLabel = isOutOfStock
                    ? applyTemplate(cfg('cart_stock_template', 'Solo {stock} disponibles'), { stock: String(product.stock) })
                    : cfg('cart_available_text', 'Disponible');
                availabilityText.append(availabilityIcon, document.createTextNode(stockLabel));
                availability.append(availabilityText);
                availabilityColumn.append(availability);
            }

            row.append(productColumn, quantityColumn, subtotalColumn, availabilityColumn);
            cartItem.append(row);
            cartElements.push(cartItem);
        });

        cartContainer.replaceChildren.apply(cartContainer, cartElements);
        cartTotal.textContent = formatMoney(total);
        setOrderButtonEnabled(!hasOutOfStockItems && cartElements.length > 0);
        if (hasOutOfStockItems) {
            var btn = document.getElementById('whatsapp-order');
            if (btn) btn.setAttribute('title', 'Ajusta las cantidades antes de enviar el pedido');
        }
    }

    function updateCartItemQuantity(productId, quantity) {
        if (!Number.isSafeInteger(quantity) || quantity < 1) return;
        var product = getProductById(productId);
        if (!product) return;
        if (quantity > Number(product.stock)) quantity = Number(product.stock);
        if (quantity < 1) return;
        var cart = normalizedCart();
        var index = cart.findIndex(function (item) {
            return String(item.productId) === String(productId);
        });
        if (index !== -1) {
            cart[index].quantity = quantity;
            try {
                localStorage.setItem('cart', JSON.stringify(cart));
            } catch (e) { /* ignore */ }
            loadCartItems();
            updateCartCount();
        }
    }

    function removeFromCart(productId) {
        var cart = normalizedCart().filter(function (item) {
            return String(item.productId) !== String(productId);
        });
        try {
            localStorage.setItem('cart', JSON.stringify(cart));
        } catch (e) { /* ignore */ }
        loadCartItems();
        updateCartCount();
    }

    function updateCartCount() {
        var cart = normalizedCart();
        var count = cart.reduce(function (total, item) {
            return total + item.quantity;
        }, 0);
        document.querySelectorAll('.cart-count').forEach(function (el) {
            el.textContent = String(count);
        });
    }

    function bindOrderButton() {
        var button = document.getElementById('whatsapp-order');
        if (!button || button.dataset.checkoutBound === '1') return;
        button.dataset.checkoutBound = '1';
        button.addEventListener('click', async function (e) {
            e.preventDefault();
            var cart = normalizedCart();
            if (!cart.length || button.classList.contains('disabled') || button.getAttribute('aria-disabled') === 'true') {
                return;
            }
            button.classList.add('disabled');
            button.setAttribute('aria-disabled', 'true');
            var spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-2';
            spinner.setAttribute('aria-hidden', 'true');
            button.replaceChildren(spinner, document.createTextNode(cfg('cart_registering_text', 'Registrando pedido...')));
            var whatsappWindow = null;
            try {
                whatsappWindow = window.open('', '_blank');
            } catch (err) {
                whatsappWindow = null;
            }

            function isValidIdempotencyKey(key) {
                return typeof key === 'string' && /^[a-f0-9]{64}$/.test(key);
            }

            function generateIdempotencyKey() {
                if (!globalThis.crypto || typeof globalThis.crypto.getRandomValues !== 'function') {
                    return null;
                }
                var bytes = new Uint8Array(32);
                globalThis.crypto.getRandomValues(bytes);
                var hex = '';
                for (var i = 0; i < bytes.length; i += 1) {
                    hex += bytes[i].toString(16).padStart(2, '0');
                }
                return hex;
            }

            var idempotencyKey = null;
            try {
                var storedKey = sessionStorage.getItem('checkout_idempotency_key');
                if (isValidIdempotencyKey(storedKey)) {
                    idempotencyKey = storedKey;
                } else if (storedKey !== null) {
                    sessionStorage.removeItem('checkout_idempotency_key');
                }
            } catch (err) {
                // sessionStorage blocked
            }
            if (!idempotencyKey) {
                idempotencyKey = generateIdempotencyKey();
                if (!idempotencyKey) {
                    if (whatsappWindow) {
                        try { whatsappWindow.close(); } catch (e) { /* ignore */ }
                    }
                    window.alert('No se pudo preparar el pedido de forma segura. Intentá nuevamente.');
                    button.classList.remove('disabled');
                    button.removeAttribute('aria-disabled');
                    restoreOrderButtonLabel();
                    return;
                }
                try {
                    sessionStorage.setItem('checkout_idempotency_key', idempotencyKey);
                } catch (err) {
                    // Keep in-memory key for this attempt.
                }
            }
            try {
                var response = await fetch('create_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ items: cart, idempotencyKey: idempotencyKey })
                });
                var result;
                try {
                    result = await response.json();
                } catch (parseErr) {
                    throw new Error('No se pudo registrar el pedido.');
                }
                if (!response.ok || !result || !result.success) {
                    throw new Error((result && result.message) || 'No se pudo registrar el pedido.');
                }
                try {
                    localStorage.removeItem('cart');
                    sessionStorage.removeItem('checkout_idempotency_key');
                } catch (err) { /* ignore */ }
                if (result.whatsappUrl && typeof result.whatsappUrl === 'string' && /^https:\/\/wa\.me\//i.test(result.whatsappUrl)) {
                    if (whatsappWindow) {
                        whatsappWindow.location = result.whatsappUrl;
                    } else {
                        window.location.href = result.whatsappUrl;
                    }
                } else if (whatsappWindow) {
                    whatsappWindow.close();
                }
                loadCartItems();
                updateCartCount();
                window.alert(applyTemplate(
                    cfg('cart_success_template', 'Pedido #{order_id} registrado. Te llevamos a WhatsApp para coordinarlo.'),
                    { order_id: String(result.orderId || '') }
                ));
            } catch (error) {
                if (whatsappWindow) {
                    try { whatsappWindow.close(); } catch (e) { /* ignore */ }
                }
                window.alert(error && error.message ? String(error.message) : 'No se pudo registrar el pedido.');
                button.classList.remove('disabled');
                button.removeAttribute('aria-disabled');
                restoreOrderButtonLabel();
            }
        });
    }

    function init() {
        loadCartItems();
        updateCartCount();
        bindOrderButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
