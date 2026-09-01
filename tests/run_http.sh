#!/usr/bin/env bash
# End-to-end HTTP security and behavior tests. A MariaDB socket/database must
# be supplied by tests/run.sh so this suite never touches a developer database.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${TEST_DB_SOCKET:?TEST_DB_SOCKET is required}"
: "${TEST_DB_NAME:?TEST_DB_NAME is required}"

for command in curl php mysql rg base64; do
    command -v "$command" >/dev/null 2>&1 || {
        printf 'Falta el prerrequisito HTTP: %s\n' "$command" >&2
        exit 1
    }
done

HTTP_TMP="$(mktemp -d "${TMPDIR:-/tmp}/cyberleo-http.XXXXXX")"
HTTP_COOKIE="$HTTP_TMP/cookies"
MAIL_LOG="$HTTP_TMP/mail.log"
SERVER_LOG="$HTTP_TMP/php-server.log"
SERVER_PID=""
UPLOAD_DIR="$ROOT/assets/images/products"
mkdir -p "$UPLOAD_DIR"

cleanup() {
    local status=$?
    trap - EXIT INT TERM
    rmdir "$MAIL_LOG" 2>/dev/null || true
    sql 'DROP TRIGGER IF EXISTS http_image_fail' >/dev/null 2>&1 || true
    if declare -p CREATED_IMAGES >/dev/null 2>&1; then
        local image
        for image in "${CREATED_IMAGES[@]}"; do
            [[ -n "$image" ]] && rm -f "$ROOT/$image"
        done
    fi
    if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill -- "-$SERVER_PID" 2>/dev/null || kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    if [[ $status -ne 0 && -s "$SERVER_LOG" ]]; then
        printf '\nPHP server log (%s):\n' "$SERVER_LOG" >&2
        sed -n '1,120p' "$SERVER_LOG" >&2
    fi
    rm -rf "$HTTP_TMP"
    exit "$status"
}
trap cleanup EXIT INT TERM

# shellcheck source=helpers/http.sh
source "$ROOT/tests/helpers/http.sh"

MYSQL=(mysql --protocol=socket --socket="$TEST_DB_SOCKET" -uroot "$TEST_DB_NAME")
sql() {
    "${MYSQL[@]}" --batch --skip-column-names -e "$1"
}
assert_sql() {
    local id=$1 expected=$2 query=$3
    local actual
    actual="$(sql "$query")"
    [[ "$actual" == "$expected" ]] || fail "$id" "SQL <$actual> (esperado <$expected>)"
}

"${MYSQL[@]}" < "$ROOT/tests/fixtures/http_seed.sql"
base64 --decode "$ROOT/tests/fixtures/tiny.png.b64" > "$HTTP_TMP/tiny.png"
printf '<?php echo "not an image"; ?>\n' > "$HTTP_TMP/not-image.php"
php -r '$f=fopen($argv[1],"wb"); ftruncate($f, 5242881); fclose($f);' "$HTTP_TMP/too-large.png"

PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
HTTP_BASE_URL="http://127.0.0.1:$PORT"
export HTTP_TMP HTTP_COOKIE HTTP_BASE_URL

(
    cd "$ROOT"
    exec env \
        PHP_CLI_SERVER_WORKERS=4 \
        APP_SECRET='http-suite-secret-that-is-not-used-outside-tests' \
        SITE_URL="$HTTP_BASE_URL" \
        DB_HOST="localhost;unix_socket=$TEST_DB_SOCKET" \
        DB_NAME="$TEST_DB_NAME" DB_USER=root DB_PASS='' \
        MAIL_TRANSPORT=log MAIL_LOG_PATH="$MAIL_LOG" \
        php -S "127.0.0.1:$PORT"
) >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

for _ in {1..100}; do
    if curl --silent --fail --max-time 1 "$HTTP_BASE_URL/" >/dev/null 2>&1; then
        break
    fi
    kill -0 "$SERVER_PID" 2>/dev/null || fail SERVER "el servidor PHP terminó durante el arranque"
    sleep 0.05
done
curl --silent --fail --max-time 2 "$HTTP_BASE_URL/" >/dev/null ||
    fail SERVER "el servidor PHP no escuchó en $HTTP_BASE_URL"
