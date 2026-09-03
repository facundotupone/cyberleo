(function () {
    'use strict';

    var LAYOUT_ALLOW = { standard: true, compact: true };
    var FIT_ALLOW = { contain: true, cover: true };
    var SIZE_ALLOW = { compact: true, normal: true, large: true };

    function $(id) {
        return document.getElementById(id);
    }

    function checked(id) {
        var el = $(id);
        return !!(el && el.checked);
    }

    function val(id) {
        var el = $(id);
        return el ? String(el.value || '') : '';
    }

    function setText(id, text) {
        var el = $(id);
        if (el) el.textContent = text;
    }

    function setHidden(el, hide) {
        if (!el) return;
        el.hidden = !!hide;
    }

    function pick(map, raw, fallback) {
        return map[raw] ? raw : fallback;
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

    function parseList(raw) {
        return String(raw || '')
            .split(',')
            .map(function (part) { return part.trim(); })
            .filter(function (part) { return part !== ''; });
    }

    function fillList(containerId, items) {
        var el = $(containerId);
        if (!el) return;
        el.replaceChildren();
        items.forEach(function (item) {
            var li = document.createElement('li');
            li.textContent = item;
            el.append(li);
        });
    }

    /** Client equivalent of is_safe_checkout_terms_url / is_safe_local_theme_url. */
    function isSafeCheckoutTermsUrl(value) {
        var url = String(value || '').trim();
        if (url === '') return true;
        if (url.length > 180) return false;
        if (/[\x00-\x1F\x7F"'<>\\]/.test(url)) return false;
        if (/[\r\n]/.test(url)) return false;
        if (/^(?:https?:|javascript:|data:|file:|vbscript:)/i.test(url)) return false;
        if (url.indexOf('//') === 0) return false;
        if (url.indexOf('..') !== -1) return false;
        if (url.charAt(0) === '#') {
            return /^#[A-Za-z][A-Za-z0-9_:-]{0,80}$/.test(url);
        }
        return /^[A-Za-z0-9][A-Za-z0-9._/?=&%-]{0,179}$/.test(url);
    }

    function applySafeTermsLink(linkEl, rawUrl) {
        if (!linkEl) return false;
        var url = String(rawUrl || '').trim();
        linkEl.removeAttribute('href');
        linkEl.setAttribute('aria-disabled', 'true');
        linkEl.tabIndex = -1;
        if (url === '' || !isSafeCheckoutTermsUrl(url)) {
            setHidden(linkEl, true);
            return false;
        }
        linkEl.setAttribute('href', url);
        linkEl.removeAttribute('aria-disabled');
        linkEl.removeAttribute('tabindex');
        setHidden(linkEl, false);
        return true;
    }

    function applyCheckoutPreview() {
        var layout = pick(LAYOUT_ALLOW, val('cart_layout'), 'standard');
        var fit = pick(FIT_ALLOW, val('cart_image_fit'), 'cover');
        var size = pick(SIZE_ALLOW, val('cart_image_size'), 'normal');

        setText('checkout-preview-page-title', val('cart_page_title') || 'Carrito de Compras');
        setText('checkout-preview-items-title', val('cart_items_title') || 'Productos en tu carrito');
        setText('checkout-preview-summary-title', val('cart_summary_title') || 'Resumen del pedido');
        setText('checkout-preview-total-label', val('cart_total_label') || 'Total:');
        setText('checkout-preview-total-value', formatMoney(259998));
        setText('checkout-preview-product-name', 'Producto de ejemplo');
        setText('checkout-preview-price', formatMoney(129999));
        setText('checkout-preview-old-price', formatMoney(150000));
        setText('checkout-preview-qty', '2');
        setText('checkout-preview-subtotal', formatMoney(259998));
        setText('checkout-preview-order-btn', val('cart_order_button_text') || 'Enviar Pedido por WhatsApp');
        setText('checkout-preview-continue-btn', val('cart_continue_button_text') || 'Seguir Comprando');
        setText('checkout-preview-empty-title', val('cart_empty_title') || 'Tu carrito está vacío');
        setText('checkout-preview-empty-text', val('cart_empty_text') || 'Agrega algunos productos para comenzar');
        setText('checkout-preview-empty-btn', val('cart_empty_button_text') || 'Explorar productos');
        setText('checkout-preview-delivery-title', val('cart_delivery_title') || 'Información de envío');
        setText('checkout-preview-delivery-text', val('cart_delivery_text') || '');
        setText('checkout-preview-delivery-methods-title', val('cart_delivery_methods_title') || 'Formas de entrega:');
        setText('checkout-preview-payment-title', val('cart_payment_title') || 'Métodos de pago:');
        setText('checkout-preview-payment-note', val('cart_payment_note') || '');
        setText('checkout-preview-sale-badge', val('product_sale_badge_text') || 'LIQUIDACIÓN');
        setText(
            'checkout-preview-stock',
            applyTemplate(val('cart_stock_template') || 'Solo {stock} disponibles', { stock: '3' })
        );
        setText(
            'checkout-preview-available',
            val('cart_available_text') || 'Disponible'
        );
        setText(
            'checkout-preview-reservation',
            applyTemplate(val('cart_reservation_text') || 'El stock se reserva durante {minutes} minutos después de registrar el pedido.', { minutes: val('reservation_minutes') || '120' })
        );
        var termsText = val('cart_terms_text').trim();
        setText('checkout-preview-terms', termsText);
        setText(
            'checkout-preview-meta',
            'Layout: ' + layout + ' · Fit: ' + fit + ' · Size: ' + size
        );

        var showImages = checked('cart_show_images');
        var showBadge = checked('cart_show_sale_badge');
        var showOld = checked('cart_show_old_price');
        var showStock = checked('cart_show_stock_status');
        var showDelivery = checked('cart_show_delivery_info');
        var showDeliveryMethods = checked('cart_show_delivery_methods');
        var showPayment = checked('cart_show_payment_methods');
        var showReservation = checked('cart_show_reservation_note');
        var termsEnabled = checked('cart_terms_enabled');
        var sticky = checked('cart_summary_sticky');
        var deliveryMethods = parseList(val('cart_delivery_methods'));
        var hasMethods = showDeliveryMethods && deliveryMethods.length > 0;

        setHidden($('checkout-preview-image-wrap'), !showImages);
        setHidden($('checkout-preview-sale-badge'), !showBadge);
        setHidden($('checkout-preview-old-price'), !showOld);
        setHidden($('checkout-preview-stock-wrap'), !showStock);
        setHidden($('checkout-preview-delivery'), !(showDelivery || hasMethods));
        setHidden($('checkout-preview-delivery-title'), !showDelivery);
        setHidden($('checkout-preview-delivery-text'), !showDelivery);
        setHidden($('checkout-preview-delivery-methods-wrap'), !hasMethods);
        setHidden($('checkout-preview-payment'), !showPayment);
        setHidden($('checkout-preview-reservation'), !showReservation);
        setHidden($('checkout-preview-sticky-flag'), !sticky);

        fillList('checkout-preview-delivery-methods', deliveryMethods);
        fillList('checkout-preview-payment-methods', parseList(val('payment_methods')));

        var card = $('checkout-preview-card');
        if (card) {
            card.classList.toggle('checkout-preview-compact', layout === 'compact');
            card.classList.toggle('checkout-preview-sticky', sticky);
            card.dataset.imageFit = fit;
            card.dataset.imageSize = size;
        }

        var img = $('checkout-preview-image');
        if (img) {
            img.style.objectFit = fit;
        }

        var termsLink = $('checkout-preview-terms-link');
        var termsSpan = $('checkout-preview-terms');
        var safeLink = applySafeTermsLink(termsLink, val('cart_terms_url'));
        var showTerms = termsEnabled && (termsText !== '' || safeLink);
        setHidden($('checkout-preview-terms-wrap'), !showTerms);
        setHidden(termsSpan, !(termsEnabled && termsText !== ''));
        if (termsSpan && termsText === '') {
            termsSpan.textContent = '';
        }

        // Preview never opens WhatsApp or mutates storage.
        var orderBtn = $('checkout-preview-order-btn-el');
        if (orderBtn) {
            orderBtn.setAttribute('type', 'button');
            orderBtn.setAttribute('aria-disabled', 'true');
            orderBtn.disabled = true;
        }

        // Prevent accidental navigation from preview terms links.
        if (termsLink && !termsLink.dataset.previewBound) {
            termsLink.dataset.previewBound = '1';
            termsLink.addEventListener('click', function (e) {
                e.preventDefault();
            });
        }
    }

    function bind() {
        var ids = [
            'cart_page_title', 'cart_items_title', 'cart_summary_title', 'cart_total_label',
            'cart_delivery_title', 'cart_delivery_text', 'cart_delivery_methods_title', 'cart_delivery_methods',
            'cart_payment_title', 'cart_payment_note', 'payment_methods',
            'cart_order_button_text', 'cart_continue_button_text',
            'cart_empty_title', 'cart_empty_text', 'cart_empty_button_text',
            'cart_available_text', 'cart_stock_template', 'cart_registering_text',
            'cart_success_template', 'cart_reservation_text', 'order_whatsapp_template',
            'cart_layout', 'cart_image_fit', 'cart_image_size',
            'cart_show_images', 'cart_show_sale_badge', 'cart_show_old_price', 'cart_show_stock_status',
            'cart_show_delivery_info', 'cart_show_delivery_methods', 'cart_show_payment_methods',
            'cart_show_reservation_note', 'cart_summary_sticky', 'cart_terms_enabled',
            'cart_terms_text', 'cart_terms_url', 'product_sale_badge_text', 'reservation_minutes'
        ];
        ids.forEach(function (id) {
            var el = $(id);
            if (!el) return;
            el.addEventListener('input', applyCheckoutPreview);
            el.addEventListener('change', applyCheckoutPreview);
        });

        var updateBtn = $('update-preview-btn');
        if (updateBtn) {
            updateBtn.addEventListener('click', function (e) {
                e.preventDefault();
                applyCheckoutPreview();
            });
        }

        var restoreForm = document.getElementById('restore-checkout-display-form');
        if (restoreForm) {
            restoreForm.addEventListener('submit', function (e) {
                var ok = window.confirm(
                    '¿Restaurar textos y opciones del carrito y pedido? No se modificarán WhatsApp, pagos, reserva, identidad visual, portada, catálogo, productos ni pedidos.'
                );
                if (!ok) e.preventDefault();
            });
        }

        applyCheckoutPreview();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
