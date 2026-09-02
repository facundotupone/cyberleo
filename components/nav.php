<?php
if (!isset($categories)) { $categories = get_categories(); }
if (!isset($storeSettings)) { $storeSettings = get_store_settings(); }
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<nav class="navbar navbar-expand-lg site-navbar sticky-top" aria-label="Navegación principal">
    <div class="container">
        <a class="navbar-brand" href="index.php" title="<?= htmlspecialchars($storeSettings['store_name']) ?>">
            <img
                src="assets/images/brand/cyberleo-logo.png"
                alt="CyberLeo"
                class="brand-logo"
                width="220"
                height="62"
                decoding="async"
            >
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Abrir menú">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                <li class="nav-item">
                    <a class="nav-link<?= $currentScript === 'index.php' ? ' active' : '' ?>" href="index.php"<?= $currentScript === 'index.php' ? ' aria-current="page"' : '' ?>>Inicio</a>
                </li>
                <?php foreach ($categories as $category): ?>
                    <?php
                    $isActiveCategory = $currentScript === 'category.php'
                        && isset($_GET['id'])
                        && (int)$_GET['id'] === (int)$category['id'];
                    ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $isActiveCategory ? ' active' : '' ?>" href="category.php?id=<?= (int)$category['id'] ?>"<?= $isActiveCategory ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($category['name']) ?></a>
                    </li>
                <?php endforeach; ?>
                <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                    <a class="nav-cart-btn<?= $currentScript === 'cart.php' ? ' active' : '' ?>" href="cart.php">
                        <i class="bi bi-cart3" aria-hidden="true"></i>
                        <span>Carrito</span>
                        <span class="cart-count" aria-label="Productos en el carrito">0</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
