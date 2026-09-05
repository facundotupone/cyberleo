<?php
if (!isset($storeSettings)) {
    $storeSettings = get_store_settings();
}
if (!isset($categories)) {
    $categories = get_categories();
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

$brandLogoPath = is_safe_brand_logo_path($themeSettings['brand_logo'] ?? '')
    ? $themeSettings['brand_logo']
    : THEME_OFFICIAL_LOGO;

$showLogo = ($homeContent['footer_show_logo'] ?? '1') === '1';
$showIg = ($homeContent['footer_show_instagram'] ?? '1') === '1'
    && !empty($storeSettings['instagram_url']);
$showWa = ($homeContent['footer_show_whatsapp'] ?? '1') === '1'
    && !empty($storeSettings['whatsapp_number']);
$showHours = ($homeContent['footer_show_business_hours'] ?? '0') === '1'
    && trim((string) ($homeContent['business_hours'] ?? '')) !== '';
$showLocation = ($homeContent['footer_show_location'] ?? '0') === '1'
    && trim((string) ($homeContent['business_location'] ?? '')) !== '';

$footerDescription = trim((string) ($homeContent['footer_description'] ?? ''));
$igText = (string) ($homeContent['footer_instagram_text'] ?? 'Seguinos en Instagram');
$waText = (string) ($homeContent['footer_whatsapp_text'] ?? 'Contactar por WhatsApp');

$showBrandCol = $showLogo || $footerDescription !== '';
$showContactCol = $showIg || $showWa || $showHours || $showLocation;

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$activeCategoryId = public_nav_active_category_id($currentScript, $_GET);
$footerNavItems = public_nav_footer_items(
    public_nav_items($categories, $currentScript, $activeCategoryId)
);

$colCount = 1 + ($showBrandCol ? 1 : 0) + ($showContactCol ? 1 : 0);
?>
<footer class="footer site-footer" role="contentinfo">
    <div class="container site-footer-inner">
        <div class="site-footer-grid site-footer-cols-<?= (int) $colCount ?>">
            <?php if ($showBrandCol): ?>
            <div class="site-footer-col site-footer-brand">
                <?php if ($showLogo): ?>
                <a class="site-footer-logo-link" href="index.php" title="<?= htmlspecialchars($storeSettings['store_name']) ?>">
                    <img
                        src="<?= htmlspecialchars($brandLogoPath, ENT_QUOTES, 'UTF-8') ?>"
                        alt="CyberLeo"
                        class="brand-logo brand-logo-sm"
                        width="150"
                        height="42"
                        decoding="async"
                    >
                </a>
                <?php endif; ?>
                <?php if ($footerDescription !== ''): ?>
                    <p class="site-footer-description"><?= htmlspecialchars($footerDescription) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="site-footer-col site-footer-nav">
                <h2 class="site-footer-heading">Navegación</h2>
                <ul class="site-footer-links">
                    <?php foreach ($footerNavItems as $item): ?>
                        <li>
                            <a
                                href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                class="site-footer-link<?= $item['current'] ? ' is-current' : '' ?>"
                                <?= $item['current'] ? ' aria-current="page"' : '' ?>
                            ><?= htmlspecialchars($item['label']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($showContactCol): ?>
            <div class="site-footer-col site-footer-contact">
                <h2 class="site-footer-heading">Contacto</h2>
                <ul class="site-footer-contact-list">
                    <?php if ($showIg): ?>
                    <li>
                        <a
                            class="site-footer-social site-footer-ig"
                            href="<?= htmlspecialchars($storeSettings['instagram_url']) ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            <i class="bi bi-instagram" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($igText) ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($showWa): ?>
                    <li>
                        <a
                            class="site-footer-social site-footer-wa"
                            href="https://wa.me/<?= htmlspecialchars($storeSettings['whatsapp_number']) ?>?text=<?= urlencode('Hola ' . $storeSettings['store_name'] . ', quisiera hacer una consulta.') ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            <i class="bi bi-whatsapp" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($waText) ?></span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($showHours): ?>
                    <li class="site-footer-meta-item">
                        <i class="bi bi-clock" aria-hidden="true"></i>
                        <span><span class="visually-hidden">Horarios: </span><?= htmlspecialchars($homeContent['business_hours']) ?></span>
                    </li>
                    <?php endif; ?>
                    <?php if ($showLocation): ?>
                    <li class="site-footer-meta-item">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <span><span class="visually-hidden">Ubicación: </span><?= htmlspecialchars($homeContent['business_location']) ?></span>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <div class="site-footer-copy">
            <small class="footer-copyright">&copy; <?php echo date('Y'); ?> <?= htmlspecialchars($storeSettings['store_name']) ?>. Todos los derechos reservados.</small>
        </div>
    </div>
</footer>
