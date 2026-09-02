<?php
declare(strict_types=1);

if (!isset($homeContent)) {
    require_once __DIR__ . '/../includes/home_content.php';
    if (!isset($storeSettings)) {
        $storeSettings = get_store_settings();
    }
    $homeContent = resolve_home_content_settings($storeSettings);
}

if (($homeContent['announcement_enabled'] ?? '0') !== '1') {
    return;
}

$text = trim((string) ($homeContent['announcement_text'] ?? ''));
if ($text === '') {
    return;
}

$style = $homeContent['announcement_style'] ?? 'primary';
if (!in_array($style, ['primary', 'secondary', 'navy'], true)) {
    $style = 'primary';
}

$url = trim((string) ($homeContent['announcement_url'] ?? ''));
$hasLink = $url !== '' && is_safe_local_home_url($url);
$barClass = 'site-announcement site-announcement-' . $style;
?>
<div id="site-announcement" class="<?= htmlspecialchars($barClass, ENT_QUOTES, 'UTF-8') ?>" role="region" aria-label="Aviso">
    <div class="container site-announcement-inner">
        <?php if ($hasLink): ?>
            <a class="site-announcement-link" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($text) ?></a>
        <?php else: ?>
            <p class="site-announcement-text mb-0"><?= htmlspecialchars($text) ?></p>
        <?php endif; ?>
        <button type="button" class="site-announcement-close" id="site-announcement-close" aria-label="Cerrar aviso">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
</div>
<script>
(function () {
    'use strict';
    var KEY = 'cyberleo_announcement_dismissed';
    var bar = document.getElementById('site-announcement');
    if (!bar) return;
    try {
        if (window.sessionStorage && sessionStorage.getItem(KEY) === '1') {
            bar.hidden = true;
            return;
        }
    } catch (e) {}
    var btn = document.getElementById('site-announcement-close');
    if (!btn) return;
    btn.addEventListener('click', function () {
        bar.hidden = true;
        try {
            if (window.sessionStorage) sessionStorage.setItem(KEY, '1');
        } catch (e) {}
    });
})();
</script>
