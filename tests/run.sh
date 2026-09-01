#!/usr/bin/env bash
# Prueba de integración de la migración contra una instancia MariaDB efímera.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DB="cyberleo_migration_test"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cyberleo-mariadb.XXXXXX")"
DATA_DIR="$WORK_DIR/data"
SOCKET="$WORK_DIR/mariadb.sock"
PID_FILE="$WORK_DIR/mariadb.pid"
LOG_FILE="$WORK_DIR/mariadb.log"
SERVER_PID=""
MYSQL=(mysql --protocol=socket --socket="$SOCKET" -uroot)

cleanup() {
    local status=$?
    if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
        "${MYSQL[@]}" -e 'SHUTDOWN' >/dev/null 2>&1 || kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    if [[ $status -ne 0 && -f "$LOG_FILE" ]]; then
        printf '\nMariaDB log (%s):\n' "$LOG_FILE" >&2
        sed -n '1,160p' "$LOG_FILE" >&2
    fi
    rm -rf "$WORK_DIR"
    exit "$status"
}
trap cleanup EXIT INT TERM

require_command() {
    command -v "$1" >/dev/null 2>&1 || {
        printf 'Falta el prerrequisito: %s\n' "$1" >&2
        exit 1
    }
}

for command in php mysql mysqladmin mariadbd mariadb-install-db; do
    require_command "$command"
done
php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' || {
    printf 'Falta la extensión PHP pdo_mysql.\n' >&2
    exit 1
}

printf 'Lint PHP...\n'
while IFS= read -r -d '' file; do
    php -l "$ROOT/$file" >/dev/null
done < <(git -C "$ROOT" ls-files -z '*.php')

mkdir -p "$DATA_DIR"
mariadb-install-db --no-defaults --datadir="$DATA_DIR" \
    --auth-root-authentication-method=normal >/dev/null
mariadbd --no-defaults --datadir="$DATA_DIR" --socket="$SOCKET" \
    --pid-file="$PID_FILE" --log-error="$LOG_FILE" --skip-networking &
SERVER_PID=$!

for _ in {1..50}; do
    if mysqladmin --protocol=socket --socket="$SOCKET" -uroot ping --silent >/dev/null 2>&1; then
        break
    fi
    sleep 0.1
done
mysqladmin --protocol=socket --socket="$SOCKET" -uroot ping --silent >/dev/null

scalar() {
    "${MYSQL[@]}" --skip-column-names --batch -D "$TEST_DB" -e "$1"
}

assert_scalar() {
    local expected=$1
    local query=$2
    local description=$3
    local actual
    actual="$(scalar "$query")"
    if [[ "$actual" != "$expected" ]]; then
        printf 'Falló %s: esperado <%s>, recibido <%s>\n' \
            "$description" "$expected" "$actual" >&2
        exit 1
    fi
}

assert_schema() {
    local fixture_name=$1
    assert_scalar 7 \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('products', 'orders', 'order_items', 'store_settings', 'categories', 'order_rate_limits', 'auth_rate_limits')" \
        "$fixture_name: tablas requeridas"
    assert_scalar 1 \
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'stock' AND column_type = 'int(11)' AND is_nullable = 'NO'" \
        "$fixture_name: products.stock"
    assert_scalar 1 \
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'is_active' AND column_type = 'tinyint(1)' AND is_nullable = 'NO'" \
        "$fixture_name: products.is_active"
    assert_scalar 1 \
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'idempotency_key' AND column_type = 'char(64)'" \
        "$fixture_name: orders.idempotency_key"
    assert_scalar 1 \
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'expires_at' AND column_type = 'datetime'" \
        "$fixture_name: orders.expires_at"
    assert_scalar 1 \
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'PRIMARY' AND column_name = 'id'" \
        "$fixture_name: índice primario de orders"
    assert_scalar 1 "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='uq_orders_idempotency_key' AND non_unique=0" "$fixture_name: unique idempotency"
    assert_scalar 1 "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='idx_orders_status_expires' AND column_name='status' AND seq_in_index=1" "$fixture_name: index status"
    assert_scalar 1 "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='idx_orders_status_expires' AND column_name='expires_at' AND seq_in_index=2" "$fixture_name: index expires"
    assert_scalar 1 \
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'status' AND seq_in_index = 1" \
        "$fixture_name: índice por estado de orders"
    assert_scalar 1 \
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'store_settings' AND index_name = 'PRIMARY' AND column_name = 'setting_key'" \
        "$fixture_name: índice primario de store_settings"
}

run_fixture() {
    local fixture=$1
    local fixture_name
    fixture_name="$(basename "$fixture" .sql)"

    printf 'Probando %s...\n' "$fixture_name"
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$TEST_DB\`; CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4;"
    "${MYSQL[@]}" "$TEST_DB" < "$fixture"

    DB_HOST="localhost;unix_socket=$SOCKET" DB_NAME="$TEST_DB" DB_USER=root DB_PASS='' \
        php "$ROOT/migrations/001_add_orders_stock_settings.php"
    if [[ "$fixture_name" == "pre_5c8bdb2_orders" ]]; then
        assert_scalar 3 "SELECT COUNT(*) FROM orders" "legacy orders preserved"
        assert_scalar 3 "SELECT COUNT(DISTINCT idempotency_key) FROM orders WHERE CHAR_LENGTH(idempotency_key)=64 AND expires_at IS NOT NULL" "legacy keys and expiry"
        assert_scalar 0 "SELECT COUNT(*) FROM orders WHERE idempotency_key = SHA2(CONCAT('legacy-order-', id), 256)" "legacy keys are not predictable"
        assert_scalar 7 "SELECT stock FROM products WHERE id=1" "legacy stock preserved"
    fi
    DB_HOST="localhost;unix_socket=$SOCKET" DB_NAME="$TEST_DB" DB_USER=root DB_PASS='' \
        php "$ROOT/migrations/001_add_orders_stock_settings.php"

    assert_schema "$fixture_name"
}

run_fixture "$ROOT/tests/fixtures/legacy_without_orders.sql"
run_fixture "$ROOT/tests/fixtures/pre_5c8bdb2_orders.sql"

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$TEST_DB\`; CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4;"
"${MYSQL[@]}" "$TEST_DB" < "$ROOT/schema.sql"
TEST_DSN="mysql:unix_socket=$SOCKET;dbname=$TEST_DB;charset=utf8mb4" DB_USER=root DB_PASS='' \
    php "$ROOT/tests/image_deletion_test.php"
TEST_DSN="mysql:unix_socket=$SOCKET;dbname=$TEST_DB;charset=utf8mb4" DB_USER=root DB_PASS='' \
    php "$ROOT/tests/image_deletion_regression_test.php"
TEST_DSN="mysql:unix_socket=$SOCKET;dbname=$TEST_DB;charset=utf8mb4" DB_USER=root DB_PASS='' \
    php "$ROOT/tests/image_upload_settings_test.php"

TEST_DB_SOCKET="$SOCKET" TEST_DB_NAME="$TEST_DB" \
    "$ROOT/tests/run_http.sh"

printf 'OK: lint, migración, imágenes y pruebas HTTP verificados.\n'
