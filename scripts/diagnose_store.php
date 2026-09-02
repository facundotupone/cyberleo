<?php
declare(strict_types=1);

/**
 * Diagnóstico CLI de CyberLeo.
 * Uso: php scripts/diagnose_store.php --public-root=/ruta/public_html
 */

require_once __DIR__ . '/lib/maintenance.php';

maintenance_require_cli();

$publicRootArg = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--public-root=')) {
        $publicRootArg = substr($arg, strlen('--public-root='));
    } else {
        maintenance_fail("Argumento no reconocido: {$arg}");
    }
}
if ($publicRootArg === '') {
    maintenance_fail('Uso: php scripts/diagnose_store.php --public-root=/ruta/public_html');
}

$publicRoot = maintenance_validate_public_root($publicRootArg);
require_once $publicRoot . '/includes/system_health.php';

$pdo = null;
$creds = null;
try {
    if (is_file($publicRoot . '/includes/config.local.php')) {
        require_once $publicRoot . '/includes/config.php';
        $creds = maintenance_creds_from_public_root($publicRoot);
        $pdo = maintenance_pdo($creds);
    }
} catch (Throwable $e) {
    $pdo = null;
}

$checks = system_health_run_checks($pdo, $publicRoot);
$summary = system_health_summary($checks);

foreach ($checks as $check) {
    $line = sprintf("[%s] %s — %s\n", $check['status'], $check['label'], $check['detail']);
    if ($creds !== null && maintenance_output_contains_secret($line, $creds, defined('APP_SECRET') ? (string) APP_SECRET : '')) {
        $line = sprintf("[%s] %s — (detalle omitido)\n", $check['status'], $check['label']);
    }
    fwrite(STDOUT, $line);
}

fwrite(STDOUT, sprintf(
    "Resumen: %s (PASS=%d WARN=%d FAIL=%d)\n",
    $summary['status'],
    $summary['pass'],
    $summary['warn'],
    $summary['fail']
));

exit(system_health_has_fail($checks) ? 1 : 0);
