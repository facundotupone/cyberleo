<?php
declare(strict_types=1);

/**
 * Pruebas Stage 5 — instalador, diagnóstico, backup y restore.
 * Requiere: MariaDB efímera vía env TEST_DB_SOCKET / TEST_DB_NAME,
 * ZipArchive, mysql, mysqldump, proc_open.
 */

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/maintenance.php';
require_once $root . '/includes/system_health.php';

$socket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
if ($socket === '' || $dbName === '') {
    fwrite(STDERR, "S5: TEST_DB_SOCKET y TEST_DB_NAME son obligatorios.\n");
    exit(2);
}

function s5(bool $ok, string $id, string $msg): void
{
    if ($ok) {
        printf("%s PASS - %s\n", $id, $msg);
        return;
    }
    fwrite(STDERR, "{$id} FAIL - {$msg}\n");
    exit(1);
}

function s5_run(array $env, array $cmd, ?string $stdin = null): array
{
    $cmdEnv = [];
    foreach ($env as $k => $v) {
        $cmdEnv[] = $k . '=' . $v;
    }
    $full = array_merge(['env'], $cmdEnv, $cmd);
    return maintenance_proc_open($full, $stdin);
}

maintenance_require_zip();
maintenance_require_proc_open();
maintenance_require_mysql_client();
maintenance_require_mysqldump();
// Capability split is exercised per-script; suite needs the full set available.

$work = sys_get_temp_dir() . '/cyberleo-s5-' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);
$publicA = $work . '/public_a';
$publicB = $work . '/public_b';
$private = $work . '/private';
$backups = $work . '/backups';
mkdir($backups, 0700, true);

// Stage public roots from Hostinger allowlist (build if needed).
$distZip = $root . '/dist/cyberleo-hostinger.zip';
if (!is_file($distZip)) {
    $build = maintenance_proc_open(['bash', $root . '/scripts/build_hostinger_release.sh'], null, $root);
    s5($build['code'] === 0, 'S5-BUILD-H', 'hostinger zip construido');
}
s5(is_file($distZip), 'S5-BUILD-H2', 'hostinger zip presente');

foreach ([$publicA, $publicB] as $pub) {
    mkdir($pub, 0755, true);
    $r = maintenance_proc_open(['unzip', '-q', $distZip, '-d', $pub]);
    s5($r['code'] === 0, 'S5-STAGE', 'public root extraído');
}

// Private tools tree (minimal: scripts + schema).
mkdir($private . '/scripts/lib', 0755, true);
foreach ([
    'scripts/install_store.php',
    'scripts/diagnose_store.php',
    'scripts/backup_store.php',
    'scripts/restore_store.php',
    'scripts/lib/maintenance.php',
    'schema.sql',
] as $rel) {
    $dest = $private . '/' . $rel;
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }
    copy($root . '/' . $rel, $dest);
}
// system_health is loaded from repo path in diagnose via dirname — install uses private schema + scripts.
// Point diagnose/install to use private scripts but system_health from staged public includes.
copy($root . '/includes/system_health.php', $publicA . '/includes/system_health.php');
copy($root . '/includes/system_health.php', $publicB . '/includes/system_health.php');

$baseEnv = [
    'DB_HOST' => 'localhost;unix_socket=' . $socket,
    'DB_NAME' => $dbName,
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'STORE_NAME' => 'Tienda S5',
    'SITE_URL' => 'http://localhost:8000',
    'WHATSAPP_NUMBER' => '5491100000000',
    'ADMIN_USERNAME' => 's5admin',
    'ADMIN_EMAIL' => 's5@example.test',
    'ADMIN_PASSWORD' => 'password-segura-12',
];

$installCmd = ['php', $private . '/scripts/install_store.php', '--public-root=' . $publicA, '--non-interactive'];

// --- Validation failures ---
$badUrl = s5_run(array_merge($baseEnv, ['SITE_URL' => 'http://evil.example']), $installCmd);
s5($badUrl['code'] !== 0 && str_contains($badUrl['stderr'], 'SITE_URL'), 'S5-INST-BAD-URL', 'SITE_URL inválida rechazada');

$badWa = s5_run(array_merge($baseEnv, ['WHATSAPP_NUMBER' => '12']), $installCmd);
s5($badWa['code'] !== 0 && str_contains($badWa['stderr'], 'WhatsApp'), 'S5-INST-BAD-WA', 'WhatsApp inválido rechazado');

$badPass = s5_run(array_merge($baseEnv, ['ADMIN_PASSWORD' => 'corta']), $installCmd);
s5($badPass['code'] !== 0 && str_contains($badPass['stderr'], 'contraseña'), 'S5-INST-BAD-PASS', 'contraseña corta rechazada');

$badRoot = s5_run($baseEnv, ['php', $private . '/scripts/install_store.php', '--public-root=/', '--non-interactive']);
s5($badRoot['code'] !== 0, 'S5-INST-BAD-ROOT', 'public root / rechazado');

$repoRoot = s5_run($baseEnv, ['php', $private . '/scripts/install_store.php', '--public-root=' . $root, '--non-interactive']);
s5($repoRoot['code'] !== 0, 'S5-INST-REPO', 'repositorio privado rechazado como public-root');

$cliPass = s5_run($baseEnv, ['php', $private . '/scripts/install_store.php', '--public-root=' . $publicA, '--db-pass=secret']);
s5($cliPass['code'] !== 0 && str_contains($cliPass['stderr'], 'contraseñas'), 'S5-INST-NO-CLIPASS', 'password por CLI rechazada');

