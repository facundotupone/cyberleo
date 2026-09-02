<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/theme.php';
require_once dirname(__DIR__) . '/includes/home_content.php';
require_once dirname(__DIR__) . '/includes/catalog_display.php';
require_once dirname(__DIR__) . '/includes/checkout_display.php';
require_once dirname(__DIR__) . '/includes/images.php';

if (!function_exists('format_price')) {
    function format_price($price) {
        return '$' . number_format((float) $price, 2, ',', '.');
    }
}

$pdo = new PDO(
    (string) getenv('TEST_DSN'),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$root = sys_get_temp_dir() . '/cyberleo-checkout-' . bin2hex(random_bytes(5));
mkdir($root . '/assets/images/products', 0700, true);
mkdir($root . '/assets/images/settings', 0700, true);
mkdir($root . '/assets/images/brand', 0700, true);
$official = $root . '/' . THEME_OFFICIAL_LOGO;
@mkdir(dirname($official), 0700, true);
copy(dirname(__DIR__) . '/' . THEME_OFFICIAL_LOGO, $official);
$officialHash = '1c209559ea540fa864ba3e3bd17be1f6cdb823582389cd65224a2382849b456b';
$passed = 0;

function cook(bool $v, string $id, string $text): void {
    global $passed;
    if (!$v) {
        throw new RuntimeException("$id failed: $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

function coreset(PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0; TRUNCATE store_settings; TRUNCATE product_images; TRUNCATE products; TRUNCATE categories; SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO categories(id,name,icon) VALUES(1,'Notebooks','bi-laptop')");
    $pdo->exec("INSERT INTO products(id,name,description,price,price_sale,stock,image,category_id,is_active,destacados) VALUES(1,'Prod','Desc',100,80,5,'',1,1,1)");
}

function coset(PDO $pdo, string $k, string $v): void {
    $s = $pdo->prepare('INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute([$k, $v]);
}

function coget(PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key=?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}

function corm(string $d): void {
    if (!is_dir($d)) {
        return;
    }
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = "$d/$f";
        is_dir($p) ? corm($p) : @unlink($p);
    }
    @rmdir($d);
}

function copost(array $overrides = []): array {
    $base = checkout_display_default_settings();
    $base['product_show_stock'] = '1'; // ignored
    foreach ($overrides as $k => $v) {
        $base[$k] = $v;
    }
    return $base;
}

try {
    $defaults = checkout_display_default_settings();
    cook(
        $defaults['cart_page_title'] === 'Carrito de Compras'
        && $defaults['cart_items_title'] === 'Productos en tu carrito'
        && $defaults['cart_summary_title'] === 'Resumen del pedido'
        && $defaults['cart_total_label'] === 'Total:'
        && $defaults['cart_delivery_title'] === 'Información de envío'
        && $defaults['cart_delivery_text'] === 'Envíanos tu consulta y te responderemos a la brevedad para coordinar envío o retiro.'
        && $defaults['cart_delivery_methods_title'] === 'Formas de entrega:'
        && $defaults['cart_delivery_methods'] === ''
        && $defaults['cart_payment_title'] === 'Métodos de pago:'
        && $defaults['cart_payment_note'] === 'Abonas al recibir tu pedido'
        && $defaults['cart_order_button_text'] === 'Enviar Pedido por WhatsApp'
        && $defaults['cart_continue_button_text'] === 'Seguir Comprando'
        && $defaults['cart_empty_title'] === 'Tu carrito está vacío'
        && $defaults['cart_empty_text'] === 'Agrega algunos productos para comenzar'
        && $defaults['cart_empty_button_text'] === 'Explorar productos'
        && $defaults['cart_available_text'] === 'Disponible'
        && $defaults['cart_stock_template'] === 'Solo {stock} disponibles'
        && $defaults['cart_registering_text'] === 'Registrando pedido...'
        && $defaults['cart_success_template'] === 'Pedido #{order_id} registrado. Te llevamos a WhatsApp para coordinarlo.'
        && $defaults['cart_reservation_text'] === 'El stock se reserva durante {minutes} minutos después de registrar el pedido.'
        && $defaults['order_whatsapp_template'] === "Hola {store_name}, quiero confirmar el pedido #{order_id}:\n\n{items}\n\nTotal: {total}"
        && $defaults['cart_layout'] === 'standard'
        && $defaults['cart_image_fit'] === 'cover'
        && $defaults['cart_image_size'] === 'normal'
        && $defaults['cart_show_images'] === '1'
        && $defaults['cart_show_sale_badge'] === '1'
        && $defaults['cart_show_old_price'] === '1'
        && $defaults['cart_show_stock_status'] === '1'
        && $defaults['cart_show_delivery_info'] === '1'
        && $defaults['cart_show_delivery_methods'] === '0'
        && $defaults['cart_show_payment_methods'] === '1'
        && $defaults['cart_show_reservation_note'] === '0'
        && $defaults['cart_summary_sticky'] === '0'
        && $defaults['cart_terms_enabled'] === '0'
        && $defaults['cart_terms_text'] === ''
        && $defaults['cart_terms_url'] === '',
        'CO-01',
        'defaults exactos'
    );

    $opts = checkout_display_select_options();
    cook(
        $opts['cart_layout'] === ['standard', 'compact']
        && $opts['cart_image_fit'] === ['contain', 'cover']
        && $opts['cart_image_size'] === ['compact', 'normal', 'large'],
        'CO-02',
        'allowlists de selects'
    );

    cook(
        resolve_checkout_display_settings([]) === $defaults,
        'CO-03',
        'resolve vacío usa defaults'
    );

    cook(validate_checkout_display_setting('cart_layout', 'neon') === null, 'CO-04', 'layout inválido');
    cook(validate_checkout_display_setting('cart_layout', 'compact') === 'compact', 'CO-04b', 'layout compact');
    cook(validate_checkout_display_setting('cart_image_fit', 'fill') === null, 'CO-05', 'fit inválido');
    cook(validate_checkout_display_setting('cart_image_size', 'huge') === null, 'CO-06', 'size inválido');

    $absent = collect_checkout_display_settings_from_post([]);
    cook(empty($absent['errors']) && $absent['values']['cart_show_images'] === '0', 'CO-07', 'booleano ausente → 0');
    cook($absent['values']['cart_show_payment_methods'] === '0', 'CO-07b', 'payment ausente → 0');

    $zero = collect_checkout_display_settings_from_post(copost(['cart_show_images' => '0']));
    cook(empty($zero['errors']) && $zero['values']['cart_show_images'] === '0', 'CO-08', '"0" → "0"');

    $one = collect_checkout_display_settings_from_post(copost(['cart_show_images' => '1']));
    cook(empty($one['errors']) && $one['values']['cart_show_images'] === '1', 'CO-09', '"1" → "1"');

    foreach (['yes', 'on', 'true', 'false', 'banana', 'TRUE', ''] as $bad) {
        $r = collect_checkout_display_settings_from_post(copost(['cart_show_images' => $bad]));
        cook(!empty($r['errors']) && !isset($r['values']['cart_show_images']), 'CO-10', "rechaza booleano <$bad>");
    }
    $arr = collect_checkout_display_settings_from_post(copost(['cart_show_images' => ['1']]));
    cook(!empty($arr['errors']) && !isset($arr['values']['cart_show_images']), 'CO-10b', 'array booleano → error');

    cook(validate_checkout_display_setting('cart_page_title', '<b>Hola</b>') === 'Hola', 'CO-11', 'HTML eliminado en textos');
    cook(validate_checkout_display_setting('cart_page_title', "a\0b") === 'ab' || validate_checkout_display_setting('cart_page_title', "a\0b") === 'a', 'CO-11b', 'null byte rechazado/sanitizado');
    $long = str_repeat('x', 200);
    cook(mb_strlen((string) validate_checkout_display_setting('cart_page_title', $long)) <= 80, 'CO-12', 'límite de texto');

    cook(parse_checkout_delivery_methods('Retiro, Envío') === ['Retiro', 'Envío'], 'CO-13', 'lista de métodos');
    cook(parse_checkout_delivery_methods('Retiro, Retiro') === null, 'CO-14', 'duplicados rechazados');
    cook(parse_checkout_delivery_methods('A,B,C,D,E,F,G,H,I') === null, 'CO-15', 'más de 8 rechazados');
    cook(parse_checkout_delivery_methods('<b>Retiro</b>, Envío') === ['Retiro', 'Envío'], 'CO-16', 'HTML en métodos');
    $showEmpty = collect_checkout_display_settings_from_post(copost([
        'cart_show_delivery_methods' => '1',
        'cart_delivery_methods' => '',
        'cart_show_images' => '1',
        'cart_show_sale_badge' => '1',
        'cart_show_old_price' => '1',
        'cart_show_stock_status' => '1',
        'cart_show_delivery_info' => '1',
        'cart_show_payment_methods' => '1',
    ]));
    cook(!empty($showEmpty['errors']), 'CO-17', 'mostrar métodos sin lista → error');

    cook(is_safe_checkout_terms_url('terminos.php') === true, 'CO-18', 'URL relativa segura');
    cook(is_safe_checkout_terms_url('javascript:alert(1)') === false, 'CO-19', 'javascript: rechazado');
    cook(is_safe_checkout_terms_url('data:text/html,x') === false, 'CO-20', 'data: rechazado');
    cook(is_safe_checkout_terms_url('//evil.example/x') === false, 'CO-21', 'protocol-relative rechazado');
    cook(is_safe_checkout_terms_url('https://evil.example/x') === false, 'CO-22', 'https absoluto rechazado');
    cook(validate_checkout_display_setting('cart_terms_url', 'javascript:alert(1)') === null, 'CO-22b', 'terms URL inválida');

    cook(
        validate_checkout_display_setting(
            'order_whatsapp_template',
            "Hola {store_name}, pedido #{order_id}:\n{items}\nTotal: {total}"
        ) !== null,
        'CO-23',
        'placeholders permitidos'
    );
    cook(validate_checkout_display_setting('order_whatsapp_template', 'Hola {foo} {order_id} {items} {total}') === null, 'CO-24', 'placeholder desconocido');
    cook(validate_checkout_display_setting('order_whatsapp_template', 'Hola {order_id} {items}') === null, 'CO-25', 'placeholder faltante');
    cook(validate_checkout_display_setting('order_whatsapp_template', '<script>{order_id}{items}{total}</script>') === null, 'CO-26', 'HTML en plantilla');
    cook(validate_checkout_display_setting('order_whatsapp_template', '{order_id}{items}{total}{order_id}') === null, 'CO-27', 'order_id repetido');
    cook(validate_checkout_display_setting('cart_stock_template', 'Quedan {stock}') === 'Quedan {stock}', 'CO-28', 'sustitución stock válida');
    cook(validate_checkout_display_setting('cart_stock_template', 'Quedan {qty}') === null, 'CO-28b', 'stock placeholder inválido');
    cook(validate_checkout_display_setting('cart_reservation_text', '{minutes} min') === '{minutes} min', 'CO-29', 'minutos válidos');
    cook(validate_checkout_display_setting('cart_reservation_text', 'sin placeholder') === null, 'CO-29b', 'minutos faltantes');
    cook(validate_checkout_display_setting('cart_success_template', 'OK #{order_id}') === 'OK #{order_id}', 'CO-30', 'success order_id');
    cook(validate_checkout_display_setting('cart_success_template', 'OK') === null, 'CO-30b', 'success sin order_id');

    $msg = checkout_build_whatsapp_message(
        [
            ['product_name' => "Notebook\nPro", 'unit_price' => 1000.5, 'quantity' => 2],
            ['product_name' => "Evil https://wa.me/evil\x00x", 'unit_price' => 10, 'quantity' => 1],
        ],
        42,
        array_merge($defaults, ['store_name' => 'CyberLeo Test', 'whatsapp_number' => '5491100000000'])
    );
    cook(is_string($msg) && str_contains($msg, 'pedido #42'), 'CO-31', 'render WhatsApp incluye order_id');
    cook(str_contains((string) $msg, 'Notebook Pro x 2 = $2.001,00'), 'CO-32', 'ítems y formato AR');
    cook(str_contains((string) $msg, 'Total: $2.011,00'), 'CO-33', 'total correcto');
    cook(!str_contains((string) $msg, "\nPro") && !str_contains((string) $msg, "\0"), 'CO-34', 'nombres maliciosos sanitizados');
    $url = checkout_build_whatsapp_url('5491100000000', (string) $msg);
    cook(str_starts_with($url, 'https://wa.me/5491100000000?text='), 'CO-35', 'URL wa.me + rawurlencode');

    $legacy = checkout_build_whatsapp_message(
        [['product_name' => 'Mouse', 'unit_price' => 50, 'quantity' => 1]],
        7,
        array_merge($defaults, ['store_name' => 'Tienda', 'whatsapp_number' => '54911'])
    );
    cook(
        $legacy === "Hola Tienda, quiero confirmar el pedido #7:\n\nMouse x 1 = \$50,00\n\nTotal: \$50,00",
        'CO-36',
        'mensaje default equivalente'
    );

    coreset($pdo);
    coset($pdo, 'brand_primary_color', '#abcdef');
    coset($pdo, 'footer_description', 'Footer Keep Stage2');
    coset($pdo, 'featured_columns', '4');
    coset($pdo, 'product_card_style', 'minimal');
    coset($pdo, 'whatsapp_number', '5491199999999');
    coset($pdo, 'payment_methods', 'Efectivo, Transferencia');
    coset($pdo, 'reservation_minutes', '90');
    coset($pdo, 'cart_layout', 'compact');
    coset($pdo, 'cart_page_title', 'Mi Carrito Alt');
    coset($pdo, 'cart_show_images', '0');
    $stockBefore = (int) $pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn();
    $nameBefore = (string) $pdo->query('SELECT name FROM products WHERE id=1')->fetchColumn();

    $r1 = restore_checkout_display_defaults($pdo);
    cook($r1['restored']['cart_layout'] === 'standard' && $r1['restored']['cart_page_title'] === 'Carrito de Compras', 'CO-37', 'restauración independiente');
    cook(coget($pdo, 'cart_layout') === 'standard', 'CO-37b', 'DB layout default');
    cook(coget($pdo, 'cart_show_images') === '1', 'CO-37c', 'DB show images default');

    $r2 = restore_checkout_display_defaults($pdo);
    cook($r2['restored'] === $defaults, 'CO-38', 'restauración idempotente');

    cook(coget($pdo, 'brand_primary_color') === '#abcdef', 'CO-39', 'no toca Etapa 1');
    cook(coget($pdo, 'footer_description') === 'Footer Keep Stage2', 'CO-40', 'no toca Etapa 2');
    cook(coget($pdo, 'featured_columns') === '4', 'CO-41', 'no toca Etapa 3');
    cook(coget($pdo, 'product_card_style') === 'minimal', 'CO-41b', 'no toca card style');
    cook(coget($pdo, 'whatsapp_number') === '5491199999999', 'CO-42', 'no toca WhatsApp');
    cook(coget($pdo, 'payment_methods') === 'Efectivo, Transferencia', 'CO-43', 'no toca payment_methods');
    cook(coget($pdo, 'reservation_minutes') === '90', 'CO-44', 'no toca reservation_minutes');
    $stockAfter = (int) $pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn();
    $nameAfter = (string) $pdo->query('SELECT name FROM products WHERE id=1')->fetchColumn();
    cook($stockAfter === $stockBefore && $nameAfter === $nameBefore, 'CO-45', 'no modifica productos/stock');

    cook(!array_key_exists('store_name', $defaults), 'CO-46', 'no duplica store_name en defaults');
    cook(!array_key_exists('whatsapp_number', $defaults), 'CO-46b', 'no duplica whatsapp_number');
    cook(!array_key_exists('payment_methods', $defaults), 'CO-46c', 'no duplica payment_methods');
    cook(!array_key_exists('reservation_minutes', $defaults), 'CO-46d', 'no duplica reservation_minutes');
    cook(!array_key_exists('product_sale_badge_text', $defaults), 'CO-46e', 'no duplica product_sale_badge_text');

    cook(checkout_format_money(1234.5) === '$1.234,50', 'CO-47', 'formato AR');
    cook(hash_file('sha256', dirname(__DIR__) . '/' . THEME_OFFICIAL_LOGO) === $officialHash, 'CO-48', 'SHA-256 logo oficial intacto');
    cook(hash_file('sha256', $official) === $officialHash, 'CO-48b', 'copia logo intacta');

    echo "checkout_display_settings_test: $passed assertions OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    corm($root);
    exit(1);
}
corm($root);
exit(0);
