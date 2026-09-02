<?php
declare(strict_types=1);

/**
 * Stage 4 — cart, checkout summary and WhatsApp order message display.
 */

/**
 * @return array<string,string>
 */
function checkout_display_default_settings(): array {
    return [
        'cart_page_title' => 'Carrito de Compras',
        'cart_items_title' => 'Productos en tu carrito',
        'cart_summary_title' => 'Resumen del pedido',
        'cart_total_label' => 'Total:',
        'cart_delivery_title' => 'Información de envío',
        'cart_delivery_text' => 'Envíanos tu consulta y te responderemos a la brevedad para coordinar envío o retiro.',
        'cart_delivery_methods_title' => 'Formas de entrega:',
        'cart_delivery_methods' => '',
        'cart_payment_title' => 'Métodos de pago:',
        'cart_payment_note' => 'Abonas al recibir tu pedido',
        'cart_order_button_text' => 'Enviar Pedido por WhatsApp',
        'cart_continue_button_text' => 'Seguir Comprando',
        'cart_empty_title' => 'Tu carrito está vacío',
        'cart_empty_text' => 'Agrega algunos productos para comenzar',
        'cart_empty_button_text' => 'Explorar productos',
        'cart_available_text' => 'Disponible',
        'cart_stock_template' => 'Solo {stock} disponibles',
        'cart_registering_text' => 'Registrando pedido...',
        'cart_success_template' => 'Pedido #{order_id} registrado. Te llevamos a WhatsApp para coordinarlo.',
        'cart_reservation_text' => 'El stock se reserva durante {minutes} minutos después de registrar el pedido.',
        'order_whatsapp_template' => "Hola {store_name}, quiero confirmar el pedido #{order_id}:\n\n{items}\n\nTotal: {total}",
        'cart_layout' => 'standard',
        'cart_image_fit' => 'cover',
        'cart_image_size' => 'normal',
        'cart_show_images' => '1',
        'cart_show_sale_badge' => '1',
        'cart_show_old_price' => '1',
        'cart_show_stock_status' => '1',
        'cart_show_delivery_info' => '1',
        'cart_show_delivery_methods' => '0',
        'cart_show_payment_methods' => '1',
        'cart_show_reservation_note' => '0',
        'cart_summary_sticky' => '0',
        'cart_terms_enabled' => '0',
        'cart_terms_text' => '',
        'cart_terms_url' => '',
    ];
}

/** @return list<string> */
function checkout_display_keys(): array {
    return array_keys(checkout_display_default_settings());
}

/** @return list<string> */
function checkout_display_boolean_keys(): array {
    return [
        'cart_show_images',
        'cart_show_sale_badge',
        'cart_show_old_price',
        'cart_show_stock_status',
        'cart_show_delivery_info',
        'cart_show_delivery_methods',
        'cart_show_payment_methods',
        'cart_show_reservation_note',
        'cart_summary_sticky',
        'cart_terms_enabled',
    ];
}

/** @return list<string> */
function checkout_display_empty_allowed_keys(): array {
    return [
        'cart_delivery_methods',
        'cart_terms_text',
        'cart_terms_url',
    ];
}

/** @return array<string,list<string>> */
function checkout_display_select_options(): array {
    return [
        'cart_layout' => ['standard', 'compact'],
        'cart_image_fit' => ['contain', 'cover'],
        'cart_image_size' => ['compact', 'normal', 'large'],
    ];
}

/** @return array<string,int> */
function checkout_display_text_limits(): array {
    return [
        'cart_page_title' => 80,
        'cart_items_title' => 80,
        'cart_summary_title' => 80,
        'cart_total_label' => 40,
        'cart_delivery_title' => 80,
        'cart_delivery_text' => 280,
        'cart_delivery_methods_title' => 80,
        'cart_delivery_methods' => 500,
        'cart_payment_title' => 80,
        'cart_payment_note' => 160,
        'cart_order_button_text' => 60,
        'cart_continue_button_text' => 60,
        'cart_empty_title' => 80,
        'cart_empty_text' => 160,
        'cart_empty_button_text' => 60,
        'cart_available_text' => 40,
        'cart_stock_template' => 80,
        'cart_registering_text' => 80,
        'cart_success_template' => 180,
        'cart_reservation_text' => 200,
        'order_whatsapp_template' => 800,
        'cart_terms_text' => 280,
        'cart_terms_url' => 180,
    ];
}

