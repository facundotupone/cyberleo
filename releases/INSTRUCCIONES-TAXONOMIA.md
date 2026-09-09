# Instrucciones — taxonomía de catálogo CyberLeo

Documento privado de operación. **No ejecutar en producción sin backup.** Este refinamiento no modifica el esquema ni reclasifica productos automáticamente.

## Paquetes

| Paquete | Contenido |
|---------|-----------|
| `cyberleo-taxonomia-ui.zip` | Código productivo para `public_html` (nav Productos/Ofertas, página de ofertas, helpers) |
| `cyberleo-taxonomia-tools.zip` | Importador CLI, SQL phpMyAdmin e instrucciones |

## Resultado esperado

- 10 categorías principales con iconos Bootstrap Icons
- 69 subcategorías canónicas
- Renombres conservando ID: `Notebooks` → `Notebooks y PC`, `Componentes` → `Componentes y almacenamiento`
- `Periféricos` conserva nombre; se actualiza icono a `bi-keyboard` si hace falta
- Productos, precios, stock, imágenes y pedidos **intactos**
- Segunda ejecución **idempotente** (sin duplicados)
- Si coexisten nombre legado y canónico → **abortar** (sin fusión automática)

## Opción A — Importador PHP (recomendado)

1. Desplegar primero el código de `cyberleo-taxonomia-ui.zip` sobre `public_html` (sobrescribir código; conservar `config.local.php` y uploads).
2. Extraer `cyberleo-taxonomia-tools.zip` **fuera** de `public_html`.
3. Dry-run:

```bash
php scripts/seed_catalog_taxonomy.php \
  --public-root=/ruta/absoluta/public_html \
  --dry-run
```

4. Aplicar:

```bash
php scripts/seed_catalog_taxonomy.php \
  --public-root=/ruta/absoluta/public_html \
  --apply
```

5. Revisar el informe (creados / renombrados / reutilizados / conflictos / productos preservados).
6. Si hay productos, revisar `artifacts/productos-para-reclasificar.csv` (solo sugerencias; **no aplicadas**).

## Opción B — SQL en phpMyAdmin

1. Exportar backup completo de la base.
2. Abrir `artifacts/cyberleo-taxonomia-catalogo.sql`.
3. Ejecutar en la base de la tienda.
4. Si aparece conflicto legado/canónico, el script aborta con `SIGNAL` — resolver a mano antes de reintentar.
5. Al final, el `SELECT` resume categoría, icono, cantidad de subcategorías y productos.

## Navegación y ofertas

Tras el deploy de UI:

- Menú: `Inicio` · `Productos ▾` · `Ofertas` · `Carrito`
- Ofertas = productos con `is_active = 1 AND price_sale IS NOT NULL AND price_sale > 0 AND price_sale < price`
- **No** existe categoría “Ofertas varios”

## Verificación rápida

- [ ] 10 categorías visibles en portada / panel
- [ ] Subcategorías en menú Productos (desktop y móvil)
- [ ] `get_subcategories.php` responde por categoría
- [ ] Alta/edición de productos carga subcategorías dependientes
- [ ] Página `offers.php` lista solo ofertas válidas
- [ ] Carrito y pedidos sin cambios de comportamiento
- [ ] Segunda ejecución del seed no duplica filas

## Rollback

1. Restaurar backup de `public_html` (código).
2. Restaurar backup SQL **solo** si necesitás revertir nombres de categorías/subcategorías creadas.
3. No hace falta tocar pedidos ni stock si el seed terminó bien (no los modifica).

## Límites

- No hardcodear IDs
- No fusionar categorías automáticamente
- No reclasificar productos por palabras clave sin aprobación
- Marcas (JBL, Soul, Genius, SanDisk, Kingston, TP-Link, HP, Epson, Canon, …) no son categorías
