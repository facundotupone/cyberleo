<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/orders.php';
try { echo expire_pending_orders($pdo) . " reservas vencidas liberadas.\n"; }
catch (Throwable $e) { error_log($e->getMessage()); fwrite(STDERR, "No se pudieron liberar reservas.\n"); exit(1); }
