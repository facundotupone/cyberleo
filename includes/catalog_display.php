<?php
declare(strict_types=1);

/**
 * Stage 3 — catalog and product card display settings.
 */

/**
 * @return array<string,string>
 */
function catalog_display_default_settings(): array {
    return [
        'featured_section_title' => 'Productos Destacados',
        'featured_empty_text' => 'No hay productos destacados disponibles.',
        'catalog_empty_text' => 'No hay productos disponibles en esta categoría.',
        'featured_columns' => '3',
        'catalog_columns' => '3',
        'product_card_style' => 'elevated',
        'product_image_fit' => 'contain',
        'product_image_height' => 'normal',
        'product_card_alignment' => 'left',
        'product_description_mode' => 'expandable',
        'product_description_length' => '200',
        'product_show_category_badge' => '1',
        'product_show_stock' => '1',
        'product_show_sale_badge' => '1',
        'product_show_old_price' => '1',
        'product_sale_badge_text' => 'LIQUIDACIÓN',
        'product_show_share_buttons' => '1',
        'product_share_whatsapp' => '1',
        'product_share_facebook' => '1',
        'product_share_copy' => '1',
        'product_add_button_text' => 'Agregar al carrito',
        'product_out_of_stock_text' => 'Sin stock',
        'catalog_show_breadcrumbs' => '1',
        'catalog_show_product_count' => '1',
        'catalog_show_subcategory_filter' => '1',
    ];
}

/** @return list<string> */
function catalog_display_keys(): array {
    return array_keys(catalog_display_default_settings());
}

/** @return list<string> */
function catalog_display_boolean_keys(): array {
    return [
        'product_show_category_badge',
        'product_show_stock',
        'product_show_sale_badge',
        'product_show_old_price',
        'product_show_share_buttons',
        'product_share_whatsapp',
        'product_share_facebook',
        'product_share_copy',
        'catalog_show_breadcrumbs',
        'catalog_show_product_count',
        'catalog_show_subcategory_filter',
    ];
}

/** @return list<string> */
function catalog_display_empty_allowed_keys(): array {
    return [];
}

/** @return array<string,list<string>> */
function catalog_display_select_options(): array {
    return [
        'featured_columns' => ['2', '3', '4'],
        'catalog_columns' => ['2', '3', '4'],
        'product_card_style' => ['bordered', 'elevated', 'minimal'],
        'product_image_fit' => ['contain', 'cover'],
        'product_image_height' => ['compact', 'normal', 'large'],
        'product_card_alignment' => ['left', 'center'],
        'product_description_mode' => ['hidden', 'compact', 'expandable'],
        'product_description_length' => ['100', '160', '200', '300'],
    ];
}

function sanitize_catalog_plain_text(string $value, int $maxLen): string {
    if (function_exists('sanitize_theme_plain_text')) {
        return sanitize_theme_plain_text($value, $maxLen);
    }
    $value = trim(str_replace(["\0", "\r", "\n"], '', $value));
    $value = strip_tags($value);
    if (mb_strlen($value) > $maxLen) {
        $value = mb_substr($value, 0, $maxLen);
    }
    return $value;
}

function normalize_catalog_boolean($value): ?string {
    if (function_exists('normalize_theme_boolean')) {
        return normalize_theme_boolean($value);
    }
    if ($value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true') {
        return '1';
    }
    if ($value === false || $value === 0 || $value === '0' || $value === 'off' || $value === 'false' || $value === '') {
        return '0';
    }
    return null;
}

function validate_catalog_display_setting(string $key, $raw): ?string {
    $defaults = catalog_display_default_settings();
    if (!array_key_exists($key, $defaults)) {
        return null;
    }
    if (in_array($key, catalog_display_boolean_keys(), true)) {
        return normalize_catalog_boolean($raw);
    }
    if (!is_string($raw) && !is_numeric($raw)) {
        return null;
    }
    $value = trim((string) $raw);
    $options = catalog_display_select_options();
    if (isset($options[$key])) {
        return in_array($value, $options[$key], true) ? $value : null;
    }
    $limits = [
        'featured_section_title' => 80,
        'featured_empty_text' => 160,
        'catalog_empty_text' => 160,
        'product_sale_badge_text' => 30,
        'product_add_button_text' => 40,
        'product_out_of_stock_text' => 30,
    ];
    if (!isset($limits[$key])) {
        return null;
    }
    $text = sanitize_catalog_plain_text($value, $limits[$key]);
    return $text !== '' ? $text : null;
}

