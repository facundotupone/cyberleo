# Fix redeclaración config_value + portada completa

## Causa del modo seguro
`Cannot redeclare config_value()`: `index.php` cargaba `config.php` por ruta absoluta y luego `db.php` lo volvía a cargar por ruta relativa.

## Importante al subir
- **SÍ** sobrescribí `includes/config.php` (el del ZIP es seguro; credenciales van en `config.local.php`)
- **NO** borres `includes/config.local.php`
- Confirmá en diag: `functions.php size=4778` (si sigue 4631, el ZIP no se extrajo completo)

## Pasos
1. Extraé el ZIP directo sobre public_html
2. Reiniciar PHP + Purge LiteSpeed
3. `/diag_recovery.php?opcache=reset` → `package_build=index-resilient-20260909`, `done`, `functions.php` size 4778
4. `/` debe abrir sin modo seguro

SHA-256: `95ccdab16fdf79ada040d4400962de70d31e753de90c5ef2212000e1c130f190`
