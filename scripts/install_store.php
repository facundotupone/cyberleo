<?php
declare(strict_types=1);

/**
 * Instalador privado CLI de CyberLeo.
 * Uso: php scripts/install_store.php --public-root=/ruta/absoluta/public_html
 */

require_once __DIR__ . '/lib/maintenance.php';

maintenance_require_cli();
maintenance_require_zip_and_proc();

$publicRootArg = '';
$nonInteractive = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--public-root=')) {
        $publicRootArg = substr($arg, strlen('--public-root='));
    } elseif ($arg === '--non-interactive') {
        $nonInteractive = true;
    } elseif (str_starts_with($arg, '--db-pass=') || str_starts_with($arg, '--password=') || str_starts_with($arg, '--admin-pass=')) {
        maintenance_fail('Las contraseñas no se aceptan como argumentos visibles de línea de comandos.');
    } else {
        maintenance_fail("Argumento no reconocido: {$arg}\nUso: php scripts/install_store.php --public-root=/ruta/public_html");
    }
}
if ($publicRootArg === '') {
    maintenance_fail("Falta --public-root.\nUso: php scripts/install_store.php --public-root=/ruta/public_html");
}

$publicRoot = maintenance_validate_public_root($publicRootArg);
$configLocal = $publicRoot . '/includes/config.local.php';
if (is_file($configLocal) || is_link($configLocal)) {
    maintenance_fail('La tienda ya está instalada (config.local.php presente). Segunda ejecución rechazada.');
}

$creds = maintenance_obtain_db_credentials(!$nonInteractive);

$storeName = getenv('STORE_NAME');
$siteUrl = getenv('SITE_URL');
$whatsapp = getenv('WHATSAPP_NUMBER');
$adminUser = getenv('ADMIN_USERNAME');
$adminMail = getenv('ADMIN_EMAIL');
$adminPass = getenv('ADMIN_PASSWORD');

if (!is_string($storeName) || $storeName === '') {
    if ($nonInteractive) {
        maintenance_fail('Falta STORE_NAME.');
    }
    $storeName = trim(maintenance_prompt('Nombre de la tienda: '));
}
if (!is_string($siteUrl) || $siteUrl === '') {
    if ($nonInteractive) {
        maintenance_fail('Falta SITE_URL.');
    }
    $siteUrl = trim(maintenance_prompt('SITE_URL (https://...): '));
}
if (!is_string($whatsapp) || $whatsapp === '') {
    if ($nonInteractive) {
        maintenance_fail('Falta WHATSAPP_NUMBER.');
    }
    $whatsapp = trim(maintenance_prompt('WhatsApp (solo dígitos, 8-16): '));
}
if (!is_string($adminUser) || $adminUser === '') {
    if ($nonInteractive) {
        maintenance_fail('Falta ADMIN_USERNAME.');
    }
    $adminUser = trim(maintenance_prompt('Usuario administrador: '));
}
if ($adminMail === false || $adminMail === null) {
    $adminMail = $nonInteractive ? '' : trim(maintenance_prompt('Email administrador (opcional): '));
}
$adminMail = is_string($adminMail) ? trim($adminMail) : '';
if (!is_string($adminPass) || $adminPass === '') {
    if ($nonInteractive) {
        maintenance_fail('Falta ADMIN_PASSWORD en el entorno.');
    }
    $adminPass = maintenance_prompt('Contraseña administrativa (mín. 12): ', true);
    $confirm = maintenance_prompt('Repetir contraseña: ', true);
    if (!hash_equals($adminPass, $confirm)) {
        maintenance_fail('Las contraseñas no coinciden.');
    }
}

$storeName = trim($storeName);
$siteUrl = trim($siteUrl);
$whatsapp = trim($whatsapp);
$adminUser = trim($adminUser);

if ($storeName === '' || mb_strlen($storeName) > 120) {
    maintenance_fail('Nombre de tienda inválido.');
}
if (!maintenance_validate_site_url($siteUrl)) {
    maintenance_fail('SITE_URL inválida (HTTPS obligatorio salvo localhost).');
}
if (!maintenance_validate_whatsapp($whatsapp)) {
    maintenance_fail('WhatsApp inválido: solo 8–16 dígitos.');
}
if (!maintenance_validate_username($adminUser)) {
    maintenance_fail('Usuario inválido: 3–80 caracteres [A-Za-z0-9_].');
}
if ($adminMail !== '' && !filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
    maintenance_fail('Email inválido.');
}
if (!maintenance_validate_admin_password($adminPass)) {
    maintenance_fail('La contraseña administrativa debe tener al menos 12 caracteres.');
}

