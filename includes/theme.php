<?php
declare(strict_types=1);

const THEME_OFFICIAL_LOGO = 'assets/images/brand/cyberleo-logo.png';

/**
 * Closed allowlist of visual identity keys managed in Stage 1.
 *
 * @return array<string,string> key => default value
 */
function theme_default_settings(): array {
    return [
        'brand_primary_color' => '#0057b8',
        'brand_secondary_color' => '#00aeef',
        'brand_navy_color' => '#071a33',
        'brand_background_color' => '#f3f8fc',
        'brand_text_color' => '#111827',
        'brand_logo' => THEME_OFFICIAL_LOGO,
        'brand_favicon' => '',
        'brand_font' => 'system',
        'nav_style' => 'white',
        'button_radius' => 'medium',
        'card_radius' => 'medium',
        'hero_button_text' => 'Explorar catálogo',
        'hero_button_url' => '#productos-destacados',
        'hero_height' => 'normal',
        'hero_alignment' => 'center',
        'hero_overlay' => 'medium',
        'show_search' => '1',
        'show_categories' => '1',
        'show_featured_products' => '1',
    ];
}

/**
 * Keys that may be stored as empty string and still override defaults.
 *
 * @return list<string>
 */
function theme_empty_allowed_keys(): array {
    return ['brand_favicon', 'hero_background', 'body_background'];
}

/**
 * @return list<string>
 */
function theme_boolean_keys(): array {
    return ['show_search', 'show_categories', 'show_featured_products'];
}

/**
 * @return list<string>
 */
function theme_visual_keys(): array {
    return array_keys(theme_default_settings());
}

/**
 * Commercial / operational keys that restore must never wipe.
 *
 * @return list<string>
 */
function theme_preserved_commercial_keys(): array {
    return [
        'store_name',
        'whatsapp_number',
        'instagram_url',
        'reservation_minutes',
        'admin_email',
        'mail_from',
        'payment_methods',
        'body_background',
    ];
}

/**
 * @return array<string,list<string>>
 */
function theme_select_options(): array {
    return [
        'brand_font' => ['system', 'inter', 'montserrat', 'poppins'],
        'nav_style' => ['white', 'navy'],
        'button_radius' => ['low', 'medium', 'high'],
        'card_radius' => ['low', 'medium', 'high'],
        'hero_height' => ['compact', 'normal', 'large'],
        'hero_alignment' => ['left', 'center'],
        'hero_overlay' => ['soft', 'medium', 'strong'],
    ];
}

function theme_font_stack(string $font): string {
    return match ($font) {
        'inter' => 'Inter, system-ui, sans-serif',
        'montserrat' => 'Montserrat, Arial, sans-serif',
        'poppins' => 'Poppins, Arial, sans-serif',
        default => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    };
}

function theme_radius_css(string $level, string $kind): string {
    $map = [
        'button' => ['low' => '6px', 'medium' => '8px', 'high' => '16px'],
        'card' => ['low' => '8px', 'medium' => '12px', 'high' => '20px'],
    ];
    return $map[$kind][$level] ?? $map[$kind]['medium'];
}

function is_valid_theme_hex_color(string $value): bool {
    return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $value);
}

function normalize_theme_hex_color(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    if ($value[0] !== '#') $value = '#' . $value;
    if (!is_valid_theme_hex_color($value)) return null;
    return strtolower($value);
}

function is_safe_local_theme_url(string $value): bool {
    $value = trim($value);
    if ($value === '' || strlen($value) > 180) return false;
    if (preg_match('/[\x00-\x1F\x7F"\'<>\\\\]|[\r\n]/', $value)) return false;
    if (preg_match('#^(?:https?:|javascript:|data:|file:|vbscript:)#i', $value)) return false;
    if (str_starts_with($value, '//')) return false;
    if (str_contains($value, '..')) return false;
    if ($value[0] === '#') {
        return (bool) preg_match('/^#[A-Za-z][A-Za-z0-9_:-]{0,80}$/', $value);
    }
    return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._/?=&%-]{0,179}$#', $value);
}

/**
 * Keys that may be written into store_settings from the settings panel.
 *
 * @return list<string>
 */
function theme_allowed_setting_keys(): array {
    return array_values(array_unique(array_merge(
        array_keys(theme_default_settings()),
        [
            'store_name',
            'whatsapp_number',
            'instagram_url',
            'hero_title',
            'hero_subtitle',
            'hero_background',
            'body_background',
            'reservation_minutes',
            'admin_email',
            'mail_from',
            'payment_methods',
        ]
    )));
}

function is_safe_official_brand_logo_path(?string $path): bool {
    return is_string($path) && $path === THEME_OFFICIAL_LOGO;
}

function is_safe_brand_logo_path(?string $path): bool {
    return is_safe_official_brand_logo_path($path)
        || (is_string($path) && (bool) preg_match('#^assets/images/settings/[a-f0-9]{32}\.png$#i', $path));
}

