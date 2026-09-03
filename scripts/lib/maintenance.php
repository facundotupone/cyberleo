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
        'includes/orders.php',
        'includes/images.php',
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

function maintenance_has_control_chars(string $value): bool
{
    return (bool) preg_match('/[\x00-\x1F\x7F]/', $value);
}

function maintenance_validate_db_name(string $name): bool
{
    if ($name === '' || strlen($name) > 64) {
        return false;
    }
    if (str_starts_with($name, '-')) {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name);
}

function maintenance_validate_db_user(string $user): bool
{
    if ($user === '' || strlen($user) > 80 || maintenance_has_control_chars($user)) {
        return false;
    }
    return !str_starts_with($user, '-');
}

function maintenance_validate_db_host(string $host): bool
{
    if ($host === '' || strlen($host) > 255 || maintenance_has_control_chars($host)) {
        return false;
    }
    return !str_starts_with($host, '-');
}

function maintenance_validate_db_socket(?string $socket): bool
{
    if ($socket === null || $socket === '') {
        return true;
    }
    if (!str_starts_with($socket, DIRECTORY_SEPARATOR) || maintenance_has_control_chars($socket)) {
        return false;
    }
    return is_string($socket) && $socket !== DIRECTORY_SEPARATOR;
}

function maintenance_validate_db_password(string $pass): bool
{
    return !maintenance_has_control_chars($pass) && strlen($pass) <= 512;
}

/**
 * @param array{host:string,socket:?string,name:string,user:string,pass:string} $creds
 */
function maintenance_assert_safe_credentials(array $creds): void
{
    if (!maintenance_validate_db_host($creds['host'])) {
        maintenance_fail('DB_HOST inválido.');
    }
    if (!maintenance_validate_db_name($creds['name'])) {
        maintenance_fail('DB_NAME inválido.');
    }
    if (!maintenance_validate_db_user($creds['user'])) {
        maintenance_fail('DB_USER inválido.');
    }
    if (!maintenance_validate_db_password($creds['pass'])) {
        maintenance_fail('DB_PASS contiene caracteres de control no permitidos.');
    }
    if (!maintenance_validate_db_socket($creds['socket'] ?? null)) {
        maintenance_fail('DB_SOCKET inválido.');
    }
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
    $creds = [
        'host' => $host,
        'socket' => $socket,
        'name' => $name,
        'user' => $user,
        'pass' => (string) $pass,
    ];
    maintenance_assert_safe_credentials($creds);
    return $creds;
}

/**
 * Locate an executable by absolute PATH scan (no shell_exec).
 * Hostinger and similar hosts often disable shell_exec in PHP CLI.
 */
function maintenance_find_executable(string $name): ?string
{
    if ($name === '' || str_contains($name, '/') || str_contains($name, "\0")) {
        return null;
    }
    $pathEnv = getenv('PATH');
    if (!is_string($pathEnv) || $pathEnv === '') {
        $pathEnv = '/usr/local/bin:/usr/bin:/bin';
    }
    foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
        if ($dir === '') {
            continue;
        }
        $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Resolve absolute executable path for stty.
 */
function maintenance_resolve_stty(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        maintenance_fail('No se puede ocultar la contraseña de forma segura. Definí la variable de entorno correspondiente.');
    }
    $path = maintenance_find_executable('stty');
    if ($path === null && function_exists('shell_exec')) {
        $path = trim((string) @shell_exec('command -v stty 2>/dev/null'));
        $path = $path !== '' ? $path : null;
    }
    if ($path === null || !str_starts_with($path, DIRECTORY_SEPARATOR) || !is_file($path) || !is_executable($path)) {
        maintenance_fail('No se puede ocultar la contraseña de forma segura. Definí la variable de entorno correspondiente.');
    }
    return $path;
}

/**
 * Run stty against the controlling terminal; return exit code and stdout.
 *
 * @param list<string> $args
 * @return array{code:int,stdout:string,stderr:string}
 */
