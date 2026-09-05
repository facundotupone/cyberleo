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
        ['id' => 'cart', 'label' => 'Carrito', 'href' => 'cart.php'],
    ];
}

/**
 * Build the public primary navigation items (Inicio, categories, Carrito).
 *
 * @param list<array<string,mixed>> $categories
 * @return list<array{id:string,label:string,href:string,current:bool,type:string}>
 */
function public_nav_items(array $categories, string $currentScript, ?int $activeCategoryId = null): array
{
    $items = [];
    $items[] = [
        'id' => 'home',
        'label' => 'Inicio',
        'href' => 'index.php',
        'current' => $currentScript === 'index.php',
        'type' => 'link',
    ];

    foreach ($categories as $category) {
        $id = (int) ($category['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = trim((string) ($category['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $items[] = [
            'id' => 'category-' . $id,
            'label' => $name,
            'href' => 'category.php?id=' . $id,
            'current' => $currentScript === 'category.php' && $activeCategoryId === $id,
            'type' => 'link',
        ];
    }

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
 * Footer quick links reuse the same public allowlist (including Carrito).
 *
 * @param list<array{id:string,label:string,href:string,current:bool,type:string}> $items
 * @return list<array{id:string,label:string,href:string,current:bool,type:string}>
 */
function public_nav_footer_items(array $items): array
{
    return $items;
}
