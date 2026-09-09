<?php
/** Minimal public nav used only when components/nav.php fails. */
$storeLabel = isset($storeSettings['store_name']) ? (string) $storeSettings['store_name'] : 'CyberLeo';
?>
<nav class="navbar navbar-expand-lg site-navbar sticky-top mb-3" data-cyberleo-nav="public" data-cyberleo-nav-fallback="1">
    <div class="container">
        <a class="navbar-brand" href="index.php"><?= htmlspecialchars($storeLabel, ENT_QUOTES, 'UTF-8') ?></a>
        <a class="nav-link" href="cart.php">Carrito</a>
    </div>
</nav>
