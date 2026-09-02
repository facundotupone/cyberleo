<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/theme.php';
require_once dirname(__DIR__) . '/includes/home_content.php';
require_once dirname(__DIR__) . '/includes/catalog_display.php';
require_once dirname(__DIR__) . '/includes/images.php';

if (!function_exists('format_price')) {
    function format_price($price) {
        return '$' . number_format((float) $price, 2, ',', '.');
    }
}

$pdo = new PDO(
    (string) getenv('TEST_DSN'),
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$root = sys_get_temp_dir() . '/cyberleo-catalog-' . bin2hex(random_bytes(5));
mkdir($root . '/assets/images/products', 0700, true);
mkdir($root . '/assets/images/settings', 0700, true);
mkdir($root . '/assets/images/brand', 0700, true);
$official = $root . '/' . THEME_OFFICIAL_LOGO;
copy(dirname(__DIR__) . '/' . THEME_OFFICIAL_LOGO, $official);
$officialHash = '1c209559ea540fa864ba3e3bd17be1f6cdb823582389cd65224a2382849b456b';
$passed = 0;

function cdok(bool $v, string $id, string $text): void {
    global $passed;
    if (!$v) {
        throw new RuntimeException("$id failed: $text");
    }
    $passed++;
    echo "$id PASS - $text\n";
}

function cdreset(PDO $pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0; TRUNCATE store_settings; TRUNCATE product_images; TRUNCATE products; TRUNCATE categories; SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO categories(id,name,icon) VALUES(1,'Notebooks','bi-laptop')");
    $pdo->exec("INSERT INTO products(id,name,description,price,price_sale,stock,image,category_id,is_active,destacados) VALUES(1,'Prod','Desc larga',100,80,5,'',1,1,1)");
}

function cdset(PDO $pdo, string $k, string $v): void {
    $s = $pdo->prepare('INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $s->execute([$k, $v]);
}

function cdget(PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key=?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}

function cdrm(string $d): void {
    if (!is_dir($d)) {
        return;
    }
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        $p = "$d/$f";
        is_dir($p) ? cdrm($p) : @unlink($p);
    }
    @rmdir($d);
}

try {
    $defaults = catalog_display_default_settings();
    cdok(
        $defaults['featured_section_title'] === 'Productos Destacados'
        && $defaults['featured_empty_text'] === 'No hay productos destacados disponibles.'
        && $defaults['catalog_empty_text'] === 'No hay productos disponibles en esta categoría.'
        && $defaults['featured_columns'] === '3'
        && $defaults['catalog_columns'] === '3'
        && $defaults['product_card_style'] === 'elevated'
        && $defaults['product_image_fit'] === 'contain'
        && $defaults['product_image_height'] === 'normal'
        && $defaults['product_card_alignment'] === 'left'
        && $defaults['product_description_mode'] === 'expandable'
        && $defaults['product_description_length'] === '200'
        && $defaults['product_show_category_badge'] === '1'
        && $defaults['product_show_stock'] === '1'
        && $defaults['product_show_sale_badge'] === '1'
        && $defaults['product_show_old_price'] === '1'
        && $defaults['product_sale_badge_text'] === 'LIQUIDACIÓN'
        && $defaults['product_show_share_buttons'] === '1'
        && $defaults['product_share_whatsapp'] === '1'
        && $defaults['product_share_facebook'] === '1'
        && $defaults['product_share_copy'] === '1'
        && $defaults['product_add_button_text'] === 'Agregar al carrito'
        && $defaults['product_out_of_stock_text'] === 'Sin stock'
        && $defaults['catalog_show_breadcrumbs'] === '1'
        && $defaults['catalog_show_product_count'] === '1'
        && $defaults['catalog_show_subcategory_filter'] === '1',
        'CD-01',
        'defaults exactos'
    );

    $resolvedEmpty = resolve_catalog_display_settings([]);
    cdok($resolvedEmpty === $defaults, 'CD-02', 'sin filas DB usa defaults');

    cdok(validate_catalog_display_setting('product_show_stock', '1') === '1', 'CD-03', 'booleano 1');
    cdok(validate_catalog_display_setting('product_show_stock', '0') === '0', 'CD-03b', 'booleano 0');
    cdok(validate_catalog_display_setting('product_show_stock', 'maybe') === null, 'CD-04', 'booleano inválido rechazado');

    cdok(validate_catalog_display_setting('featured_columns', '2') === '2', 'CD-05', 'columnas 2');
    cdok(validate_catalog_display_setting('catalog_columns', '4') === '4', 'CD-05b', 'columnas 4');
    cdok(validate_catalog_display_setting('featured_columns', '5') === null, 'CD-06', 'columnas inválidas');
    cdok(validate_catalog_display_setting('catalog_columns', '1') === null, 'CD-06b', 'columnas 1 inválidas');

    cdok(validate_catalog_display_setting('product_image_fit', 'contain') === 'contain', 'CD-07', 'fit contain');
    cdok(validate_catalog_display_setting('product_image_fit', 'cover') === 'cover', 'CD-07b', 'fit cover');
    cdok(validate_catalog_display_setting('product_image_fit', 'fill') === null, 'CD-07c', 'fit inválido');

    cdok(validate_catalog_display_setting('product_image_height', 'compact') === 'compact', 'CD-08', 'altura compact');
    cdok(validate_catalog_display_setting('product_image_height', 'huge') === null, 'CD-08b', 'altura inválida');

    cdok(validate_catalog_display_setting('product_card_style', 'bordered') === 'bordered', 'CD-09', 'estilo bordered');
    cdok(validate_catalog_display_setting('product_card_style', 'glass') === null, 'CD-09b', 'estilo inválido');

    cdok(validate_catalog_display_setting('product_card_alignment', 'center') === 'center', 'CD-10', 'alineación center');
    cdok(validate_catalog_display_setting('product_card_alignment', 'right') === null, 'CD-10b', 'alineación inválida');

    cdok(validate_catalog_display_setting('product_description_mode', 'hidden') === 'hidden', 'CD-11', 'desc hidden');
    cdok(validate_catalog_display_setting('product_description_mode', 'full') === null, 'CD-11b', 'desc inválida');

    cdok(validate_catalog_display_setting('product_description_length', '160') === '160', 'CD-12', 'longitud 160');
    cdok(validate_catalog_display_setting('product_description_length', '250') === null, 'CD-12b', 'longitud cerrada');

    $san = validate_catalog_display_setting('featured_section_title', '<script>alert(1)</script>Ofertas');
    cdok(is_string($san) && !str_contains($san, '<') && !str_contains($san, 'script>'), 'CD-13', 'sanitización de textos');

    $long = str_repeat('á', 100);
    $cut = validate_catalog_display_setting('featured_section_title', $long);
    cdok($cut !== null && mb_strlen($cut) === 80, 'CD-14', 'límites multibyte');

    $collect = collect_catalog_display_settings_from_post([
        'featured_section_title' => 'Título',
        'featured_empty_text' => 'Vacío dest',
        'catalog_empty_text' => 'Vacío cat',
        'featured_columns' => '2',
        'catalog_columns' => '4',
        'product_card_style' => 'minimal',
        'product_image_fit' => 'cover',
        'product_image_height' => 'large',
        'product_card_alignment' => 'center',
        'product_description_mode' => 'compact',
        'product_description_length' => '100',
        'product_show_stock' => '1',
        'product_sale_badge_text' => 'OFERTA',
        'product_add_button_text' => 'Sumar',
        'product_out_of_stock_text' => 'Agotado',
        'evil_key' => 'hack',
        'brand_primary_color' => '#000000',
    ]);
    cdok(!isset($collect['values']['evil_key']), 'CD-15', 'clave arbitraria rechazada');
    cdok(!isset($collect['values']['brand_primary_color']), 'CD-15b', 'clave Stage1 no entra por collect catalog');

    $corrupt = resolve_catalog_display_settings([
        'featured_columns' => '99',
        'product_card_style' => 'neon',
        'product_show_stock' => 'yes',
    ]);
    cdok(
        $corrupt['featured_columns'] === '3'
        && $corrupt['product_card_style'] === 'elevated'
        && $corrupt['product_show_stock'] === '1',
        'CD-16',
        'valor corrupto en DB cae al default'
    );

    cdreset($pdo);
    cdset($pdo, 'brand_primary_color', '#112233');
    cdset($pdo, 'footer_description', 'Footer Stage2 keep');
    cdset($pdo, 'announcement_enabled', '1');
    cdset($pdo, 'whatsapp_number', '5491199999999');
    cdset($pdo, 'payment_methods', 'Efectivo, Mercado Pago');
    cdset($pdo, 'featured_columns', '4');
    cdset($pdo, 'product_card_style', 'minimal');
    cdset($pdo, 'product_add_button_text', 'Comprar ya');
    $stockBefore = (int) $pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn();
    $nameBefore = (string) $pdo->query('SELECT name FROM products WHERE id=1')->fetchColumn();

    $r1 = restore_catalog_display_defaults($pdo);
    cdok($r1['restored']['featured_columns'] === '3' && $r1['restored']['product_card_style'] === 'elevated', 'CD-17', 'restauración independiente');
    cdok(cdget($pdo, 'featured_columns') === '3', 'CD-17b', 'DB featured_columns default');
    cdok(cdget($pdo, 'product_add_button_text') === 'Agregar al carrito', 'CD-17c', 'DB botón default');

    $r2 = restore_catalog_display_defaults($pdo);
    cdok($r2['restored'] === $defaults, 'CD-18', 'restauración idempotente');

    cdok(cdget($pdo, 'brand_primary_color') === '#112233', 'CD-19', 'restauración conserva Etapa 1');
    cdok(cdget($pdo, 'footer_description') === 'Footer Stage2 keep', 'CD-20', 'restauración conserva Etapa 2');
    cdok(cdget($pdo, 'announcement_enabled') === '1', 'CD-20b', 'announcement Stage2 intacto');
    cdok(cdget($pdo, 'whatsapp_number') === '5491199999999', 'CD-21', 'conserva WhatsApp');
    cdok(cdget($pdo, 'payment_methods') === 'Efectivo, Mercado Pago', 'CD-21b', 'conserva pagos');

    $stockAfter = (int) $pdo->query('SELECT stock FROM products WHERE id=1')->fetchColumn();
    $nameAfter = (string) $pdo->query('SELECT name FROM products WHERE id=1')->fetchColumn();
    cdok($stockAfter === $stockBefore && $nameAfter === $nameBefore, 'CD-22', 'no modifica productos ni stock');

    $safePath = 'assets/images/products/' . str_repeat('a', 32) . '.jpg';
    $images = catalog_safe_product_images([
        $safePath,
        'https://evil.example/x.jpg',
        'javascript:alert(1)',
        'data:image/png;base64,xx',
        '../etc/passwd',
        'assets\\images\\products\\x.jpg',
        "assets/images/products/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg\" onerror=alert(1)",
        'assets/images/products/nothex.gif',
        'assets/images/settings/' . str_repeat('b', 32) . '.png',
    ]);
    cdok($images === [$safePath], 'CD-23', 'rutas inválidas descartadas');

    if (!defined('SITE_URL')) {
        define('SITE_URL', 'http://localhost');
    }
    $product = [
        'id' => 1,
        'name' => 'Sin foto',
        'description' => 'Desc',
        'price' => 10,
        'price_sale' => null,
        'stock' => 1,
        'category_id' => 1,
        'category_name' => 'Notebooks',
        'image' => 'https://evil.example/x.jpg',
    ];
    $images = ['javascript:alert(1)', '../x'];
    $catalogDisplay = catalog_display_default_settings();
    $cardContext = 'catalog';
    $categoryName = 'Notebooks';
    ob_start();
    require dirname(__DIR__) . '/components/product_card.php';
    $html = ob_get_clean();
    cdok(str_contains($html, 'Sin imagen') && !str_contains($html, 'javascript:') && !str_contains($html, 'evil.example'), 'CD-24', 'componente sin imagen usa placeholder');

    cdok(hash_file('sha256', dirname(__DIR__) . '/' . THEME_OFFICIAL_LOGO) === $officialHash, 'CD-25', 'logo oficial SHA intacto');
    cdok(hash_file('sha256', $official) === $officialHash, 'CD-25b', 'copia de logo intacta');

    echo "catalog_display_settings_test: $passed assertions OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    cdrm($root);
    exit(1);
}
cdrm($root);
exit(0);
