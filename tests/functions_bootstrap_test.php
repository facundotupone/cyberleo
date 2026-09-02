<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/includes/functions.php';
$bytes = file_get_contents($path);
if ($bytes === false) {
    fwrite(STDERR, "No se pudo leer functions.php\n");
    exit(1);
}

$passed = 0;
function bok(bool $ok, string $id, string $text): void {
    global $passed;
    if (!$ok) {
        throw new RuntimeException("$id FAIL - $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

bok(str_starts_with($bytes, '<?php'), 'O-01', 'bytes iniciales exactos <?php');
bok(!str_starts_with($bytes, "\xEF\xBB\xBF"), 'O-02', 'ausencia de BOM UTF-8');
bok(isset($bytes[0]) && $bytes[0] === '<', 'O-03', 'primer byte es <');
bok(!preg_match('/\?>\s*$/', $bytes), 'O-04', 'sin cierre ?> final');

$tmp = sys_get_temp_dir() . '/cyberleo-out-' . bin2hex(random_bytes(4));
mkdir($tmp);
file_put_contents($tmp . '/db.php', "<?php\n\$pdo = null;\n");
copy($root . '/includes/theme.php', $tmp . '/theme.php');
$functions = file_get_contents($path);
$functions = str_replace("require_once 'db.php';", "require_once __DIR__ . '/db.php';", $functions);
file_put_contents($tmp . '/functions.php', $functions);

$runner = $tmp . '/runner.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
if (!defined('STORE_NAME')) define('STORE_NAME', 'OutTest');
if (!defined('WHATSAPP_NUMBER')) define('WHATSAPP_NUMBER', '5491100000000');
if (!defined('STORE_INSTAGRAM')) define('STORE_INSTAGRAM', '');
ob_start();
require __DIR__ . '/functions.php';
$out = ob_get_clean();
if ($out !== '') {
    fwrite(STDERR, 'unexpected output hex=' . bin2hex($out) . "\n");
    exit(2);
}
if (headers_sent($file, $line)) {
    fwrite(STDERR, "headers already sent at $file:$line\n");
    exit(3);
}
header('X-Functions-Bootstrap: 1');
echo "OK\n";
PHP);

$proc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=1', '-d', 'output_buffering=0', $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
$code = proc_close($proc);
bok($code === 0 && str_contains((string) $stdout, 'OK'), 'O-05', 'require sin salida y headers posibles');
if ($code !== 0) {
    fwrite(STDERR, $stderr . $stdout);
}

foreach (['includes/theme.php'] as $rel) {
    $b = file_get_contents($root . '/' . $rel);
    bok(is_string($b) && str_starts_with($b, '<?php'), 'O-06', "$rel comienza con <?php");
    bok(is_string($b) && !str_starts_with($b, "\xEF\xBB\xBF"), 'O-07', "$rel sin BOM");
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

echo "Functions bootstrap tests: $passed passed, 0 failed\n";
