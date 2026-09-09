<?php
declare(strict_types=1);

/**
 * Canonical CyberLeo catalog taxonomy (10 categories / 69 subcategories).
 * No schema changes — names and icons only. Used by public nav, home, seeder and SQL.
 */

/**
 * @return list<array{name:string,icon:string,legacy_names:list<string>,subcategories:list<string>}>
 */
function catalog_taxonomy_definition(): array
{
    return [
        [
            'name' => 'Notebooks y PC',
            'icon' => 'bi-laptop',
            'legacy_names' => ['Notebooks'],
            'subcategories' => [
                'Notebooks',
                'Computadoras',
                'Monitores',
            ],
        ],
        [
            'name' => 'Componentes y almacenamiento',
            'icon' => 'bi-cpu',
            'legacy_names' => ['Componentes'],
            'subcategories' => [
                'SSD y discos rígidos',
                'Discos externos',
                'Pendrives',
                'Tarjetas de memoria',
                'Fuentes ATX',
                'Refrigeración y pasta térmica',
                'Limpieza y mantenimiento',
            ],
        ],
        [
            'name' => 'Carga y energía',
            'icon' => 'bi-lightning-charge',
            'legacy_names' => [],
            'subcategories' => [
                'Cargadores para notebooks',
                'Cargadores de pared',
                'Estaciones de carga',
                'Cargadores para auto',
                'Power banks',
                'Protección eléctrica',
                'Pilas y cargadores',
            ],
        ],
        [
            'name' => 'Cables y conectividad',
            'icon' => 'bi-usb-plug',
            'legacy_names' => [],
            'subcategories' => [
                'Cables USB y USB-C',
                'Cables HDMI',
                'Cables DisplayPort',
                'Cables VGA y DVI',
                'Cables de red',
                'Cables de audio',
                'Adaptadores y convertidores',
                'Hubs USB y lectores de memoria',
                'Adaptadores Wi-Fi USB',
                'Routers, switches y extensores',
            ],
        ],
        [
            'name' => 'Audio',
            'icon' => 'bi-headphones',
            'legacy_names' => [],
            'subcategories' => [
                'Auriculares Bluetooth',
                'Auriculares con cable',
                'Parlantes Bluetooth',
                'Parlantes con cable',
                'Parlantes de 12 pulgadas',
                'Micrófonos y streaming',
            ],
        ],
        [
            'name' => 'Periféricos',
            'icon' => 'bi-keyboard',
            'legacy_names' => [],
            'subcategories' => [
                'Teclados',
                'Teclados numéricos',
                'Combos teclado y mouse',
                'Mouse',
                'Joysticks',
                'Mouse pads',
                'Lápices ópticos',
            ],
        ],
        [
            'name' => 'Gaming',
            'icon' => 'bi-controller',
            'legacy_names' => [],
            'subcategories' => [
                'Combos gamer',
                'Mouse pads gamer y RGB',
                'Consolas retro',
                'Sillas gamer',
                'Escritorios gamer',
                'Gabinetes gamer',
            ],
        ],
        [
            'name' => 'Impresión y oficina',
            'icon' => 'bi-printer',
            'legacy_names' => [],
            'subcategories' => [
                'Impresoras',
                'Cartuchos',
                'Tintas',
                'Tóner',
                'Resmas y papel',
                'Calculadoras',
                'CD y DVD',
            ],
        ],
        [
            'name' => 'Iluminación y multimedia',
            'icon' => 'bi-lightbulb',
            'legacy_names' => [],
            'subcategories' => [
                'Lámparas',
                'Proyectores astronauta',
                'Aros de luz',
                'Tiras LED',
                'TV Stick',
                'Controles universales',
                'Radios y teléfonos',
                'Timbres inalámbricos',
                'Punteros láser',
            ],
        ],
        [
            'name' => 'Soportes, fundas y movilidad',
            'icon' => 'bi-phone',
            'legacy_names' => [],
            'subcategories' => [
                'Soportes para celular',
                'Soportes para tablet',
                'Bases para notebook',
                'Soportes para TV',
                'Fundas para tablet',
                'Mochilas',
                'Maletines',
            ],
        ],
    ];
}

function catalog_taxonomy_expected_category_count(): int
{
    return count(catalog_taxonomy_definition());
}

function catalog_taxonomy_expected_subcategory_count(): int
{
    $n = 0;
    foreach (catalog_taxonomy_definition() as $cat) {
        $n += count($cat['subcategories']);
    }
    return $n;
}

/**
 * @return list<string>
 */
function catalog_taxonomy_brand_tokens(): array
{
    return [
        'jbl', 'soul', 'genius', 'sandisk', 'kingston', 'tp-link', 'tplink',
        'hp', 'epson', 'canon', 'logitech', 'samsung', 'xiaomi', 'apple',
    ];
}

/**
 * Normalize stored icon to a Bootstrap Icons class list ("bi bi-laptop").
 */
function catalog_taxonomy_icon_class(?string $icon): string
{
    $raw = trim((string) $icon);
    if ($raw === '') {
        return 'bi bi-cpu';
    }
    $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    if (preg_match('/^bi\s+bi-[a-z0-9-]+$/i', $raw) === 1) {
        return strtolower($raw);
    }
    if (preg_match('/^bi-[a-z0-9-]+$/i', $raw) === 1) {
        return 'bi ' . strtolower($raw);
    }
    return 'bi bi-cpu';
}

