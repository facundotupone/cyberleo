<?php
declare(strict_types=1);

/**
 * Biblioteca privada de mantenimiento CyberLeo (solo CLI / herramientas privadas).
 * No debe incluirse desde endpoints públicos.
 */

const CYBERLEO_OFFICIAL_LOGO_SHA256 = '1c209559ea540fa864ba3e3bd17be1f6cdb823582389cd65224a2382849b456b';
const CYBERLEO_BACKUP_FORMAT = 'cyberleo-backup';
const CYBERLEO_BACKUP_VERSION = 1;

/**
 * @return never
 */
function maintenance_fail(string $message, int $code = 1): void
{
    fwrite(STDERR, rtrim($message) . "\n");
    exit($code);
}

function maintenance_path_has_symlink(string $path): bool
{
    if ($path === '' || $path === DIRECTORY_SEPARATOR) {
        return false;
    }
    $current = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
    foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
        if ($segment === '') {
            continue;
        }
        $current .= ($current === '' || $current === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR) . $segment;
        if (is_link($current)) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve an absolute, canonical, non-symlink, non-root directory.
 */
function maintenance_require_absolute_dir(string $path, string $label, bool $mustExist = true): string
{
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR)) {
        maintenance_fail("{$label} debe ser una ruta absoluta.");
    }
    if ($path === DIRECTORY_SEPARATOR) {
        maintenance_fail("{$label} no puede ser /.");
    }
    if (maintenance_path_has_symlink($path)) {
        maintenance_fail("{$label} no puede contener enlaces simbólicos.");
    }
    if ($mustExist) {
        if (!is_dir($path)) {
            maintenance_fail("{$label} no existe o no es un directorio.");
        }
        $real = realpath($path);
        if ($real === false || $real !== $path || $real === DIRECTORY_SEPARATOR || is_link($path)) {
            maintenance_fail("{$label} debe ser un directorio canónico real, sin symlinks.");
        }
        return $real;
    }
    $parent = dirname($path);
    if (!is_dir($parent)) {
        maintenance_fail("El directorio padre de {$label} no existe.");
    }
    $realParent = realpath($parent);
    if ($realParent === false || maintenance_path_has_symlink($parent) || is_link($parent)) {
        maintenance_fail("El directorio padre de {$label} debe ser canónico y sin symlinks.");
    }
    if (basename($path) === '' || basename($path) === '.' || basename($path) === '..') {
        maintenance_fail("{$label} tiene un nombre inválido.");
    }
    return $realParent . DIRECTORY_SEPARATOR . basename($path);
}

/**
 * @return list<string>
 */
function maintenance_expected_public_files(): array
{
    return [
        '.htaccess',
        'index.php',
        'cart.php',
        'admin_login.php',
        'admin_products.php',
        'admin_settings.php',
        'admin_system.php',
        'includes/config.php',
        'includes/db.php',
        'includes/functions.php',
        'assets/images/products/.htaccess',
        'assets/images/settings/.htaccess',
        'assets/images/brand/cyberleo-logo.png',
    ];
}

function maintenance_validate_public_root(string $path): string
{
    $root = maintenance_require_absolute_dir($path, '--public-root');
    foreach (maintenance_expected_public_files() as $relative) {
        $full = $root . DIRECTORY_SEPARATOR . $relative;
        if (!is_file($full) || is_link($full)) {
            maintenance_fail("--public-root no parece un release CyberLeo válido (falta {$relative}).");
        }
    }
    // Evitar apuntar al repositorio privado por error.
    foreach (['.git', 'tests', 'scripts', 'migrations', 'docs', 'cron'] as $marker) {
        if (is_dir($root . DIRECTORY_SEPARATOR . $marker)) {
            maintenance_fail("--public-root parece el repositorio o herramientas privadas (encontró {$marker}/). Usá el release público.");
        }
    }
    if (is_file($root . DIRECTORY_SEPARATOR . 'schema.sql')) {
        maintenance_fail('--public-root no debe contener schema.sql (herramienta privada).');
    }
    return $root;
}

function maintenance_private_root(): string
{
    $scriptsDir = dirname(__DIR__);
    $root = dirname($scriptsDir);
    $real = realpath($root);
    if ($real === false) {
        maintenance_fail('No se pudo resolver la raíz de herramientas privadas.');
    }
    return $real;
}

function maintenance_schema_path(): string
{
    $path = maintenance_private_root() . '/schema.sql';
    if (!is_file($path) || is_link($path)) {
        maintenance_fail('schema.sql privado ausente junto a las herramientas.');
    }
    return $path;
}