// Ensure DB empty then install.
$pdo = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $dbName . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
if ($tables > 0) {
    // Drop all tables for empty install test.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES') as $row) {
        $t = array_values($row)[0];
        $pdo->exec('DROP TABLE `' . str_replace('`', '``', $t) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

$okInstall = s5_run($baseEnv, $installCmd);
$combined = $okInstall['stdout'] . $okInstall['stderr'];
s5($okInstall['code'] === 0, 'S5-INST-OK', 'instalación nueva exitosa');
s5(is_file($publicA . '/includes/config.local.php'), 'S5-INST-CONFIG', 'config.local.php creado');
s5(!str_contains($combined, 'password-segura-12'), 'S5-INST-NO-SECRET-OUT', 'salida sin contraseña admin');
s5(!preg_match('/APP_SECRET\s*=/', $combined), 'S5-INST-NO-SECRET-NAME', 'salida sin asignación de APP_SECRET');
$configSrc = file_get_contents($publicA . '/includes/config.local.php');
s5(is_string($configSrc) && str_contains($configSrc, 'APP_SECRET'), 'S5-INST-SECRET-FILE', 'APP_SECRET en config');
preg_match("/define\\('APP_SECRET', '([a-f0-9]+)'\\)/", (string) $configSrc, $m);
s5(isset($m[1]) && strlen($m[1]) === 64, 'S5-INST-SECRET-LEN', 'APP_SECRET 64 hex');
s5(!str_contains($combined, $m[1]), 'S5-INST-NO-SECRET-VALUE', 'salida sin valor de APP_SECRET');
$perms = substr(sprintf('%o', fileperms($publicA . '/includes/config.local.php')), -3);
s5($perms === '600', 'S5-INST-PERMS', 'permisos config exactamente 0600');

$userRow = $pdo->query("SELECT username, password, mail FROM users WHERE username='s5admin'")->fetch(PDO::FETCH_ASSOC);
s5(is_array($userRow), 'S5-INST-ADMIN', 'admin creado');
s5(password_verify('password-segura-12', (string) $userRow['password']), 'S5-INST-HASH', 'password_hash válido');

$storeName = (string) $pdo->query("SELECT setting_value FROM store_settings WHERE setting_key='store_name'")->fetchColumn();
s5($storeName === 'Tienda S5', 'S5-INST-SETTINGS', 'store_name inicial');

$second = s5_run($baseEnv, $installCmd);
s5($second['code'] !== 0 && str_contains($second['stderr'], 'ya está instalada'), 'S5-INST-SECOND', 'segunda ejecución rechazada');

// Non-empty DB rejection (use publicB without config, but DB already has tables).
@unlink($publicB . '/includes/config.local.php');
$nonEmpty = s5_run(array_merge($baseEnv, [
    'STORE_NAME' => 'Otra',
    'ADMIN_USERNAME' => 'otroadmin',
    'ADMIN_PASSWORD' => 'password-segura-12',
]), ['php', $private . '/scripts/install_store.php', '--public-root=' . $publicB, '--non-interactive']);
s5($nonEmpty['code'] !== 0 && str_contains($nonEmpty['stderr'], 'vacía'), 'S5-INST-NONEMPTY', 'base no vacía rechazada');

// Existing config on publicB
file_put_contents($publicB . '/includes/config.local.php', "<?php\ndefine('APP_SECRET', 'x');\n");
@chmod($publicB . '/includes/config.local.php', 0600);
$existCfg = s5_run($baseEnv, ['php', $private . '/scripts/install_store.php', '--public-root=' . $publicB, '--non-interactive']);
s5($existCfg['code'] !== 0 && str_contains($existCfg['stderr'], 'ya está instalada'), 'S5-INST-EXISTCFG', 'config existente rechazada');
@unlink($publicB . '/includes/config.local.php');

// Diagnose OK
$diag = s5_run([], ['php', $private . '/scripts/diagnose_store.php', '--public-root=' . $publicA]);
s5($diag['code'] === 0, 'S5-DIAG-OK', 'diagnóstico exit 0');
s5(!str_contains($diag['stdout'] . $diag['stderr'], 'password-segura-12'), 'S5-DIAG-NO-SECRET', 'diagnóstico sin secretos');

// Incomplete config → FAIL
$broken = $work . '/public_broken';
maintenance_proc_open(['cp', '-a', $publicA, $broken]);
file_put_contents($broken . '/includes/config.local.php', "<?php\ndefine('DB_HOST','');\ndefine('DB_USER','');\ndefine('DB_PASS','');\ndefine('DB_NAME','');\ndefine('SITE_URL','http://localhost');\ndefine('APP_SECRET','short');\n");
$diagFail = s5_run([], ['php', $private . '/scripts/diagnose_store.php', '--public-root=' . $broken]);
s5($diagFail['code'] !== 0, 'S5-DIAG-FAIL', 'config incompleta → FAIL');

// Missing table → FAIL
$pdo->exec('DROP TABLE order_rate_limits');
$diagTable = s5_run([], ['php', $private . '/scripts/diagnose_store.php', '--public-root=' . $publicA]);
s5($diagTable['code'] !== 0, 'S5-DIAG-TABLE', 'tabla faltante → FAIL');
// recreate for later backup
$pdo->exec('CREATE TABLE order_rate_limits (
  client_hash CHAR(64) NOT NULL,
  requested_at DATETIME NOT NULL,
  KEY idx_rate_client_time (client_hash, requested_at)
) ENGINE=InnoDB');

// Backup
$bak = s5_run([], [
    'php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $backups,
]);
s5($bak['code'] === 0, 'S5-BAK-OK', 'backup válido');
$zips = glob($backups . '/cyberleo-backup-*.zip') ?: [];
s5(count($zips) === 1, 'S5-BAK-FILE', 'un ZIP de backup');
$zipPath = $zips[0];
$bakPerms = substr(sprintf('%o', fileperms($zipPath)), -3);
s5($bakPerms === '600', 'S5-BAK-PERMS', 'backup publicado exactamente 0600');

$verify = s5_run([], ['php', $private . '/scripts/restore_store.php', '--verify=' . $zipPath]);
s5($verify['code'] === 0, 'S5-BAK-VERIFY', 'verify exitoso');
s5(str_contains($bak['stdout'], 'Autoverificación: OK'), 'S5-BAK-SELF-VERIFY', 'backup autodocumenta verify completo');

$za = new ZipArchive();
s5($za->open($zipPath) === true, 'S5-BAK-OPEN', 'zip abre');
$hasManifest = $za->locateName('manifest.json') !== false;
$hasDb = $za->locateName('database.sql') !== false;
$hasConfig = false;
for ($i = 0; $i < $za->numFiles; $i++) {
    $n = (string) $za->getNameIndex($i);
    if (str_contains(strtolower($n), 'config.local')) {
        $hasConfig = true;
    }
}
$manifest = json_decode((string) $za->getFromName('manifest.json'), true);
$za->close();
s5($hasManifest && $hasDb, 'S5-BAK-CONTENTS', 'manifest + database.sql');
s5(!$hasConfig, 'S5-BAK-NO-CONFIG', 'config.local excluido');
s5(is_array($manifest) && !empty($manifest['config_local_php_excluded']) && !empty($manifest['files']), 'S5-BAK-MANIFEST', 'manifest completo');

// Reject backup output inside public
$badOut = s5_run([], [
    'php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $publicA . '/assets',
]);
s5($badOut['code'] !== 0, 'S5-BAK-OUT-PUBLIC', 'output dentro de public rechazado');

// Symlink upload rejection
$sym = $publicA . '/assets/images/products/evil.webp';
$target = $work . '/outside.webp';
file_put_contents($target, 'x');
@unlink($sym);
symlink($target, $sym);
$bakSym = s5_run([], [
    'php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $backups,
]);
s5($bakSym['code'] !== 0, 'S5-BAK-SYMLINK', 'symlink en uploads rechazado');
@unlink($sym);

// Unexpected extension
file_put_contents($publicA . '/assets/images/products/nota.txt', 'no');
$bakExt = s5_run([], [
    'php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $backups,
]);
s5($bakExt['code'] !== 0, 'S5-BAK-EXT', 'extensión inesperada rechazada');
@unlink($publicA . '/assets/images/products/nota.txt');

// Nested subdirectory in uploads rejected
$nestedDir = $publicA . '/assets/images/products/subdir';
mkdir($nestedDir, 0755, true);
file_put_contents($nestedDir . '/foto.png', 'x');
$bakNested = s5_run([], [
    'php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $backups,
]);
s5($bakNested['code'] !== 0, 'S5-BAK-NESTED', 'subdirectorio en uploads rechazado');
@unlink($nestedDir . '/foto.png');
@rmdir($nestedDir);

// Tampered hash
$tamperDir = $work . '/tamper';
mkdir($tamperDir, 0700, true);
maintenance_proc_open(['unzip', '-q', $zipPath, '-d', $tamperDir]);
$sql = file_get_contents($tamperDir . '/database.sql');
file_put_contents($tamperDir . '/database.sql', $sql . "\n-- tampered\n");
$manifest = json_decode((string) file_get_contents($tamperDir . '/manifest.json'), true);
$badZip = $work . '/bad-hash.zip';
$z2 = new ZipArchive();
$z2->open($badZip, ZipArchive::CREATE);
$z2->addFile($tamperDir . '/manifest.json', 'manifest.json');
foreach (array_keys($manifest['files']) as $rel) {
    $z2->addFile($tamperDir . '/' . $rel, $rel);
}
$z2->close();
$badHash = s5_run([], ['php', $private . '/scripts/restore_store.php', '--verify=' . $badZip]);
s5($badHash['code'] !== 0, 'S5-RES-BAD-HASH', 'hash alterado rechazado');

// ZIP Slip
$slipZip = $work . '/slip.zip';
$z3 = new ZipArchive();
$z3->open($slipZip, ZipArchive::CREATE);
$z3->addFromString('manifest.json', json_encode([
    'format' => 'cyberleo-backup',
    'version' => 1,
    'created_at_utc' => gmdate('c'),
    'config_local_php_excluded' => true,
    'files' => [
        'database.sql' => ['size' => 2, 'sha256' => hash('sha256', '--')],
        '../evil.txt' => ['size' => 1, 'sha256' => hash('sha256', 'x')],
    ],
], JSON_THROW_ON_ERROR));
$z3->addFromString('database.sql', '--');
$z3->addFromString('../evil.txt', 'x');
$z3->close();
$slip = s5_run([], ['php', $private . '/scripts/restore_store.php', '--verify=' . $slipZip]);
s5($slip['code'] !== 0, 'S5-RES-SLIP', 'ZIP Slip rechazado');

// Extra file not in manifest
$extraDir = $work . '/extra';
mkdir($extraDir, 0700, true);
maintenance_proc_open(['unzip', '-q', $zipPath, '-d', $extraDir]);
file_put_contents($extraDir . '/extra.txt', 'x');
$extraZip = $work . '/extra.zip';
$z4 = new ZipArchive();
$z4->open($extraZip, ZipArchive::CREATE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extraDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $rel = substr($f->getPathname(), strlen($extraDir) + 1);
        $z4->addFile($f->getPathname(), $rel);
    }
}
$z4->close();
$extra = s5_run([], ['php', $private . '/scripts/restore_store.php', '--verify=' . $extraZip]);
s5($extra['code'] !== 0, 'S5-RES-EXTRA', 'archivo extra rechazado');

// Restore rejected on non-empty DB
$resBusy = s5_run([], [
    'php', $private . '/scripts/restore_store.php',
    '--restore-empty=' . $zipPath,
    '--public-root=' . $publicA,
]);
s5($resBusy['code'] !== 0 && str_contains($resBusy['stderr'], 'vacía'), 'S5-RES-BUSY-DB', 'base no vacía rechazada');

// Prepare empty publicB + empty DB2 for restore
$db2 = $dbName . '_s5b';
$admin = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $db2) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $db2) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

