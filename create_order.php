<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/orders.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$cart = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
$idempotencyKey = $payload['idempotencyKey'] ?? '';
if (!is_string($idempotencyKey) || !preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => 'Solicitud inválida.']); exit;
}
if (!$cart) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'El carrito está vacío.']);
    exit;
}
if (count($cart) > 50) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'El carrito supera el límite permitido.']); exit; }

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
    if ($quantities[$id] > 20) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Cantidad no permitida.']); exit; }
}
if (array_sum($quantities) > 100) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'El carrito supera el límite permitido.']); exit; }

try {
    $storeSettings = get_store_settings();
    enforce_order_rate_limit($pdo);
    expire_pending_orders($pdo);
    $pdo->beginTransaction();
    $existing = $pdo->prepare('SELECT id FROM orders WHERE idempotency_key = ? FOR UPDATE');
    $existing->execute([$idempotencyKey]);
    if ($existingId = $existing->fetchColumn()) {
        $pdo->commit();
        echo json_encode(['success' => true, 'orderId' => (int)$existingId, 'whatsappUrl' => order_whatsapp_url($pdo, $existingId, $storeSettings), 'message' => 'Pedido ya registrado.']);
        exit;
    }
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

    $minutes = reservation_minutes($storeSettings);
    $stmt = $pdo->prepare("INSERT INTO orders (status, total, idempotency_key, expires_at) VALUES ('pending', ?, ?, DATE_ADD(NOW(), INTERVAL $minutes MINUTE))");
    $stmt->execute([$total, $idempotencyKey]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity) VALUES (?, ?, ?, ?, ?)');
    $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');

    foreach ($orderItems as $item) {
        $itemStmt->execute([$orderId, $item['product']['id'], $item['product']['name'], $item['price'], $item['quantity']]);
        $stockStmt->execute([$item['quantity'], $item['product']['id']]);
    }
    $pdo->commit();

    echo json_encode(['success' => true, 'orderId' => $orderId, 'whatsappUrl' => order_whatsapp_url($pdo, $orderId, $storeSettings)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    error_log('Order creation failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No fue posible registrar el pedido. Verificá el stock e intentá nuevamente.']);
}
