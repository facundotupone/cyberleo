<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/theme.php';
require_once dirname(__DIR__) . '/includes/home_content.php';
require_once dirname(__DIR__) . '/includes/images.php';

$pdo = new PDO(
    (string) getenv('TEST_DSN'),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$root = sys_get_temp_dir() . '/cyberleo-home-' . bin2hex(random_bytes(5));
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
$fakePng = $root . '/fake.png';
file_put_contents($fakePng, '<?php echo 1;');
$move = static fn($s, $d) => copy($s, $d);
$passed = 0;

function hok(bool $v, string $id, string $text): void {
    global $passed;
    if (!$v) {
        throw new RuntimeException("$id failed: $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

function hreset(PDO $pdo): void {
    $pdo->exec('DROP TRIGGER IF EXISTS home_fail');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0; TRUNCATE store_settings; TRUNCATE product_images; TRUNCATE products; TRUNCATE categories; SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO categories(id,name,icon) VALUES(1,'T','bi-cpu')");
}

function hset(PDO $pdo, string $k, string $v): void {
    $s = $pdo->prepare('INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute([$k, $v]);
}

function hget(PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key=?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}

function hurl(string $path): array {
    return ['name' => basename($path), 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => is_file($path) ? filesize($path) : 1];
}

function hrm(string $d): void {
    if (!is_dir($d)) {
        return;
    }
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = "$d/$f";
        is_dir($p) ? hrm($p) : @unlink($p);
    }
    @rmdir($d);
}

try {
    $defaults = home_content_default_settings();
    hok(
        $defaults['announcement_enabled'] === '0'
        && $defaults['promo_enabled'] === '0'
        && $defaults['benefits_enabled'] === '1'
        && $defaults['home_section_order'] === 'featured,promo,categories,benefits'
        && $defaults['promo_position'] === 'after_featured'
        && $defaults['footer_show_logo'] === '1',
        'HC-01',
        'defaults Stage 2'
    );

    $resolvedEmpty = resolve_home_content_settings([]);
    hok(
        $resolvedEmpty === $defaults
        || (
            $resolvedEmpty['announcement_enabled'] === '0'
            && $resolvedEmpty['promo_enabled'] === '0'
            && $resolvedEmpty['home_section_order'] === 'featured,promo,categories,benefits'
        ),
        'HC-02',
        'sin claves usa defaults'
    );

    hok(validate_home_content_setting('announcement_enabled', '1') === '1', 'HC-03', 'franja enabled');
    hok(validate_home_content_setting('announcement_enabled', '0') === '0', 'HC-04', 'franja disabled');

    $escaped = validate_home_content_setting('announcement_text', '<script>alert(1)</script>Promo');
    hok($escaped === 'alert(1)Promo' || $escaped === 'Promo' || (is_string($escaped) && !str_contains($escaped, '<')), 'HC-05', 'texto escapado sin HTML');

    hok(validate_home_content_setting('announcement_url', 'category.php?id=1') === 'category.php?id=1', 'HC-06', 'URL local válida');
    hok(validate_home_content_setting('announcement_url', 'https://evil.example/') === null, 'HC-07', 'URL externa rechazada');
    hok(validate_home_content_setting('announcement_url', 'javascript:alert(1)') === null, 'HC-07b', 'javascript rechazado');
    hok(validate_home_content_setting('announcement_url', '//evil.example/x') === null, 'HC-07c', 'protocol-relative rechazado');
    hok(validate_home_content_setting('announcement_url', '../etc/passwd') === null, 'HC-07d', 'traversal rechazado');

    hok(validate_home_content_setting('benefit_1_icon', 'bi-truck') === 'bi-truck', 'HC-08', 'ícono válido');
    hok(validate_home_content_setting('benefit_1_icon', 'bi-evil') === null, 'HC-09', 'ícono inválido');
    hok(validate_home_content_setting('benefit_1_icon', 'fa-truck') === null, 'HC-09b', 'clase arbitraria rechazada');

    hok(validate_home_section_order('featured,promo,categories,benefits') === 'featured,promo,categories,benefits', 'HC-10', 'orden válido');
    hok(validate_home_section_order('featured,featured,categories,benefits') === null, 'HC-11', 'orden duplicado rechazado');
    hok(validate_home_section_order('featured,promo,categories,evil') === null, 'HC-12', 'token desconocido rechazado');
    hok(build_home_section_order_from_ranks([
        'featured' => 1,
        'promo' => 1,
        'categories' => 3,
        'benefits' => 4,
    ]) === null, 'HC-11b', 'ranks duplicados rechazados');

    $badOrder = resolve_home_content_settings(['home_section_order' => 'dup,dup,dup,dup']);
    hok($badOrder['home_section_order'] === 'featured,promo,categories,benefits', 'HC-13', 'orden inválido en DB usa default sin reescribir');

    hok(validate_home_content_setting('promo_enabled', '1') === '1', 'HC-14', 'banner enabled');
    hok(validate_home_content_setting('promo_enabled', '0') === '0', 'HC-15', 'banner disabled');

    hreset($pdo);
    $beforeCount = count(glob($root . '/assets/images/settings/*') ?: []);
    $saved = save_settings_with_images(
        $pdo,
        array_merge(
            [
                'store_name' => 'Home Store',
                'whatsapp_number' => '5491100000000',
                'instagram_url' => '',
                'hero_title' => 'T',
                'hero_subtitle' => 'S',
            ],
            theme_default_settings(),
            home_content_default_settings(),
            [
                'promo_enabled' => '1',
                'promo_title' => 'Oferta',
                'promo_text' => 'Texto promo',
            ]
        ),
        ['promo_image' => hurl($fixture)],
        [],
        $root,
        $move
    );
    $promoPath = hget($pdo, 'promo_image');
    hok(
        is_string($promoPath) && is_safe_settings_image_path($promoPath) && is_file($root . '/' . $promoPath),
        'HC-16',
        'upload válido promo'
    );
    hok(count(glob($root . '/assets/images/settings/*') ?: []) === $beforeCount + 1, 'HC-16b', 'archivo creado');

    try {
        save_settings_with_images(
            $pdo,
            array_merge(theme_default_settings(), home_content_default_settings(), ['store_name' => 'X', 'whatsapp_number' => '5491100000000', 'hero_title' => 'T', 'hero_subtitle' => 'S']),
            ['promo_image' => hurl($fakePng)],
            [],
            $root,
            $move
        );
        hok(false, 'HC-17', 'MIME falso debió fallar');
    } catch (Throwable $e) {
        hok(true, 'HC-17', 'MIME falso rechazado');
        hok(!str_contains($e->getMessage(), $root) && !str_contains($e->getMessage(), 'PDO'), 'HC-17b', 'error interno no expone rutas');
    }

    $pdo->exec("CREATE TRIGGER home_fail BEFORE UPDATE ON store_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fail'");
    $promoBefore = hget($pdo, 'promo_image');
    $countBeforeFail = count(glob($root . '/assets/images/settings/*') ?: []);
    try {
        save_settings_with_images(
            $pdo,
            array_merge(theme_default_settings(), home_content_default_settings(), [
                'store_name' => 'Fail',
                'whatsapp_number' => '5491100000000',
                'hero_title' => 'T',
                'hero_subtitle' => 'S',
                'promo_title' => 'Nuevo',
            ]),
            ['promo_image' => hurl($fixture)],
            [],
            $root,
            $move
        );
        hok(false, 'HC-18', 'rollback debió fallar');
    } catch (Throwable $e) {
        $pdo->exec('DROP TRIGGER IF EXISTS home_fail');
        hok(hget($pdo, 'promo_image') === $promoBefore, 'HC-18', 'rollback conserva promo_image');
        hok(count(glob($root . '/assets/images/settings/*') ?: []) === $countBeforeFail, 'HC-18b', 'cleanup post-rollback');
    }
    $pdo->exec('DROP TRIGGER IF EXISTS home_fail');

    hset($pdo, 'body_background', $promoPath);
    $r = restore_home_content_defaults($pdo);
    foreach ($r['cleanup_candidates'] as $path) {
        delete_unreferenced_image($pdo, $path, $root);
    }
    hok(hget($pdo, 'promo_image') === '', 'HC-19', 'restore limpia promo_image en DB');
    hok(is_file($root . '/' . $promoPath), 'HC-19b', 'archivo compartido conservado');
    hok(hget($pdo, 'body_background') === $promoPath, 'HC-19c', 'body_background no tocado por restore Stage 2');

    hset($pdo, 'body_background', '');
    $r2 = restore_home_content_defaults($pdo);
    foreach ($r2['cleanup_candidates'] as $path) {
        delete_unreferenced_image($pdo, $path, $root);
    }
    hok(!is_file($root . '/' . $promoPath), 'HC-20', 'limpieza posterior al commit sin referencias');

    $r3 = restore_home_content_defaults($pdo);
    hok($r3['restored']['benefits_enabled'] === '1', 'HC-21', 'segunda restauración idempotente');

    hok(hash_file('sha256', $official) === $officialHash, 'HC-22', 'logo oficial intacto');

    $collectBad = collect_home_content_settings_from_post([
        'announcement_style' => 'primary',
        'announcement_text' => 'ok',
        'promo_button_text' => 'Ver más',
        'promo_button_url' => '#',
        'home_order_featured' => '1',
        'home_order_promo' => '2',
        'home_order_categories' => '2',
        'home_order_benefits' => '4',
        'benefit_1_icon' => 'bi-truck',
        'benefit_1_title' => 'A',
        'benefit_1_text' => 'B',
        'benefit_2_icon' => 'bi-shield-check',
        'benefit_2_title' => 'A',
        'benefit_2_text' => 'B',
        'benefit_3_icon' => 'bi-headset',
        'benefit_3_title' => 'A',
        'benefit_3_text' => 'B',
        'footer_description' => 'Desc',
        'footer_instagram_text' => 'IG',
        'footer_whatsapp_text' => 'WA',
        'evil_key' => '1',
    ]);
    hok(!empty($collectBad['errors']), 'HC-23', 'orden duplicado en POST rechazado');
    hok(!isset($collectBad['values']['evil_key']), 'HC-24', 'clave arbitraria no entra en values');

    $homeFooter = resolve_home_content_settings(array_merge($defaults, [
        'footer_show_instagram' => '1',
    ]));
    hok($homeFooter['footer_show_instagram'] === '1', 'HC-25', 'footer settings resueltos');

    $benefits = resolve_home_content_settings(array_merge($defaults, [
        'benefits_enabled' => '1',
        'benefit_2_icon' => 'not-allowed',
    ]));
    hok($benefits['benefit_2_icon'] === 'bi-shield-check', 'HC-26', 'ícono inválido en DB cae a default');

    echo "home_content_settings_test: $passed assertions OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    hrm($root);
    exit(1);
}
hrm($root);
exit(0);
