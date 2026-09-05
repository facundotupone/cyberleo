<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/asset_version.php';

$passed = 0;
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
    $v1 = cyberleo_asset_version('assets/css/style.css');
    $v2 = cyberleo_asset_version('assets/css/style.css');
    av_ok(preg_match('/^[a-f0-9]{12}$/', $v1) === 1, 'AV-01', 'hash de 12 hex para style.css');
    av_ok($v1 === $v2, 'AV-02', 'versión cacheada estable');
    $url = cyberleo_asset_url('assets/css/style.css');
    av_ok(str_starts_with($url, 'assets/css/style.css?v='), 'AV-03', 'URL con ?v=');
    av_ok(!str_contains($url, 'filemtime'), 'AV-04', 'no usa filemtime en URL');
    $bg = cyberleo_asset_url('assets/css/backgrounds.css');
    av_ok(str_contains($bg, '?v='), 'AV-05', 'backgrounds versionado');
    $bad = cyberleo_asset_version('../etc/passwd');
    av_ok($bad === CYBERLEO_ASSET_VERSION_FALLBACK, 'AV-06', 'path traversal cae a fallback');
    echo "asset_version_test: $passed assertions OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
exit(0);
