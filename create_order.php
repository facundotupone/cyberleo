<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$cart = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
if (!$cart) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'El carrito está vacío.']);
    exit;
}

// Se acumulan cantidades por producto: el precio y el nombre siempre se obtienen de la base.
$quantities = [];
foreach ($cart as $item) {
    $id = filter_var($item['productId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
    if (!$id || !$quantity) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Hay un producto o cantidad inválida.']);
        exit;
    }
    $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
}

try {
    $pdo->beginTransaction();
    $orderItems = [];
    $total = 0;

    foreach ($quantities as $productId => $quantity) {
        $stmt = $pdo->prepare('SELECT id, name, price, price_sale, stock FROM products WHERE id = ? AND is_active = 1 FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || (int)$product['stock'] < $quantity) {
            throw new RuntimeException($product ? 'No hay stock suficiente para ' . $product['name'] . '.' : 'Uno de los productos ya no está disponible.');
        }

        $price = !empty($product['price_sale']) && (float)$product['price_sale'] > 0
            ? (float)$product['price_sale'] : (float)$product['price'];
        $orderItems[] = ['product' => $product, 'quantity' => $quantity, 'price' => $price];
        $total += $price * $quantity;
    }

    $stmt = $pdo->prepare("INSERT INTO orders (status, total) VALUES ('pending', ?)");
    $stmt->execute([$total]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity) VALUES (?, ?, ?, ?, ?)');
    $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');

    foreach ($orderItems as $item) {
        $itemStmt->execute([$orderId, $item['product']['id'], $item['product']['name'], $item['price'], $item['quantity']]);
        $stockStmt->execute([$item['quantity'], $item['product']['id']]);
    }
    $pdo->commit();

    $message = "Hola " . STORE_NAME . ", quiero confirmar el pedido #{$orderId}:\n\n";
    foreach ($orderItems as $item) {
        $message .= "{$item['product']['name']} x {$item['quantity']} = $" . number_format($item['price'] * $item['quantity'], 2, ',', '.') . "\n";
    }
    $message .= "\nTotal: $" . number_format($total, 2, ',', '.') . "\n\nQuedo atento/a para coordinar pago, envío o retiro.";
    echo json_encode(['success' => true, 'orderId' => $orderId, 'whatsappUrl' => 'https://wa.me/' . WHATSAPP_NUMBER . '?text=' . rawurlencode($message)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
