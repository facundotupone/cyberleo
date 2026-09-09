# Deploy Hostinger — menú público, admin unificado y 4 columnas

Paquete incremental generado desde `git diff --name-only main...HEAD` (sin `tests/`). Extraer **sobre** `public_html`.

No incluye `create_order.php`, `schema.sql`, `config.local.php`, autenticación, carrito, logo ni migraciones.

## Archivos (lista Git)

- `admin_categories.php`
- `admin_orders.php`
- `admin_products.php`
- `admin_system.php`
- `assets/css/style.css`
- `assets/js/catalog-preview.js`
- `assets/js/public-nav.js`
- `category.php`
- `components/admin_nav.php`
- `components/footer.php`
- `components/home_featured.php`
- `components/nav.php`
- `includes/admin_nav.php`
- `includes/catalog_display.php`
- `includes/public_nav.php`
- `offers.php`

`admin_settings.php` no aparece en el diff: ya usaba `components/admin_nav.php` en `main`. El estilo unificado llega por `assets/css/style.css`.

## Pasos

1. Extraé el ZIP en `public_html`.
2. Reiniciá PHP / OPcache si el menú anterior sigue en caché.
3. Recorré Inicio, Productos, categoría, Ofertas, Carrito y el panel admin.

## Cuatro columnas

En **Configuración**: **Columnas destacados = 4** y **Columnas catálogo = 4**.

Si la base ya tenía 2 o 3, esa elección se respeta hasta cambiarla o restaurar la visualización de catálogo (el valor por defecto del código ahora es 4).