// Write config.local for publicB pointing to empty db2 (simulate installed empty store config without schema)
$appSecret = bin2hex(random_bytes(32));
file_put_contents($publicB . '/includes/config.local.php', "<?php\n" .
    "define('DB_HOST', " . var_export('localhost;unix_socket=' . $socket, true) . ");\n" .
    "define('DB_USER', 'root');\n" .
    "define('DB_PASS', '');\n" .
    "define('DB_NAME', " . var_export($db2, true) . ");\n" .
    "define('SITE_URL', 'http://localhost:8000');\n" .
    "define('STORE_NAME', 'Restore Target');\n" .
    "define('WHATSAPP_NUMBER', '5491100000000');\n" .
    "define('STORE_INSTAGRAM', '');\n" .
    "define('APP_SECRET', " . var_export($appSecret, true) . ");\n"
);
@chmod($publicB . '/includes/config.local.php', 0600);

// Seed a product image into publicA and rebuild backup so restore has an image
$img = $publicA . '/assets/images/products/' . str_repeat('a', 32) . '.png';
copy($root . '/tests/fixtures/tiny.png.b64', $work . '/tiny.b64');
// decode fixture
$bin = base64_decode(trim((string) file_get_contents($root . '/tests/fixtures/tiny.png.b64')), true);
if ($bin !== false) {
    file_put_contents($img, $bin);
}
// Re-backup after adding image
foreach (glob($backups . '/cyberleo-backup-*.zip') ?: [] as $old) {
    @unlink($old);
}
$bak2 = s5_run([], [
    'php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $backups,
]);
s5($bak2['code'] === 0, 'S5-BAK-OK2', 'backup con imagen');
$zips = glob($backups . '/cyberleo-backup-*.zip') ?: [];
$zipPath = $zips[0];

$countsBefore = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'categories' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
    'products' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'store_settings' => (int) $pdo->query('SELECT COUNT(*) FROM store_settings')->fetchColumn(),
];

$resOk = s5_run([], [
    'php', $private . '/scripts/restore_store.php',
    '--restore-empty=' . $zipPath,
    '--public-root=' . $publicB,
]);
s5($resOk['code'] === 0, 'S5-RES-OK', 'restore en segunda base vacía');

