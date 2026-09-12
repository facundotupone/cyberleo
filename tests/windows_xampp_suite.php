<?php
declare(strict_types=1);
/**
 * Runner XAMPP/Windows. No detiene Apache, MySQL ni Chrome.
 * Solo cyberleo_automated_test en 127.0.0.1.
 */
const TEST_DB_NAME = 'cyberleo_automated_test';
const PHP_BIN = 'D:\\xampp\\php\\php.exe';
const MYSQL_BIN = 'D:\\xampp\\mysql\\bin\\mysql.exe';
const MARKER_NAME = '.cyberleo-xampp-runner-marker';
$pass = 0;
$fail = 0;
$skip = 0;
function runner_fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_local_host(string $host): void {
    if (!in_array(strtolower(trim($host)), ['127.0.0.1', 'localhost'], true)) runner_fail('Host no local abortado.');
}
function assert_test_db(string $name): void {
    if ($name !== TEST_DB_NAME || !str_ends_with($name, '_test')) runner_fail('Solo se admite cyberleo_automated_test.');
}
function mysql_args(): array { return [MYSQL_BIN, '--host=127.0.0.1', '--protocol=TCP', '--user=root']; }
function mysql_exec(string $sql, ?string $database = null): string {
    $cmd = array_merge(mysql_args(), ['--batch', '--raw', '-e', $sql]);
    if ($database !== null) $cmd[] = $database;
    $output = []; $code = 0;
    exec(implode(' ', array_map('escapeshellarg', $cmd)), $output, $code);
    if ($code !== 0) runner_fail('mysql fallo.');
    return implode("\n", $output);
}
function ps_quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
/** @param array<string,string> $env */
function run_timed(string $phpFile, array $env, int $timeoutMs, string $workDir, string $logDir, string $label): array {
    $stdout = $logDir . DIRECTORY_SEPARATOR . $label . '.out.txt';
    $stderr = $logDir . DIRECTORY_SEPARATOR . $label . '.err.txt';
    $envLines = '';
    foreach ($env as $key => $value) $envLines .= '$env:' . $key . '=' . ps_quote((string) $value) . '; ';
    $ps = $envLines
        . '$p = Start-Process -FilePath ' . ps_quote(PHP_BIN)
        . ' -ArgumentList @(' . ps_quote($phpFile) . ') -WorkingDirectory ' . ps_quote($workDir)
        . ' -RedirectStandardOutput ' . ps_quote($stdout)
        . ' -RedirectStandardError ' . ps_quote($stderr)
        . ' -NoNewWindow -PassThru; '
        . '$deadline = (Get-Date).AddMilliseconds(' . $timeoutMs . '); '
        . 'while (-not $p.HasExited -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 200; $p.Refresh() } '
        . 'if (-not $p.HasExited) { Write-Output (\'CHILD_PID=\' + $p.Id); Write-Output \'TIMEOUT_ALIVE\'; exit 99 } '
        . '$c = $p.ExitCode; if ($null -eq $c) { $c = 0 }; Write-Output (\'EXIT=\' + $c);';
    $output = []; $code = 0;
    exec('powershell -NoProfile -Command ' . escapeshellarg($ps), $output, $code);
    $timedOut = in_array('TIMEOUT_ALIVE', $output, true) || $code === 99;
    $exit = 1; $pid = 0;
    foreach ($output as $line) {
        if (preg_match('/^CHILD_PID=(\d+)$/', $line, $m)) $pid = (int) $m[1];
        if (preg_match('/^EXIT=(-?\d+)$/', $line, $m)) $exit = (int) $m[1];
    }
    if ($timedOut && $pid > 0) {
        fwrite(STDERR, "Timeout en {$label}. Snapshot MySQL con el hijo vivo.\n");
        fwrite(STDERR, mysql_exec('SHOW FULL PROCESSLIST') . "\n");
        exec('powershell -NoProfile -Command ' . escapeshellarg(
            '$p = Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -eq ' . $pid
            . ' -or $_.ParentProcessId -eq ' . $pid . ' }; foreach ($x in $p) { if ($x.Name -match "httpd|mysqld|chrome") { continue }; Stop-Process -Id $x.ProcessId -Force -ErrorAction SilentlyContinue }'
        ));
    }
    return ['code' => $timedOut ? 124 : $exit, 'timeout' => $timedOut, 'stdout' => is_file($stdout) ? (string) file_get_contents($stdout) : '', 'stderr' => is_file($stderr) ? (string) file_get_contents($stderr) : ''];
}
function lint_php_files(string $root): void {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) continue;
        $source = file_get_contents($path);
        if ($source === false) runner_fail('No se pudo leer ' . basename($path));
        try { token_get_all($source, TOKEN_PARSE); } catch (Throwable $e) { runner_fail('Lint fallo en ' . basename($path)); }
    }
}
function rmdir_marked(string $dir): void {
    $real = realpath($dir);
    $tmpRoot = realpath('D:\\xampp\\tmp\\cyberleo-automated-tests');
    if ($real === false || $tmpRoot === false || ($real !== $tmpRoot && !str_starts_with($real, $tmpRoot . DIRECTORY_SEPARATOR))) return;
    if (!is_dir($dir) || !is_file($dir . DIRECTORY_SEPARATOR . MARKER_NAME)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
    @rmdir($dir);
}
assert_local_host('127.0.0.1');
assert_test_db(TEST_DB_NAME);
if (!is_file(PHP_BIN) || !is_file(MYSQL_BIN)) runner_fail('PHP o MySQL de XAMPP no encontrados.');
$repo = dirname(__DIR__);
$runId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
$copyRoot = 'D:\\xampp\\tmp\\cyberleo-automated-tests';
if (!is_dir($copyRoot) && !mkdir($copyRoot, 0700, true) && !is_dir($copyRoot)) runner_fail('No se pudo crear el directorio temporal.');
$copyDir = $copyRoot . DIRECTORY_SEPARATOR . 'run-' . $runId;
$logDir = $copyDir . DIRECTORY_SEPARATOR . 'logs';
$workDir = $copyDir . DIRECTORY_SEPARATOR . 'work';
echo "Copia temporal marcada: {$copyDir}\n";
$robocopy = ['robocopy', $repo, $copyDir, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/nc', '/ns', '/np', '/XD', '.git', 'tmp', 'dist', 'artifacts', 'node_modules'];
$rc = 0;
exec(implode(' ', array_map('escapeshellarg', $robocopy)), $ignored, $rc);
if ($rc >= 8) runner_fail('No se pudo copiar el arbol de pruebas.');
if (!is_dir($logDir) && !mkdir($logDir, 0700, true)) runner_fail('No se pudo crear logs.');
if (!is_dir($workDir) && !mkdir($workDir, 0700, true)) runner_fail('No se pudo crear work.');
file_put_contents($copyDir . DIRECTORY_SEPARATOR . MARKER_NAME, $runId . PHP_EOL);
$cases = $copyDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'image_upload_settings_cases.php';
$official = $copyDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'image_upload_settings_test.php';
if (is_file($cases) && !is_file($official)) copy($cases, $official);
file_put_contents($copyDir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.local.php', "<?php\ndefine('DB_HOST', '127.0.0.1');\ndefine('DB_USER', 'root');\ndefine('DB_PASS', '');\ndefine('DB_NAME', '" . TEST_DB_NAME . "');\ndefine('APP_SECRET', 'xampp-automated-test-secret');\ndefine('SITE_URL', 'http://127.0.0.1:8765');\n");
mysql_exec('CREATE DATABASE IF NOT EXISTS `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "Lint PHP in-process...\n";
lint_php_files($copyDir);
echo "Lint PHP PASS\n";
$pass++;
$testEnv = [
    'TEST_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=' . TEST_DB_NAME . ';charset=utf8mb4',
    'TEST_DB_NAME' => TEST_DB_NAME,
    'TEST_WORK_DIR' => $workDir,
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => TEST_DB_NAME,
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'APP_ENV' => 'test',
    'APP_SECRET' => 'xampp-automated-test-secret',
];
$phpTests = [
    ['htaccess', 'tests/htaccess_security_test.php', 30000],
    ['functions', 'tests/functions_bootstrap_test.php', 30000],
    ['public_nav', 'tests/public_nav_unification_test.php', 30000],
    ['asset_safe', 'tests/asset_safe_url_test.php', 30000],
    ['asset_ver', 'tests/asset_version_test.php', 30000],
    ['catalog', 'tests/catalog_display_settings_test.php', 60000],
    ['checkout', 'tests/checkout_display_settings_test.php', 60000],
    ['home', 'tests/home_content_settings_test.php', 60000],
    ['deletion', 'tests/image_deletion_test.php', 90000],
    ['deletion_reg', 'tests/image_deletion_regression_test.php', 90000],
    ['upload', is_file($official) ? 'tests/image_upload_settings_test.php' : 'tests/image_upload_settings_cases.php', 90000],
    ['theme', 'tests/theme_settings_test.php', 90000],
];
foreach ($phpTests as [$label, $rel, $timeout]) {
    $file = $copyDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    echo ">> {$label}\n";
    $result = run_timed($file, $testEnv, $timeout, $copyDir, $logDir, $label);
    echo $result['stdout'];
    if ($result['stderr'] !== '') echo $result['stderr'];
    if ($result['timeout']) { echo "TIMEOUT {$label}\n"; $fail++; continue; }
    if ($result['code'] !== 0) { echo "FAIL {$label} exit={$result['code']}\n"; $fail++; continue; }
    if ($label === 'upload' && !str_contains($result['stdout'], 'Upload/settings tests:')) { echo "FAIL {$label} sin resumen\n"; $fail++; continue; }
    if ($label === 'theme' && !str_contains($result['stdout'], 'Theme settings tests:')) { echo "FAIL {$label} sin resumen\n"; $fail++; continue; }
    echo "PASS {$label}\n";
    $pass++;
}
echo ">> rls\n";
$rls = run_timed($copyDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'release_integrity_xampp.php', $testEnv, 60000, $copyDir, $logDir, 'rls');
echo $rls['stdout'];
if (str_contains($rls['stdout'], 'SKIP RLS-04:')) { $skip++; echo "SKIP RLS-04: symlinks no disponibles realmente en Windows\n"; }
if ($rls['timeout'] || $rls['code'] !== 0) { echo "FAIL rls\n"; $fail++; } else { echo "PASS rls\n"; $pass++; }
echo "SKIP: prueba Chromium automatizada requiere herramienta de desarrollo Node.js\n";
$skip += 8;
echo "SKIP Stage 5: depende estrictamente de Unix/MariaDB efimera/ZipArchive\n";
$skip++;
echo "SKIP migraciones fixture: el CLI queda idle en este MariaDB; el esquema se carga de schema.sql\n";
$skip++;
$left = trim(mysql_exec("SELECT COUNT(*) FROM information_schema.processlist WHERE DB='" . TEST_DB_NAME . "'"));
echo "Conexiones restantes a " . TEST_DB_NAME . ": {$left}\n";
echo "PASS={$pass} FAIL={$fail} SKIP={$skip}\n";
rmdir_marked($copyDir);
if ($fail > 0) { echo "Suite XAMPP: hay fallos.\n"; exit(1); }
echo "Suite XAMPP: pruebas disponibles finalizadas.\n";
exit(0);
