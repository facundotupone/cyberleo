# Recuperación urgente — index/login HTTP 500

## Causa
Deploy parcial: `asset_version.php` ausente **o vacío**. El código antiguo llama `cyberleo_asset_url()`:

`Call to undefined function cyberleo_asset_url()`

→ HTTP 500 en `/` e `/admin_login.php`.

## Pasos
1. Extraer este ZIP **directo sobre** `public_html` (sobrescribir).
2. LiteSpeed → Purge All / Flush All.
3. Si Hostinger tiene OPcache: reiniciar PHP.
4. Verificar `/` y `/admin_login.php` → 200.

SHA-256: `040a7dc3548a8a87c25df999aab8612bfdbae600d2dbb219be0e29f87a169fa3`

Luego subir el paquete integral cuando puedas.
