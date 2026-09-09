<?php
declare(strict_types=1);

/**
 * Regression: asset versioning must never take down callers when the helper is
 * missing, unreadable, or incomplete.
 */

$passed = 0;
$projectRoot = dirname(__DIR__);
$helperPath = $projectRoot . '/includes/asset_version.php';
$helperBackup = $projectRoot . '/includes/asset_version.php.safe-test-bak';
$safePath = $projectRoot . '/includes/asset_safe_url.php';

function safe_ok(bool $v, string $id, string $text): void
{
    global $passed;
    if (!$v) {
        throw new RuntimeException("$id failed: $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

function run_php_script(string $cwd, string $scriptName, array $phpArgs = [], bool $includeStderr = true): string
{
    $cmd = array_merge([PHP_BINARY], $phpArgs, [$scriptName]);
    $proc = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('proc_open failed for ' . $scriptName);
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if ($includeStderr) {
        return (string) $out . (string) $err;
    }
    return (string) $out;
}

function restore_helper(string $helperPath, string $helperBackup): void
{
    if (is_file($helperBackup)) {
        if (is_file($helperPath)) {
            @chmod($helperPath, 0644);
        }
        copy($helperBackup, $helperPath);
        @chmod($helperPath, 0644);
        @unlink($helperBackup);
    } elseif (is_file($helperPath)) {
        @chmod($helperPath, 0644);
    }
}

register_shutdown_function(static function () use ($helperPath, $helperBackup): void {
    restore_helper($helperPath, $helperBackup);
});

/**
 * @return array{style:string,js:string,has_asset_url:bool}
 */
function run_safe_asset_scenario(string $projectRoot, string $helperPath, string $helperBackup, string $scenario): array
{
    if (is_file($helperBackup)) {
        copy($helperBackup, $helperPath);
        chmod($helperPath, 0644);
    }

    $script = sys_get_temp_dir() . '/cyberleo_safe_asset_' . $scenario . '_' . getmypid() . '.php';
    $php = <<<'PHP'
<?php
$root = %s;
$scenario = %s;
$helper = $root . '/includes/asset_version.php';
$localBak = $helper . '.child-bak';
chdir($root);

$cleanup = static function () use ($helper, $localBak, $scenario) {
    if (($scenario === 'missing' || $scenario === 'function-missing') && is_file($localBak)) {
        rename($localBak, $helper);
        chmod($helper, 0644);
    } elseif ($scenario === 'unreadable' && is_file($helper)) {
        chmod($helper, 0644);
    }
};
register_shutdown_function($cleanup);

if ($scenario === 'missing') {
    rename($helper, $localBak);
} elseif ($scenario === 'unreadable') {
    chmod($helper, 0000);
} elseif ($scenario === 'function-missing') {
    rename($helper, $localBak);
    file_put_contents($helper, "<?php\n// empty stub without cyberleo_asset_url\n");
}

require_once $root . '/includes/asset_safe_url.php';

echo json_encode([
    'style' => cyberleo_safe_asset_url('assets/css/style.css'),
    'js' => cyberleo_safe_asset_url('assets/js/catalog-cards.js'),
    'has_asset_url' => function_exists('cyberleo_asset_url'),
], JSON_THROW_ON_ERROR);
PHP;
    file_put_contents($script, sprintf($php, var_export($projectRoot, true), var_export($scenario, true)));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);

    if (is_file($helperBackup)) {
        copy($helperBackup, $helperPath);
        chmod($helperPath, 0644);
    }

    if (!is_string($out) || trim($out) === '') {
        throw new RuntimeException("empty output for scenario=$scenario");
    }
    $trim = trim($out);
    $jsonStart = strrpos($trim, '{');
    if ($jsonStart === false) {
        throw new RuntimeException("no json for scenario=$scenario: $trim");
    }
    return json_decode(substr($trim, $jsonStart), true, 512, JSON_THROW_ON_ERROR);
}

try {
    if (!is_file($helperPath) || !is_file($safePath)) {
        throw new RuntimeException('asset helpers missing in workspace');
    }
    copy($helperPath, $helperBackup);

    $ok = run_safe_asset_scenario($projectRoot, $helperPath, $helperBackup, 'ok');
    safe_ok(str_starts_with((string) $ok['style'], 'assets/css/style.css?v='), 'SAFE-01', 'helper OK versiona style.css');
    safe_ok(str_contains((string) $ok['js'], 'catalog-cards.js?v='), 'SAFE-02', 'helper OK versiona JS');

    $missing = run_safe_asset_scenario($projectRoot, $helperPath, $helperBackup, 'missing');
    safe_ok($missing['style'] === 'assets/css/style.css', 'SAFE-03', 'helper ausente → CSS sin ?v=');
    safe_ok($missing['js'] === 'assets/js/catalog-cards.js', 'SAFE-04', 'helper ausente → JS sin ?v=');
    safe_ok($missing['has_asset_url'] === false, 'SAFE-05', 'helper ausente no define cyberleo_asset_url');

    $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    if ($uid === 0 || PHP_OS_FAMILY === 'Windows') {
        safe_ok(true, 'SAFE-06', PHP_OS_FAMILY === 'Windows' ? 'ilegible omitido (Windows)' : 'ilegible omitido (proceso root)');
        safe_ok(true, 'SAFE-07', PHP_OS_FAMILY === 'Windows' ? 'ilegible omitido (Windows)' : 'ilegible omitido (proceso root)');
    } else {
        $unreadable = run_safe_asset_scenario($projectRoot, $helperPath, $helperBackup, 'unreadable');
        safe_ok($unreadable['style'] === 'assets/css/style.css', 'SAFE-06', 'helper ilegible → CSS sin ?v=');
        safe_ok($unreadable['js'] === 'assets/js/catalog-cards.js', 'SAFE-07', 'helper ilegible → JS sin ?v=');
    }

    $nofn = run_safe_asset_scenario($projectRoot, $helperPath, $helperBackup, 'function-missing');
    safe_ok($nofn['style'] === 'assets/css/style.css', 'SAFE-08', 'función ausente → CSS sin ?v=');
    safe_ok($nofn['js'] === 'assets/js/catalog-cards.js', 'SAFE-09', 'función ausente → JS sin ?v=');

    // Empty stub in an isolated directory (avoids opcache of the real helper).
    $iso = sys_get_temp_dir() . '/cyberleo_empty_stub_iso_' . getmypid();
    @mkdir($iso, 0755, true);
    file_put_contents($iso . '/asset_version.php', "<?php\n");
    copy($safePath, $iso . '/asset_safe_url.php');
    $stubScript = $iso . '/run.php';
    file_put_contents($stubScript, '<?php
require_once ' . var_export($iso . '/asset_safe_url.php', true) . ';
echo cyberleo_safe_asset_url("assets/css/style.css");
');
    $stubRaw = run_php_script($iso, 'run.php', ['-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0'], false);
    $stubOut = trim($stubRaw);
    safe_ok($stubOut === 'assets/css/style.css', 'SAFE-12', 'stub vacío de asset_version → CSS sin ?v= sin fatal');
    @unlink($stubScript);
    @unlink($iso . '/asset_safe_url.php');
    @unlink($iso . '/asset_version.php');
    @rmdir($iso);

    $probeDir = sys_get_temp_dir() . '/cyberleo_login_fatal_' . getmypid();
    @mkdir($probeDir . '/includes', 0755, true);
    file_put_contents($probeDir . '/probe.php', "<?php\nrequire_once 'includes/asset_version.php';\necho cyberleo_asset_url('assets/css/style.css');\n");
    file_put_contents($probeDir . '/includes/asset_version.php', "<?php\n");
    $fatalOut = run_php_script($probeDir, 'probe.php', ['-d', 'display_errors=1', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0']);
    safe_ok(str_contains($fatalOut, 'Call to undefined function cyberleo_asset_url()'), 'SAFE-10', 'fatal stub vacío reproducible sin secretos');
    @unlink($probeDir . '/probe.php');
    @unlink($probeDir . '/includes/asset_version.php');
    @rmdir($probeDir . '/includes');
    @rmdir($probeDir);

    require_once $safePath;
    $cssMissing = cyberleo_safe_asset_url('assets/css/does-not-exist-xyz.css');
    safe_ok(str_contains($cssMissing, 'assets/css/does-not-exist-xyz.css'), 'SAFE-11', 'CSS ausente no derriba resolver');

    restore_helper($helperPath, $helperBackup);
    echo "asset_safe_url_test: $passed assertions OK\n";
} catch (Throwable $e) {
    restore_helper($helperPath, $helperBackup);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
exit(0);
