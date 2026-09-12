<?php
declare(strict_types=1);

/**
 * Isolated S-04 runner for Windows/XAMPP. Does not start or stop Apache, MySQL or Chrome.
 * Uses only cyberleo_automated_test on 127.0.0.1.
 */

const TEST_DB_NAME = 'cyberleo_automated_test';
const PHP_BIN = 'D:\\xampp\\php\\php.exe';
const MYSQL_BIN = 'D:\\xampp\\mysql\\bin\\mysql.exe';
const MARKER_NAME = '.cyberleo-xampp-runner-marker';

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assert_local_host(string $host): void {
    $normalized = strtolower(trim($host));
    if (!in_array($normalized, ['127.0.0.1', 'localhost'], true)) {
        fail('Host no local abortado.');
    }
}

function assert_test_db(string $name): void {
    if ($name !== TEST_DB_NAME) {
        fail('Solo se admite la base cyberleo_automated_test.');
    }
    if (!str_ends_with($name, '_test')) {
        fail('La base no termina en _test.');
    }
}

function mysql_exec(string $sql, ?string $database = null): string {
    $args = [
        MYSQL_BIN,
        '--host=127.0.0.1',
        '--protocol=TCP',
        '--user=root',
        '--batch',
        '--raw',
        '-e',
        $sql,
    ];
    if ($database !== null) {
        $args[] = $database;
    }
    $cmd = implode(' ', array_map('escapeshellarg', $args));
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    if ($code !== 0) {
        fail('mysql fallo con codigo ' . $code);
    }
    return implode("\n", $output);
}

function snapshot_mysql(string $label): void {
    fwrite(STDERR, "==== MYSQL SNAPSHOT {$label} ====\n");
    fwrite(STDERR, mysql_exec(
        'SHOW FULL PROCESSLIST; '
        . "SELECT ID,USER,HOST,DB,COMMAND,TIME,STATE,LEFT(INFO,180) AS Q FROM information_schema.processlist WHERE DB='" . TEST_DB_NAME . "'; "
        . 'SELECT trx_id,trx_state,trx_started,trx_mysql_thread_id,trx_query FROM information_schema.innodb_trx; '
        . 'SHOW TRIGGERS FROM `' . TEST_DB_NAME . '`;'
    ) . "\n");
}

function kill_tree(int $pid): void {
    $cmd = 'powershell -NoProfile -Command '
        . escapeshellarg(
            '$p = Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -eq ' . $pid
            . ' -or $_.ParentProcessId -eq ' . $pid . ' }; '
            . 'foreach ($x in $p) { if ($x.Name -match "httpd|mysqld|chrome") { continue } '
            . 'Stop-Process -Id $x.ProcessId -Force -ErrorAction SilentlyContinue }'
        );
    exec($cmd);
}

$host = '127.0.0.1';
assert_local_host($host);
assert_test_db(TEST_DB_NAME);

if (!is_file(PHP_BIN) || !is_file(MYSQL_BIN)) {
    fail('PHP o MySQL de XAMPP no encontrados.');
}

$repo = dirname(__DIR__);
$runId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
$copyDir = 'D:\\xampp\\tmp\\cyberleo-automated-tests\\s04-' . $runId;
if (!mkdir($copyDir, 0700, true) && !is_dir($copyDir)) {
    fail('No se pudo crear la copia temporal.');
}
file_put_contents($copyDir . DIRECTORY_SEPARATOR . MARKER_NAME, $runId . PHP_EOL);
$workDir = $copyDir . DIRECTORY_SEPARATOR . 'work';
mkdir($workDir, 0700, true);

$includeCopy = $copyDir . DIRECTORY_SEPARATOR . 'includes';
$testsCopy = $copyDir . DIRECTORY_SEPARATOR . 'tests';
mkdir($includeCopy, 0700, true);
mkdir($testsCopy, 0700, true);
foreach (['images.php', 'theme.php', 'home_content.php', 'catalog_display.php', 'checkout_display.php'] as $file) {
    $src = $repo . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . $file;
    if (!is_file($src) || !copy($src, $includeCopy . DIRECTORY_SEPARATOR . $file)) {
        fail('No se pudo copiar includes/' . $file);
    }
}
$srcTest = $repo . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'image_upload_settings_cases.php';
if (!is_file($srcTest)) {
    $srcTest = $repo . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'image_upload_settings_test.php';
}
if (!copy($srcTest, $testsCopy . DIRECTORY_SEPARATOR . 'image_upload_settings_test.php')) {
    fail('No se pudo copiar el test.');
}

