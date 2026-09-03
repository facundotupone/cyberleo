<?php
if (!isset($storeSettings)) { $storeSettings = get_store_settings(); }
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/home_content.php';
if (!isset($themeSettings)) { $themeSettings = resolve_theme_settings($storeSettings); }
if (!isset($homeContent)) { $homeContent = resolve_home_content_settings($storeSettings); }
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
$footerDescription = (string) ($homeContent['footer_description'] ?? '');
$igText = (string) ($homeContent['footer_instagram_text'] ?? 'Seguinos en Instagram');
$waText = (string) ($homeContent['footer_whatsapp_text'] ?? 'Contactar por WhatsApp');
?>
<footer class="footer pt-4 pb-3" role="contentinfo">
    <div class="container">
        <?php if ($showLogo): ?>
        <div class="text-center footer-brand">
            <a href="index.php" title="<?= htmlspecialchars($storeSettings['store_name']) ?>">
                <img
                    src="<?= htmlspecialchars($brandLogoPath, ENT_QUOTES, 'UTF-8') ?>"
                    alt="CyberLeo"
                    class="brand-logo brand-logo-sm"
                    width="150"
                    height="42"
                    decoding="async"
                >
            </a>
        </div>
        <?php endif; ?>
        <div class="row justify-content-center mb-3">
            <div class="col-lg-10">
                <div class="d-flex flex-column flex-md-row gap-3 justify-content-center align-items-stretch">
                    <?php if ($showIg || $footerDescription !== ''): ?>
                    <div class="footer-banner footer-ig d-flex align-items-center gap-2 flex-grow-1">
                        <span class="footer-icon" aria-hidden="true"><i class="bi bi-instagram"></i></span>
                        <span>
                            <?= htmlspecialchars($footerDescription) ?>
                            <?php if ($showIg): ?>
                                <br><a href="<?= htmlspecialchars($storeSettings['instagram_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($igText) ?></a>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($showWa): ?>
                    <div class="footer-banner footer-wa d-flex align-items-center gap-2 flex-grow-1">
                        <span class="footer-icon" aria-hidden="true"><i class="bi bi-whatsapp"></i></span>
                        <span>
                            Escribinos por cualquier consulta o para coordinar tu compra:<br>
                            <a href="https://wa.me/<?= htmlspecialchars($storeSettings['whatsapp_number']) ?>?text=<?= urlencode('Hola ' . $storeSettings['store_name'] . ', quisiera hacer una consulta.') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($waText) ?></a>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($showHours || $showLocation): ?>
                <div class="footer-meta text-center mt-3">
                    <?php if ($showHours): ?>
                        <p class="mb-1"><span class="visually-hidden">Horarios: </span><?= htmlspecialchars($homeContent['business_hours']) ?></p>
                    <?php endif; ?>
                    <?php if ($showLocation): ?>
                        <p class="mb-0"><span class="visually-hidden">Ubicación: </span><?= htmlspecialchars($homeContent['business_location']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="row">
            <div class="col text-center">
                <small class="footer-copyright">&copy; <?php echo date('Y'); ?> <?= htmlspecialchars($storeSettings['store_name']) ?>. Todos los derechos reservados.</small>
            </div>
        </div>
    </div>
</footer>
