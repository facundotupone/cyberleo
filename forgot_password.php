<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    // Validación básica
    if (empty($email)) {
        $error = "Ingrese su email.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Formato de email inválido.";
    }

    if (!$error) {
        // Buscar usuario por mail
        $stmt = $pdo->prepare("SELECT id, username, mail FROM users WHERE mail = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {

            // Generar token
            $token = bin2hex(random_bytes(32)); // 64 caracteres
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hora

            // Guardar en DB
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $stmt->execute([$token, $expiresAt, $user['id']]);

            // Link de reseteo
            $resetLink = "https://happyears.somoscrear.com.ar/reset_password.php?token=" . urlencode($token);

            // Email
            $to = $user['mail'];
            $subject = "Recuperación de contraseña - HappyEars";

            $logo = "https://happyears.somoscrear.com.ar/assets/images/happyears.png";

            $message = "
            <!DOCTYPE html>
            <html>
            <body style='background:#f2f4f7;font-family:Arial,sans-serif;padding:20px;margin:0;'>
            
            <div style='max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:30px;box-shadow:0 4px 18px rgba(0,0,0,0.08);'>
            
                <div style='text-align:center;margin-bottom:20px;'>
                    <img src='$logo' alt='HappyEars' style='width:140px;'>
                </div>
            
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
                    HappyEars © " . date('Y') . "
                </p>
            
            </div>
            
            </body>
            </html>
            ";

            // Headers correctos
            $headers  = "From: HappyEars <happyears@somoscrear.com.ar>\r\n";
            $headers .= "Reply-To: happyears@somoscrear.com.ar\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";


            // Enviar mail
            @mail($to, $subject, $message, $headers);

            // Redirigir al login con mensaje
            header("Location: admin_login.php?msg=recovery_sent");
            exit;

        } else {
            $error = "El mail no está registrado.";
        }
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

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
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
