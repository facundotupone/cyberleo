<?php
declare(strict_types=1);

/**
 * Stage 2 — administrable homepage content (announcement, promo, order, benefits, footer).
 * Visual identity remains in includes/theme.php.
 */

/**
 * @return array<string,string>
 */
function home_content_default_settings(): array {
    return [
        'announcement_enabled' => '0',
        'announcement_text' => '',
        'announcement_url' => '',
        'announcement_style' => 'primary',

        'promo_enabled' => '0',
        'promo_title' => '',
        'promo_text' => '',
        'promo_button_text' => 'Ver más',
        'promo_button_url' => '#',
        'promo_image' => '',
        'promo_position' => 'after_featured',

        'home_section_order' => 'featured,promo,categories,benefits',

        'benefits_enabled' => '1',
        'benefit_1_icon' => 'bi-truck',
        'benefit_1_title' => 'Envíos y entregas',
        'benefit_1_text' => 'Coordinamos la entrega o retiro de tu compra.',
        'benefit_2_icon' => 'bi-shield-check',
        'benefit_2_title' => 'Compra segura',
        'benefit_2_text' => 'Stock actualizado y pedido confirmado por WhatsApp.',
        'benefit_3_icon' => 'bi-headset',
        'benefit_3_title' => 'Atención personalizada',
        'benefit_3_text' => 'Te asesoramos para elegir la mejor opción.',

        'footer_description' => 'Tecnología, periféricos y soluciones para tu equipo.',
        'footer_instagram_text' => 'Seguinos en Instagram',
        'footer_whatsapp_text' => 'Contactar por WhatsApp',
        'footer_show_logo' => '1',
        'footer_show_instagram' => '1',
        'footer_show_whatsapp' => '1',
        'footer_show_business_hours' => '0',
        'business_hours' => '',
        'footer_show_location' => '0',
        'business_location' => '',
    ];
}

/**
 * @return list<string>
 */
function home_content_keys(): array {
    return array_keys(home_content_default_settings());
}

/**
 * @return list<string>
 */
function home_content_boolean_keys(): array {
    return [
        'announcement_enabled',
        'promo_enabled',
        'benefits_enabled',
        'footer_show_logo',
        'footer_show_instagram',
        'footer_show_whatsapp',
        'footer_show_business_hours',
        'footer_show_location',
    ];
}

/**
 * Keys that may be stored as empty and still override defaults.
 *
 * @return list<string>
 */
function home_content_empty_allowed_keys(): array {
    return [
        'announcement_text',
        'announcement_url',
        'promo_title',
        'promo_text',
        'promo_image',
        'business_hours',
        'business_location',
    ];
}

/**
 * @return list<string>
 */
function home_content_section_tokens(): array {
    return ['featured', 'promo', 'categories', 'benefits'];
}

/**
 * @return array<string,list<string>>
 */
function home_content_select_options(): array {
    return [
        'announcement_style' => ['primary', 'secondary', 'navy'],
        'promo_position' => ['before_featured', 'after_featured', 'before_categories', 'after_categories'],
        'benefit_1_icon' => home_content_icon_allowlist(),
        'benefit_2_icon' => home_content_icon_allowlist(),
        'benefit_3_icon' => home_content_icon_allowlist(),
    ];
}

/**
 * @return list<string>
 */
function home_content_icon_allowlist(): array {
    return [
        'bi-truck',
        'bi-shield-check',
        'bi-whatsapp',
        'bi-credit-card',
        'bi-headset',
        'bi-box-seam',
        'bi-lightning-charge',
        'bi-tools',
    ];
}

/**
 * Commercial keys restore must never wipe (also preserved by Stage 1 restore).
 *
 * @return list<string>
 */
function home_content_preserved_commercial_keys(): array {
    return [
        'store_name',
        'whatsapp_number',
        'instagram_url',
        'reservation_minutes',
        'admin_email',
        'mail_from',
        'payment_methods',
        'hero_title',
        'hero_subtitle',
        'hero_background',
        'body_background',
    ];
}

