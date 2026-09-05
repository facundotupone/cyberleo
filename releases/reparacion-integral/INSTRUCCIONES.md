# Reparación integral — refinamiento + versionado seguro

Paquete completo (55 rutas) para `public_html`. Incluye nav/footer/beneficios,
`asset_version`, `asset_safe_url`, CSS y admin login endurecido.

SHA-256: `9a56b8a107365df81e462aff4f4e7462e077854caab36b29f6e64df1e977296c`

1. Backup de `public_html` + DB.
2. Extraer sobre `public_html` (sobrescribir; sin carpeta contenedora).
3. Conservar `config.local.php` e imágenes.
4. Purge LiteSpeed + hard refresh.
5. Verificar: login 200, `style.css?v=`, `meta cyberleo-release`, footer `.site-footer` sin `.footer-banner`.
