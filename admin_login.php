<?php
require_once 'includes/security.php';
start_secure_session();
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/theme.php';

// Soft-load shared safe resolver when present (partial deploys may omit it).
if (!function_exists('cyberleo_safe_asset_url')) {
    $cyberleoAssetSafeUrl = __DIR__ . '/includes/asset_safe_url.php';
    if (is_file($cyberleoAssetSafeUrl) && is_readable($cyberleoAssetSafeUrl)) {
        require_once $cyberleoAssetSafeUrl;
    }
}

// Last-resort polyfill: login must survive even if both helper files are absent.
if (!function_exists('cyberleo_safe_asset_url')) {
    function cyberleo_safe_asset_url($relativePath)
    {
        $relativePath = is_string($relativePath) ? trim($relativePath) : '';
        if ($relativePath === '') {
            return 'assets/css/style.css';
        }
        $helper = __DIR__ . '/includes/asset_version.php';
        if (!is_file($helper) || !is_readable($helper)) {
            error_log('cyberleo asset version helper unavailable; using unversioned assets');
            return $relativePath;
        }
        if (!function_exists('cyberleo_asset_url')) {
            require_once $helper;
        }
        if (!function_exists('cyberleo_asset_url')) {
            error_log('cyberleo asset version function missing; using unversioned assets');
            return $relativePath;
        }
        try {
            $url = cyberleo_asset_url($relativePath);
            return (is_string($url) && $url !== '') ? $url : $relativePath;
        } catch (Throwable $e) {
            error_log('cyberleo asset version failed; using unversioned assets');
            return $relativePath;
        }
    }
}

$loginStyleHref = cyberleo_safe_asset_url('assets/css/style.css');

// Si ya está logueado, redirigir al panel de administración
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_products.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    try {
    $key = enforce_auth_rate_limit($pdo, 'login|' . strtolower($username));
    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

       if ($user && password_verify($password, $user['password'])) {
            $_SESSION['admin_logged_in'] = true;

            session_regenerate_id(true);

            $stmt = $pdo->prepare("UPDATE users SET reset_token=NULL, reset_expires=NULL WHERE id=?");
            $stmt->execute([$user['id']]);

            $_SESSION['admin_id'] = $user['id'];
            clear_auth_rate_limit($pdo, $key);
            header('Location: admin_products.php');
            exit;
        }
        else {
            $error = 'Usuario o contraseña incorrectos';
        }
    } else {
        $error = 'Por favor, complete todos los campos';
    }
    } catch (RateLimitException $e) {
        http_response_code(429); header('Retry-After: 900'); $error = 'Demasiados intentos. Intentá más tarde.';
    } catch (Throwable $e) { error_log($e->getMessage()); $error = 'Usuario o contraseña incorrectos'; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Administrador - <?= htmlspecialchars(STORE_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($loginStyleHref, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <style><?php
        $themeSettings = resolve_theme_settings(get_store_settings());
        echo theme_css_custom_properties($themeSettings);
        $brandLogoPath = is_safe_brand_logo_path($themeSettings['brand_logo']) ? $themeSettings['brand_logo'] : THEME_OFFICIAL_LOGO;
    ?></style>
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
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
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
                            <div>
                            <a href="forgot_password.php" class="d-block text-center mt-3">
                                ¿Olvidaste tu contraseña?
                            </a>
                            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'recovery_sent'): ?>
                            <div class="alert alert-success">

                                Te enviamos un correo con instrucciones para recuperar tu contraseña.
                            </div>
                        <?php endif; ?>
                            <button type="submit" class="btn btn-primary w-100">Iniciar Sesión</button>
                        </form>



                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once 'components/footer.php'; ?>
</body>
</html>
