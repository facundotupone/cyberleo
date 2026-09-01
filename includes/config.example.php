<?php
// Copiar como config.local.php para desarrollo; nunca versionar credenciales.
define('DB_HOST', 'localhost');
define('DB_USER', 'usuario_local');
define('DB_PASS', 'cambiar_esta_clave');
define('DB_NAME', 'cyberleo');
define('SITE_URL', 'http://localhost:8000');
define('STORE_NAME', 'CyberLeo');
define('WHATSAPP_NUMBER', '5491100000000');
define('STORE_INSTAGRAM', '');
// Generar con: php -r "echo bin2hex(random_bytes(32));"
define('APP_SECRET', 'reemplazar_por_secreto_aleatorio_local');
