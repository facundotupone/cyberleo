<?php
declare(strict_types=1);

/**
 * Liberación de reservas vencidas (CLI privado).
 *
 * Uso:
 *   php cron/expire_reservations.php --public-root=/ruta/absoluta/public_html
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/scripts/lib/maintenance.php';

$publicRootArg = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--public-root=')) {
        $publicRootArg = substr($arg, strlen('--public-root='));
    } else {
        maintenance_fail("Argumento no reconocido: {$arg}\nUso: php cron/expire_reservations.php --public-root=/ruta/public_html");
    }
}
if ($publicRootArg === '') {
    maintenance_fail('Uso: php cron/expire_reservations.php --public-root=/ruta/absoluta/public_html');
}

$publicRoot = maintenance_validate_public_root($publicRootArg);
require_once $publicRoot . '/includes/config.php';
require_once $publicRoot . '/includes/db.php';
require_once $publicRoot . '/includes/orders.php';

try {
    echo expire_pending_orders($pdo) . " reservas vencidas liberadas.\n";
} catch (Throwable $e) {
    error_log('expire_reservations: failure');
    fwrite(STDERR, "No se pudieron liberar reservas.\n");
    exit(1);
}