/**
 * @param array<string,mixed> $storeSettings
 * @return array<string,string>
 */
function resolve_catalog_display_settings(array $storeSettings): array {
    $catalog = catalog_display_default_settings();
    foreach ($catalog as $key => $default) {
        if (!array_key_exists($key, $storeSettings)) {
            continue;
        }
        $validated = validate_catalog_display_setting($key, $storeSettings[$key]);
        if ($validated !== null) {
            $catalog[$key] = $validated;
        }
    }
    return $catalog;
}

/**
 * @param array<string,mixed> $post
 * @return array{values:array<string,string>,errors:list<string>}
 */
function collect_catalog_display_settings_from_post(array $post): array {
    $values = [];
    $errors = [];
    $defaults = catalog_display_default_settings();

    foreach (catalog_display_boolean_keys() as $key) {
        $values[$key] = isset($post[$key]) ? '1' : '0';
    }

    foreach (catalog_display_select_options() as $key => $opts) {
        $validated = validate_catalog_display_setting($key, $post[$key] ?? $defaults[$key]);
        if ($validated === null) {
            $errors[] = "Opción inválida: {$key}.";
        } else {
            $values[$key] = $validated;
        }
    }

    $textKeys = [
        'featured_section_title' => 'Título de destacados',
        'featured_empty_text' => 'Mensaje vacío de destacados',
        'catalog_empty_text' => 'Mensaje vacío de catálogo',
        'product_sale_badge_text' => 'Texto de oferta',
        'product_add_button_text' => 'Texto del botón',
        'product_out_of_stock_text' => 'Texto sin stock',
    ];
    foreach ($textKeys as $key => $label) {
        $validated = validate_catalog_display_setting($key, $post[$key] ?? $defaults[$key]);
        if ($validated === null) {
            $errors[] = "{$label} inválido.";
        } else {
            $values[$key] = $validated;
        }
    }

    $allowed = array_flip(catalog_display_keys());
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
function restore_catalog_display_defaults(PDO $pdo): array {
    $defaults = catalog_display_default_settings();
    $upsert = $pdo->prepare(
        'INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($defaults as $key => $value) {
        $upsert->execute([$key, $value]);
    }
    return ['restored' => $defaults];
}

/**
 * Filter product image paths to safe local product images only.
 *
 * @param list<mixed> $images
 * @return list<string>
 */
function catalog_safe_product_images(array $images): array {
    $safe = [];
    foreach ($images as $image) {
        if (!is_string($image) || $image === '') {
            continue;
        }
        if (function_exists('is_safe_product_image_path') && is_safe_product_image_path($image)) {
            $safe[] = $image;
        }
    }
    return array_values(array_unique($safe));
}

function catalog_column_class(string $columns): string {
    $n = in_array($columns, ['2', '3', '4'], true) ? $columns : '3';
    return 'product-cols-' . $n;
}

function catalog_card_classes(array $catalog): string {
    $style = $catalog['product_card_style'] ?? 'elevated';
    $align = $catalog['product_card_alignment'] ?? 'left';
    $fit = $catalog['product_image_fit'] ?? 'contain';
    $height = $catalog['product_image_height'] ?? 'normal';
    if (!in_array($style, ['bordered', 'elevated', 'minimal'], true)) {
        $style = 'elevated';
    }
    if (!in_array($align, ['left', 'center'], true)) {
        $align = 'left';
    }
    if (!in_array($fit, ['contain', 'cover'], true)) {
        $fit = 'contain';
    }
    if (!in_array($height, ['compact', 'normal', 'large'], true)) {
        $height = 'normal';
    }
    return implode(' ', [
        'card',
        'product-card',
        'h-100',
        'product-card-' . $style,
        'product-card-align-' . $align,
        'product-fit-' . $fit,
        'product-height-' . $height,
    ]);
}
