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
    array $subcategoriesByCategory = [],
    ?int $activeSubcategoryId = null
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
    $selectedCategoryId = null;
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
            $isSubCurrent = $currentScript === 'category.php'
                && $activeSubcategoryId !== null
                && $activeSubcategoryId === $sid
                && $activeCategoryId === $id;
            $subs[] = [
                'id' => 'sub-' . $sid,
                'subcategory_id' => $sid,
                'label' => $sname,
                'href' => 'category.php?id=' . $id . '&sub=' . $sid,
                'current' => $isSubCurrent,
            ];
        }

        $isCategoryPage = $currentScript === 'category.php' && $activeCategoryId === $id;
        // aria-current only on the deepest match: subcategory XOR category "Ver todos".
        $categoryCurrent = $isCategoryPage && ($activeSubcategoryId === null || $activeSubcategoryId <= 0);

        if ($isCategoryPage) {
            $selectedCategoryId = $id;
        }

        $productChildren[] = [
            'id' => 'category-' . $id,
            'category_id' => $id,
            'label' => $name,
            'href' => 'category.php?id=' . $id,
            'icon' => catalog_taxonomy_icon_class((string) ($category['icon'] ?? '')),
            'current' => $categoryCurrent,
            'selected' => false,
            'type' => 'category',
            'children' => $subs,
        ];
    }

    if ($selectedCategoryId === null && $productChildren !== []) {
        $selectedCategoryId = (int) ($productChildren[0]['category_id'] ?? 0);
    }
    foreach ($productChildren as &$child) {
        $child['selected'] = ((int) ($child['category_id'] ?? 0)) === $selectedCategoryId;
    }
    unset($child);

    // Always expose Productos even if taxonomy failed to load (empty children).
    $items[] = [
        'id' => 'products',
        'label' => 'Productos',
        'href' => 'index.php#categorias',
        'current' => $currentScript === 'category.php',
        'type' => 'products_menu',
        'selected_category_id' => $selectedCategoryId,
        'children' => $productChildren,
    ];

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
 * Resolve active subcategory id from the public catalog query string.
 */
function public_nav_active_subcategory_id(string $currentScript, array $query, ?int $resolvedSubcategoryId = null): ?int
{
    if ($currentScript !== 'category.php') {
        return null;
    }
    if ($resolvedSubcategoryId !== null && $resolvedSubcategoryId > 0) {
        return $resolvedSubcategoryId;
    }
    if (!isset($query['sub']) || !is_numeric($query['sub'])) {
        return null;
    }
    $id = (int) $query['sub'];
    return $id > 0 ? $id : null;
}

/**
 * Footer quick links: compact Inicio · Productos · Ofertas · Carrito
 * (never expand the 10 categories or 69 subcategories).
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
            $out[] = [
                'id' => (string) ($item['id'] ?? 'products'),
                'label' => (string) ($item['label'] ?? 'Productos'),
                'href' => (string) ($item['href'] ?? 'index.php#categorias'),
                // Compact footer never competes with category/sub aria-current.
                'current' => false,
                'type' => 'link',
            ];
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
 * Single query via get_subcategories(); never N+1.
 *
 * @return array<int,list<array<string,mixed>>>
 */
function public_nav_subcategories_by_category(): array
{
    $grouped = [];
    try {
        if (!function_exists('get_subcategories')) {
            return $grouped;
        }
        foreach (get_subcategories() as $row) {
            $cid = (int) ($row['category_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $grouped[$cid][] = $row;
        }
    } catch (Throwable $e) {
        return [];
    }
    return $grouped;
}
