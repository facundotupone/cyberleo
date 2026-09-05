# Recuperación urgente — admin_login HTTP 500

## Causa exacta
`admin_login.php` nuevo hacía:

```php
require_once 'includes/asset_version.php';
```

Si ese archivo no está en `public_html/includes/` (publicación parcial), PHP aborta con:

`Failed opening required 'includes/asset_version.php'`

→ HTTP 500. El versionado de CSS es solo estética/caché y **nunca** debe derribar el login.

## Qué contiene este ZIP
Rutas relativas a `public_html` (sin carpeta contenedora):

- `admin_login.php` (degradación segura)
- `includes/asset_safe_url.php`
- `includes/asset_version.php`

## Pasos
1. Backup rápido de los 3 archivos actuales (si existen).
2. Extraer este ZIP **directo sobre** `public_html` (sobrescribir).
3. LiteSpeed → Purge All.
4. Abrir `/admin_login.php` — debe responder HTTP 200.
5. Luego desplegar el paquete integral de reparación cuando puedas.

SHA-256: `770534ba191da4e27141215c7c19a871ceb71c84872cbc8c0c10ebae742aedf2`