/**
 * @return array{host:string,socket:?string,name:string,user:string,pass:string}
 */
function maintenance_db_credentials_from_env(): ?array
{
    $host = getenv('DB_HOST');
    $name = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    if ($host === false || $name === false || $user === false || $pass === false) {
        return null;
    }
    if ($host === '' || $name === '' || $user === '') {
        return null;
    }
    $socket = null;
    if (str_contains($host, 'unix_socket=')) {
        $parts = explode(';', $host);
        $hostOnly = 'localhost';
        foreach ($parts as $part) {
            if (str_starts_with($part, 'unix_socket=')) {
                $socket = substr($part, strlen('unix_socket='));
            } elseif (str_starts_with($part, 'host=')) {
                $hostOnly = substr($part, 5);
            } elseif ($part !== '' && !str_contains($part, '=')) {
                $hostOnly = $part;
            }
        }
        $host = $hostOnly;
    }
    $envSocket = getenv('DB_SOCKET');
    if (is_string($envSocket) && $envSocket !== '') {
        $socket = $envSocket;
    }
    return [
        'host' => $host,
        'socket' => $socket,
        'name' => $name,
        'user' => $user,
        'pass' => (string) $pass,
    ];
}

function maintenance_prompt(string $label, bool $hidden = false): string
{
    if (!defined('STDIN') || !is_resource(STDIN) || stream_isatty(STDIN) === false) {
        maintenance_fail("No hay terminal interactiva para solicitar: {$label}");
    }
    fwrite(STDERR, $label);
    if ($hidden && function_exists('shell_exec') && PHP_OS_FAMILY !== 'Windows') {
        $value = shell_exec('stty -echo; read -r REPLY; stty echo; printf \'%s\' "$REPLY"');
        fwrite(STDERR, "\n");
        return is_string($value) ? $value : '';
    }
    $line = fgets(STDIN);
    if ($line === false) {
        maintenance_fail("No se pudo leer: {$label}");
    }
    return rtrim($line, "\r\n");
}

/**
 * @return array{host:string,socket:?string,name:string,user:string,pass:string}
 */
function maintenance_obtain_db_credentials(bool $interactive = true): array
{
    $fromEnv = maintenance_db_credentials_from_env();
    if ($fromEnv !== null) {
        return $fromEnv;
    }
    if (!$interactive) {
        maintenance_fail('Faltan DB_HOST, DB_NAME, DB_USER y DB_PASS en el entorno.');
    }
    $host = trim(maintenance_prompt('DB_HOST [localhost]: '));
    if ($host === '') {
        $host = 'localhost';
    }
    $name = trim(maintenance_prompt('DB_NAME: '));
    $user = trim(maintenance_prompt('DB_USER: '));
    $pass = maintenance_prompt('DB_PASS: ', true);
    if ($name === '' || $user === '') {
        maintenance_fail('DB_NAME y DB_USER son obligatorios.');
    }
    return ['host' => $host, 'socket' => null, 'name' => $name, 'user' => $user, 'pass' => $pass];
}

function maintenance_validate_site_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 255) {
        return false;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    if ($scheme === 'https') {
        return true;
    }
    if ($scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1')) {
        return true;
    }
    return false;
}

function maintenance_validate_whatsapp(string $value): bool
{
    return (bool) preg_match('/^\d{8,16}$/', $value);
}

function maintenance_validate_username(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]{3,80}$/', $value);
}

function maintenance_validate_admin_password(string $value): bool
{
    return strlen($value) >= 12 && strlen($value) <= 200;
}

function maintenance_pdo(array $creds): PDO
{
    $charset = 'utf8mb4';
    if (!empty($creds['socket'])) {
        $dsn = 'mysql:unix_socket=' . $creds['socket'] . ';dbname=' . $creds['name'] . ';charset=' . $charset;
    } else {
        $dsn = 'mysql:host=' . $creds['host'] . ';dbname=' . $creds['name'] . ';charset=' . $charset;
    }
    try {
        $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        return $pdo;
    } catch (PDOException $e) {
        maintenance_fail('No se pudo conectar a MySQL. Verificá host, base y usuario (detalle omitido).');
    }
}

function maintenance_database_is_empty(PDO $pdo): bool
{
    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchColumn();
    return $count === 0;
}

/**
 * Write a temporary MySQL defaults file (0600) and return its path.
 */
