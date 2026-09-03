# Pruebas de migración

`run.sh` ejecuta una prueba de integración reproducible de
`migrations/001_add_orders_stock_settings.php`. No usa la base de datos de
desarrollo: inicializa una instancia MariaDB temporal con socket Unix, crea la
base `cyberleo_migration_test`, la elimina entre fixtures y destruye el
directorio temporal al terminar, incluso ante un error.

## Prerrequisitos

- Bash, `mktemp` y Git.
- PHP CLI con la extensión `pdo_mysql`.
- Cliente MariaDB/MySQL: `mysql` y `mysqladmin`.
- Servidor MariaDB local: `mariadbd` y `mariadb-install-db`.
- Permiso para crear archivos temporales bajo `${TMPDIR:-/tmp}`.

La prueba no requiere un servidor MariaDB ya iniciado ni credenciales locales.
El proceso temporal se inicia con `--skip-networking` y sólo acepta conexiones
por su socket temporal.

## Ejecución

Desde la raíz del repositorio:

```bash
tests/run.sh
```

El script primero ejecuta `php -l` sobre todos los archivos PHP versionados.
Después, para cada fixture, importa el esquema, ejecuta la migración dos veces
y consulta `information_schema` mediante `mysql` para comprobar tablas,
columnas e índices.

## Fixtures

- `fixtures/legacy_without_orders.sql`: catálogo legacy compatible sin
  `orders`, `order_items` ni `store_settings`, y sin las columnas nuevas de
  `products`.
- `fixtures/pre_5c8bdb2_orders.sql`: aproximación compatible del esquema de
  `5c8bdb2` con `orders`, `order_items` y `store_settings`, pero sin
  `orders.idempotency_key` ni `orders.expires_at`. Incluye `expired` en el
  `ENUM` de estado porque la migración actual lo exige.
