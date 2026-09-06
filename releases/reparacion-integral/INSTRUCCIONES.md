# Recuperación TOTAL — index + login HTTP 500

## Importante
El ZIP mínimo **no alcanzó** porque el hosting quedó en estado parcial + posible OPcache.
Este paquete es el **árbol público completo** (56 archivos), igual a la reparación integral.

## Causa
`asset_version.php` ausente/vacío + código que llama `cyberleo_asset_url()` → fatal → HTTP 500.
También puede persistir el 500 si PHP/OPcache sigue sirviendo bytecode viejo.

## Pasos exactos
1. Backup de `public_html` (y no borres `config.local.php`).
2. Subí `cyberleo-recuperacion-login.zip` y extraélo **directo sobre** `public_html` (sobrescribir todo).
3. Confirmá que **no** quedó `public_html/alguna-carpeta/index.php`.
4. En hPanel Hostinger:
   - **Reiniciar PHP** / OPcache (o "Restart PHP").
   - LiteSpeed Cache → **Purge All**.
5. Abrí en ventana privada:
   - `/diag_recovery.php` → debe mostrar `cyberleo_asset_url=1` y `size` > 1000 para `asset_version.php`
   - `/admin_login.php` → HTTP 200
   - `/` → HTTP 200
6. Borrá `diag_recovery.php` cuando esté estable.

SHA-256: `b6f59dd5af6ab38920bc994a83287579ba175eefb9b611a7625921179ef6bf82`
