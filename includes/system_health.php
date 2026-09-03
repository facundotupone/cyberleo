<?php
declare(strict_types=1);

/**
 * Controles de salud seguros para CLI y panel admin (sin secretos ni rutas absolutas).
 * Nunca lanza excepciones hacia el llamador.
 */

if (!defined('CYBERLEO_OFFICIAL_LOGO_SHA256')) {
    define('CYBERLEO_OFFICIAL_LOGO_SHA256', '1c209559ea540fa864ba3e3bd17be1f6cdb823582389cd65224a2382849b456b');
}

/**
 * @return list<array{id:string,label:string,status:string,detail:string}>
 */
function system_health_run_checks(?PDO $pdo = null, ?string $publicRoot = null): array
{
    try {
        return system_health_run_checks_inner($pdo, $publicRoot);
    } catch (Throwable $e) {
        return [[
            'id' => 'health_runner',
            'label' => 'Controles de salud',
            'status' => 'FAIL',
            'detail' => 'Control no disponible.',
        ]];
    }
}

/**
 * @return list<array{id:string,label:string,status:string,detail:string}>
 */
function system_health_run_checks_inner(?PDO $pdo = null, ?string $publicRoot = null): array
{
    $checks = [];
    $add = static function (string $id, string $label, string $status, string $detail = '') use (&$checks): void {
        $checks[] = [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'detail' => system_health_sanitize_detail($detail),
        ];
    };

    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $add('php_version', 'PHP 8.0+', $phpOk ? 'PASS' : 'FAIL', $phpOk ? 'Versión compatible' : 'Se requiere PHP 8.0 o superior');

    $extensions = ['pdo', 'pdo_mysql', 'mbstring', 'fileinfo', 'json', 'session'];
    foreach ($extensions as $ext) {
        $ok = extension_loaded($ext);
        $add('ext_' . $ext, 'Extensión ' . $ext, $ok ? 'PASS' : 'FAIL', $ok ? 'Disponible' : 'Ausente');
    }

    $zipOk = class_exists('ZipArchive');
    $add('ext_zip', 'ZipArchive (mantenimiento)', $zipOk ? 'PASS' : 'WARN', $zipOk ? 'Disponible' : 'Requerido para backup/restore CLI');

    $procOk = function_exists('proc_open');
    $add('proc_open', 'proc_open (mantenimiento)', $procOk ? 'PASS' : 'WARN', $procOk ? 'Disponible' : 'Requerido para dump/import CLI');

    $mysqlBin = system_health_find_bin('mysql');
    if ($mysqlBin === null) {
        $add('mysql_cli', 'Cliente mysql (mantenimiento)', 'WARN', 'mysql no está disponible en PATH');
    } elseif (!@is_executable($mysqlBin)) {
        $add('mysql_cli', 'Cliente mysql (mantenimiento)', 'WARN', 'mysql encontrado pero no ejecutable');
    } else {
        $add('mysql_cli', 'Cliente mysql (mantenimiento)', 'PASS', 'Disponible y ejecutable');
    }

    $dumpBin = system_health_find_bin('mysqldump');
    if ($dumpBin === null) {
        $add('mysqldump_cli', 'Cliente mysqldump (mantenimiento)', 'WARN', 'mysqldump no está disponible en PATH');
    } elseif (!@is_executable($dumpBin)) {
        $add('mysqldump_cli', 'Cliente mysqldump (mantenimiento)', 'WARN', 'mysqldump encontrado pero no ejecutable');
    } else {
        $add('mysqldump_cli', 'Cliente mysqldump (mantenimiento)', 'PASS', 'Disponible y ejecutable');
    }

    $configComplete = defined('DB_HOST') && DB_HOST !== ''
        && defined('DB_NAME') && DB_NAME !== ''
        && defined('DB_USER') && DB_USER !== ''
        && defined('DB_PASS');
    $add('config_complete', 'Configuración de base', $configComplete ? 'PASS' : 'FAIL', $configComplete ? 'Constantes presentes' : 'Config incompleta');

    $secretOk = defined('APP_SECRET') && is_string(APP_SECRET) && strlen(APP_SECRET) >= 32;
    $add('app_secret', 'Secreto de aplicación', $secretOk ? 'PASS' : 'FAIL', $secretOk ? 'Presente y con longitud suficiente' : 'Ausente o demasiado corto');

    $siteOk = defined('SITE_URL') && SITE_URL !== '';
    $httpsOk = $siteOk && (str_starts_with((string) SITE_URL, 'https://')
        || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?(/|$)#i', (string) SITE_URL));
    $add('site_url', 'SITE_URL', !$siteOk ? 'FAIL' : ($httpsOk ? 'PASS' : 'WARN'), $siteOk ? ($httpsOk ? 'Formato aceptable' : 'Se recomienda HTTPS') : 'Ausente');

    $localConfigReadable = false;
    $localConfigExists = false;
    if ($publicRoot !== null) {
        $local = $publicRoot . '/includes/config.local.php';
        $localConfigExists = is_file($local) && !is_link($local);
        $localConfigReadable = $localConfigExists && is_readable($local);
    } else {
        $local = __DIR__ . '/config.local.php';
        $localConfigExists = is_file($local) && !is_link($local);
        $localConfigReadable = $localConfigExists && is_readable($local);
    }
    $add(
        'config_local',
        'config.local.php',
        !$localConfigExists ? 'FAIL' : ($localConfigReadable ? 'PASS' : 'FAIL'),
        !$localConfigExists ? 'No encontrado' : ($localConfigReadable ? 'Presente y legible' : 'No legible')
    );

    $dbOk = false;
    if ($pdo instanceof PDO) {
        try {
            $pdo->query('SELECT 1');
            $dbOk = true;
        } catch (Throwable $e) {
            $dbOk = false;
        }
    }
    $add('db_connection', 'Conexión a base de datos', $dbOk ? 'PASS' : 'FAIL', $dbOk ? 'Conectado' : 'Sin conexión');

    $requiredTables = [
        'users', 'categories', 'subcategories', 'products', 'product_images',
        'orders', 'order_items', 'store_settings', 'order_rate_limits', 'auth_rate_limits',
    ];
    if ($dbOk && $pdo instanceof PDO) {
        try {
            foreach ($requiredTables as $table) {
                $exists = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($table)
                )->fetchColumn();
                $add('table_' . $table, 'Tabla ' . $table, $exists === 1 ? 'PASS' : 'FAIL', $exists === 1 ? 'Presente' : 'Ausente');
            }
            $colChecks = [
                ['orders', 'idempotency_key', 'char(64)'],
                ['orders', 'expires_at', 'datetime'],
                ['products', 'stock', null],
                ['products', 'is_active', null],
                ['users', 'password', null],
            ];
            foreach ($colChecks as [$table, $column, $type]) {
                $sql = "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = "
                    . $pdo->quote($table) . " AND column_name = " . $pdo->quote($column);
                if ($type !== null) {
                    $sql .= ' AND column_type = ' . $pdo->quote($type);
                }
                $ok = (int) $pdo->query($sql)->fetchColumn() === 1;
                $add('col_' . $table . '_' . $column, "Columna {$table}.{$column}", $ok ? 'PASS' : 'FAIL', $ok ? 'OK' : 'Falta o tipo inesperado');
            }
            $idx = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'uq_orders_idempotency_key' AND non_unique = 0"
            )->fetchColumn();
            $add('idx_idempotency', 'Índice único idempotency', $idx === 1 ? 'PASS' : 'FAIL', $idx === 1 ? 'Presente' : 'Ausente');
        } catch (Throwable $e) {
            $add('schema_error', 'Esquema', 'FAIL', 'No se pudo verificar el esquema.');
        }
    } else {
        $add('schema_skipped', 'Esquema', 'FAIL', 'No se pudo verificar el esquema sin conexión');
    }

    $root = $publicRoot ?? dirname(__DIR__);
    foreach (['products', 'settings'] as $scope) {
        $dir = $root . '/assets/images/' . $scope;
        $exists = is_dir($dir) && !is_link($dir);
        $readable = $exists && @is_readable($dir);
        $writable = $exists && @is_writable($dir);
        $ht = $exists && is_file($dir . '/.htaccess') && !is_link($dir . '/.htaccess');
        $add('upload_' . $scope . '_dir', "Uploads {$scope}", $exists ? 'PASS' : 'FAIL', $exists ? 'Directorio presente' : 'Ausente');
        $add('upload_' . $scope . '_read', "Lectura {$scope}", $readable ? 'PASS' : 'FAIL', $readable ? 'Legible' : 'Ilegible o ausente');
        $add('upload_' . $scope . '_write', "Escritura {$scope}", $writable ? 'PASS' : 'FAIL', $writable ? 'Escribible' : 'No escribible');
        $add('upload_' . $scope . '_htaccess', ".htaccess {$scope}", $ht ? 'PASS' : 'WARN', $ht ? 'Presente' : 'Ausente');

        $symlinkFound = false;
        $scanOk = true;
        if ($exists && $readable) {
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if (is_link($file->getPathname())) {
                        $symlinkFound = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                $scanOk = false;
            }
        } elseif ($exists && !$readable) {
            $scanOk = false;
        }
        if (!$scanOk) {
            $add('upload_' . $scope . '_symlinks', "Sin symlinks {$scope}", 'WARN', 'No se pudo inspeccionar el directorio');
        } else {
            $add('upload_' . $scope . '_symlinks', "Sin symlinks {$scope}", $symlinkFound ? 'FAIL' : 'PASS', $symlinkFound ? 'Se detectaron symlinks' : 'OK');
        }
    }

    $htaccess = is_file($root . '/.htaccess') && !is_link($root . '/.htaccess');
    $add('htaccess_root', '.htaccess raíz', $htaccess ? 'PASS' : 'FAIL', $htaccess ? 'Presente' : 'Ausente');

    $logo = $root . '/assets/images/brand/cyberleo-logo.png';
    if (is_file($logo) && !is_link($logo)) {
        $hash = hash_file('sha256', $logo);
        $ok = hash_equals(CYBERLEO_OFFICIAL_LOGO_SHA256, (string) $hash);
        $add('logo_sha', 'SHA logo oficial', $ok ? 'PASS' : 'FAIL', $ok ? 'Coincide' : 'No coincide con el oficial');
    } else {
        $add('logo_sha', 'SHA logo oficial', 'FAIL', 'Logo ausente');
    }

    $privateMarkers = ['tests', 'migrations', 'scripts', 'docs', 'cron', 'backups', 'dist'];
    $privatePresent = [];
    foreach ($privateMarkers as $marker) {
        if (is_dir($root . '/' . $marker) || is_file($root . '/' . $marker)) {
            $privatePresent[] = $marker;
        }
    }
    if (is_file($root . '/schema.sql')) {
        $privatePresent[] = 'schema.sql';
    }
    $add(
        'private_absent',
        'Artefactos privados ausentes del public root',
        $privatePresent === [] ? 'PASS' : 'FAIL',
        $privatePresent === [] ? 'OK' : ('Presentes: ' . implode(', ', $privatePresent))
    );

    return $checks;
}

