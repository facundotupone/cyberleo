# Hotfix urgente — admin en blanco

## Causa
En producción faltaba `components/admin_nav.php` (HTTP 404).
`admin_products.php` y `admin_categories.php` (también pedidos, settings y system) hacen:

```php
require_once __DIR__ . '/components/admin_nav.php';
```

Si el archivo no existe, PHP aborta y la página queda en blanco.

## Qué subir ahora
Extraer `cyberleo-hotfix-admin-blank.zip` **directo sobre** `public_html` (sobrescribir).

Archivos incluidos (mínimo crítico + dependencias alineadas):
- `components/admin_nav.php` ← el que faltaba
- `includes/admin_nav.php`
- `includes/asset_version.php`
- footer/nav/head/benefits + CSS versionables asociados

## Después
1. LiteSpeed → Purge All
2. Recarga forzada
3. Abrir `admin_products.php` y `admin_categories.php`

## Mejor opción
Si podés, desplegar el paquete completo de refinamiento corregido en su lugar:
`releases/refinamiento-corregido/cyberleo-refinamiento-corregido.zip`
