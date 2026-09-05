# Despliegue controlado — refinamiento visual (PR #4)

Documento operativo para instalar en Hostinger el release público ya fusionado en `main`, **sin tocar la base de datos** y **sin vaciar** `public_html`.

## Referencias

| Ítem | Valor |
|------|--------|
| Commit de `main` | `d90cc6b86d69df2ad77a9543ebced46ee14d972a` |
| PR | [#4](https://github.com/facundotupone/cyberleo/pull/4) (MERGED) |
| Paquete | `cyberleo-hostinger.zip` |
| SHA-256 | `f4d382d5a89d8d9add4e26be11395a60fcdadadf6922a3ae76ff54915796756a` |
| Rutas | 53 (código público allowlist) |
| Base de datos | **sin cambios** — no importar SQL |

> **No usar** el ZIP incremental (`cyberleo-ajuste-beneficios-footer-nav.zip`) salvo que se demuestre que producción corresponde exactamente a `5d25a84`. En duda, usar siempre este paquete completo.

---

## 1. Respaldo (obligatorio antes de continuar)

### 1.1 Base de datos (phpMyAdmin)

1. Abrir **phpMyAdmin** en hPanel.
2. Seleccionar la base de la tienda.
3. **Exportar** → método **Rápido** o personalizado → formato **SQL**.
4. Descargar el `.sql` a un lugar seguro fuera del hosting.
5. Anotar fecha/hora del export.

### 1.2 Archivos (`public_html`)

1. En el **Administrador de archivos** de hPanel, ir al directorio que contiene `public_html` (o al propio `public_html`).
2. Comprimir **todo** `public_html` (ZIP o tar según permita el panel).
3. **Descargar** el archivo comprimido a tu equipo.
4. No borrar el backup del servidor hasta completar la verificación posterior.

### 1.3 Comprobar el backup de archivos

Antes de seguir, abrir/inspeccionar el ZIP de respaldo y confirmar que incluye:

- [ ] `config.local.php` (configuración local de producción)
- [ ] `.htaccess`
- [ ] `assets/images/products/` (imágenes de productos subidas)
- [ ] Código PHP actual (`index.php`, `cart.php`, `includes/`, etc.)

### 1.4 Regla de parada

**No continuar** si falla el export SQL, la compresión/descarga de `public_html`, o si el backup no contiene alguno de los ítems anteriores.

---

## 2. Instalación sobre `public_html`

### 2.1 Qué no hacer

- **No** borrar ni vaciar `public_html`.
- **No** importar SQL.
- **No** sobrescribir ni eliminar `config.local.php`.
- **No** borrar `assets/images/products/` ni otros uploads.

### 2.2 Subida y extracción

1. Subir `cyberleo-hostinger.zip` a un directorio temporal del hosting (por ejemplo dentro de `public_html` o al home de la cuenta), o usarlo directamente desde el Administrador de archivos.
2. Si Hostinger permite comprobar integridad, verificar el **SHA-256**:
   ```
   f4d382d5a89d8d9add4e26be11395a60fcdadadf6922a3ae76ff54915796756a
   ```
3. **Extraer** el ZIP **directamente sobre** `public_html`, conservando las rutas relativas del archivo (por ejemplo `includes/public_nav.php` → `public_html/includes/public_nav.php`).
4. Cuando el panel pregunte, **permitir sobrescribir** los archivos de código incluidos en el ZIP.
5. Tras la extracción, **eliminar** del servidor el ZIP subido si quedó dentro de `public_html` (no dejar el paquete públicamente descargable).

### 2.3 Confirmaciones post-extracción

Comprobar que siguen presentes y no fueron reemplazados por el ZIP:

- [ ] `config.local.php` (el paquete **no** lo incluye)
- [ ] Imágenes de productos en `assets/images/products/`
- [ ] Otros archivos subidos / directorios operativos no listados en el ZIP

Archivos nuevos/actualizados relevantes de este refinamiento (deben existir tras la extracción):

- `includes/public_nav.php`
- `includes/admin_nav.php`
- `components/admin_nav.php`
- `components/nav.php`
- `components/benefits.php`
- `components/footer.php`

---

## 3. Caché

Tras sobrescribir código PHP/CSS/JS, limpiar caché para ver el refinamiento:

1. **Hostinger / LiteSpeed**  
   En hPanel: sección de caché del sitio (LiteSpeed Cache / Cache Manager) → **Flush / Purge All** (o equivalente).  
   No borrar a mano archivos internos del plugin de caché.

2. **PHP / OPcache**  
   Si hPanel ofrece reinicio de PHP o “Reset OPcache”, usarlo.  
   No eliminar directorios del sistema PHP manualmente.

3. **Navegador**  
   Recarga forzada (Ctrl/Cmd+Shift+R) o ventana privada para la verificación.

---

## 4. Verificación posterior (checklist)

### 4.1 Público

- [ ] Portada responde **HTTP 200**
- [ ] Menú de navegación uniforme (Inicio / categorías / carrito)
- [ ] Menú móvil: abre y cierra; `aria-expanded` coherente
- [ ] Categorías del menú conservadas (dinámicas desde la base)
- [ ] Sección Beneficios: superficie blanca y tres tarjetas en escritorio
- [ ] Beneficios apilados correctamente a **~390px** de ancho
- [ ] Footer navy (colores de tema)
- [ ] Enlaces Instagram y WhatsApp visibles/funcionan si están habilitados
- [ ] Carrito vacío carga correctamente
- [ ] Página de categoría carga correctamente
- [ ] Consola del navegador **sin errores** de recursos propios
- [ ] Sin overflow horizontal en 1440px y 390px

### 4.2 Administrativo y datos

- [ ] Acceso a la URL del panel
- [ ] Login administrativo funciona
- [ ] Navegación admin uniforme entre páginas autenticadas
- [ ] `config.local.php` sigue disponible y con permisos restrictivos (solo lectura para el dueño / no world-writable)
- [ ] Imágenes de productos siguen presentes
- [ ] La tienda mantiene conexión con la base (listados, categorías, settings)
- [ ] Pedidos y carrito sin regresiones aparentes (añadir al carrito, ver carrito; no forzar pedido real de prueba en producción salvo acuerdo explícito)

---

## 5. Rollback inmediato

Si aparecen errores PHP, **HTTP 500**, fallos de conexión a la base, recursos faltantes o rotura visible del storefront:

1. **Detener** la prueba (no seguir “arreglando” a ciegas sobre producción).
2. **Restaurar** el backup completo de `public_html` descargado en el paso 1.2 (extraer/sobrescribir el árbol anterior).
3. **No restaurar la base** salvo que se haya modificado accidentalmente (este refinamiento no requiere SQL).
4. **Limpiar caché** (Hostinger/LiteSpeed, OPcache si aplica, navegador).
5. Repetir verificaciones básicas: portada 200, login admin, una categoría, carrito vacío.

---

## 6. Límites (recordatorio)

- No vaciar `public_html`.
- No importar SQL por este despliegue.
- No compartir ni pegar credenciales en tickets/chats.
- No eliminar `config.local.php` ni uploads.
- Si algo falla en el respaldo, **no desplegar**.
