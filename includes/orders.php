<?php
function reservation_minutes($settings) {
    return max(5, min(1440, (int)($settings['reservation_minutes'] ?? 120)));
}
function expire_pending_orders(PDO $pdo) {
    $pdo->beginTransaction();
    $orders = $pdo->query("SELECT id FROM orders WHERE status = 'pending' AND expires_at <= NOW() FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($orders as $orderId) {
        $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        $restore = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) if ($item['product_id']) $restore->execute([$item['quantity'], $item['product_id']]);
        $pdo->prepare("UPDATE orders SET status = 'expired' WHERE id = ? AND status = 'pending'")->execute([$orderId]);
    }
    $pdo->commit();
    return count($orders);
}