/**
 * Bare icon token for DB storage ("bi-laptop").
 */
function catalog_taxonomy_icon_token(?string $icon): string
{
    $class = catalog_taxonomy_icon_class($icon);
    if (preg_match('/\bbi-([a-z0-9-]+)\b/i', $class, $m) === 1) {
        return 'bi-' . strtolower($m[1]);
    }
    return 'bi-cpu';
}

/**
 * @param list<array<string,mixed>> $categories
 * @return list<array<string,mixed>>
 */
function catalog_taxonomy_sort_categories(array $categories): array
{
    $order = [];
    foreach (catalog_taxonomy_definition() as $i => $def) {
        $order[mb_strtolower($def['name'])] = $i;
        foreach ($def['legacy_names'] as $legacy) {
            $order[mb_strtolower($legacy)] = $i;
        }
    }
    usort($categories, static function (array $a, array $b) use ($order): int {
        $na = mb_strtolower(trim((string) ($a['name'] ?? '')));
        $nb = mb_strtolower(trim((string) ($b['name'] ?? '')));
        $ia = $order[$na] ?? 1000;
        $ib = $order[$nb] ?? 1000;
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }
        return $na <=> $nb;
    });
    return $categories;
}

/**
 * Whether a product row qualifies as an offer (public offers page criteria).
 *
 * @param array<string,mixed> $product
 */
function catalog_product_is_offer(array $product): bool
{
    if ((int) ($product['is_active'] ?? 0) !== 1) {
        return false;
    }
    if (!array_key_exists('price_sale', $product) || $product['price_sale'] === null || $product['price_sale'] === '') {
        return false;
    }
    $sale = (float) $product['price_sale'];
    $price = (float) ($product['price'] ?? 0);
    return $sale > 0 && $sale < $price;
}

/**
 * SQL fragment (no leading AND) for offer products.
 */
function catalog_offers_sql_predicate(string $alias = 'p'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
    return "{$a}.is_active = 1"
        . " AND {$a}.price_sale IS NOT NULL"
        . " AND {$a}.price_sale > 0"
        . " AND {$a}.price_sale < {$a}.price";
}

/**
 * Suggest category/subcategory for a product without applying changes.
 * Brands are never suggested as categories.
 *
 * @param array<string,mixed> $product
 * @param list<array<string,mixed>> $categories keyed rows with id,name
 * @param list<array<string,mixed>> $subcategories rows with id,category_id,name
 * @return array{category:?string,subcategory:?string,motivo:string}
 */
function catalog_taxonomy_suggest_reclassify(
    array $product,
    array $categories,
    array $subcategories
): array {
    $hay = mb_strtolower(
        trim((string) ($product['name'] ?? '')) . ' ' . trim((string) ($product['description'] ?? ''))
    );
    $brands = catalog_taxonomy_brand_tokens();
    foreach ($brands as $brand) {
        // Strip brand tokens so they never drive category naming.
        $hay = str_replace($brand, ' ', $hay);
    }
    $hay = preg_replace('/\s+/', ' ', $hay) ?? $hay;

    $bestScore = 0;
    $bestSub = null;
    $bestCat = null;
    $motivo = 'Sin coincidencia clara; revisar manualmente';

    $catById = [];
    foreach ($categories as $cat) {
        $catById[(int) $cat['id']] = $cat;
    }

    foreach ($subcategories as $sub) {
        $subName = trim((string) ($sub['name'] ?? ''));
        if ($subName === '') {
            continue;
        }
        $needle = mb_strtolower($subName);
        $score = 0;
        if ($needle !== '' && str_contains($hay, $needle)) {
            $score = mb_strlen($needle) + 10;
        } else {
            foreach (preg_split('/\s+/u', $needle) ?: [] as $token) {
                if (mb_strlen($token) < 4) {
                    continue;
                }
                if (str_contains($hay, $token)) {
                    $score += mb_strlen($token);
                }
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestSub = $sub;
            $bestCat = $catById[(int) ($sub['category_id'] ?? 0)] ?? null;
            $motivo = 'Coincidencia por palabras de subcategoría canónica (no aplicada)';
        }
    }

    // Prefer canonical category name tokens if no subcategory matched well.
    if ($bestScore < 4) {
        foreach (catalog_taxonomy_definition() as $def) {
            $cname = mb_strtolower($def['name']);
            foreach (preg_split('/\s+/u', $cname) ?: [] as $token) {
                if (mb_strlen($token) < 5) {
                    continue;
                }
                if (str_contains($hay, $token)) {
                    return [
                        'category' => $def['name'],
                        'subcategory' => $def['subcategories'][0] ?? '',
                        'motivo' => 'Coincidencia débil por categoría canónica (no aplicada)',
                    ];
                }
            }
        }
    }

    return [
        'category' => $bestCat ? (string) $bestCat['name'] : null,
        'subcategory' => $bestSub ? (string) $bestSub['name'] : null,
        'motivo' => $bestSub ? $motivo : 'Sin coincidencia clara; revisar manualmente',
    ];
}
