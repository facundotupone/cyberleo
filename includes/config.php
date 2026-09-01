<?php
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) require_once $localConfig;
function config_value($name, $default = '') {
    $value = getenv($name);
    if ($value !== false && $value !== '') return $value;
    return defined($name) ? constant($name) : $default;
}
defined('DB_HOST') || define('DB_HOST', config_value('DB_HOST', 'localhost'));
defined('DB_USER') || define('DB_USER', config_value('DB_USER', ''));
defined('DB_PASS') || define('DB_PASS', config_value('DB_PASS', ''));
defined('DB_NAME') || define('DB_NAME', config_value('DB_NAME', ''));
defined('SITE_URL') || define('SITE_URL', config_value('SITE_URL', 'http://localhost:8000'));
defined('STORE_NAME') || define('STORE_NAME', config_value('STORE_NAME', 'CyberLeo'));
defined('WHATSAPP_NUMBER') || define('WHATSAPP_NUMBER', config_value('WHATSAPP_NUMBER', '5491100000000'));
defined('STORE_INSTAGRAM') || define('STORE_INSTAGRAM', config_value('STORE_INSTAGRAM', ''));
defined('APP_SECRET') || define('APP_SECRET', config_value('APP_SECRET', ''));