HTTP_WORKERS="$(pgrep -P "$SERVER_PID" | wc -l | tr -d ' ')"
[[ "$HTTP_WORKERS" -ge 4 ]] || fail SERVER "se iniciaron $HTTP_WORKERS workers; se requieren al menos 4"
printf 'Servidor HTTP listo con %s workers.\n' "$HTTP_WORKERS"

CREATED_IMAGES=()
json_file_value() {
    php -r '
        $data=json_decode(file_get_contents($argv[1]),true);
        if (!is_array($data) || !array_key_exists($argv[2],$data)) exit(2);
        echo $data[$argv[2]];
    ' "$1" "$2"
}
csrf_from_file() {
    php -r '
        $html=file_get_contents($argv[1]);
        if (!preg_match("/name=\"csrf_token\" value=\"([a-f0-9]{64})\"/",$html,$m)) exit(2);
        echo $m[1];
    ' "$1"
}
reset_orders() {
    sql 'SET FOREIGN_KEY_CHECKS=0; TRUNCATE order_rate_limits; TRUNCATE order_items; TRUNCATE orders; SET FOREIGN_KEY_CHECKS=1;'
}
order_curl() {
    local prefix=$1 payload=$2
    curl --silent --show-error --max-time 20 \
        -H 'Content-Type: application/json' --data "$payload" \
        --output "$prefix.body" --write-out '%{http_code}' \
        "$HTTP_BASE_URL/create_order.php" > "$prefix.status"
}
assert_parallel_statuses() {
    local id=$1 expected=$2
    shift 2
    local actual
    actual="$(for prefix in "$@"; do
        printf '%s\n' "$(tr -d '\r\n' < "$prefix.status")"
    done | sort | paste -sd, -)"
    [[ "$actual" == "$expected" ]] || fail "$id" "estados concurrentes <$actual> (esperado <$expected>)"
}
image_file_count() {
    local count=0 path name
    for path in "$UPLOAD_DIR"/*; do
        [[ -e "$path" ]] || continue
        name="${path##*/}"
        [[ "$name" =~ ^[a-f0-9]{32}\.(jpg|png|webp)$ ]] && ((count+=1))
    done
    printf '%s' "$count"
}
cli_env=(
    env APP_SECRET='http-suite-secret-that-is-not-used-outside-tests'
    SITE_URL="$HTTP_BASE_URL"
    DB_HOST="localhost;unix_socket=$TEST_DB_SOCKET"
    DB_NAME="$TEST_DB_NAME" DB_USER=root DB_PASS=''
)

printf 'Pruebas HTTP de pedidos...\n'
reset_orders
sql 'UPDATE products SET stock=8 WHERE id=1'
ORDER_KEY="$(printf 'a%.0s' {1..64})"
ORDER_PAYLOAD="{\"idempotencyKey\":\"$ORDER_KEY\",\"items\":[{\"productId\":1,\"quantity\":2}]}"
ORDER_PREFIXES=()
ORDER_PIDS=()
for worker in {1..4}; do
    prefix="$HTTP_TMP/order01-$worker"
    ORDER_PREFIXES+=("$prefix")
    order_curl "$prefix" "$ORDER_PAYLOAD" &
    ORDER_PIDS+=("$!")
