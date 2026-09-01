# CyberLeo · Tienda informática

Catálogo autoadministrable de productos informáticos con carrito, control de stock y solicitudes de compra por WhatsApp.

## Instalación nueva

1. Crear una base de datos vacía con UTF-8:
   ```sql
   CREATE DATABASE cyberleo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Importar el esquema indicando explícitamente esa base. `schema.sql` no crea ni selecciona bases de datos:
   ```bash
   mysql -u USUARIO -p cyberleo < schema.sql
   ```
3. Completar las credenciales, dominio público y número del dueño en `includes/config.php`.
   `WHATSAPP_NUMBER` debe llevar código de país y número, sin `+`, espacios ni guiones.
4. Crear el primer usuario administrador en la tabla `users`. La contraseña debe almacenarse con `password_hash()` de PHP.
5. Dar permisos de escritura al servidor web sobre `assets/images/products/`.
6. Abrir `admin_login.php`, cargar categorías y productos, incluyendo el stock inicial.

Desde `admin_settings.php`, el administrador puede modificar el nombre comercial, WhatsApp, Instagram, textos de portada y subir fondos para el encabezado y el sitio. La configuración técnica (base de datos y dominio) permanece en `includes/config.php`.

## Actualización de una instalación existente

1. Hacer un respaldo antes de migrar:
   ```bash
   mysqldump -u USUARIO -p NOMBRE_BASE > respaldo-antes-migracion.sql
   ```
2. Ejecutar la migración desde la raíz del proyecto. Puede tomar las credenciales de `includes/config.php` o recibirlas por variables de entorno:
   ```bash
   DB_HOST=localhost DB_NAME=NOMBRE_BASE DB_USER=USUARIO DB_PASS='CONTRASEÑA' \
     php migrations/001_add_orders_stock_settings.php
   ```

La migración es incremental e idempotente: crea únicamente las tablas `orders`, `order_items` y `store_settings`, las columnas faltantes `products.stock` y `products.is_active`, y los índices o claves foráneas requeridos cuando la estructura existente es compatible. No elimina ni actualiza datos. Si encuentra tipos, motores, restricciones o referencias huérfanas incompatibles, se detiene con un diagnóstico y no intenta corregir datos automáticamente.

### Rollback manual

No hay rollback automático, porque la migración no debe borrar datos. Para volver al estado previo, detener la aplicación y restaurar el respaldo:

```bash
mysql -u USUARIO -p NOMBRE_BASE < respaldo-antes-migracion.sql
```

Como alternativa, un administrador de base de datos puede retirar manualmente las columnas, índices, claves foráneas y tablas creadas, únicamente después de verificar que no contienen pedidos ni configuración que se deban conservar.

## Flujo de pedidos y stock

- El cliente agrega productos y presiona **Enviar pedido por WhatsApp**.
- Antes de abrir WhatsApp, el sitio valida el stock en el servidor, crea un pedido `Pendiente` y reserva las unidades.
- Desde `admin_orders.php`, el dueño confirma el pedido o lo cancela. Al cancelar, el stock se repone automáticamente.

Las cantidades y precios se vuelven a validar en el servidor; los valores del navegador no se toman como fuente de verdad.