function maintenance_stty_run(string $sttyBin, array $args): array
{
    // Operate on the real terminal, not a redirected pipe.
    $cmd = [$sttyBin];
    if (is_readable('/dev/tty') && is_writable('/dev/tty')) {
        $cmd[] = '-F';
        $cmd[] = '/dev/tty';
    }
    $cmd = array_merge($cmd, $args);
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

function maintenance_prompt(string $label, bool $hidden = false): string
{
    if (!defined('STDIN') || !is_resource(STDIN) || stream_isatty(STDIN) === false) {
        maintenance_fail("No hay terminal interactiva para solicitar: {$label}. Usá variables de entorno.");
    }
    fwrite(STDERR, $label);
    if ($hidden) {
        $sttyBin = maintenance_resolve_stty();
        $prevState = null;
        $echoDisabled = false;
        $line = false;
        try {
            $save = maintenance_stty_run($sttyBin, ['-g']);
            if ($save['code'] !== 0 || trim($save['stdout']) === '') {
                maintenance_fail('No se puede ocultar la contraseña de forma segura. Definí la variable de entorno correspondiente.');
            }
            $prevState = trim($save['stdout']);
            $disable = maintenance_stty_run($sttyBin, ['-echo']);
            if ($disable['code'] !== 0) {
                maintenance_fail('No se puede ocultar la contraseña de forma segura. Definí la variable de entorno correspondiente.');
            }
            $echoDisabled = true;
            // Only read after confirmed echo-off.
            $line = fgets(STDIN);
        } finally {
            if (is_string($prevState) && $prevState !== '') {
                // Restore exact prior settings; never pass secrets here.
                maintenance_stty_run($sttyBin, [$prevState]);
            } elseif ($echoDisabled) {
                maintenance_stty_run($sttyBin, ['echo']);
            }
            fwrite(STDERR, "\n");
        }
        if ($line === false) {
            maintenance_fail("No se pudo leer: {$label}");
        }
        return rtrim($line, "\r\n");
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
    $creds = ['host' => $host, 'socket' => null, 'name' => $name, 'user' => $user, 'pass' => $pass];
    maintenance_assert_safe_credentials($creds);
    return $creds;
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
    return $scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1');
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
    return strlen($value) >= 12 && strlen($value) <= 200 && !maintenance_has_control_chars($value);
}

function maintenance_pdo(array $creds): PDO
{
    maintenance_assert_safe_credentials($creds);
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

function maintenance_chmod_0600(string $path): void
{
    if (!@chmod($path, 0600)) {
        @unlink($path);
        maintenance_fail('No se pudieron aplicar permisos 0600.');
    }
    clearstatcache(true, $path);
    $perms = fileperms($path);
    if ($perms === false || (($perms & 0777) !== 0600)) {
        @unlink($path);
        maintenance_fail('Los permisos resultantes no son 0600.');
    }
}

function maintenance_assert_mode_0600(string $path, bool $deleteIfBad = false): void
{
    clearstatcache(true, $path);
    $perms = @fileperms($path);
    if ($perms === false || (($perms & 0777) !== 0600)) {
        if ($deleteIfBad) {
            @unlink($path);
        }
        maintenance_fail('El archivo no tiene permisos 0600.');
    }
}

/**
 * Write a temporary MySQL defaults file (0600) and return its path.
 *
 * @param array{host:string,socket:?string,name:string,user:string,pass:string} $creds
 */
function maintenance_write_defaults_extra_file(array $creds): string
{
    maintenance_assert_safe_credentials($creds);
    $dir = sys_get_temp_dir();
    $path = tempnam($dir, 'cyberleo-my-');
    if ($path === false) {
        maintenance_fail('No se pudo crear archivo temporal de credenciales.');
    }
    $hostLine = !empty($creds['socket'])
        ? 'socket=' . $creds['socket']
        : 'host=' . $creds['host'];
    $content = "[client]\n"
        . $hostLine . "\n"
        . 'user=' . $creds['user'] . "\n"
        . 'password="' . addcslashes($creds['pass'], "\\\"") . "\"\n";
    if (file_put_contents($path, $content) === false) {
        @unlink($path);
        maintenance_fail('No se pudo escribir el archivo temporal de credenciales.');
    }
    maintenance_chmod_0600($path);
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

function maintenance_mysql_bin(string $name): string
{
    if (!in_array($name, ['mysql', 'mysqldump'], true)) {
        maintenance_fail('Binario MySQL no permitido.');
    }
    $path = maintenance_find_executable($name);
    if ($path === null && function_exists('shell_exec')) {
        $resolved = trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($resolved !== '' && is_file($resolved) && is_executable($resolved)) {
            $path = $resolved;
        }
    }
    if ($path === null || !is_executable($path)) {
        maintenance_fail("Falta el binario ejecutable {$name}.");
    }
    return $path;
}

function maintenance_import_sql_file(array $creds, string $sqlPath): void
{
    if (!is_file($sqlPath) || is_link($sqlPath) || filesize($sqlPath) === false || filesize($sqlPath) < 1) {
        maintenance_fail('Archivo SQL inválido o vacío.');
    }
    $bin = maintenance_mysql_bin('mysql');
    $defaults = maintenance_write_defaults_extra_file($creds);
    $stderrFile = tempnam(sys_get_temp_dir(), 'cyberleo-mysql-err-');
    $stdoutFile = tempnam(sys_get_temp_dir(), 'cyberleo-mysql-out-');
    if ($stderrFile === false || $stdoutFile === false) {
        @unlink($defaults);
        maintenance_fail('No se pudieron crear temporales de mysql.');
    }
    try {
        $cmd = [$bin, '--defaults-extra-file=' . $defaults, '--default-character-set=utf8mb4', $creds['name']];
        $descriptors = [
            0 => ['file', $sqlPath, 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            maintenance_fail('No se pudo iniciar mysql.');
        }
        $code = proc_close($process);
        if ($code !== 0) {
            maintenance_fail('Falló la importación SQL. Recreá la base vacía e intentá de nuevo.');
        }
    } finally {
        @unlink($defaults);
        @unlink($stderrFile);
        @unlink($stdoutFile);
    }
}

function maintenance_import_schema(array $creds, string $schemaPath): void
{
    maintenance_import_sql_file($creds, $schemaPath);
}

function maintenance_export_database(array $creds, string $outputSqlPath): void
{
    $bin = maintenance_mysql_bin('mysqldump');
    $defaults = maintenance_write_defaults_extra_file($creds);
    $stderrFile = tempnam(sys_get_temp_dir(), 'cyberleo-dump-err-');
    if ($stderrFile === false) {
        @unlink($defaults);
        maintenance_fail('No se pudo crear temporal de stderr.');
    }
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
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outputSqlPath, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            maintenance_fail('No se pudo iniciar mysqldump.');
        }
        fclose($pipes[0]);
        $code = proc_close($process);
        if ($code !== 0 || !is_file($outputSqlPath) || is_link($outputSqlPath) || filesize($outputSqlPath) < 1) {
            @unlink($outputSqlPath);
            maintenance_fail('Falló mysqldump. El respaldo no se completó.');
        }
        maintenance_chmod_0600($outputSqlPath);
    } finally {
        @unlink($defaults);
        @unlink($stderrFile);
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
    $tmp = $dir . '/.config.local.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($tmp, implode('', $lines)) === false) {
        @unlink($tmp);
        maintenance_fail('No se pudo escribir config.local.php temporal.');
    }
    maintenance_chmod_0600($tmp);
    if (!rename($tmp, $target)) {
        @unlink($tmp);
        maintenance_fail('No se pudo publicar config.local.php de forma atómica.');
    }
    maintenance_assert_mode_0600($target, true);
}

function maintenance_output_contains_secret(string $haystack, array $creds, string $appSecret = ''): bool
{
    foreach ([$creds['pass'] ?? '', $appSecret] as $needle) {
        if (is_string($needle) && $needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * Collect regular upload files under products/ or settings/.
 * Rejects any subdirectory: each file dirname must equal assets/images/{scope}.
 *
 * @return list<string> relative paths from public root
 */
function maintenance_collect_upload_files(string $publicRoot, string $scope): array
{
    if (!in_array($scope, ['products', 'settings'], true)) {
        maintenance_fail('Scope de upload inválido.');
    }
    $base = $publicRoot . '/assets/images/' . $scope;
    $expectedDir = 'assets/images/' . $scope;
    if (!is_dir($base) || is_link($base) || maintenance_path_has_symlink($base) || realpath($base) !== $base) {
        maintenance_fail("Uploads {$scope} inválidos o con symlinks.");
    }
    $entries = @scandir($base);
    if ($entries === false) {
        maintenance_fail("No se pudo leer uploads {$scope}.");
    }
    $files = [];
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $base . '/' . $name;
        if (is_link($path)) {
            maintenance_fail("Se rechazó un symlink en uploads ({$scope}).");
        }
        if (is_dir($path)) {
            maintenance_fail("Subdirectorio no permitido en uploads: {$expectedDir}/{$name}");
        }
        if (!is_file($path)) {
            maintenance_fail('Entrada no regular en uploads.');
        }
        $rel = $expectedDir . '/' . $name;
        if (dirname($rel) !== $expectedDir) {
            maintenance_fail("Ruta de upload inesperada: {$rel}");
        }
        if ($name === '.htaccess') {
            $files[] = $rel;
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            maintenance_fail("Extensión no permitida en uploads: {$rel}");
        }
        if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
            maintenance_fail("Ruta de upload insegura: {$rel}");
        }
        $files[] = $rel;
    }
    sort($files);
    return $files;
}

function maintenance_uploads_are_empty_except_htaccess(string $publicRoot): bool
{
    foreach (['products', 'settings'] as $scope) {
        foreach (maintenance_collect_upload_files($publicRoot, $scope) as $rel) {
            if (basename($rel) !== '.htaccess') {
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
    $creds = ['host' => $host, 'socket' => $socket, 'name' => $name, 'user' => $user, 'pass' => $pass];
    maintenance_assert_safe_credentials($creds);
    return $creds;
}

function maintenance_require_cli(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('Forbidden');
    }
}

function maintenance_require_zip(): void
{
    if (!class_exists('ZipArchive')) {
        maintenance_fail('Falta la extensión PHP ZipArchive. Instalá php-zip antes de continuar.');
    }
}

function maintenance_require_proc_open(): void
{
    if (!function_exists('proc_open')) {
        maintenance_fail('proc_open no está disponible; es obligatorio para mantenimiento seguro.');
    }
}

function maintenance_require_mysql_client(): void
{
    maintenance_mysql_bin('mysql');
}

function maintenance_require_mysqldump(): void
{
    maintenance_mysql_bin('mysqldump');
}

/**
 * @deprecated Prefer capability-specific helpers.
 */
function maintenance_require_zip_and_proc(): void
{
    maintenance_require_zip();
    maintenance_require_proc_open();
    maintenance_require_mysql_client();
    maintenance_require_mysqldump();
}

function maintenance_app_commit(): ?string
{
    $private = maintenance_private_root();
    if (!is_dir($private . '/.git')) {
        return null;
    }
    $git = maintenance_find_executable('git');
    if ($git === null) {
        return null;
    }
    $result = maintenance_proc_open([$git, '-C', $private, 'rev-parse', 'HEAD']);
    if ($result['code'] !== 0) {
        return null;
    }
    $out = trim($result['stdout']);
    return $out !== '' ? $out : null;
}

/**
 * Full backup ZIP verification (same rules as restore --verify).
 *
 * @return array{manifest:array<string,mixed>,tmpdir:string}
 */
function maintenance_verify_backup_zip(string $zipPath): array
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

    $tmpdir = sys_get_temp_dir() . '/cyberleo-bakverify-' . bin2hex(random_bytes(8));
    if (!mkdir($tmpdir, 0700, true) && !is_dir($tmpdir)) {
        maintenance_fail('No se pudo crear temporal de verificación.');
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
    $seen = [];
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
        $normalized = $name;
        if ($normalized !== $name || str_contains($name, '//') || str_contains($name, './')) {
            // Reject non-normalized forms explicitly.
        }
        if (str_starts_with($name, '/') || str_contains($name, '..') || str_contains($name, "\0")
            || str_contains($name, './') || str_contains($name, '//')) {
            $zip->close();
            maintenance_fail('ZIP Slip o ruta no normalizada detectada.');
        }
        if (isset($seen[$name])) {
            $zip->close();
            maintenance_fail('Nombre duplicado dentro del ZIP.');
        }
        $seen[$name] = true;
        if (!empty($stat['external_attributes'])) {
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
        if (str_contains(strtolower($name), 'config.local')) {
            $zip->close();
            maintenance_fail('El backup no debe incluir config.local.php.');
        }
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
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($tmpdir) + 1));
        if ($rel === 'manifest.json') {
            continue;
        }
        $onDisk[] = $rel;
    }
    sort($onDisk);
    if ($onDisk !== $declared) {
        maintenance_fail('El contenido del ZIP no coincide exactamente con el manifiesto.');
    }

    foreach ($manifest['files'] as $rel => $meta) {
        if (!is_string($rel) || str_contains($rel, '..') || str_starts_with($rel, '/')
            || str_contains($rel, './') || str_contains($rel, '//')) {
            maintenance_fail('Ruta de manifiesto insegura.');
        }
        if (!is_array($meta) || !isset($meta['size'], $meta['sha256'])
            || !is_numeric($meta['size']) || !is_string($meta['sha256'])
            || !preg_match('/^[a-f0-9]{64}$/', $meta['sha256'])) {
            maintenance_fail('Metadatos de manifiesto inválidos.');
        }
        if ($rel !== 'database.sql') {
            if (str_starts_with($rel, 'assets/images/products/') || str_starts_with($rel, 'assets/images/settings/')) {
                $scope = str_starts_with($rel, 'assets/images/products/') ? 'products' : 'settings';
                if (dirname($rel) !== 'assets/images/' . $scope) {
                    maintenance_fail("Upload anidado no permitido: {$rel}");
                }
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
            || (int) $meta['size'] !== (int) $size
            || !hash_equals($meta['sha256'], $hash)
        ) {
            maintenance_fail("Hash o tamaño alterado: {$rel}");
        }
    }

    return ['manifest' => $manifest, 'tmpdir' => $tmpdir];
}
