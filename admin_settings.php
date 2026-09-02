<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';
require_once 'includes/images.php';

$defaults = get_store_settings();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $name = trim($_POST['store_name'] ?? '');
        $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp_number'] ?? '');
        if ($name === '' || mb_strlen($name) > 80 || strlen($whatsapp) < 8 || strlen($whatsapp) > 16) {
            throw new RuntimeException('Ingresá un nombre y un WhatsApp válido, con código de país.');
        }
        $instagram = trim($_POST['instagram_url'] ?? '');
        if ($instagram !== '' && !filter_var($instagram, FILTER_VALIDATE_URL)) throw new RuntimeException('La URL de Instagram no es válida.');

        $values = [
            'store_name' => $name,
            'whatsapp_number' => $whatsapp,
            'instagram_url' => $instagram,
            'hero_title' => mb_substr(trim($_POST['hero_title'] ?? ''), 0, 140),
            'hero_subtitle' => mb_substr(trim($_POST['hero_subtitle'] ?? ''), 0, 240),
            'reservation_minutes' => max(5, min(1440, (int)($_POST['reservation_minutes'] ?? 120))),
            'admin_email' => filter_var($_POST['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
            'mail_from' => filter_var($_POST['mail_from'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
            'payment_methods' => mb_substr(trim($_POST['payment_methods'] ?? ''), 0, 255),
        ];
        save_settings_with_images(
            $pdo,
            $values,
            [
                'hero_background' => $_FILES['hero_background_file'] ?? [],
                'body_background' => $_FILES['body_background_file'] ?? [],
            ],
            [
                'hero_background' => isset($_POST['remove_hero_background']),
                'body_background' => isset($_POST['remove_body_background']),
            ]
        );
        header('Location: admin_settings.php?saved=1'); exit;
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }
}
$message = isset($_GET['saved']) ? 'Configuración guardada correctamente.' : $message;
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Configuración de tienda</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head><body class="bg-light">
<nav class="navbar navbar-dark" style="background:#071a33"><div class="container"><a class="navbar-brand" href="admin_products.php">Administración</a><div><a class="btn btn-outline-light btn-sm" href="admin_products.php">Productos</a> <a class="btn btn-outline-light btn-sm" href="admin_orders.php">Pedidos</a></div></div></nav>
<main class="container py-4" style="max-width:850px">
<h1 class="h2"><i class="bi bi-sliders"></i> Configuración de la tienda</h1><p class="text-muted">Los cambios se reflejan en el sitio público y en los mensajes de pedido.</p>
<?php if ($message): ?><div class="alert alert-info"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card shadow-sm"><?= csrf_input() ?><div class="card-body">
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Nombre de la tienda</label><input class="form-control" name="store_name" required value="<?= htmlspecialchars($defaults['store_name']) ?>"></div>
<div class="col-md-6"><label class="form-label">WhatsApp de ventas</label><input class="form-control" name="whatsapp_number" required inputmode="numeric" value="<?= htmlspecialchars($defaults['whatsapp_number']) ?>"><small class="text-muted">Código de país + número, sin + ni espacios.</small></div>
<div class="col-12"><label class="form-label">Instagram (opcional)</label><input class="form-control" type="url" name="instagram_url" value="<?= htmlspecialchars($defaults['instagram_url']) ?>"></div>
<div class="col-12"><label class="form-label">Título principal</label><input class="form-control" name="hero_title" required value="<?= htmlspecialchars($defaults['hero_title']) ?>"></div>
<div class="col-12"><label class="form-label">Subtítulo principal</label><textarea class="form-control" name="hero_subtitle" rows="2" required><?= htmlspecialchars($defaults['hero_subtitle']) ?></textarea></div>
<div class="col-md-4"><label class="form-label">Reserva (minutos)</label><input class="form-control" type="number" min="5" max="1440" name="reservation_minutes" value="<?= (int)$defaults['reservation_minutes'] ?>"></div>
<div class="col-md-4"><label class="form-label">Correo administrador</label><input class="form-control" type="email" name="admin_email" value="<?= htmlspecialchars($defaults['admin_email']) ?>"></div>
<div class="col-md-4"><label class="form-label">Remitente de correos</label><input class="form-control" type="email" name="mail_from" value="<?= htmlspecialchars($defaults['mail_from']) ?>"></div>
<div class="col-12"><label class="form-label">Métodos de pago (separados por coma)</label><input class="form-control" name="payment_methods" value="<?= htmlspecialchars($defaults['payment_methods']) ?>"></div>
<div class="col-md-6"><label class="form-label">Fondo del encabezado</label><input class="form-control" type="file" name="hero_background_file" accept="image/jpeg,image/png,image/webp"><?php if ($defaults['hero_background']): ?><img class="img-thumbnail mt-2" style="max-height:100px" src="<?= htmlspecialchars($defaults['hero_background']) ?>" alt="Fondo actual"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_hero_background" id="remove_hero_background" value="1"><label class="form-check-label" for="remove_hero_background">Quitar fondo del encabezado</label></div><?php endif; ?></div>
<div class="col-md-6"><label class="form-label">Fondo del sitio</label><input class="form-control" type="file" name="body_background_file" accept="image/jpeg,image/png,image/webp"><?php if ($defaults['body_background']): ?><img class="img-thumbnail mt-2" style="max-height:100px" src="<?= htmlspecialchars($defaults['body_background']) ?>" alt="Fondo actual"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_body_background" id="remove_body_background" value="1"><label class="form-check-label" for="remove_body_background">Quitar fondo del sitio</label></div><?php endif; ?></div>
</div>
</div><div class="card-footer bg-white text-end"><button class="btn btn-primary"><i class="bi bi-save"></i> Guardar cambios</button></div></form>
</main></body></html>
