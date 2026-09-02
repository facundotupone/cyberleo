(function () {
    'use strict';

    function showToast(message) {
        var toast = document.createElement('div');
        toast.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3';
        toast.style.zIndex = '9999';
        toast.setAttribute('role', 'status');
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 2000);
    }

    function readCart() {
        try {
            var raw = localStorage.getItem('cart');
            var cart = raw ? JSON.parse(raw) : [];
            return Array.isArray(cart) ? cart : [];
        } catch (e) {
            return [];
        }
    }

    function writeCart(cart) {
        localStorage.setItem('cart', JSON.stringify(cart));
    }

    function updateCartCount() {
        var cart = readCart();
        var count = cart.reduce(function (total, item) {
            return total + (parseInt(item.quantity, 10) || 0);
        }, 0);
        document.querySelectorAll('.cart-count').forEach(function (el) {
            el.textContent = String(count);
        });
    }

    function setButtonLabel(btn, text) {
        var label = btn.querySelector('.add-to-cart-label');
        if (label) {
            label.textContent = text;
            return;
        }
        var icon = btn.querySelector('i.bi-cart-plus');
        btn.replaceChildren();
        if (icon) {
            btn.appendChild(icon);
        } else {
            var i = document.createElement('i');
            i.className = 'bi bi-cart-plus';
            i.setAttribute('aria-hidden', 'true');
            btn.appendChild(i);
        }
        var span = document.createElement('span');
        span.className = 'add-to-cart-label';
        span.textContent = text;
        btn.appendChild(document.createTextNode(' '));
        btn.appendChild(span);
    }

    function syncStockVisual() {
        var cart = readCart();
        document.querySelectorAll('.add-to-cart').forEach(function (btn) {
            var productId = btn.getAttribute('data-product-id');
            var stockOriginal = parseInt(btn.getAttribute('data-product-stock-original'), 10) || 0;
            var addText = btn.getAttribute('data-add-text') || 'Agregar al carrito';
            var oosText = btn.getAttribute('data-oos-text') || 'Sin stock';
            var totalQuantity = 0;
            cart.forEach(function (item) {
                if (String(item.productId) === String(productId)) {
                    totalQuantity += parseInt(item.quantity, 10) || 0;
                }
            });
            var stockVisual = stockOriginal - totalQuantity;
            btn.setAttribute('data-product-stock', String(stockVisual));
            var stockDisplay = btn.closest('.card-body')
                ? btn.closest('.card-body').querySelector('.stock-display')
                : null;
            if (stockDisplay) {
                if (stockDisplay.classList.contains('visually-hidden')) {
                    stockDisplay.textContent = String(stockVisual);
                } else {
                    stockDisplay.textContent = '(Stock: ' + stockVisual + ')';
                }
            }
            if (stockVisual <= 0) {
                btn.disabled = true;
                setButtonLabel(btn, oosText);
            } else {
                btn.disabled = false;
                setButtonLabel(btn, addText);
            }
        });
    }

    function bindAddToCart() {
        document.querySelectorAll('.add-to-cart').forEach(function (btn) {
            if (btn.dataset.catalogBound === '1') return;
            btn.dataset.catalogBound = '1';
            btn.addEventListener('click', function () {
                var productId = btn.getAttribute('data-product-id');
                var productName = btn.getAttribute('data-product-name') || '';
                var productPrice = parseFloat(btn.getAttribute('data-product-price') || '0');
                var stockOriginal = parseInt(btn.getAttribute('data-product-stock-original'), 10) || 0;
                var cart = readCart();
                var totalQuantity = 0;
                cart.forEach(function (item) {
                    if (String(item.productId) === String(productId)) {
                        totalQuantity += parseInt(item.quantity, 10) || 0;
                    }
                });
                if (totalQuantity >= stockOriginal) {
                    window.alert('No hay suficiente stock. Disponible: ' + (stockOriginal - totalQuantity));
                    return;
                }
                var index = cart.findIndex(function (item) {
                    return String(item.productId) === String(productId);
                });
                if (index !== -1) {
                    cart[index].quantity = (parseInt(cart[index].quantity, 10) || 0) + 1;
                } else {
                    cart.push({
                        productId: productId,
                        productName: productName,
                        productPrice: productPrice,
                        quantity: 1
                    });
                }
                writeCart(cart);
                updateCartCount();
                showToast('Producto agregado al carrito');
                syncStockVisual();
            });
        });
    }

    function bindDescriptions() {
        document.querySelectorAll('.ver-mas').forEach(function (button) {
            if (button.dataset.catalogBound === '1') return;
            button.dataset.catalogBound = '1';
            button.addEventListener('click', function () {
                var container = button.closest('.description-container');
                if (!container) return;
                var shortDesc = container.querySelector('.short-description');
                var fullDesc = container.querySelector('.full-description');
                var ellipsis = container.querySelector('.ellipsis');
                var isShowingFull = button.getAttribute('data-showing-full') === 'true';
                if (!shortDesc || !fullDesc) return;
                if (isShowingFull) {
                    shortDesc.hidden = false;
                    shortDesc.style.display = '';
                    if (ellipsis) ellipsis.hidden = false;
                    fullDesc.hidden = true;
                    button.textContent = 'Ver más';
                    button.setAttribute('data-showing-full', 'false');
                } else {
                    shortDesc.hidden = true;
                    shortDesc.style.display = 'none';
                    if (ellipsis) ellipsis.hidden = true;
                    fullDesc.hidden = false;
                    button.textContent = 'Ver menos';
                    button.setAttribute('data-showing-full', 'true');
                }
            });
        });
    }

    function bindCopyLinks() {
        document.querySelectorAll('.share-copy-link').forEach(function (btn) {
            if (btn.dataset.catalogBound === '1') return;
            btn.dataset.catalogBound = '1';
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                var url = btn.getAttribute('data-share-url') || '';
                if (!url || !/^https?:\/\//i.test(url)) {
                    showToast('No se pudo copiar el enlace');
                    return;
                }
                var done = function () { showToast('Link copiado al portapapeles'); };
                var fail = function () {
                    try {
                        var input = document.createElement('input');
                        input.value = url;
                        input.setAttribute('readonly', 'readonly');
                        input.style.position = 'fixed';
                        input.style.opacity = '0';
                        document.body.appendChild(input);
                        input.select();
                        document.execCommand('copy');
                        document.body.removeChild(input);
                        done();
                    } catch (e) {
                        showToast('No se pudo copiar el enlace');
                    }
                };
                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    navigator.clipboard.writeText(url).then(done).catch(fail);
                } else {
                    fail();
                }
            });
        });
    }

    function bindImageErrors() {
        document.querySelectorAll('.product-card-image').forEach(function (img) {
            if (img.dataset.catalogBound === '1') return;
            img.dataset.catalogBound = '1';
            img.addEventListener('error', function () {
                var media = img.closest('.product-media');
                if (!media) return;
                media.replaceChildren();
                var empty = document.createElement('div');
                empty.className = 'product-image-empty';
                empty.setAttribute('aria-hidden', 'true');
                var icon = document.createElement('i');
                icon.className = 'bi bi-cpu';
                var span = document.createElement('span');
                span.textContent = 'Sin imagen';
                empty.appendChild(icon);
                empty.appendChild(span);
                media.appendChild(empty);
            });
        });
    }

    function init() {
        syncStockVisual();
        updateCartCount();
        bindAddToCart();
        bindDescriptions();
        bindCopyLinks();
        bindImageErrors();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
