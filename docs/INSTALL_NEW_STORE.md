# Instalación de una tienda nueva (CyberLeo)

Este flujo es para **clientes nuevos** sobre una base vacía y un `public_html`
recién preparado. No usa instaladores web ni endpoints públicos.

## Artefactos

1. `cyberleo-hostinger.zip` — código público (extraer en `public_html`).
2. `cyberleo-private-tools.zip` — herramientas privadas (extraer **fuera** de
   `public_html`, por ejemplo `/home/USUARIO/cyberleo-private`).

Nunca subas `schema.sql`, `scripts/`, `migrations/`, `cron/`, `tests/` ni
`docs/` dentro del document root.

## Pasos

### 1. Crear una base MySQL vacía

```sql
CREATE DATABASE nombre_base CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

La base debe estar **sin tablas**. El instalador se detiene si encuentra
cualquier tabla.

### 2. Extraer el release público

```bash
cd /ruta/al/public_html
unzip /ruta/cyberleo-hostinger.zip
```

### 3. Extraer herramientas privadas fuera de public_html

```bash
mkdir -p /ruta/privada/cyberleo-private
cd /ruta/privada/cyberleo-private
unzip /ruta/cyberleo-private-tools.zip
```

### 4. Ejecutar el instalador CLI

```bash
export DB_HOST='HOST_MYSQL'
export DB_NAME='nombre_base'
export DB_USER='usuario_mysql'
export DB_PASS='clave_mysql'   # solo por entorno, nunca como flag visible
export STORE_NAME='Mi Tienda'
export SITE_URL='https://www.ejemplo.com'
export WHATSAPP_NUMBER='5491100000000'
export ADMIN_USERNAME='admin'
export ADMIN_EMAIL='admin@ejemplo.com'   # opcional
export ADMIN_PASSWORD='contraseña-segura-de-12+'

php scripts/install_store.php \
  --public-root=/ruta/absoluta/real/public_html \
  --non-interactive
```

Sin variables de entorno, el instalador pregunta de forma interactiva.
`DB_PASS` y la contraseña administrativa **no** se aceptan como argumentos
`--db-pass=...`.

El instalador:

- valida el public root (estructura CyberLeo, sin symlinks, sin repo privado);
- importa `schema.sql` solo sobre base vacía;
- crea el primer administrador con `password_hash()`;
- genera `APP_SECRET` con `random_bytes(32)`;
- escribe `includes/config.local.php` de forma atómica (0600) **después** del
  esquema y del administrador;
- ejecuta un diagnóstico sin imprimir secretos.

Una segunda ejecución se detiene si `config.local.php` ya existe.

Si la instalación queda a medias, **recreá la base vacía** (DROP/CREATE) y
volvé a intentar. No hay rollback destructivo automático de DDL.

### 5. Entrar al panel

Abrí `/admin_login.php`, iniciá sesión y configurá la tienda desde
`admin_settings.php`. Revisá el estado en `admin_system.php` (solo lectura).

### 6. Cron privado

Mantener `cron/` en el directorio privado, con su propia copia de configuración
cuando corresponda, y programar cada 5 minutos:

```cron
*/5 * * * * php /ruta/privada/cyberleo-private/cron/expire_reservations.php >> /ruta/privada/cron.log 2>&1
```

## Diagnóstico

```bash
php scripts/diagnose_store.php --public-root=/ruta/absoluta/real/public_html
```

Exit 0 si no hay FAIL. Nunca imprime contraseñas ni `APP_SECRET`.
