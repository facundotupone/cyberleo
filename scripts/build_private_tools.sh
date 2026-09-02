#!/usr/bin/env bash
# Genera cyberleo-private-tools.zip (fuera de public_html).
# Valida autosuficiencia: php -l, grafo require/include, y runtime opcional.
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
    includes/config.php
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
PUBLIC_VERIFY="$WORK_DIR/public_extracted"
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
        */config.local.php|config.local.php|.env|.env.*|tests/*|dist/*|*.log|logs/*|backups/*|.git/*|*.sha256)
            printf 'Artefacto prohibido en ZIP privado: %s\n' "$relative" >&2
            exit 1
            ;;
    esac
done

# php -l sobre todos los PHP del ZIP extraído (solo árbol extraído).
while IFS= read -r -d '' phpfile; do
    php -l "$phpfile" >/dev/null || {
        printf 'php -l falló en el ZIP privado: %s\n' "$phpfile" >&2
        exit 1
    }
done < <(find "$VERIFY_DIR" -type f -name '*.php' -print0)

# Dependencias literales require/include dentro del paquete privado.
php -r '
$root = $argv[1];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$missing = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), ".php")) {
        continue;
    }
    $src = file_get_contents($file->getPathname());
    if ($src === false) {
        fwrite(STDERR, "No se pudo leer {$file->getPathname()}\n");
        exit(1);
    }
    if (!preg_match_all("/\\b(?:require|include)(?:_once)?\\s*(\\([^;]+?\\)|[^;(][^;]*);/m", $src, $matches)) {
        continue;
    }
    foreach ($matches[1] as $expr) {
        $expr = trim($expr);
        if (str_starts_with($expr, "(") && str_ends_with($expr, ")")) {
            $expr = trim(substr($expr, 1, -1));
        }
        // Solo resolver rutas literales del paquete privado.
        if (!preg_match("/dirname\\(__DIR__\\)\\s*\\.\\s*[\"'\\'''](\\/[^\"'\\''']+)[\"'\\''']/", $expr, $m)
            && !preg_match("/__DIR__\\s*\\.\\s*[\"'\\'''](\\/[^\"'\\''']+)[\"'\\''']/", $expr, $m)
            && !preg_match("/dirname\\(__DIR__\\)\\s*\\.\\s*[\"'\\'''](\\/[^\"'\\''']+)[\"'\\''']\\s*\\.\\s*[\"'\\'''](\\/[^\"'\\''']+)[\"'\\''']/", $expr, $m2)
        ) {
            // Cargas desde \$publicRoot / --root se validan en runtime.
            if (str_contains($expr, "\$publicRoot") || str_contains($expr, "\$realRoot") || str_contains($expr, "\$root")) {
                continue;
            }
            continue;
        }
        $rel = $m[1] ?? "";
        if (isset($m2) && $m2) {
            $rel = ($m2[1] ?? "") . ($m2[2] ?? "");
        }
        // Mapear desde el archivo actual.
        $from = $file->getPathname();
        if (preg_match("/dirname\\(__DIR__\\)/", $expr)) {
            $base = dirname(dirname($from));
        } else {
            $base = dirname($from);
        }
        // Re-parse simpler known patterns used in this package:
        if (preg_match("/dirname\\(__DIR__\\)\\s*\\.\\s*[\"'\\'''](\\/scripts\\/lib\\/maintenance\\.php)[\"'\\''']/", $expr, $mm)
            || preg_match("/__DIR__\\s*\\.\\s*[\"'\\'''](\\/lib\\/maintenance\\.php)[\"'\\''']/", $expr, $mm)
            || preg_match("/dirname\\(__DIR__\\)\\s*\\.\\s*[\"'\\'''](\\/includes\\/config\\.php)[\"'\\''']/", $expr, $mm)
        ) {
            $target = $base . $mm[1];
            if (!is_file($target)) {
                $missing[] = substr($from, strlen($root) + 1) . " -> " . $mm[1];
            }
        }
    }
}
// Explicit known private-package edges (authoritative).
$edges = [
    "migrations/001_add_orders_stock_settings.php" => "includes/config.php",
    "cron/expire_reservations.php" => "scripts/lib/maintenance.php",
    "scripts/backup_store.php" => "scripts/lib/maintenance.php",
    "scripts/restore_store.php" => "scripts/lib/maintenance.php",
    "scripts/install_store.php" => "scripts/lib/maintenance.php",
    "scripts/diagnose_store.php" => "scripts/lib/maintenance.php",
];
foreach ($edges as $from => $to) {
    if (!is_file($root . "/" . $from)) {
        fwrite(STDERR, "Falta origen en ZIP: {$from}\n");
        exit(1);
    }
    if (!is_file($root . "/" . $to)) {
        fwrite(STDERR, "Dependencia privada ausente en ZIP: {$from} requiere {$to}\n");
        exit(1);
    }
}
if ($missing !== []) {
    fwrite(STDERR, implode("\n", $missing) . "\n");
    exit(1);
}
echo "OK dependencias privadas\n";
' "$VERIFY_DIR"

# Prohibir config.local / secretos literales.
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
if [[ -f "$VERIFY_DIR/includes/config.local.php" ]]; then
    printf 'config.local.php no debe ir en el ZIP privado.\n' >&2
    exit 1
fi

# Runtime con ZIP privado extraído + release público extraído (sin scripts del repo).
if [[ -n "${TEST_DB_SOCKET:-}" && -n "${TEST_DB_NAME:-}" ]]; then
    HOSTINGER_ZIP="$DIST_DIR/cyberleo-hostinger.zip"
    if [[ ! -f "$HOSTINGER_ZIP" ]]; then
        bash "$ROOT/scripts/build_hostinger_release.sh"
    fi
    mkdir -p "$PUBLIC_VERIFY"
    unzip -q "$HOSTINGER_ZIP" -d "$PUBLIC_VERIFY"

    # Instalar en el public extraído usando SOLO scripts del ZIP privado.
    DB_HOST="localhost;unix_socket=${TEST_DB_SOCKET}" \
    DB_NAME="${TEST_DB_NAME}_privpkg" \
    DB_USER="${TEST_DB_USER:-root}" \
    DB_PASS="${TEST_DB_PASS:-}" \
    STORE_NAME="PrivatePkg" \
    SITE_URL="http://localhost:8000" \
    WHATSAPP_NUMBER="5491100000000" \
    ADMIN_USERNAME="privadmin" \
    ADMIN_EMAIL="priv@example.test" \
    ADMIN_PASSWORD="password-segura-12" \
    php -r '
        $socket = getenv("TEST_DB_SOCKET");
        $name = getenv("DB_NAME");
        $pdo = new PDO("mysql:unix_socket=".$socket.";charset=utf8mb4", getenv("DB_USER") ?: "root", getenv("DB_PASS") ?: "", [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `".str_replace("`","``",$name)."`");
        $pdo->exec("CREATE DATABASE `".str_replace("`","``",$name)."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    '
    DB_HOST="localhost;unix_socket=${TEST_DB_SOCKET}" \
    DB_NAME="${TEST_DB_NAME}_privpkg" \
    DB_USER="${TEST_DB_USER:-root}" \
    DB_PASS="${TEST_DB_PASS:-}" \
    STORE_NAME="PrivatePkg" \
    SITE_URL="http://localhost:8000" \
    WHATSAPP_NUMBER="5491100000000" \
    ADMIN_USERNAME="privadmin" \
    ADMIN_EMAIL="priv@example.test" \
    ADMIN_PASSWORD="password-segura-12" \
    php "$VERIFY_DIR/scripts/install_store.php" --public-root="$PUBLIC_VERIFY" --non-interactive

    # Migración: solo desde ZIP privado + env (config.php del ZIP, sin config.local).
    DB_HOST="localhost;unix_socket=${TEST_DB_SOCKET}" \
    DB_NAME="${TEST_DB_NAME}_privpkg" \
    DB_USER="${TEST_DB_USER:-root}" \
    DB_PASS="${TEST_DB_PASS:-}" \
    php "$VERIFY_DIR/migrations/001_add_orders_stock_settings.php"

    # Cron: --public-root del release público extraído.
    php "$VERIFY_DIR/cron/expire_reservations.php" --public-root="$PUBLIC_VERIFY" >/dev/null

    # Verify images: deps solo desde public root.
    php "$VERIFY_DIR/scripts/verify_production_images.php" --root="$PUBLIC_VERIFY" >/dev/null

    # Negativos: quitar cada dependencia privada crítica y comprobar fallo.
    for dep in includes/config.php scripts/lib/maintenance.php; do
        backup="$WORK_DIR/neg-$(basename "$dep")"
        mv "$VERIFY_DIR/$dep" "$backup"
        set +e
        case "$dep" in
            includes/config.php)
                DB_HOST="localhost;unix_socket=${TEST_DB_SOCKET}" \
                DB_NAME="${TEST_DB_NAME}_privpkg" \
                DB_USER="${TEST_DB_USER:-root}" \
                DB_PASS="${TEST_DB_PASS:-}" \
                php "$VERIFY_DIR/migrations/001_add_orders_stock_settings.php" >/dev/null 2>&1
                code=$?
                ;;
            scripts/lib/maintenance.php)
                php "$VERIFY_DIR/cron/expire_reservations.php" --public-root="$PUBLIC_VERIFY" >/dev/null 2>&1
                code=$?
                ;;
        esac
        set -e
        mv "$backup" "$VERIFY_DIR/$dep"
        [[ "$code" -ne 0 ]] || {
            printf 'Negativo falló: se esperaba error sin %s\n' "$dep" >&2
            exit 1
        }
    done

    # Negativo verify: quitar images.php del public extraído.
    mv "$PUBLIC_VERIFY/includes/images.php" "$WORK_DIR/images.php.bak"
    set +e
    php "$VERIFY_DIR/scripts/verify_production_images.php" --root="$PUBLIC_VERIFY" >/dev/null 2>&1
    code=$?
    set -e
    mv "$WORK_DIR/images.php.bak" "$PUBLIC_VERIFY/includes/images.php"
    [[ "$code" -ne 0 ]] || {
        printf 'Negativo verify falló: se esperaba error sin images.php público\n' >&2
        exit 1
    }

    printf 'OK runtime privado (migración/cron/verify + negativos)\n'
fi

archive_hash="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
printf 'OK: %s\n' "$ARCHIVE"
printf 'Archivos: %d\n' "${#archive_files[@]}"
printf 'Tamaño: %s bytes\n' "$(stat -c %s "$ARCHIVE")"
printf 'SHA-256: %s\n' "$archive_hash"
