# Backup y restore verificables (CyberLeo)

Las operaciones de respaldo y restauración son **solo CLI** y viven en el
paquete privado. No hay endpoints web, ni formularios que reciban credenciales.

## Backup

```bash
php scripts/backup_store.php \
  --public-root=/ruta/absoluta/real/public_html \
  --output-dir=/ruta/privada/backups
```

Reglas:

- `--output-dir` absoluto, canónico, **fuera** de `public_html`, sin symlinks;
- el ZIP se crea con permiso restrictivo (0600 cuando es posible);
- `mysqldump` se ejecuta con `proc_open` en modo array;
- la contraseña viaja en un defaults-extra-file temporal 0600 que siempre se
  elimina;
- no se incluye `config.local.php` ni credenciales.

Contenido:

- `database.sql`
- `assets/images/products/` (solo JPG/JPEG/PNG/WebP y `.htaccess`)
- `assets/images/settings/` (igual)
- `manifest.json` (formato, versión, fecha UTC, commit si existe, base lógica
  sin usuario/clave, listado exacto con tamaño y SHA-256, exclusión explícita
  de `config.local.php`)

## Verificar un backup

```bash
php scripts/restore_store.php --verify=/ruta/privada/backups/cyberleo-backup-....zip
```

Comprueba manifiesto, hashes, ausencia de ZIP Slip / symlinks / archivos
extras, y extensiones permitidas. No modifica nada.

## Restore solo sobre ambiente vacío

```bash
php scripts/restore_store.php \
  --restore-empty=/ruta/privada/backups/cyberleo-backup-....zip \
  --public-root=/ruta/staging/public_html
```

Requisitos:

- base MySQL **sin tablas**;
- public root de staging válido;
- uploads vacíos salvo sus `.htaccess` del release;
- verificación completa del ZIP **antes** de escribir.

El restore:

- importa `database.sql` con `mysql`/`proc_open` sin exponer credenciales;
- restaura solo imágenes permitidas;
- preserva los `.htaccess` actuales del release;
- ejecuta diagnóstico;
- informa cantidades (usuarios, categorías, productos, pedidos, settings).

**No** existe un modo que borre o sobrescriba automáticamente una instalación
existente. El rollback de producción sigue siendo manual (ver
`DEPLOY_HOSTINGER.md`).

## Actualización (resumen)

1. Backup privado verificable.
2. Staging con base y uploads propios.
3. Migraciones privadas si aplica.
4. ZIP incremental (`cyberleo-actualizacion-estetica.zip`) o release completo.
5. Verificación (`diagnose_store`, smoke test).
6. Producción.
7. Rollback manual si falla.
