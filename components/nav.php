<?php
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

// Fail soft: keep Inicio / Productos / Ofertas / Carrito even if taxonomy queries fail.
if (!isset($categories) || !is_array($categories)) {
    try {
        $categories = function_exists('get_categories') ? get_categories() : [];
    } catch (Throwable $e) {
        $categories = [];
    }
}
if (!is_array($categories)) {
    $categories = [];
}

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$resolvedCategoryId = null;
if ($currentScript === 'category.php' && isset($category_id) && is_numeric($category_id) && (int) $category_id > 0) {
    $resolvedCategoryId = (int) $category_id;
}
$resolvedSubcategoryId = null;
if ($currentScript === 'category.php' && isset($subcategory_id) && is_numeric($subcategory_id) && (int) $subcategory_id > 0) {
    $resolvedSubcategoryId = (int) $subcategory_id;
} elseif ($currentScript === 'category.php' && isset($sub_id) && is_numeric($sub_id) && (int) $sub_id > 0) {
    $resolvedSubcategoryId = (int) $sub_id;
}

$activeCategoryId = public_nav_active_category_id($currentScript, $_GET, $resolvedCategoryId);
$activeSubcategoryId = public_nav_active_subcategory_id($currentScript, $_GET, $resolvedSubcategoryId);

if (!isset($navSubcategoriesByCategory) || !is_array($navSubcategoriesByCategory)) {
    $navSubcategoriesByCategory = public_nav_subcategories_by_category();
}
$navItems = public_nav_items(
    $categories,
    $currentScript,
    $activeCategoryId,
    $navSubcategoriesByCategory,
    $activeSubcategoryId
);

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
                        <?php
                        $productCats = is_array($item['children'] ?? null) ? $item['children'] : [];
                        $panelId = 'navProductsPanel';
                        ?>
                        <li class="nav-item dropdown site-nav-products">
                            <button
                                type="button"
                                class="nav-link cyberleo-nav-link site-nav-products-toggle<?= !empty($item['current']) ? ' active' : '' ?>"
                                id="navProductsDropdown"
                                aria-expanded="false"
                                aria-controls="<?= htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8') ?>"
                                aria-haspopup="true"
                                data-cyberleo-products-toggle="1"
                            >
                                <span><?= htmlspecialchars((string) $item['label']) ?></span>
                                <i class="bi bi-chevron-down site-nav-products-chevron" aria-hidden="true"></i>
                            </button>
                            <div
                                class="dropdown-menu site-nav-products-menu"
                                id="<?= htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8') ?>"
                                role="region"
                                aria-label="Categorías de productos"
                                hidden
                            >
                                <?php if ($productCats === []): ?>
                                    <p class="site-nav-products-empty mb-0">No hay categorías disponibles.</p>
                                <?php else: ?>
                                    <div class="site-nav-products-mega" data-cyberleo-products-mega>
                                        <div class="site-nav-products-cats" role="tablist" aria-label="Categorías">
                                            <?php foreach ($productCats as $category): ?>
                                                <?php
                                                $cid = (int) ($category['category_id'] ?? 0);
                                                $tabId = 'navProductsTab-' . $cid;
                                                $panelCatId = 'navProductsDetail-' . $cid;
                                                $isSelected = !empty($category['selected']);
                                                ?>
                                                <button
                                                    type="button"
                                                    class="site-nav-products-cat<?= $isSelected ? ' is-selected' : '' ?>"
                                                    id="<?= htmlspecialchars($tabId, ENT_QUOTES, 'UTF-8') ?>"
                                                    role="tab"
                                                    aria-selected="<?= $isSelected ? 'true' : 'false' ?>"
                                                    aria-controls="<?= htmlspecialchars($panelCatId, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-cyberleo-products-cat="<?= (int) $cid ?>"
                                                    tabindex="<?= $isSelected ? '0' : '-1' ?>"
                                                >
                                                    <i class="<?= htmlspecialchars((string) ($category['icon'] ?? 'bi bi-cpu'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                                    <span class="site-nav-products-cat-label"><?= htmlspecialchars((string) $category['label']) ?></span>
                                                    <i class="bi bi-chevron-right site-nav-products-cat-chevron" aria-hidden="true"></i>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="site-nav-products-detail">
                                            <?php foreach ($productCats as $category): ?>
                                                <?php
                                                $cid = (int) ($category['category_id'] ?? 0);
                                                $tabId = 'navProductsTab-' . $cid;
                                                $panelCatId = 'navProductsDetail-' . $cid;
                                                $isSelected = !empty($category['selected']);
                                                $subs = is_array($category['children'] ?? null) ? $category['children'] : [];
                                                ?>
                                                <div
                                                    class="site-nav-products-panel<?= $isSelected ? ' is-active' : '' ?>"
                                                    id="<?= htmlspecialchars($panelCatId, ENT_QUOTES, 'UTF-8') ?>"
                                                    role="tabpanel"
                                                    aria-labelledby="<?= htmlspecialchars($tabId, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-cyberleo-products-panel="<?= (int) $cid ?>"
                                                    <?= $isSelected ? '' : ' hidden' ?>
                                                >
                                                    <div class="site-nav-products-panel-head">
                                                        <div class="site-nav-products-panel-title">
                                                            <i class="<?= htmlspecialchars((string) ($category['icon'] ?? 'bi bi-cpu'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                                            <span><?= htmlspecialchars((string) $category['label']) ?></span>
                                                        </div>
                                                        <a
                                                            class="site-nav-products-all<?= !empty($category['current']) ? ' is-current' : '' ?>"
                                                            href="<?= htmlspecialchars((string) $category['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                            <?= !empty($category['current']) ? ' aria-current="page"' : '' ?>
                                                            data-cyberleo-nav-final="1"
                                                        >Ver todos</a>
                                                    </div>
                                                    <?php if ($subs !== []): ?>
                                                        <ul class="site-nav-products-subs">
                                                            <?php foreach ($subs as $sub): ?>
                                                                <li>
                                                                    <a
                                                                        class="site-nav-products-sub<?= !empty($sub['current']) ? ' is-current' : '' ?>"
                                                                        href="<?= htmlspecialchars((string) $sub['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                                        <?= !empty($sub['current']) ? ' aria-current="page"' : '' ?>
                                                                        data-cyberleo-nav-final="1"
                                                                    ><?= htmlspecialchars((string) $sub['label']) ?></a>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <p class="site-nav-products-empty mb-0">Sin subcategorías. Usá Ver todos para ver productos.</p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="site-nav-products-mobile" data-cyberleo-products-mobile>
                                        <?php foreach ($productCats as $category): ?>
                                            <?php
                                            $cid = (int) ($category['category_id'] ?? 0);
                                            $accBtnId = 'navProductsAccBtn-' . $cid;
                                            $accPanelId = 'navProductsAccPanel-' . $cid;
                                            $subs = is_array($category['children'] ?? null) ? $category['children'] : [];
                                            $openAcc = !empty($category['selected']);
                                            ?>
                                            <div class="site-nav-products-acc">
                                                <button
                                                    type="button"
                                                    class="site-nav-products-acc-toggle<?= $openAcc ? ' is-open' : '' ?>"
                                                    id="<?= htmlspecialchars($accBtnId, ENT_QUOTES, 'UTF-8') ?>"
                                                    aria-expanded="<?= $openAcc ? 'true' : 'false' ?>"
                                                    aria-controls="<?= htmlspecialchars($accPanelId, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-cyberleo-products-acc="<?= (int) $cid ?>"
                                                >
                                                    <span class="site-nav-products-acc-main">
                                                        <i class="<?= htmlspecialchars((string) ($category['icon'] ?? 'bi bi-cpu'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                                        <span><?= htmlspecialchars((string) $category['label']) ?></span>
                                                    </span>
                                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                                </button>
                                                <div
                                                    class="site-nav-products-acc-panel"
                                                    id="<?= htmlspecialchars($accPanelId, ENT_QUOTES, 'UTF-8') ?>"
                                                    role="region"
                                                    aria-labelledby="<?= htmlspecialchars($accBtnId, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-cyberleo-products-acc-panel="<?= (int) $cid ?>"
                                                    <?= $openAcc ? '' : ' hidden' ?>
                                                >
                                                    <a
                                                        class="site-nav-products-all<?= !empty($category['current']) ? ' is-current' : '' ?>"
                                                        href="<?= htmlspecialchars((string) $category['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= !empty($category['current']) ? ' aria-current="page"' : '' ?>
                                                        data-cyberleo-nav-final="1"
                                                    >Ver todos</a>
                                                    <?php if ($subs !== []): ?>
                                                        <ul class="site-nav-products-subs">
                                                            <?php foreach ($subs as $sub): ?>
                                                                <li>
                                                                    <a
                                                                        class="site-nav-products-sub<?= !empty($sub['current']) ? ' is-current' : '' ?>"
                                                                        href="<?= htmlspecialchars((string) $sub['href'], ENT_QUOTES, 'UTF-8') ?>"
                                                                        <?= !empty($sub['current']) ? ' aria-current="page"' : '' ?>
                                                                        data-cyberleo-nav-final="1"
                                                                    ><?= htmlspecialchars((string) $sub['label']) ?></a>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
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
<script src="assets/js/public-nav.js" defer></script>
