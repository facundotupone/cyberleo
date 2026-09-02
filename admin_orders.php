<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';
require_once 'includes/orders.php';

$message = '';
$storeSettings = get_store_settings();
expire_pending_orders($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    require_csrf();
    $orderId = filter_var($_POST['order_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = $_POST['status'];
    if (!$orderId || !in_array($status, ['confirmed', 'cancelled'], true)) {
        $message = 'Actualización inválida.';
    } else {
        try {
            $result = transition_order($pdo, $orderId, $status);
            $message = $result === 'confirmed' ? 'Pedido confirmado.' : ($result === 'expired' ? 'El pedido venció y se repuso el stock.' : 'Pedido cancelado y stock repuesto.');
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }
    }
}

$orders = $pdo->query("SELECT o.*, GROUP_CONCAT(CONCAT(oi.product_name, ' × ', oi.quantity) SEPARATOR ' | ') AS items
    FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
    GROUP BY o.id ORDER BY o.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos | <?= htmlspecialchars($storeSettings['store_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark" style="background:#071a33"><div class="container"><a class="navbar-brand" href="admin_products.php"><?= htmlspecialchars($storeSettings['store_name']) ?> · Administración</a><div><a class="btn btn-outline-light btn-sm" href="admin_products.php">Productos</a> <a class="btn btn-outline-light btn-sm" href="admin_categories.php">Categorías</a></div></div></nav>
<main class="container py-4">
    <h1 class="h2 mb-1"><i class="bi bi-receipt"></i> Pedidos</h1>
    <p class="text-muted">Cada solicitud reserva stock. Las reservas vencidas se liberan automáticamente.</p>
    <?php if ($message): ?><div class="alert alert-info"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <div class="table-responsive bg-white rounded shadow-sm">
    <table class="table align-middle mb-0"><thead class="table-dark"><tr><th>#</th><th>Fecha</th><th>Vence</th><th>Productos</th><th>Total</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
    <?php foreach ($orders as $order): ?>
        <tr><td><?= (int)$order['id'] ?></td><td><?= htmlspecialchars($order['created_at']) ?></td><td><?= htmlspecialchars($order['expires_at']) ?></td><td><?= htmlspecialchars($order['items'] ?: 'Sin productos') ?></td><td><?= format_price($order['total']) ?></td>
        <td><span class="badge text-bg-<?= $order['status'] === 'pending' ? 'warning' : ($order['status'] === 'confirmed' ? 'success' : 'secondary') ?>"><?= htmlspecialchars(['pending'=>'Pendiente','confirmed'=>'Confirmado','cancelled'=>'Cancelado','expired'=>'Vencido'][$order['status']] ?? $order['status']) ?></span></td>
        <td><?php if ($order['status'] === 'pending'): ?><form method="post" class="d-flex gap-1"><?= csrf_input() ?><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button name="status" value="confirmed" class="btn btn-sm btn-success">Confirmar</button><button name="status" value="cancelled" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Cancelar y reponer el stock?')">Cancelar</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="7" class="text-center py-4 text-muted">Todavía no hay pedidos.</td></tr><?php endif; ?>
    </tbody></table></div>
</main>
</body></html>