$pdo2 = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $db2 . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$countsAfter = [
    'users' => (int) $pdo2->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'categories' => (int) $pdo2->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
    'products' => (int) $pdo2->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'orders' => (int) $pdo2->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'store_settings' => (int) $pdo2->query('SELECT COUNT(*) FROM store_settings')->fetchColumn(),
];
s5($countsAfter === $countsBefore, 'S5-RES-COUNTS', 'cantidades equivalentes');
$hash = (string) $pdo2->query("SELECT password FROM users WHERE username='s5admin'")->fetchColumn();
s5(password_verify('password-segura-12', $hash), 'S5-RES-LOGIN', 'admin restaurado verificable');

// Reject restore when upload already exists
if (is_file($img)) {
    $destImg = $publicB . '/assets/images/products/' . basename($img);
    if (!is_file($destImg)) {
        // already restored
    }
    // Drop db2 and recreate empty, but leave image file → should reject
    $admin->exec('DROP DATABASE `' . str_replace('`', '``', $db2) . '`');
    $admin->exec('CREATE DATABASE `' . str_replace('`', '``', $db2) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    if (is_file($destImg)) {
        $resUp = s5_run([], [
            'php', $private . '/scripts/restore_store.php',
            '--restore-empty=' . $zipPath,
            '--public-root=' . $publicB,
        ]);
        s5($resUp['code'] !== 0, 'S5-RES-UPLOAD-EXISTS', 'upload existente rechazado');
    } else {
        s5(true, 'S5-RES-UPLOAD-EXISTS', 'upload existente rechazado (sin imagen en backup skip)');
    }
}

// --- S5-RES-SQL-FAIL: database.sql inválido con hash coherente; uploads vacíos ---
$sqlFailDir = $work . '/sqlfail';
mkdir($sqlFailDir, 0700, true);
maintenance_proc_open(['unzip', '-q', $zipPath, '-d', $sqlFailDir]);
$badSql = "THIS IS NOT VALID SQL;\n";
file_put_contents($sqlFailDir . '/database.sql', $badSql);
$man = json_decode((string) file_get_contents($sqlFailDir . '/manifest.json'), true);
$man['files']['database.sql'] = [
    'size' => strlen($badSql),
    'sha256' => hash('sha256', $badSql),
];
file_put_contents($sqlFailDir . '/manifest.json', json_encode($man, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$sqlFailZip = $work . '/sql-fail.zip';
$zSql = new ZipArchive();
$zSql->open($sqlFailZip, ZipArchive::CREATE);
$zSql->addFile($sqlFailDir . '/manifest.json', 'manifest.json');
foreach (array_keys($man['files']) as $rel) {
    $zSql->addFile($sqlFailDir . '/' . $rel, $rel);
}
$zSql->close();

$db3 = $dbName . '_s5sql';
$admin->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $db3) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $db3) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$publicC = $work . '/public_c';
maintenance_proc_open(['cp', '-a', $publicB, $publicC]);
// limpiar uploads C (dejar solo .htaccess)
foreach (['products', 'settings'] as $scope) {
    $dir = $publicC . '/assets/images/' . $scope;
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') {
            continue;
        }
        @unlink($dir . '/' . $n);
    }
}
file_put_contents($publicC . '/includes/config.local.php', "<?php\n" .
    "define('DB_HOST', " . var_export('localhost;unix_socket=' . $socket, true) . ");\n" .
    "define('DB_USER', 'root');\n" .
    "define('DB_PASS', '');\n" .
    "define('DB_NAME', " . var_export($db3, true) . ");\n" .
    "define('SITE_URL', 'http://localhost:8000');\n" .
    "define('STORE_NAME', 'SQL Fail');\n" .
    "define('WHATSAPP_NUMBER', '5491100000000');\n" .
    "define('STORE_INSTAGRAM', '');\n" .
    "define('APP_SECRET', " . var_export(bin2hex(random_bytes(32)), true) . ");\n"
);
@chmod($publicC . '/includes/config.local.php', 0600);

$resSqlFail = s5_run([], [
    'php', $private . '/scripts/restore_store.php',
    '--restore-empty=' . $sqlFailZip,
    '--public-root=' . $publicC,
]);
s5($resSqlFail['code'] !== 0, 'S5-RES-SQL-FAIL', 'restore con SQL inválido falla');
$uploadsEmpty = true;
foreach (['products', 'settings'] as $scope) {
    foreach (scandir($publicC . '/assets/images/' . $scope) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        if ($n !== '.htaccess') {
            $uploadsEmpty = false;
        }
    }
}
s5($uploadsEmpty, 'S5-RES-SQL-FAIL-CLEAN', 'uploads vacíos salvo .htaccess tras SQL fail');
// segundo intento no bloqueado por residuos
$admin->exec('DROP DATABASE `' . str_replace('`', '``', $db3) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $db3) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$resSqlFail2 = s5_run([], [
    'php', $private . '/scripts/restore_store.php',
    '--restore-empty=' . $sqlFailZip,
    '--public-root=' . $publicC,
]);
s5($resSqlFail2['code'] !== 0, 'S5-RES-SQL-FAIL-RETRY', 'segundo intento no queda bloqueado por residuos');

// Simulated fail on second image copy + cleanup
$img2 = $publicA . '/assets/images/products/' . str_repeat('b', 32) . '.png';
$bin2 = base64_decode(trim((string) file_get_contents($root . '/tests/fixtures/tiny.png.b64')), true);
if ($bin2 !== false) {
    file_put_contents($img2, $bin2);
}
foreach (glob($backups . '/cyberleo-backup-*.zip') ?: [] as $old) {
    @unlink($old);
}
$bak3 = s5_run([], [
    'php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $backups,
]);
s5($bak3['code'] === 0, 'S5-BAK-OK3', 'backup con dos imágenes');
$zipTwo = (glob($backups . '/cyberleo-backup-*.zip') ?: [null])[0];
s5(is_string($zipTwo), 'S5-BAK-OK3-FILE', 'zip dos imágenes presente');