function maintenance_write_defaults_extra_file(array $creds): string
{
    $dir = sys_get_temp_dir();
    $path = tempnam($dir, 'cyberleo-my-');
    if ($path === false) {
        maintenance_fail('No se pudo crear archivo temporal de credenciales.');
    }
    $hostLine = !empty($creds['socket'])
        ? 'socket=' . str_replace(["\n", "\r"], '', (string) $creds['socket'])
        : 'host=' . str_replace(["\n", "\r"], '', (string) $creds['host']);
    $content = "[client]\n"
        . $hostLine . "\n"
        . 'user=' . str_replace(["\n", "\r"], '', (string) $creds['user']) . "\n"
        . 'password="' . addcslashes((string) $creds['pass'], "\\\"") . "\"\n";
    if (file_put_contents($path, $content) === false) {
        @unlink($path);
        maintenance_fail('No se pudo escribir el archivo temporal de credenciales.');
    }
    @chmod($path, 0600);
    return $path;
}

/**
 * @param list<string> $command
 * @return array{code:int,stdout:string,stderr:string}
 */
function maintenance_proc_open(array $command, ?string $stdin = null, ?string $cwd = null): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return ['code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

function maintenance_import_schema(array $creds, string $schemaPath): void
{
    $bin = trim((string) shell_exec('command -v mysql')) ?: 'mysql';
    $defaults = maintenance_write_defaults_extra_file($creds);
    try {
        $sql = file_get_contents($schemaPath);
        if ($sql === false || $sql === '') {
            maintenance_fail('No se pudo leer schema.sql.');
        }
        $cmd = [$bin, '--defaults-extra-file=' . $defaults, '--default-character-set=utf8mb4', $creds['name']];
        $result = maintenance_proc_open($cmd, $sql);
        if ($result['code'] !== 0) {
            maintenance_fail('Falló la importación del esquema. Recreá la base vacía e intentá de nuevo.');
        }
    } finally {
        @unlink($defaults);
    }
}

function maintenance_export_database(array $creds, string $outputSqlPath): void
{
    $bin = trim((string) shell_exec('command -v mysqldump')) ?: 'mysqldump';
    $defaults = maintenance_write_defaults_extra_file($creds);
    try {
        $cmd = [
            $bin,
            '--defaults-extra-file=' . $defaults,
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            $creds['name'],
        ];
        $result = maintenance_proc_open($cmd);
        if ($result['code'] !== 0 || $result['stdout'] === '') {
            maintenance_fail('Falló mysqldump. El respaldo no se completó.');
        }
        if (file_put_contents($outputSqlPath, $result['stdout']) === false) {
            maintenance_fail('No se pudo escribir database.sql del respaldo.');
        }
        @chmod($outputSqlPath, 0600);
    } finally {
        @unlink($defaults);
    }
}

function maintenance_import_sql_file(array $creds, string $sqlPath): void
{
    $bin = trim((string) shell_exec('command -v mysql')) ?: 'mysql';
    $defaults = maintenance_write_defaults_extra_file($creds);
    try {
        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            maintenance_fail('No se pudo leer database.sql del backup.');
        }
        $cmd = [$bin, '--defaults-extra-file=' . $defaults, '--default-character-set=utf8mb4', $creds['name']];
        $result = maintenance_proc_open($cmd, $sql);
        if ($result['code'] !== 0) {
            maintenance_fail('Falló la importación de database.sql.');
        }
    } finally {
        @unlink($defaults);
    }
}

/**
 * @param array<string,string|int|bool|null> $defines
 */
function maintenance_write_config_local(string $publicRoot, array $defines): void
{
    $target = $publicRoot . '/includes/config.local.php';
    if (is_file($target) || is_link($target)) {
        maintenance_fail('config.local.php ya existe. La tienda parece instalada; no se sobrescribe.');
    }
    $lines = ["<?php\n", "declare(strict_types=1);\n\n"];
    foreach ($defines as $name => $value) {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            maintenance_fail('Nombre de constante inválido.');
        }
        $lines[] = 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
    }
    $dir = dirname($target);
    $tmp = tempnam($dir, '.config.local.');
    if ($tmp === false) {
        maintenance_fail('No se pudo crear temporal para config.local.php.');
    }
    // tempnam may create in /tmp; ensure same directory for atomic rename.
    if (dirname($tmp) !== $dir) {
        @unlink($tmp);
        $tmp = $dir . '/.config.local.' . bin2hex(random_bytes(8)) . '.tmp';
    }
    if (file_put_contents($tmp, implode('', $lines)) === false) {
        @unlink($tmp);
        maintenance_fail('No se pudo escribir config.local.php temporal.');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $target)) {
        @unlink($tmp);
        maintenance_fail('No se pudo publicar config.local.php de forma atómica.');
    }
    @chmod($target, 0600);
}

