<?php
require_once 'db.php';

function get_store_settings() {
    global $pdo;

    require_once __DIR__ . '/theme.php';
    require_once __DIR__ . '/home_content.php';
    $settings = array_merge([
        'store_name' => STORE_NAME,
        'whatsapp_number' => WHATSAPP_NUMBER,
        'instagram_url' => STORE_INSTAGRAM,
        'hero_title' => 'Tecnología para trabajar, estudiar y disfrutar',
        'hero_subtitle' => 'Encontrá notebooks, componentes y periféricos con stock actualizado.',
        'hero_background' => '',
        'body_background' => '',
        'reservation_minutes' => '120',
        'admin_email' => '',
        'mail_from' => '',
        'payment_methods' => 'Efectivo, Transferencia, Mercado Pago',
    ], theme_default_settings(), home_content_default_settings());
    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM store_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $emptyOk = array_flip(array_merge(
            theme_empty_allowed_keys(),
            theme_boolean_keys(),
            home_content_empty_allowed_keys(),
            home_content_boolean_keys()
        ));
        foreach ($rows as $key => $value) {
            if (!array_key_exists($key, $settings)) continue;
            if ($value === '' && !isset($emptyOk[$key])) continue;
            $settings[$key] = $value;
        }
    } catch (PDOException $e) {
        // La tienda sigue mostrando valores seguros por defecto hasta importar schema.sql.
        error_log($e->getMessage());
    }
    return $settings;
}

function get_theme_settings(): array {
    return resolve_theme_settings(get_store_settings());
}

function get_categories() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log($e->getMessage());
        return [];
    }
}

function get_products_by_category($category_id) {
    global $pdo;
    // Validar que category_id sea un entero positivo
    if (!is_int($category_id) || $category_id <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("SELECT id, name, description, price, price_sale, stock, image, is_active, category_id, subcategory_id FROM products WHERE category_id = ? AND is_active = 1");
        $stmt->execute([$category_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log($e->getMessage());
        return [];
    }
}

function format_price($price) {
    return '$' . number_format($price, 2, ',', '.');
}

function get_featured_products() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT p.*, c.name as category_name
        FROM products p
        JOIN categories c ON p.category_id = c.id
        WHERE p.destacados > 0 AND p.is_active = 1
        ORDER BY p.destacados ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_subcategories($category_id = null) {
    global $pdo;
    try {
        if ($category_id) {
            $stmt = $pdo->prepare("SELECT * FROM subcategories WHERE category_id = ? ORDER BY name");
            $stmt->execute([$category_id]);
        } else {
            $stmt = $pdo->query("SELECT * FROM subcategories ORDER BY category_id, name");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log($e->getMessage());
        return [];
    }
}

function get_products_by_subcategory($category_id, $subcategory_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id, name, description, price, price_sale, stock, image, is_active, category_id, subcategory_id FROM products WHERE category_id = ? AND subcategory_id = ? AND is_active = 1");
        $stmt->execute([$category_id, $subcategory_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log($e->getMessage());
        return [];
    }
}