echo "Copia temporal marcada: {$copyDir}\n";
echo "Caso: S-04\n";

mysql_exec(
    'CREATE DATABASE IF NOT EXISTS `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);

$schema = $repo . DIRECTORY_SEPARATOR . 'schema.sql';
if (is_file($schema)) {
    $load = escapeshellarg(MYSQL_BIN)
        . ' --host=127.0.0.1 --protocol=TCP --user=root '
        . escapeshellarg(TEST_DB_NAME)
        . ' < ' . escapeshellarg($schema);
    $code = 0;
    system($load, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Aviso: recarga de schema.sql codigo {$code}; se continua con la base existente.\n");
    }
}

$stdout = $copyDir . DIRECTORY_SEPARATOR . 'stdout.txt';
$stderr = $copyDir . DIRECTORY_SEPARATOR . 'stderr.txt';
$timeoutMs = 20000;
$cases = getenv('TEST_UPLOAD_CASE') ?: 'S-04';

$ps = sprintf(
    '$env:TEST_DSN="mysql:host=127.0.0.1;port=3306;dbname=%s;charset=utf8mb4"; '
    . '$env:DB_USER="root"; $env:DB_PASS=""; $env:TEST_WORK_DIR=%s; '
    . '$env:TEST_UPLOAD_CASE=%s; $env:TEST_UPLOAD_TRACE="1"; '
    . '$p = Start-Process -FilePath %s -ArgumentList %s -WorkingDirectory %s '
    . '-RedirectStandardOutput %s -RedirectStandardError %s -NoNewWindow -PassThru; '
    . '$ok = $p.WaitForExit(%d); '
    . 'if (-not $ok) { Write-Output ("CHILD_PID=" + $p.Id); Write-Output "TIMEOUT_ALIVE"; exit 99 } '
    . '$p.Refresh(); Write-Output ("CHILD_PID=" + $p.Id); Write-Output ("EXIT=" + $p.ExitCode);',
    TEST_DB_NAME,
    escapeshellarg($workDir),
    escapeshellarg($cases),
    escapeshellarg(PHP_BIN),
    escapeshellarg($testsCopy . DIRECTORY_SEPARATOR . 'image_upload_settings_test.php'),
    escapeshellarg($copyDir),
    escapeshellarg($stdout),
    escapeshellarg($stderr),
    $timeoutMs
);

$psOut = [];
$psCode = 0;
exec('powershell -NoProfile -Command ' . escapeshellarg($ps), $psOut, $psCode);
echo implode("\n", $psOut) . "\n";

$childPid = 0;
foreach ($psOut as $line) {
    if (preg_match('/^CHILD_PID=(\d+)$/', $line, $m)) {
        $childPid = (int) $m[1];
    }
}

$timedOut = in_array('TIMEOUT_ALIVE', $psOut, true) || $psCode === 99;
if ($timedOut) {
    fwrite(STDERR, "Timeout: el hijo sigue vivo. Snapshot MySQL antes de matar.\n");
    snapshot_mysql('while-child-alive');
    if ($childPid > 0) {
        kill_tree($childPid);
        fwrite(STDERR, "Detenido PID {$childPid} y sus hijos PHP.\n");
    }
}

echo "---- STDOUT ----\n";
echo is_file($stdout) ? (string) file_get_contents($stdout) : "(vacio)\n";
echo "---- STDERR ----\n";
echo is_file($stderr) ? (string) file_get_contents($stderr) : "(vacio)\n";

$left = mysql_exec(
    "SELECT COUNT(*) FROM information_schema.processlist WHERE DB='" . TEST_DB_NAME . "'"
);
echo "Conexiones restantes a " . TEST_DB_NAME . ": {$left}\n";

if ($timedOut) {
    fwrite(STDERR, "S-04 TIMEOUT. Traza conservada en {$copyDir}\n");
    exit(2);
}

$exitLine = '';
foreach ($psOut as $line) {
    if (str_starts_with($line, 'EXIT=')) {
        $exitLine = $line;
    }
}
$childExit = $exitLine === 'EXIT=' ? 0 : (int) substr($exitLine, 5);
if ($childExit !== 0) {
    fwrite(STDERR, "S-04 fallo con codigo {$childExit}\n");
    exit($childExit > 0 ? $childExit : 1);
}

echo "S-04 aislado OK\n";
exit(0);
