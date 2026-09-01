<?php
require_once 'includes/security.php';
start_secure_session();
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    // Validación básica
    if (empty($email)) {
        $notice = "Si el correo está registrado, recibirás instrucciones.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $notice = "Si el correo está registrado, recibirás instrucciones.";
    }

    if (!$notice) {
        // Buscar usuario por mail
        $stmt = $pdo->prepare("SELECT id, username, mail FROM users WHERE mail = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $settings = get_store_settings();
        if ($user && filter_var($settings['mail_from'], FILTER_VALIDATE_EMAIL)) {

            // Generar token
            $token = bin2hex(random_bytes(32)); // 64 caracteres
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hora

            // Guardar en DB
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $stmt->execute([$token, $expiresAt, $user['id']]);

            // Link de reseteo
            $resetLink = rtrim(SITE_URL, '/') . "/reset_password.php?token=" . urlencode($token);

            // Email
            $to = $user['mail'];
            $subject = "Recuperación de contraseña - " . $settings['store_name'];

            $message = "
            <!DOCTYPE html>
            <html>
            <body style='background:#f2f4f7;font-family:Arial,sans-serif;padding:20px;margin:0;'>
            
            <div style='max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:30px;box-shadow:0 4px 18px rgba(0,0,0,0.08);'>
            
                <h1 style='color:#2BBFBD;text-align:center;font-size:24px;'>" . $settings['store_name'] . "</h1>
            
                <h2 style='color:#333;margin:0 0 10px;font-size:20px;font-weight:600;text-align:center;'>
                    Recuperar contraseña
                </h2>
            
                <p style='color:#555;font-size:15px;line-height:1.6;'>
                    Hola <strong>{$user['username']}</strong>,<br><br>
                    Solicitaste restablecer tu contraseña. Hacé click en el siguiente botón para continuar.
                </p>
            
                <div style='text-align:center;margin:30px 0;'>
                    <a href='$resetLink' 
                       style='background:#6c63ff;color:#fff;text-decoration:none;font-weight:600;
                              padding:14px 24px;border-radius:8px;display:inline-block;font-size:15px;'>
                        Restablecer contraseña
                    </a>
                </div>
            
                <p style='color:#555;font-size:14px;line-height:1.6;'>
                    Si no fuiste vos, simplemente ignorá este mensaje.
                </p>
            
                <hr style='border:none;border-top:1px solid #eee;margin:25px 0;'>
            
                <p style='color:#888;font-size:12px;text-align:center;line-height:1.5;margin:0;'>
                    Este enlace es válido por 1 hora.<br>
                    " . $settings['store_name'] . " © " . date('Y') . "
                </p>
            
            </div>
            
            </body>
            </html>
            ";

            // Headers correctos
            $headers  = "From: " . $settings['store_name'] . " <{$settings['mail_from']}>\r\n";
            $headers .= "Reply-To: " . ($settings['admin_email'] ?: $settings['mail_from']) . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";


            // Enviar mail
            if (!mail($to, $subject, $message, $headers)) error_log('Password reset email delivery failed.');

            // Redirigir al login con mensaje
        }
        $notice = "Si el correo está registrado, recibirás instrucciones.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Recuperar contraseña</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    body {
        background: #f8f9fa;
    }
    .card {
        border-radius: 10px;
        border: none;
        box-shadow: 0 2px 10px rgba(0,0,0,0.07);
    }
</style>

</head>
<body class="container py-5">

    <div class="row justify-content-center">
        <div class="col-md-5">

            <div class="card p-4">
                <h2 class="mb-4 text-center">Recuperar contraseña</h2>

                <?php if ($notice): ?>
                    <div class="alert alert-info"><?= htmlspecialchars($notice) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Mail registrado</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>

                    <button class="btn btn-primary w-100">Enviar email</button>
                </form>

                <a href="admin_login.php" class="d-block text-center mt-3">
                    Volver al login
                </a>
            </div>

        </div>
    </div>

</body>
</html>