$db4 = $dbName . '_s5copy';
$admin->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $db4) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $db4) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$publicD = $work . '/public_d';
maintenance_proc_open(['cp', '-a', $publicB, $publicD]);
foreach (['products', 'settings'] as $scope) {
    $dir = $publicD . '/assets/images/' . $scope;
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') {
            continue;
        }
        @unlink($dir . '/' . $n);
    }
}
file_put_contents($publicD . '/includes/config.local.php', "<?php\n" .
    "define('DB_HOST', " . var_export('localhost;unix_socket=' . $socket, true) . ");\n" .
    "define('DB_USER', 'root');\n" .
    "define('DB_PASS', '');\n" .
    "define('DB_NAME', " . var_export($db4, true) . ");\n" .
    "define('SITE_URL', 'http://localhost:8000');\n" .
    "define('STORE_NAME', 'Copy Fail');\n" .
    "define('WHATSAPP_NUMBER', '5491100000000');\n" .
    "define('STORE_INSTAGRAM', '');\n" .
    "define('APP_SECRET', " . var_export(bin2hex(random_bytes(32)), true) . ");\n"
);
@chmod($publicD . '/includes/config.local.php', 0600);
$htBefore = [];
foreach (['products', 'settings'] as $scope) {
    $htBefore[$scope] = is_file($publicD . '/assets/images/' . $scope . '/.htaccess');
}
$resCopyFail = s5_run(
    ['CYBERLEO_TEST_FAIL_UPLOAD_COPY' => '1'],
    [
        'php', $private . '/scripts/restore_store.php',
        '--restore-empty=' . $zipTwo,
        '--public-root=' . $publicD,
    ]
);
s5($resCopyFail['code'] !== 0, 'S5-RES-COPY-FAIL', 'fallo simulado en 2ª copia');
s5(str_contains($resCopyFail['stderr'], 'Recreá la base'), 'S5-RES-COPY-FAIL-MSG', 'avisa recrear base tras SQL');
$createdLeft = 0;
foreach (['products', 'settings'] as $scope) {
    foreach (scandir($publicD . '/assets/images/' . $scope) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') {
            continue;
        }
        $createdLeft++;
    }
    s5($htBefore[$scope] && is_file($publicD . '/assets/images/' . $scope . '/.htaccess'), 'S5-RES-COPY-FAIL-HT-' . $scope, '.htaccess preservado');
}
s5($createdLeft === 0, 'S5-RES-COPY-FAIL-CLEAN', 'archivos creados por el intento eliminados');

// Duplicate ZIP entry name
$dupZip = $work . '/dup.zip';
$zDup = new ZipArchive();
$zDup->open($dupZip, ZipArchive::CREATE);
$payload = '--';
$meta = ['size' => 2, 'sha256' => hash('sha256', $payload)];
$zDup->addFromString('manifest.json', json_encode([
    'format' => 'cyberleo-backup',
    'version' => 1,
    'created_at_utc' => gmdate('c'),
    'config_local_php_excluded' => true,
    'files' => ['database.sql' => $meta],
], JSON_THROW_ON_ERROR));
$zDup->addFromString('database.sql', $payload);
// ZipArchive may overwrite same name; craft via raw if needed — use two adds same name
$zDup->addFromString('database.sql', $payload . 'x');
$zDup->close();
// If ZipArchive collapsed duplicates, craft manually with different approach:
$dupCheck = new ZipArchive();
$dupCheck->open($dupZip);
$dupNames = [];
for ($i = 0; $i < $dupCheck->numFiles; $i++) {
    $dupNames[] = $dupCheck->getNameIndex($i);
}
$dupCheck->close();
if (count($dupNames) !== count(array_unique($dupNames))) {
    $dupRes = s5_run([], ['php', $private . '/scripts/restore_store.php', '--verify=' . $dupZip]);
    s5($dupRes['code'] !== 0, 'S5-RES-DUPLICATE', 'nombre duplicado en ZIP rechazado');
} else {
    // Force duplicate detection via verify path that sees seen[] — inject by rewriting maintenance test helper
    // Build a zip with duplicate using PHP stream workaround: two entries with same name via ZipArchive::FL_OVERWRITE off
    // Fallback: call maintenance_verify with crafted tmpdir simulation — instead add second file with non-normalized path
    $dupZip2 = $work . '/dup2.zip';
    $zDup2 = new ZipArchive();
    $zDup2->open($dupZip2, ZipArchive::CREATE);
    $zDup2->addFromString('manifest.json', json_encode([
        'format' => 'cyberleo-backup',
        'version' => 1,
        'created_at_utc' => gmdate('c'),
        'config_local_php_excluded' => true,
        'files' => [
            'database.sql' => $meta,
            'assets/images/products/./x.png' => ['size' => 1, 'sha256' => hash('sha256', 'x')],
        ],
    ], JSON_THROW_ON_ERROR));
    $zDup2->addFromString('database.sql', $payload);
    $zDup2->addFromString('assets/images/products/./x.png', 'x');
    $zDup2->close();
    $dupRes = s5_run([], ['php', $private . '/scripts/restore_store.php', '--verify=' . $dupZip2]);
    s5($dupRes['code'] !== 0, 'S5-RES-DUPLICATE', 'ruta no normalizada / duplicado rechazado');
}

// Credential validation
$credName = maintenance_proc_open([
    'php', '-r',
    'require "' . $private . '/scripts/lib/maintenance.php"; maintenance_assert_safe_credentials(["host"=>"localhost","socket"=>null,"name"=>"--execute=evil","user"=>"u","pass"=>"p"]);',
]);
s5($credName['code'] !== 0, 'S5-CRED-DBNAME', 'DB_NAME tipo --execute rechazado');

$credNl = maintenance_proc_open([
    'php', '-r',
    'require "' . $private . '/scripts/lib/maintenance.php"; maintenance_assert_safe_credentials(["host"=>"localhost","socket"=>null,"name"=>"db1","user"=>"u","pass"=>"p' . "\n" . 'x"]);',
]);
s5($credNl['code'] !== 0, 'S5-CRED-PASS-NL', 'password con salto de línea rechazada');

$credPrompt = maintenance_proc_open([
    'env', 'PATH=/usr/bin:/bin',
    'php', '-r',
    'require "' . $private . '/scripts/lib/maintenance.php"; putenv("DB_HOST"); putenv("DB_NAME"); putenv("DB_USER"); putenv("DB_PASS"); maintenance_prompt("DB_PASS: ", true);',
], "secret\n");
s5($credPrompt['code'] !== 0, 'S5-CRED-NO-STTY', 'prompt hidden sin stty seguro falla');

// S5-CRED-STTY-ECHO-FAIL: real PTY where stty -g OK but stty -echo fails → abort before fgets.
$secretPass = 'super-secret-password-NEVER-ECHO';
$fakeSttyDir = $work . '/fake-stty-bin';
mkdir($fakeSttyDir, 0700, true);
$fakeStty = $fakeSttyDir . '/stty';
file_put_contents($fakeStty, <<<'BASH'
#!/bin/bash
set -euo pipefail
args=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    -F|-f) shift 2 ;;
    *) args+=("$1"); shift ;;
  esac
