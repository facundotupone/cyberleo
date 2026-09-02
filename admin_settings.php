<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';
require_once 'includes/images.php';
require_once 'includes/theme.php';
require_once 'includes/home_content.php';
require_once 'includes/catalog_display.php';

$defaults = get_store_settings();
$theme = resolve_theme_settings($defaults);
$home = resolve_home_content_settings($defaults);
$catalog = resolve_catalog_display_settings($defaults);
$message = '';
$messageType = 'info';
$contrastWarnings = theme_contrast_warnings($theme);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['settings_action'] ?? 'save');
    try {
        if ($action === 'restore_cyberleo') {
            $pdo->beginTransaction();
            try {
                $result = restore_cyberleo_visual_identity($pdo);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            foreach ($result['cleanup_candidates'] as $path) {
                delete_unreferenced_image($pdo, $path);
            }
            header('Location: admin_settings.php?restored=1');
            exit;
        }

        if ($action === 'restore_home_content') {
            $pdo->beginTransaction();
            try {
                $result = restore_home_content_defaults($pdo);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            foreach ($result['cleanup_candidates'] as $path) {
                delete_unreferenced_image($pdo, $path);
            }
            header('Location: admin_settings.php?home_restored=1');
            exit;
        }

        if ($action === 'restore_catalog_display') {
            $pdo->beginTransaction();
            try {
                restore_catalog_display_defaults($pdo);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            header('Location: admin_settings.php?catalog_restored=1');
            exit;
        }

        $name = trim($_POST['store_name'] ?? '');
        $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp_number'] ?? '');
        if ($name === '' || mb_strlen($name) > 80 || strlen($whatsapp) < 8 || strlen($whatsapp) > 16) {
            throw new PublicSettingsException('Ingresá un nombre y un WhatsApp válido, con código de país.');
        }
        $instagram = trim($_POST['instagram_url'] ?? '');
        if ($instagram !== '' && !filter_var($instagram, FILTER_VALIDATE_URL)) {
            throw new PublicSettingsException('La URL de Instagram no es válida.');
        }

        $themeCollect = collect_theme_settings_from_post($_POST);
        if ($themeCollect['errors']) {
            throw new PublicSettingsException(implode(' ', $themeCollect['errors']));
        }

        $homeCollect = collect_home_content_settings_from_post($_POST);
        if ($homeCollect['errors']) {
            throw new PublicSettingsException(implode(' ', $homeCollect['errors']));
        }

        $catalogCollect = collect_catalog_display_settings_from_post($_POST);
        if ($catalogCollect['errors']) {
            throw new PublicSettingsException(implode(' ', $catalogCollect['errors']));
        }

        $heroTitle = sanitize_theme_plain_text((string) ($_POST['hero_title'] ?? ''), 140);
        $heroSubtitle = sanitize_theme_plain_text((string) ($_POST['hero_subtitle'] ?? ''), 240);
        if ($heroTitle === '' || $heroSubtitle === '') {
            throw new PublicSettingsException('Completá el título y el subtítulo de la portada.');
        }

        $values = array_merge($themeCollect['values'], $homeCollect['values'], $catalogCollect['values'], [
            'store_name' => $name,
            'whatsapp_number' => $whatsapp,
            'instagram_url' => $instagram,
            'hero_title' => $heroTitle,
            'hero_subtitle' => $heroSubtitle,
            'reservation_minutes' => (string) max(5, min(1440, (int) ($_POST['reservation_minutes'] ?? 120))),
            'admin_email' => filter_var($_POST['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
            'mail_from' => filter_var($_POST['mail_from'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
            'payment_methods' => mb_substr(trim($_POST['payment_methods'] ?? ''), 0, 255),
        ]);

        save_settings_with_images(
            $pdo,
            $values,
            [
                'hero_background' => $_FILES['hero_background_file'] ?? [],
                'body_background' => $_FILES['body_background_file'] ?? [],
                'brand_logo' => $_FILES['brand_logo_file'] ?? [],
                'brand_favicon' => $_FILES['brand_favicon_file'] ?? [],
                'promo_image' => $_FILES['promo_image_file'] ?? [],
            ],
            [
                'hero_background' => isset($_POST['remove_hero_background']),
                'body_background' => isset($_POST['remove_body_background']),
                'brand_logo' => isset($_POST['restore_official_logo']),
                'brand_favicon' => isset($_POST['remove_brand_favicon']),
                'promo_image' => isset($_POST['remove_promo_image']),
            ]
        );
        header('Location: admin_settings.php?saved=1');
        exit;
    } catch (PublicSettingsException $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        $defaults = array_merge($defaults, $_POST);
        $theme = resolve_theme_settings(array_merge($defaults, collect_theme_settings_from_post($_POST)['values'] ?? []));
        $home = resolve_home_content_settings(array_merge($defaults, collect_home_content_settings_from_post($_POST)['values'] ?? []));
        $catalog = resolve_catalog_display_settings(array_merge($defaults, collect_catalog_display_settings_from_post($_POST)['values'] ?? []));
        $contrastWarnings = theme_contrast_warnings($theme);
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        $defaults = array_merge($defaults, $_POST);
        $theme = resolve_theme_settings(array_merge($defaults, collect_theme_settings_from_post($_POST)['values'] ?? []));
        $home = resolve_home_content_settings(array_merge($defaults, collect_home_content_settings_from_post($_POST)['values'] ?? []));
        $catalog = resolve_catalog_display_settings(array_merge($defaults, collect_catalog_display_settings_from_post($_POST)['values'] ?? []));
        $contrastWarnings = theme_contrast_warnings($theme);
    } catch (Throwable $e) {
        error_log('admin_settings: ' . $e->getMessage());
        $message = 'No se pudo guardar la configuración. Intentá nuevamente.';
        $messageType = 'danger';
        $defaults = array_merge($defaults, $_POST);
        $theme = resolve_theme_settings(array_merge($defaults, collect_theme_settings_from_post($_POST)['values'] ?? []));
        $home = resolve_home_content_settings(array_merge($defaults, collect_home_content_settings_from_post($_POST)['values'] ?? []));
        $catalog = resolve_catalog_display_settings(array_merge($defaults, collect_catalog_display_settings_from_post($_POST)['values'] ?? []));
        $contrastWarnings = theme_contrast_warnings($theme);
    }
}
if (isset($_GET['saved'])) {
    $message = 'Configuración guardada correctamente.';
    $messageType = 'success';
}
if (isset($_GET['restored'])) {
    $message = 'Identidad CyberLeo restaurada. Se conservaron datos comerciales y de catálogo.';
    $messageType = 'success';
    $defaults = get_store_settings();
    $theme = resolve_theme_settings($defaults);
    $home = resolve_home_content_settings($defaults);
    $catalog = resolve_catalog_display_settings($defaults);
    $contrastWarnings = theme_contrast_warnings($theme);
}
if (isset($_GET['home_restored'])) {
    $message = 'Contenido de portada restaurado. Se conservaron identidad visual, logo, colores y datos comerciales.';
    $messageType = 'success';
    $defaults = get_store_settings();
    $theme = resolve_theme_settings($defaults);
    $home = resolve_home_content_settings($defaults);
    $catalog = resolve_catalog_display_settings($defaults);
    $contrastWarnings = theme_contrast_warnings($theme);
}
if (isset($_GET['catalog_restored'])) {
    $message = 'Catálogo y tarjetas restaurados. Se conservaron identidad visual, contenido de portada y datos comerciales.';
    $messageType = 'success';
    $defaults = get_store_settings();
    $theme = resolve_theme_settings($defaults);
    $home = resolve_home_content_settings($defaults);
    $catalog = resolve_catalog_display_settings($defaults);
    $contrastWarnings = theme_contrast_warnings($theme);
}

$previewPayload = [
    'theme' => $theme,
    'store_name' => (string) $defaults['store_name'],
    'hero_title' => (string) $defaults['hero_title'],
    'hero_subtitle' => (string) $defaults['hero_subtitle'],
    'logo' => $theme['brand_logo'],
];
$fontOptions = [
    'system' => 'Sistema',
    'inter' => 'Inter',
    'montserrat' => 'Montserrat',
    'poppins' => 'Poppins',
];
$radiusOptions = ['low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto'];
$heightOptions = ['compact' => 'Compacta', 'normal' => 'Normal', 'large' => 'Grande'];
$alignOptions = ['left' => 'Izquierda', 'center' => 'Centro'];
$overlayOptions = ['soft' => 'Suave', 'medium' => 'Medio', 'strong' => 'Fuerte'];
$navOptions = ['white' => 'Blanca', 'navy' => 'Navy'];
$announcementStyles = ['primary' => 'Principal', 'secondary' => 'Secundario', 'navy' => 'Navy'];
$cardStyleOptions = ['bordered' => 'Con borde', 'elevated' => 'Elevada', 'minimal' => 'Mínima'];
$imageFitOptions = ['contain' => 'Contain (completa)', 'cover' => 'Cover (recorta)'];
$descModeOptions = ['hidden' => 'Oculta', 'compact' => 'Compacta', 'expandable' => 'Expandible'];
$descLenOptions = ['100' => '100', '160' => '160', '200' => '200', '300' => '300'];
$columnOptions = ['2' => '2', '3' => '3', '4' => '4'];
$iconLabels = [
    'bi-truck' => 'Envío',
    'bi-shield-check' => 'Seguridad',
    'bi-whatsapp' => 'WhatsApp',
    'bi-credit-card' => 'Pago',
    'bi-headset' => 'Atención',
    'bi-box-seam' => 'Paquete',
    'bi-lightning-charge' => 'Rápido',
    'bi-tools' => 'Soporte',
];
$sectionLabels = [
    'featured' => 'Productos destacados',
    'promo' => 'Banner promocional',
    'categories' => 'Categorías',
    'benefits' => 'Beneficios',
];
$orderRanks = home_content_order_ranks($home);
$logoSrc = is_safe_brand_logo_path($theme['brand_logo']) ? $theme['brand_logo'] : THEME_OFFICIAL_LOGO;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Configuración de tienda</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/style.css">
<style><?= theme_css_custom_properties($theme) ?></style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark" style="background:#071a33">
    <div class="container">
        <a class="navbar-brand" href="admin_products.php">Administración</a>
        <div>
            <a class="btn btn-outline-light btn-sm" href="admin_products.php">Productos</a>
            <a class="btn btn-outline-light btn-sm" href="admin_orders.php">Pedidos</a>
        </div>
    </div>
</nav>
<main class="container py-4" style="max-width:980px">
    <h1 class="h2"><i class="bi bi-sliders" aria-hidden="true"></i> Configuración de la tienda</h1>
    <p class="text-muted">Los cambios se reflejan en el sitio público. La identidad visual y el contenido de portada son opcionales y conservan CyberLeo por defecto.</p>
    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?>" role="status"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php foreach ($contrastWarnings as $warning): ?>
        <div class="alert alert-warning" role="status"><?= htmlspecialchars($warning) ?></div>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" class="card shadow-sm mb-4" id="store-settings-form">
        <?= csrf_input() ?>
        <input type="hidden" name="settings_action" value="save">
        <div class="card-body">
            <h2 class="h4 mb-3">Datos comerciales</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="store_name">Nombre de la tienda</label>
                    <input class="form-control" id="store_name" name="store_name" required value="<?= htmlspecialchars($defaults['store_name']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="whatsapp_number">WhatsApp de ventas</label>
                    <input class="form-control" id="whatsapp_number" name="whatsapp_number" required inputmode="numeric" value="<?= htmlspecialchars($defaults['whatsapp_number']) ?>">
                    <small class="text-muted">Código de país + número, sin + ni espacios.</small>
                </div>
                <div class="col-12">
                    <label class="form-label" for="instagram_url">Instagram (opcional)</label>
                    <input class="form-control" type="url" id="instagram_url" name="instagram_url" value="<?= htmlspecialchars($defaults['instagram_url']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="reservation_minutes">Reserva (minutos)</label>
                    <input class="form-control" type="number" min="5" max="1440" id="reservation_minutes" name="reservation_minutes" value="<?= (int) $defaults['reservation_minutes'] ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="admin_email">Correo administrador</label>
                    <input class="form-control" type="email" id="admin_email" name="admin_email" value="<?= htmlspecialchars($defaults['admin_email']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="mail_from">Remitente de correos</label>
                    <input class="form-control" type="email" id="mail_from" name="mail_from" value="<?= htmlspecialchars($defaults['mail_from']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="payment_methods">Métodos de pago (separados por coma)</label>
                    <input class="form-control" id="payment_methods" name="payment_methods" value="<?= htmlspecialchars($defaults['payment_methods']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="body_background_file">Fondo del sitio</label>
                    <input class="form-control" type="file" id="body_background_file" name="body_background_file" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($defaults['body_background']) && is_safe_settings_image_path($defaults['body_background'])): ?>
                        <img class="img-thumbnail mt-2" style="max-height:100px" src="<?= htmlspecialchars($defaults['body_background']) ?>" alt="Fondo actual del sitio">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_body_background" id="remove_body_background" value="1">
                            <label class="form-check-label" for="remove_body_background">Quitar fondo del sitio</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <hr>
            <h2 class="h4 mb-3">Identidad visual</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="brand_logo_file">Logo principal (PNG)</label>
                    <div class="mb-2 p-2 border rounded bg-white text-center">
                        <img id="preview-logo-current" src="<?= htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="CyberLeo" class="brand-logo" style="height:56px;width:auto;object-fit:contain">
                    </div>
                    <input class="form-control" type="file" id="brand_logo_file" name="brand_logo_file" accept="image/png">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="restore_official_logo" id="restore_official_logo" value="1">
                        <label class="form-check-label" for="restore_official_logo">Restaurar logo oficial</label>
                    </div>
                    <small class="text-muted">El archivo oficial versionado no se elimina.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="brand_favicon_file">Favicon (PNG, opcional)</label>
                    <?php if (!empty($theme['brand_favicon']) && is_safe_brand_favicon_path($theme['brand_favicon'])): ?>
                        <div class="mb-2"><img src="<?= htmlspecialchars($theme['brand_favicon']) ?>" alt="" width="32" height="32"></div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="remove_brand_favicon" id="remove_brand_favicon" value="1">
                            <label class="form-check-label" for="remove_brand_favicon">Quitar favicon personalizado</label>
                        </div>
                    <?php endif; ?>
                    <input class="form-control" type="file" id="brand_favicon_file" name="brand_favicon_file" accept="image/png">
                    <small class="text-muted">No se usa el logo ovalado automáticamente.</small>
                </div>
                <?php
                $colorFields = [
                    'brand_primary_color' => 'Color principal',
                    'brand_secondary_color' => 'Color secundario',
                    'brand_navy_color' => 'Color navy/oscuro',
                    'brand_background_color' => 'Fondo general',
                    'brand_text_color' => 'Texto principal',
                ];
                foreach ($colorFields as $key => $label):
                    $val = htmlspecialchars($theme[$key]);
                ?>
                <div class="col-md-4">
                    <label class="form-label" for="<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    <div class="input-group">
                        <input type="color" class="form-control form-control-color theme-color-picker" data-hex-target="<?= $key ?>_hex" value="<?= $val ?>" title="<?= htmlspecialchars($label) ?>" aria-label="<?= htmlspecialchars($label) ?> selector">
                        <input type="text" class="form-control theme-color-hex" id="<?= $key ?>_hex" name="<?= $key ?>" value="<?= $val ?>" pattern="#?[0-9A-Fa-f]{6}" maxlength="7" required aria-label="<?= htmlspecialchars($label) ?> hexadecimal">
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="col-md-4">
                    <label class="form-label" for="nav_style">Estilo de navegación</label>
                    <select class="form-select" id="nav_style" name="nav_style">
                        <?php foreach ($navOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $theme['nav_style'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="brand_font">Tipografía</label>
                    <select class="form-select" id="brand_font" name="brand_font">
                        <?php foreach ($fontOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $theme['brand_font'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="button_radius">Redondeado de botones</label>
                    <select class="form-select" id="button_radius" name="button_radius">
                        <?php foreach ($radiusOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $theme['button_radius'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="card_radius">Redondeado de tarjetas</label>
                    <select class="form-select" id="card_radius" name="card_radius">
                        <?php foreach ($radiusOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $theme['card_radius'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <hr>
            <h2 class="h4 mb-3">Portada</h2>
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <label class="form-label" for="hero_title">Título del hero</label>
                    <input class="form-control" id="hero_title" name="hero_title" required maxlength="140" value="<?= htmlspecialchars($defaults['hero_title']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="hero_subtitle">Subtítulo del hero</label>
                    <textarea class="form-control" id="hero_subtitle" name="hero_subtitle" rows="2" required maxlength="240"><?= htmlspecialchars($defaults['hero_subtitle']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="hero_button_text">Texto del botón principal</label>
                    <input class="form-control" id="hero_button_text" name="hero_button_text" maxlength="60" required value="<?= htmlspecialchars($theme['hero_button_text']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="hero_button_url">Enlace del botón (ruta local)</label>
                    <input class="form-control" id="hero_button_url" name="hero_button_url" maxlength="180" required value="<?= htmlspecialchars($theme['hero_button_url']) ?>">
                    <small class="text-muted">Ejemplos: #productos-destacados, index.php, category.php?id=1</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="hero_background_file">Imagen de fondo del hero</label>
                    <input class="form-control" type="file" id="hero_background_file" name="hero_background_file" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($defaults['hero_background']) && is_safe_settings_image_path($defaults['hero_background'])): ?>
                        <img class="img-thumbnail mt-2" style="max-height:100px" src="<?= htmlspecialchars($defaults['hero_background']) ?>" alt="Fondo actual del encabezado">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_hero_background" id="remove_hero_background" value="1">
                            <label class="form-check-label" for="remove_hero_background">Quitar imagen de fondo</label>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="hero_height">Altura</label>
                    <select class="form-select" id="hero_height" name="hero_height">
                        <?php foreach ($heightOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $theme['hero_height'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="hero_alignment">Alineación</label>
                    <select class="form-select" id="hero_alignment" name="hero_alignment">
                        <?php foreach ($alignOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $theme['hero_alignment'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="hero_overlay">Overlay</label>
                    <select class="form-select" id="hero_overlay" name="hero_overlay">
                        <?php foreach ($overlayOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $theme['hero_overlay'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="show_search" name="show_search" value="1"<?= $theme['show_search'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="show_search">Mostrar buscador</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="show_categories" name="show_categories" value="1"<?= $theme['show_categories'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="show_categories">Mostrar categorías</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="show_featured_products" name="show_featured_products" value="1"<?= $theme['show_featured_products'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="show_featured_products">Mostrar productos destacados</label>
                    </div>
                </div>
            </div>

            <hr>
            <h2 class="h4 mb-3">Aviso superior</h2>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="announcement_enabled" name="announcement_enabled" value="1"<?= $home['announcement_enabled'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="announcement_enabled">Mostrar franja informativa</label>
                    </div>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="announcement_text">Texto (máx. 140)</label>
                    <input class="form-control" id="announcement_text" name="announcement_text" maxlength="140" value="<?= htmlspecialchars($home['announcement_text']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="announcement_style">Estilo</label>
                    <select class="form-select" id="announcement_style" name="announcement_style">
                        <?php foreach ($announcementStyles as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $home['announcement_style'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="announcement_url">Enlace opcional (ruta local)</label>
                    <input class="form-control" id="announcement_url" name="announcement_url" maxlength="180" value="<?= htmlspecialchars($home['announcement_url']) ?>">
                    <small class="text-muted">Solo rutas locales seguras. Sin URLs externas.</small>
                </div>
            </div>

            <hr>
            <h2 class="h4 mb-3">Banner promocional</h2>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="promo_enabled" name="promo_enabled" value="1"<?= $home['promo_enabled'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="promo_enabled">Mostrar banner promocional</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="promo_title">Título (máx. 100)</label>
                    <input class="form-control" id="promo_title" name="promo_title" maxlength="100" value="<?= htmlspecialchars($home['promo_title']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="promo_button_text">Texto del botón (máx. 60)</label>
                    <input class="form-control" id="promo_button_text" name="promo_button_text" maxlength="60" value="<?= htmlspecialchars($home['promo_button_text']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="promo_text">Texto (máx. 240)</label>
                    <textarea class="form-control" id="promo_text" name="promo_text" rows="2" maxlength="240"><?= htmlspecialchars($home['promo_text']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="promo_button_url">Enlace del botón (ruta local)</label>
                    <input class="form-control" id="promo_button_url" name="promo_button_url" maxlength="180" value="<?= htmlspecialchars($home['promo_button_url']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="promo_image_file">Imagen (JPG, PNG o WebP)</label>
                    <input class="form-control" type="file" id="promo_image_file" name="promo_image_file" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($home['promo_image']) && is_safe_promo_image_path($home['promo_image'])): ?>
                        <img class="img-thumbnail mt-2" style="max-height:100px" src="<?= htmlspecialchars($home['promo_image']) ?>" alt="Imagen promocional actual">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_promo_image" id="remove_promo_image" value="1">
                            <label class="form-check-label" for="remove_promo_image">Quitar imagen promocional</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <hr>
            <h2 class="h4 mb-3">Orden de portada</h2>
            <p class="text-muted small">Asigná posiciones del 1 al 4 sin duplicados. El buscador permanece fijo debajo del hero.</p>
            <div class="row g-3 mb-4">
                <?php foreach ($sectionLabels as $token => $label): ?>
                <div class="col-md-3 col-6">
                    <label class="form-label" for="home_order_<?= $token ?>"><?= htmlspecialchars($label) ?></label>
                    <select class="form-select" id="home_order_<?= $token ?>" name="home_order_<?= $token ?>">
                        <?php for ($n = 1; $n <= 4; $n++): ?>
                            <option value="<?= $n ?>"<?= ((int) ($orderRanks[$token] ?? 0) === $n) ? ' selected' : '' ?>><?= $n ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>

            <hr>
            <h2 class="h4 mb-3">Beneficios</h2>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="benefits_enabled" name="benefits_enabled" value="1"<?= $home['benefits_enabled'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="benefits_enabled">Mostrar bloque de beneficios</label>
                    </div>
                </div>
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="col-12"><h3 class="h6 mb-0">Beneficio <?= $i ?></h3></div>
                <div class="col-md-3">
                    <label class="form-label" for="benefit_<?= $i ?>_icon">Ícono</label>
                    <select class="form-select" id="benefit_<?= $i ?>_icon" name="benefit_<?= $i ?>_icon">
                        <?php foreach ($iconLabels as $icon => $iconLabel): ?>
                            <option value="<?= $icon ?>"<?= $home["benefit_{$i}_icon"] === $icon ? ' selected' : '' ?>><?= htmlspecialchars($iconLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="benefit_<?= $i ?>_title">Título</label>
                    <input class="form-control" id="benefit_<?= $i ?>_title" name="benefit_<?= $i ?>_title" maxlength="60" value="<?= htmlspecialchars($home["benefit_{$i}_title"]) ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="benefit_<?= $i ?>_text">Texto</label>
                    <input class="form-control" id="benefit_<?= $i ?>_text" name="benefit_<?= $i ?>_text" maxlength="180" value="<?= htmlspecialchars($home["benefit_{$i}_text"]) ?>">
                </div>
                <?php endfor; ?>
            </div>

            <hr>
            <h2 class="h4 mb-3">Footer y datos visibles</h2>
            <div class="row g-3 mb-2">
                <div class="col-12">
                    <label class="form-label" for="footer_description">Descripción (máx. 180)</label>
                    <textarea class="form-control" id="footer_description" name="footer_description" rows="2" maxlength="180"><?= htmlspecialchars($home['footer_description']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="footer_instagram_text">Texto enlace Instagram</label>
                    <input class="form-control" id="footer_instagram_text" name="footer_instagram_text" maxlength="60" value="<?= htmlspecialchars($home['footer_instagram_text']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="footer_whatsapp_text">Texto enlace WhatsApp</label>
                    <input class="form-control" id="footer_whatsapp_text" name="footer_whatsapp_text" maxlength="60" value="<?= htmlspecialchars($home['footer_whatsapp_text']) ?>">
                </div>
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="footer_show_logo" name="footer_show_logo" value="1"<?= $home['footer_show_logo'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="footer_show_logo">Mostrar logo</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="footer_show_instagram" name="footer_show_instagram" value="1"<?= $home['footer_show_instagram'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="footer_show_instagram">Mostrar Instagram</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="footer_show_whatsapp" name="footer_show_whatsapp" value="1"<?= $home['footer_show_whatsapp'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="footer_show_whatsapp">Mostrar WhatsApp</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="footer_show_business_hours" name="footer_show_business_hours" value="1"<?= $home['footer_show_business_hours'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="footer_show_business_hours">Mostrar horarios</label>
                    </div>
                    <label class="form-label" for="business_hours">Horarios</label>
                    <input class="form-control" id="business_hours" name="business_hours" maxlength="140" value="<?= htmlspecialchars($home['business_hours']) ?>">
                </div>
                <div class="col-md-6">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="footer_show_location" name="footer_show_location" value="1"<?= $home['footer_show_location'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="footer_show_location">Mostrar ubicación</label>
                    </div>
                    <label class="form-label" for="business_location">Ubicación</label>
                    <input class="form-control" id="business_location" name="business_location" maxlength="180" value="<?= htmlspecialchars($home['business_location']) ?>">
                </div>
                <div class="col-12">
                    <small class="text-muted">WhatsApp e Instagram usan los datos comerciales de arriba. Si Instagram está vacío, no se muestra el enlace.</small>
                </div>
            </div>

            <hr>
            <h2 class="h4 mb-3" id="catalog-display-heading">Catálogo y tarjetas de productos</h2>
            <p class="text-muted small">Opciones cerradas para destacados, catálogo y presentación de tarjetas. Sin HTML, CSS ni JavaScript personalizados.</p>
            <div class="row g-3 mb-4">
                <div class="col-12"><h3 class="h6 mb-0">Textos y columnas</h3></div>
                <div class="col-md-6">
                    <label class="form-label" for="featured_section_title">Título de destacados</label>
                    <input class="form-control" id="featured_section_title" name="featured_section_title" maxlength="80" value="<?= htmlspecialchars($catalog['featured_section_title']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="featured_columns">Columnas destacados</label>
                    <select class="form-select" id="featured_columns" name="featured_columns">
                        <?php foreach ($columnOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $catalog['featured_columns'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="catalog_columns">Columnas catálogo</label>
                    <select class="form-select" id="catalog_columns" name="catalog_columns">
                        <?php foreach ($columnOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $catalog['catalog_columns'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="featured_empty_text">Mensaje vacío de destacados</label>
                    <input class="form-control" id="featured_empty_text" name="featured_empty_text" maxlength="160" value="<?= htmlspecialchars($catalog['featured_empty_text']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="catalog_empty_text">Mensaje vacío de catálogo</label>
                    <input class="form-control" id="catalog_empty_text" name="catalog_empty_text" maxlength="160" value="<?= htmlspecialchars($catalog['catalog_empty_text']) ?>">
                </div>

                <div class="col-12"><h3 class="h6 mb-0 mt-2">Estilo de tarjeta e imagen</h3></div>
                <div class="col-md-3 col-6">
                    <label class="form-label" for="product_card_style">Estilo</label>
                    <select class="form-select" id="product_card_style" name="product_card_style">
                        <?php foreach ($cardStyleOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $catalog['product_card_style'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label" for="product_image_fit">Ajuste de imagen</label>
                    <select class="form-select" id="product_image_fit" name="product_image_fit">
                        <?php foreach ($imageFitOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $catalog['product_image_fit'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label" for="product_image_height">Altura de imagen</label>
                    <select class="form-select" id="product_image_height" name="product_image_height">
                        <?php foreach ($heightOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $catalog['product_image_height'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label" for="product_card_alignment">Alineación</label>
                    <select class="form-select" id="product_card_alignment" name="product_card_alignment">
                        <?php foreach ($alignOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $catalog['product_card_alignment'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 col-6">
                    <label class="form-label" for="product_description_mode">Descripción</label>
                    <select class="form-select" id="product_description_mode" name="product_description_mode">
                        <?php foreach ($descModeOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $catalog['product_description_mode'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 col-6">
                    <label class="form-label" for="product_description_length">Longitud</label>
                    <select class="form-select" id="product_description_length" name="product_description_length">
                        <?php foreach ($descLenOptions as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $catalog['product_description_length'] === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="product_sale_badge_text">Texto badge de oferta</label>
                    <input class="form-control" id="product_sale_badge_text" name="product_sale_badge_text" maxlength="30" value="<?= htmlspecialchars($catalog['product_sale_badge_text']) ?>">
                </div>

                <div class="col-12"><h3 class="h6 mb-0 mt-2">Elementos visibles</h3></div>
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="product_show_category_badge" name="product_show_category_badge" value="1"<?= $catalog['product_show_category_badge'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="product_show_category_badge">Badge de categoría (destacados)</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="product_show_stock" name="product_show_stock" value="1"<?= $catalog['product_show_stock'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="product_show_stock">Mostrar stock</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="product_show_sale_badge" name="product_show_sale_badge" value="1"<?= $catalog['product_show_sale_badge'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="product_show_sale_badge">Badge de oferta</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="product_show_old_price" name="product_show_old_price" value="1"<?= $catalog['product_show_old_price'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="product_show_old_price">Precio anterior</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="product_show_share_buttons" name="product_show_share_buttons" value="1"<?= $catalog['product_show_share_buttons'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="product_show_share_buttons">Mostrar compartir</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="product_share_whatsapp" name="product_share_whatsapp" value="1"<?= $catalog['product_share_whatsapp'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="product_share_whatsapp">WhatsApp</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="product_share_facebook" name="product_share_facebook" value="1"<?= $catalog['product_share_facebook'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="product_share_facebook">Facebook</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="product_share_copy" name="product_share_copy" value="1"<?= $catalog['product_share_copy'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="product_share_copy">Copiar enlace</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="catalog_show_breadcrumbs" name="catalog_show_breadcrumbs" value="1"<?= $catalog['catalog_show_breadcrumbs'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="catalog_show_breadcrumbs">Breadcrumbs</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="catalog_show_product_count" name="catalog_show_product_count" value="1"<?= $catalog['catalog_show_product_count'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="catalog_show_product_count">Contador de productos</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="catalog_show_subcategory_filter" name="catalog_show_subcategory_filter" value="1"<?= $catalog['catalog_show_subcategory_filter'] === '1' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="catalog_show_subcategory_filter">Filtro de subcategorías</label>
                    </div>
                </div>

                <div class="col-12"><h3 class="h6 mb-0 mt-2">Textos del botón</h3></div>
                <div class="col-md-6">
                    <label class="form-label" for="product_add_button_text">Con stock</label>
                    <input class="form-control" id="product_add_button_text" name="product_add_button_text" maxlength="40" value="<?= htmlspecialchars($catalog['product_add_button_text']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="product_out_of_stock_text">Sin stock</label>
                    <input class="form-control" id="product_out_of_stock_text" name="product_out_of_stock_text" maxlength="30" value="<?= htmlspecialchars($catalog['product_out_of_stock_text']) ?>">
                </div>
            </div>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save" aria-hidden="true"></i> Guardar cambios</button>
            <button type="button" class="btn btn-outline-secondary" id="update-preview-btn">Actualizar vista previa</button>
        </div>
    </form>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <form method="post" id="restore-cyberleo-form">
            <?= csrf_input() ?>
            <input type="hidden" name="settings_action" value="restore_cyberleo">
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurar identidad CyberLeo</button>
        </form>
        <form method="post" id="restore-home-content-form">
            <?= csrf_input() ?>
            <input type="hidden" name="settings_action" value="restore_home_content">
            <button type="submit" class="btn btn-outline-warning"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurar contenido predeterminado</button>
        </form>
        <form method="post" id="restore-catalog-display-form">
            <?= csrf_input() ?>
            <input type="hidden" name="settings_action" value="restore_catalog_display">
            <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurar catálogo predeterminado</button>
        </form>
    </div>

    <section class="card shadow-sm mb-4" aria-labelledby="preview-heading">
        <div class="card-header bg-white"><h2 class="h5 mb-0" id="preview-heading">Vista previa</h2></div>
        <div class="card-body">
            <div id="theme-preview" class="theme-preview border rounded overflow-hidden mb-3">
                <div id="preview-nav" class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                    <img id="preview-logo" src="<?= htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="CyberLeo" style="height:42px;width:auto;object-fit:contain">
                    <span id="preview-nav-link" class="small">Inicio · Catálogo · Carrito</span>
                </div>
                <div id="preview-hero" class="p-4 text-white">
                    <h3 id="preview-hero-title" class="h4"></h3>
                    <p id="preview-hero-subtitle" class="mb-3"></p>
                    <span id="preview-hero-button" class="btn btn-sm"></span>
                </div>
                <div class="p-3 bg-white">
                    <div id="preview-card" class="border p-3" style="max-width:260px">
                        <div class="bg-light mb-2" style="height:90px"></div>
                        <strong id="preview-card-title">Producto de ejemplo</strong>
                        <div id="preview-card-price" class="mt-1">$99.999</div>
                        <button type="button" id="preview-card-button" class="btn btn-sm mt-2">Agregar</button>
                    </div>
                </div>
            </div>

            <h3 class="h6">Contenido de portada</h3>
            <p class="small text-muted mb-2">Orden: <span id="preview-home-order"></span></p>
            <div class="home-preview-block mb-3">
                <div id="preview-announcement" class="home-preview-announcement is-primary">
                    <span id="preview-announcement-text">Aviso</span>
                </div>
                <div id="preview-promo" class="home-preview-promo">
                    <strong id="preview-promo-title">Promoción</strong>
                    <p class="mb-2 small" id="preview-promo-text"></p>
                    <span class="btn btn-sm btn-light" id="preview-promo-button">Ver más</span>
                </div>
                <div id="preview-benefits" class="home-preview-benefits">
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                    <div class="home-preview-benefit">
                        <i id="preview-benefit-<?= $i ?>-icon" class="bi bi-truck" aria-hidden="true"></i>
                        <strong id="preview-benefit-<?= $i ?>-title"></strong>
                        <div id="preview-benefit-<?= $i ?>-text" class="text-muted"></div>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="home-preview-footer">
                    <div id="preview-footer-logo" class="mb-2">
                        <img src="<?= htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" style="height:28px;width:auto">
                    </div>
                    <div id="preview-footer-desc" class="mb-2"></div>
                    <div id="preview-footer-ig-wrap" class="mb-1"><span id="preview-footer-ig"></span></div>
                    <div id="preview-footer-wa-wrap" class="mb-1"><span id="preview-footer-wa"></span></div>
                    <div id="preview-footer-hours-wrap" class="mb-1"><span id="preview-footer-hours"></span></div>
                    <div id="preview-footer-location-wrap"><span id="preview-footer-location"></span></div>
                </div>
            </div>
            <h3 class="h6 mt-4">Catálogo y tarjetas</h3>
            <p class="small text-muted mb-2" id="catalog-preview-info">Vista previa de tarjeta</p>
            <div class="catalog-preview-wrap mb-3">
                <div id="catalog-preview-card" class="card product-card catalog-preview-card product-card-elevated product-card-align-left product-fit-contain product-height-normal">
                    <div class="product-media">
                        <div id="catalog-preview-sale-badge" class="product-sale-badge">LIQUIDACIÓN</div>
                        <div class="catalog-preview-image-sim" aria-hidden="true"></div>
                    </div>
                    <div class="card-body">
                        <div id="catalog-preview-category" class="mb-2">
                            <span class="badge bg-warning text-dark">Ver más de Notebooks</span>
                        </div>
                        <h3 class="card-title h5" id="catalog-preview-title">Producto de ejemplo</h3>
                        <div id="catalog-preview-desc-wrap" class="description-container">
                            <p class="card-text">
                                <span id="catalog-preview-desc-short" class="short-description"></span>
                                <span id="catalog-preview-desc-ellipsis" class="ellipsis">...</span>
                                <button type="button" id="catalog-preview-desc-more" class="btn btn-link p-0 ver-mas">Ver más</button>
                            </p>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-auto pt-2 gap-2 flex-wrap">
                            <div class="d-flex align-items-center flex-wrap product-price-block gap-2">
                                <span id="catalog-preview-old-price" class="price-old">$150.000,00</span>
                                <span class="price-sale">$129.999,00</span>
                                <small id="catalog-preview-stock" class="text-muted stock-display">(Stock: 5)</small>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="catalog-preview-button">
                                <i class="bi bi-cart-plus" aria-hidden="true"></i>
                                <span class="add-to-cart-label" id="catalog-preview-button-label">Agregar al carrito</span>
                            </button>
                        </div>
                        <div id="catalog-preview-share" class="mt-3 product-share">
                            <div class="d-flex justify-content-center gap-2">
                                <span id="catalog-preview-share-wa" class="btn btn-success btn-sm rounded-circle product-share-btn" aria-hidden="true"><i class="bi bi-whatsapp"></i></span>
                                <span id="catalog-preview-share-fb" class="btn btn-primary btn-sm rounded-circle product-share-btn" aria-hidden="true"><i class="bi bi-facebook"></i></span>
                                <span id="catalog-preview-share-copy" class="btn btn-secondary btn-sm rounded-circle product-share-btn" aria-hidden="true"><i class="bi bi-link-45deg"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <p class="small text-muted mb-0">Columnas destacados: <strong id="catalog-preview-featured-cols">3</strong> · Catálogo: <strong id="catalog-preview-catalog-cols">3</strong> · Fit: <span id="catalog-preview-fit">contain</span> · Altura: <span id="catalog-preview-height">normal</span></p>
            <p class="small text-muted mt-2 mb-0">La vista previa no guarda cambios. Usá “Guardar cambios” para persistir.</p>
        </div>
    </section>
</main>
<script>
window.THEME_PREVIEW_BOOT = <?= json_encode($previewPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
</script>
<script src="<?= htmlspecialchars('assets/js/theme-preview.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="<?= htmlspecialchars('assets/js/home-content-preview.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="<?= htmlspecialchars('assets/js/catalog-preview.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
