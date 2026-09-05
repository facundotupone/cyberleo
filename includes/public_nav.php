<?php
declare(strict_types=1);

/**
 * Allowlist and helpers for the canonical public storefront navigation.
 * Used by components/nav.php and the footer quick links.
 */

require_once __DIR__ . '/catalog_taxonomy.php';

/**
 * @return list<array{id:string,label:string,href:string}>
 */
function public_nav_static_links(): array
{
    return [
        ['id' => 'home', 'label' => 'Inicio', 'href' => 'index.php'],
        ['id' => 'offers', 'label' => 'Ofertas', 'href' => 'offers.php'],
        ['id' => 'cart', 'label' => 'Carrito', 'href' => 'cart.php'],
    ];
}

/**
 * Build the public primary navigation items:
 * Inicio · Productos (dropdown) · Ofertas · Carrito
 *
 * @param list<array<string,mixed>> $categories
 * @param array<int|string,list<array<string,mixed>>> $subcategoriesByCategory
 * @return list<array<string,mixed>>
 */
function public_nav_items(
    array $categories,
    string $currentScript,
    ?int $activeCategoryId = null,
    array $subcategoriesByCategory = []
): array {
    $categories = catalog_taxonomy_sort_categories($categories);
    $items = [];
    $items[] = [
        'id' => 'home',
        'label' => 'Inicio',
        'href' => 'index.php',
        'current' => $currentScript === 'index.php',
        'type' => 'link',
    ];

    $productChildren = [];
    foreach ($categories as $category) {
        $id = (int) ($category['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = trim((string) ($category['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $subsRaw = $subcategoriesByCategory[$id] ?? $subcategoriesByCategory[(string) $id] ?? [];
        $subs = [];
        foreach (is_array($subsRaw) ? $subsRaw : [] as $sub) {
            $sid = (int) ($sub['id'] ?? 0);
            $sname = trim((string) ($sub['name'] ?? ''));
            if ($sid <= 0 || $sname === '') {
                continue;
            }
            $subs[] = [
                'id' => 'sub-' . $sid,
                'label' => $sname,
                'href' => 'category.php?id=' . $id . '&sub=' . $sid,
                'current' => false,
            ];
        }
        // Skip empty category groups in the Products menu.
        if ($subs === []) {
            continue;
        }
        $productChildren[] = [
            'id' => 'category-' . $id,
            'label' => $name,
            'href' => 'category.php?id=' . $id,
            'icon' => catalog_taxonomy_icon_class((string) ($category['icon'] ?? '')),
            'current' => $currentScript === 'category.php' && $activeCategoryId === $id,
            'type' => 'category',
            'children' => $subs,
        ];
    }

    if ($productChildren !== []) {
        $items[] = [
            'id' => 'products',
            'label' => 'Productos',
            'href' => 'index.php#categorias',
            'current' => $currentScript === 'category.php',
            'type' => 'products_menu',
            'children' => $productChildren,
        ];
    }

    $items[] = [
        'id' => 'offers',
        'label' => 'Ofertas',
        'href' => 'offers.php',
        'current' => $currentScript === 'offers.php',
        'type' => 'link',
    ];

    $items[] = [
        'id' => 'cart',
        'label' => 'Carrito',
        'href' => 'cart.php',
        'current' => $currentScript === 'cart.php',
        'type' => 'cart',
    ];

    return $items;
}

/**
 * Resolve active category id from request context.
 * Prefer a page-resolved category id when category.php already computed it
 * (e.g. via product_id), so aria-current matches the rendered content.
 */
function public_nav_active_category_id(string $currentScript, array $query, ?int $resolvedCategoryId = null): ?int
{
    if ($currentScript !== 'category.php') {
        return null;
    }
    if ($resolvedCategoryId !== null && $resolvedCategoryId > 0) {
        return $resolvedCategoryId;
    }
    if (!isset($query['id']) || !is_numeric($query['id'])) {
        return null;
    }
    $id = (int) $query['id'];
    return $id > 0 ? $id : null;
}

/**
 * Footer quick links: flatten Productos into category links + Ofertas/Inicio/Carrito.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array{id:string,label:string,href:string,current:bool,type:string}>
 */
function public_nav_footer_items(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $type = (string) ($item['type'] ?? 'link');
        if ($type === 'products_menu') {
            foreach ($item['children'] ?? [] as $child) {
                $out[] = [
                    'id' => (string) ($child['id'] ?? ''),
                    'label' => (string) ($child['label'] ?? ''),
                    'href' => (string) ($child['href'] ?? '#'),
                    'current' => !empty($child['current']),
                    'type' => 'link',
                ];
            }
            continue;
        }
        $out[] = [
            'id' => (string) ($item['id'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'href' => (string) ($item['href'] ?? '#'),
            'current' => !empty($item['current']),
            'type' => $type === 'cart' ? 'cart' : 'link',
        ];
    }
    return $out;
}

/**
 * Load subcategories grouped by category_id for navigation menus.
 *
 * @return array<int,list<array<string,mixed>>>
 */
function public_nav_subcategories_by_category(): array
{
    $grouped = [];
    foreach (get_subcategories() as $row) {
        $cid = (int) ($row['category_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $grouped[$cid][] = $row;
    }
    return $grouped;
}
