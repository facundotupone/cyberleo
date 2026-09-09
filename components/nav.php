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
$resolvedSubcategoryId = null;
if ($currentScript === 'category.php' && isset($category_id) && is_numeric($category_id) && (int) $category_id > 0) {
    $resolvedCategoryId = (int) $category_id;
}
if ($currentScript === 'category.php' && isset($subcategory_id) && is_numeric($subcategory_id) && (int) $subcategory_id > 0) {
    $resolvedSubcategoryId = (int) $subcategory_id;
}
$activeCategoryId = public_nav_active_category_id($currentScript, $_GET, $resolvedCategoryId);
$activeSubcategoryId = public_nav_active_subcategory_id($currentScript, $_GET, $resolvedSubcategoryId);
$navTaxonomy = public_nav_taxonomy($categories);
$navItems = public_nav_items($categories, $currentScript, $activeCategoryId, $activeSubcategoryId, $navTaxonomy);

$brandLogoPath = is_safe_brand_logo_path($themeSettings['brand_logo'] ?? '')
    ? $themeSettings['brand_logo']
    : THEME_OFFICIAL_LOGO;
$navClass = 'navbar navbar-expand-lg site-navbar sticky-top'
    . (($themeSettings['nav_style'] ?? 'white') === 'navy' ? ' site-navbar-navy' : '');
