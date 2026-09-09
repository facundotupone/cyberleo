<?php
declare(strict_types=1);

/**
 * Allowlist and helpers for the canonical public storefront navigation.
 * Used by components/nav.php and the footer quick links.
 */

/**
 * @return list<array{id:string,label:string,href:string}>
 */
function public_nav_static_links(): array
{
    return [
        ['id' => 'home', 'label' => 'Inicio', 'href' => 'index.php'],
        ['id' => 'products', 'label' => 'Productos', 'href' => 'index.php#productos-destacados'],
        ['id' => 'offers', 'label' => 'Ofertas', 'href' => 'offers.php'],
        ['id' => 'cart', 'label' => 'Carrito', 'href' => 'cart.php'],
    ];
}

/**
 * Categories with nested subcategories (two queries total).
 *
 * @param list<array<string,mixed>> $categories
 * @return list<array{id:int,name:string,href:string,children:list<array{id:int,name:string,href:string}>}>
 */
function public_nav_taxonomy(array $categories): array
{
    $grouped = [];
    if (function_exists('get_subcategories')) {
        foreach (get_subcategories() as $sub) {
            $categoryId = (int) ($sub['category_id'] ?? 0);
            $subId = (int) ($sub['id'] ?? 0);
            $name = trim((string) ($sub['name'] ?? ''));
            if ($categoryId <= 0 || $subId <= 0 || $name === '') {
                continue;
            }
            $grouped[$categoryId][] = [
                'id' => $subId,
                'name' => $name,
                'href' => 'category.php?id=' . $categoryId . '&sub=' . $subId,
            ];
        }
    }

    $tree = [];
    foreach ($categories as $category) {
        $id = (int) ($category['id'] ?? 0);
        $name = trim((string) ($category['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $tree[] = [
            'id' => $id,
            'name' => $name,
            'href' => 'category.php?id=' . $id,
            'children' => $grouped[$id] ?? [],
        ];
    }

    return $tree;
}

/**
 * Build the public primary navigation items.
 *
 * @param list<array<string,mixed>> $categories
 * @param list<array{id:int,name:string,href:string,children:list<array{id:int,name:string,href:string}>}>|null $taxonomy
 * @return list<array<string,mixed>>
 */
function public_nav_items(
    array $categories,
    string $currentScript,
    ?int $activeCategoryId = null,
    ?int $activeSubcategoryId = null,
    ?array $taxonomy = null
): array {
    if ($taxonomy === null) {
        $taxonomy = public_nav_taxonomy($categories);
    }

    $onCategory = $currentScript === 'category.php';
    $onOffers = $currentScript === 'offers.php';

    $items = [];
    $items[] = [
        'id' => 'home',
        'label' => 'Inicio',
        'href' => 'index.php',
        'current' => $currentScript === 'index.php',
        'type' => 'link',
    ];

    $items[] = [
        'id' => 'products',
        'label' => 'Productos',
        'href' => 'index.php#productos-destacados',
        'current' => $onCategory,
        'type' => 'products',
        'taxonomy' => $taxonomy,
        'activeCategoryId' => $onCategory ? $activeCategoryId : null,
        'activeSubcategoryId' => $onCategory ? $activeSubcategoryId : null,
    ];

    $items[] = [
        'id' => 'offers',
        'label' => 'Ofertas',
        'href' => 'offers.php',
        'current' => $onOffers,
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
 * Footer quick links: same allowlist, without expanding every category.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array{id:string,label:string,href:string,current:bool,type:string}>
 */
function public_nav_footer_items(array $items): array
{
    $footer = [];
    foreach ($items as $item) {
        $type = (string) ($item['type'] ?? 'link');
        if ($type === 'products') {
            $footer[] = [
                'id' => 'products',
                'label' => (string) ($item['label'] ?? 'Productos'),
                'href' => (string) ($item['href'] ?? 'index.php#productos-destacados'),
                'current' => !empty($item['current']),
                'type' => 'link',
            ];
            continue;
        }
        $footer[] = [
            'id' => (string) ($item['id'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'href' => (string) ($item['href'] ?? '#'),
            'current' => !empty($item['current']),
            'type' => $type === 'cart' ? 'cart' : 'link',
        ];
    }
    return $footer;
}
