# Recuperación total — index + login HTTP 500

## Importante
Si el ZIP anterior **no alcanzó**, usá **este** (SHA abajo). El PR viejo apuntaba a un SHA distinto.

Este paquete es el **árbol público completo** (~57 archivos), igual a la reparación integral.

## Causa típica
1. `asset_version.php` ausente/vacío + código viejo que llama `cyberleo_asset_url()` → fatal → HTTP 500
2. OPcache de Hostinger sigue sirviendo el PHP viejo aunque el ZIP esté bien
3. Falta `includes/config.local.php` (credenciales) → la portada no conecta a la DB

## Pasos exactos
1. Backup de `public_html` (**no borres** `includes/config.local.php`).
2. Subí `cyberleo-recuperacion-login.zip` y extraélo **directo sobre** `public_html` (sobrescribir).
3. Confirmá que **no** quedó `public_html/alguna-carpeta/index.php`.
4. En hPanel Hostinger:
   - **Reiniciar PHP** / OPcache
   - LiteSpeed Cache → **Purge All**
5. Ventana privada, en este orden:
   - `/diag_recovery.php?opcache=reset` → `cyberleo_asset_url=1`, `asset_version.php` size > 1000, `config_local_present=1`, `db_constants_present=1`
   - `/emergency_admin_login.php` → HTTP 200 (anti-OPcache; si este abre, el PHP nuevo está vivo)
   - `/admin_login.php` → HTTP 200
   - `/` → HTTP 200
6. Cuando esté estable, borrá `diag_recovery.php` y `emergency_admin_login.php`.

SHA-256: `850753add86b29f2ac1db9c56dcf4352eb331f4a5f93c778a0736873c5b24741` 
