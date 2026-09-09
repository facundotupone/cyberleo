<?php
/**
 * Storefront home — recovery-hardened.
 * Must return HTTP 200 even when theme helpers, featured queries, or
 * partial deploys leave older includes/functions.php without try/catch.
 */

if (function_exists('opcache_invalidate')) {
    foreach (array(
        __DIR__ . '/includes/asset_version.php',
        __DIR__ . '/includes/asset_safe_url.php',
        __DIR__ . '/includes/functions.php',
        __DIR__ . '/includes/theme.php',
        __DIR__ . '/components/head.php',
        __DIR__ . '/components/nav.php',
        __DIR__ . '/components/footer.php',
        __FILE__,
    ) as $cyberleoOpcacheFile) {
        if (is_file($cyberleoOpcacheFile)) {
            @opcache_invalidate($cyberleoOpcacheFile, true);
        }
    }
}

$cyberleoIndexFailed = false;
$cyberleoSafeErrorText = static function ($raw) {
    $raw = preg_replace('#(?:[A-Za-z]:)?[\\\\/][^\s:]+#', '[path]', (string) $raw);
    $raw = preg_replace('/(?i)\b(password|passwd|pwd|secret|token|authorization)=([^\s&]+)/', '$1=[redacted]', (string) $raw);
    $raw = preg_replace('/\s+/', ' ', (string) $raw);
    $raw = trim((string) $raw);
    return function_exists('mb_substr') ? mb_substr($raw, 0, 240) : substr($raw, 0, 240);
};

