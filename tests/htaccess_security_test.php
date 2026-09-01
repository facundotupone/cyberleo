<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$contents = file_get_contents($root . '/.htaccess');
if ($contents === false) {
    fwrite(STDERR, "No se pudo leer .htaccess.\n");
    exit(1);
}

function fail(string $message): never
{
    fwrite(STDERR, "Falló seguridad de .htaccess: {$message}\n");
    exit(1);
}

if (!preg_match('/^\s*Options\s+[^\r\n]*-Indexes\b/mi', $contents)) {
    fail('falta Options -Indexes');
}

$denyPatterns = [];
foreach (preg_split('/\R/', $contents) as $line) {
    if (!preg_match('/^\s*RewriteRule\s+"([^"]+)"\s+\S+\s+\[([^\]]+)\]/i', $line, $match)) {
        continue;
    }
    if (preg_match('/(?:^|,)\s*F\s*(?:,|$)/i', $match[2])) {
        $denyPatterns[] = $match[1];
    }
}
if ($denyPatterns === []) {
    fail('no hay reglas RewriteRule con flag F');
}

$isDenied = static function (string $path) use ($denyPatterns): bool {
    foreach ($denyPatterns as $pattern) {
        if (preg_match('~' . str_replace('~', '\\~', $pattern) . '~i', $path) === 1) {
            return true;
        }
    }
    return false;
};

$blocked = [
    'tests/run.sh',
    'tests/fixtures/http_seed.sql',
    'migrations/001_add_orders_stock_settings.php',
    'schema.sql',
    'README.md',
    'includes/config.php',
    'includes/config.local.php',
    '.env',
    '.git/config',
    'logs/error.log',
    'private/app.log',
];
foreach ($blocked as $path) {
    if (!$isDenied($path)) {
        fail("{$path} quedaría accesible");
    }
}

$public = [
    'index.php',
    'admin',
    'admin_products.php',
    'assets/css/style.css',
    'assets/images/products/item.webp',
];
foreach ($public as $path) {
    if ($isDenied($path)) {
        fail("{$path} quedó bloqueado");
    }
}

if (!preg_match(
    '/^\s*RewriteRule\s+\^.*\$\s+\/admin_products\.php\s+\[[^\]]*\bR=301\b[^\]]*\]/mi',
    $contents
)) {
    fail('falta la redirección 301 de /admin a /admin_products.php');
}

echo "OK: exposición estática de .htaccess verificada.\n";
