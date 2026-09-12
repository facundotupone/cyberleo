<?php
declare(strict_types=1);

/**
 * Pruebas HTTP locales para XAMPP (PHP/cURL). No usa Node ni .mjs.
 */

$base = rtrim((string) getenv('HTTP_BASE_URL'), '/');
$dsn = (string) getenv('TEST_DSN');
if ($base === '' || $dsn === '') {
    fwrite(STDERR, "HTTP_BASE_URL y TEST_DSN son obligatorios.\n");
    exit(2);
}

$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$cookieFile = sys_get_temp_dir() . '/cyberleo-http-cookie-' . bin2hex(random_bytes(4));

$passed = 0;

function xh_fail(string $id, string $message): never
{
    throw new RuntimeException("$id FAIL - $message");
}

function xh_pass(string $id, string $message): void
{
    global $passed;
    $passed++;
    echo "$id PASS - $message\n";
}

/**
 * @return array{status:int,body:string,headers:string}
 */
function xh_request(string $method, string $url, ?string $body = null, array $headers = [], ?string $cookieFile = null): array
{
    $headerLines = [];
    $ch = curl_init($url);
    if ($ch === false) {
        xh_fail('HTTP-CLIENT', 'no se pudo iniciar cURL');
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headerLines): int {
            $headerLines[] = $line;
            return strlen($line);
        },
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    if ($cookieFile !== null) {
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        xh_fail('HTTP-CLIENT', $error !== '' ? $error : 'cURL sin respuesta');
    }
    return [
        'status' => $status,
        'body' => (string) $response,
        'headers' => implode('', $headerLines),
    ];
}

function xh_sql(PDO $pdo, string $sql)
{
    return $pdo->query($sql)->fetchColumn();
}

