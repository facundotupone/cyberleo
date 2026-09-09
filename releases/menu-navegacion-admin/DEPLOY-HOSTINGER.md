# Deploy Hostinger — menú unificado, 4 columnas y carga segura de assets

Paquetes generados desde el árbol Git `b346005c76445c24ae9537cb9bc31b0dfd07b6ae` (`fix: preservar carga segura de assets y pruebas en Windows`). Extraer **sobre** `public_html` (rutas relativas, sin carpeta contenedora).

No se usó producción, no hay deploy y no hay merge. **Todavía no autorizar el deploy.**

## ZIP incremental — `cyberleo-menu-navegacion-admin.zip`

SHA-256: `559359db07853bec37413cd94680b6e6a7e891549d140be438e58653e2175a7a`

Solo archivos productivos del menú, catálogo de 4 columnas, helpers de assets y páginas que los cargan.

No incluye `create_order.php`, `schema.sql`, `config.local.php`, tests, SQL ni Node.

## ZIP completo — `cyberleo-hostinger-menu-unificado.zip`

SHA-256: `602ddd353210f3e796dca33311fa50dd28409dcaf48d6e6d54577c1f68280b01`

Código público completo con dependencias de `require`/`include`, helpers `asset_safe_url.php` / `asset_version.php`, `offers.php` y `assets/js/public-nav.js`.

Excluye: `config.local.php`, credenciales, `.git`, tests, backups, logs, SQL, base de datos, uploads de productos, temporales, Node/npm.

## Carga segura de CSS/JS

Los templates no hacen `require_once` obligatorio de helpers opcionales. Si falta `includes/asset_version.php`, el sitio sirve CSS/JS sin `?v=` y no responde HTTP 500.

El `style.css` de este paquete es el de la rama de menú (más nuevo que el hotfix `146aa83`). No se reemplazó por el CSS del ZIP de refinamiento.

## Pasos

1. Extraé el ZIP elegido en `public_html`.
2. Reiniciá PHP / OPcache.
3. Recorré Inicio, Productos (mega menú / acordeón), categoría, Ofertas, Carrito y el panel (Pedidos → Sistema).

## Cuatro columnas

Default de código: `featured_columns` y `catalog_columns` = `4`.
Responsive: 4 ≥1200px, 3 entre 992 y 1199, 2 entre 576 y 991, 1 por debajo de 576.
Si el administrador eligió 2 o 3, se respeta.