function is_safe_promo_image_path(?string $path): bool {
    return $path === '' || $path === null
        || (is_string($path) && function_exists('is_safe_settings_image_path') && is_safe_settings_image_path($path));
}

function sanitize_home_plain_text(string $value, int $maxLen): string {
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

function normalize_home_boolean($value): ?string {
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

function is_safe_local_home_url(string $value): bool {
    $value = trim($value);
    if ($value === '#') {
        return true;
    }
    if (function_exists('is_safe_local_theme_url')) {
        return is_safe_local_theme_url($value);
    }
    if ($value === '' || strlen($value) > 180) {
        return false;
    }
    if (preg_match('/[\x00-\x1F\x7F"\'<>\\\\]|[\r\n]/', $value)) {
        return false;
    }
    if (preg_match('#^(?:https?:|javascript:|data:|file:|vbscript:)#i', $value)) {
        return false;
    }
    if (str_starts_with($value, '//')) {
        return false;
    }
    if (str_contains($value, '..')) {
        return false;
    }
    if ($value[0] === '#') {
        return (bool) preg_match('/^#[A-Za-z][A-Za-z0-9_:-]{0,80}$/', $value);
    }
    return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._/?=&%-]{0,179}$#', $value);
}

/**
 * Validate a permutation of the four homepage section tokens.
 */
function validate_home_section_order($raw): ?string {
    if (!is_string($raw) && !is_numeric($raw)) {
        return null;
    }
    $parts = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $raw))), static fn($p) => $p !== ''));
    $allowed = home_content_section_tokens();
    if (count($parts) !== 4) {
        return null;
    }
    foreach ($parts as $part) {
        if (!in_array($part, $allowed, true)) {
            return null;
        }
    }
    if (count(array_unique($parts)) !== 4) {
        return null;
    }
    return implode(',', $parts);
}

/**
 * Build section order from four numeric ranks (1..4), one per token.
 *
 * @param array<string,mixed> $ranks token => rank
 */
function build_home_section_order_from_ranks(array $ranks): ?string {
    $tokens = home_content_section_tokens();
    $ordered = [];
    foreach ($tokens as $token) {
        if (!isset($ranks[$token])) {
            return null;
        }
        $rank = (int) $ranks[$token];
        if ($rank < 1 || $rank > 4) {
            return null;
        }
        if (isset($ordered[$rank])) {
            return null;
        }
        $ordered[$rank] = $token;
    }
    if (count($ordered) !== 4) {
        return null;
    }
    ksort($ordered, SORT_NUMERIC);
    return implode(',', array_values($ordered));
}

/**
 * Derive promo_position from a validated section order string.
 */
function derive_promo_position_from_order(string $order): string {
    $parts = explode(',', $order);
    $promoIdx = array_search('promo', $parts, true);
    $featuredIdx = array_search('featured', $parts, true);
    $categoriesIdx = array_search('categories', $parts, true);
    if ($promoIdx === false || $featuredIdx === false || $categoriesIdx === false) {
        return 'after_featured';
    }
    if ($promoIdx < $featuredIdx) {
        return 'before_featured';
    }
    if ($promoIdx > $featuredIdx && ($categoriesIdx === false || $promoIdx < $categoriesIdx)) {
        return 'after_featured';
    }
    if ($promoIdx < $categoriesIdx) {
        return 'before_categories';
    }
    return 'after_categories';
}

/**
 * Build order from legacy promo_position when home_section_order is missing/invalid.
 */
function home_section_order_from_promo_position(string $position): string {
    return match ($position) {
        'before_featured' => 'promo,featured,categories,benefits',
        'before_categories' => 'featured,promo,categories,benefits',
        'after_categories' => 'featured,categories,promo,benefits',
        default => 'featured,promo,categories,benefits',
    };
}

