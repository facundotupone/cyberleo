<?php
/**
 * Emergency admin login — NEW filename to bypass poisoned OPcache of admin_login.php.
 * Self-contained GET form. DB only on POST. Delete after recovery if desired.
 */

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

header('Cache-Control: no-store');

$error = '';
$storeTitle = 'CyberLeo';

$securityFile = __DIR__ . '/includes/security.php';
if (is_file($securityFile) && is_readable($securityFile)) {
    require_once $securityFile;
    if (function_exists('start_secure_session')) {
        start_secure_session();
    }
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: admin_products.php');
    exit;
}

if (is_file(__DIR__ . '/includes/config.php') && is_readable(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
    if (defined('STORE_NAME') && STORE_NAME !== '') {
        $storeTitle = STORE_NAME;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    try {
        if (!is_file(__DIR__ . '/includes/db.php')) {
            throw new RuntimeException('Missing db.php');
        }
        require_once __DIR__ . '/includes/config.php';
        require_once __DIR__ . '/includes/db.php';
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new RuntimeException('PDO unavailable');
        }
        if ($username === '' || $password === '') {
            $error = 'Por favor, complete todos los campos';
        } else {
            if (function_exists('enforce_auth_rate_limit')) {
                $key = enforce_auth_rate_limit($pdo, 'login|' . strtolower($username));
            } else {
                $key = null;
            }
            $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ?');
            $stmt->execute(array($username));
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['admin_logged_in'] = true;
                if (function_exists('session_regenerate_id')) {
                    session_regenerate_id(true);
                }
                $_SESSION['admin_id'] = $user['id'];
                if ($key !== null && function_exists('clear_auth_rate_limit')) {
                    clear_auth_rate_limit($pdo, $key);
                }
                header('Location: admin_products.php');
                exit;
            }
            $error = 'Usuario o contraseña incorrectos';
        }
    } catch (Throwable $e) {
        error_log('cyberleo emergency_admin_login: ' . $e->getMessage());
        $error = 'No se pudo validar el acceso. Abrí diag_recovery.php?opcache=reset';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login emergencia - <?= htmlspecialchars($storeTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="alert alert-warning">Login de emergencia (anti-OPcache). Preferí admin_login.php cuando esté sano.</div>
                <div class="card">
                    <div class="card-body p-4">
                        <h1 class="h4 text-center mb-4">Acceso Administrador</h1>
                        <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">Usuario</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Contraseña</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Iniciar Sesión</button>
                        </form>
                        <p class="mt-3 mb-0 small text-center">
                            <a href="diag_recovery.php?opcache=reset">Diagnóstico</a>
                            · <a href="admin_login.php">Login normal</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