function is_safe_brand_favicon_path(?string $path): bool {
    return $path === '' || $path === null
        || (is_string($path) && (bool) preg_match('#^assets/images/settings/[a-f0-9]{32}\.png$#i', $path));
}

function sanitize_theme_plain_text(string $value, int $maxLen): string {
    $value = trim(str_replace(["\0", "\r", "\n"], '', $value));
    $value = strip_tags($value);
    if (mb_strlen($value) > $maxLen) $value = mb_substr($value, 0, $maxLen);
    return $value;
}

function normalize_theme_boolean($value): ?string {
    if ($value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'true') return '1';
    if ($value === false || $value === 0 || $value === '0' || $value === 'off' || $value === 'false' || $value === '') return '0';
    return null;
}

/**
 * Validate one visual key. Returns normalized string or null if invalid.
 */
function validate_theme_setting(string $key, $raw): ?string {
    $defaults = theme_default_settings();
    if (!array_key_exists($key, $defaults)) return null;

    if (in_array($key, theme_boolean_keys(), true)) {
        return normalize_theme_boolean($raw);
    }

    if (!is_string($raw) && !is_numeric($raw)) return null;
    $value = trim((string) $raw);

    if (str_ends_with($key, '_color')) {
        return normalize_theme_hex_color($value);
    }

    $options = theme_select_options();
    if (isset($options[$key])) {
        $value = strtolower($value);
        return in_array($value, $options[$key], true) ? $value : null;
    }

    if ($key === 'brand_logo') {
        return is_safe_brand_logo_path($value) ? $value : null;
    }
    if ($key === 'brand_favicon') {
        if ($value === '') return '';
        return is_safe_brand_favicon_path($value) ? $value : null;
    }
    if ($key === 'hero_button_text') {
        $text = sanitize_theme_plain_text($value, 60);
        return $text !== '' ? $text : null;
    }
    if ($key === 'hero_button_url') {
        return is_safe_local_theme_url($value) ? $value : null;
    }
    return null;
}

/**
 * Merge store settings with validated theme values. Invalid DB values fall back
 * to defaults without rewriting the database.
 *
 * @param array<string,mixed> $storeSettings
 * @return array<string,string>
 */
function resolve_theme_settings(array $storeSettings): array {
    $theme = theme_default_settings();
    foreach ($theme as $key => $default) {
        if (!array_key_exists($key, $storeSettings)) continue;
        $validated = validate_theme_setting($key, $storeSettings[$key]);
        if ($validated !== null) $theme[$key] = $validated;
    }
    if (!is_safe_brand_logo_path($theme['brand_logo']) || $theme['brand_logo'] === '') {
        $theme['brand_logo'] = THEME_OFFICIAL_LOGO;
    }
    return $theme;
}

/**
 * Relative luminance 0..1 for #RRGGBB.
 */
