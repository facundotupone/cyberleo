<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_nav.php';

if (!isset($storeSettings)) {
    $storeSettings = get_store_settings();
}

$adminCurrentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminCurrentId = admin_nav_current_id($adminCurrentScript);
$adminNavItems = admin_nav_items();
$adminBrandLabel = trim((string) ($storeSettings['store_name'] ?? 'CyberLeo')) . ' · Administración';
?>
<nav class="navbar navbar-expand-lg navbar-dark admin-navbar" aria-label="Navegación administrativa">
    <div class="container">
        <a class="navbar-brand admin-navbar-brand fw-bold" href="admin_products.php">
            <i class="bi bi-box-seam" aria-hidden="true"></i>
            <?= htmlspecialchars($adminBrandLabel) ?>
        </a>
        <button
            class="navbar-toggler admin-navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#adminNavbar"
            aria-controls="adminNavbar"
            aria-expanded="false"
            aria-label="Abrir menú de administración"
        >
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 admin-navbar-links">
                <?php foreach ($adminNavItems as $item): ?>
                    <?php $isCurrent = $adminCurrentId === $item['id']; ?>
                    <li class="nav-item">
                        <a
                            class="nav-link admin-nav-link<?= $isCurrent ? ' active' : '' ?>"
                            href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                            <?= $isCurrent ? ' aria-current="page"' : '' ?>
                        >
                            <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                            <?= htmlspecialchars($item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>
