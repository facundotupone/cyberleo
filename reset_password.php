<?php
require_once 'includes/security.php';
start_secure_session();
require_once 'includes/config.php';
require_once 'includes/db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (!$token) {
    die("Token inválido");
}

// Solo se muestra el formulario si el token sigue vigente según MySQL.
$stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(400);
    exit("El enlace caducó o es inválido.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $password = $_POST['password'];
    $password2 = $_POST['password2'];

    if ($password !== $password2) {
        $error = "Las contraseñas no coinciden.";
    } elseif (strlen($password) < 10) {
        $error = "La contraseña debe tener al menos 10 caracteres.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND reset_token = ? AND reset_expires > NOW() FOR UPDATE");
            $stmt->execute([$user['id'], $token]);
            if (!$stmt->fetchColumn()) throw new RuntimeException('El enlace caducó o es inválido.');
            $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ? AND reset_token = ?");
            $stmt->execute([$hash, $user['id'], $token]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('El enlace caducó o es inválido.');
            $pdo->commit();
            $_SESSION['password_reset_success'] = true;
            header('Location: admin_login.php?msg=password_updated');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'El enlace caducó o es inválido.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Restablecer contraseña</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="container py-5">

<h2 class="mb-4">Restablecer contraseña</h2>

<?php if ($success): ?>
<div class="alert alert-success"><?= $success ?></div>
<a href="admin_login.php" class="btn btn-success mt-3">Ir a login</a>
<?php exit; endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<form method="POST">
    <?= csrf_input() ?>
    <div class="mb-3">
        <label class="form-label">Nueva contraseña</label>
        <input type="password" class="form-control" name="password" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Confirmar contraseña</label>
        <input type="password" class="form-control" name="password2" required>
    </div>

    <button class="btn btn-primary w-100">Actualizar contraseña</button>
</form>

</body>
</html>
