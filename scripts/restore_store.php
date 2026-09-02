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

/**
 * @return array{manifest:array,tmpdir:string,cleanup:callable}
 */
function restore_extract_and_verify(string $zipPath): array
{
    $zipPath = trim($zipPath);
    if ($zipPath === '' || !str_starts_with($zipPath, DIRECTORY_SEPARATOR)) {
        maintenance_fail('La ruta del ZIP debe ser absoluta.');
    }
    if (maintenance_path_has_symlink($zipPath) || is_link($zipPath)) {
        maintenance_fail('El ZIP no puede ser ni atravesar symlinks.');
    }
    $realZip = realpath($zipPath);
    if ($realZip === false || !is_file($realZip) || $realZip !== $zipPath) {
        maintenance_fail('ZIP inexistente o no canónico.');
    }

    $tmpdir = sys_get_temp_dir() . '/cyberleo-restore-' . bin2hex(random_bytes(8));
    if (!mkdir($tmpdir, 0700, true) && !is_dir($tmpdir)) {
        maintenance_fail('No se pudo crear temporal de restore.');
    }
    $cleanup = static function () use ($tmpdir): void {
        if (!is_dir($tmpdir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpdir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $p = $file->getPathname();
            $file->isDir() ? @rmdir($p) : @unlink($p);
        }
        @rmdir($tmpdir);
    };
    register_shutdown_function($cleanup);

    $zip = new ZipArchive();
    if ($zip->open($realZip) !== true) {
        maintenance_fail('No se pudo abrir el ZIP.');
    }

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            $zip->close();
            maintenance_fail('Entrada ZIP ilegible.');
        }
        $name = str_replace('\\', '/', (string) $stat['name']);
        if ($name === '' || str_ends_with($name, '/')) {
            continue;
        }
        if (str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, "\0")) {
            $zip->close();
            maintenance_fail('ZIP Slip o ruta insegura detectada.');
        }
        // Reject symlinks if ZipArchive reports them.
        if (!empty($stat['external_attributes'])) {
            // Unix symlink: type bits in high byte of external attr when created on Unix.
            $type = ($stat['external_attributes'] >> 16) & 0170000;
            if ($type === 0120000) {
                $zip->close();
                maintenance_fail('El backup contiene un symlink.');
            }
        }
        $names[] = $name;
    }
    sort($names);

    if (!in_array('manifest.json', $names, true) || !in_array('database.sql', $names, true)) {
        $zip->close();
        maintenance_fail('Backup incompleto: faltan manifest.json o database.sql.');
    }

    foreach ($names as $name) {
        if ($zip->extractTo($tmpdir, [$name]) !== true) {
            $zip->close();
            maintenance_fail('No se pudo extraer una entrada del backup.');
        }
        $extracted = $tmpdir . '/' . $name;
        if (!is_file($extracted) || is_link($extracted)) {
            $zip->close();
            maintenance_fail('Entrada extraída inválida.');
        }
    }
    $zip->close();

    $manifestRaw = file_get_contents($tmpdir . '/manifest.json');
    if ($manifestRaw === false) {
        maintenance_fail('No se pudo leer manifest.json.');
    }
    try {
        $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        maintenance_fail('manifest.json inválido.');
    }
    if (!is_array($manifest)
        || ($manifest['format'] ?? '') !== CYBERLEO_BACKUP_FORMAT
        || (int) ($manifest['version'] ?? 0) !== CYBERLEO_BACKUP_VERSION
        || empty($manifest['files'])
        || !is_array($manifest['files'])
        || empty($manifest['config_local_php_excluded'])
    ) {
        maintenance_fail('Manifiesto incompatible o incompleto.');
    }

    $declared = array_keys($manifest['files']);
    sort($declared);
    $onDisk = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpdir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($tmpdir) + 1);
        $rel = str_replace('\\', '/', $rel);
        if ($rel === 'manifest.json') {
            continue;
        }
        $onDisk[] = $rel;
    }
    sort($onDisk);

    if ($onDisk !== $declared) {
        maintenance_fail('El contenido del ZIP no coincide exactamente con el manifiesto (faltantes o extras).');
    }

    foreach ($manifest['files'] as $rel => $meta) {
        if (!is_string($rel) || str_contains($rel, '..') || str_starts_with($rel, '/')) {
            maintenance_fail('Ruta de manifiesto insegura.');
        }
        if ($rel !== 'database.sql') {
            if (str_starts_with($rel, 'assets/images/products/') || str_starts_with($rel, 'assets/images/settings/')) {
                $base = basename($rel);
                if ($base !== '.htaccess') {
                    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                        maintenance_fail("Extensión no permitida en backup: {$rel}");
                    }
                }
            } else {
                maintenance_fail("Archivo no permitido en backup: {$rel}");
            }
        }
        $full = $tmpdir . '/' . $rel;
        $size = filesize($full);
        $hash = hash_file('sha256', $full);
        if ($size === false || $hash === false
            || (int) ($meta['size'] ?? -1) !== (int) $size
            || !hash_equals((string) ($meta['sha256'] ?? ''), $hash)
        ) {
            maintenance_fail("Hash o tamaño alterado: {$rel}");
        }
    }

    return ['manifest' => $manifest, 'tmpdir' => $tmpdir, 'cleanup' => $cleanup];
}

$verified = restore_extract_and_verify($zipArg);
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

// Preserve existing .htaccess from release; restore other images from backup.
$tmpdir = $verified['tmpdir'];
foreach ($verified['manifest']['files'] as $rel => $_meta) {
    if ($rel === 'database.sql') {
        continue;
    }
    if (basename($rel) === '.htaccess') {
        // Keep release .htaccess; skip overwrite.
        continue;
    }
    $src = $tmpdir . '/' . $rel;
    $dest = $publicRoot . '/' . $rel;
    $destDir = dirname($dest);
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        maintenance_fail('No se pudo preparar directorio de upload destino.');
    }
    if (is_file($dest) || is_link($dest)) {
        maintenance_fail("Destino de upload ya existe: {$rel}");
    }
    if (!copy($src, $dest)) {
        maintenance_fail('No se pudo restaurar una imagen.');
    }
}

maintenance_import_sql_file($creds, $tmpdir . '/database.sql');

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
fwrite(STDOUT, 'Cantidades: ' . json_encode($counts, JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDOUT, sprintf("Diagnóstico: %s\n", $summary['status']));
exit(system_health_has_fail($checks) ? 1 : 0);
