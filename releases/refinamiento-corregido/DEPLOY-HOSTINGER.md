# Despliegue — refinamiento corregido

Paquete: `cyberleo-refinamiento-corregido.zip`  
Verificar SHA-256 en `SHA256SUMS.txt` (valor actual tras auditoría: `9a56b8a107365df81e462aff4f4e7462e077854caab36b29f6e64df1e977296c`).

## Diagnóstico previo

El ZIP original de refinamiento (`cyberleo-hostinger-refinamiento.zip`) **coincidía byte a byte** con `main@d90cc6b`.  
En producción se observó publicación parcial (nav nueva + footer/CSS viejos). Este paquete vuelve a publicar el refinamiento completo y agrega `?v=` por hash de contenido para forzar recarga de CSS/JS.

## Respaldo

1. Exportar la base desde phpMyAdmin (por seguridad; este ZIP **no** cambia SQL).
2. Comprimir y descargar el `public_html` actual.
3. Confirmar que el backup incluye `config.local.php`, `.htaccess`, `assets/images/products` y el PHP actual.
4. No continuar si el backup falla.

## Instalación

1. **No** vaciar `public_html`.
2. Subir `cyberleo-refinamiento-corregido.zip` al hosting.
3. Extraerlo **directamente sobre** `public_html` (las rutas del ZIP empiezan en `.htaccess`, `index.php`, `assets/...`, sin carpeta contenedora).
4. Permitir **sobrescribir** archivos existentes.
5. Comprobar que **no** quedó una carpeta extra del tipo `public_html/cyberleo-refinamiento-corregido/`.
   - Correcto: `public_html/assets/css/style.css`
   - Incorrecto: `public_html/alguna-carpeta/assets/css/style.css`
6. Conservar `config.local.php`, imágenes de productos y uploads.
7. No importar SQL.

## Comprobación rápida post-extracción

En el código fuente de la portada (ver HTML):

- Debe aparecer `assets/css/style.css?v=`
- Debe aparecer `meta name="cyberleo-release" content="refinamiento-hotfix-20260905"`
- El footer debe tener `class="footer site-footer"` y `.site-footer-grid`
- No debe haber `.footer-banner`

## Caché

1. Hostinger / LiteSpeed: **Purge / Flush All**.
2. OPcache / reinicio PHP si hPanel lo ofrece.
3. Recarga forzada del navegador (Ctrl/Cmd+Shift+R) o ventana privada.

No borrar a mano archivos internos del plugin de caché.

## Rollback

1. Detener si hay HTTP 500 o layout roto.
2. Restaurar el backup de `public_html`.
3. No restaurar la base (no fue modificada por este paquete).
4. Limpiar caché y revalidar portada.