function theme_relative_luminance(string $hex): float {
    $hex = ltrim(strtolower($hex), '#');
    $channels = [];
    for ($i = 0; $i < 3; $i++) {
        $c = hexdec(substr($hex, $i * 2, 2)) / 255;
        $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function theme_contrast_ratio(string $hexA, string $hexB): float {
    $l1 = theme_relative_luminance($hexA);
    $l2 = theme_relative_luminance($hexB);
    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    return ($lighter + 0.05) / ($darker + 0.05);
}

/**
 * @param array<string,string> $theme
 * @return list<string>
 */
function theme_contrast_warnings(array $theme): array {
    $warnings = [];
    $textBg = theme_contrast_ratio($theme['brand_text_color'], $theme['brand_background_color']);
    if ($textBg < 4.5) {
        $warnings[] = 'El contraste entre el texto principal y el fondo es bajo (' . number_format($textBg, 2) . ':1).';
    }
    $btn = theme_contrast_ratio('#ffffff', $theme['brand_primary_color']);
    if ($btn < 3.0) {
        $warnings[] = 'El contraste del botón principal con texto blanco es bajo (' . number_format($btn, 2) . ':1).';
    }
    if ($theme['nav_style'] === 'navy') {
        $nav = theme_contrast_ratio('#ffffff', $theme['brand_navy_color']);
        if ($nav < 4.5) {
            $warnings[] = 'El contraste de la navegación navy con texto claro es bajo (' . number_format($nav, 2) . ':1).';
        }
    } else {
        $nav = theme_contrast_ratio($theme['brand_navy_color'], '#ffffff');
        if ($nav < 4.5) {
            $warnings[] = 'El contraste de enlaces sobre navegación blanca es bajo (' . number_format($nav, 2) . ':1).';
        }
    }
    $hero = theme_contrast_ratio('#ffffff', $theme['brand_navy_color']);
    if ($hero < 4.5) {
        $warnings[] = 'El contraste del texto del hero sobre navy es bajo (' . number_format($hero, 2) . ':1).';
    }
    return $warnings;
}

/**
 * Emit safe CSS custom properties for the resolved theme.
 *
 * @param array<string,string> $theme
 */
function theme_css_custom_properties(array $theme): string {
    $lines = [
        '--brand-blue: ' . $theme['brand_primary_color'],
        '--brand-cyan: ' . $theme['brand_secondary_color'],
        '--brand-navy: ' . $theme['brand_navy_color'],
        '--brand-dark: ' . $theme['brand_text_color'],
        '--brand-light: ' . $theme['brand_background_color'],
        '--brand-white: #ffffff',
        '--brand-muted: #64748b',
        '--button-radius: ' . theme_radius_css($theme['button_radius'], 'button'),
        '--card-radius: ' . theme_radius_css($theme['card_radius'], 'card'),
        '--brand-font-family: ' . theme_font_stack($theme['brand_font']),
        '--radius-sm: ' . theme_radius_css($theme['button_radius'], 'button'),
        '--radius-md: ' . theme_radius_css($theme['card_radius'], 'card'),
    ];
    return ":root {\n    " . implode(";\n    ", $lines) . ";\n}\n"
        . "body { font-family: var(--brand-font-family); color: var(--brand-dark); background-color: var(--brand-light); }\n"
        . ".btn { border-radius: var(--button-radius); }\n"
        . ".card, .product-card, .category-card { border-radius: var(--card-radius); }\n";
}

/**
 * @param array<string,mixed> $post
 * @return array{values:array<string,string>,errors:list<string>}
 */
function collect_theme_settings_from_post(array $post): array {
    $values = [];
    $errors = [];
    $colorKeys = [
        'brand_primary_color',
        'brand_secondary_color',
        'brand_navy_color',
        'brand_background_color',
        'brand_text_color',
    ];
    foreach ($colorKeys as $key) {
        $validated = validate_theme_setting($key, $post[$key] ?? '');
        if ($validated === null) $errors[] = "Color inválido: {$key}.";
        else $values[$key] = $validated;
    }
    foreach (['brand_font', 'nav_style', 'button_radius', 'card_radius', 'hero_height', 'hero_alignment', 'hero_overlay'] as $key) {
        $validated = validate_theme_setting($key, $post[$key] ?? '');
        if ($validated === null) $errors[] = "Opción inválida: {$key}.";
        else $values[$key] = $validated;
    }
    foreach (theme_boolean_keys() as $key) {
        $values[$key] = isset($post[$key]) ? '1' : '0';
    }
    $btnText = validate_theme_setting('hero_button_text', $post['hero_button_text'] ?? '');
    if ($btnText === null) $errors[] = 'Texto del botón del hero inválido.';
    else $values['hero_button_text'] = $btnText;

    $btnUrl = validate_theme_setting('hero_button_url', $post['hero_button_url'] ?? '');
    if ($btnUrl === null) $errors[] = 'Enlace del botón del hero inválido o no permitido.';
    else $values['hero_button_url'] = $btnUrl;

    return ['values' => $values, 'errors' => $errors];
}

/**
 * User-facing settings validation/business errors (safe to display).
 */
class PublicSettingsException extends RuntimeException {
}

/**
 * Restore Stage-1 visual keys to CyberLeo defaults. Returns previous custom
 * image paths that may be cleaned after commit.
 *
 * Clears hero_background (gradient default) but preserves hero_title,
 * hero_subtitle, body_background and commercial settings.
 *
 * @return array{restored:array<string,string>,cleanup_candidates:list<string>}
 */
function restore_cyberleo_visual_identity(PDO $pdo): array {
    $defaults = theme_default_settings();
    $select = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key = ? FOR UPDATE');
    $oldLogo = '';
    $oldFavicon = '';
    $oldHeroBackground = '';
    $select->execute(['brand_logo']);
    $oldLogo = (string) ($select->fetchColumn() ?: '');
    $select->execute(['brand_favicon']);
    $oldFavicon = (string) ($select->fetchColumn() ?: '');
    $select->execute(['hero_background']);
    $oldHeroBackground = (string) ($select->fetchColumn() ?: '');

    $upsert = $pdo->prepare(
        'INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($defaults as $key => $value) {
        $upsert->execute([$key, $value]);
    }
    // Volver al degradado CyberLeo; no tocar hero_title/subtitle ni body_background.
    $upsert->execute(['hero_background', '']);

    $restored = array_merge($defaults, ['hero_background' => '']);
    $cleanup = [];
    foreach (
        [
            [$oldLogo, THEME_OFFICIAL_LOGO],
            [$oldFavicon, ''],
            [$oldHeroBackground, ''],
        ] as [$old, $new]
    ) {
        if ($old !== '' && $old !== $new && is_safe_settings_image_path($old)) {
            $cleanup[] = $old;
        }
    }
    return [
        'restored' => $restored,
        'cleanup_candidates' => array_values(array_unique($cleanup)),
    ];
}