function sanitize_checkout_plain_text(string $value, int $maxLen, bool $allowNewlines = false): string {
    $value = str_replace("\0", '', $value);
    if ($allowNewlines) {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    } else {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        $value = str_replace(["\r", "\n"], '', $value);
    }
    $value = strip_tags($value);
    $value = trim($value);
    if (mb_strlen($value) > $maxLen) {
        $value = mb_substr($value, 0, $maxLen);
    }
    return $value;
}

function normalize_checkout_boolean($value): ?string {
    if ($value === '1' || $value === 1 || $value === true) {
        return '1';
    }
    if ($value === '0' || $value === 0 || $value === false || $value === '') {
        return '0';
    }
    return null;
}

/**
 * @return list<string>|null
 */
function parse_checkout_delivery_methods(string $raw): ?array {
    $raw = sanitize_checkout_plain_text($raw, 500);
    if ($raw === '') {
        return [];
    }
    $parts = array_map('trim', explode(',', $raw));
    $clean = [];
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $part = sanitize_checkout_plain_text($part, 50);
        if ($part === '') {
            continue;
        }
        if (mb_strlen($part) > 50) {
            return null;
        }
        $clean[] = $part;
    }
    $unique = [];
    foreach ($clean as $item) {
        $key = mb_strtolower($item);
        if (isset($unique[$key])) {
            return null; // duplicates rejected
        }
        $unique[$key] = $item;
    }
    $list = array_values($unique);
    if (count($list) > 8) {
        return null;
    }
    return $list;
}

function format_checkout_delivery_methods_list(array $methods): string {
    return implode(', ', $methods);
}

function is_safe_checkout_terms_url(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return true;
    }
    if (function_exists('is_safe_local_theme_url')) {
        return is_safe_local_theme_url($value);
    }
    if (strlen($value) > 180) {
        return false;
    }
    if (preg_match('/[\x00-\x1F\x7F"\'<>\\\\]|[\r\n]/', $value)) {
        return false;
    }
    if (preg_match('#^(?:https?:|javascript:|data:|file:|vbscript:)#i', $value)) {
        return false;
    }
    if (str_starts_with($value, '//') || str_contains($value, '..')) {
        return false;
    }
    return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._/?=&%-]{0,179}$#', $value);
}

/**
 * Validate WhatsApp / stock / reservation / success templates.
 *
 * @param list<string> $required
 * @param list<string> $allowed
 */
function validate_checkout_template(string $template, array $allowed, array $required, int $maxLen): ?string {
    $template = str_replace("\0", '', $template);
    $template = str_replace(["\r\n", "\r"], "\n", $template);
    if (preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $template)) {
        return null;
    }
    if (strip_tags($template) !== $template) {
        return null;
    }
    $template = trim($template);
    if ($template === '' || mb_strlen($template) > $maxLen) {
        return null;
    }
    if (preg_match('/\{[^}]*$/', $template) || preg_match('/^[^{]*\}/', $template)) {
        return null;
    }
    preg_match_all('/\{([a-z_]+)\}/', $template, $matches);
    $found = $matches[1] ?? [];
    $counts = array_count_values($found);
    foreach ($counts as $name => $count) {
        if (!in_array($name, $allowed, true)) {
            return null;
        }
        if ($count > 3) {
            return null; // abusive repetition
        }
    }
    foreach ($required as $need) {
        if (($counts[$need] ?? 0) !== 1) {
            return null;
        }
    }
    // Reject any leftover brace sequences that aren't valid placeholders
    $stripped = preg_replace('/\{(?:' . implode('|', array_map('preg_quote', $allowed)) . ')\}/', '', $template);
    if ($stripped !== null && (str_contains($stripped, '{') || str_contains($stripped, '}'))) {
        return null;
    }
    return $template;
}