/**
 * Validate one Stage-2 key. Returns normalized string or null if invalid.
 */
function validate_home_content_setting(string $key, $raw): ?string {
    $defaults = home_content_default_settings();
    if (!array_key_exists($key, $defaults)) {
        return null;
    }

    if (in_array($key, home_content_boolean_keys(), true)) {
        return normalize_home_boolean($raw);
    }

    if (!is_string($raw) && !is_numeric($raw)) {
        return null;
    }
    $value = trim((string) $raw);

    $options = home_content_select_options();
    if (isset($options[$key])) {
        $value = strtolower($value);
        return in_array($value, $options[$key], true) ? $value : null;
    }

    if ($key === 'home_section_order') {
        return validate_home_section_order($value);
    }

    if ($key === 'promo_image') {
        if ($value === '') {
            return '';
        }
        return is_safe_promo_image_path($value) ? $value : null;
    }

    $limits = [
        'announcement_text' => 140,
        'announcement_url' => 180,
        'promo_title' => 100,
        'promo_text' => 240,
        'promo_button_text' => 60,
        'promo_button_url' => 180,
        'benefit_1_title' => 60,
        'benefit_1_text' => 180,
        'benefit_2_title' => 60,
        'benefit_2_text' => 180,
        'benefit_3_title' => 60,
        'benefit_3_text' => 180,
        'footer_description' => 180,
        'footer_instagram_text' => 60,
        'footer_whatsapp_text' => 60,
        'business_hours' => 140,
        'business_location' => 180,
    ];

    if ($key === 'announcement_url' || $key === 'promo_button_url') {
        if ($value === '' && $key === 'announcement_url') {
            return '';
        }
        return is_safe_local_home_url($value) ? $value : null;
    }

    if (isset($limits[$key])) {
        $text = sanitize_home_plain_text($value, $limits[$key]);
        if (in_array($key, home_content_empty_allowed_keys(), true)) {
            return $text;
        }
        if ($key === 'promo_button_text' && $text === '') {
            return null;
        }
        if (str_starts_with($key, 'benefit_') && str_ends_with($key, '_title') && $text === '') {
            return null;
        }
        if (str_starts_with($key, 'benefit_') && str_ends_with($key, '_text') && $text === '') {
            return null;
        }
        if ($key === 'footer_description' && $text === '') {
            return null;
        }
        if (($key === 'footer_instagram_text' || $key === 'footer_whatsapp_text') && $text === '') {
            return null;
        }
        return $text;
    }

    return null;
}

/**
 * @param array<string,mixed> $storeSettings
 * @return array<string,string>
 */
function resolve_home_content_settings(array $storeSettings): array {
    $home = home_content_default_settings();
    foreach ($home as $key => $default) {
        if (!array_key_exists($key, $storeSettings)) {
            continue;
        }
        $validated = validate_home_content_setting($key, $storeSettings[$key]);
        if ($validated !== null) {
            $home[$key] = $validated;
        }
    }

    $order = validate_home_section_order($home['home_section_order'] ?? '');
    if ($order === null) {
        $position = validate_home_content_setting('promo_position', $storeSettings['promo_position'] ?? $home['promo_position']);
        $home['home_section_order'] = home_section_order_from_promo_position($position ?? 'after_featured');
    } else {
        $home['home_section_order'] = $order;
        $home['promo_position'] = derive_promo_position_from_order($order);
    }

    if (!is_safe_promo_image_path($home['promo_image'])) {
        $home['promo_image'] = '';
    }

    return $home;
}

/**
 * @return list<string>
 */
function home_content_ordered_sections(array $home): array {
    $order = validate_home_section_order($home['home_section_order'] ?? '')
        ?? home_content_default_settings()['home_section_order'];
    return explode(',', $order);
}

/**
 * Rank map for admin selects (token => 1..4).
 *
 * @return array<string,int>
 */
