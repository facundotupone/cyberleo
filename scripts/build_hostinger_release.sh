#!/usr/bin/env bash
set -euo pipefail
export LC_ALL=C
export TZ=UTC

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT/dist"
ARCHIVE="$DIST_DIR/cyberleo-hostinger.zip"
CHECKSUM="$ARCHIVE.sha256"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-315532800}"

for command in git php zip unzip sha256sum touch rg install stat; do
    command -v "$command" >/dev/null 2>&1 || {
        printf 'Falta el prerrequisito: %s\n' "$command" >&2
        exit 1
    }
done
git -C "$ROOT" diff --check
if [[ "${RUN_TESTS:-0}" == "1" ]]; then
    bash "$ROOT/tests/run.sh"
fi
[[ "$SOURCE_DATE_EPOCH" =~ ^[0-9]+$ ]] || {
    printf 'SOURCE_DATE_EPOCH debe ser un entero Unix.\n' >&2
    exit 1
}
(( SOURCE_DATE_EPOCH >= 315532800 )) || {
    printf 'SOURCE_DATE_EPOCH debe ser 1980-01-01 o posterior para ZIP.\n' >&2
    exit 1
}

# Allowlist cerrada: sólo archivos necesarios para atender tráfico web.
FILES=(
    .htaccess
    admin_categories.php
    admin_login.php
    admin_orders.php
    admin_products.php
    admin_settings.php
    assets/css/backgrounds.css
    assets/css/style.css
    assets/images/brand/cyberleo-logo.png
    assets/images/products/.htaccess
    assets/images/settings/.htaccess
    assets/js/cart-checkout.js
    assets/js/catalog-cards.js
    assets/js/catalog-preview.js
    assets/js/checkout-preview.js
    assets/js/home-content-preview.js
    assets/js/theme-preview.js
    cart.php
    category.php
    components/announcement.php
    components/benefits.php
    components/footer.php
    components/head.php
    components/home_categories.php
    components/home_featured.php
    components/nav.php
    components/product_card.php
    components/promo_banner.php
    create_order.php
    delete_image.php
    forgot_password.php
    get_subcategories.php
    includes/auth_check.php
    includes/catalog_display.php
    includes/checkout_display.php
    includes/config.php
    includes/db.php
    includes/functions.php
    includes/home_content.php
    includes/images.php
    includes/mailer.php
    includes/orders.php
    includes/security.php
    includes/theme.php
    index.php
    logout.php
    reset_password.php
    search_products.php
)

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cyberleo-release.XXXXXX")"
STAGE_DIR="$WORK_DIR/stage"
VERIFY_DIR="$WORK_DIR/extracted"
TMP_ARCHIVE="$WORK_DIR/release.zip"
MANIFEST="$WORK_DIR/manifest.txt"
cleanup() {
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT
mkdir -p "$STAGE_DIR" "$VERIFY_DIR" "$DIST_DIR"

printf '%s\n' "${FILES[@]}" | sort > "$MANIFEST"
while IFS= read -r relative; do
    source_file="$ROOT/$relative"
    [[ -f "$source_file" && ! -L "$source_file" ]] || {
        printf 'Archivo allowlist ausente o no regular: %s\n' "$relative" >&2
        exit 1
    }
    install -D -m 0644 "$source_file" "$STAGE_DIR/$relative"
done < "$MANIFEST"

# ZIP guarda timestamps con resolución limitada; una época fija evita variación.
while IFS= read -r relative; do
    touch -d "@$SOURCE_DATE_EPOCH" "$STAGE_DIR/$relative"
done < "$MANIFEST"

(
    cd "$STAGE_DIR"
    zip -X -q "$TMP_ARCHIVE" -@ < "$MANIFEST"
)
mv -f "$TMP_ARCHIVE" "$ARCHIVE"

unzip -q "$ARCHIVE" -d "$VERIFY_DIR"
php "$ROOT/tests/release_integrity_test.php" "$VERIFY_DIR"
mapfile -t archive_files < <(unzip -Z1 "$ARCHIVE")
[[ "${#archive_files[@]}" -eq "${#FILES[@]}" ]] || {
    printf 'Cantidad inesperada de archivos en el ZIP.\n' >&2
    exit 1
}

for relative in "${archive_files[@]}"; do
    [[ "$relative" != /* && "$relative" != ../* && "$relative" != */../* ]] || {
        printf 'Ruta insegura en el ZIP: %s\n' "$relative" >&2
        exit 1
    }
    case "$relative" in
        tests/*|migrations/*|docs/*|cron/*|scripts/*|schema.sql|README*|DESIGN_CHANGES.md|\
        .env|.env.*|*/config.local.php|*.log|logs/*)
            printf 'Artefacto prohibido en el ZIP: %s\n' "$relative" >&2
            exit 1
            ;;
    esac
done

if rg --pcre2 -n --hidden \
    'BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{20,}|sk_(live|prod)_[A-Za-z0-9]+|define\(['"'"'"](APP_SECRET|DB_PASS|DB_USER|DB_NAME)['"'"'"],[[:space:]]*['"'"'"][^'"'"'"]+['"'"'"]\)|define\(['"'"'"]DB_HOST['"'"'"],[[:space:]]*['"'"'"](?!localhost)[^'"'"'"]+['"'"'"]\)' \
    "$STAGE_DIR"; then
    printf 'Posible secreto literal detectado en el paquete.\n' >&2
    exit 1
fi

while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null
done < <(printf '%s\0' "${archive_files[@]}" |
    while IFS= read -r -d '' relative; do
        [[ "$relative" == *.php ]] && printf '%s\0' "$VERIFY_DIR/$relative"
    done)

(
    cd "$DIST_DIR"
    sha256sum "$(basename "$ARCHIVE")" > "$(basename "$CHECKSUM")"
)
archive_hash="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
printf 'OK: %s\n' "$ARCHIVE"
printf 'Archivos: %d\n' "${#archive_files[@]}"
printf 'Tamaño: %s bytes\n' "$(stat -c %s "$ARCHIVE")"
printf 'SHA-256: %s\n' "$archive_hash"
