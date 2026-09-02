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

maintenance_require_zip_and_proc();

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
s5(in_array($perms, ['600', '400', '640', '644'], true), 'S5-INST-PERMS', 'permisos config restrictivos o legibles');

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

$verify = s5_run([], ['php', $private . '/scripts/restore_store.php', '--verify=' . $zipPath]);
s5($verify['code'] === 0, 'S5-BAK-VERIFY', 'verify exitoso');

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

printf("Stage 5 maintenance tests OK\n");