done
for pid in "${ORDER_PIDS[@]}"; do wait "$pid" || fail H-ORDER-01 'curl concurrente falló'; done
assert_parallel_statuses H-ORDER-01 '200,200,200,200' "${ORDER_PREFIXES[@]}"
ORDER_ID="$(json_file_value "${ORDER_PREFIXES[0]}.body" orderId)"
ORDER_URL="$(json_file_value "${ORDER_PREFIXES[0]}.body" whatsappUrl)"
[[ "$ORDER_URL" == https://wa.me/* ]] || fail H-ORDER-01 'whatsappUrl no es una URL wa.me'
for prefix in "${ORDER_PREFIXES[@]}"; do
    [[ "$(json_file_value "$prefix.body" orderId)" == "$ORDER_ID" ]] ||
        fail H-ORDER-01 'las cuatro respuestas no devolvieron el mismo pedido'
    [[ "$(json_file_value "$prefix.body" whatsappUrl)" == "$ORDER_URL" ]] ||
        fail H-ORDER-01 'las cuatro respuestas no devolvieron la misma URL'
done
assert_sql H-ORDER-01 1 "SELECT COUNT(*) FROM orders WHERE idempotency_key='$ORDER_KEY'"
assert_sql H-ORDER-01 1 "SELECT COUNT(*) FROM order_items WHERE order_id=$ORDER_ID AND product_id=1 AND quantity=2"
assert_sql H-ORDER-01 6 'SELECT stock FROM products WHERE id=1'
pass H-ORDER-01

reset_orders
sql 'UPDATE products SET stock=1 WHERE id=1'
ORDER02_A="$(printf 'b%.0s' {1..64})"
ORDER02_B="$(printf 'c%.0s' {1..64})"
order_curl "$HTTP_TMP/order02-a" "{\"idempotencyKey\":\"$ORDER02_A\",\"items\":[{\"productId\":1,\"quantity\":1}]}" & p1=$!
order_curl "$HTTP_TMP/order02-b" "{\"idempotencyKey\":\"$ORDER02_B\",\"items\":[{\"productId\":1,\"quantity\":1}]}" & p2=$!
wait "$p1" || fail H-ORDER-02 'primer curl falló'
wait "$p2" || fail H-ORDER-02 'segundo curl falló'
assert_parallel_statuses H-ORDER-02 '200,422' "$HTTP_TMP/order02-a" "$HTTP_TMP/order02-b"
assert_sql H-ORDER-02 1 'SELECT COUNT(*) FROM orders'
assert_sql H-ORDER-02 1 'SELECT COALESCE(SUM(quantity),0) FROM order_items WHERE product_id=1'
assert_sql H-ORDER-02 0 'SELECT stock FROM products WHERE id=1'
pass H-ORDER-02

reset_orders
sql 'UPDATE products SET stock=2 WHERE id IN (1,2)'
ORDER03_A="$(printf 'd%.0s' {1..64})"
ORDER03_B="$(printf 'e%.0s' {1..64})"
order_curl "$HTTP_TMP/order03-a" "{\"idempotencyKey\":\"$ORDER03_A\",\"items\":[{\"productId\":1,\"quantity\":1},{\"productId\":2,\"quantity\":1}]}" & p1=$!
order_curl "$HTTP_TMP/order03-b" "{\"idempotencyKey\":\"$ORDER03_B\",\"items\":[{\"productId\":2,\"quantity\":1},{\"productId\":1,\"quantity\":1}]}" & p2=$!
wait "$p1" || fail H-ORDER-03 'primer curl falló'
wait "$p2" || fail H-ORDER-03 'segundo curl falló'
assert_parallel_statuses H-ORDER-03 '200,200' "$HTTP_TMP/order03-a" "$HTTP_TMP/order03-b"
assert_sql H-ORDER-03 2 'SELECT COUNT(*) FROM orders'
assert_sql H-ORDER-03 0 'SELECT stock FROM products WHERE id=1'
assert_sql H-ORDER-03 0 'SELECT stock FROM products WHERE id=2'
pass H-ORDER-03

reset_orders
sql 'UPDATE products SET stock=3 WHERE id=1'
request POST create_order.php -H 'Content-Type: application/json' \
    --data "{\"idempotencyKey\":\"$(printf 'f%.0s' {1..64})\",\"items\":[{\"productId\":1,\"quantity\":2}]}"
assert_status H-ORDER-04 200
ORDER04_ID="$(json_value orderId)"
sql "UPDATE orders SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=$ORDER04_ID"
(
    cd "$ROOT"
    "${cli_env[@]}" php -r '
        require "includes/config.php"; require "includes/db.php"; require "includes/orders.php";
        try { echo transition_order($pdo,(int)$argv[1],"cancelled"); }
        catch (RuntimeException $e) { echo "already"; }
    ' "$ORDER04_ID"
) >"$HTTP_TMP/order04-cancel" &
p1=$!
(
    cd "$ROOT"
    "${cli_env[@]}" php cron/expire_reservations.php
) >"$HTTP_TMP/order04-expire" &
p2=$!
wait "$p1" || fail H-ORDER-04 'helper de cancelación falló'
wait "$p2" || fail H-ORDER-04 'helper de expiración falló'
assert_sql H-ORDER-04 1 "SELECT COUNT(*) FROM orders WHERE id=$ORDER04_ID AND status IN ('cancelled','expired')"
assert_sql H-ORDER-04 3 'SELECT stock FROM products WHERE id=1'
pass H-ORDER-04

reset_orders
sql 'UPDATE products SET stock=2 WHERE id=1'
request POST create_order.php -H 'Content-Type: application/json' \
    --data "{\"idempotencyKey\":\"$(printf '1%.0s' {1..64})\",\"items\":[{\"productId\":1,\"quantity\":1}]}"
assert_status H-ORDER-05 200
ORDER05_ID="$(json_value orderId)"
sql "UPDATE orders SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=$ORDER05_ID"
(
    cd "$ROOT"
    "${cli_env[@]}" php cron/expire_reservations.php
) >"$HTTP_TMP/order05-first"
(
    cd "$ROOT"
    "${cli_env[@]}" php cron/expire_reservations.php
) >"$HTTP_TMP/order05-second"
rg --fixed-strings --quiet '1 reservas vencidas liberadas.' "$HTTP_TMP/order05-first" ||
    fail H-ORDER-05 'la primera ejecución del cron no liberó exactamente una reserva'
rg --fixed-strings --quiet '0 reservas vencidas liberadas.' "$HTTP_TMP/order05-second" ||
    fail H-ORDER-05 'la segunda ejecución del cron no fue idempotente'
assert_sql H-ORDER-05 expired "SELECT status FROM orders WHERE id=$ORDER05_ID"
assert_sql H-ORDER-05 2 'SELECT stock FROM products WHERE id=1'
pass H-ORDER-05

printf 'Pruebas HTTP de autenticación...\n'
sql 'TRUNCATE auth_rate_limits'
for attempt in {1..5}; do
    curl --silent --show-error --max-time 15 \
        --cookie "$HTTP_TMP/auth01-$attempt.cookie" --cookie-jar "$HTTP_TMP/auth01-$attempt.cookie" \
        -H "X-Forwarded-For: 198.51.100.$attempt" \
        --data-urlencode 'username=rate-limit-user' --data-urlencode "password=wrong-$attempt" \
        --output "$HTTP_TMP/auth01-$attempt.body" --write-out '%{http_code}' \
        "$HTTP_BASE_URL/admin_login.php" >"$HTTP_TMP/auth01-$attempt.status"
done
HTTP_COOKIE="$HTTP_TMP/auth01-6.cookie"
request POST admin_login.php -H 'X-Forwarded-For: 203.0.113.99' \
    --data-urlencode 'username=rate-limit-user' --data-urlencode 'password=wrong-6'
assert_status H-AUTH-01 429
assert_header_contains H-AUTH-01 'Retry-After: 900'
for attempt in {1..5}; do
    [[ "$(<"$HTTP_TMP/auth01-$attempt.status")" == 200 ]] ||
        fail H-AUTH-01 "el intento $attempt se limitó antes de tiempo"
done
pass H-AUTH-01

sql 'TRUNCATE auth_rate_limits'
rm -f "$MAIL_LOG"
HTTP_COOKIE="$HTTP_TMP/auth02-existing.cookie"
request POST forgot_password.php --data-urlencode 'email=admin-http@example.test'
assert_status H-AUTH-02 302
cp "$HTTP_HEADERS" "$HTTP_TMP/auth02-existing.headers"
cp "$HTTP_BODY" "$HTTP_TMP/auth02-existing.body"
HTTP_COOKIE="$HTTP_TMP/auth02-absent.cookie"
request POST forgot_password.php --data-urlencode 'email=absent-http@example.test'
assert_status H-AUTH-02 302
existing_location="$(rg -i '^Location:' "$HTTP_TMP/auth02-existing.headers" | tr -d '\r')"
absent_location="$(rg -i '^Location:' "$HTTP_HEADERS" | tr -d '\r')"
[[ "$existing_location" == "$absent_location" ]] ||
    fail H-AUTH-02 'usuario existente e inexistente producen redirects distintos'
[[ "$(<"$HTTP_TMP/auth02-existing.body")" == "$(<"$HTTP_BODY")" ]] ||
    fail H-AUTH-02 'usuario existente e inexistente producen cuerpos distintos'
assert_sql H-AUTH-02 64 'SELECT CHAR_LENGTH(reset_token) FROM users WHERE id=1'
assert_sql H-AUTH-02 2 "SELECT COUNT(*) FROM auth_rate_limits"
[[ "$(wc -l < "$MAIL_LOG")" == 1 ]] || fail H-AUTH-02 'el transporte log no registró exactamente un correo'
rg --fixed-strings --quiet '"to":"admin-http@example.test"' "$MAIL_LOG" ||
    fail H-AUTH-02 'el correo no fue dirigido al usuario existente'
rg --fixed-strings --quiet "$HTTP_BASE_URL/reset_password.php?token=" "$MAIL_LOG" ||
    fail H-AUTH-02 'el correo no usa SITE_URL'
pass H-AUTH-02
RESET_TOKEN="$(sql 'SELECT reset_token FROM users WHERE id=1')"

sql 'UPDATE users SET reset_token=NULL,reset_expires=NULL WHERE id=1; TRUNCATE auth_rate_limits'
rm -f "$MAIL_LOG"
mkdir "$MAIL_LOG"
HTTP_COOKIE="$HTTP_TMP/auth03.cookie"
request POST forgot_password.php --data-urlencode 'email=admin-http@example.test'
assert_status H-AUTH-03 302
assert_sql H-AUTH-03 NULL 'SELECT COALESCE(reset_token,"NULL") FROM users WHERE id=1'
rmdir "$MAIL_LOG"
pass H-AUTH-03

sql "UPDATE users SET reset_token='$RESET_TOKEN',reset_expires=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=1"
for client in a b; do
    curl --silent --show-error --max-time 15 \
        --cookie "$HTTP_TMP/auth04-$client.cookie" --cookie-jar "$HTTP_TMP/auth04-$client.cookie" \
        --output "$HTTP_TMP/auth04-$client.get" \
        "$HTTP_BASE_URL/reset_password.php?token=$RESET_TOKEN"
    token="$(csrf_from_file "$HTTP_TMP/auth04-$client.get")"
    password="Concurrent-${client}-Pass!42"
    curl --silent --show-error --max-time 20 \
        --cookie "$HTTP_TMP/auth04-$client.cookie" --cookie-jar "$HTTP_TMP/auth04-$client.cookie" \
        --data-urlencode "csrf_token=$token" --data-urlencode "password=$password" \
        --data-urlencode "password2=$password" \
        --output "$HTTP_TMP/auth04-$client.body" --write-out '%{http_code}' \
        "$HTTP_BASE_URL/reset_password.php?token=$RESET_TOKEN" >"$HTTP_TMP/auth04-$client.status" &
    eval "auth04_pid_$client=$!"
done
wait "$auth04_pid_a" || fail H-AUTH-04 'primer reset concurrente falló'
wait "$auth04_pid_b" || fail H-AUTH-04 'segundo reset concurrente falló'
auth04_statuses="$(for client in a b; do
    printf '%s\n' "$(tr -d '\r\n' < "$HTTP_TMP/auth04-$client.status")"
done | sort | paste -sd, -)"
[[ "$auth04_statuses" == '200,302' || "$auth04_statuses" == '302,400' ]] ||
    fail H-AUTH-04 "resultados de reset concurrente inesperados <$auth04_statuses>"
assert_sql H-AUTH-04 NULL 'SELECT COALESCE(reset_token,"NULL") FROM users WHERE id=1'
winner=a
[[ "$(<"$HTTP_TMP/auth04-a.status")" == 302 ]] || winner=b
WINNING_PASSWORD="Concurrent-${winner}-Pass!42"
PASSWORD_HASH="$(sql 'SELECT password FROM users WHERE id=1')"
php -r 'exit(password_verify($argv[1],$argv[2]) ? 0 : 1);' "$WINNING_PASSWORD" "$PASSWORD_HASH" ||
    fail H-AUTH-04 'la contraseña persistida no corresponde al único reset exitoso'
pass H-AUTH-04

sql 'UPDATE users SET reset_token=NULL,reset_expires=NULL WHERE id=1; TRUNCATE auth_rate_limits'
rm -f "$MAIL_LOG"
HTTP_COOKIE="$HTTP_TMP/auth05.cookie"
request POST forgot_password.php \
    --data-urlencode $'email=admin-http@example.test\r\nBcc: injected@example.test'
assert_status H-AUTH-05 302
assert_sql H-AUTH-05 NULL 'SELECT COALESCE(reset_token,"NULL") FROM users WHERE id=1'
[[ ! -e "$MAIL_LOG" ]] || fail H-AUTH-05 'una dirección con CRLF produjo correo'
pass H-AUTH-05

printf 'Pruebas CSRF de endpoints mutables...\n'
HTTP_COOKIE="$HTTP_TMP/admin.cookie"
request POST admin_login.php --data-urlencode 'username=http-admin' --data-urlencode "password=$WINNING_PASSWORD"
assert_status H-CSRF-LOGIN 302
ADMIN_COOKIE="$HTTP_COOKIE"

sql 'UPDATE products SET stock=4 WHERE id=1'
request POST admin_products.php --data 'action=set_stock&id=1&new_stock=99'
assert_status H-CSRF-PRODUCTS 403
assert_sql H-CSRF-PRODUCTS 4 'SELECT stock FROM products WHERE id=1'
pass H-CSRF-PRODUCTS

request POST 'admin_products.php?action=save_featured_order' \
    -H 'Content-Type: application/json' --data '[{"id":1,"destacados":99}]'
assert_status H-CSRF-FEATURED 403
assert_sql H-CSRF-FEATURED 1 'SELECT destacados FROM products WHERE id=1'
pass H-CSRF-FEATURED

request POST admin_categories.php --data 'action=add_category&name=csrf-created'
assert_status H-CSRF-CATEGORIES 403
assert_sql H-CSRF-CATEGORIES 0 "SELECT COUNT(*) FROM categories WHERE name='csrf-created'"
pass H-CSRF-CATEGORIES

request POST admin_settings.php --data 'store_name=csrf-mutated'
assert_status H-CSRF-SETTINGS 403
assert_sql H-CSRF-SETTINGS 'HTTP Test Store' "SELECT setting_value FROM store_settings WHERE setting_key='store_name'"
pass H-CSRF-SETTINGS

reset_orders
sql 'UPDATE products SET stock=2 WHERE id=1'
request POST create_order.php -H 'Content-Type: application/json' \
    --data "{\"idempotencyKey\":\"$(printf '2%.0s' {1..64})\",\"items\":[{\"productId\":1,\"quantity\":1}]}"
CSRF_ORDER_ID="$(json_value orderId)"
HTTP_COOKIE="$ADMIN_COOKIE"
request POST admin_orders.php --data "order_id=$CSRF_ORDER_ID&status=cancelled"
assert_status H-CSRF-ORDERS 403
assert_sql H-CSRF-ORDERS pending "SELECT status FROM orders WHERE id=$CSRF_ORDER_ID"
assert_sql H-CSRF-ORDERS 1 'SELECT stock FROM products WHERE id=1'
pass H-CSRF-ORDERS

request POST delete_image.php --data 'image_id=999999'
assert_status H-CSRF-IMAGE 403
pass H-CSRF-IMAGE

sql "UPDATE users SET reset_token='$RESET_TOKEN',reset_expires=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=1"
HTTP_COOKIE="$HTTP_TMP/csrf-reset.cookie"
request POST "reset_password.php?token=$RESET_TOKEN" \
    --data 'password=RejectedPass!42&password2=RejectedPass!42'
assert_status H-CSRF-RESET 403
assert_sql H-CSRF-RESET "$RESET_TOKEN" 'SELECT reset_token FROM users WHERE id=1'
pass H-CSRF-RESET

printf 'Pruebas HTTP multipart de imágenes...\n'
HTTP_COOKIE="$ADMIN_COOKIE"
request GET admin_products.php
CSRF_TOKEN="$(csrf_from_body)"

IMAGE_COUNT_BEFORE="$(image_file_count)"
request POST admin_products.php \
    -F "csrf_token=$CSRF_TOKEN" -F 'action=add' -F 'name=HTTP partial batch' \
    -F 'description=must roll back' -F 'price=10.00' -F 'stock=1' \
    -F 'category_id=1' -F 'subcategory_id=1' \
    -F "images[]=@$HTTP_TMP/tiny.png;type=image/png" \
    -F "images[]=@$HTTP_TMP/not-image.php;type=image/png"
assert_status H-IMAGE-01 200
assert_sql H-IMAGE-01 0 "SELECT COUNT(*) FROM products WHERE name='HTTP partial batch'"
assert_sql H-IMAGE-01 0 "SELECT COUNT(*) FROM product_images pi JOIN products p ON p.id=pi.product_id WHERE p.name='HTTP partial batch'"
[[ "$(image_file_count)" == "$IMAGE_COUNT_BEFORE" ]] ||
    fail H-IMAGE-01 'el lote inválido dejó archivos creados antes del fallo'
pass H-IMAGE-01

IMAGE_COUNT_BEFORE="$(image_file_count)"
sql "CREATE TRIGGER http_image_fail BEFORE INSERT ON product_images FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='http image failure'"
request POST admin_products.php \
    -F "csrf_token=$CSRF_TOKEN" -F 'action=add' -F 'name=HTTP database rollback' \
    -F 'description=must roll back' -F 'price=10.00' -F 'stock=1' \
    -F 'category_id=1' -F 'subcategory_id=1' -F "images[]=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-IMAGE-02 200
sql 'DROP TRIGGER http_image_fail'
assert_sql H-IMAGE-02 0 "SELECT COUNT(*) FROM products WHERE name='HTTP database rollback'"
[[ "$(image_file_count)" == "$IMAGE_COUNT_BEFORE" ]] ||
    fail H-IMAGE-02 'el rollback de base dejó el archivo cargado'
pass H-IMAGE-02

request POST admin_products.php \
    -F "csrf_token=$CSRF_TOKEN" -F 'action=add' -F 'name=HTTP edit baseline' \
    -F 'description=original description' -F 'price=10.00' -F 'stock=1' \
    -F 'category_id=1' -F 'subcategory_id=1' -F "images[]=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-IMAGE-03 200
IMAGE03_PRODUCT="$(sql "SELECT id FROM products WHERE name='HTTP edit baseline'")"
IMAGE03_PATH="$(sql "SELECT image FROM products WHERE id=$IMAGE03_PRODUCT")"
CREATED_IMAGES+=("$IMAGE03_PATH")
IMAGE03_ID="$(sql "SELECT id FROM product_images WHERE product_id=$IMAGE03_PRODUCT")"
IMAGE_COUNT_BEFORE="$(image_file_count)"
request POST admin_products.php \
    -F "csrf_token=$CSRF_TOKEN" -F 'action=edit' -F "id=$IMAGE03_PRODUCT" \
    -F 'name=HTTP edit mutated' -F 'description=mutated description' -F 'price=99.00' \
    -F 'category_id=1' -F 'subcategory_id=1' -F 'is_active=1' -F "main_image=$IMAGE03_ID" \
    -F "new_images[]=@$HTTP_TMP/tiny.png;type=image/png" \
    -F "new_images[]=@$HTTP_TMP/not-image.php;type=image/png"
assert_status H-IMAGE-03 200
assert_sql H-IMAGE-03 1 "SELECT COUNT(*) FROM products WHERE id=$IMAGE03_PRODUCT AND name='HTTP edit baseline' AND description='original description' AND price=10.00"
assert_sql H-IMAGE-03 1 "SELECT COUNT(*) FROM product_images WHERE product_id=$IMAGE03_PRODUCT AND image_path='$IMAGE03_PATH' AND is_main=1"
[[ -f "$ROOT/$IMAGE03_PATH" ]] || fail H-IMAGE-03 'el rollback eliminó la imagen preexistente'
[[ "$(image_file_count)" == "$IMAGE_COUNT_BEFORE" ]] ||
    fail H-IMAGE-03 'la edición fallida dejó imágenes nuevas'
pass H-IMAGE-03

request POST admin_products.php \
    -F "csrf_token=$CSRF_TOKEN" -F 'action=add' -F 'name=HTTP first successful' \
    -F 'description=empty then valid' -F 'price=10.00' -F 'stock=1' \
    -F 'category_id=1' -F 'subcategory_id=1' \
    -F 'images[]=@/dev/null;filename=' -F "images[]=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-IMAGE-04 200
IMAGE04_PRODUCT="$(sql "SELECT id FROM products WHERE name='HTTP first successful'")"
IMAGE04_PATH="$(sql "SELECT image FROM products WHERE id=$IMAGE04_PRODUCT")"
CREATED_IMAGES+=("$IMAGE04_PATH")
assert_sql H-IMAGE-04 1 "SELECT COUNT(*) FROM product_images WHERE product_id=$IMAGE04_PRODUCT AND image_path='$IMAGE04_PATH' AND is_main=1"
[[ -f "$ROOT/$IMAGE04_PATH" ]] || fail H-IMAGE-04 'la primera imagen exitosa no quedó almacenada'
pass H-IMAGE-04

sql "INSERT INTO products(name,description,price,stock,category_id,subcategory_id,image) VALUES('HTTP image-less','without image',10,1,1,1,NULL)"
IMAGE05_PRODUCT="$(sql "SELECT id FROM products WHERE name='HTTP image-less'")"
request POST admin_products.php \
    -F "csrf_token=$CSRF_TOKEN" -F 'action=edit' -F "id=$IMAGE05_PRODUCT" \
    -F 'name=HTTP image-less' -F 'description=without image' -F 'price=10.00' \
    -F 'category_id=1' -F 'subcategory_id=1' -F 'is_active=1' \
    -F "new_images[]=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-IMAGE-05 200
IMAGE05_PATH="$(sql "SELECT image FROM products WHERE id=$IMAGE05_PRODUCT")"
CREATED_IMAGES+=("$IMAGE05_PATH")
assert_sql H-IMAGE-05 1 "SELECT COUNT(*) FROM product_images WHERE product_id=$IMAGE05_PRODUCT AND image_path='$IMAGE05_PATH' AND is_main=1"
[[ -f "$ROOT/$IMAGE05_PATH" ]] || fail H-IMAGE-05 'la primera imagen del producto no quedó almacenada'
pass H-IMAGE-05

printf 'Prueba XSS por HTTP...\n'
request GET index.php
assert_status H-XSS-HTTP 200
assert_body_excludes H-XSS-HTTP '<script>globalThis.xssExecuted=1;document.title="XSS_EXECUTED"</script>'
assert_body_excludes H-XSS-HTTP '"><img src=x onerror=globalThis.xssExecuted=2>'
assert_body_contains H-XSS-HTTP '&lt;script&gt;globalThis.xssExecuted=1;document.title=&quot;XSS_EXECUTED&quot;&lt;/script&gt;'
pass H-XSS-HTTP
if command -v google-chrome >/dev/null 2>&1; then
    if timeout 30 google-chrome --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage --dump-dom "$HTTP_BASE_URL/index.php" >"$HTTP_TMP/browser-dom.html" 2>"$HTTP_TMP/chrome.log"; then
        ! rg --quiet '<title>XSS_EXECUTED</title>|<script>globalThis\\.xssExecuted|onerror="globalThis\\.xssExecuted' "$HTTP_TMP/browser-dom.html" ||
            fail H-XSS-BROWSER 'Chromium creó o ejecutó nodos del payload'
        pass H-XSS-BROWSER
    else
        printf '  BLOCKED H-XSS-BROWSER - Chromium headless no terminó correctamente\n'
    fi
else
    printf '  BLOCKED H-XSS-BROWSER - google-chrome no está disponible\n'
fi

if rg --ignore-case --quiet 'PHP (Warning|Fatal error)|Stack trace:|\\[500\\]:' "$SERVER_LOG"; then
    fail SERVER_LOG 'se detectaron warnings, fatals, stack traces o respuestas 500'
fi
printf 'OK: servidor PHP (4 workers), pedidos, auth, CSRF, imágenes, correo mock y XSS HTTP.\n'
