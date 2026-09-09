# Deploy Hostinger — menú + 4 columnas

ZIP: `cyberleo-menu-subcategorias-grilla-4.zip`

1. Backup de los archivos listados en el ZIP.
2. Extraer sobre `public_html`.
3. No tocar `includes/config.local.php`, `create_order.php`, `schema.sql`, ni el logo.
4. Admin → Ajustes → Columnas = 4 / 4, o SQL `set-catalog-columns-4.sql`.
5. Purge LiteSpeed + Ctrl+F5.

Rollback: restaurar el backup de los mismos paths.
