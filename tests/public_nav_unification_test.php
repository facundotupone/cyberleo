<?php
declare(strict_types=1);

/**
 * Regression: public/admin navigation unification and shared allowlists.
 */

require_once dirname(__DIR__) . '/includes/public_nav.php';
require_once dirname(__DIR__) . '/includes/admin_nav.php';

$passed = 0;
function nuk(bool $v, string $id, string $text): void
{
    global $passed;
    if (!$v) {
        throw new RuntimeException("$id failed: $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

$root = dirname(__DIR__);

try {
    $publicPages = ['index.php', 'category.php', 'cart.php'];
    foreach ($publicPages as $page) {
        $src = file_get_contents($root . '/' . $page);
        nuk($src !== false, 'NAV-01a', "lee $page");
        nuk(
            str_contains((string) $src, "require_once 'components/nav.php'")
            || str_contains((string) $src, 'require_once "components/nav.php"')
            || preg_match("/require(?:_once)?\\s+['\"]components\\/nav\\.php['\"]/", (string) $src) === 1,
            'NAV-01',
            "$page usa components/nav.php"
        );
        nuk(!preg_match('/<nav[^>]*class="[^"]*navbar(?![^"]*site-navbar)/', (string) $src), 'NAV-02', "$page no define navbar inline");
        nuk(
            str_contains((string) $src, "require_once 'components/footer.php'")
            || str_contains((string) $src, 'components/footer.php'),
            'NAV-03',
            "$page incluye footer compartido"
        );
    }

    $navComponent = file_get_contents($root . '/components/nav.php');
    nuk(is_string($navComponent) && str_contains($navComponent, 'data-cyberleo-nav="public"'), 'NAV-04', 'nav público canónico marcado');
    nuk(str_contains((string) $navComponent, 'public_nav_items'), 'NAV-05', 'nav usa allowlist pública');
    nuk(str_contains((string) $navComponent, 'cyberleo-nav-link'), 'NAV-06', 'nav usa clase específica CyberLeo');

    $footer = file_get_contents($root . '/components/footer.php');
    nuk(is_string($footer) && str_contains($footer, 'public_nav_items'), 'NAV-07', 'footer reutiliza allowlist pública');
    nuk(str_contains((string) $footer, 'site-footer-grid'), 'NAV-08', 'footer usa layout de columnas');

    $items = public_nav_items(
        [['id' => 3, 'name' => 'Notebooks'], ['id' => 0, 'name' => 'Ignorar'], ['id' => 5, 'name' => '']],
        'category.php',
        3
    );
    nuk(count($items) === 3, 'NAV-09', 'allowlist filtra categorías inválidas');
    nuk($items[0]['id'] === 'home' && $items[0]['current'] === false, 'NAV-10', 'inicio no activo en categoría');
    nuk($items[1]['id'] === 'category-3' && $items[1]['current'] === true, 'NAV-11', 'categoría activa marcada');
    nuk($items[2]['type'] === 'cart' && $items[2]['href'] === 'cart.php', 'NAV-12', 'carrito al final');

    // Resolved category id (product_id flows) must drive aria-current.
    $resolved = public_nav_active_category_id('category.php', ['id' => '1', 'product_id' => '99'], 7);
    nuk($resolved === 7, 'NAV-12b', 'categoría resuelta prioriza id efectivo de página');
    $fromQuery = public_nav_active_category_id('category.php', ['id' => '4']);
    nuk($fromQuery === 4, 'NAV-12c', 'sin resolución usa id de query');
    $invalid = public_nav_active_category_id('category.php', ['id' => '0']);
    nuk($invalid === null, 'NAV-12d', 'id inválido no activa categoría');
    $notCategory = public_nav_active_category_id('index.php', ['id' => '4'], 4);
    nuk($notCategory === null, 'NAV-12e', 'fuera de category.php no hay categoría activa');
    $mismatchItems = public_nav_items([['id' => 1, 'name' => 'A'], ['id' => 7, 'name' => 'B']], 'category.php', 7);
    nuk($mismatchItems[1]['current'] === false && $mismatchItems[2]['current'] === true, 'NAV-12f', 'solo la categoría resuelta queda activa');

    $adminPages = [
        'admin_products.php',
        'admin_categories.php',
        'admin_orders.php',
        'admin_settings.php',
        'admin_system.php',
    ];
    foreach ($adminPages as $page) {
        $src = file_get_contents($root . '/' . $page);
        nuk(
            is_string($src) && str_contains($src, "components/admin_nav.php"),
            'NAV-13',
            "$page usa admin_nav canónico"
        );
        nuk(!preg_match('/<nav class="navbar navbar-dark"[^>]*>/', (string) $src), 'NAV-14', "$page sin navbar admin inline legacy");
    }

    $adminNav = file_get_contents($root . '/components/admin_nav.php');
    nuk(is_string($adminNav) && str_contains($adminNav, 'admin-navbar'), 'NAV-15', 'admin nav con clase específica');
    nuk(count(admin_nav_items()) === 5, 'NAV-16', 'allowlist admin con 5 enlaces');
    nuk(admin_nav_current_id('admin_settings.php') === 'settings', 'NAV-17', 'current id admin settings');

    $benefits = file_get_contents($root . '/components/benefits.php');
    nuk(is_string($benefits) && str_contains($benefits, 'benefits_section_title'), 'NAV-18', 'beneficios usan título administrable');
    nuk(str_contains((string) $benefits, 'benefits-surface'), 'NAV-19', 'beneficios con superficie visual');

    echo "public_nav_unification_test: $passed assertions OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
exit(0);
