# Reparación integral — index + login HTTP 500

Mismo contenido que `releases/recuperacion-login/` (árbol público completo).

## Pasos
1. Backup de `public_html` (conservá `includes/config.local.php`).
2. Extraé `cyberleo-reparacion-integral.zip` **directo sobre** `public_html`.
3. hPanel → Reiniciar PHP + Purge LiteSpeed.
4. Abrí `/diag_recovery.php?opcache=reset`, luego `/emergency_admin_login.php`, `/admin_login.php`, `/`.
5. Borrá `diag_recovery.php` y `emergency_admin_login.php` al estabilizar.

Si `config_local_present=0` o `db_constants_present=0`, restaurá `includes/config.local.php` desde el backup.

SHA-256: (se actualiza al regenerar el ZIP)
