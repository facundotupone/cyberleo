<?php
declare(strict_types=1);

/**
 * Idempotent CyberLeo catalog taxonomy seeder (CLI only).
 *
 * Usage:
 *   php scripts/seed_catalog_taxonomy.php --public-root=/ruta/public_html --dry-run
 *   php scripts/seed_catalog_taxonomy.php --public-root=/ruta/public_html --apply
 */

require_once __DIR__ . '/lib/maintenance.php';

maintenance_require_cli();

$publicRootArg = '';
$mode = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--public-root=')) {
        $publicRootArg = substr($arg, strlen('--public-root='));
    } elseif ($arg === '--dry-run') {
        $mode = 'dry-run';
    } elseif ($arg === '--apply') {
        $mode = 'apply';
    } else {
        maintenance_fail(
            "Argumento no reconocido: {$arg}\n"
            . "Uso:\n"
            . "  php scripts/seed_catalog_taxonomy.php --public-root=/ruta/public_html --dry-run\n"
            . "  php scripts/seed_catalog_taxonomy.php --public-root=/ruta/public_html --apply\n"
        );
    }
}

if ($publicRootArg === '' || ($mode !== 'dry-run' && $mode !== 'apply')) {
    maintenance_fail(
        "Uso:\n"
        . "  php scripts/seed_catalog_taxonomy.php --public-root=/ruta/public_html --dry-run\n"
        . "  php scripts/seed_catalog_taxonomy.php --public-root=/ruta/public_html --apply\n"
    );
}

$publicRoot = maintenance_validate_public_root($publicRootArg);
if (!is_file($publicRoot . '/includes/config.local.php')) {
    maintenance_fail('Falta includes/config.local.php en public-root (tienda no instalada).');
}
if (!is_file($publicRoot . '/includes/catalog_taxonomy.php')) {
    maintenance_fail('Falta includes/catalog_taxonomy.php en public-root. Desplegá el código de taxonomía primero.');
}

require_once $publicRoot . '/includes/config.php';
require_once $publicRoot . '/includes/catalog_taxonomy.php';

$creds = maintenance_creds_from_public_root($publicRoot);
$pdo = maintenance_pdo($creds);

require_once __DIR__ . '/lib/catalog_taxonomy_seed.php';


try {
    $report = seed_catalog_taxonomy_run($pdo, $mode === 'apply');
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (maintenance_output_contains_secret($msg, $creds, defined('APP_SECRET') ? (string) APP_SECRET : '')) {
        $msg = 'Error de taxonomía (detalle omitido).';
    }
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

fwrite(STDOUT, "Modo: {$mode}\n");
fwrite(STDOUT, 'Categorías creadas: ' . count($report['created_categories']) . "\n");
foreach ($report['created_categories'] as $line) {
    fwrite(STDOUT, "  + {$line}\n");
}
fwrite(STDOUT, 'Categorías renombradas: ' . count($report['renamed_categories']) . "\n");
foreach ($report['renamed_categories'] as $line) {
    fwrite(STDOUT, "  ~ {$line}\n");
}
fwrite(STDOUT, 'Categorías reutilizadas: ' . count($report['reused_categories']) . "\n");
fwrite(STDOUT, 'Iconos actualizados: ' . count($report['updated_icons']) . "\n");
foreach ($report['updated_icons'] as $line) {
    fwrite(STDOUT, "  i {$line}\n");
}
fwrite(STDOUT, 'Subcategorías creadas: ' . count($report['created_subcategories']) . "\n");
fwrite(STDOUT, 'Subcategorías reutilizadas: ' . count($report['reused_subcategories']) . "\n");
fwrite(STDOUT, 'Conflictos: ' . count($report['conflicts']) . "\n");
foreach ($report['conflicts'] as $line) {
    fwrite(STDOUT, "  ! {$line}\n");
}
fwrite(STDOUT, "Productos preservados: {$report['products_preserved']}\n");
fwrite(STDOUT, "Total categorías en base: {$report['category_count']}\n");
fwrite(STDOUT, "Total subcategorías en base: {$report['subcategory_count']}\n");
if ($mode === 'dry-run') {
    fwrite(STDOUT, "Dry-run: sin escrituras persistentes (rollback).\n");
} else {
    fwrite(STDOUT, "Apply: cambios confirmados en transacción.\n");
}

// Optional CSV of reclassification suggestions (never applied).
try {
    $products = $pdo->query(
        'SELECT p.id, p.name, p.description, p.category_id, p.subcategory_id,
                c.name AS category_name, s.name AS subcategory_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN subcategories s ON s.id = p.subcategory_id
         ORDER BY p.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($products !== []) {
        $cats = $pdo->query('SELECT id, name FROM categories')->fetchAll(PDO::FETCH_ASSOC);
        $subs = $pdo->query('SELECT id, category_id, name FROM subcategories')->fetchAll(PDO::FETCH_ASSOC);
        $artifactsDir = dirname(__DIR__) . '/artifacts';
        if (!is_dir($artifactsDir)) {
            @mkdir($artifactsDir, 0755, true);
        }
        $csvPath = $artifactsDir . '/productos-para-reclasificar.csv';
        $fh = fopen($csvPath, 'wb');
        if ($fh !== false) {
            fwrite($fh, "ID,producto,categoría actual,subcategoría actual,categoría sugerida,subcategoría sugerida,motivo\n");
            foreach ($products as $product) {
                $suggestion = catalog_taxonomy_suggest_reclassify($product, $cats, $subs);
                $row = [
                    (string) $product['id'],
                    (string) $product['name'],
                    (string) ($product['category_name'] ?? ''),
                    (string) ($product['subcategory_name'] ?? ''),
                    (string) ($suggestion['category'] ?? ''),
                    (string) ($suggestion['subcategory'] ?? ''),
                    (string) $suggestion['motivo'],
                ];
                fputcsv($fh, $row);
            }
            fclose($fh);
            fwrite(STDOUT, 'CSV sugerencias: artifacts/productos-para-reclasificar.csv (' . count($products) . " productos)\n");
        }
    } else {
        fwrite(STDOUT, "CSV sugerencias: omitido (sin productos).\n");
    }
} catch (Throwable $e) {
    fwrite(STDOUT, "CSV sugerencias: no generado.\n");
}

exit(0);
