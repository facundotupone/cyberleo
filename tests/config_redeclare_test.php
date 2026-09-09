<?php
declare(strict_types=1);

/**
 * config.php must tolerate being required via absolute and relative paths.
 */

$root = dirname(__DIR__);
$config = $root . '/includes/config.php';
$db = $root . '/includes/db.php';

$bytes = file_get_contents($config);
if ($bytes === false) {
    fwrite(STDERR, "missing config.php\n");
    exit(1);
}

$passed = 0;
function cok(bool $ok, string $id, string $text): void
{
    global $passed;
    if (!$ok) {
        throw new RuntimeException("$id FAIL - $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

cok(str_contains($bytes, 'function_exists(') && str_contains($bytes, 'config_value'), 'CFG-01', 'config_value protegido con function_exists');
cok(str_contains((string) file_get_contents($db), "__DIR__ . '/config.php'"), 'CFG-02', 'db.php incluye config con __DIR__');

$tmp = sys_get_temp_dir() . '/cyberleo-config-' . bin2hex(random_bytes(3));
mkdir($tmp . '/includes', 0777, true);
copy($config, $tmp . '/includes/config.php');
// Simulate Hostinger double-load: absolute then chdir+relative.
$runner = $tmp . '/runner.php';
file_put_contents($runner, <<<'PHP'
<?php
require_once __DIR__ . '/includes/config.php';
chdir(__DIR__ . '/includes');
require_once 'config.php'; // second path key
echo function_exists('config_value') ? "OK\n" : "NO\n";
PHP);

$proc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=1', $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
foreach ($pipes as $p) {
    fclose($p);
}
$code = proc_close($proc);
cok($code === 0 && str_contains((string) $stdout, 'OK'), 'CFG-03', 'doble include no redeclara config_value');
if ($code !== 0) {
    fwrite(STDERR, $stderr . $stdout);
}

$rm = static function (string $dir) use (&$rm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
        $p = $dir . '/' . $f;
        is_dir($p) ? $rm($p) : @unlink($p);
    }
    @rmdir($dir);
};
$rm($tmp);

echo "config_redeclare_test: $passed passed\n";
