<?php
$storeSettings = get_store_settings();
require_once __DIR__ . '/../includes/images.php';
require_once __DIR__ . '/../includes/theme.php';
if (!function_exists('cyberleo_safe_asset_url')) {
    $cyberleoAssetSafeUrl = __DIR__ . '/../includes/asset_safe_url.php';
    if (is_file($cyberleoAssetSafeUrl) && is_readable($cyberleoAssetSafeUrl)) {
        require_once $cyberleoAssetSafeUrl;
    }
}
if (!function_exists('cyberleo_safe_asset_url')) {
    // Extremely defensive fallback if soft helper is absent.
    function cyberleo_safe_asset_url($relativePath)
    {
        return is_string($relativePath) && $relativePath !== '' ? $relativePath : 'assets/css/style.css';
    }
}
$themeSettings = resolve_theme_settings($storeSettings);
$favicon = $themeSettings['brand_favicon'];
$styleCss = cyberleo_safe_asset_url('assets/css/style.css');
$backgroundsCss = cyberleo_safe_asset_url('assets/css/backgrounds.css');
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($storeSettings['store_name']) ?>, tecnología y productos informáticos.">
<meta name="cyberleo-release" content="refinamiento-hotfix-20260905">
<title><?= htmlspecialchars($storeSettings['store_name']) ?> | Tienda informática</title>
<?php if ($favicon !== '' && is_safe_brand_favicon_path($favicon)): ?>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= htmlspecialchars($styleCss, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars($backgroundsCss, ENT_QUOTES, 'UTF-8') ?>">
<style>
<?= theme_css_custom_properties($themeSettings) ?>
<?php if (!empty($storeSettings['body_background']) && is_safe_settings_image_path($storeSettings['body_background'])): ?>
body { background-image: url('<?= htmlspecialchars($storeSettings['body_background'], ENT_QUOTES) ?>') !important; }
<?php endif; ?>
<?php if (!empty($storeSettings['hero_background']) && is_safe_settings_image_path($storeSettings['hero_background'])): ?>
.hero-section.hero-has-image { background-image: url('<?= htmlspecialchars($storeSettings['hero_background'], ENT_QUOTES) ?>') !important; background-size: cover !important; background-position: center !important; background-repeat: no-repeat !important; }
<?php endif; ?>
</style>
