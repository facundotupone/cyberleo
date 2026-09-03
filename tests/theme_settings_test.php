<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/theme.php';
require_once dirname(__DIR__) . '/includes/images.php';

$pdo = new PDO(
    (string) getenv('TEST_DSN'),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$root = sys_get_temp_dir() . '/cyberleo-theme-' . bin2hex(random_bytes(5));
mkdir($root . '/assets/images/products', 0700, true);
mkdir($root . '/assets/images/settings', 0700, true);
mkdir($root . '/assets/images/brand', 0700, true);
$official = $root . '/' . THEME_OFFICIAL_LOGO;
copy(dirname(__DIR__) . '/' . THEME_OFFICIAL_LOGO, $official);
$officialHash = hash_file('sha256', $official);
$fixture = $root . '/pixel.png';
file_put_contents(
    $fixture,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
);
$svg = $root . '/x.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
$fakePng = $root . '/fake.png';
file_put_contents($fakePng, '<?php echo 1;');
$tooLarge = $root . '/large.png';
file_put_contents($tooLarge, str_repeat('A', MAX_IMAGE_BYTES + 1));
$move = static fn($s, $d) => copy($s, $d);
$passed = 0;

function tok(bool $v, string $id, string $text): void {
    global $passed;
    if (!$v) {
        throw new RuntimeException("$id failed: $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

function treset(PDO $pdo): void {
    $pdo->exec('DROP TRIGGER IF EXISTS theme_fail');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0; TRUNCATE store_settings; TRUNCATE product_images; TRUNCATE products; TRUNCATE categories; SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO categories(id,name,icon) VALUES(1,'T','bi-cpu')");
}

function tset(PDO $pdo, string $k, string $v): void {
    $s = $pdo->prepare('INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute([$k, $v]);
}

function tget(PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key=?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}

function turl(string $path): array {
    return ['name' => basename($path), 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => is_file($path) ? filesize($path) : 1];
}

function trm(string $d): void {
    if (!is_dir($d)) {
        return;
    }
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = "$d/$f";
        is_dir($p) ? trm($p) : @unlink($p);
    }
    @rmdir($d);
}

try {
    $defaults = theme_default_settings();
    tok($defaults['brand_primary_color'] === '#0057b8'
        && $defaults['brand_logo'] === THEME_OFFICIAL_LOGO
        && $defaults['nav_style'] === 'white'
        && $defaults['show_search'] === '1', 'T-01', 'valores predeterminados CyberLeo');

    $resolved = resolve_theme_settings([]);
    tok($resolved === $defaults, 'T-02', 'configuración ausente usa defaults');

    tok(validate_theme_setting('brand_primary_color', '#00AEEf') === '#00aeef', 'T-03', 'color válido normalizado');
    tok(validate_theme_setting('brand_primary_color', 'rgb(0,0,0)') === null, 'T-04', 'color RGB inválido');
    tok(validate_theme_setting('brand_primary_color', '#0057b8; } body{x:1') === null, 'T-05', 'inyección CSS rechazada');
    tok(validate_theme_setting('brand_primary_color', 'red') === null, 'T-05b', 'nombre de color rechazado');
    tok(validate_theme_setting('brand_primary_color', 'var(--x)') === null, 'T-05c', 'var() rechazado');

    tok(validate_theme_setting('nav_style', 'navy') === 'navy', 'T-06', 'opción select válida');
    tok(validate_theme_setting('nav_style', 'purple') === null, 'T-07', 'opción select inválida');
    tok(validate_theme_setting('show_search', '1') === '1', 'T-08', 'booleano válido');
    tok(validate_theme_setting('show_search', 'maybe') === null, 'T-09', 'booleano inválido');

    tok(is_safe_local_theme_url('index.php') && is_safe_local_theme_url('category.php?id=1'), 'T-10', 'URL local válida');
    tok(is_safe_local_theme_url('#productos-destacados'), 'T-11', 'fragmento local válido');
    tok(!is_safe_local_theme_url('https://evil.example/'), 'T-12', 'URL externa rechazada');
    tok(!is_safe_local_theme_url('javascript:alert(1)'), 'T-13', 'javascript: rechazado');
    tok(!is_safe_local_theme_url('data:text/html,x'), 'T-14', 'data: rechazado');
    tok(!is_safe_local_theme_url('../etc/passwd'), 'T-15', 'traversal rechazado');
    tok(!is_safe_local_theme_url("index.php\r\nX"), 'T-16', 'CR/LF rechazado');
    tok(!is_safe_local_theme_url('<script>x</script>'), 'T-17', 'HTML rechazado');

    treset($pdo);
    tset($pdo, 'whatsapp_number', '5491100000000');
    tset($pdo, 'instagram_url', 'https://instagram.com/cyberleo');
    tset($pdo, 'payment_methods', 'Efectivo, Transferencia');
    tset($pdo, 'store_name', 'Preserve Me');
    tset($pdo, 'brand_primary_color', '#112233');
    tset($pdo, 'nav_style', 'navy');
    $pdo->beginTransaction();
    $restore = restore_cyberleo_visual_identity($pdo);
    $pdo->commit();
    tok(tget($pdo, 'brand_primary_color') === '#0057b8'
        && tget($pdo, 'nav_style') === 'white'
        && tget($pdo, 'brand_logo') === THEME_OFFICIAL_LOGO
        && tget($pdo, 'whatsapp_number') === '5491100000000'
        && tget($pdo, 'instagram_url') === 'https://instagram.com/cyberleo'
        && tget($pdo, 'payment_methods') === 'Efectivo, Transferencia'
        && tget($pdo, 'store_name') === 'Preserve Me'
        && isset($restore['restored']['brand_primary_color']), 'T-18', 'restauración correcta sin tocar settings comerciales');

    treset($pdo);
    $logo = save_settings_with_images(
        $pdo,
        ['store_name' => 'Logo Store'],
        ['brand_logo' => turl($fixture)],
        [],
        $root,
        $move
    );
    tok(
        isset($logo['backgrounds']['brand_logo'])
        && is_safe_brand_logo_path($logo['backgrounds']['brand_logo'])
        && is_file($root . '/' . $logo['backgrounds']['brand_logo'])
        && hash_file('sha256', $official) === $officialHash,
        'T-19',
        'upload PNG válido; logo oficial intacto'
    );

    $rejectedMime = false;
    try {
        store_safe_image($fakePng, UPLOAD_ERR_OK, filesize($fakePng), 'assets/images/settings', $root, $move, null, ['image/png' => 'png']);
    } catch (Throwable) {
        $rejectedMime = true;
    }
    tok($rejectedMime, 'T-20', 'MIME falso rechazado');

    $rejectedSvg = false;
    try {
        store_safe_image($svg, UPLOAD_ERR_OK, filesize($svg), 'assets/images/settings', $root, $move, null, ['image/png' => 'png']);
    } catch (Throwable) {
        $rejectedSvg = true;
    }
    tok($rejectedSvg, 'T-21', 'SVG rechazado');

    $rejectedLarge = false;
    try {
        store_safe_image($tooLarge, UPLOAD_ERR_OK, filesize($tooLarge), 'assets/images/settings', $root, $move, null, ['image/png' => 'png']);
    } catch (Throwable) {
        $rejectedLarge = true;
    }
    tok($rejectedLarge, 'T-22', 'archivo demasiado grande rechazado');

    $customLogo = $logo['backgrounds']['brand_logo'];
    save_settings_with_images($pdo, ['store_name' => 'Logo Store'], [], ['brand_logo' => true], $root, $move);
    tok(
        tget($pdo, 'brand_logo') === THEME_OFFICIAL_LOGO
        && !is_file($root . '/' . $customLogo)
        && is_file($official)
        && hash_file('sha256', $official) === $officialHash,
        'T-23',
        'logo personalizado reemplazado tras commit; oficial no eliminado'
    );

    treset($pdo);
    $shared = 'assets/images/settings/' . str_repeat('a', 32) . '.png';
    file_put_contents($root . '/' . $shared, 'shared');
    tset($pdo, 'brand_logo', $shared);
    tset($pdo, 'brand_favicon', $shared);
    $rShared = save_settings_with_images(
        $pdo,
        ['store_name' => 'Shared'],
        ['brand_logo' => turl($fixture)],
        [],
        $root,
        $move
    );
    tok(
        tget($pdo, 'brand_favicon') === $shared
        && is_file($root . '/' . $shared)
        && ($rShared['cleanup'][$shared] ?? '') === 'still_referenced',
        'T-24',
        'archivo compartido conservado'
    );

    treset($pdo);
    $oldLogo = 'assets/images/settings/' . str_repeat('b', 32) . '.png';
    file_put_contents($root . '/' . $oldLogo, 'old');
    tset($pdo, 'brand_logo', $oldLogo);
    tset($pdo, 'store_name', 'Old');
    $pdo->exec("CREATE TRIGGER theme_fail BEFORE UPDATE ON store_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fail'");
    $newPath = null;
    $failed = false;
    try {
        save_settings_with_images(
            $pdo,
            ['store_name' => 'New'],
            ['brand_logo' => turl($fixture)],
            [],
            $root,
            static function ($s, $d) use (&$newPath) {
                $newPath = $d;
                return copy($s, $d);
            }
        );
    } catch (Throwable) {
        $failed = true;
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS theme_fail');
    }
    tok(
        $failed
        && tget($pdo, 'brand_logo') === $oldLogo
        && tget($pdo, 'store_name') === 'Old'
        && is_file($root . '/' . $oldLogo)
        && ($newPath === null || !file_exists($newPath)),
        'T-25',
        'error SQL con rollback'
    );

    $bad = resolve_theme_settings([
        'brand_primary_color' => '#0057b8;}body{background:url(x)',
        'nav_style' => 'neon',
        'brand_logo' => '../../etc/passwd',
        'hero_button_url' => 'javascript:alert(1)',
    ]);
    tok(
        $bad['brand_primary_color'] === '#0057b8'
        && $bad['nav_style'] === 'white'
        && $bad['brand_logo'] === THEME_OFFICIAL_LOGO
        && $bad['hero_button_url'] === '#productos-destacados',
        'T-26',
        'configuración inválida en DB usa default'
    );

    $css = theme_css_custom_properties($defaults);
    tok(
        !str_contains($css, '</')
        && !str_contains($css, 'url(')
        && !str_contains($css, 'expression(')
        && !str_contains($css, '@import')
        && !str_contains($css, '<script')
        && str_contains($css, '--brand-blue: #0057b8')
        && str_contains($css, '--button-radius: 8px')
        && preg_match('/^[:.#a-zA-Z0-9\s\-_",;{}\n(),]+$/', $css) === 1,
        'T-27',
        'salida CSS sin caracteres peligrosos'
    );

    $html = htmlspecialchars($defaults['hero_button_text'] . '"><script>', ENT_QUOTES, 'UTF-8');
    tok(
        !str_contains($html, '<script>')
        && str_contains($html, '&quot;'),
        'T-28',
        'salida HTML escapada'
    );

    $previewJs = file_get_contents(dirname(__DIR__) . '/assets/js/theme-preview.js');
    tok(
        is_string($previewJs)
        && !str_contains($previewJs, 'innerHTML')
        && !str_contains($previewJs, 'eval(')
        && !str_contains($previewJs, 'new Function')
        && !str_contains($previewJs, 'document.write')
        && str_contains($previewJs, 'textContent')
        && str_contains($previewJs, 'setAttribute'),
        'T-29',
        'vista previa sin sinks dinámicos'
    );

    $arbitrary = false;
    treset($pdo);
    save_settings_with_images($pdo, ['store_name' => 'OK', 'evil_key' => '1'], [], [], $root, $move);
    tok(tget($pdo, 'evil_key') === null && tget($pdo, 'store_name') === 'OK', 'T-30', 'clave arbitraria no se inserta');

    tok(theme_hex_to_rgb_channels('#0057b8') === '0, 87, 184', 'T-RGB-01', 'conversión #0057b8');
    tok(theme_hex_to_rgb_channels('#00aeef') === '0, 174, 239', 'T-RGB-02', 'conversión #00aeef');
    tok(theme_hex_to_rgb_channels('#071a33') === '7, 26, 51', 'T-RGB-03', 'conversión #071a33');
    tok(theme_hex_to_rgb_channels('#0057b8;}body{x') === null, 'T-RGB-04', 'color inválido rechazado antes de RGB');
    tok(theme_hex_to_rgb_channels('rgb(0,87,184)') === null, 'T-RGB-04b', 'RGB literal rechazado');

    $cssDefault = theme_css_custom_properties(theme_default_settings());
    tok(
        str_contains($cssDefault, '--brand-blue-rgb: 0, 87, 184')
        && str_contains($cssDefault, '--brand-cyan-rgb: 0, 174, 239')
        && str_contains($cssDefault, '--brand-navy-rgb: 7, 26, 51'),
        'T-RGB-05',
        'variables RGB predeterminadas correctas'
    );
    $cssAlt = theme_css_custom_properties(array_merge(theme_default_settings(), [
        'brand_primary_color' => '#7a1f1f',
        'brand_secondary_color' => '#c45c26',
        'brand_navy_color' => '#1b1030',
    ]));
    tok(
        str_contains($cssAlt, '--brand-blue-rgb: 122, 31, 31')
        && str_contains($cssAlt, '--brand-cyan-rgb: 196, 92, 38')
        && str_contains($cssAlt, '--brand-navy-rgb: 27, 16, 48')
        && !str_contains($cssAlt, '--brand-blue-rgb: 0, 87, 184'),
        'T-RGB-06',
        'variables RGB alternativas correctas'
    );

    $warnings = theme_contrast_warnings(array_merge($defaults, [
        'brand_text_color' => '#eeeeee',
        'brand_background_color' => '#ffffff',
    ]));
    tok($warnings !== [], 'T-31', 'advertencia de contraste bajo');

    // R-HERO-01: fondo exclusivo se limpia y elimina tras commit
    treset($pdo);
    $heroOnly = 'assets/images/settings/' . str_repeat('c', 32) . '.png';
    file_put_contents($root . '/' . $heroOnly, 'hero-only');
    tset($pdo, 'hero_background', $heroOnly);
    tset($pdo, 'hero_title', 'Título conservado');
    tset($pdo, 'hero_subtitle', 'Subtítulo conservado');
    tset($pdo, 'whatsapp_number', '5491100000000');
    $pdo->beginTransaction();
    $rHero = restore_cyberleo_visual_identity($pdo);
    $pdo->commit();
    foreach ($rHero['cleanup_candidates'] as $path) {
        delete_unreferenced_image($pdo, $path, $root);
    }
    tok(
        tget($pdo, 'hero_background') === ''
        && !is_file($root . '/' . $heroOnly)
        && in_array($heroOnly, $rHero['cleanup_candidates'], true),
        'R-HERO-01',
        'fondo personalizado exclusivo eliminado tras commit'
    );

    // R-HERO-02: archivo compartido con body_background se conserva
    treset($pdo);
    $sharedHero = 'assets/images/settings/' . str_repeat('d', 32) . '.png';
    file_put_contents($root . '/' . $sharedHero, 'shared-hero');
    tset($pdo, 'hero_background', $sharedHero);
    tset($pdo, 'body_background', $sharedHero);
    $pdo->beginTransaction();
    $rSharedHero = restore_cyberleo_visual_identity($pdo);
    $pdo->commit();
    foreach ($rSharedHero['cleanup_candidates'] as $path) {
        delete_unreferenced_image($pdo, $path, $root);
    }
    tok(
        tget($pdo, 'hero_background') === ''
        && tget($pdo, 'body_background') === $sharedHero
        && is_file($root . '/' . $sharedHero),
        'R-HERO-02',
        'archivo compartido conservado tras limpiar hero_background'
    );

    // R-HERO-03: fallo SQL → rollback conserva valor y archivo
    treset($pdo);
    $heroFail = 'assets/images/settings/' . str_repeat('e', 32) . '.png';
    file_put_contents($root . '/' . $heroFail, 'hero-fail');
    tset($pdo, 'hero_background', $heroFail);
    $pdo->exec("CREATE TRIGGER theme_fail BEFORE UPDATE ON store_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fail'");
    $failedHero = false;
    try {
        $pdo->beginTransaction();
        restore_cyberleo_visual_identity($pdo);
        $pdo->commit();
    } catch (Throwable) {
        $failedHero = true;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS theme_fail');
    }
    tok(
        $failedHero
        && tget($pdo, 'hero_background') === $heroFail
        && is_file($root . '/' . $heroFail),
        'R-HERO-03',
        'error SQL con rollback conserva hero_background'
    );

    // R-HERO-04: segunda restauración idempotente
    treset($pdo);
    tset($pdo, 'hero_background', '');
    $pdo->beginTransaction();
    restore_cyberleo_visual_identity($pdo);
    $pdo->commit();
    $pdo->beginTransaction();
    $rTwice = restore_cyberleo_visual_identity($pdo);
    $pdo->commit();
    foreach ($rTwice['cleanup_candidates'] as $path) {
        delete_unreferenced_image($pdo, $path, $root);
    }
    tok(
        tget($pdo, 'hero_background') === ''
        && tget($pdo, 'nav_style') === 'white'
        && $rTwice['cleanup_candidates'] === [],
        'R-HERO-04',
        'segunda restauración idempotente'
    );

    // R-HERO-05: conserva título y subtítulo
    treset($pdo);
    tset($pdo, 'hero_title', 'Mi título custom');
    tset($pdo, 'hero_subtitle', 'Mi subtítulo custom');
    tset($pdo, 'hero_background', $heroFail);
    file_put_contents($root . '/' . $heroFail, 'x');
    $pdo->beginTransaction();
    restore_cyberleo_visual_identity($pdo);
    $pdo->commit();
    tok(
        tget($pdo, 'hero_title') === 'Mi título custom'
        && tget($pdo, 'hero_subtitle') === 'Mi subtítulo custom'
        && tget($pdo, 'hero_background') === '',
        'R-HERO-05',
        'hero_title y hero_subtitle se conservan'
    );

    echo "Theme settings tests: $passed passed, 0 failed\n";
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS theme_fail');
    trm($root);
}
