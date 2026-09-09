/**
 * CyberLeo public products mega-menu / mobile accordion.
 * No innerHTML, no inline handlers, no dynamic sinks.
 */
(function () {
    'use strict';

    var MQ = '(min-width: 992px)';

    function isDesktop() {
        return window.matchMedia(MQ).matches;
    }

    function qs(root, sel) {
        return root.querySelector(sel);
    }

    function qsa(root, sel) {
        return Array.prototype.slice.call(root.querySelectorAll(sel));
    }

    function setExpanded(el, open) {
        el.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function initProductsMenu(nav) {
        var toggle = qs(nav, '[data-cyberleo-products-toggle]');
        var panel = qs(nav, '#navProductsPanel');
        if (!toggle || !panel) {
            return;
        }

        var rootLi = toggle.closest('.site-nav-products');
        var catButtons = qsa(nav, '[data-cyberleo-products-cat]');
        var catPanels = qsa(nav, '[data-cyberleo-products-panel]');
        var accButtons = qsa(nav, '[data-cyberleo-products-acc]');
        var collapse = qs(nav, '#mainNav');
        var hamburger = qs(nav, '.navbar-toggler');

        function openMenu() {
            panel.hidden = false;
            panel.classList.add('show');
            if (rootLi) {
                rootLi.classList.add('show');
            }
            setExpanded(toggle, true);
        }

        function closeMenu(returnFocus) {
            if (panel.hidden && toggle.getAttribute('aria-expanded') !== 'true') {
                return;
            }
            panel.hidden = true;
            panel.classList.remove('show');
            if (rootLi) {
                rootLi.classList.remove('show');
            }
            setExpanded(toggle, false);
            if (returnFocus) {
                toggle.focus();
            }
        }

        function selectCategory(categoryId, focusTab) {
            catButtons.forEach(function (btn) {
                var id = btn.getAttribute('data-cyberleo-products-cat');
                var on = String(id) === String(categoryId);
                btn.classList.toggle('is-selected', on);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
                btn.tabIndex = on ? 0 : -1;
                if (on && focusTab) {
                    btn.focus();
                }
            });
            catPanels.forEach(function (p) {
                var id = p.getAttribute('data-cyberleo-products-panel');
                var on = String(id) === String(categoryId);
                p.classList.toggle('is-active', on);
                p.hidden = !on;
            });
        }

        function openOnlyAccordion(categoryId) {
            accButtons.forEach(function (btn) {
                var id = btn.getAttribute('data-cyberleo-products-acc');
                var on = String(id) === String(categoryId);
                var panelEl = qs(nav, '[data-cyberleo-products-acc-panel="' + id + '"]');
                btn.classList.toggle('is-open', on);
                setExpanded(btn, on);
                if (panelEl) {
                    panelEl.hidden = !on;
                }
            });
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (toggle.getAttribute('aria-expanded') === 'true') {
                closeMenu(false);
            } else {
                openMenu();
            }
        });

        toggle.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (toggle.getAttribute('aria-expanded') === 'true') {
                    closeMenu(false);
                } else {
                    openMenu();
                }
            } else if (event.key === 'Escape') {
                closeMenu(true);
            } else if (event.key === 'ArrowDown' && toggle.getAttribute('aria-expanded') !== 'true') {
                event.preventDefault();
                openMenu();
                if (isDesktop() && catButtons.length) {
                    var selected = qs(nav, '[data-cyberleo-products-cat][aria-selected="true"]') || catButtons[0];
                    selected.focus();
                }
            }
        });

        catButtons.forEach(function (btn, index) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                selectCategory(btn.getAttribute('data-cyberleo-products-cat'), false);
            });
            btn.addEventListener('keydown', function (event) {
                var key = event.key;
                if (key === 'ArrowDown' || key === 'ArrowRight') {
                    event.preventDefault();
                    var next = catButtons[(index + 1) % catButtons.length];
                    selectCategory(next.getAttribute('data-cyberleo-products-cat'), true);
                } else if (key === 'ArrowUp' || key === 'ArrowLeft') {
                    event.preventDefault();
                    var prev = catButtons[(index - 1 + catButtons.length) % catButtons.length];
                    selectCategory(prev.getAttribute('data-cyberleo-products-cat'), true);
                } else if (key === 'Enter' || key === ' ') {
                    event.preventDefault();
                    selectCategory(btn.getAttribute('data-cyberleo-products-cat'), false);
                } else if (key === 'Escape') {
                    event.preventDefault();
                    closeMenu(true);
                }
            });
            btn.addEventListener('focus', function () {
                if (isDesktop()) {
                    selectCategory(btn.getAttribute('data-cyberleo-products-cat'), false);
                }
            });
        });

        accButtons.forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var id = btn.getAttribute('data-cyberleo-products-acc');
                var open = btn.getAttribute('aria-expanded') === 'true';
                if (open) {
                    btn.classList.remove('is-open');
                    setExpanded(btn, false);
                    var panelEl = qs(nav, '[data-cyberleo-products-acc-panel="' + id + '"]');
                    if (panelEl) {
                        panelEl.hidden = true;
                    }
                } else {
                    openOnlyAccordion(id);
                }
            });
            btn.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeMenu(true);
                }
            });
        });

        // Final links close the products menu and (on mobile) the hamburger collapse.
        qsa(panel, '[data-cyberleo-nav-final]').forEach(function (link) {
            link.addEventListener('click', function () {
                closeMenu(false);
                if (!isDesktop() && collapse && collapse.classList.contains('show') && hamburger) {
                    hamburger.click();
                }
            });
        });

        document.addEventListener('click', function (event) {
            if (toggle.getAttribute('aria-expanded') !== 'true') {
                return;
            }
            if (rootLi && rootLi.contains(event.target)) {
                return;
            }
            closeMenu(false);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                closeMenu(true);
            }
        });

        window.addEventListener('resize', function () {
            // Keep markup modes in sync; do not force-close on tiny resizes while open.
            if (!isDesktop()) {
                // Desktop tabs are hidden via CSS; leave state as-is.
            }
        });
    }

    function boot() {
        var nav = document.querySelector('nav.site-navbar[data-cyberleo-nav="public"]');
        if (!nav || nav.getAttribute('data-cyberleo-nav-ready') === '1') {
            return;
        }
        nav.setAttribute('data-cyberleo-nav-ready', '1');
        initProductsMenu(nav);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
