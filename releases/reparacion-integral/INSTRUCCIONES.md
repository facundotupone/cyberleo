# Reparación integral — deploy parcial / query featured

## Qué pasaba
Tu diag anterior mostraba archivos OK, pero tamaños viejos en `functions.php` (4631)
mientras `index.php` ya era nuevo (13562). Deploy parcial.

## Este ZIP
Árbol público completo. La portada ahora:
- atrapa fallos de productos destacados
- no depende de subir functions.php nuevo para dejar de dar 500
- degrada a HTML usable en vez de HTTP 500

## Pasos
1. Backup (conservá `includes/config.local.php`)
2. Extraé este ZIP **directo sobre** public_html (sobrescribir TODO)
3. hPanel → Reiniciar PHP + Purge LiteSpeed
4. Abrí `/diag_recovery.php?opcache=reset`
   - debe decir `package_build=index-resilient-20260909`
   - `done` al final
   - `index.php size` y `functions.php size` según fingerprint
5. Abrí `/` → debe cargar (aunque sea modo seguro)
6. Borrá diag_recovery.php y emergency_admin_login.php al estabilizar

SHA-256: `4166b1c2d44fb4ed71b2c0af6ccbae29970af3b7360cb9350f021d4d34dda079`
