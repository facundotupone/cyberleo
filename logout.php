<?php
require_once 'includes/security.php';
start_secure_session();

// Destruir todas las variables de sesión
$_SESSION = [];

// Destruir la sesión en el servidor
session_unset();
session_destroy();

// Borrar cookie de sesión (opcional pero recomendable)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// (Opcional) redirigir a login
header("Location: admin_login.php");
exit;
