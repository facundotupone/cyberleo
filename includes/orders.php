<?php
function reservation_minutes($settings) {
    return max(5, min(1440, (int)($settings['reservation_minutes'] ?? 120)));
}
function mysql_now(PDO $pdo) { return $pdo->query('SELECT NOW()')->fetchColumn(); }
function enforce_order_rate_limit(PDO $pdo) {
    $hash = hash('sha256', 'order|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $pdo->prepare('DELETE FROM order_rate_limits WHERE requested_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)')->execute();
    $count = $pdo->prepare('SELECT COUNT(*) FROM order_rate_limits WHERE client_hash = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $count->execute([$hash]);
    if ((int)$count->fetchColumn() >= 10) { header('Retry-After: 900'); http_response_code(429); echo json_encode(['success'=>false,'message'=>'Demasiados intentos. Intentá más tarde.']); exit; }
    $pdo->prepare('INSERT INTO order_rate_limits (client_hash, requested_at) VALUES (?, NOW())')->execute([$hash]);
}
function order_whatsapp_url(PDO $pdo, $orderId, $settings) {
    $stmt = $pdo->prepare('SELECT product_name, unit_price, quantity FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId]); $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) return null;
    $message = "Hola {$settings['store_name']}, quiero confirmar el pedido #{$orderId}:\n\n"; $total = 0;
    foreach ($items as $item) { $subtotal = $item['unit_price'] * $item['quantity']; $total += $subtotal; $message .= "{$item['product_name']} x {$item['quantity']} = $" . number_format($subtotal, 2, ',', '.') . "\n"; }
    return 'https://wa.me/' . $settings['whatsapp_number'] . '?text=' . rawurlencode($message . "\nTotal: $" . number_format($total, 2, ',', '.'));
}
function restore_order_stock(PDO $pdo, $orderId) {
    $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $restore = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) if ($item['product_id']) $restore->execute([$item['quantity'], $item['product_id']]);
}
function transition_order(PDO $pdo, $orderId, $target) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status, expires_at FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['status'] !== 'pending') throw new RuntimeException('El pedido ya fue procesado.');
        $now = mysql_now($pdo);
        if ($target === 'confirmed' && $order['expires_at'] <= $now) $target = 'expired';
        $update = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ? AND status = \'pending\'');
        $update->execute([$target, $orderId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('El pedido no pudo actualizarse.');
        if ($target === 'cancelled' || $target === 'expired') restore_order_stock($pdo, $orderId);
        $pdo->commit();
        return $target;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function expire_pending_orders(PDO $pdo) {
    try {
        $ids = $pdo->query("SELECT id FROM orders WHERE status = 'pending' AND expires_at <= NOW()")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) { try { transition_order($pdo, $id, 'expired'); } catch (RuntimeException $e) {} }
        return count($ids);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
