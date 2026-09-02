(function () {
    'use strict';

    var HEX_RE = /^#?[0-9A-Fa-f]{6}$/;
    var boot = window.THEME_PREVIEW_BOOT || {};
    var fontStacks = {
        system: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        inter: 'Inter, system-ui, sans-serif',
        montserrat: 'Montserrat, Arial, sans-serif',
        poppins: 'Poppins, Arial, sans-serif'
    };
    var radiusMap = {
        button: { low: '6px', medium: '8px', high: '16px' },
        card: { low: '8px', medium: '12px', high: '20px' }
    };

    function $(id) {
        return document.getElementById(id);
    }

    function normalizeHex(value) {
        if (typeof value !== 'string') return null;
        var v = value.trim();
        if (!HEX_RE.test(v)) return null;
        if (v.charAt(0) !== '#') v = '#' + v;
        return v.toLowerCase();
    }

    function readFormTheme() {
        var theme = Object.assign({}, boot.theme || {});
        ['brand_primary_color', 'brand_secondary_color', 'brand_navy_color', 'brand_background_color', 'brand_text_color'].forEach(function (key) {
            var input = $(key + '_hex');
            if (!input) return;
            var hex = normalizeHex(input.value);
            if (hex) theme[key] = hex;
        });
        ['brand_font', 'nav_style', 'button_radius', 'card_radius', 'hero_height', 'hero_alignment', 'hero_overlay'].forEach(function (key) {
            var el = $(key);
            if (el && el.value) theme[key] = el.value;
        });
        theme.hero_button_text = ($('hero_button_text') && $('hero_button_text').value) || theme.hero_button_text || '';
        theme.hero_button_url = ($('hero_button_url') && $('hero_button_url').value) || theme.hero_button_url || '';
        return theme;
    }

    function applyPreview() {
        var theme = readFormTheme();
        var title = ($('hero_title') && $('hero_title').value) || (boot.hero_title || '');
        var subtitle = ($('hero_subtitle') && $('hero_subtitle').value) || (boot.hero_subtitle || '');
        var logo = (boot.logo || 'assets/images/brand/cyberleo-logo.png');

        var preview = $('theme-preview');
        var nav = $('preview-nav');
        var hero = $('preview-hero');
        var card = $('preview-card');
        var btn = $('preview-hero-button');
        var cardBtn = $('preview-card-button');
        var logoImg = $('preview-logo');
        if (!preview || !nav || !hero) return;

        preview.style.fontFamily = fontStacks[theme.brand_font] || fontStacks.system;
        preview.style.backgroundColor = theme.brand_background_color;
        preview.style.color = theme.brand_text_color;

        if (theme.nav_style === 'navy') {
            nav.style.backgroundColor = theme.brand_navy_color;
            nav.style.color = '#ffffff';
        } else {
            nav.style.backgroundColor = '#ffffff';
            nav.style.color = theme.brand_navy_color;
        }

        hero.style.background = 'linear-gradient(135deg, ' + theme.brand_navy_color + ', ' + theme.brand_primary_color + ')';
        hero.style.textAlign = theme.hero_alignment === 'left' ? 'left' : 'center';

        var titleEl = $('preview-hero-title');
        var subEl = $('preview-hero-subtitle');
        if (titleEl) titleEl.textContent = title;
        if (subEl) subEl.textContent = subtitle;
        if (btn) {
            btn.textContent = theme.hero_button_text || 'Explorar catálogo';
            btn.style.backgroundColor = '#ffffff';
            btn.style.color = theme.brand_navy_color;
            btn.style.borderRadius = (radiusMap.button[theme.button_radius] || '8px');
        }
        if (card) {
            card.style.borderRadius = (radiusMap.card[theme.card_radius] || '12px');
            card.style.borderColor = theme.brand_primary_color;
        }
        if (cardBtn) {
            cardBtn.style.backgroundColor = theme.brand_primary_color;
            cardBtn.style.color = '#ffffff';
            cardBtn.style.borderRadius = (radiusMap.button[theme.button_radius] || '8px');
        }
        var price = $('preview-card-price');
        if (price) price.style.color = theme.brand_primary_color;
        if (logoImg) logoImg.setAttribute('src', logo);
    }

    function bindColorSync() {
        document.querySelectorAll('.theme-color-picker').forEach(function (picker) {
            picker.addEventListener('input', function () {
                var targetId = picker.getAttribute('data-hex-target');
                var hexInput = targetId ? $(targetId) : null;
                var hex = normalizeHex(picker.value);
                if (hexInput && hex) hexInput.value = hex;
                applyPreview();
            });
        });
        document.querySelectorAll('.theme-color-hex').forEach(function (input) {
            input.addEventListener('change', function () {
                var hex = normalizeHex(input.value);
                if (!hex) return;
                input.value = hex;
                var picker = document.querySelector('.theme-color-picker[data-hex-target="' + input.id + '"]');
                if (picker) picker.value = hex;
                applyPreview();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindColorSync();
        applyPreview();
        var updateBtn = $('update-preview-btn');
        if (updateBtn) updateBtn.addEventListener('click', applyPreview);
        ['hero_title', 'hero_subtitle', 'hero_button_text', 'brand_font', 'nav_style', 'button_radius', 'card_radius', 'hero_alignment'].forEach(function (id) {
            var el = $(id);
            if (el) el.addEventListener('change', applyPreview);
            if (el) el.addEventListener('input', applyPreview);
        });
        var restoreForm = document.getElementById('restore-cyberleo-form');
        if (restoreForm) {
            restoreForm.addEventListener('submit', function (event) {
                var ok = window.confirm('¿Restaurar la identidad visual CyberLeo? No se modificarán WhatsApp, Instagram, pagos, productos ni pedidos.');
                if (!ok) event.preventDefault();
            });
        }
    });
})();
