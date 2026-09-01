# Deploy en Hostinger

El artefacto público se genera con una allowlist. No contiene pruebas,
migraciones, esquema, cron, documentación, configuración local ni secretos.

## 1. Preparar y verificar el release

Requisitos locales: Bash, PHP CLI, `zip`, `unzip` y `sha256sum`.

```bash
./tests/run.sh
./scripts/build_hostinger_release.sh
(cd dist && sha256sum -c cyberleo-hostinger.zip.sha256)
unzip -Z1 dist/cyberleo-hostinger.zip
```

El ZIP no tiene una carpeta contenedora: sus archivos deben extraerse
directamente en `public_html`. Guardar fuera del hosting una copia del ZIP y
del archivo `.sha256`.

## 2. Respaldo y staging privado

Antes de cambiar producción:

1. Cambiar obligatoriamente la contraseña MySQL que haya sido expuesta anteriormente y actualizarla sólo en la configuración privada.
2. Exportar la base desde hPanel/phpMyAdmin o con `mysqldump`.
3. Comprimir el `public_html` actual.
4. Guardar ambos respaldos fuera de `public_html` y comprobar que se pueden
   abrir.
4. Crear un directorio privado como
   `/home/USUARIO/cyberleo-private`, nunca debajo de `public_html`.
5. Para una actualización, subir allí una copia del repositorio fuente. Esa
   copia privada contiene `migrations/`, `schema.sql` y `cron/`; el ZIP público
   no los contiene.

Probar primero en un subdominio de staging con su propia base de datos y
credenciales. No reutilizar la base ni `APP_SECRET` de producción.

## 3. Base de datos y migración

Para una instalación nueva, importar desde el staging privado:

```bash
mysql -h HOST -u USUARIO -p NOMBRE_BASE \
  < /home/USUARIO/cyberleo-private/schema.sql
```

Para actualizar, ejecutar la migración sólo por SSH/Terminal de hPanel desde
el staging privado, después del respaldo:

```bash
cd /home/USUARIO/cyberleo-private
DB_HOST='HOST' DB_NAME='NOMBRE_BASE' DB_USER='USUARIO' DB_PASS='CLAVE' \
  php migrations/001_add_orders_stock_settings.php
```

Ejecutarla primero sobre la copia de staging, volver a ejecutarla para
comprobar idempotencia y revisar explícitamente pedidos, stock, usuarios y
settings. Sólo después de esos controles se debe ejecutar una vez en
producción mediante SSH.

No copiar `migrations/` ni `schema.sql` a `public_html`. La migración es
idempotente, pero siempre debe probarse primero contra el respaldo restaurado
en staging.

## 4. Publicar y configurar

Vaciar o renombrar el contenido anterior de `public_html` y extraer allí
`cyberleo-hostinger.zip`. No extraerlo como
`public_html/cyberleo-hostinger/...`.

Crear manualmente `public_html/includes/config.local.php`; nunca agregarlo al
ZIP. Plantilla:

```php
<?php
define('DB_HOST', 'HOST_MYSQL');
define('DB_USER', 'USUARIO_MYSQL');
define('DB_PASS', 'CLAVE_MYSQL');
define('DB_NAME', 'NOMBRE_BASE');
define('SITE_URL', 'https://www.ejemplo.com');
define('STORE_NAME', 'CyberLeo');
define('WHATSAPP_NUMBER', '5491100000000');
define('STORE_INSTAGRAM', '');
define('APP_SECRET', 'PEGAR_64_CARACTERES_HEXADECIMALES');
```

Generar `APP_SECRET` en una terminal segura:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Usar un valor distinto por ambiente. No enviarlo por chat, no guardarlo en el
repositorio y no rotarlo durante un deploy normal: la rotación puede invalidar
controles firmados o limitadores existentes. Dar al archivo el permiso mínimo
que permita leerlo al proceso PHP (normalmente `0600`) y comprobar que una
petición HTTP a él responde `403` o `404`.

Verificar en hPanel:

- HTTPS activo y redirección a HTTPS configurada por el dominio.
- Una versión de PHP compatible con la aplicación y la extensión `pdo_mysql`.
- Escritura del usuario PHP únicamente donde se suben imágenes:
  `assets/images/products/` y `assets/images/settings/`.
- `.htaccess` fue extraído (el gestor de archivos puede ocultarlo).
- No usar permisos `0777`.

## 5. Cron privado cada 5 minutos

Mantener `cron/` y su copia operativa de `includes/` en
`/home/USUARIO/cyberleo-private`, junto con un
`includes/config.local.php` privado con la misma configuración de producción.
Actualizar esa copia privada al desplegar cambios relacionados.

En **hPanel → Tareas cron**, seleccionar cada 5 minutos usando las rutas reales
que muestre Hostinger:

```cron
*/5 * * * * php /ruta/privada/al/proyecto/cron/expire_reservations.php >> /ruta/privada/al/proyecto/cron.log 2>&1
```

Confirmar en hPanel la ruta real de PHP CLI. Probar una vez por SSH y revisar
su código de salida. El log también queda fuera de `public_html`; aplicar
rotación o truncado periódico para que no crezca sin límite.

## 6. Checklist y smoke test

- El SHA-256 local coincide con `dist/cyberleo-hostinger.zip.sha256`.
- El contenido quedó en la raíz de `public_html`.
- `config.local.php` existe, tiene permisos restrictivos y no aparece en el
  ZIP.
- La portada, búsqueda, categorías, carrito y recursos CSS cargan por HTTPS.
- `/admin` responde `301` hacia `/admin_products.php`; el login y una sesión
  administrativa funcionan.
- La creación controlada de un pedido de prueba y su cancelación ajustan el
  stock como corresponde.
- Una carga y eliminación de imagen de prueba funcionan.
- La tarea cron se ejecuta sin errores y escribe en su log privado.
- `/tests/run.sh`, `/migrations/`, `/schema.sql`, `/README.md`, `/.env`,
  `/includes/config.local.php` y `/logs/` responden `403` o `404`.
- Un directorio sin índice, por ejemplo `/includes/`, no muestra un listado.
- Los logs de PHP no muestran errores nuevos.

Ejemplos de comprobación:

```bash
curl -sS -I https://www.ejemplo.com/
curl -sS -I https://www.ejemplo.com/admin
curl -sS -I https://www.ejemplo.com/schema.sql
curl -sS -I https://www.ejemplo.com/includes/config.local.php
curl -sS -I https://www.ejemplo.com/includes/
```

## 7. Rollback

1. Detener cambios administrativos y pedidos mientras se revierte.
2. Renombrar el `public_html` fallido y restaurar el respaldo de archivos o
   extraer el ZIP anterior.
3. Restaurar el `config.local.php` anterior sin exponerlo públicamente.
4. Si se aplicó la migración y es necesario volver también los datos,
   restaurar el dump completo previo. No intentar deshacer columnas o tablas
   manualmente con tráfico activo.
5. Restaurar la versión privada anterior usada por cron y migraciones.
6. Repetir los smoke tests antes de reabrir el sitio.

Conservar el despliegue fallido y sus logs fuera de `public_html` hasta
terminar el diagnóstico.
