# CyberLeo · Tienda informática

Catálogo autoadministrable de productos informáticos con carrito, control de stock y solicitudes de compra por WhatsApp.

## Puesta en marcha

1. Crear/importar la base con `mysql -u USUARIO -p < schema.sql`.
2. Completar las credenciales, dominio público y número del dueño en `includes/config.php`.
   `WHATSAPP_NUMBER` debe llevar código de país y número, sin `+`, espacios ni guiones.
3. Crear el primer usuario administrador en la tabla `users`. La contraseña debe almacenarse con `password_hash()` de PHP.
4. Dar permisos de escritura al servidor web sobre `assets/images/products/`.
5. Abrir `admin_login.php`, cargar categorías y productos, incluyendo el stock inicial.

Desde `admin_settings.php`, el administrador puede modificar el nombre comercial, WhatsApp, Instagram, textos de portada y subir fondos para el encabezado y el sitio. La configuración técnica (base de datos y dominio) permanece en `includes/config.php`.

## Flujo de pedidos y stock

- El cliente agrega productos y presiona **Enviar pedido por WhatsApp**.
- Antes de abrir WhatsApp, el sitio valida el stock en el servidor, crea un pedido `Pendiente` y reserva las unidades.
- Desde `admin_orders.php`, el dueño confirma el pedido o lo cancela. Al cancelar, el stock se repone automáticamente.

Las cantidades y precios se vuelven a validar en el servidor; los valores del navegador no se toman como fuente de verdad.
