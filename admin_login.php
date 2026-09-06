<?php
/**
 * Admin login — recovery-hardened entrypoint.
 * GET must render even when theme/footer/asset helpers/DB are broken.
 * POST is the only path that touches the database.
 */

if (function_exists('opcache_invalidate')) {
    foreach (array(
        __DIR__ . '/includes/asset_version.php',
        __DIR__ . '/includes/asset_safe_url.php',
        __DIR__ . '/includes/functions.php',
        __DIR__ . '/includes/theme.php',
        __DIR__ . '/includes/security.php',
        __DIR__ . '/includes/config.php',
        __DIR__ . '/includes/db.php',
        __DIR__ . '/components/footer.php',
        __DIR__ . '/components/head.php',
        __FILE__,
    ) as $cyberleoOpcacheFile) {
        if (is_file($cyberleoOpcacheFile)) {
            @opcache_invalidate($cyberleoOpcacheFile, true);
        }
    }
}

$cyberleoLoginFail = static function ($title, $hint) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error</title></head><body>';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="diag_recovery.php?opcache=reset">Diagnóstico + reset OPcache</a>';
    echo ' · <a href="emergency_admin_login.php">Login de emergencia</a></p>';
    echo '</body></html>';
    exit;
};

set_exception_handler(static function (Throwable $e) use ($cyberleoLoginFail) {
    error_log('cyberleo admin_login uncaught: ' . $e->getMessage());
    $cyberleoLoginFail(
        'No se pudo cargar el login',
        'Extrá el ZIP de recuperación sobre public_html, reiniciá PHP en hPanel y abrí emergency_admin_login.php.'
    );
});

register_shutdown_function(static function () use ($cyberleoLoginFail) {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    // Avoid double-output when exception handler already responded.
    if (headers_sent()) {
        return;
    }
    error_log('cyberleo admin_login shutdown: ' . $err['message']);
    $cyberleoLoginFail(
        'No se pudo cargar el login',
        'Fatal de PHP. Usá /diag_recovery.php?opcache=reset y /emergency_admin_login.php.'
    );
});

$error = '';
$brandLogoPath = 'assets/images/brand/cyberleo-logo.png';
$loginStyleHref = 'assets/css/style.css';
$themeCss = '';
$storeTitle = 'CyberLeo';

// Session only — never pull theme/functions/assets for the form shell.
$securityFile = __DIR__ . '/includes/security.php';
if (!is_file($securityFile) || !is_readable($securityFile)) {
    $cyberleoLoginFail('Falta includes/security.php', 'Subí el ZIP de recuperación completo sobre public_html.');
}
require_once $securityFile;
if (!function_exists('start_secure_session')) {
    $cyberleoLoginFail('security.php incompleto', 'Subí el ZIP de recuperación completo sobre public_html.');
}
start_secure_session();

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_products.php');
    exit;
}

// Soft optional CSS/logo — never fatal.
if (is_file(__DIR__ . '/includes/asset_safe_url.php') && is_readable(__DIR__ . '/includes/asset_safe_url.php')) {
    require_once __DIR__ . '/includes/asset_safe_url.php';
}
if (function_exists('cyberleo_safe_asset_url')) {
    $loginStyleHref = cyberleo_safe_asset_url('assets/css/style.css');
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
        require_once __DIR__ . '/includes/config.php';
        require_once __DIR__ . '/includes/db.php';
        $key = enforce_auth_rate_limit($pdo, 'login|' . strtolower($username));
        if ($username !== '' && $password !== '') {
            $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ?');
            $stmt->execute(array($username));
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['admin_logged_in'] = true;
                session_regenerate_id(true);
                $stmt = $pdo->prepare('UPDATE users SET reset_token=NULL, reset_expires=NULL WHERE id=?');
                $stmt->execute(array($user['id']));
                $_SESSION['admin_id'] = $user['id'];
                clear_auth_rate_limit($pdo, $key);
                header('Location: admin_products.php');
                exit;
            }
            $error = 'Usuario o contraseña incorrectos';
        } else {
            $error = 'Por favor, complete todos los campos';
        }
    } catch (RateLimitException $e) {
        http_response_code(429);
        header('Retry-After: 900');
        $error = 'Demasiados intentos. Intentá más tarde.';
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $error = 'No se pudo validar el acceso. Revisá diag_recovery.php y la base de datos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Administrador - <?= htmlspecialchars($storeTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if (is_file(__DIR__ . '/' . strtok($loginStyleHref, '?'))): ?>
    <link href="<?= htmlspecialchars($loginStyleHref, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php endif; ?>
    <style><?= $themeCss ?></style>
</head>
<body class="admin-login-page">
    <div class="container my-5 flex-grow-1">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card login-card">
                    <div class="card-body p-4">
                        <div class="text-center">
                            <img
                                src="<?= htmlspecialchars($brandLogoPath, ENT_QUOTES, 'UTF-8') ?>"
                                alt="CyberLeo"
                                class="brand-logo brand-logo-login"
                                width="240"
                                height="72"
                                decoding="async"
                            >
                        </div>
                        <h2 class="card-title text-center mb-4 h4">Acceso Administrador</h2>

                        <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
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
                            <a href="forgot_password.php" class="d-block text-center mt-3">¿Olvidaste tu contraseña?</a>
                            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'recovery_sent'): ?>
                            <div class="alert alert-success">Te enviamos un correo con instrucciones para recuperar tu contraseña.</div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary w-100">Iniciar Sesión</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