$pdo = maintenance_pdo($creds);
if (!maintenance_database_is_empty($pdo)) {
    maintenance_fail('La base no está vacía. El instalador solo opera sobre una base sin tablas. Creá una base nueva e intentá de nuevo.');
}

$schemaPath = maintenance_schema_path();
$schemaImported = false;
$adminCreated = false;
$appSecret = bin2hex(random_bytes(32));

try {
    maintenance_import_schema($creds, $schemaPath);
    $schemaImported = true;

    // Reconnect after schema import.
    $pdo = maintenance_pdo($creds);
    if (maintenance_database_is_empty($pdo)) {
        maintenance_fail('El esquema no se materializó. Recreá la base vacía e intentá de nuevo.');
    }

    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        maintenance_fail('No se pudo generar el hash de la contraseña.');
    }
    $stmt = $pdo->prepare('INSERT INTO users (username, password, mail) VALUES (?, ?, ?)');
    $stmt->execute([$adminUser, $hash, $adminMail !== '' ? $adminMail : null]);
    $adminCreated = true;

    $upsert = $pdo->prepare(
        'INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $upsert->execute(['store_name', $storeName]);
    $upsert->execute(['whatsapp_number', $whatsapp]);

    maintenance_write_config_local($publicRoot, [
        'DB_HOST' => !empty($creds['socket'])
            ? ($creds['host'] . ';unix_socket=' . $creds['socket'])
            : $creds['host'],
        'DB_USER' => $creds['user'],
        'DB_PASS' => $creds['pass'],
        'DB_NAME' => $creds['name'],
        'SITE_URL' => $siteUrl,
        'STORE_NAME' => $storeName,
        'WHATSAPP_NUMBER' => $whatsapp,
        'STORE_INSTAGRAM' => '',
        'APP_SECRET' => $appSecret,
    ]);
} catch (Throwable $e) {
    $hint = "Instalación incompleta.\n"
        . "No se realizó rollback destructivo automático.\n"
        . "Recreá la base temporal vacía (DROP DATABASE / CREATE DATABASE) y volvé a ejecutar el instalador.\n";
    if ($schemaImported && !$adminCreated) {
        $hint .= "El esquema se importó pero el administrador no quedó creado.\n";
    } elseif ($adminCreated) {
        $hint .= "El administrador pudo haberse creado; eliminá config.local.php solo si no existe y recreá la base.\n";
    }
    fwrite(STDERR, $hint);
    // Never dump exception messages that might contain credentials.
    maintenance_fail('Error durante la instalación (detalle omitido por seguridad).');
}

fwrite(STDOUT, "Instalación completada.\n");
fwrite(STDOUT, "Public root: validado\n");
fwrite(STDOUT, "Base: esquema importado\n");
fwrite(STDOUT, "Administrador: creado\n");
fwrite(STDOUT, "config.local.php: escrito (permisos restrictivos)\n");

// Post-install diagnose without printing secrets.
require_once $publicRoot . '/includes/system_health.php';
// Load config from public root for checks.
require_once $publicRoot . '/includes/config.php';
$diagPdo = maintenance_pdo($creds);
$checks = system_health_run_checks($diagPdo, $publicRoot);
$summary = system_health_summary($checks);
foreach ($checks as $check) {
    $line = sprintf("[%s] %s — %s\n", $check['status'], $check['label'], $check['detail']);
    if (maintenance_output_contains_secret($line, $creds, $appSecret)) {
        continue;
    }
    fwrite(STDOUT, $line);
}
fwrite(STDOUT, sprintf(
    "Resumen diagnóstico: %s (PASS=%d WARN=%d FAIL=%d)\n",
    $summary['status'],
    $summary['pass'],
    $summary['warn'],
    $summary['fail']
));
exit(system_health_has_fail($checks) ? 1 : 0);