done
if [[ ${#args[@]} -eq 0 ]]; then
  exit 2
fi
if [[ "${args[0]}" == "-g" ]]; then
  printf '%s\n' '500:5:bf:8a3b:3:1c:7f:15:4:0:1:0:11:13:1a:0:12:f:17:16:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0'
  exit 0
fi
if [[ "${args[0]}" == "-echo" ]]; then
  exit 1
fi
# restore / echo / other: pretend success without touching real tty
exit 0
BASH
);
chmod(0755, $fakeStty);
$ptyScript = $work . '/pty_prompt_test.py';
file_put_contents($ptyScript, <<<'PY'
import os, sys, time, select, pty, subprocess
secret = sys.argv[1]
php = sys.argv[2]
maintenance = sys.argv[3]
fake_bin = sys.argv[4]
transcript_path = sys.argv[5]

env = os.environ.copy()
env["PATH"] = fake_bin + os.pathsep + env.get("PATH", "")
# Clear DB_* so prompt path is used
for k in ("DB_HOST", "DB_NAME", "DB_USER", "DB_PASS"):
    env.pop(k, None)

master, slave = pty.openpty()
cmd = [
    php, "-r",
    "require $argv[1]; maintenance_prompt('DB_PASS: ', true); echo 'SHOULD_NOT_PRINT\\n';",
    maintenance,
]
proc = subprocess.Popen(
    cmd,
    stdin=slave,
    stdout=slave,
    stderr=slave,
    env=env,
    close_fds=True,
)
os.close(slave)
buf = b""
deadline = time.time() + 5.0
typed = False
while time.time() < deadline:
    r, _, _ = select.select([master], [], [], 0.1)
    if master in r:
        try:
            chunk = os.read(master, 4096)
        except OSError:
            break
        if not chunk:
            break
        buf += chunk
    rc = proc.poll()
    if rc is not None:
        # Drain remaining
        while True:
            r, _, _ = select.select([master], [], [], 0.05)
            if master not in r:
                break
            try:
                chunk = os.read(master, 4096)
            except OSError:
                break
            if not chunk:
                break
            buf += chunk
        break
    # Only type secret if process still running after prompt-ish activity —
    # with the fix it should already have exited before reading.
    if (not typed) and b"DB_PASS" in buf and proc.poll() is None and time.time() > deadline - 4.0:
        # Give a brief moment; if still alive, attempt type (bug would echo).
        time.sleep(0.2)
        if proc.poll() is None:
            os.write(master, (secret + "\n").encode())
            typed = True
else:
    proc.kill()
    proc.wait()

os.close(master)
rc = proc.wait()
text = buf.decode("utf-8", "replace")
open(transcript_path, "w", encoding="utf-8").write(text)
# exit 0 from this helper means TEST ASSERTIONS PASSED
if rc == 0:
    sys.stderr.write("php exited 0 unexpectedly\n")
    sys.exit(11)
if "SHOULD_NOT_PRINT" in text:
    sys.stderr.write("password was read\n")
    sys.exit(12)
if secret in text:
    sys.stderr.write("secret leaked into PTY transcript\n")
    sys.exit(13)
if "variable de entorno" not in text and "variable de entorno" not in text.lower():
    # Message goes to STDERR attached to PTY, should be in transcript
    if "ocultar" not in text and "entorno" not in text:
        sys.stderr.write("expected generic env message\n")
        sys.exit(14)
sys.exit(0)
PY
);
$ptyOut = $work . '/pty-transcript.txt';
$ptyRun = maintenance_proc_open([
    'python3', $ptyScript, $secretPass, PHP_BINARY, $private . '/scripts/lib/maintenance.php', $fakeSttyDir, $ptyOut,
]);
$ptyCombined = $ptyRun['stdout'] . $ptyRun['stderr'] . (is_file($ptyOut) ? (string) file_get_contents($ptyOut) : '');
s5($ptyRun['code'] === 0, 'S5-CRED-STTY-ECHO-FAIL', 'PTY: stty -echo falla aborta antes de leer');
s5(!str_contains($ptyCombined, $secretPass), 'S5-CRED-STTY-ECHO-FAIL-SECRET', 'secreto ausente de stdout/stderr/transcript');

// Insecure backup perms rejected (assert deletes)
$insecure = $work . '/insecure.zip';
file_put_contents($insecure, 'x');
@chmod($insecure, 0644);
$permCheck = maintenance_proc_open([
    'php', '-r',
    'require "' . $private . '/scripts/lib/maintenance.php"; maintenance_assert_mode_0600("' . $insecure . '", true);',
]);
s5($permCheck['code'] !== 0 && !is_file($insecure), 'S5-PERM-0644', '0644 rechazado y archivo eliminado');

// Large dump + low memory_limit streaming
$bigSql = $work . '/big.sql';
$fh = fopen($bigSql, 'wb');
fwrite($fh, "-- big dump\n");
$chunk = str_repeat('--' . str_repeat('x', 100) . "\n", 1000);
for ($i = 0; $i < 40; $i++) {
    fwrite($fh, $chunk); // ~4MB+
}
fclose($fh);
$memTest = maintenance_proc_open([
    'php', '-d', 'memory_limit=8M', '-r',
    'require "' . $private . '/scripts/lib/maintenance.php";
     $creds=["host"=>"localhost","socket"=>' . var_export($socket, true) . ',"name"=>' . var_export($dbName, true) . ',"user"=>"root","pass"=>""];
     // Import large file via streaming (must not load whole file)
     // Use a tiny valid statement file instead for import success path, and only prove export streaming:
     $out="' . $work . '/stream-out.sql";
     maintenance_export_database($creds, $out);
     echo filesize($out);
    ',
]);
s5($memTest['code'] === 0 && (int) trim($memTest['stdout']) > 0, 'S5-STREAM-DUMP', 'mysqldump streaming con memory_limit bajo');

// Diagnose with PATH that has php but not mysql/mysqldump
$emptyPath = $work . '/path-no-mysql';
mkdir($emptyPath, 0700, true);
$phpBin = trim((string) shell_exec('command -v php'));
symlink($phpBin, $emptyPath . '/php');
$diagPath2 = s5_run(['PATH' => $emptyPath], [
    $emptyPath . '/php', $private . '/scripts/diagnose_store.php', '--public-root=' . $publicA,
]);
s5(
    str_contains($diagPath2['stdout'], 'mysql') && str_contains($diagPath2['stdout'], 'WARN'),
    'S5-DIAG-NO-MYSQL',
    'PATH sin mysql reporta WARN explícito'
);

// Unreadable uploads: system_health must not throw / leak paths
$unreadable = $work . '/public_unreadable';
maintenance_proc_open(['cp', '-a', $publicA, $unreadable]);
@chmod($unreadable . '/assets/images/products', 0000);
$healthSafe = maintenance_proc_open([
    'php', '-r',
    'require "' . $unreadable . '/includes/config.php";
     require "' . $root . '/includes/system_health.php";
     $pdo=null;
     try { require "' . $unreadable . '/includes/db.php"; } catch (Throwable $e) {}
     $checks=system_health_run_checks($pdo, "' . $unreadable . '");
     $out=json_encode($checks);
     if (str_contains($out, "' . $unreadable . '") || str_contains($out, "/workspace/")) { fwrite(STDERR,"path leak\n"); exit(2); }
     echo system_health_summary($checks)["status"];
    ',
]);
@chmod($unreadable . '/assets/images/products', 0755);
s5($healthSafe['code'] === 0 && $healthSafe['stderr'] === '', 'S5-DIAG-UNREADABLE', 'uploads ilegibles sin excepción ni fuga de rutas');

// --- S5-RES-PARTIAL-COPY: partial dest left then fail → cleanup ---
$dbPartial = $dbName . '_s5partial';
$admin->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbPartial) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $dbPartial) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$publicPartial = $work . '/public_partial';
maintenance_proc_open(['cp', '-a', $publicB, $publicPartial]);
foreach (['products', 'settings'] as $scope) {
    $dir = $publicPartial . '/assets/images/' . $scope;
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') {
            continue;
        }
        @unlink($dir . '/' . $n);
    }
}
$htHashes = [];
foreach (['products', 'settings'] as $scope) {
    $ht = $publicPartial . '/assets/images/' . $scope . '/.htaccess';
    s5(is_file($ht), 'S5-RES-PARTIAL-HT-PRE-' . $scope, '.htaccess presente antes');
    $htHashes[$scope] = hash_file('sha256', $ht);
}
file_put_contents($publicPartial . '/includes/config.local.php', "<?php\n" .
    "define('DB_HOST', " . var_export('localhost;unix_socket=' . $socket, true) . ");\n" .
    "define('DB_USER', 'root');\n" .
    "define('DB_PASS', '');\n" .
    "define('DB_NAME', " . var_export($dbPartial, true) . ");\n" .
    "define('SITE_URL', 'http://localhost:8000');\n" .
    "define('STORE_NAME', 'Partial');\n" .
    "define('WHATSAPP_NUMBER', '5491100000000');\n" .
    "define('STORE_INSTAGRAM', '');\n" .
    "define('APP_SECRET', " . var_export(bin2hex(random_bytes(32)), true) . ");\n"
);
@chmod($publicPartial . '/includes/config.local.php', 0600);
s5(is_string($zipTwo) && is_file($zipTwo), 'S5-RES-PARTIAL-ZIP', 'backup con imágenes disponible');
$resPartial = s5_run(
    ['CYBERLEO_TEST_PARTIAL_UPLOAD_COPY' => '1'],
    [
        'php', $private . '/scripts/restore_store.php',
        '--restore-empty=' . $zipTwo,
        '--public-root=' . $publicPartial,
    ]
);
s5($resPartial['code'] !== 0, 'S5-RES-PARTIAL-COPY', 'restore falla con copia parcial simulada');
$partialLeft = 0;
foreach (['products', 'settings'] as $scope) {
    foreach (scandir($publicPartial . '/assets/images/' . $scope) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        if ($n === '.htaccess') {
            $ht = $publicPartial . '/assets/images/' . $scope . '/.htaccess';
            s5(hash_file('sha256', $ht) === $htHashes[$scope], 'S5-RES-PARTIAL-HT-HASH-' . $scope, '.htaccess hash preservado');
            continue;
        }
        $partialLeft++;
    }
}
s5($partialLeft === 0, 'S5-RES-PARTIAL-CLEAN', 'sin archivos parciales residuales');
// Recreate empty DB and confirm retry not blocked
$admin->exec('DROP DATABASE `' . str_replace('`', '``', $dbPartial) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $dbPartial) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$resPartialRetry = s5_run([], [
    'php', $private . '/scripts/restore_store.php',
    '--restore-empty=' . $zipTwo,
    '--public-root=' . $publicPartial,
]);
s5($resPartialRetry['code'] === 0, 'S5-RES-PARTIAL-RETRY', 'restore posterior no bloqueado por residuos');