function validate_checkout_display_setting(string $key, $raw): ?string {
    $defaults = checkout_display_default_settings();
    if (!array_key_exists($key, $defaults)) {
        return null;
    }
    if (in_array($key, checkout_display_boolean_keys(), true)) {
        return normalize_checkout_boolean($raw);
    }
    if (!is_string($raw) && !is_numeric($raw)) {
        return null;
    }
    $value = is_string($raw) ? $raw : (string) $raw;
    $options = checkout_display_select_options();
    if (isset($options[$key])) {
        $value = trim($value);
        return in_array($value, $options[$key], true) ? $value : null;
    }
    if ($key === 'cart_delivery_methods') {
        $parsed = parse_checkout_delivery_methods($value);
        return $parsed === null ? null : format_checkout_delivery_methods_list($parsed);
    }
    if ($key === 'cart_terms_url') {
        $value = trim(str_replace(["\0", "\r", "\n"], '', $value));
        if ($value === '') {
            return '';
        }
        return is_safe_checkout_terms_url($value) ? mb_substr($value, 0, 180) : null;
    }
    if ($key === 'order_whatsapp_template') {
        return validate_checkout_template(
            $value,
            ['store_name', 'order_id', 'items', 'total'],
            ['order_id', 'items', 'total'],
            800
        );
    }
    if ($key === 'cart_stock_template') {
        return validate_checkout_template($value, ['stock'], ['stock'], 80);
    }
    if ($key === 'cart_reservation_text') {
        return validate_checkout_template($value, ['minutes'], ['minutes'], 200);
    }
    if ($key === 'cart_success_template') {
        return validate_checkout_template($value, ['order_id'], ['order_id'], 180);
    }
    $limits = checkout_display_text_limits();
    if (!isset($limits[$key])) {
        return null;
    }
    $allowNl = in_array($key, ['cart_delivery_text', 'cart_terms_text'], true);
    $text = sanitize_checkout_plain_text($value, $limits[$key], $allowNl);
    if ($text === '' && !in_array($key, checkout_display_empty_allowed_keys(), true)) {
        return null;
    }
    return $text;
}

/**
 * @param array<string,mixed> $storeSettings
 * @return array<string,string>
 */
function resolve_checkout_display_settings(array $storeSettings): array {
    $checkout = checkout_display_default_settings();
    foreach ($checkout as $key => $default) {
        if (!array_key_exists($key, $storeSettings)) {
            continue;
        }
        $validated = validate_checkout_display_setting($key, $storeSettings[$key]);
        if ($validated !== null) {
            $checkout[$key] = $validated;
        }
    }
    return $checkout;
}

/**
 * @param array<string,mixed> $post
 * @return array{values:array<string,string>,errors:list<string>}
 */