function maintenance_output_contains_secret(string $haystack, array $creds, string $appSecret = ''): bool
{
    $needles = array_filter([
        $creds['pass'] ?? '',
        $appSecret,
        $creds['user'] ?? '',
    ], static fn ($v) => is_string($v) && $v !== '');
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * Allowed upload extensions for backup/restore.
 *
 * @return list<string>
 */
function maintenance_allowed_upload_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'webp', 'htaccess'];
}

/**
 * Collect regular upload files under products/ or settings/.
 *
 * @return list<string> relative paths from public root
 */
function maintenance_collect_upload_files(string $publicRoot, string $scope): array
{
    $base = $publicRoot . '/assets/images/' . $scope;
    if (!is_dir($base) || is_link($base) || maintenance_path_has_symlink($base)) {
        maintenance_fail("Uploads {$scope} inválidos o con symlinks.");
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (is_link($path) || maintenance_path_has_symlink($path)) {
            maintenance_fail("Se rechazó un symlink en uploads ({$scope}).");
        }
        if ($file->isDir()) {
            // Only flat files expected; nested dirs are unexpected except the root itself.
            $relDir = substr($path, strlen($publicRoot) + 1);
            if ($relDir !== 'assets/images/' . $scope) {
                maintenance_fail("Subdirectorio inesperado en uploads: {$relDir}");
            }
            continue;
        }
        if (!$file->isFile()) {
            maintenance_fail('Entrada no regular en uploads.');
        }
        $rel = substr($path, strlen($publicRoot) + 1);
        $name = $file->getFilename();
        if ($name === '.htaccess') {
            $files[] = str_replace('\\', '/', $rel);
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            maintenance_fail("Extensión no permitida en uploads: {$rel}");
        }
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
            maintenance_fail("Ruta de upload insegura: {$rel}");
        }
        $files[] = str_replace('\\', '/', $rel);
    }
    sort($files);
    return $files;
}

function maintenance_uploads_are_empty_except_htaccess(string $publicRoot): bool
{
    foreach (['products', 'settings'] as $scope) {
        foreach (maintenance_collect_upload_files($publicRoot, $scope) as $rel) {
            if (!str_ends_with($rel, '/.htaccess') && basename($rel) !== '.htaccess') {
                return false;
            }
        }
    }
    return true;
}

/**
 * @return array{host:string,socket:?string,name:string,user:string,pass:string}
 */
function maintenance_creds_from_public_root(string $publicRoot): array
{
    $config = $publicRoot . '/includes/config.php';
    if (!is_file($config)) {
        maintenance_fail('includes/config.php ausente en public-root.');
    }
    // Load in an isolated scope without HTML side effects from db.php.
    require_once $config;
    $host = defined('DB_HOST') ? (string) DB_HOST : '';
    $name = defined('DB_NAME') ? (string) DB_NAME : '';
    $user = defined('DB_USER') ? (string) DB_USER : '';
    $pass = defined('DB_PASS') ? (string) DB_PASS : '';
    if ($host === '' || $name === '' || $user === '') {
        maintenance_fail('Configuración de base incompleta.');
    }
    $socket = null;
    if (str_contains($host, 'unix_socket=')) {
        $parts = explode(';', $host);
        $hostOnly = 'localhost';
        foreach ($parts as $part) {
            if (str_starts_with($part, 'unix_socket=')) {
                $socket = substr($part, strlen('unix_socket='));
            } elseif ($part !== '' && !str_contains($part, '=')) {
                $hostOnly = $part;
            }
        }
        $host = $hostOnly;
    }
    return ['host' => $host, 'socket' => $socket, 'name' => $name, 'user' => $user, 'pass' => $pass];
}

function maintenance_require_cli(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('Forbidden');
    }
}

function maintenance_require_zip_and_proc(): void
{
    if (!class_exists('ZipArchive')) {
        maintenance_fail('Falta la extensión PHP ZipArchive. Instalá php-zip antes de continuar.');
    }
    if (!function_exists('proc_open')) {
        maintenance_fail('proc_open no está disponible; es obligatorio para mantenimiento seguro.');
    }
    foreach (['mysql', 'mysqldump'] as $bin) {
        $path = trim((string) shell_exec('command -v ' . $bin));
        if ($path === '') {
            maintenance_fail("Falta el binario {$bin} en PATH.");
        }
    }
}

function maintenance_app_commit(): ?string
{
    $private = maintenance_private_root();
    if (!is_dir($private . '/.git')) {
        return null;
    }
    $out = trim((string) shell_exec('git -C ' . escapeshellarg($private) . ' rev-parse HEAD 2>/dev/null'));
    return $out !== '' ? $out : null;
}
