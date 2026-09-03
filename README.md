# CyberLeo · Tienda informática

Catálogo autoadministrable de productos informáticos con carrito, control de
stock y solicitudes de compra por WhatsApp.

## Paquetes

| Artefacto | Uso |
| --- | --- |
| `cyberleo-hostinger.zip` | Release público para `public_html` |
| `cyberleo-private-tools.zip` | Instalador, diagnóstico, backup/restore, schema, cron y docs (fuera de `public_html`) |
| `cyberleo-actualizacion-estetica.zip` | Actualización incremental de archivos productivos |

Generación:

```bash
./scripts/build_hostinger_release.sh
./scripts/build_private_tools.sh
./scripts/build_aesthetic_update.sh
```

## Cliente nuevo

Ver `docs/INSTALL_NEW_STORE.md`. Resumen:

1. Crear base MySQL **vacía** (utf8mb4).
2. Extraer `cyberleo-hostinger.zip` en `public_html`.
3. Extraer `cyberleo-private-tools.zip` fuera de `public_html`.
4. Ejecutar `php scripts/install_store.php --public-root=...` (CLI privado).
5. Entrar al panel, configurar la tienda y programar el cron privado.

No hay instalador web ni endpoints públicos de setup/backup.

## Actualización de una instalación existente

Ver `docs/DEPLOY_HOSTINGER.md` y `docs/BACKUP_RESTORE.md`. Resumen:

1. Backup verificable (`scripts/backup_store.php`).
2. Staging con base y uploads propios.
3. Migraciones privadas si aplica.
4. ZIP incremental o release completo.
5. Diagnóstico y smoke test.
6. Producción.
7. Rollback manual si hace falta.

## Diagnóstico

```bash
php scripts/diagnose_store.php --public-root=/ruta/absoluta/public_html
```

En el panel: `admin_system.php` (solo lectura, sin secretos).

## Flujo de pedidos y stock

- El cliente agrega productos y presiona **Enviar pedido por WhatsApp**.
- Antes de abrir WhatsApp, el sitio valida el stock en el servidor, crea un pedido `Pendiente` y reserva las unidades.
- Desde `admin_orders.php`, el dueño confirma el pedido o lo cancela. Al cancelar, el stock se repone automáticamente.

Las cantidades y precios se vuelven a validar en el servidor; los valores del navegador no se toman como fuente de verdad.
