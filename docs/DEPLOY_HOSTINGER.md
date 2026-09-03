# Deploy en Hostinger

Hay **dos flujos** separados. No mezclarlos.

1. **Cliente nuevo** → `docs/INSTALL_NEW_STORE.md`
2. **Actualización** → esta guía + `docs/BACKUP_RESTORE.md`

El artefacto público (`cyberleo-hostinger.zip`) se genera con allowlist. No
contiene pruebas, migraciones, esquema, cron, scripts de mantenimiento,
documentación, configuración local ni secretos.

## 1. Preparar y verificar los paquetes

Requisitos locales: Bash, PHP CLI con ZipArchive, `zip`, `unzip`, `mysql`,
`mysqldump` y `sha256sum`.

```bash
./tests/run.sh
./scripts/build_hostinger_release.sh
./scripts/build_private_tools.sh
./scripts/build_aesthetic_update.sh
unzip -Z1 dist/cyberleo-hostinger.zip
unzip -Z1 dist/cyberleo-private-tools.zip
unzip -Z1 dist/cyberleo-actualizacion-estetica.zip
```

El ZIP público no tiene carpeta contenedora: sus archivos se extraen directo en
`public_html`. Las herramientas privadas se extraen **fuera** de
`public_html`. Conservá los SHA-256 impresos por los builders (no se generan
sidecars `.sha256`).

## 2. Actualización: respaldo obligatorio

Antes de tocar producción:

1. Ejecutar backup privado verificable:
   ```bash
   php /ruta/privada/scripts/backup_store.php \
     --public-root=/ruta/absoluta/public_html \
     --output-dir=/ruta/privada/backups
   ```
2. Verificar el ZIP:
   ```bash
   php /ruta/privada/scripts/restore_store.php \
     --verify=/ruta/privada/backups/cyberleo-backup-....zip
   ```
3. Rotar la contraseña MySQL si estuvo expuesta y actualizar solo
   `config.local.php` privado.
4. Probar primero en staging con base y credenciales propias.

Orden obligatorio de staging:

1. Base exclusiva de staging (vacía para restore de prueba, o copia controlada
   para migración).
2. Uploads de staging.
3. Migraciones privadas si aplican.
4. `scripts/verify_production_images.php --root` sobre la raíz pública real.
5. Desplegar ZIP en directorio nuevo de staging.
6. Restaurar uploads conservando `.htaccess` del release.
7. `diagnose_store.php` + smoke test.
8. Recién entonces planificar producción.

## 3. Migraciones (solo privadas)

```bash
cd /ruta/privada/cyberleo-private
DB_HOST='HOST' DB_NAME='NOMBRE_BASE' DB_USER='USUARIO' DB_PASS='CLAVE' \
  php migrations/001_add_orders_stock_settings.php
```

No copiar `migrations/` ni `schema.sql` a `public_html`.

## 4. Publicar sin perder uploads

No vaciar `public_html` directamente. Extraer el ZIP en un directorio nuevo,
restaurar uploads respaldados (JPG/JPEG/PNG/WebP), mantener `.htaccess` nuevos,
diagnosticar y recién entonces intercambiar.

```bash
PUBLIC_ROOT="$(pwd -P)"
php /ruta/privada/scripts/verify_production_images.php --root "$PUBLIC_ROOT"
php /ruta/privada/scripts/diagnose_store.php --public-root "$PUBLIC_ROOT"
```

`config.local.php` se crea con el instalador (tienda nueva) o se conserva en
actualizaciones. Nunca va en el ZIP. Permiso tipicamente `0600`.

## 5. Cron privado cada 5 minutos

```cron
*/5 * * * * php /ruta/privada/cyberleo-private/cron/expire_reservations.php --public-root=/ruta/absoluta/public_html >> /ruta/privada/cron.log 2>&1
```

## 6. Checklist

- SHA-256 del builder coincide con el artefacto subido.
- Contenido en la raíz de `public_html`.
- `config.local.php` existe, restrictivo y no está en el ZIP.
- Portada, catálogo, carrito y CSS cargan por HTTPS.
- `/admin` → `301` a `/admin_products.php`; login y `admin_system.php` OK.
- Pedido de prueba y cancelación ajustan stock.
- Cron privado sin errores.
- `/tests/`, `/scripts/`, `/migrations/`, `/docs/`, `/cron/`, `/backups/`,
  `/dist/`, `/schema.sql`, `/README.md`, `/.env`, `/includes/config.local.php`
  y dumps responden `403` o `404`.
- Sin directory listing.

## 7. Rollback manual

1. Detener cambios administrativos y pedidos.
2. Renombrar el `public_html` fallido y restaurar el directorio/ZIP anterior.
3. Restaurar `config.local.php` anterior sin exponerlo.
4. Si hace falta volver datos: restaurar dump previo (manual). El restore
   automático CLI solo opera sobre bases vacías de staging.
5. Repetir smoke tests.

Conservar el despliegue fallido y sus logs fuera de `public_html` hasta
terminar el diagnóstico.
