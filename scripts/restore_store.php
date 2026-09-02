<?php
declare(strict_types=1);

/**
 * Restore / verify de backups CyberLeo (solo CLI, solo ambiente vacío).
 *
 * php scripts/restore_store.php --verify=/ruta/backup.zip
 * php scripts/restore_store.php --restore-empty=/ruta/backup.zip --public-root=/ruta/public_html
 */

require_once __DIR__ . '/lib/maintenance.php';

maintenance_require_cli();
maintenance_require_zip_and_proc();

$mode = '';
$zipArg = '';
$publicRootArg = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--verify=')) {
        $mode = 'verify';
        $zipArg = substr($arg, strlen('--verify='));
    } elseif (str_starts_with($arg, '--restore-empty=')) {
        $mode = 'restore';
        $zipArg = substr($arg, strlen('--restore-empty='));
    } elseif (str_starts_with($arg, '--public-root=')) {
        $publicRootArg = substr($arg, strlen('--public-root='));
    } else {
        maintenance_fail("Argumento no reconocido: {$arg}");
    }
}

if ($mode === '' || $zipArg === '') {
    maintenance_fail(
        "Uso:\n"
        . "  php scripts/restore_store.php --verify=/ruta/backup.zip\n"
        . "  php scripts/restore_store.php --restore-empty=/ruta/backup.zip --public-root=/ruta/public_html\n"
    );
}
if ($mode === 'restore' && $publicRootArg === '') {
    maintenance_fail('--public-root es obligatorio con --restore-empty.');
}

$verified = maintenance_verify_backup_zip($zipArg);
fwrite(STDOUT, "Verificación OK.\n");
fwrite(STDOUT, 'Archivos: ' . count($verified['manifest']['files']) . "\n");
fwrite(STDOUT, 'Creado UTC: ' . ($verified['manifest']['created_at_utc'] ?? '') . "\n");
fwrite(STDOUT, "config.local.php: excluido según manifiesto\n");

if ($mode === 'verify') {
    exit(0);
}

$publicRoot = maintenance_validate_public_root($publicRootArg);
require_once $publicRoot . '/includes/system_health.php';

if (!maintenance_uploads_are_empty_except_htaccess($publicRoot)) {
    maintenance_fail('Los directorios de uploads no están vacíos (salvo .htaccess). Restore rechazado para no sobrescribir imágenes.');
}

$creds = maintenance_creds_from_public_root($publicRoot);
$pdo = maintenance_pdo($creds);
if (!maintenance_database_is_empty($pdo)) {
    maintenance_fail('La base no está vacía. Solo se permite restore sobre una base sin tablas.');
}

$tmpdir = $verified['tmpdir'];
$sqlImported = false;
$createdFiles = [];

try {
    maintenance_import_sql_file($creds, $tmpdir . '/database.sql');
    $sqlImported = true;

    foreach ($verified['manifest']['files'] as $rel => $_meta) {
        if ($rel === 'database.sql') {
            continue;
        }
        if (basename($rel) === '.htaccess') {
            // Preserve release .htaccess; never overwrite.
            continue;
        }
        $src = $tmpdir . '/' . $rel;
        $dest = $publicRoot . '/' . $rel;
        if (is_file($dest) || is_link($dest)) {
            throw new RuntimeException('upload-exists');
        }
        $destDir = dirname($dest);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new RuntimeException('mkdir-failed');
        }
        // Optional test hook: fail on second image copy.
        if (getenv('CYBERLEO_TEST_FAIL_UPLOAD_COPY') === '1') {
            static $copyCount = 0;
            $copyCount++;
            if ($copyCount >= 2) {
                throw new RuntimeException('simulated-copy-fail');
            }
        }
        if (!copy($src, $dest)) {
            throw new RuntimeException('copy-failed');
        }
        $createdFiles[] = $dest;
    }
} catch (Throwable $e) {
    foreach ($createdFiles as $created) {
        if (is_file($created) && !is_link($created) && basename($created) !== '.htaccess') {
            @unlink($created);
        }
    }
    if ($sqlImported) {
        fwrite(STDERR, "La importación SQL ya se aplicó. Recreá la base temporal vacía antes de reintentar.\n");
    }
    maintenance_fail('Restore falló (detalle omitido).');
}

$pdo = maintenance_pdo($creds);
$counts = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'categories' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
    'products' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'store_settings' => (int) $pdo->query('SELECT COUNT(*) FROM store_settings')->fetchColumn(),
];

require_once $publicRoot . '/includes/config.php';
$checks = system_health_run_checks($pdo, $publicRoot);
$summary = system_health_summary($checks);
foreach ($checks as $check) {
    fwrite(STDOUT, sprintf("[%s] %s — %s\n", $check['status'], $check['label'], $check['detail']));
}
fwrite(STDOUT, "Restore completado sobre ambiente vacío.\n");
fwrite(STDOUT, 'Archivos de upload creados: ' . count($createdFiles) . "\n");
fwrite(STDOUT, 'Cantidades: ' . json_encode($counts, JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDOUT, sprintf("Diagnóstico: %s\n", $summary['status']));
exit(system_health_has_fail($checks) ? 1 : 0);
