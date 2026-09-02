<?php
$storeSettings = get_store_settings();
require_once __DIR__ . '/../includes/images.php';
require_once __DIR__ . '/../includes/theme.php';
$themeSettings = resolve_theme_settings($storeSettings);
$favicon = $themeSettings['brand_favicon'];
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($storeSettings['store_name']) ?>, tecnología y productos informáticos.">
<title><?= htmlspecialchars($storeSettings['store_name']) ?> | Tienda informática</title>
<?php if ($favicon !== '' && is_safe_brand_favicon_path($favicon)): ?>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/backgrounds.css">
<style>
<?= theme_css_custom_properties($themeSettings) ?>
<?php if (!empty($storeSettings['body_background']) && is_safe_settings_image_path($storeSettings['body_background'])): ?>
body { background-image: url('<?= htmlspecialchars($storeSettings['body_background'], ENT_QUOTES) ?>') !important; }
<?php endif; ?>
<?php if (!empty($storeSettings['hero_background']) && is_safe_settings_image_path($storeSettings['hero_background'])): ?>
.hero-section.hero-has-image { background-image: url('<?= htmlspecialchars($storeSettings['hero_background'], ENT_QUOTES) ?>') !important; background-size: cover !important; background-position: center !important; background-repeat: no-repeat !important; }
<?php endif; ?>
</style>
