<?php if (!isset($categories)) { $categories = get_categories(); } ?>
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold text-primary" href="index.php">
            <i class="bi bi-cpu-fill me-2"></i><?= htmlspecialchars(STORE_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Abrir menú">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item"><a class="nav-link" href="index.php">Inicio</a></li>
                <?php foreach ($categories as $category): ?>
                    <li class="nav-item"><a class="nav-link" href="category.php?id=<?= (int)$category['id'] ?>"><?= htmlspecialchars($category['name']) ?></a></li>
                <?php endforeach; ?>
                <li class="nav-item ms-lg-2"><a class="btn btn-outline-primary btn-sm" href="cart.php"><i class="bi bi-cart3"></i> Carrito <span class="cart-count">0</span></a></li>
            </ul>
        </div>
    </div>
</nav>
