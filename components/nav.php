<?php
if (!isset($categories)) {
    $categories = get_categories();
}
if (!isset($storeSettings)) {
    $storeSettings = get_store_settings();
}
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/home_content.php';
require_once __DIR__ . '/../includes/public_nav.php';
if (!isset($themeSettings)) {
    $themeSettings = resolve_theme_settings($storeSettings);
}
if (!isset($homeContent)) {
    $homeContent = resolve_home_content_settings($storeSettings);
}

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$resolvedCategoryId = null;
if ($currentScript === 'category.php' && isset($category_id) && is_numeric($category_id) && (int) $category_id > 0) {
    $resolvedCategoryId = (int) $category_id;
}
$activeCategoryId = public_nav_active_category_id($currentScript, $_GET, $resolvedCategoryId);
if (!isset($navSubcategoriesByCategory) || !is_array($navSubcategoriesByCategory)) {
    $navSubcategoriesByCategory = public_nav_subcategories_by_category();
}
$navItems = public_nav_items($categories, $currentScript, $activeCategoryId, $navSubcategoriesByCategory);

$brandLogoPath = is_safe_brand_logo_path($themeSettings['brand_logo'] ?? '')
    ? $themeSettings['brand_logo']
    : THEME_OFFICIAL_LOGO;
$navClass = 'navbar navbar-expand-lg site-navbar sticky-top'
    . (($themeSettings['nav_style'] ?? 'white') === 'navy' ? ' site-navbar-navy' : '');
require __DIR__ . '/announcement.php';
?>
<nav class="<?= htmlspecialchars($navClass) ?>" aria-label="Navegación principal" data-cyberleo-nav="public">
    <div class="container">
        <a class="navbar-brand site-navbar-brand" href="index.php" title="<?= htmlspecialchars($storeSettings['store_name']) ?>">
            <img
                src="<?= htmlspecialchars($brandLogoPath, ENT_QUOTES, 'UTF-8') ?>"
                alt="CyberLeo"
                class="brand-logo"
                width="220"
                height="62"
                decoding="async"
            >
        </a>
        <button
            class="navbar-toggler site-navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNav"
            aria-controls="mainNav"
            aria-expanded="false"
            aria-label="Abrir menú"
        >
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1 site-navbar-links">
                <?php foreach ($navItems as $item): ?>
                    <?php if (($item['type'] ?? '') === 'cart'): ?>
                        <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                            <a
                                class="nav-cart-btn site-nav-cart<?= !empty($item['current']) ? ' active' : '' ?>"
                                href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= !empty($item['current']) ? ' aria-current="page"' : '' ?>
                            >
                                <i class="bi bi-cart3" aria-hidden="true"></i>
                                <span><?= htmlspecialchars((string) $item['label']) ?></span>
                                <span class="cart-count" aria-label="Productos en el carrito">0</span>
                            </a>
                        </li>
                    <?php elseif (($item['type'] ?? '') === 'products_menu'): ?>
                        <li class="nav-item dropdown site-nav-products">
                            <a
                                class="nav-link cyberleo-nav-link dropdown-toggle<?= !empty($item['current']) ? ' active' : '' ?>"
                                href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                id="navProductsDropdown"
                                role="button"
                                data-bs-toggle="dropdown"
                                data-bs-auto-close="outside"
                                aria-expanded="false"
                                aria-haspopup="true"
                            ><?= htmlspecialchars((string) $item['label']) ?></a>
                            <div class="dropdown-menu site-nav-products-menu" aria-labelledby="navProductsDropdown">
                                <div class="site-nav-products-grid">
                                    <?php foreach ($item['children'] as $category): ?>
                                        <div class="site-nav-products-group">
                                            <a
                                                class="site-nav-products-heading<?= !empty($category['current']) ? ' active' : '' ?>"
                                                href="<?= htmlspecialchars((string) $category['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                <?= !empty($category['current']) ? ' aria-current="page"' : '' ?>
                                            >
                                                <i class="<?= htmlspecialchars((string) ($category['icon'] ?? 'bi bi-cpu'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                                <span><?= htmlspecialchars((string) $category['label']) ?></span>
                                            </a>
                                            <ul class="site-nav-products-subs">
                                                <?php foreach ($category['children'] as $sub): ?>
                                                    <li>
                                                        <a
                                                            class="dropdown-item site-nav-products-sub"
                                                            href="<?= htmlspecialchars((string) $sub['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                        ><?= htmlspecialchars((string) $sub['label']) ?></a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a
                                class="nav-link cyberleo-nav-link<?= !empty($item['current']) ? ' active' : '' ?>"
                                href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= !empty($item['current']) ? ' aria-current="page"' : '' ?>
                            ><?= htmlspecialchars((string) $item['label']) ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>
