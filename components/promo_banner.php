<?php
declare(strict_types=1);

if (!isset($homeContent)) {
    require_once __DIR__ . '/../includes/home_content.php';
    if (!isset($storeSettings)) {
        $storeSettings = get_store_settings();
    }
    $homeContent = resolve_home_content_settings($storeSettings);
}

if (($homeContent['promo_enabled'] ?? '0') !== '1') {
    return;
}

$title = trim((string) ($homeContent['promo_title'] ?? ''));
$text = trim((string) ($homeContent['promo_text'] ?? ''));
if ($title === '' && $text === '') {
    return;
}

$buttonText = trim((string) ($homeContent['promo_button_text'] ?? 'Ver más'));
if ($buttonText === '') {
    $buttonText = 'Ver más';
}
$buttonUrl = (string) ($homeContent['promo_button_url'] ?? '#');
if (!is_safe_local_home_url($buttonUrl)) {
    $buttonUrl = '#';
}

$image = (string) ($homeContent['promo_image'] ?? '');
$hasImage = $image !== '' && is_safe_promo_image_path($image);
$classes = ['promo-banner', 'promo-banner-overlay'];
if ($hasImage) {
    $classes[] = 'promo-banner-has-image';
}
?>
<section id="promo-banner" class="<?= htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="promo-banner-title">
    <?php if ($hasImage): ?>
        <div class="promo-banner-media" aria-hidden="true">
            <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" alt="" class="promo-banner-image" loading="lazy" decoding="async">
        </div>
    <?php endif; ?>
    <div class="promo-banner-content">
        <?php if ($title !== ''): ?>
            <h2 id="promo-banner-title" class="promo-banner-title h3"><?= htmlspecialchars($title) ?></h2>
        <?php else: ?>
            <h2 id="promo-banner-title" class="visually-hidden">Promoción</h2>
        <?php endif; ?>
        <?php if ($text !== ''): ?>
            <p class="promo-banner-text mb-3"><?= htmlspecialchars($text) ?></p>
        <?php endif; ?>
        <a class="btn btn-light promo-banner-cta" href="<?= htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($buttonText) ?>
        </a>
    </div>
</section>