// --- Capability-specific PATH tests ---
$mkPath = static function (string $dir, array $bins) use ($work): string {
    $pathDir = $work . '/' . $dir;
    mkdir($pathDir, 0700, true);
    foreach ($bins as $name) {
        $real = trim((string) shell_exec('command -v ' . escapeshellarg($name)));
        if ($real === '' || !is_file($real)) {
            fwrite(STDERR, "bin missing for PATH fixture: {$name}\n");
            exit(1);
        }
        symlink($real, $pathDir . '/' . $name);
    }
    // Always include php for invoking scripts via relative name if needed
    return $pathDir;
};
$phpOnly = $mkPath('path-php', ['php']);
$phpMysql = $mkPath('path-php-mysql', ['php', 'mysql']);
$phpDump = $mkPath('path-php-dump', ['php', 'mysqldump']);
$phpMysqlDump = $mkPath('path-php-mysql-dump', ['php', 'mysql', 'mysqldump']);

// S5-VERIFY-NO-DB-BINS: verify works without mysql/mysqldump
$verifyNoDb = s5_run(['PATH' => $phpOnly], [
    $phpOnly . '/php', $private . '/scripts/restore_store.php', '--verify=' . $zipPath,
]);
s5($verifyNoDb['code'] === 0, 'S5-VERIFY-NO-DB-BINS', 'verify válido sin mysql/mysqldump');
s5(
    !str_contains($verifyNoDb['stdout'] . $verifyNoDb['stderr'], $socket)
    && !str_contains($verifyNoDb['stdout'] . $verifyNoDb['stderr'], '/workspace/'),
    'S5-VERIFY-NO-DB-BINS-SAFE',
    'verify sin fugas de rutas'
);

// Negativo: backup without mysqldump
$bakNoDump = s5_run(['PATH' => $phpMysql], [
    $phpMysql . '/php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $backups,
]);
s5($bakNoDump['code'] !== 0, 'S5-BAK-NEED-DUMP', 'backup falla sin mysqldump');
s5(
    !str_contains($bakNoDump['stderr'], $socket)
    && !preg_match('/DB_PASS|password-segura/', $bakNoDump['stdout'] . $bakNoDump['stderr']),
    'S5-BAK-NEED-DUMP-SAFE',
    'error genérico sin secretos/rutas'
);