function collect_checkout_display_settings_from_post(array $post): array {
    $values = [];
    $errors = [];
    $defaults = checkout_display_default_settings();

    foreach (checkout_display_boolean_keys() as $key) {
        if (!array_key_exists($key, $post)) {
            $values[$key] = '0';
            continue;
        }
        $raw = $post[$key];
        if ($raw === '0') {
            $values[$key] = '0';
        } elseif ($raw === '1') {
            $values[$key] = '1';
        } else {
            $errors[] = "Booleano inválido: {$key}.";
        }
    }

    foreach (checkout_display_select_options() as $key => $_opts) {
        $validated = validate_checkout_display_setting($key, $post[$key] ?? $defaults[$key]);
        if ($validated === null) {
            $errors[] = "Opción inválida: {$key}.";
        } else {
            $values[$key] = $validated;
        }
    }

    $textKeys = [
        'cart_page_title' => 'Título del carrito',
        'cart_items_title' => 'Título de productos',
        'cart_summary_title' => 'Título del resumen',
        'cart_total_label' => 'Etiqueta de total',
        'cart_delivery_title' => 'Título de envío',
        'cart_delivery_text' => 'Texto de envío',
        'cart_delivery_methods_title' => 'Título de formas de entrega',
        'cart_delivery_methods' => 'Formas de entrega',
        'cart_payment_title' => 'Título de pagos',
        'cart_payment_note' => 'Nota de pagos',
        'cart_order_button_text' => 'Texto del botón de pedido',
        'cart_continue_button_text' => 'Texto de seguir comprando',
        'cart_empty_title' => 'Título de carrito vacío',
        'cart_empty_text' => 'Texto de carrito vacío',
        'cart_empty_button_text' => 'Botón de carrito vacío',
        'cart_available_text' => 'Texto disponible',
        'cart_stock_template' => 'Plantilla de stock',
        'cart_registering_text' => 'Texto registrando',
        'cart_success_template' => 'Plantilla de éxito',
        'cart_reservation_text' => 'Texto de reserva',
        'order_whatsapp_template' => 'Plantilla de WhatsApp',
        'cart_terms_text' => 'Texto de términos',
        'cart_terms_url' => 'URL de términos',
    ];
    foreach ($textKeys as $key => $label) {
        $raw = $post[$key] ?? $defaults[$key];
        $validated = validate_checkout_display_setting($key, $raw);
        if ($validated === null) {
            $errors[] = "{$label} inválido.";
        } else {
            $values[$key] = $validated;
        }
    }

    if (($values['cart_show_delivery_methods'] ?? '0') === '1') {
        $methods = parse_checkout_delivery_methods($values['cart_delivery_methods'] ?? '');
        if ($methods === null || $methods === []) {
            $errors[] = 'Formas de entrega inválidas o vacías.';
            unset($values['cart_delivery_methods']);
        }
    }

    if (($values['cart_terms_enabled'] ?? '0') === '1') {
        $termsText = trim((string) ($values['cart_terms_text'] ?? ''));
        $termsUrl = trim((string) ($values['cart_terms_url'] ?? ''));
        if ($termsText === '' && $termsUrl === '') {
            $errors[] = 'Completá el texto o la URL de las condiciones comerciales.';
        }
    }

    $allowed = array_flip(checkout_display_keys());
    foreach (array_keys($values) as $key) {
        if (!isset($allowed[$key])) {
            unset($values[$key]);
        }
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * @return array{restored:array<string,string>}
 */
function restore_checkout_display_defaults(PDO $pdo): array {
    $defaults = checkout_display_default_settings();
    $upsert = $pdo->prepare(
        'INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($defaults as $key => $value) {
        $upsert->execute([$key, $value]);
    }
    return ['restored' => $defaults];
}

function checkout_format_money($amount): string {
    return '$' . number_format((float) $amount, 2, ',', '.');
}

function checkout_sanitize_product_name_for_whatsapp(string $name): string {
    $name = str_replace("\0", '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name);
    if ($name === '') {
        $name = 'Producto';
    }
    if (mb_strlen($name) > 120) {
        $name = mb_substr($name, 0, 120);
    }
    return $name;
}

/**
 * Apply {placeholder} substitutions as plain text (no HTML).
 *
 * @param array<string,string> $vars
 */
function checkout_apply_template(string $template, array $vars): string {
    $out = $template;
    foreach ($vars as $key => $value) {
        $out = str_replace('{' . $key . '}', $value, $out);
    }
    return $out;
}

/**
 * Build WhatsApp message body from persisted order items and checkout template.
 *
 * @param list<array{product_name:string,unit_price:mixed,quantity:mixed}> $items
 * @param array<string,mixed> $settings store settings (must include store_name + resolved checkout keys or defaults)
 */
function checkout_build_whatsapp_message(array $items, $orderId, array $settings): ?string {
    if ($items === []) {
        return null;
    }
    $checkout = resolve_checkout_display_settings($settings);
    $template = $checkout['order_whatsapp_template']
        ?? checkout_display_default_settings()['order_whatsapp_template'];
    $lines = [];
    $total = 0.0;
    foreach ($items as $item) {
        $qty = (int) ($item['quantity'] ?? 0);
        $unit = (float) ($item['unit_price'] ?? 0);
        if ($qty < 1) {
            continue;
        }
        $subtotal = $unit * $qty;
        $total += $subtotal;
        $name = checkout_sanitize_product_name_for_whatsapp((string) ($item['product_name'] ?? 'Producto'));
        $lines[] = $name . ' x ' . $qty . ' = ' . checkout_format_money($subtotal);
    }
    if ($lines === []) {
        return null;
    }
    $storeName = sanitize_checkout_plain_text((string) ($settings['store_name'] ?? 'Tienda'), 80);
    if ($storeName === '') {
        $storeName = 'Tienda';
    }
    return checkout_apply_template($template, [
        'store_name' => $storeName,
        'order_id' => (string) (int) $orderId,
        'items' => implode("\n", $lines),
        'total' => checkout_format_money($total),
    ]);
}

/**
 * @param array<string,mixed> $settings
 */
function checkout_build_whatsapp_url(string $whatsappNumber, string $message): string {
    $digits = preg_replace('/\D+/', '', $whatsappNumber) ?? '';
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
}
