<?php
declare(strict_types=1);

require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';
require_once 'includes/system_health.php';

$storeSettings = get_store_settings();
$checks = system_health_run_checks($pdo, dirname(__FILE__));
$summary = system_health_summary($checks);

$statusClass = [
    'PASS' => 'success',
    'WARN' => 'warning',
    'FAIL' => 'danger',
];
$overall = $summary['status'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema | <?= htmlspecialchars((string) ($storeSettings['store_name'] ?? 'CyberLeo'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f3f8fc; }
        .navbar { background: #071a33; }
        .status-pill { font-size: .85rem; letter-spacing: .02em; }
        @media (max-width: 420px) {
            h1 { font-size: 1.35rem; }
            .table { font-size: .9rem; }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="admin_products.php">
            <i class="bi bi-box-seam" aria-hidden="true"></i> Panel de Admin
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Menú">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="admin_products.php">Productos</a></li>
                <li class="nav-item"><a class="nav-link" href="admin_categories.php">Categorías</a></li>
                <li class="nav-item"><a class="nav-link" href="admin_orders.php">Pedidos</a></li>
                <li class="nav-item"><a class="nav-link" href="admin_settings.php">Configuración</a></li>
                <li class="nav-item"><a class="nav-link active" href="admin_system.php" aria-current="page">Sistema</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container my-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-heartbeat" aria-hidden="true"></i> Estado del sistema</h1>
            <p class="text-muted mb-0">Solo lectura. Backup, restore e instalación se realizan por CLI privado.</p>
        </div>
        <span class="badge text-bg-<?= htmlspecialchars($statusClass[$overall] ?? 'secondary', ENT_QUOTES, 'UTF-8') ?> status-pill px-3 py-2">
            Estado general: <?= htmlspecialchars($overall, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="bg-white border rounded p-3 text-center">
                <div class="text-success fw-bold fs-4"><?= (int) $summary['pass'] ?></div>
                <div class="small text-muted">PASS</div>
            </div>
        </div>
        <div class="col-4">
            <div class="bg-white border rounded p-3 text-center">
                <div class="text-warning fw-bold fs-4"><?= (int) $summary['warn'] ?></div>
                <div class="small text-muted">WARN</div>
            </div>
        </div>
        <div class="col-4">
            <div class="bg-white border rounded p-3 text-center">
                <div class="text-danger fw-bold fs-4"><?= (int) $summary['fail'] ?></div>
                <div class="small text-muted">FAIL</div>
            </div>
        </div>
    </div>

    <div class="table-responsive bg-white border rounded">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Control</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Detalle</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($checks as $check): ?>
                <?php
                $st = (string) ($check['status'] ?? '');
                $badge = $statusClass[$st] ?? 'secondary';
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) $check['label'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge text-bg-<?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="small text-muted"><?= htmlspecialchars((string) ($check['detail'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="small text-muted mt-3 mb-0">
        Este panel no muestra credenciales, secretos, SQL ni rutas absolutas.
        Para mantenimiento usá las herramientas privadas fuera de public_html.
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
