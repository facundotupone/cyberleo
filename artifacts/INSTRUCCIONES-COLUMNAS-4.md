# Establecer 4 columnas (instalación existente)

Archivo SQL revisado (no ejecutado por el agente):

`artifacts/set-catalog-columns-4.sql`

## Qué hace

Upsert idempotente de:

* `featured_columns` = `4`
* `catalog_columns` = `4`

en `store_settings` (`setting_key` PK, `setting_value` TEXT).

## Cómo aplicarlo

1. Backup de `store_settings` si lo deseás.
2. Ejecutar el SQL en phpMyAdmin / cliente MySQL.
3. Purge LiteSpeed / Ctrl+F5.
4. Verificar Admin → Ajustes → Columnas destacados/catálogo = 4.

## Rollback

```sql
UPDATE store_settings SET setting_value='3' WHERE setting_key IN ('featured_columns','catalog_columns');
```

(o el valor que tenías antes).

## Alternativa sin SQL

Admin → Ajustes → elegir 4 en ambos selectores → Guardar.
