<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';
require_once 'includes/images.php';
require_once 'includes/theme.php';

$defaults = get_store_settings();
$theme = resolve_theme_settings($defaults);
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

        $name = trim($_POST['store_name'] ?? '');
        $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp_number'] ?? '');
        if ($name === '' || mb_strlen($name) > 80 || strlen($whatsapp) < 8 || strlen($whatsapp) > 16) {
            throw new RuntimeException('Ingresá un nombre y un WhatsApp válido, con código de país.');
        }
        $instagram = trim($_POST['instagram_url'] ?? '');
        if ($instagram !== '' && !filter_var($instagram, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('La URL de Instagram no es válida.');
        }

        $themeCollect = collect_theme_settings_from_post($_POST);
        if ($themeCollect['errors']) {
            throw new RuntimeException(implode(' ', $themeCollect['errors']));
        }

        $heroTitle = sanitize_theme_plain_text((string) ($_POST['hero_title'] ?? ''), 140);
        $heroSubtitle = sanitize_theme_plain_text((string) ($_POST['hero_subtitle'] ?? ''), 240);
        if ($heroTitle === '' || $heroSubtitle === '') {
            throw new RuntimeException('Completá el título y el subtítulo de la portada.');
        }

        $values = array_merge($themeCollect['values'], [
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
            ],
            [
                'hero_background' => isset($_POST['remove_hero_background']),
                'body_background' => isset($_POST['remove_body_background']),
                'brand_logo' => isset($_POST['restore_official_logo']),
                'brand_favicon' => isset($_POST['remove_brand_favicon']),
            ]
        );
        header('Location: admin_settings.php?saved=1');
        exit;
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
        $defaults = array_merge($defaults, $_POST);
        $theme = resolve_theme_settings(array_merge($defaults, collect_theme_settings_from_post($_POST)['values'] ?? []));
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
    <p class="text-muted">Los cambios se reflejan en el sitio público. La identidad visual es opcional y conserva CyberLeo por defecto.</p>
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
        </div>
        <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save" aria-hidden="true"></i> Guardar cambios</button>
            <button type="button" class="btn btn-outline-secondary" id="update-preview-btn">Actualizar vista previa</button>
        </div>
    </form>

    <form method="post" class="mb-4" id="restore-cyberleo-form">
        <?= csrf_input() ?>
        <input type="hidden" name="settings_action" value="restore_cyberleo">
        <button type="submit" class="btn btn-outline-danger"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurar identidad CyberLeo</button>
    </form>

    <section class="card shadow-sm" aria-labelledby="preview-heading">
        <div class="card-header bg-white"><h2 class="h5 mb-0" id="preview-heading">Vista previa</h2></div>
        <div class="card-body">
            <div id="theme-preview" class="theme-preview border rounded overflow-hidden">
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
            <p class="small text-muted mt-2 mb-0">La vista previa no guarda cambios. Usá “Guardar cambios” para persistir.</p>
        </div>
    </section>
</main>
<script>
window.THEME_PREVIEW_BOOT = <?= json_encode($previewPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
</script>
<script src="<?= htmlspecialchars('assets/js/theme-preview.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
</body>
</html>
