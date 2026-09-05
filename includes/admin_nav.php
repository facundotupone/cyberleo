<?php
declare(strict_types=1);

/**
 * Canonical admin panel navigation allowlist.
 *
 * @return list<array{id:string,label:string,href:string,icon:string}>
 */
function admin_nav_items(): array
{
    return [
        ['id' => 'products', 'label' => 'Productos', 'href' => 'admin_products.php', 'icon' => 'bi-box-seam'],
        ['id' => 'categories', 'label' => 'Categorías', 'href' => 'admin_categories.php', 'icon' => 'bi-tags'],
        ['id' => 'orders', 'label' => 'Pedidos', 'href' => 'admin_orders.php', 'icon' => 'bi-receipt'],
        ['id' => 'settings', 'label' => 'Configuración', 'href' => 'admin_settings.php', 'icon' => 'bi-sliders'],
        ['id' => 'system', 'label' => 'Sistema', 'href' => 'admin_system.php', 'icon' => 'bi-heartbeat'],
    ];
}

/**
 * Map current admin script to nav id.
 */
function admin_nav_current_id(string $currentScript): ?string
{
    foreach (admin_nav_items() as $item) {
        if ($item['href'] === $currentScript) {
            return $item['id'];
        }
    }
    return null;
}