function home_content_order_ranks(array $home): array {
    $ranks = [];
    foreach (home_content_ordered_sections($home) as $index => $token) {
        $ranks[$token] = $index + 1;
    }
    foreach (home_content_section_tokens() as $token) {
        if (!isset($ranks[$token])) {
            $ranks[$token] = 0;
        }
    }
    return $ranks;
}

/**
 * @param array<string,mixed> $post
 * @return array{values:array<string,string>,errors:list<string>}
 */
function collect_home_content_settings_from_post(array $post): array {
    $values = [];
    $errors = [];
    $defaults = home_content_default_settings();

    foreach (home_content_boolean_keys() as $key) {
        $values[$key] = isset($post[$key]) ? '1' : '0';
    }

    $style = validate_home_content_setting('announcement_style', $post['announcement_style'] ?? $defaults['announcement_style']);
    if ($style === null) {
        $errors[] = 'Estilo de aviso superior inválido.';
    } else {
        $values['announcement_style'] = $style;
    }

    $annText = validate_home_content_setting('announcement_text', $post['announcement_text'] ?? '');
    if ($annText === null) {
        $errors[] = 'Texto del aviso superior inválido.';
    } else {
        $values['announcement_text'] = $annText;
    }

    $annUrlRaw = trim((string) ($post['announcement_url'] ?? ''));
    if ($annUrlRaw === '') {
        $values['announcement_url'] = '';
    } else {
        $annUrl = validate_home_content_setting('announcement_url', $annUrlRaw);
        if ($annUrl === null) {
            $errors[] = 'Enlace del aviso superior inválido o no permitido.';
        } else {
            $values['announcement_url'] = $annUrl;
        }
    }

    if ($values['announcement_enabled'] === '1' && $values['announcement_text'] === '') {
        $errors[] = 'Activaste el aviso superior: completá el texto.';
    }

    foreach (['promo_title' => 100, 'promo_text' => 240] as $key => $_max) {
        $validated = validate_home_content_setting($key, $post[$key] ?? '');
        if ($validated === null) {
            $errors[] = "Campo inválido: {$key}.";
        } else {
            $values[$key] = $validated;
        }
    }

    $btnText = validate_home_content_setting('promo_button_text', $post['promo_button_text'] ?? $defaults['promo_button_text']);
    if ($btnText === null) {
        $errors[] = 'Texto del botón promocional inválido.';
    } else {
        $values['promo_button_text'] = $btnText;
    }

    $btnUrl = validate_home_content_setting('promo_button_url', $post['promo_button_url'] ?? $defaults['promo_button_url']);
    if ($btnUrl === null) {
        $errors[] = 'Enlace del botón promocional inválido o no permitido.';
    } else {
        $values['promo_button_url'] = $btnUrl;
    }

    $ranks = [];
    foreach (home_content_section_tokens() as $token) {
        $field = 'home_order_' . $token;
        $ranks[$token] = (int) ($post[$field] ?? 0);
    }
    $order = build_home_section_order_from_ranks($ranks);
    if ($order === null) {
        $errors[] = 'El orden de portada debe incluir las cuatro secciones sin duplicados (1 a 4).';
    } else {
        $values['home_section_order'] = $order;
        $values['promo_position'] = derive_promo_position_from_order($order);
    }

    // Optional explicit promo_position in POST (closed list); section order remains authoritative.
    if (isset($post['promo_position']) && is_string($post['promo_position']) && $post['promo_position'] !== '') {
        $pos = validate_home_content_setting('promo_position', $post['promo_position']);
        if ($pos === null) {
            $errors[] = 'Posición del banner promocional inválida.';
        }
    }

    if (($values['promo_enabled'] ?? '0') === '1' && ($values['promo_title'] ?? '') === '' && ($values['promo_text'] ?? '') === '') {
        $errors[] = 'Activaste el banner promocional: completá al menos título o texto.';
    }

    for ($i = 1; $i <= 3; $i++) {
        $iconKey = "benefit_{$i}_icon";
        $titleKey = "benefit_{$i}_title";
        $textKey = "benefit_{$i}_text";
        $icon = validate_home_content_setting($iconKey, $post[$iconKey] ?? $defaults[$iconKey]);
        if ($icon === null) {
            $errors[] = "Ícono de beneficio {$i} no permitido.";
        } else {
            $values[$iconKey] = $icon;
        }
        $title = validate_home_content_setting($titleKey, $post[$titleKey] ?? $defaults[$titleKey]);
        if ($title === null) {
            $errors[] = "Título de beneficio {$i} inválido.";
        } else {
            $values[$titleKey] = $title;
        }
        $text = validate_home_content_setting($textKey, $post[$textKey] ?? $defaults[$textKey]);
        if ($text === null) {
            $errors[] = "Texto de beneficio {$i} inválido.";
        } else {
            $values[$textKey] = $text;
        }
    }

    $footerDesc = validate_home_content_setting('footer_description', $post['footer_description'] ?? $defaults['footer_description']);
    if ($footerDesc === null) {
        $errors[] = 'Descripción del footer inválida.';
    } else {
        $values['footer_description'] = $footerDesc;
    }

    $igText = validate_home_content_setting('footer_instagram_text', $post['footer_instagram_text'] ?? $defaults['footer_instagram_text']);
    if ($igText === null) {
        $errors[] = 'Texto de Instagram del footer inválido.';
    } else {
        $values['footer_instagram_text'] = $igText;
    }

    $waText = validate_home_content_setting('footer_whatsapp_text', $post['footer_whatsapp_text'] ?? $defaults['footer_whatsapp_text']);
    if ($waText === null) {
        $errors[] = 'Texto de WhatsApp del footer inválido.';
    } else {
        $values['footer_whatsapp_text'] = $waText;
    }

    $hours = validate_home_content_setting('business_hours', $post['business_hours'] ?? '');
    if ($hours === null) {
        $errors[] = 'Horarios inválidos.';
    } else {
        $values['business_hours'] = $hours;
    }

    $location = validate_home_content_setting('business_location', $post['business_location'] ?? '');
    if ($location === null) {
        $errors[] = 'Ubicación inválida.';
    } else {
        $values['business_location'] = $location;
    }

    if (($values['footer_show_business_hours'] ?? '0') === '1' && ($values['business_hours'] ?? '') === '') {
        $errors[] = 'Activaste horarios en el footer: completá el texto.';
    }
    if (($values['footer_show_location'] ?? '0') === '1' && ($values['business_location'] ?? '') === '') {
        $errors[] = 'Activaste ubicación en el footer: completá el texto.';
    }

    // Never accept arbitrary keys from POST into values.
    $allowed = array_flip(home_content_keys());
    foreach (array_keys($values) as $key) {
        if (!isset($allowed[$key])) {
            unset($values[$key]);
        }
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * Restore only Stage-2 keys. Preserves Stage-1 identity and commercial data.
 *
 * @return array{restored:array<string,string>,cleanup_candidates:list<string>}
 */
function restore_home_content_defaults(PDO $pdo): array {
    $defaults = home_content_default_settings();
    $select = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key = ? FOR UPDATE');
    $select->execute(['promo_image']);
    $oldPromo = (string) ($select->fetchColumn() ?: '');

    $upsert = $pdo->prepare(
        'INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($defaults as $key => $value) {
        $upsert->execute([$key, $value]);
    }

    $cleanup = [];
    if ($oldPromo !== '' && $oldPromo !== ($defaults['promo_image'] ?? '') && function_exists('is_safe_settings_image_path') && is_safe_settings_image_path($oldPromo)) {
        $cleanup[] = $oldPromo;
    }

    return [
        'restored' => $defaults,
        'cleanup_candidates' => array_values(array_unique($cleanup)),
    ];
}
