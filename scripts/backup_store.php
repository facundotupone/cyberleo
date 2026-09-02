<?php
declare(strict_types=1);

/**
 * Backup privado verificable de CyberLeo (solo CLI).
 *
 * php scripts/backup_store.php --public-root=/ruta/public_html --output-dir=/ruta/privada/backups
 */

require_once __DIR__ . '/lib/maintenance.php';

maintenance_require_cli();
maintenance_require_zip_and_proc();

$publicRootArg = '';
$outputDirArg = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--public-root=')) {
        $publicRootArg = substr($arg, strlen('--public-root='));
    } elseif (str_starts_with($arg, '--output-dir=')) {
        $outputDirArg = substr($arg, strlen('--output-dir='));
    } else {
        maintenance_fail("Argumento no reconocido: {$arg}");
    }
}
if ($publicRootArg === '' || $outputDirArg === '') {
    maintenance_fail("Uso: php scripts/backup_store.php --public-root=/ruta/public_html --output-dir=/ruta/privada/backups");
}

$publicRoot = maintenance_validate_public_root($publicRootArg);
$outputDir = maintenance_require_absolute_dir($outputDirArg, '--output-dir', true);

if ($outputDir === $publicRoot || str_starts_with($outputDir, $publicRoot . DIRECTORY_SEPARATOR)) {
    maintenance_fail('--output-dir debe quedar fuera de public_html.');
}
if (str_starts_with($publicRoot, $outputDir . DIRECTORY_SEPARATOR)) {
    // output inside parent of public is ok; only reject when output is under public.
}

$creds = maintenance_creds_from_public_root($publicRoot);
$work = sys_get_temp_dir() . '/cyberleo-backup-' . bin2hex(random_bytes(8));
if (!mkdir($work, 0700, true) && !is_dir($work)) {
    maintenance_fail('No se pudo crear directorio temporal de backup.');
}

$cleanup = static function () use (&$work): void {
    if ($work === '' || !is_dir($work)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($work);
};
register_shutdown_function($cleanup);

try {
    $sqlPath = $work . '/database.sql';
    maintenance_export_database($creds, $sqlPath);

    $files = ['database.sql'];
    foreach (['products', 'settings'] as $scope) {
        foreach (maintenance_collect_upload_files($publicRoot, $scope) as $rel) {
            $src = $publicRoot . '/' . $rel;
            $dest = $work . '/' . $rel;
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true) && !is_dir(dirname($dest))) {
                maintenance_fail('No se pudo preparar árbol temporal de uploads.');
            }
            if (!copy($src, $dest)) {
                maintenance_fail('No se pudo copiar un archivo de upload al backup.');
            }
            $files[] = $rel;
        }
    }
    sort($files);

    $fileMeta = [];
    foreach ($files as $rel) {
        $full = $work . '/' . $rel;
        $fileMeta[$rel] = [
            'size' => filesize($full),
            'sha256' => hash_file('sha256', $full),
        ];
        if ($fileMeta[$rel]['size'] === false || $fileMeta[$rel]['sha256'] === false) {
            maintenance_fail('No se pudo hashear un archivo del backup.');
        }
    }

    $manifest = [
        'format' => CYBERLEO_BACKUP_FORMAT,
        'version' => CYBERLEO_BACKUP_VERSION,
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'app_commit' => maintenance_app_commit(),
        'database' => [
            'name' => $creds['name'],
            'host' => $creds['host'],
            // intentionally no user/password
        ],
        'config_local_php_excluded' => true,
        'files' => $fileMeta,
    ];
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($work . '/manifest.json', $manifestJson) === false) {
        maintenance_fail('No se pudo escribir manifest.json.');
    }

    $stamp = gmdate('Ymd\THis\Z');
    $basename = 'cyberleo-backup-' . $stamp . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.zip';
    if (!preg_match('/^cyberleo-backup-[0-9T]+Z-[a-f0-9]{8}\.zip$/', $basename)) {
        maintenance_fail('Nombre de backup inválido.');
    }
    $zipPath = $outputDir . '/' . $basename;
    if (is_file($zipPath) || is_link($zipPath)) {
        maintenance_fail('El archivo de backup destino ya existe.');
    }

    $zip = new ZipArchive();
    $tmpZip = $work . '/backup.zip';
    if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
        maintenance_fail('No se pudo crear el ZIP de backup.');
    }
    $zip->addFile($work . '/manifest.json', 'manifest.json');
    foreach ($files as $rel) {
        $zip->addFile($work . '/' . $rel, $rel);
    }
    $zip->close();

    if (!rename($tmpZip, $zipPath)) {
        if (!copy($tmpZip, $zipPath)) {
            maintenance_fail('No se pudo publicar el ZIP de backup.');
        }
        @unlink($tmpZip);
    }
    @chmod($zipPath, 0600);

    // Ensure secrets not in zip by quick scan of names only.
    $verify = new ZipArchive();
    if ($verify->open($zipPath) !== true) {
        @unlink($zipPath);
        maintenance_fail('No se pudo verificar el ZIP publicado.');
    }
    for ($i = 0; $i < $verify->numFiles; $i++) {
        $name = $verify->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        if (str_contains(strtolower($name), 'config.local')) {
            $verify->close();
            @unlink($zipPath);
            maintenance_fail('El backup no debe incluir config.local.php.');
        }
    }
    $verify->close();

    fwrite(STDOUT, "Backup creado.\n");
    fwrite(STDOUT, 'Archivo: ' . $basename . "\n");
    fwrite(STDOUT, 'Archivos en manifiesto: ' . count($files) . "\n");
    fwrite(STDOUT, "config.local.php: excluido\n");
} catch (Throwable $e) {
    maintenance_fail('Backup falló (detalle omitido).');
}

exit(0);
