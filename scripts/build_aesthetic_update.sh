#!/usr/bin/env bash
# Genera cyberleo-actualizacion-estetica.zip incremental (06c4848..HEAD).
set -euo pipefail
export LC_ALL=C
export TZ=UTC

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT/dist"
ARCHIVE="$DIST_DIR/cyberleo-actualizacion-estetica.zip"
BASE_COMMIT="${BASE_COMMIT:-06c4848}"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-315532800}"

for command in git zip unzip sha256sum touch install rg; do
    command -v "$command" >/dev/null 2>&1 || {
        printf 'Falta el prerrequisito: %s\n' "$command" >&2
        exit 1
    }
done

mapfile -t CANDIDATES < <(
    git -C "$ROOT" diff --name-only --diff-filter=ACMR "${BASE_COMMIT}..HEAD" | sort -u
)

FILES=()
for relative in "${CANDIDATES[@]}"; do
    case "$relative" in
        tests/*|migrations/*|docs/*|cron/*|scripts/*|schema.sql|README*|DESIGN_CHANGES.md|\
        .env|.env.*|includes/config.local.php|*.log|logs/*|dist/*|backups/*|artifacts/*|\
        .git/*|.gitignore|.gitattributes|*.sql|*.b64|*.md)
            continue
            ;;
    esac
    # Solo productivos bajo public_html.
    case "$relative" in
        admin_*.php|*.php|assets/*|components/*|includes/*|.htaccess)
            [[ -f "$ROOT/$relative" && ! -L "$ROOT/$relative" ]] || continue
            # Excluir herramientas privadas y ejemplos.
            case "$relative" in
                includes/config.example.php|includes/config.local.php) continue ;;
            esac
            FILES+=("$relative")
            ;;
    esac
done

[[ "${#FILES[@]}" -gt 0 ]] || {
    printf 'No hay archivos productivos para el ZIP incremental.\n' >&2
    exit 1
}

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cyberleo-estetica.XXXXXX")"
STAGE_DIR="$WORK_DIR/stage"
VERIFY_DIR="$WORK_DIR/extracted"
TMP_ARCHIVE="$WORK_DIR/estetica.zip"
MANIFEST="$WORK_DIR/manifest.txt"
cleanup() { rm -rf "$WORK_DIR"; }
trap cleanup EXIT
mkdir -p "$STAGE_DIR" "$VERIFY_DIR" "$DIST_DIR"

printf '%s\n' "${FILES[@]}" | sort -u > "$MANIFEST"
mapfile -t FILES < "$MANIFEST"

while IFS= read -r relative; do
    install -D -m 0644 "$ROOT/$relative" "$STAGE_DIR/$relative"
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
    printf 'Cantidad inesperada en ZIP estético.\n' >&2
    exit 1
}

for relative in "${archive_files[@]}"; do
    case "$relative" in
        tests/*|scripts/*|schema.sql|*/config.local.php|*.sql|docs/*|migrations/*|cron/*)
            printf 'Prohibido en ZIP estético: %s\n' "$relative" >&2
            exit 1
            ;;
    esac
done

archive_hash="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
printf 'OK: %s\n' "$ARCHIVE"
printf 'Archivos: %d\n' "${#archive_files[@]}"
printf 'Tamaño: %s bytes\n' "$(stat -c%s "$ARCHIVE")"
printf 'SHA-256: %s\n' "$archive_hash"
