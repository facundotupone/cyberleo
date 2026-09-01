<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (!$token) {
    die("Token inválido");
}

// Buscar usuario por token válido
$stmt = $pdo->prepare("SELECT id, reset_expires FROM users WHERE reset_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user || strtotime($user['reset_expires']) < time()) {
    die("El enlace caducó o es inválido.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $password2 = $_POST['password2'];

    if ($password !== $password2) {
        $error = "Las contraseñas no coinciden.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Actualizar y limpiar token
        $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $stmt->execute([$hash, $user['id']]);

        $success = "Contraseña actualizada. Ahora podés iniciar sesión.";
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
