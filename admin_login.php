<?php
/**
 * Admin login — dependency-light recovery entrypoint.
 * Must keep working even when theme/footer/asset helpers are broken or partial.
 */

if (function_exists('opcache_invalidate')) {
    foreach (array(
        __DIR__ . '/includes/asset_version.php',
        __DIR__ . '/includes/asset_safe_url.php',
        __DIR__ . '/includes/functions.php',
        __DIR__ . '/includes/theme.php',
        __DIR__ . '/components/footer.php',
        __DIR__ . '/components/head.php',
        __FILE__,
    ) as $cyberleoOpcacheFile) {
        if (is_file($cyberleoOpcacheFile)) {
            opcache_invalidate($cyberleoOpcacheFile, true);
        }
    }
}

set_exception_handler(static function (Throwable $e) {
    http_response_code(500);
    error_log('cyberleo admin_login uncaught: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error</title></head><body>';
    echo '<h1>No se pudo cargar el login</h1>';
    echo '<p>Extrá el ZIP de reparación integral sobre public_html y reiniciá PHP en hPanel.</p>';
    echo '</body></html>';
    exit;
});

require_once 'includes/security.php';
start_secure_session();
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Optional theme — never take down login if theme stack is incomplete.
$brandLogoPath = 'assets/images/brand/cyberleo-logo.png';
$themeCss = '';
if (is_file(__DIR__ . '/includes/theme.php') && is_readable(__DIR__ . '/includes/theme.php')) {
    require_once __DIR__ . '/includes/theme.php';
    try {
        if (function_exists('resolve_theme_settings') && function_exists('get_store_settings')) {
            $themeSettings = resolve_theme_settings(get_store_settings());
            if (function_exists('theme_css_custom_properties')) {
                $themeCss = theme_css_custom_properties($themeSettings);
            }
            if (function_exists('is_safe_brand_logo_path') && !empty($themeSettings['brand_logo']) && is_safe_brand_logo_path($themeSettings['brand_logo'])) {
                $brandLogoPath = $themeSettings['brand_logo'];
            } elseif (defined('THEME_OFFICIAL_LOGO')) {
                $brandLogoPath = THEME_OFFICIAL_LOGO;
            }
        }
    } catch (Throwable $e) {
        error_log('cyberleo admin_login theme fallback: ' . $e->getMessage());
    }
}

// CSS: prefer plain local file; never hard-require asset versioning.
$loginStyleHref = 'assets/css/style.css';
if (is_file(__DIR__ . '/includes/asset_safe_url.php') && is_readable(__DIR__ . '/includes/asset_safe_url.php')) {
    require_once __DIR__ . '/includes/asset_safe_url.php';
}
if (function_exists('cyberleo_safe_asset_url')) {
    $loginStyleHref = cyberleo_safe_asset_url('assets/css/style.css');
}

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_products.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    try {
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
        $error = 'Usuario o contraseña incorrectos';
    }
}

$storeTitle = defined('STORE_NAME') ? STORE_NAME : 'CyberLeo';
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