$cyberleoRenderDegraded = static function ($detail = '') use ($cyberleoSafeErrorText) {
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $store = defined('STORE_NAME') ? STORE_NAME : 'CyberLeo';
    $safeDetail = $detail !== '' ? $cyberleoSafeErrorText($detail) : '';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($store, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"></head><body class="p-4">';
    echo '<div class="container" style="max-width:720px">';
    echo '<h1 class="h3">' . htmlspecialchars($store, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>La portada está en modo seguro. El catálogo sigue disponible.</p>';
    echo '<p><a class="btn btn-primary me-2" href="category.php">Ver productos</a>';
    echo '<a class="btn btn-outline-secondary me-2" href="cart.php">Carrito</a>';
    echo '<a class="btn btn-outline-secondary" href="admin_login.php">Admin</a></p>';
    if ($safeDetail !== '') {
        echo '<p class="small text-muted"><strong>Detalle:</strong> ' . htmlspecialchars($safeDetail, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '<p class="small"><a href="diag_recovery.php?opcache=reset">Diagnóstico</a></p>';
    echo '</div></body></html>';
    exit;
};

$cyberleoIndexFail = static function ($detail = '') use (&$cyberleoIndexFailed, $cyberleoSafeErrorText) {
    if ($cyberleoIndexFailed || headers_sent()) {
        return;
    }
    $cyberleoIndexFailed = true;
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error</title></head><body>';
    echo '<h1>No se pudo cargar la portada</h1>';
    echo '<p>Falta configuración o base de datos. Revisá <code>config.local.php</code> y el diagnóstico.</p>';
    if ($detail !== '') {
        echo '<p><strong>Detalle:</strong> ' . htmlspecialchars($cyberleoSafeErrorText($detail), ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '<p><a href="diag_recovery.php?opcache=reset">Diagnóstico</a> · <a href="emergency_admin_login.php">Login emergencia</a></p>';
    echo '</body></html>';
    exit;
};

set_exception_handler(static function (Throwable $e) use ($cyberleoRenderDegraded, &$cyberleoIndexFailed) {
    error_log('cyberleo index uncaught: ' . $e->getMessage());
    if ($cyberleoIndexFailed) {
        return;
    }
    $cyberleoRenderDegraded(get_class($e) . ': ' . $e->getMessage());
});

register_shutdown_function(static function () use ($cyberleoRenderDegraded, &$cyberleoIndexFailed) {
    if ($cyberleoIndexFailed || headers_sent()) {
        return;
    }
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    error_log('cyberleo index shutdown: ' . $err['message']);
    $cyberleoRenderDegraded($err['message']);
});

try {
    // Load db.php only — it pulls config.php via __DIR__.
    // Do not require config.php separately first: absolute + relative paths can
    // both execute on Hostinger and redeclare config_value().
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/functions.php';
} catch (Throwable $e) {
    $cyberleoIndexFail(get_class($e) . ': ' . $e->getMessage());
}

$categories = array();
$featured_products = array();
$images_by_product = array();
$all_subcategories = array();

try {
    if (function_exists('get_categories')) {
        $categories = get_categories();
    }
} catch (Throwable $e) {
    error_log('cyberleo index categories: ' . $e->getMessage());
    $categories = array();
}
if (!is_array($categories)) {
    $categories = array();
}

try {
    if (function_exists('get_featured_products')) {
        $featured_products = get_featured_products();
    }
} catch (Throwable $e) {
    // Old functions.php without try/catch used to 500 the whole page here.
    error_log('cyberleo index featured: ' . $e->getMessage());
    $featured_products = array();
}
if (!is_array($featured_products)) {
    $featured_products = array();
}

$featured_ids = array_column($featured_products, 'id');
$featured_ids_str = implode(',', array_map('intval', $featured_ids));

if ($featured_ids_str !== '' && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->query("SELECT product_id, image_path FROM product_images WHERE product_id IN ($featured_ids_str) ORDER BY is_main DESC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $images_by_product[$row['product_id']][] = $row['image_path'];
        }
    } catch (Throwable $e) {
        error_log('cyberleo index product_images: ' . $e->getMessage());
        $images_by_product = array();
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->query('SELECT * FROM subcategories ORDER BY category_id, name');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $all_subcategories[$row['category_id']][] = $row;
        }
    } catch (Throwable $e) {
        error_log('cyberleo index subcategories: ' . $e->getMessage());
        $all_subcategories = array();
    }
}

$storeSettings = array(
    'store_name' => defined('STORE_NAME') ? STORE_NAME : 'CyberLeo',
    'whatsapp_number' => defined('WHATSAPP_NUMBER') ? WHATSAPP_NUMBER : '',
    'instagram_url' => defined('STORE_INSTAGRAM') ? STORE_INSTAGRAM : '',
    'hero_title' => 'Tecnología para trabajar, estudiar y disfrutar',
    'hero_subtitle' => 'Encontrá notebooks, componentes y periféricos con stock actualizado.',
    'hero_background' => '',
    'body_background' => '',
);
$themeSettings = array(
    'hero_height' => 'normal',
    'hero_alignment' => 'center',
    'hero_overlay' => 'medium',
    'hero_button_text' => 'Ver productos',
    'hero_button_url' => '#productos-destacados',
    'show_search' => '1',
    'show_featured_products' => '1',
    'brand_logo' => 'assets/images/brand/cyberleo-logo.png',
);
$homeContent = array('home_section_order' => 'featured,categories');
$catalogDisplay = array();
$heroButtonUrl = '#productos-destacados';
$heroClasses = array('hero-section', 'hero-height-normal', 'hero-align-center');
$styleCss = 'assets/css/style.css';
$backgroundsCss = 'assets/css/backgrounds.css';
$degradedNotice = '';

try {
    if (function_exists('get_store_settings')) {
        $storeSettings = array_merge($storeSettings, get_store_settings());
    }
    if (is_file(__DIR__ . '/includes/theme.php')) {
        require_once __DIR__ . '/includes/theme.php';
    }
    if (is_file(__DIR__ . '/includes/home_content.php')) {
        require_once __DIR__ . '/includes/home_content.php';
    }
    if (is_file(__DIR__ . '/includes/catalog_display.php')) {
        require_once __DIR__ . '/includes/catalog_display.php';
    }
    if (function_exists('resolve_theme_settings')) {
        $themeSettings = array_merge($themeSettings, resolve_theme_settings($storeSettings));
    }
    if (function_exists('resolve_home_content_settings')) {
        $homeContent = array_merge($homeContent, resolve_home_content_settings($storeSettings));
    }
    if (function_exists('resolve_catalog_display_settings')) {
        $catalogDisplay = resolve_catalog_display_settings($storeSettings);
    }
    $heroClasses = array('hero-section');
    $heroClasses[] = 'hero-height-' . ($themeSettings['hero_height'] ?? 'normal');
    $heroClasses[] = 'hero-align-' . ($themeSettings['hero_alignment'] ?? 'center');
    if (!empty($storeSettings['hero_background']) && function_exists('is_safe_settings_image_path') && is_safe_settings_image_path($storeSettings['hero_background'])) {
        $heroClasses[] = 'hero-has-image';
        $heroClasses[] = 'hero-overlay-' . ($themeSettings['hero_overlay'] ?? 'medium');
    }
    $heroButtonRaw = (string) ($themeSettings['hero_button_url'] ?? '');
    if (function_exists('is_safe_local_theme_url') && is_safe_local_theme_url($heroButtonRaw)) {
        $heroButtonUrl = $heroButtonRaw;
    }
    if (function_exists('cyberleo_safe_asset_url')) {
        $styleCss = cyberleo_safe_asset_url('assets/css/style.css');
        $backgroundsCss = cyberleo_safe_asset_url('assets/css/backgrounds.css');
    } elseif (function_exists('cyberleo_asset_url')) {
        try {
            $styleCss = cyberleo_asset_url('assets/css/style.css');
            $backgroundsCss = cyberleo_asset_url('assets/css/backgrounds.css');
        } catch (Throwable $e) {
            // keep unversioned
        }
    }
} catch (Throwable $e) {
    error_log('cyberleo index theme bootstrap: ' . $e->getMessage());
    $degradedNotice = 'Algunos módulos visuales no cargaron; mostrando portada reducida.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars((string) $storeSettings['store_name']) ?>, tecnología y productos informáticos.">
<meta name="cyberleo-release" content="refinamiento-hotfix-20260905">
<title><?= htmlspecialchars((string) $storeSettings['store_name']) ?> | Tienda informática</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= htmlspecialchars($styleCss, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($backgroundsCss, ENT_QUOTES, 'UTF-8') ?>">
<?php
try {
    if (function_exists('theme_css_custom_properties') && is_array($themeSettings)) {
        echo '<style>' . theme_css_custom_properties($themeSettings) . '</style>';
    }
} catch (Throwable $e) {
    // ignore theme CSS failures
}
?>
<style>
    .product-carousel { height: 100%; overflow: hidden; position: relative; }
    .product-carousel .carousel-inner, .product-carousel .carousel-item { height: 100%; }
    .product-carousel .carousel-item { text-align: center; }
    .product-carousel .carousel-item img { height: 100%; width: auto; max-width: 100%; object-fit: contain; }
    .carousel-control-prev, .carousel-control-next { width: 30px; background: rgba(0,0,0,0.2); border-radius: 50%; height: 30px; top: 50%; transform: translateY(-50%); }
    .single-product-image { height: 100%; object-fit: contain; width: 100%; }
</style>
</head>
<body>
<?php
try {
    if (is_file(__DIR__ . '/components/nav.php')) {
        require __DIR__ . '/components/nav.php';
    }
} catch (Throwable $e) {
    error_log('cyberleo index nav: ' . $e->getMessage());
    if (is_file(__DIR__ . '/components/nav_fallback.php')) {
        require __DIR__ . '/components/nav_fallback.php';
    }
}
?>
<main class="container mt-3">
    <?php if ($degradedNotice !== ''): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($degradedNotice, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <section class="<?= htmlspecialchars(implode(' ', $heroClasses)) ?>" aria-label="<?= htmlspecialchars((string) $storeSettings['store_name']) ?>">
        <div class="hero-content text-white<?= (($themeSettings['hero_alignment'] ?? 'center') === 'left') ? '' : ' text-center' ?>">
            <h1 class="text-white"><?= htmlspecialchars((string) ($storeSettings['hero_title'] ?? '')) ?></h1>
            <p class="hero-subtitle mb-0"><?= htmlspecialchars((string) ($storeSettings['hero_subtitle'] ?? '')) ?></p>
            <a href="<?= htmlspecialchars($heroButtonUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary hero-cta mt-3">
                <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> <?= htmlspecialchars((string) ($themeSettings['hero_button_text'] ?? 'Ver productos')) ?>
            </a>
        </div>
    </section>

    <?php if (($themeSettings['show_search'] ?? '1') === '1'): ?>
    <section class="row justify-content-center search-section" aria-label="Buscador de productos">
        <div class="col-md-7 col-lg-6 position-relative">
            <div class="input-group">
                <input type="search" id="searchProducts" class="form-control" placeholder="Buscar productos..." aria-label="Buscar productos">
                <button type="button" class="btn btn-search" aria-label="Buscar"><i class="bi bi-search" aria-hidden="true"></i></button>
            </div>
            <div id="searchResults" class="position-absolute bg-white shadow-sm rounded p-2" style="display: none; z-index: 1000; width: 100%;"></div>
        </div>
    </section>
    <?php endif; ?>

    <?php
    $homeSections = array('featured', 'categories');
    try {
        if (function_exists('home_content_ordered_sections')) {
            $homeSections = home_content_ordered_sections($homeContent);
        }
    } catch (Throwable $e) {
        error_log('cyberleo index sections: ' . $e->getMessage());
    }
    foreach ($homeSections as $sectionToken) {
        try {
            switch ($sectionToken) {
                case 'featured':
                    if (is_file(__DIR__ . '/components/home_featured.php')) {
                        require __DIR__ . '/components/home_featured.php';
                    }
                    break;
                case 'promo':
                    if (is_file(__DIR__ . '/components/promo_banner.php')) {
                        require __DIR__ . '/components/promo_banner.php';
                    }
                    break;
                case 'categories':
                    if (is_file(__DIR__ . '/components/home_categories.php')) {
                        require __DIR__ . '/components/home_categories.php';
                    }
                    break;
                case 'benefits':
                    if (is_file(__DIR__ . '/components/benefits.php')) {
                        require __DIR__ . '/components/benefits.php';
                    }
                    break;
            }
        } catch (Throwable $e) {
            error_log('cyberleo index section ' . $sectionToken . ': ' . $e->getMessage());
        }
    }
    ?>
</main>
<?php
try {
    if (is_file(__DIR__ . '/components/footer.php')) {
        require __DIR__ . '/components/footer.php';
    }
} catch (Throwable $e) {
    error_log('cyberleo index footer: ' . $e->getMessage());
    echo '<footer class="footer site-footer py-4"><div class="container"><small>&copy; '
        . date('Y') . ' ' . htmlspecialchars((string) $storeSettings['store_name'], ENT_QUOTES, 'UTF-8')
        . '</small></div></footer>';
}
$catalogJs = 'assets/js/catalog-cards.js';
if (function_exists('cyberleo_safe_asset_url')) {
    $catalogJs = cyberleo_safe_asset_url('assets/js/catalog-cards.js');
}
?>
<a href="cart.php" class="floating-cart"><i class="bi bi-cart" style="font-size: 24px;"></i><span class="cart-count">0</span></a>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= htmlspecialchars($catalogJs, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script>
(function initProductSearch() {
    const searchInput = document.getElementById('searchProducts');
    const resultsContainer = document.getElementById('searchResults');
    if (!searchInput || !resultsContainer) return;
    let searchTimeout;
    searchInput.addEventListener('input', function(e) {
        const searchTerm = e.target.value.trim();
        clearTimeout(searchTimeout);
        if (searchTerm.length < 2) { resultsContainer.style.display = 'none'; return; }
        searchTimeout = setTimeout(() => {
            fetch('search_products.php?q=' + encodeURIComponent(searchTerm))
                .then(r => r.json())
                .then(products => {
                    resultsContainer.replaceChildren();
                    if (!products.length) {
                        const empty = document.createElement('div');
                        empty.className = 'p-2';
                        empty.textContent = 'No se encontraron productos';
                        resultsContainer.appendChild(empty);
                        resultsContainer.style.display = 'block';
                        return;
                    }
                    products.forEach(product => {
                        const link = document.createElement('a');
                        link.href = 'category.php?id=' + encodeURIComponent(product.category_id) + '&sub=' + encodeURIComponent(product.subcategory_id || '');
                        link.className = 'search-result p-2 border-bottom text-decoration-none text-dark d-block';
                        const row = document.createElement('div'); row.className = 'd-flex align-items-center';
                        if (typeof product.image === 'string' && /^assets\/images\/[a-zA-Z0-9_./-]+$/.test(product.image)) {
                            const image = document.createElement('img');
                            image.src = product.image; image.alt = product.name || ''; image.style.cssText = 'width:50px;height:50px;object-fit:cover;margin-right:10px;';
                            row.appendChild(image);
                        }
                        const content = document.createElement('div');
                        const name = document.createElement('h6'); name.className = 'mb-0'; name.textContent = product.name || '';
                        const category = document.createElement('small'); category.className = 'text-muted'; category.textContent = product.category_name || '';
                        const prices = document.createElement('div'); prices.className = 'd-flex justify-content-between';
                        const price = document.createElement('span'); price.className = 'text-primary'; price.textContent = '$' + Number(product.price).toFixed(2);
                        const stock = document.createElement('small'); stock.className = Number(product.stock) > 0 ? 'text-success' : 'text-danger'; stock.textContent = Number(product.stock) > 0 ? 'Disponible' : 'Sin stock';
                        prices.append(price, stock); content.append(name, category, prices); row.appendChild(content); link.appendChild(row); resultsContainer.appendChild(link);
                    });
                    resultsContainer.style.display = 'block';
                });
        }, 300);
    });
    document.addEventListener('click', function(e) {
        if (!resultsContainer.contains(e.target) && e.target !== searchInput) resultsContainer.style.display = 'none';
    });
})();
</script>
</body>
</html>
