<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/asset_version.php';

$passed = 0;
$projectRoot = dirname(__DIR__);
$styleRel = 'assets/css/style.css';
$styleAbs = $projectRoot . '/' . $styleRel;

function av_ok(bool $v, string $id, string $text): void
{
    global $passed;
    if (!$v) {
        throw new RuntimeException("$id failed: $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

try {
    // Existing + memoization
    $v1 = cyberleo_asset_version($styleRel);
    $v2 = cyberleo_asset_version($styleRel);
    av_ok(preg_match('/^[a-f0-9]{12}$/', $v1) === 1, 'AV-01', 'hash de 12 hex para style.css');
    av_ok($v1 === $v2, 'AV-02', 'versión memoizada estable en el mismo request');
    $url = cyberleo_asset_url($styleRel);
    av_ok(str_starts_with($url, $styleRel . '?v='), 'AV-03', 'URL con ?v=');
    av_ok(preg_match('/\?v=[a-zA-Z0-9]+$/', $url) === 1, 'AV-04', 'query alfanumérico solamente');
    av_ok(!str_contains($url, $projectRoot), 'AV-05', 'URL no expone rutas internas');
    $bg = cyberleo_asset_url('assets/css/backgrounds.css');
    av_ok(str_contains($bg, 'assets/css/backgrounds.css?v='), 'AV-06', 'backgrounds versionado');

    // Rejected inputs → empty version (no fallback leak as "valid hash")
    av_ok(cyberleo_asset_version('../etc/passwd') === '', 'AV-07', 'traversal rechazado');
    av_ok(cyberleo_asset_version('assets/css/../../includes/config.php') === '', 'AV-08', 'traversal anidado rechazado');
    av_ok(cyberleo_asset_version($styleAbs) === '', 'AV-09', 'ruta absoluta rechazada');
    av_ok(cyberleo_asset_version('/etc/passwd') === '', 'AV-10', 'absoluta unix rechazada');
    av_ok(cyberleo_asset_version('https://cdn.example/a.css') === '', 'AV-11', 'https rechazado');
    av_ok(cyberleo_asset_version('http://cdn.example/a.css') === '', 'AV-12', 'http rechazado');
    av_ok(cyberleo_asset_version('//cdn.example/a.css') === '', 'AV-13', 'protocol-relative rechazado');
    av_ok(cyberleo_asset_version('includes/config.php') === '', 'AV-14', 'fuera de allowlist');
    av_ok(cyberleo_asset_version('assets/images/logo.png') === '', 'AV-15', 'png no versionable');
    av_ok(cyberleo_asset_version("assets/css/style.css\0.txt") === '', 'AV-16', 'null byte rechazado');

    // Missing / unreadable without warnings
    $warned = false;
    set_error_handler(static function () use (&$warned): bool {
        $warned = true;
        return true;
    });
    $missingVersion = cyberleo_asset_version('assets/css/does-not-exist-xyz.css');
    restore_error_handler();
    av_ok($missingVersion === '', 'AV-17', 'archivo inexistente → versión vacía');
    av_ok($warned === false, 'AV-18', 'inexistente no genera warning');

    $missingUrl = cyberleo_asset_url('assets/css/missing-never.css');
    av_ok($missingUrl === 'assets/css/missing-never.css?v=' . CYBERLEO_ASSET_VERSION_FALLBACK, 'AV-19', 'URL usa fallback alfanumérico');
    av_ok(preg_match('/^[a-zA-Z0-9]+$/', CYBERLEO_ASSET_VERSION_FALLBACK) === 1, 'AV-20', 'fallback solo alfanumérico');

    $tmpRel = 'assets/css/_tmp_unreadable_asset_version.css';
    $tmpAbs = $projectRoot . '/' . $tmpRel;
    file_put_contents($tmpAbs, ".tmp-unreadable{color:red}\n");
    chmod($tmpAbs, 0000);
    $warned = false;
    set_error_handler(static function () use (&$warned): bool {
        $warned = true;
        return true;
    });
    $unreadableVersion = cyberleo_asset_version($tmpRel);
    restore_error_handler();
    chmod($tmpAbs, 0644);
    @unlink($tmpAbs);
    // On some environments root can still read mode 0000; accept empty OR hashed.
    if (posix_geteuid() === 0) {
        av_ok(true, 'AV-21', 'ilegible omitido (proceso root)');
        av_ok($warned === false, 'AV-22', 'ilegible sin warning (root)');
    } else {
        av_ok($unreadableVersion === '', 'AV-21', 'archivo ilegible → versión vacía');
        av_ok($warned === false, 'AV-22', 'ilegible no genera warning');
    }

    // Content change alters version; same content keeps version
    $probe1 = 'assets/css/_tmp_asset_version_probe.css';
    $probe2 = 'assets/css/_tmp_asset_version_probe2.css';
    file_put_contents($projectRoot . '/' . $probe1, ".probe-a{color:#111}\n");
    $hash1 = hash_file('sha256', $projectRoot . '/' . $probe1);
    $pv1 = cyberleo_asset_version($probe1);
    av_ok($pv1 === substr((string) $hash1, 0, 12), 'AV-23', 'versión = prefijo sha256');
    av_ok($pv1 === cyberleo_asset_version($probe1), 'AV-24', 'mismo contenido conserva versión');

    file_put_contents($projectRoot . '/' . $probe2, ".probe-b{color:#222}\n");
    $hash2 = hash_file('sha256', $projectRoot . '/' . $probe2);
    $pv2 = cyberleo_asset_version($probe2);
    av_ok($pv2 === substr((string) $hash2, 0, 12), 'AV-25', 'contenido distinto → versión distinta');
    av_ok($pv1 !== $pv2, 'AV-26', 'hashes de contenido distintos');
    @unlink($projectRoot . '/' . $probe1);
    @unlink($projectRoot . '/' . $probe2);

    // Rejected URL placeholder does not leak path; HTML-safe
    $rejectedUrl = cyberleo_asset_url('../secret.css');
    av_ok(str_starts_with($rejectedUrl, 'assets/css/style.css?v='), 'AV-27', 'rechazado → placeholder seguro');
    $escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    av_ok($escaped === $url, 'AV-28', 'URL versionada segura en atributos HTML');

    // CDN assets are never passed through this helper in templates (spot-check constant)
    av_ok(!str_contains(CYBERLEO_ASSET_VERSION_FALLBACK, '-'), 'AV-29', 'fallback sin guiones');

    echo "asset_version_test: $passed assertions OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
exit(0);