$publicNavJs = 'assets/js/public-nav.js';
if (function_exists('cyberleo_safe_asset_url')) {
    $publicNavJs = cyberleo_safe_asset_url('assets/js/public-nav.js');
}
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
            <span class="site-navbar-toggler-label">Menú</span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-lg-center site-navbar-links">
                <?php foreach ($navItems as $item): ?>
                    <?php if ($item['type'] === 'cart'): ?>
                        <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                            <a
                                class="nav-cart-btn site-nav-cart<?= $item['current'] ? ' active' : '' ?>"
                                href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= $item['current'] ? ' aria-current="page"' : '' ?>
                            >
                                <i class="bi bi-cart3" aria-hidden="true"></i>
                                <span><?= htmlspecialchars($item['label']) ?></span>
                                <span class="cart-count" aria-label="Productos en el carrito">0</span>
                            </a>
                        </li>
                    <?php elseif ($item['type'] === 'products'): ?>
                        <?php
                        $taxonomy = is_array($item['taxonomy'] ?? null) ? $item['taxonomy'] : [];
                        $panelCategoryId = (int) ($item['activeCategoryId'] ?? 0);
                        if ($panelCategoryId <= 0 && $taxonomy !== []) {
                            $panelCategoryId = (int) $taxonomy[0]['id'];
                        }
                        ?>
                        <li class="nav-item site-nav-products" data-public-nav-products>
                            <button
                                type="button"
                                class="nav-link cyberleo-nav-link site-nav-products-toggle<?= $item['current'] ? ' active' : '' ?>"
                                id="productsNavToggle"
                                aria-expanded="false"
                                aria-controls="productsMega"
                                aria-haspopup="true"
                                <?= $item['current'] ? ' aria-current="page"' : '' ?>
                            >
                                <span><?= htmlspecialchars($item['label']) ?></span>
                                <i class="bi bi-chevron-down site-nav-caret" aria-hidden="true"></i>
                            </button>
                            <div class="site-mega" id="productsMega" hidden>
                                <div class="site-mega-inner">
                                    <div class="site-mega-cats" role="list">
                                        <?php foreach ($taxonomy as $cat): ?>
                                            <?php
                                            $catId = (int) $cat['id'];
                                            $hasChildren = $cat['children'] !== [];
                                            $isActiveCat = $catId === $panelCategoryId;
                                            $isCurrentCat = $item['current'] && $activeCategoryId === $catId && !$activeSubcategoryId;
                                            ?>
                                            <div class="site-mega-cat" role="listitem" data-category-id="<?= $catId ?>">
                                                <?php if ($hasChildren): ?>
                                                    <button
                                                        type="button"
                                                        class="site-mega-cat-btn<?= $isActiveCat ? ' is-active' : '' ?>"
                                                        data-mega-cat="<?= $catId ?>"
                                                        aria-expanded="<?= $isActiveCat ? 'true' : 'false' ?>"
                                                        aria-controls="mega-panel-<?= $catId ?>"
                                                    ><?= htmlspecialchars($cat['name']) ?></button>
                                                <?php else: ?>
                                                    <a
                                                        class="site-mega-cat-link<?= $isCurrentCat ? ' is-active' : '' ?>"
                                                        href="<?= htmlspecialchars($cat['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= $isCurrentCat ? ' aria-current="page"' : '' ?>
                                                    ><?= htmlspecialchars($cat['name']) ?></a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($taxonomy === []): ?>
                                            <p class="site-mega-empty">No hay categorías disponibles.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="site-mega-subs">
                                        <?php foreach ($taxonomy as $cat): ?>
                                            <?php
                                            $catId = (int) $cat['id'];
                                            $isActiveCat = $catId === $panelCategoryId;
                                            $isCurrentCat = $item['current'] && $activeCategoryId === $catId && !$activeSubcategoryId;
                                            ?>
                                            <?php if ($cat['children'] !== []): ?>
                                                <div
                                                    class="site-mega-panel"
                                                    id="mega-panel-<?= $catId ?>"
                                                    data-mega-panel="<?= $catId ?>"
                                                    <?= $isActiveCat ? '' : 'hidden' ?>
                                                >
                                                    <a
                                                        class="site-mega-all<?= $isCurrentCat ? ' is-current' : '' ?>"
                                                        href="<?= htmlspecialchars($cat['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= $isCurrentCat ? ' aria-current="page"' : '' ?>
                                                    >Ver todos</a>
                                                    <ul class="site-mega-sublist">
                                                        <?php foreach ($cat['children'] as $sub): ?>
                                                            <?php
                                                            $isCurrentSub = $item['current']
                                                                && $activeCategoryId === $catId
                                                                && $activeSubcategoryId === (int) $sub['id'];
                                                            ?>
                                                            <li>
                                                                <a
                                                                    class="site-mega-sublink<?= $isCurrentSub ? ' is-current' : '' ?>"
                                                                    href="<?= htmlspecialchars($sub['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                                    <?= $isCurrentSub ? ' aria-current="page"' : '' ?>
                                                                ><?= htmlspecialchars($sub['name']) ?></a>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="site-nav-accordion" id="productsAccordion" hidden>
                                <?php foreach ($taxonomy as $cat): ?>
                                    <?php
                                    $catId = (int) $cat['id'];
                                    $hasChildren = $cat['children'] !== [];
                                    $isCurrentCat = $item['current'] && $activeCategoryId === $catId && !$activeSubcategoryId;
                                    $openCat = $item['current'] && $activeCategoryId === $catId;
                                    ?>
                                    <div class="site-acc-cat" data-acc-cat="<?= $catId ?>">
                                        <div class="site-acc-row">
                                            <a
                                                class="site-acc-link<?= $isCurrentCat ? ' is-current' : '' ?>"
                                                href="<?= htmlspecialchars($cat['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                <?= $isCurrentCat ? ' aria-current="page"' : '' ?>
                                            ><?= htmlspecialchars($cat['name']) ?></a>
                                            <?php if ($hasChildren): ?>
                                                <button
                                                    type="button"
                                                    class="site-acc-toggle"
                                                    aria-expanded="<?= $openCat ? 'true' : 'false' ?>"
                                                    aria-controls="acc-panel-<?= $catId ?>"
                                                    aria-label="Mostrar subcategorías de <?= htmlspecialchars($cat['name']) ?>"
                                                >
                                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($hasChildren): ?>
                                            <div class="site-acc-panel" id="acc-panel-<?= $catId ?>" <?= $openCat ? '' : 'hidden' ?>>
                                                <a class="site-acc-sublink" href="<?= htmlspecialchars($cat['href'], ENT_QUOTES, 'UTF-8') ?>">Ver todos</a>
                                                <?php foreach ($cat['children'] as $sub): ?>
                                                    <?php
                                                    $isCurrentSub = $item['current']
                                                        && $activeCategoryId === $catId
                                                        && $activeSubcategoryId === (int) $sub['id'];
                                                    ?>
                                                    <a
                                                        class="site-acc-sublink<?= $isCurrentSub ? ' is-current' : '' ?>"
                                                        href="<?= htmlspecialchars($sub['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= $isCurrentSub ? ' aria-current="page"' : '' ?>
                                                    ><?= htmlspecialchars($sub['name']) ?></a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a
                                class="nav-link cyberleo-nav-link<?= $item['current'] ? ' active' : '' ?>"
                                href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= $item['current'] ? ' aria-current="page"' : '' ?>
                            ><?= htmlspecialchars($item['label']) ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>
<script src="<?= htmlspecialchars($publicNavJs, ENT_QUOTES, 'UTF-8') ?>" defer></script>
