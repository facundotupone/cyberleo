<?php
declare(strict_types=1);

/**
 * Canonical admin panel navigation allowlist.
 *
 * @return list<array{id:string,label:string,href:string,icon:string,type:string}>
 */
function admin_nav_items(): array
{
    return [
        ['id' => 'orders', 'label' => 'Pedidos', 'href' => 'admin_orders.php', 'icon' => 'bi-receipt', 'type' => 'link'],
        ['id' => 'products', 'label' => 'Productos', 'href' => 'admin_products.php', 'icon' => 'bi-box-seam', 'type' => 'link'],
        ['id' => 'categories', 'label' => 'Categorías', 'href' => 'admin_categories.php', 'icon' => 'bi-tags', 'type' => 'link'],
        ['id' => 'settings', 'label' => 'Configuración', 'href' => 'admin_settings.php', 'icon' => 'bi-sliders', 'type' => 'link'],
        ['id' => 'system', 'label' => 'Sistema', 'href' => 'admin_system.php', 'icon' => 'bi-heartbeat', 'type' => 'link'],
        ['id' => 'logout', 'label' => 'Cerrar sesión', 'href' => 'logout.php', 'icon' => 'bi-box-arrow-right', 'type' => 'logout'],
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
