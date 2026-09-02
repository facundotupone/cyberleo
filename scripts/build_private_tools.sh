#!/usr/bin/env bash
# Genera cyberleo-private-tools.zip (fuera de public_html).
set -euo pipefail
export LC_ALL=C
export TZ=UTC

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT/dist"
ARCHIVE="$DIST_DIR/cyberleo-private-tools.zip"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-315532800}"

for command in git php zip unzip sha256sum touch rg install stat; do
    command -v "$command" >/dev/null 2>&1 || {
        printf 'Falta el prerrequisito: %s\n' "$command" >&2
        exit 1
    }
done
[[ "$SOURCE_DATE_EPOCH" =~ ^[0-9]+$ ]] || {
    printf 'SOURCE_DATE_EPOCH debe ser un entero Unix.\n' >&2
    exit 1
}

FILES=(
    cron/expire_reservations.php
    docs/BACKUP_RESTORE.md
    docs/DEPLOY_HOSTINGER.md
    docs/INSTALL_NEW_STORE.md
    includes/config.example.php
    migrations/001_add_orders_stock_settings.php
    schema.sql
    scripts/backup_store.php
    scripts/diagnose_store.php
    scripts/install_store.php
    scripts/lib/maintenance.php
    scripts/restore_store.php
    scripts/verify_production_images.php
)

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cyberleo-private.XXXXXX")"
STAGE_DIR="$WORK_DIR/stage"
VERIFY_DIR="$WORK_DIR/extracted"
TMP_ARCHIVE="$WORK_DIR/private.zip"
MANIFEST="$WORK_DIR/manifest.txt"
cleanup() { rm -rf "$WORK_DIR"; }
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

while IFS= read -r relative; do
    touch -d "@$SOURCE_DATE_EPOCH" "$STAGE_DIR/$relative"
done < "$MANIFEST"

(
    cd "$STAGE_DIR"
    zip -X -q "$TMP_ARCHIVE" -@ < "$MANIFEST"
)
mv -f "$TMP_ARCHIVE" "$ARCHIVE"

unzip -q "$ARCHIVE" -d "$VERIFY_DIR"
mapfile -t archive_files < <(unzip -Z1 "$ARCHIVE")
[[ "${#archive_files[@]}" -eq "${#FILES[@]}" ]] || {
    printf 'Cantidad inesperada de archivos en el ZIP privado.\n' >&2
    exit 1
}

for relative in "${archive_files[@]}"; do
    [[ "$relative" != /* && "$relative" != ../* && "$relative" != */../* ]] || {
        printf 'Ruta insegura en el ZIP privado: %s\n' "$relative" >&2
        exit 1
    }
    case "$relative" in
        */config.local.php|config.local.php|.env|.env.*|tests/*|dist/*|*.log|logs/*|backups/*|.git/*)
            printf 'Artefacto prohibido en ZIP privado: %s\n' "$relative" >&2
            exit 1
            ;;
    esac
done

secret_hits="$(rg --pcre2 -n --hidden \
    'define\(['"'"'"](APP_SECRET|DB_PASS)['"'"'"],[[:space:]]*['"'"'"][^'"'"'"]+['"'"'"]\)|BEGIN (RSA |OPENSSH )?PRIVATE KEY' \
    "$VERIFY_DIR" || true)"
if [[ -n "$secret_hits" ]]; then
    filtered="$(printf '%s\n' "$secret_hits" | rg -v 'config\.example\.php|docs/' || true)"
    if [[ -n "$filtered" ]]; then
        printf '%s\n' "$filtered" >&2
        printf 'Posible secreto literal en ZIP privado.\n' >&2
        exit 1
    fi
fi

archive_hash="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
printf 'OK: %s\n' "$ARCHIVE"
printf 'Archivos: %d\n' "${#archive_files[@]}"
printf 'Tamaño: %s bytes\n' "$(stat -c %s "$ARCHIVE")"
printf 'SHA-256: %s\n' "$archive_hash"
