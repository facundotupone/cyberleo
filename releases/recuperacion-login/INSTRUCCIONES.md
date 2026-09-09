# Fix: El servicio no está configurado

## Causa
Existe `public_html/config.php` (malo/vacío). El `db.php` viejo carga ese archivo
desde el CWD y deja la DB sin usuario/nombre.

## Qué hacer
1. Extraé este ZIP sobre public_html
2. **BORRÁ** el archivo `public_html/config.php` (el de la raíz, NO `includes/config.php`)
3. Conservá `includes/config.local.php`
4. Reiniciar PHP + Purge LiteSpeed
5. Diag: `package_build=cwd-includes-boot-20260909` y `root_config_php=0`
6. Abrí `/`

SHA-256: `4c07c76eaeba76b2465a44a5b807092e967b59cfa7fad1bdebeebb7102dd58ad`