function system_health_sanitize_detail(string $detail): string
{
    $detail = preg_replace('#/(?:home|var|tmp|workspace|Users)/[^\s]+#', '[path]', $detail) ?? $detail;
    $detail = str_ireplace(['PDOException', 'stack trace', 'SQLSTATE'], ['[db]', '[trace]', '[sql]'], $detail);
    return $detail;
}

function system_health_find_bin(string $name): ?string
{
    $path = getenv('PATH');
    if (!is_string($path) || $path === '') {
        return null;
    }
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        if ($dir === '') {
            continue;
        }
        $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * @param list<array{id:string,label:string,status:string,detail:string}> $checks
 */
function system_health_has_fail(array $checks): bool
{
    foreach ($checks as $check) {
        if (($check['status'] ?? '') === 'FAIL') {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array{id:string,label:string,status:string,detail:string}> $checks
 * @return array{pass:int,warn:int,fail:int,status:string}
 */
function system_health_summary(array $checks): array
{
    $pass = $warn = $fail = 0;
    foreach ($checks as $check) {
        $status = $check['status'] ?? '';
        if ($status === 'PASS') {
            $pass++;
        } elseif ($status === 'WARN') {
            $warn++;
        } elseif ($status === 'FAIL') {
            $fail++;
        }
    }
    return [
        'pass' => $pass,
        'warn' => $warn,
        'fail' => $fail,
        'status' => $fail > 0 ? 'FAIL' : ($warn > 0 ? 'WARN' : 'PASS'),
    ];
}
