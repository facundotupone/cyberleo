<?php
declare(strict_types=1);

if (!isset($homeContent)) {
    require_once __DIR__ . '/../includes/home_content.php';
    if (!isset($storeSettings)) {
        $storeSettings = get_store_settings();
    }
    $homeContent = resolve_home_content_settings($storeSettings);
}

if (($homeContent['benefits_enabled'] ?? '0') !== '1') {
    return;
}

$icons = home_content_icon_allowlist();
$blocks = [];
for ($i = 1; $i <= 3; $i++) {
    $icon = (string) ($homeContent["benefit_{$i}_icon"] ?? '');
    if (!in_array($icon, $icons, true)) {
        $icon = home_content_default_settings()["benefit_{$i}_icon"];
    }
    $title = trim((string) ($homeContent["benefit_{$i}_title"] ?? ''));
    $text = trim((string) ($homeContent["benefit_{$i}_text"] ?? ''));
    if ($title === '' && $text === '') {
        continue;
    }
    $blocks[] = ['icon' => $icon, 'title' => $title, 'text' => $text];
}
if ($blocks === []) {
    return;
}
?>
<section id="beneficios" class="benefits-section" aria-labelledby="beneficios-heading">
    <h2 id="beneficios-heading" class="text-center mb-3 mt-2 h4 fw-bold">Beneficios</h2>
    <div class="row g-3 benefits-grid">
        <?php foreach ($blocks as $block): ?>
            <div class="col-md-4">
                <div class="benefit-block h-100">
                    <div class="benefit-icon" aria-hidden="true">
                        <i class="bi <?= htmlspecialchars($block['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                    </div>
                    <?php if ($block['title'] !== ''): ?>
                        <h3 class="benefit-title h6"><?= htmlspecialchars($block['title']) ?></h3>
                    <?php endif; ?>
                    <?php if ($block['text'] !== ''): ?>
                        <p class="benefit-text mb-0"><?= htmlspecialchars($block['text']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