try {
    $home = xh_request('GET', $base . '/');
    if ($home['status'] !== 200) {
        xh_fail('XH-01', 'GET / devolvió HTTP ' . $home['status']);
    }
    xh_pass('XH-01', 'portada HTTP 200');

    if (!str_contains($home['body'], 'site-navbar') && !str_contains($home['body'], 'public-nav')) {
        xh_fail('XH-02', 'la portada no incluye la navegación pública');
    }
    xh_pass('XH-02', 'portada incluye navegación pública');

    $offers = xh_request('GET', $base . '/offers.php');
    if ($offers['status'] !== 200) {
        xh_fail('XH-03', 'offers.php HTTP ' . $offers['status']);
    }
    xh_pass('XH-03', 'ofertas HTTP 200');

    $cart = xh_request('GET', $base . '/cart.php');
    if ($cart['status'] !== 200) {
        xh_fail('XH-04', 'cart.php HTTP ' . $cart['status']);
    }
    xh_pass('XH-04', 'carrito HTTP 200');

    $category = xh_request('GET', $base . '/category.php?id=1');
    if ($category['status'] !== 200) {
        xh_fail('XH-05', 'category.php HTTP ' . $category['status']);
    }
    xh_pass('XH-05', 'categoría HTTP 200');

    $login = xh_request('GET', $base . '/admin_login.php');
    if ($login['status'] !== 200) {
        xh_fail('XH-06', 'admin_login.php HTTP ' . $login['status']);
    }
    if (str_contains($login['body'], 'admin_products.php') && str_contains($login['body'], 'admin-nav')) {
        xh_fail('XH-06', 'login muestra menú administrativo');
    }
    xh_pass('XH-06', 'login administrativo HTTP 200 sin menú admin');

    $denied = [
        'XH-07' => 'includes/config.php',
        'XH-08' => 'schema.sql',
        'XH-09' => 'tests/run.sh',
        'XH-10' => '.env',
        'XH-11' => 'includes/config.local.php',
    ];
    foreach ($denied as $id => $path) {
        $response = xh_request('GET', $base . '/' . $path);
        if ($response['status'] !== 403) {
            xh_fail($id, "$path devolvió HTTP {$response['status']} (esperado 403)");
        }
        xh_pass($id, "$path denegado con 403");
    }

    $css = xh_request('GET', $base . '/assets/css/style.css');
    if ($css['status'] !== 200 || $css['body'] === '') {
        xh_fail('XH-12', 'style.css HTTP ' . $css['status']);
    }
    xh_pass('XH-12', 'CSS público HTTP 200');

    $js = xh_request('GET', $base . '/assets/js/public-nav.js');
    if ($js['status'] !== 200 || $js['body'] === '') {
        xh_fail('XH-13', 'public-nav.js HTTP ' . $js['status']);
    }
    xh_pass('XH-13', 'JS de navegación HTTP 200');

    $method = xh_request('GET', $base . '/create_order.php');
    if ($method['status'] !== 405) {
        xh_fail('XH-14', 'GET create_order.php devolvió HTTP ' . $method['status']);
    }
    xh_pass('XH-14', 'create_order rechaza GET con 405');

    $empty = xh_request(
        'POST',
        $base . '/create_order.php',
        json_encode(['idempotencyKey' => str_repeat('a', 64), 'items' => []], JSON_THROW_ON_ERROR),
        ['Content-Type: application/json']
    );
    if ($empty['status'] !== 422) {
        xh_fail('XH-15', 'carrito vacío devolvió HTTP ' . $empty['status']);
    }
    xh_pass('XH-15', 'create_order rechaza carrito vacío');

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DELETE FROM order_rate_limits');
    $pdo->exec('DELETE FROM order_items');
    $pdo->exec('DELETE FROM orders');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec('UPDATE products SET stock=8 WHERE id=1');
    $order = xh_request(
        'POST',
        $base . '/create_order.php',
        json_encode([
            'idempotencyKey' => str_repeat('b', 64),
            'items' => [['productId' => 1, 'quantity' => 2]],
        ], JSON_THROW_ON_ERROR),
        ['Content-Type: application/json']
    );
    if ($order['status'] !== 200) {
        xh_fail('XH-16', 'pedido válido devolvió HTTP ' . $order['status'] . ' body=' . substr($order['body'], 0, 200));
    }
    $payload = json_decode($order['body'], true);
    if (!is_array($payload) || empty($payload['success']) || empty($payload['orderId'])) {
        xh_fail('XH-16', 'respuesta de pedido inválida');
    }
    if (!is_string($payload['whatsappUrl'] ?? null) || !str_starts_with($payload['whatsappUrl'], 'https://wa.me/')) {
        xh_fail('XH-16', 'whatsappUrl no es wa.me');
    }
    $stock = (int) xh_sql($pdo, 'SELECT stock FROM products WHERE id=1');
    $orders = (int) xh_sql($pdo, 'SELECT COUNT(*) FROM orders');
    if ($stock !== 6 || $orders !== 1) {
        xh_fail('XH-16', "stock=$stock orders=$orders");
    }
    xh_pass('XH-16', 'pedido HTTP reserva stock y devuelve WhatsApp');

    $repeat = xh_request(
        'POST',
        $base . '/create_order.php',
        json_encode([
            'idempotencyKey' => str_repeat('b', 64),
            'items' => [['productId' => 1, 'quantity' => 2]],
        ], JSON_THROW_ON_ERROR),
        ['Content-Type: application/json']
    );
    $repeatPayload = json_decode($repeat['body'], true);
    if ($repeat['status'] !== 200 || (int) ($repeatPayload['orderId'] ?? 0) !== (int) $payload['orderId']) {
        xh_fail('XH-17', 'idempotencia de pedido falló');
    }
    if ((int) xh_sql($pdo, 'SELECT COUNT(*) FROM orders') !== 1 || (int) xh_sql($pdo, 'SELECT stock FROM products WHERE id=1') !== 6) {
        xh_fail('XH-17', 'la repetición alteró stock o pedidos');
    }
    xh_pass('XH-17', 'pedido idempotente no duplica ni descuenta otra vez');

    $admin = xh_request('GET', $base . '/admin_products.php');
    if (!in_array($admin['status'], [302, 303, 401, 403], true) && !($admin['status'] === 200 && str_contains($admin['body'], 'admin_login.php'))) {
        xh_fail('XH-18', 'admin_products.php accesible sin sesión HTTP ' . $admin['status']);
    }
    xh_pass('XH-18', 'panel de productos no queda abierto sin login');

    $csrf = xh_request('POST', $base . '/admin_settings.php', 'store_name=csrf-mutated', [
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    if ($csrf['status'] !== 403 && !in_array($csrf['status'], [302, 303], true)) {
        xh_fail('XH-19', 'admin_settings sin CSRF devolvió HTTP ' . $csrf['status']);
    }
    $storeName = (string) xh_sql($pdo, "SELECT setting_value FROM store_settings WHERE setting_key='store_name'");
    if ($storeName === 'csrf-mutated') {
        xh_fail('XH-19', 'la petición sin CSRF modificó store_name');
    }
    xh_pass('XH-19', 'settings sin CSRF no muta (403 o redirect de login)');

    $search = xh_request('GET', $base . '/search_products.php?q=HTTP');
    if (!in_array($search['status'], [200, 400], true)) {
        xh_fail('XH-20', 'search_products.php HTTP ' . $search['status']);
    }
    xh_pass('XH-20', 'búsqueda pública responde sin 500');

    echo "HTTP XAMPP smoke: $passed passed, 0 failed\n";
} finally {
    if (is_file($cookieFile)) {
        @unlink($cookieFile);
    }
}