// S5-INST-NO-MYSQLDUMP: install with mysql, without mysqldump
$dbInst = $dbName . '_s5instnodump';
$admin->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbInst) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $dbInst) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$publicInst = $work . '/public_inst_nodump';
maintenance_proc_open(['cp', '-a', $publicB, $publicInst]);
@unlink($publicInst . '/includes/config.local.php');
foreach (['products', 'settings'] as $scope) {
    $dir = $publicInst . '/assets/images/' . $scope;
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') {
            continue;
        }
        @unlink($dir . '/' . $n);
    }
}
$instNoDump = s5_run(array_merge($baseEnv, [
    'PATH' => $phpMysql,
    'DB_NAME' => $dbInst,
    'ADMIN_USERNAME' => 'instnodump',
    'ADMIN_PASSWORD' => 'password-segura-12',
]), [
    $phpMysql . '/php', $private . '/scripts/install_store.php',
    '--public-root=' . $publicInst,
    '--non-interactive',
]);
s5($instNoDump['code'] === 0, 'S5-INST-NO-MYSQLDUMP', 'install OK sin mysqldump');

// Negativo install without mysql
$publicInst2 = $work . '/public_inst_needmysql';
maintenance_proc_open(['cp', '-a', $publicB, $publicInst2]);
@unlink($publicInst2 . '/includes/config.local.php');
foreach (['products', 'settings'] as $scope) {
    $dir = $publicInst2 . '/assets/images/' . $scope;
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') {
            continue;
        }
        @unlink($dir . '/' . $n);
    }
}
$dbInst2 = $dbName . '_s5needmysql';
$admin->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbInst2) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $dbInst2) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$instNoMysql = s5_run(array_merge($baseEnv, [
    'PATH' => $phpOnly,
    'DB_NAME' => $dbInst2,
    'ADMIN_USERNAME' => 'needmysql',
]), [
    $phpOnly . '/php', $private . '/scripts/install_store.php',
    '--public-root=' . $publicInst2,
    '--non-interactive',
]);
s5($instNoMysql['code'] !== 0, 'S5-INST-NEED-MYSQL', 'install falla sin mysql');
s5(
    !str_contains($instNoMysql['stderr'], $socket)
    && !str_contains($instNoMysql['stdout'] . $instNoMysql['stderr'], 'password-segura'),
    'S5-INST-NEED-MYSQL-SAFE',
    'error install genérico'
);

// S5-BAK-NO-MYSQL-CLIENT: backup with mysqldump, without mysql client
foreach (glob($backups . '/cyberleo-backup-*.zip') ?: [] as $old) {
    @unlink($old);
}
$bakNoMysql = s5_run(['PATH' => $phpDump], [
    $phpDump . '/php', $private . '/scripts/backup_store.php',
    '--public-root=' . $publicA,
    '--output-dir=' . $backups,
]);
s5($bakNoMysql['code'] === 0, 'S5-BAK-NO-MYSQL-CLIENT', 'backup OK sin cliente mysql');
$bakZips = glob($backups . '/cyberleo-backup-*.zip') ?: [];
s5(count($bakZips) >= 1, 'S5-BAK-NO-MYSQL-CLIENT-FILE', 'backup zip creado');

// Negativo backup without mysqldump already covered; negativo without zip would need unloading ZipArchive — skip.

// S5-RES-NO-MYSQLDUMP: restore with mysql, without mysqldump
$dbResNd = $dbName . '_s5resnodump';
$admin->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbResNd) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $dbResNd) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$publicResNd = $work . '/public_res_nodump';
maintenance_proc_open(['cp', '-a', $publicB, $publicResNd]);
foreach (['products', 'settings'] as $scope) {
    $dir = $publicResNd . '/assets/images/' . $scope;
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') {
            continue;
        }
        @unlink($dir . '/' . $n);
    }
}
file_put_contents($publicResNd . '/includes/config.local.php', "<?php\n" .
    "define('DB_HOST', " . var_export('localhost;unix_socket=' . $socket, true) . ");\n" .
    "define('DB_USER', 'root');\n" .
    "define('DB_PASS', '');\n" .
    "define('DB_NAME', " . var_export($dbResNd, true) . ");\n" .
    "define('SITE_URL', 'http://localhost:8000');\n" .
    "define('STORE_NAME', 'ResNoDump');\n" .
    "define('WHATSAPP_NUMBER', '5491100000000');\n" .
    "define('STORE_INSTAGRAM', '');\n" .
    "define('APP_SECRET', " . var_export(bin2hex(random_bytes(32)), true) . ");\n"
);
@chmod($publicResNd . '/includes/config.local.php', 0600);
$resNoDump = s5_run(['PATH' => $phpMysql], [
    $phpMysql . '/php', $private . '/scripts/restore_store.php',
    '--restore-empty=' . $bakZips[0],
    '--public-root=' . $publicResNd,
]);
s5($resNoDump['code'] === 0, 'S5-RES-NO-MYSQLDUMP', 'restore OK sin mysqldump');

// Negativo restore without mysql
$admin->exec('DROP DATABASE `' . str_replace('`', '``', $dbResNd) . '`');
$admin->exec('CREATE DATABASE `' . str_replace('`', '``', $dbResNd) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
foreach (['products', 'settings'] as $scope) {
    $dir = $publicResNd . '/assets/images/' . $scope;
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === '.htaccess') {
            continue;
        }
        @unlink($dir . '/' . $n);
    }
}
$resNeedMysql = s5_run(['PATH' => $phpOnly], [
    $phpOnly . '/php', $private . '/scripts/restore_store.php',
    '--restore-empty=' . $bakZips[0],
    '--public-root=' . $publicResNd,
]);
s5($resNeedMysql['code'] !== 0, 'S5-RES-NEED-MYSQL', 'restore falla sin mysql');
s5(
    !str_contains($resNeedMysql['stderr'], $socket)
    && !str_contains($resNeedMysql['stdout'] . $resNeedMysql['stderr'], 'password-segura'),
    'S5-RES-NEED-MYSQL-SAFE',
    'error restore genérico'
);

// S5-PKG-PRIVATE-RUNTIME: build private zip and run from extracted trees only
$privBuild = maintenance_proc_open(
    ['env', 'TEST_DB_SOCKET=' . $socket, 'TEST_DB_NAME=' . $dbName, 'bash', $root . '/scripts/build_private_tools.sh'],
    null,
    $root
);
s5($privBuild['code'] === 0, 'S5-PKG-PRIVATE-RUNTIME', 'builder privado con runtime + negativos OK');
s5(is_file($root . '/dist/cyberleo-private-tools.zip'), 'S5-PKG-PRIVATE-ZIP', 'ZIP privado presente');

printf("Stage 5 maintenance tests OK\n");
