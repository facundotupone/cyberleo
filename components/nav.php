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
$activeCategoryId = public_nav_active_category_id($currentScript, $_GET);
$navItems = public_nav_items($categories, $currentScript, $activeCategoryId);

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
