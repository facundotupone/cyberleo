<?php
declare(strict_types=1);

/**
 * Casos RLS-01..RLS-11 para el runner XAMPP (sin Bash).
 * RLS-04 se omite solo cuando Windows no puede crear un symlink real.
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$tester = $root . '/tests/release_integrity_test.php';
$work = sys_get_temp_dir() . '/cyberleo-rls-' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);

$passed = 0;
$skipped = 0;

function rls_rm(string $dir): void
{
    if (!is_dir($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) && !is_link($path) ? rls_rm($path) : @unlink($path);
    }
    @rmdir($dir);
}

function rls_check(string $id, int $expected, string $release, string $php, string $tester): void
{
    global $passed;
    $cmd = [$php, $tester, $release];
    $outFile = tempnam(sys_get_temp_dir(), 'rls-out');
    $errFile = tempnam(sys_get_temp_dir(), 'rls-err');
    $nul = 'NUL';
    $proc = proc_open($cmd, [
        0 => ['file', $nul, 'r'],
        1 => ['file', $outFile, 'w'],
        2 => ['file', $errFile, 'w'],
    ], $pipes);
    if (!is_resource($proc)) {
        @unlink($outFile);
        @unlink($errFile);
        throw new RuntimeException("$id: no se pudo iniciar release_integrity_test.php");
    }
    $code = proc_close($proc);
    $stdout = (string) @file_get_contents($outFile);
    $stderr = (string) @file_get_contents($errFile);
    @unlink($outFile);
    @unlink($errFile);
    if ($code !== $expected) {
        fwrite(STDERR, $stdout . $stderr);
        throw new RuntimeException("$id: esperado $expected, recibido $code");
    }
    $passed++;
    echo "$id PASS\n";
}

try {
    mkdir($work . '/01/release/assets', 0700, true);
    file_put_contents($work . '/01/release/assets/image.webp', 'image');
    file_put_contents($work . '/01/release/index.css', 'body{background:url("assets/image.webp")}');
    rls_check('RLS-01', 0, $work . '/01/release', $php, $tester);

    mkdir($work . '/02/release', 0700, true);
    file_put_contents($work . '/02/release/index.css', 'body{background:url("missing.webp")}');
    rls_check('RLS-02', 1, $work . '/02/release', $php, $tester);

    mkdir($work . '/03/release/css', 0700, true);
    file_put_contents($work . '/03/outside.webp', 'outside');
    file_put_contents($work . '/03/release/css/style.css', 'body{background:url("../../outside.webp")}');
    rls_check('RLS-03', 1, $work . '/03/release', $php, $tester);

    mkdir($work . '/04/release/assets', 0700, true);
    file_put_contents($work . '/04/outside.webp', 'outside');
    $rls04Link = $work . '/04/release/assets/image.webp';
    $created = @symlink($work . '/04/outside.webp', $rls04Link);
    $isLink = $created && is_link($rls04Link);
    if ($isLink) {
        file_put_contents($work . '/04/release/index.css', 'body{background:url("assets/image.webp")}');
        rls_check('RLS-04', 1, $work . '/04/release', $php, $tester);
    } elseif (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'Darwin') {
        fwrite(STDERR, 'RLS-04: no se pudo crear un symlink real en ' . PHP_OS_FAMILY . "\n");
        exit(1);
    } else {
        $skipped++;
        echo "SKIP RLS-04: symlinks no disponibles en este entorno\n";
    }

    mkdir($work . '/05/release/css', 0700, true);
    mkdir($work . '/05/release/assets', 0700, true);
    file_put_contents($work . '/05/release/assets/image.webp', 'image');
    file_put_contents($work . '/05/release/css/style.css', 'body{background:url("../assets/image.webp")}');
    rls_check('RLS-05', 0, $work . '/05/release', $php, $tester);

    mkdir($work . '/06/release', 0700, true);
    file_put_contents(
        $work . '/06/release/style.css',
        'a{background:url("https://example.test/a.png")}b{background:url("data:image/png;base64,AA==")}c{background:url("#gradient")}'
    );
    rls_check('RLS-06', 0, $work . '/06/release', $php, $tester);

    mkdir($work . '/07/release/components', 0700, true);
    file_put_contents($work . '/07/release/index.php', "<?php require 'components/announcement.php';\n");
    file_put_contents($work . '/07/release/components/announcement.php', "<?php\n");
    rls_check('RLS-07', 0, $work . '/07/release', $php, $tester);

    mkdir($work . '/08/release', 0700, true);
    file_put_contents($work . '/08/release/index.php', "<?php require 'components/announcement.php';\n");
    rls_check('RLS-08', 1, $work . '/08/release', $php, $tester);

    mkdir($work . '/09/release/components', 0700, true);
    file_put_contents($work . '/09/release/components/nav.php', "<?php require __DIR__ . '/announcement.php';\n");
    rls_check('RLS-09', 1, $work . '/09/release', $php, $tester);

    mkdir($work . '/10/release/components', 0700, true);
    file_put_contents($work . '/10/release/components/nav.php', "<?php require __DIR__ . '/announcement.php';\n");
    file_put_contents($work . '/10/release/components/announcement.php', "<?php\n");
    rls_check('RLS-10', 0, $work . '/10/release', $php, $tester);

    mkdir($work . '/11/release/components', 0700, true);
    file_put_contents(
        $work . '/11/release/index.php',
        "<?php\nrequire 'components/home_featured.php';\nrequire 'components/promo_banner.php';\nrequire 'components/home_categories.php';\nrequire 'components/benefits.php';\n"
    );
    file_put_contents($work . '/11/release/components/nav.php', "<?php require __DIR__ . '/announcement.php';\n");
    rls_check('RLS-11', 1, $work . '/11/release', $php, $tester);

    echo "Release integrity cases OK ($passed PASS, $skipped SKIP)\n";
} finally {
    rls_rm($work);
}
