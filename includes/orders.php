<?php
function reservation_minutes($settings) {
    return max(5, min(1440, (int)($settings['reservation_minutes'] ?? 120)));
}
function mysql_now(PDO $pdo) { return $pdo->query('SELECT NOW()')->fetchColumn(); }
function enforce_order_rate_limit(PDO $pdo) {
    if (APP_SECRET === '') throw new RuntimeException('Missing application secret.');
    $hash = hash_hmac('sha256', 'order|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), APP_SECRET);
    $lockName = 'cyberleo:order:' . substr($hash, 0, 48);
    if (strlen($lockName) > 64) throw new RuntimeException('Invalid rate limit lock name.');
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)'); $lock->execute([$lockName]);
    if (!$lock->fetchColumn()) throw new RuntimeException('Rate limit lock unavailable.');
    try {
        $pdo->prepare('DELETE FROM order_rate_limits WHERE requested_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)')->execute();
        $count = $pdo->prepare('SELECT COUNT(*) FROM order_rate_limits WHERE client_hash = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $count->execute([$hash]);
        if ((int)$count->fetchColumn() >= 10) throw new RateLimitException('Too many attempts.');
        $pdo->prepare('INSERT INTO order_rate_limits (client_hash, requested_at) VALUES (?, NOW())')->execute([$hash]);
    } finally { $pdo->prepare('DO RELEASE_LOCK(?)')->execute([$lockName]); }
}
function order_whatsapp_url(PDO $pdo, $orderId, $settings) {
    require_once __DIR__ . '/checkout_display.php';
    $stmt = $pdo->prepare('SELECT product_name, unit_price, quantity FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return null;
    }
    $message = checkout_build_whatsapp_message($items, $orderId, is_array($settings) ? $settings : []);
    if ($message === null || $message === '') {
        return null;
    }
    $number = is_array($settings) ? (string) ($settings['whatsapp_number'] ?? '') : '';
    return checkout_build_whatsapp_url($number, $message);
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
