<?php $storeSettings = get_store_settings(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($storeSettings['store_name']) ?>, tecnología y productos informáticos.">
<title><?= htmlspecialchars($storeSettings['store_name']) ?> | Tienda informática</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/backgrounds.css">
<style>
<?php if (!empty($storeSettings['body_background'])): ?>body { background-image: url('<?= htmlspecialchars($storeSettings['body_background'], ENT_QUOTES) ?>') !important; }<?php endif; ?>
<?php if (!empty($storeSettings['hero_background'])): ?>.hero-section.hero-has-image { background-image: linear-gradient(135deg, rgba(7, 26, 51, 0.72), rgba(0, 87, 184, 0.55)), url('<?= htmlspecialchars($storeSettings['hero_background'], ENT_QUOTES) ?>') !important; background-size: cover !important; background-position: center !important; background-repeat: no-repeat !important; }<?php endif; ?>
</style>
