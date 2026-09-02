(function () {
    'use strict';

    var STYLE_ALLOW = { bordered: true, elevated: true, minimal: true };
    var FIT_ALLOW = { contain: true, cover: true };
    var HEIGHT_ALLOW = { compact: true, normal: true, large: true };
    var ALIGN_ALLOW = { left: true, center: true };
    var DESC_ALLOW = { hidden: true, compact: true, expandable: true };
    var LEN_ALLOW = { '100': true, '160': true, '200': true, '300': true };
    var COL_ALLOW = { '2': true, '3': true, '4': true };

    var SAMPLE_DESC = 'Notebook de ejemplo con procesador rápido, pantalla nítida y autonomía pensada para estudio y trabajo diario en la oficina o en casa.';

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

    function truncate(text, len) {
        if (text.length <= len) return text;
        return text.slice(0, len);
    }

    function rebuildCardClasses(card, style, align, fit, height) {
        if (!card) return;
        var keep = [];
        card.className.split(/\s+/).forEach(function (cls) {
            if (!cls) return;
            if (cls.indexOf('product-card-') === 0 && cls !== 'product-card') return;
            if (cls.indexOf('product-fit-') === 0) return;
            if (cls.indexOf('product-height-') === 0) return;
            keep.push(cls);
        });
        keep.push('product-card-' + style);
        keep.push('product-card-align-' + align);
        keep.push('product-fit-' + fit);
        keep.push('product-height-' + height);
        card.className = keep.join(' ');
    }

    function applyCatalogPreview() {
        var style = pick(STYLE_ALLOW, val('product_card_style'), 'elevated');
        var fit = pick(FIT_ALLOW, val('product_image_fit'), 'contain');
        var height = pick(HEIGHT_ALLOW, val('product_image_height'), 'normal');
        var align = pick(ALIGN_ALLOW, val('product_card_alignment'), 'left');
        var descMode = pick(DESC_ALLOW, val('product_description_mode'), 'expandable');
        var descLen = pick(LEN_ALLOW, val('product_description_length'), '200');
        var featuredCols = pick(COL_ALLOW, val('featured_columns'), '3');
        var catalogCols = pick(COL_ALLOW, val('catalog_columns'), '3');

        var card = $('catalog-preview-card');
        rebuildCardClasses(card, style, align, fit, height);

        setText('catalog-preview-featured-cols', featuredCols);
        setText('catalog-preview-catalog-cols', catalogCols);
        setText('catalog-preview-fit', fit);
        setText('catalog-preview-height', height);

        var badgeCat = $('catalog-preview-category');
        setHidden(badgeCat, !checked('product_show_category_badge'));

        var saleBadge = $('catalog-preview-sale-badge');
        var showSale = checked('product_show_sale_badge');
        setHidden(saleBadge, !showSale);
        if (saleBadge) {
            saleBadge.textContent = val('product_sale_badge_text') || 'LIQUIDACIÓN';
        }

        setText('catalog-preview-title', 'Producto de ejemplo');

        var descWrap = $('catalog-preview-desc-wrap');
        var descShort = $('catalog-preview-desc-short');
        var descEllipsis = $('catalog-preview-desc-ellipsis');
        var descMore = $('catalog-preview-desc-more');
        if (descMode === 'hidden') {
            setHidden(descWrap, true);
        } else {
            setHidden(descWrap, false);
            var len = parseInt(descLen, 10) || 200;
            var short = truncate(SAMPLE_DESC, len);
            if (descShort) descShort.textContent = short;
            var hasMore = SAMPLE_DESC.length > len;
            setHidden(descEllipsis, !hasMore);
            setHidden(descMore, !(descMode === 'expandable' && hasMore));
            if (descMore) descMore.textContent = 'Ver más';
        }

        var oldPrice = $('catalog-preview-old-price');
        setHidden(oldPrice, !checked('product_show_old_price'));

        var stock = $('catalog-preview-stock');
        setHidden(stock, !checked('product_show_stock'));
        if (stock && !stock.hidden) {
            stock.textContent = '(Stock: 5)';
        }

        var btnLabel = $('catalog-preview-button-label');
        if (btnLabel) {
            btnLabel.textContent = val('product_add_button_text') || 'Agregar al carrito';
        }

        var shareWrap = $('catalog-preview-share');
        var showShare = checked('product_show_share_buttons');
        var wa = checked('product_share_whatsapp');
        var fb = checked('product_share_facebook');
        var copy = checked('product_share_copy');
        var anyShare = wa || fb || copy;
        setHidden(shareWrap, !(showShare && anyShare));
        setHidden($('catalog-preview-share-wa'), !wa);
        setHidden($('catalog-preview-share-fb'), !fb);
        setHidden($('catalog-preview-share-copy'), !copy);

        setText(
            'catalog-preview-info',
            'Destacados: ' + featuredCols + ' cols · Catálogo: ' + catalogCols + ' cols · ' + style + ' · ' + fit + '/' + height
        );
    }

    function bind() {
        var ids = [
            'featured_section_title', 'featured_empty_text', 'catalog_empty_text',
            'featured_columns', 'catalog_columns',
            'product_card_style', 'product_image_fit', 'product_image_height',
            'product_card_alignment', 'product_description_mode', 'product_description_length',
            'product_show_category_badge', 'product_show_stock', 'product_show_sale_badge',
            'product_show_old_price', 'product_sale_badge_text',
            'product_show_share_buttons', 'product_share_whatsapp', 'product_share_facebook',
            'product_share_copy', 'product_add_button_text', 'product_out_of_stock_text',
            'catalog_show_breadcrumbs', 'catalog_show_product_count', 'catalog_show_subcategory_filter'
        ];
        ids.forEach(function (id) {
            var el = $(id);
            if (!el) return;
            el.addEventListener('input', applyCatalogPreview);
            el.addEventListener('change', applyCatalogPreview);
        });
        var updateBtn = $('update-preview-btn');
        if (updateBtn) updateBtn.addEventListener('click', applyCatalogPreview);

        var restoreCatalog = document.getElementById('restore-catalog-display-form');
        if (restoreCatalog) {
            restoreCatalog.addEventListener('submit', function (event) {
                var ok = window.confirm(
                    '¿Restaurar el catálogo y las tarjetas predeterminadas (Etapa 3)? Se conservarán identidad visual, contenido de portada, WhatsApp, Instagram, pagos, productos, stock y pedidos.'
                );
                if (!ok) event.preventDefault();
            });
        }

        applyCatalogPreview();
    }

    document.addEventListener('DOMContentLoaded', bind);
})();
