(function () {
    'use strict';

    function isDesktop() {
        return window.matchMedia('(min-width: 992px)').matches;
    }

    function closestNav(el) {
        return el && el.closest ? el.closest('[data-cyberleo-nav="public"]') : null;
    }

    function setExpanded(btn, expanded) {
        if (!btn) return;
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (btn.classList.contains('site-navbar-toggler')) {
            btn.setAttribute('aria-label', expanded ? 'Cerrar menú' : 'Abrir menú');
        }
    }

    function closeMobile(nav) {
        var collapse = nav.querySelector('#mainNav');
        var toggler = nav.querySelector('.site-navbar-toggler');
        if (collapse) {
            collapse.classList.remove('show');
        }
        setExpanded(toggler, false);
        var productsToggle = nav.querySelector('.site-nav-products-toggle');
        if (productsToggle) {
            productsToggle.classList.remove('is-open');
            setExpanded(productsToggle, false);
        }
        var accordion = nav.querySelector('#productsAccordion');
        if (accordion) {
            accordion.hidden = true;
        }
    }

    function closeMega(root) {
        var mega = root.querySelector('#productsMega');
        var toggle = root.querySelector('.site-nav-products-toggle');
        if (mega) {
            mega.hidden = true;
        }
        setExpanded(toggle, false);
        if (toggle) {
            toggle.classList.remove('is-open');
        }
    }

    function openMega(root) {
        var mega = root.querySelector('#productsMega');
        var toggle = root.querySelector('.site-nav-products-toggle');
        if (!mega || !toggle) return;
        mega.hidden = false;
        setExpanded(toggle, true);
        toggle.classList.add('is-open');
        var active = mega.querySelector('.site-mega-cat-btn.is-active')
            || mega.querySelector('.site-mega-cat-btn');
        if (active) {
            showPanel(root, active.getAttribute('data-mega-cat'));
        }
    }

    function showPanel(root, categoryId) {
        if (!categoryId) return;
        var mega = root.querySelector('#productsMega');
        if (!mega) return;
        mega.querySelectorAll('.site-mega-cat-btn').forEach(function (btn) {
            var on = btn.getAttribute('data-mega-cat') === String(categoryId);
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-expanded', on ? 'true' : 'false');
        });
        mega.querySelectorAll('[data-mega-panel]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-mega-panel') !== String(categoryId);
        });
    }

    function toggleProductsMobile(root) {
        var toggle = root.querySelector('.site-nav-products-toggle');
        var accordion = root.querySelector('#productsAccordion');
        if (!toggle || !accordion) return;
        var open = toggle.getAttribute('aria-expanded') !== 'true';
        setExpanded(toggle, open);
        toggle.classList.toggle('is-open', open);
        accordion.hidden = !open;
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        var nav = closestNav(target) || document.querySelector('[data-cyberleo-nav="public"]');
        if (!nav) return;

        var productsToggle = target.closest('.site-nav-products-toggle');
        if (productsToggle && nav.contains(productsToggle)) {
            event.preventDefault();
            if (isDesktop()) {
                if (productsToggle.getAttribute('aria-expanded') === 'true') {
                    closeMega(nav);
                } else {
                    openMega(nav);
                }
            } else {
                toggleProductsMobile(nav);
            }
            return;
        }

        var catBtn = target.closest('[data-mega-cat]');
        if (catBtn && nav.contains(catBtn) && isDesktop()) {
            event.preventDefault();
            showPanel(nav, catBtn.getAttribute('data-mega-cat'));
            return;
        }

        var accToggle = target.closest('.site-acc-toggle');
        if (accToggle && nav.contains(accToggle)) {
            event.preventDefault();
            var panelId = accToggle.getAttribute('aria-controls');
            var panel = panelId ? document.getElementById(panelId) : null;
            if (!panel) return;
            var open = accToggle.getAttribute('aria-expanded') !== 'true';
            accToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
            return;
        }

        if (isDesktop()) {
            var mega = nav.querySelector('#productsMega');
            var wrap = nav.querySelector('[data-public-nav-products]');
            if (mega && !mega.hidden && wrap && !wrap.contains(target)) {
                closeMega(nav);
            }
        }

        var link = target.closest('a');
        if (link && nav.contains(link) && !isDesktop()) {
            closeMobile(nav);
        }
    });

    document.addEventListener('keydown', function (event) {
        var nav = document.querySelector('[data-cyberleo-nav="public"]');
        if (!nav) return;
        var toggle = nav.querySelector('.site-nav-products-toggle');
        var mega = nav.querySelector('#productsMega');

        if (event.key === 'Escape') {
            if (isDesktop()) {
                closeMega(nav);
            } else {
                closeMobile(nav);
            }
            if (toggle) toggle.focus();
            return;
        }

        if (!toggle) return;
        var onToggle = document.activeElement === toggle;
        if (!onToggle) return;

        if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
            event.preventDefault();
            if (isDesktop()) {
                openMega(nav);
                var first = mega ? mega.querySelector('.site-mega-cat-btn, .site-mega-cat-link') : null;
                if (first) first.focus();
            } else {
                toggleProductsMobile(nav);
            }
        }
    });

    document.addEventListener('focusin', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        var nav = closestNav(target);
        if (!nav || !isDesktop()) return;
        var catBtn = target.closest('[data-mega-cat]');
        if (catBtn && nav.contains(catBtn)) {
            showPanel(nav, catBtn.getAttribute('data-mega-cat'));
        }
    });

    window.addEventListener('resize', function () {
        var nav = document.querySelector('[data-cyberleo-nav="public"]');
        if (!nav) return;
        if (isDesktop()) {
            var accordion = nav.querySelector('#productsAccordion');
            if (accordion) accordion.hidden = true;
        } else {
            closeMega(nav);
        }
    });
})();
