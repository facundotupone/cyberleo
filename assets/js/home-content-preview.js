(function () {
    'use strict';

    var ICON_ALLOW = {
        'bi-truck': true,
        'bi-shield-check': true,
        'bi-whatsapp': true,
        'bi-credit-card': true,
        'bi-headset': true,
        'bi-box-seam': true,
        'bi-lightning-charge': true,
        'bi-tools': true
    };

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

    function setIcon(id, iconClass) {
        var el = $(id);
        if (!el) return;
        var safe = ICON_ALLOW[iconClass] ? iconClass : 'bi-truck';
        el.className = 'bi ' + safe;
    }

    function applyHomePreview() {
        var annEnabled = checked('announcement_enabled');
        var ann = $('preview-announcement');
        if (ann) {
            ann.classList.toggle('is-off', !annEnabled);
            ann.classList.remove('is-primary', 'is-secondary', 'is-navy');
            var style = val('announcement_style') || 'primary';
            if (style !== 'primary' && style !== 'secondary' && style !== 'navy') style = 'primary';
            ann.classList.add('is-' + style);
            setText('preview-announcement-text', val('announcement_text') || 'Aviso de ejemplo');
        }

        var promoEnabled = checked('promo_enabled');
        var promo = $('preview-promo');
        if (promo) {
            promo.classList.toggle('is-off', !promoEnabled);
            setText('preview-promo-title', val('promo_title') || 'Promoción');
            setText('preview-promo-text', val('promo_text') || '');
            setText('preview-promo-button', val('promo_button_text') || 'Ver más');
        }

        var benefitsEnabled = checked('benefits_enabled');
        var benefits = $('preview-benefits');
        if (benefits) {
            benefits.hidden = !benefitsEnabled;
            for (var i = 1; i <= 3; i++) {
                setIcon('preview-benefit-' + i + '-icon', val('benefit_' + i + '_icon'));
                setText('preview-benefit-' + i + '-title', val('benefit_' + i + '_title'));
                setText('preview-benefit-' + i + '-text', val('benefit_' + i + '_text'));
            }
        }

        setText('preview-footer-desc', val('footer_description'));
        setText('preview-footer-ig', val('footer_instagram_text'));
        setText('preview-footer-wa', val('footer_whatsapp_text'));
        setText('preview-footer-hours', val('business_hours'));
        setText('preview-footer-location', val('business_location'));

        var logoWrap = $('preview-footer-logo');
        if (logoWrap) logoWrap.hidden = !checked('footer_show_logo');
        var igWrap = $('preview-footer-ig-wrap');
        if (igWrap) igWrap.hidden = !checked('footer_show_instagram');
        var waWrap = $('preview-footer-wa-wrap');
        if (waWrap) waWrap.hidden = !checked('footer_show_whatsapp');
        var hoursWrap = $('preview-footer-hours-wrap');
        if (hoursWrap) hoursWrap.hidden = !checked('footer_show_business_hours') || !val('business_hours');
        var locWrap = $('preview-footer-location-wrap');
        if (locWrap) locWrap.hidden = !checked('footer_show_location') || !val('business_location');

        var orderBits = [];
        ['featured', 'promo', 'categories', 'benefits'].forEach(function (token) {
            var rank = parseInt(val('home_order_' + token), 10) || 0;
            orderBits.push({ token: token, rank: rank });
        });
        orderBits.sort(function (a, b) { return a.rank - b.rank; });
        var labels = {
            featured: 'Destacados',
            promo: 'Promo',
            categories: 'Categorías',
            benefits: 'Beneficios'
        };
        setText(
            'preview-home-order',
            orderBits.map(function (item) { return labels[item.token] || item.token; }).join(' → ')
        );
    }

    function bind() {
        var ids = [
            'announcement_enabled', 'announcement_text', 'announcement_url', 'announcement_style',
            'promo_enabled', 'promo_title', 'promo_text', 'promo_button_text', 'promo_button_url',
            'benefits_enabled',
            'benefit_1_icon', 'benefit_1_title', 'benefit_1_text',
            'benefit_2_icon', 'benefit_2_title', 'benefit_2_text',
            'benefit_3_icon', 'benefit_3_title', 'benefit_3_text',
            'footer_description', 'footer_instagram_text', 'footer_whatsapp_text',
            'footer_show_logo', 'footer_show_instagram', 'footer_show_whatsapp',
            'footer_show_business_hours', 'business_hours',
            'footer_show_location', 'business_location',
            'home_order_featured', 'home_order_promo', 'home_order_categories', 'home_order_benefits'
        ];
        ids.forEach(function (id) {
            var el = $(id);
            if (!el) return;
            el.addEventListener('input', applyHomePreview);
            el.addEventListener('change', applyHomePreview);
        });
        var updateBtn = $('update-preview-btn');
        if (updateBtn) updateBtn.addEventListener('click', applyHomePreview);
        var restoreHome = document.getElementById('restore-home-content-form');
        if (restoreHome) {
            restoreHome.addEventListener('submit', function (event) {
                var ok = window.confirm(
                    '¿Restaurar el contenido predeterminado de la portada (Etapa 2)? Se conservarán identidad visual, logo, colores, WhatsApp, Instagram, pagos, productos y pedidos.'
                );
                if (!ok) event.preventDefault();
            });
        }
        applyHomePreview();
    }

    document.addEventListener('DOMContentLoaded', bind);
})();
