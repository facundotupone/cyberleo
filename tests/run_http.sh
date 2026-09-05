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
CHROME_PID=""
NAV_CHROME_PID=""
HERO_CHROME_PID=""
UPLOAD_DIR="$ROOT/assets/images/products"
mkdir -p "$UPLOAD_DIR"

cleanup() {
    local status=$?
    trap - EXIT INT TERM
    rmdir "$MAIL_LOG" 2>/dev/null || true
    sql 'DROP TRIGGER IF EXISTS http_image_fail' >/dev/null 2>&1 || true
    sql 'DROP TRIGGER IF EXISTS settings_internal_fail' >/dev/null 2>&1 || true
    sql 'DROP TRIGGER IF EXISTS settings_home_fail' >/dev/null 2>&1 || true
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
    if [[ -n "$CHROME_PID" ]]; then
        kill -- "-$CHROME_PID" 2>/dev/null || kill "$CHROME_PID" 2>/dev/null || true
        wait "$CHROME_PID" 2>/dev/null || true
    fi
    if [[ -n "$NAV_CHROME_PID" ]]; then
        kill -- "-$NAV_CHROME_PID" 2>/dev/null || kill "$NAV_CHROME_PID" 2>/dev/null || true
        wait "$NAV_CHROME_PID" 2>/dev/null || true
    fi
    if [[ -n "$HERO_CHROME_PID" ]]; then
        kill -- "-$HERO_CHROME_PID" 2>/dev/null || kill "$HERO_CHROME_PID" 2>/dev/null || true
        wait "$HERO_CHROME_PID" 2>/dev/null || true
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
        APP_ENV=test \
        APP_SECRET='http-suite-secret-that-is-not-used-outside-tests' \
        SITE_URL="$HTTP_BASE_URL" \
        DB_HOST="localhost;unix_socket=$TEST_DB_SOCKET" \
        DB_NAME="$TEST_DB_NAME" DB_USER=root DB_PASS='' \
        MAIL_TRANSPORT=log MAIL_LOG_PATH="$MAIL_LOG" \
        php -S "127.0.0.1:$PORT" "$ROOT/tests/helpers/php_server_router.php"
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
settings_image_file_count() {
    local count=0 path name directory="$ROOT/assets/images/settings"
    for path in "$directory"/*; do
        [[ -e "$path" ]] || continue
        name="${path##*/}"
        [[ "$name" =~ ^[a-f0-9]{32}\.(jpg|png|webp)$ ]] && ((count+=1))
    done
    printf '%s' "$count"
}
settings_form() {
    local store_name=${1:-HTTP Test Store}
    shift || true
    request POST admin_settings.php \
        -F "csrf_token=$CSRF_TOKEN" \
        -F 'settings_action=save' \
        -F "store_name=$store_name" \
        -F 'whatsapp_number=5491100000000' \
        -F 'instagram_url=' \
        -F 'hero_title=HTTP hero' \
        -F 'hero_subtitle=HTTP subtitle' \
        -F 'reservation_minutes=120' \
        -F 'admin_email=admin-http@example.test' \
        -F 'mail_from=store-http@example.test' \
        -F 'payment_methods=Efectivo' \
        -F 'brand_primary_color=#0057b8' \
        -F 'brand_secondary_color=#00aeef' \
        -F 'brand_navy_color=#071a33' \
        -F 'brand_background_color=#f3f8fc' \
        -F 'brand_text_color=#111827' \
        -F 'brand_font=system' \
        -F 'nav_style=white' \
        -F 'button_radius=medium' \
        -F 'card_radius=medium' \
        -F 'hero_button_text=Explorar catálogo' \
        -F 'hero_button_url=#productos-destacados' \
        -F 'hero_height=normal' \
        -F 'hero_alignment=center' \
        -F 'hero_overlay=medium' \
        -F 'show_search=1' \
        -F 'show_categories=1' \
        -F 'show_featured_products=1' \
        -F 'announcement_style=primary' \
        -F 'announcement_text=' \
        -F 'announcement_url=' \
        -F 'promo_title=' \
        -F 'promo_text=' \
        -F 'promo_button_text=Ver más' \
        -F 'promo_button_url=#' \
        -F 'home_order_featured=1' \
        -F 'home_order_promo=2' \
        -F 'home_order_categories=3' \
        -F 'home_order_benefits=4' \
        -F 'benefits_enabled=1' \
        -F 'benefits_section_title=¿Por qué elegir CyberLeo?' \
        -F 'benefit_1_icon=bi-truck' \
        -F 'benefit_1_title=Envíos y entregas' \
        -F 'benefit_1_text=Coordinamos la entrega o retiro de tu compra.' \
        -F 'benefit_2_icon=bi-shield-check' \
        -F 'benefit_2_title=Compra segura' \
        -F 'benefit_2_text=Stock actualizado y pedido confirmado por WhatsApp.' \
        -F 'benefit_3_icon=bi-headset' \
        -F 'benefit_3_title=Atención personalizada' \
        -F 'benefit_3_text=Te asesoramos para elegir la mejor opción.' \
        -F 'footer_description=Tecnología, periféricos y soluciones para tu equipo.' \
        -F 'footer_instagram_text=Seguinos en Instagram' \
        -F 'footer_whatsapp_text=Contactar por WhatsApp' \
        -F 'footer_show_logo=1' \
        -F 'footer_show_instagram=1' \
        -F 'footer_show_whatsapp=1' \
        -F 'business_hours=' \
        -F 'business_location=' \
        -F 'featured_section_title=Productos Destacados' \
        -F 'featured_empty_text=No hay productos destacados disponibles.' \
        -F 'catalog_empty_text=No hay productos disponibles en esta categoría.' \
        -F 'featured_columns=3' \
        -F 'catalog_columns=3' \
        -F 'product_card_style=elevated' \
        -F 'product_image_fit=contain' \
        -F 'product_image_height=normal' \
        -F 'product_card_alignment=left' \
        -F 'product_description_mode=expandable' \
        -F 'product_description_length=200' \
        -F 'product_show_category_badge=1' \
        -F 'product_show_stock=1' \
        -F 'product_show_sale_badge=1' \
        -F 'product_show_old_price=1' \
        -F 'product_sale_badge_text=LIQUIDACIÓN' \
        -F 'product_show_share_buttons=1' \
        -F 'product_share_whatsapp=1' \
        -F 'product_share_facebook=1' \
        -F 'product_share_copy=1' \
        -F 'product_add_button_text=Agregar al carrito' \
        -F 'product_out_of_stock_text=Sin stock' \
        -F 'catalog_show_breadcrumbs=1' \
        -F 'catalog_show_product_count=1' \
        -F 'catalog_show_subcategory_filter=1' \
        -F 'cart_page_title=Carrito de Compras' \
        -F 'cart_items_title=Productos en tu carrito' \
        -F 'cart_summary_title=Resumen del pedido' \
        -F 'cart_total_label=Total:' \
        -F 'cart_delivery_title=Información de envío' \
        -F 'cart_delivery_text=Envíanos tu consulta y te responderemos a la brevedad para coordinar envío o retiro.' \
        -F 'cart_delivery_methods_title=Formas de entrega:' \
        -F 'cart_delivery_methods=' \
        -F 'cart_payment_title=Métodos de pago:' \
        -F 'cart_payment_note=Abonas al recibir tu pedido' \
        -F 'cart_order_button_text=Enviar Pedido por WhatsApp' \
        -F 'cart_continue_button_text=Seguir Comprando' \
        -F 'cart_empty_title=Tu carrito está vacío' \
        -F 'cart_empty_text=Agrega algunos productos para comenzar' \
        -F 'cart_empty_button_text=Explorar productos' \
        -F 'cart_available_text=Disponible' \
        -F 'cart_stock_template=Solo {stock} disponibles' \
        -F 'cart_registering_text=Registrando pedido...' \
        -F 'cart_success_template=Pedido #{order_id} registrado. Te llevamos a WhatsApp para coordinarlo.' \
        -F 'cart_reservation_text=El stock se reserva durante {minutes} minutos después de registrar el pedido.' \
        -F 'order_whatsapp_template=Hola {store_name}, quiero confirmar el pedido #{order_id}:

{items}

Total: {total}' \
        -F 'cart_layout=standard' \
        -F 'cart_image_fit=cover' \
        -F 'cart_image_size=normal' \
        -F 'cart_show_images=1' \
        -F 'cart_show_sale_badge=1' \
        -F 'cart_show_old_price=1' \
        -F 'cart_show_stock_status=1' \
        -F 'cart_show_delivery_info=1' \
        -F 'cart_show_payment_methods=1' \
        -F 'cart_terms_text=' \
        -F 'cart_terms_url=' \
        "$@"
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
    "${cli_env[@]}" php -r '
        require "includes/config.php"; require "includes/db.php"; require "includes/orders.php";
        echo expire_pending_orders($pdo) . " reservas vencidas liberadas.\n";
    '
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
    "${cli_env[@]}" php -r '
        require "includes/config.php"; require "includes/db.php"; require "includes/orders.php";
        echo expire_pending_orders($pdo) . " reservas vencidas liberadas.\n";
    '
) >"$HTTP_TMP/order05-first"
(
    cd "$ROOT"
    "${cli_env[@]}" php -r '
        require "includes/config.php"; require "includes/db.php"; require "includes/orders.php";
        echo expire_pending_orders($pdo) . " reservas vencidas liberadas.\n";
    '
) >"$HTTP_TMP/order05-second"
rg --fixed-strings --quiet '1 reservas vencidas liberadas.' "$HTTP_TMP/order05-first" ||
    fail H-ORDER-05 'la primera ejecución del cron no liberó exactamente una reserva'
rg --fixed-strings --quiet '0 reservas vencidas liberadas.' "$HTTP_TMP/order05-second" ||
    fail H-ORDER-05 'la segunda ejecución del cron no fue idempotente'
assert_sql H-ORDER-05 expired "SELECT status FROM orders WHERE id=$ORDER05_ID"
assert_sql H-ORDER-05 2 'SELECT stock FROM products WHERE id=1'
pass H-ORDER-05

printf 'Prueba de límite HTTP de pedidos...\n'
reset_orders
sql 'UPDATE products SET stock=3 WHERE id=1'
RATE_ORDER_KEY="$(printf '3%.0s' {1..64})"
RATE_ORDER_PAYLOAD="{\"idempotencyKey\":\"$RATE_ORDER_KEY\",\"items\":[{\"productId\":1,\"quantity\":1}]}"
for attempt in {1..10}; do
    HTTP_COOKIE="$HTTP_TMP/rate-order-$attempt.cookie"
    request POST create_order.php -H 'Content-Type: application/json' --data "$RATE_ORDER_PAYLOAD"
    assert_status H-RATE-ORDER 200
done
assert_sql H-RATE-ORDER 10 'SELECT COUNT(*) FROM order_rate_limits'
assert_sql H-RATE-ORDER 1 "SELECT COUNT(*) FROM orders WHERE idempotency_key='$RATE_ORDER_KEY'"
assert_sql H-RATE-ORDER 2 'SELECT stock FROM products WHERE id=1'
RATE_ORDER_ID="$(sql "SELECT id FROM orders WHERE idempotency_key='$RATE_ORDER_KEY'")"
HTTP_COOKIE="$HTTP_TMP/rate-order-11.cookie"
request POST create_order.php -H 'Content-Type: application/json' \
    --data "{\"idempotencyKey\":\"$(printf '4%.0s' {1..64})\",\"items\":[{\"productId\":1,\"quantity\":1}]}"
assert_status H-RATE-ORDER 429
assert_header_contains H-RATE-ORDER 'Retry-After: 900'
assert_sql H-RATE-ORDER 1 'SELECT COUNT(*) FROM orders'
assert_sql H-RATE-ORDER 1 "SELECT COUNT(*) FROM order_items WHERE order_id=$RATE_ORDER_ID"
assert_sql H-RATE-ORDER 2 'SELECT stock FROM products WHERE id=1'
pass H-RATE-ORDER

printf 'Pruebas de estrés HTTP de pedidos (20 iteraciones)...\n'
for iteration in {1..20}; do
    reset_orders
    sql 'UPDATE products SET stock=8 WHERE id=1'
    key="$(printf '%064x' "$((0x1000 + iteration))")"
    payload="{\"idempotencyKey\":\"$key\",\"items\":[{\"productId\":1,\"quantity\":2}]}"
    prefixes=()
    pids=()
    for worker in {1..4}; do
        prefix="$HTTP_TMP/stress-idempotency-$iteration-$worker"
        prefixes+=("$prefix")
        order_curl "$prefix" "$payload" &
        pids+=("$!")
    done
    for pid in "${pids[@]}"; do wait "$pid" || fail H-STRESS-IDEMPOTENCY "iteración $iteration: curl falló"; done
    assert_parallel_statuses H-STRESS-IDEMPOTENCY '200,200,200,200' "${prefixes[@]}"
    order_id="$(json_file_value "${prefixes[0]}.body" orderId)"
    for prefix in "${prefixes[@]}"; do
        [[ "$(json_file_value "$prefix.body" orderId)" == "$order_id" ]] ||
            fail H-STRESS-IDEMPOTENCY "iteración $iteration: respuestas con pedidos distintos"
    done
    assert_sql H-STRESS-IDEMPOTENCY 1 "SELECT COUNT(*) FROM orders WHERE idempotency_key='$key'"
    assert_sql H-STRESS-IDEMPOTENCY 1 "SELECT COUNT(*) FROM order_items WHERE order_id=$order_id AND product_id=1 AND quantity=2"
    assert_sql H-STRESS-IDEMPOTENCY 6 'SELECT stock FROM products WHERE id=1'
done
pass H-STRESS-IDEMPOTENCY

for iteration in {1..20}; do
    reset_orders
    sql 'UPDATE products SET stock=1 WHERE id=1'
    key_a="$(printf '%064x' "$((0x2000 + iteration * 2))")"
    key_b="$(printf '%064x' "$((0x2001 + iteration * 2))")"
    prefix_a="$HTTP_TMP/stress-last-$iteration-a"
    prefix_b="$HTTP_TMP/stress-last-$iteration-b"
    order_curl "$prefix_a" "{\"idempotencyKey\":\"$key_a\",\"items\":[{\"productId\":1,\"quantity\":1}]}" & p1=$!
    order_curl "$prefix_b" "{\"idempotencyKey\":\"$key_b\",\"items\":[{\"productId\":1,\"quantity\":1}]}" & p2=$!
    wait "$p1" || fail H-STRESS-LAST-UNIT "iteración $iteration: primer curl falló"
    wait "$p2" || fail H-STRESS-LAST-UNIT "iteración $iteration: segundo curl falló"
    assert_parallel_statuses H-STRESS-LAST-UNIT '200,422' "$prefix_a" "$prefix_b"
    assert_sql H-STRESS-LAST-UNIT 1 'SELECT COUNT(*) FROM orders'
    assert_sql H-STRESS-LAST-UNIT 1 'SELECT COALESCE(SUM(quantity),0) FROM order_items WHERE product_id=1'
    assert_sql H-STRESS-LAST-UNIT 0 'SELECT stock FROM products WHERE id=1'
done
pass H-STRESS-LAST-UNIT

for iteration in {1..20}; do
    reset_orders
    sql 'UPDATE products SET stock=2 WHERE id IN (1,2)'
    key_a="$(printf '%064x' "$((0x3000 + iteration * 2))")"
    key_b="$(printf '%064x' "$((0x3001 + iteration * 2))")"
    prefix_a="$HTTP_TMP/stress-reverse-$iteration-a"
    prefix_b="$HTTP_TMP/stress-reverse-$iteration-b"
    order_curl "$prefix_a" "{\"idempotencyKey\":\"$key_a\",\"items\":[{\"productId\":1,\"quantity\":1},{\"productId\":2,\"quantity\":1}]}" & p1=$!
    order_curl "$prefix_b" "{\"idempotencyKey\":\"$key_b\",\"items\":[{\"productId\":2,\"quantity\":1},{\"productId\":1,\"quantity\":1}]}" & p2=$!
    wait "$p1" || fail H-STRESS-REVERSE "iteración $iteration: primer curl falló"
    wait "$p2" || fail H-STRESS-REVERSE "iteración $iteration: segundo curl falló"
    assert_parallel_statuses H-STRESS-REVERSE '200,200' "$prefix_a" "$prefix_b"
    assert_sql H-STRESS-REVERSE 2 'SELECT COUNT(*) FROM orders'
    assert_sql H-STRESS-REVERSE 2 'SELECT COUNT(*) FROM order_items WHERE product_id=1 AND quantity=1'
    assert_sql H-STRESS-REVERSE 2 'SELECT COUNT(*) FROM order_items WHERE product_id=2 AND quantity=1'
    assert_sql H-STRESS-REVERSE 0 'SELECT stock FROM products WHERE id=1'
    assert_sql H-STRESS-REVERSE 0 'SELECT stock FROM products WHERE id=2'
done
pass H-STRESS-REVERSE

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
for attempt in {1..5}; do
    HTTP_COOKIE="$HTTP_TMP/rate-forgot-$attempt.cookie"
    request POST forgot_password.php --data-urlencode 'email=admin-http@example.test'
    assert_status H-RATE-FORGOT 302
done
[[ "$(wc -l < "$MAIL_LOG")" == 5 ]] ||
    fail H-RATE-FORGOT 'los cinco intentos permitidos no generaron exactamente cinco correos'
assert_sql H-RATE-FORGOT 5 'SELECT COUNT(*) FROM auth_rate_limits'
RATE_FORGOT_TOKEN="$(sql 'SELECT reset_token FROM users WHERE id=1')"
HTTP_COOKIE="$HTTP_TMP/rate-forgot-6.cookie"
request POST forgot_password.php --data-urlencode 'email=admin-http@example.test'
assert_status H-RATE-FORGOT 429
assert_header_contains H-RATE-FORGOT 'Retry-After: 900'
[[ "$(wc -l < "$MAIL_LOG")" == 5 ]] || fail H-RATE-FORGOT 'el intento limitado generó un correo'
assert_sql H-RATE-FORGOT "$RATE_FORGOT_TOKEN" 'SELECT reset_token FROM users WHERE id=1'
assert_sql H-RATE-FORGOT 5 'SELECT COUNT(*) FROM auth_rate_limits'
pass H-RATE-FORGOT

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
sql "UPDATE store_settings SET setting_value=CONCAT('CyberLeo',CHAR(13),CHAR(10),'Bcc: injected@example.test<script>globalThis.mailXss=1</script>') WHERE setting_key='store_name'"
request POST forgot_password.php --data-urlencode 'email=admin-http@example.test'
assert_status H-AUTH-05 302
[[ "$(wc -l < "$MAIL_LOG")" == 1 ]] || fail H-AUTH-05 'no se generó exactamente un correo'
php -r '
$m=json_decode(trim(file_get_contents($argv[1])),true,512,JSON_THROW_ON_ERROR);
if ($m["to"]!=="admin-http@example.test") exit(1);
if (preg_match("/[\r\n]/",$m["subject"])) exit(2);
if (preg_match("/\r?\n(?:Bcc|Cc|To|Subject):/i",$m["headers"])) exit(3);
if (stripos($m["subject"],"Bcc:")!==false || str_contains($m["subject"],"<script>")) exit(4);
if (!str_contains($m["html"],"&lt;script&gt;globalThis.mailXss=1&lt;/script&gt;")) exit(5);
' "$MAIL_LOG" || fail H-AUTH-05 'encabezados o HTML del correo no fueron normalizados'
sql "UPDATE store_settings SET setting_value='HTTP Test Store' WHERE setting_key='store_name'"
pass H-AUTH-05

printf 'Pruebas CSRF de endpoints mutables...\n'
HTTP_COOKIE="$HTTP_TMP/admin.cookie"
request POST admin_login.php --data-urlencode 'username=http-admin' --data-urlencode "password=$WINNING_PASSWORD"
assert_status H-CSRF-LOGIN 302
ADMIN_COOKIE="$HTTP_COOKIE"
request GET admin_products.php
assert_status H-CSRF-LOGIN 200
CSRF_TOKEN="$(csrf_from_body)"

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

printf 'Pruebas CSRF válidas y reversibles...\n'
HTTP_COOKIE="$ADMIN_COOKIE"
request POST admin_categories.php \
    --data-urlencode "csrf_token=$CSRF_TOKEN" \
    --data-urlencode 'action=add_category' \
    --data-urlencode 'name=HTTP CSRF reversible' \
    --data-urlencode 'icon=bi bi-star'
assert_status H-CSRF-VALID-CATEGORIES 200
CSRF_CATEGORY_ID="$(sql "SELECT id FROM categories WHERE name='HTTP CSRF reversible'")"
[[ -n "$CSRF_CATEGORY_ID" ]] || fail H-CSRF-VALID-CATEGORIES 'el token válido no creó la categoría'
request POST admin_categories.php \
    --data-urlencode "csrf_token=$CSRF_TOKEN" \
    --data-urlencode 'action=delete_category' \
    --data-urlencode "id=$CSRF_CATEGORY_ID"
assert_status H-CSRF-VALID-CATEGORIES 200
assert_sql H-CSRF-VALID-CATEGORIES 0 "SELECT COUNT(*) FROM categories WHERE name='HTTP CSRF reversible'"
pass H-CSRF-VALID-CATEGORIES

settings_form 'HTTP CSRF changed'
assert_status H-CSRF-VALID-SETTINGS 302
assert_sql H-CSRF-VALID-SETTINGS 'HTTP CSRF changed' "SELECT setting_value FROM store_settings WHERE setting_key='store_name'"
settings_form 'HTTP Test Store'
assert_status H-CSRF-VALID-SETTINGS 302
assert_sql H-CSRF-VALID-SETTINGS 'HTTP Test Store' "SELECT setting_value FROM store_settings WHERE setting_key='store_name'"
pass H-CSRF-VALID-SETTINGS

reset_orders
sql 'UPDATE products SET stock=2 WHERE id=1'
HTTP_COOKIE="$HTTP_TMP/csrf-valid-order-client.cookie"
request POST create_order.php -H 'Content-Type: application/json' \
    --data "{\"idempotencyKey\":\"$(printf '5%.0s' {1..64})\",\"items\":[{\"productId\":1,\"quantity\":1}]}"
assert_status H-CSRF-VALID-ORDERS 200
CSRF_VALID_ORDER_ID="$(json_value orderId)"
HTTP_COOKIE="$ADMIN_COOKIE"
request POST admin_orders.php \
    --data-urlencode "csrf_token=$CSRF_TOKEN" \
    --data-urlencode "order_id=$CSRF_VALID_ORDER_ID" \
    --data-urlencode 'status=cancelled'
assert_status H-CSRF-VALID-ORDERS 200
assert_sql H-CSRF-VALID-ORDERS cancelled "SELECT status FROM orders WHERE id=$CSRF_VALID_ORDER_ID"
assert_sql H-CSRF-VALID-ORDERS 2 'SELECT stock FROM products WHERE id=1'
reset_orders
pass H-CSRF-VALID-ORDERS

CSRF_IMAGE_NAME="$(printf '6%.0s' {1..32}).png"
CSRF_IMAGE_PATH="assets/images/products/$CSRF_IMAGE_NAME"
cp "$HTTP_TMP/tiny.png" "$ROOT/$CSRF_IMAGE_PATH"
CREATED_IMAGES+=("$CSRF_IMAGE_PATH")
sql "INSERT INTO products(name,description,price,stock,category_id,subcategory_id,image) VALUES('HTTP CSRF image','temporary',10,1,1,1,'$CSRF_IMAGE_PATH')"
CSRF_IMAGE_PRODUCT="$(sql "SELECT id FROM products WHERE name='HTTP CSRF image'")"
sql "INSERT INTO product_images(product_id,image_path,is_main) VALUES($CSRF_IMAGE_PRODUCT,'$CSRF_IMAGE_PATH',1)"
CSRF_IMAGE_ID="$(sql "SELECT id FROM product_images WHERE product_id=$CSRF_IMAGE_PRODUCT")"
request POST delete_image.php \
    --data-urlencode "csrf_token=$CSRF_TOKEN" \
    --data-urlencode "image_id=$CSRF_IMAGE_ID"
assert_status H-CSRF-VALID-IMAGE 200
assert_sql H-CSRF-VALID-IMAGE 0 "SELECT COUNT(*) FROM product_images WHERE id=$CSRF_IMAGE_ID"
assert_sql H-CSRF-VALID-IMAGE NULL "SELECT COALESCE(image,'NULL') FROM products WHERE id=$CSRF_IMAGE_PRODUCT"
[[ ! -e "$ROOT/$CSRF_IMAGE_PATH" ]] || fail H-CSRF-VALID-IMAGE 'el archivo eliminado sigue presente'
sql "DELETE FROM products WHERE id=$CSRF_IMAGE_PRODUCT"
pass H-CSRF-VALID-IMAGE

printf 'Pruebas de rutas maliciosas en delete_image...\n'
MALICIOUS_SENTINEL="$HTTP_TMP/delete-image-sentinel"
printf 'do-not-delete\n' > "$MALICIOUS_SENTINEL"
MALICIOUS_LINK_PATH="assets/images/products/$(printf '7%.0s' {1..32}).png"
ln -s "$MALICIOUS_SENTINEL" "$ROOT/$MALICIOUS_LINK_PATH"
CREATED_IMAGES+=("$MALICIOUS_LINK_PATH")
sql "INSERT INTO products(name,description,price,stock,category_id,subcategory_id,image) VALUES('HTTP malicious paths','temporary',10,1,1,1,NULL)"
MALICIOUS_PRODUCT="$(sql "SELECT id FROM products WHERE name='HTTP malicious paths'")"
MALICIOUS_PATHS=(
    '../../delete-image-sentinel'
    '/etc/passwd'
    'assets/images/products/../../../../etc/passwd'
    "$MALICIOUS_LINK_PATH"
)
for path in "${MALICIOUS_PATHS[@]}"; do
    escaped_path="${path//\'/\'\'}"
    sql "INSERT INTO product_images(product_id,image_path,is_main) VALUES($MALICIOUS_PRODUCT,'$escaped_path',0)"
    malicious_id="$(sql "SELECT MAX(id) FROM product_images WHERE product_id=$MALICIOUS_PRODUCT")"
    request POST delete_image.php \
        --data-urlencode "csrf_token=$CSRF_TOKEN" \
        --data-urlencode "image_id=$malicious_id"
    assert_status H-IMAGE-PATHS 200
    assert_sql H-IMAGE-PATHS 0 "SELECT COUNT(*) FROM product_images WHERE id=$malicious_id"
    [[ "$(<"$MALICIOUS_SENTINEL")" == 'do-not-delete' ]] ||
        fail H-IMAGE-PATHS "la ruta maliciosa <$path> alteró el archivo externo"
done
[[ -L "$ROOT/$MALICIOUS_LINK_PATH" ]] ||
    fail H-IMAGE-PATHS 'la limpieza siguió un enlace simbólico válido en apariencia'
sql "DELETE FROM products WHERE id=$MALICIOUS_PRODUCT"
pass H-IMAGE-PATHS

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

printf 'Pruebas HTTP de fondos configurables...\n'
HTTP_COOKIE="$ADMIN_COOKIE"
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
SETTINGS_COUNT_BEFORE="$(settings_image_file_count)"

settings_form 'HTTP Test Store' -F "hero_background_file=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-BACKGROUND-UPLOAD 302
BACKGROUND_HERO="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'")"
CREATED_IMAGES+=("$BACKGROUND_HERO")
[[ "$BACKGROUND_HERO" =~ ^assets/images/settings/[a-f0-9]{32}\.png$ && -f "$ROOT/$BACKGROUND_HERO" ]] ||
    fail H-BACKGROUND-UPLOAD 'el fondo subido no quedó almacenado con una ruta segura'
[[ "$(settings_image_file_count)" == "$((SETTINGS_COUNT_BEFORE + 1))" ]] ||
    fail H-BACKGROUND-UPLOAD 'la carga no creó exactamente un archivo'
pass H-BACKGROUND-UPLOAD

BACKGROUND_OLD="$BACKGROUND_HERO"
settings_form 'HTTP Test Store' -F "hero_background_file=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-BACKGROUND-REPLACE 302
BACKGROUND_HERO="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'")"
CREATED_IMAGES+=("$BACKGROUND_HERO")
[[ "$BACKGROUND_HERO" != "$BACKGROUND_OLD" && -f "$ROOT/$BACKGROUND_HERO" && ! -e "$ROOT/$BACKGROUND_OLD" ]] ||
    fail H-BACKGROUND-REPLACE 'el reemplazo no creó el nuevo fondo o no retiró el anterior'
pass H-BACKGROUND-REPLACE

settings_form 'HTTP Test Store' -F 'remove_hero_background=1'
assert_status H-BACKGROUND-DELETE 302
assert_sql H-BACKGROUND-DELETE '' "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'"
[[ ! -e "$ROOT/$BACKGROUND_HERO" ]] || fail H-BACKGROUND-DELETE 'el fondo quitado sigue en disco'
pass H-BACKGROUND-DELETE

BACKGROUND_SHARED="assets/images/settings/$(printf '8%.0s' {1..32}).png"
mkdir -p "$ROOT/assets/images/settings"
cp "$HTTP_TMP/tiny.png" "$ROOT/$BACKGROUND_SHARED"
CREATED_IMAGES+=("$BACKGROUND_SHARED")
sql "INSERT INTO store_settings(setting_key,setting_value) VALUES('hero_background','$BACKGROUND_SHARED'),('body_background','$BACKGROUND_SHARED') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
settings_form 'HTTP Test Store' -F "hero_background_file=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-BACKGROUND-SHARED 302
BACKGROUND_SHARED_REPLACEMENT="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'")"
CREATED_IMAGES+=("$BACKGROUND_SHARED_REPLACEMENT")
assert_sql H-BACKGROUND-SHARED "$BACKGROUND_SHARED" "SELECT setting_value FROM store_settings WHERE setting_key='body_background'"
[[ -f "$ROOT/$BACKGROUND_SHARED" && -f "$ROOT/$BACKGROUND_SHARED_REPLACEMENT" ]] ||
    fail H-BACKGROUND-SHARED 'el reemplazo eliminó un fondo aún compartido'
pass H-BACKGROUND-SHARED

BACKGROUND_CONFLICT="$BACKGROUND_SHARED_REPLACEMENT"
settings_form 'HTTP Test Store' \
    -F "hero_background_file=@$HTTP_TMP/tiny.png;type=image/png" \
    -F 'remove_hero_background=1'
assert_status H-BACKGROUND-CONFLICT 200
assert_sql H-BACKGROUND-CONFLICT "$BACKGROUND_CONFLICT" "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'"
[[ -f "$ROOT/$BACKGROUND_CONFLICT" ]] || fail H-BACKGROUND-CONFLICT 'el conflicto retiró el fondo existente'
pass H-BACKGROUND-CONFLICT

BACKGROUND_COUNT_BEFORE_FAILURE="$(settings_image_file_count)"
BACKGROUND_HERO_BEFORE_FAILURE="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'")"
BACKGROUND_BODY_BEFORE_FAILURE="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='body_background'")"
settings_form 'HTTP Test Store' \
    -F "hero_background_file=@$HTTP_TMP/tiny.png;type=image/png" \
    -F "body_background_file=@$HTTP_TMP/too-large.png;type=image/png"
assert_status H-BACKGROUND-SECOND-FAILURE 200
assert_sql H-BACKGROUND-SECOND-FAILURE "$BACKGROUND_HERO_BEFORE_FAILURE" "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'"
assert_sql H-BACKGROUND-SECOND-FAILURE "$BACKGROUND_BODY_BEFORE_FAILURE" "SELECT setting_value FROM store_settings WHERE setting_key='body_background'"
[[ "$(settings_image_file_count)" == "$BACKGROUND_COUNT_BEFORE_FAILURE" ]] ||
    fail H-BACKGROUND-SECOND-FAILURE 'el fallo del segundo fondo dejó el primer archivo nuevo'
pass H-BACKGROUND-SECOND-FAILURE

settings_form 'HTTP Test Store' -F 'remove_hero_background=1' -F 'remove_body_background=1'
assert_status H-BACKGROUND-CLEANUP 302
assert_sql H-BACKGROUND-CLEANUP '' "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'"
assert_sql H-BACKGROUND-CLEANUP '' "SELECT setting_value FROM store_settings WHERE setting_key='body_background'"
[[ ! -e "$ROOT/$BACKGROUND_SHARED" && ! -e "$ROOT/$BACKGROUND_SHARED_REPLACEMENT" ]] ||
    fail H-BACKGROUND-CLEANUP 'la restauración final dejó fondos de prueba'
pass H-BACKGROUND-CLEANUP

printf 'Pruebas HTTP de identidad visual...\n'
request GET admin_settings.php
assert_status H-THEME-PAGE 200
assert_body_contains H-THEME-PAGE 'Identidad visual'
assert_body_contains H-THEME-PAGE 'Portada'
assert_body_contains H-THEME-PAGE 'Aviso superior'
assert_body_contains H-THEME-PAGE 'Banner promocional'
assert_body_contains H-THEME-PAGE 'Orden de portada'
assert_body_contains H-THEME-PAGE 'Beneficios'
assert_body_contains H-THEME-PAGE 'Footer y datos visibles'
assert_body_contains H-THEME-PAGE 'Restaurar identidad CyberLeo'
assert_body_contains H-THEME-PAGE 'Restaurar contenido predeterminado'
assert_body_contains H-THEME-PAGE 'Vista previa'
assert_body_contains H-THEME-PAGE 'assets/js/theme-preview.js'
assert_body_contains H-THEME-PAGE 'assets/js/home-content-preview.js'
CSRF_TOKEN="$(csrf_from_body)"

request POST admin_settings.php \
    -F "csrf_token=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef" \
    -F 'settings_action=save' \
    -F 'store_name=ShouldNotPersist' \
    -F 'whatsapp_number=5491100000000' \
    -F 'hero_title=x' -F 'hero_subtitle=y' \
    -F 'brand_primary_color=#0057b8' -F 'brand_secondary_color=#00aeef' \
    -F 'brand_navy_color=#071a33' -F 'brand_background_color=#f3f8fc' -F 'brand_text_color=#111827' \
    -F 'brand_font=system' -F 'nav_style=white' -F 'button_radius=medium' -F 'card_radius=medium' \
    -F 'hero_button_text=Explorar catálogo' -F 'hero_button_url=#productos-destacados' \
    -F 'hero_height=normal' -F 'hero_alignment=center' -F 'hero_overlay=medium'
assert_status H-THEME-CSRF 403
assert_sql H-THEME-CSRF 'HTTP Test Store' "SELECT setting_value FROM store_settings WHERE setting_key='store_name'"
pass H-THEME-CSRF

settings_form 'HTTP Theme Alt' \
    -F 'brand_primary_color=#003366' \
    -F 'nav_style=navy' \
    -F 'brand_font=inter' \
    -F 'button_radius=high' \
    -F 'card_radius=low' \
    -F 'hero_button_text=Ver ofertas' \
    -F 'hero_button_url=category.php?id=1' \
    -F 'hero_height=large' \
    -F 'hero_alignment=left' \
    -F 'hero_overlay=strong'
assert_status H-THEME-SAVE 302
assert_header_contains H-THEME-SAVE 'Location: admin_settings.php?saved=1'
assert_sql H-THEME-SAVE '#003366' "SELECT setting_value FROM store_settings WHERE setting_key='brand_primary_color'"
assert_sql H-THEME-SAVE 'navy' "SELECT setting_value FROM store_settings WHERE setting_key='nav_style'"
assert_sql H-THEME-SAVE 'Ver ofertas' "SELECT setting_value FROM store_settings WHERE setting_key='hero_button_text'"
assert_sql H-THEME-SAVE 'category.php?id=1' "SELECT setting_value FROM store_settings WHERE setting_key='hero_button_url'"
pass H-THEME-SAVE

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=save' \
    -F 'store_name=HTTP Theme Bad' \
    -F 'whatsapp_number=5491100000000' \
    -F 'instagram_url=' \
    -F 'hero_title=HTTP hero' \
    -F 'hero_subtitle=HTTP subtitle' \
    -F 'reservation_minutes=120' \
    -F 'admin_email=admin-http@example.test' \
    -F 'mail_from=store-http@example.test' \
    -F 'payment_methods=Efectivo' \
    --form-string 'brand_primary_color=#0057b8;}body{x:1' \
    -F 'brand_secondary_color=#00aeef' \
    -F 'brand_navy_color=#071a33' \
    -F 'brand_background_color=#f3f8fc' \
    -F 'brand_text_color=#111827' \
    -F 'brand_font=system' \
    -F 'nav_style=white' \
    -F 'button_radius=medium' \
    -F 'card_radius=medium' \
    -F 'hero_button_text=Explorar catálogo' \
    -F 'hero_button_url=#productos-destacados' \
    -F 'hero_height=normal' \
    -F 'hero_alignment=center' \
    -F 'hero_overlay=medium' \
    -F 'show_search=1' \
    -F 'show_categories=1' \
    -F 'show_featured_products=1'
assert_status H-THEME-BAD-COLOR 200
assert_body_contains H-THEME-BAD-COLOR 'Color inválido'
assert_sql H-THEME-BAD-COLOR '#003366' "SELECT setting_value FROM store_settings WHERE setting_key='brand_primary_color'"
pass H-THEME-BAD-COLOR

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=save' \
    -F 'store_name=HTTP Theme BadUrl' \
    -F 'whatsapp_number=5491100000000' \
    -F 'instagram_url=' \
    -F 'hero_title=HTTP hero' \
    -F 'hero_subtitle=HTTP subtitle' \
    -F 'reservation_minutes=120' \
    -F 'admin_email=admin-http@example.test' \
    -F 'mail_from=store-http@example.test' \
    -F 'payment_methods=Efectivo' \
    -F 'brand_primary_color=#003366' \
    -F 'brand_secondary_color=#00aeef' \
    -F 'brand_navy_color=#071a33' \
    -F 'brand_background_color=#f3f8fc' \
    -F 'brand_text_color=#111827' \
    -F 'brand_font=system' \
    -F 'nav_style=navy' \
    -F 'button_radius=high' \
    -F 'card_radius=low' \
    -F 'hero_button_text=Ver ofertas' \
    -F 'hero_button_url=javascript:alert(1)' \
    -F 'hero_height=large' \
    -F 'hero_alignment=left' \
    -F 'hero_overlay=strong' \
    -F 'show_search=1' \
    -F 'show_categories=1' \
    -F 'show_featured_products=1'
assert_status H-THEME-BAD-URL 200
assert_body_contains H-THEME-BAD-URL 'Enlace del botón'
assert_sql H-THEME-BAD-URL 'category.php?id=1' "SELECT setting_value FROM store_settings WHERE setting_key='hero_button_url'"
pass H-THEME-BAD-URL

OFFICIAL_LOGO_HASH="$(sha256sum "$ROOT/assets/images/brand/cyberleo-logo.png" | awk '{print $1}')"
THEME_COUNT_BEFORE="$(settings_image_file_count)"
settings_form 'HTTP Theme Logo' -F "brand_logo_file=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-THEME-LOGO 302
CUSTOM_LOGO="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='brand_logo'")"
[[ "$CUSTOM_LOGO" =~ ^assets/images/settings/[a-f0-9]{32}\.png$ && -f "$ROOT/$CUSTOM_LOGO" ]] ||
    fail H-THEME-LOGO "logo personalizado inválido <$CUSTOM_LOGO>"
[[ "$(settings_image_file_count)" == "$((THEME_COUNT_BEFORE + 1))" ]] ||
    fail H-THEME-LOGO 'conteo de archivos de settings inesperado tras logo'
[[ "$(sha256sum "$ROOT/assets/images/brand/cyberleo-logo.png" | awk '{print $1}')" == "$OFFICIAL_LOGO_HASH" ]] ||
    fail H-THEME-LOGO 'el logo oficial fue modificado'
CREATED_IMAGES+=("$CUSTOM_LOGO")
pass H-THEME-LOGO

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
sql "UPDATE store_settings SET setting_value='5491199999999' WHERE setting_key='whatsapp_number'"
sql "INSERT INTO store_settings(setting_key,setting_value) VALUES('payment_methods','Efectivo, Mercado Pago') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=restore_cyberleo'
assert_status H-THEME-RESTORE 302
assert_header_contains H-THEME-RESTORE 'Location: admin_settings.php?restored=1'
assert_sql H-THEME-RESTORE '#0057b8' "SELECT setting_value FROM store_settings WHERE setting_key='brand_primary_color'"
assert_sql H-THEME-RESTORE 'white' "SELECT setting_value FROM store_settings WHERE setting_key='nav_style'"
assert_sql H-THEME-RESTORE 'assets/images/brand/cyberleo-logo.png' "SELECT setting_value FROM store_settings WHERE setting_key='brand_logo'"
assert_sql H-THEME-RESTORE '5491199999999' "SELECT setting_value FROM store_settings WHERE setting_key='whatsapp_number'"
assert_sql H-THEME-RESTORE 'Efectivo, Mercado Pago' "SELECT setting_value FROM store_settings WHERE setting_key='payment_methods'"
[[ ! -f "$ROOT/$CUSTOM_LOGO" ]] || fail H-THEME-RESTORE 'logo personalizado no fue limpiado'
[[ -f "$ROOT/assets/images/brand/cyberleo-logo.png" ]] || fail H-THEME-RESTORE 'falta el logo oficial'
[[ "$(sha256sum "$ROOT/assets/images/brand/cyberleo-logo.png" | awk '{print $1}')" == "$OFFICIAL_LOGO_HASH" ]] ||
    fail H-THEME-RESTORE 'logo oficial alterado tras restauración'
pass H-THEME-RESTORE

request GET index.php
assert_status H-THEME-HOME 200
assert_body_contains H-THEME-HOME '--brand-blue: #0057b8'
assert_body_contains H-THEME-HOME 'assets/images/brand/cyberleo-logo.png'
assert_body_excludes H-THEME-HOME 'javascript:'
pass H-THEME-HOME

printf 'Pruebas HTTP de navegación navy (computed styles)...\n'
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
settings_form 'HTTP Test Store' -F 'nav_style=navy'
assert_status H-NAV-SAVE-NAVY 302
assert_sql H-NAV-SAVE-NAVY 'navy' "SELECT setting_value FROM store_settings WHERE setting_key='nav_style'"
pass H-NAV-SAVE-NAVY

CHROME_BIN="$(command -v google-chrome-stable || command -v google-chrome || true)"
if [[ -n "$CHROME_BIN" ]] && command -v node >/dev/null 2>&1; then
    NAV_CHROME_PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    NAV_CHROME_COMMAND=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$NAV_CHROME_PORT" "--remote-allow-origins=*"
        "--user-data-dir=$HTTP_TMP/chrome-nav-profile"
        about:blank)
    setsid "${NAV_CHROME_COMMAND[@]}" >"$HTTP_TMP/chrome-nav.out" 2>"$HTTP_TMP/chrome-nav.log" & NAV_CHROME_PID=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$NAV_CHROME_PORT/json/list" >/dev/null && break; sleep .05; done
    if timeout 45 node "$ROOT/tests/helpers/chrome_nav_theme.mjs" \
        "$NAV_CHROME_PORT" "$HTTP_BASE_URL" navy >"$HTTP_TMP/chrome-nav-test.out" 2>>"$HTTP_TMP/chrome-nav.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-nav-test.out"
        pass H-NAV-NAVY-COMPUTED
        pass H-NAV-NAVY-MOBILE
    else
        sed -n '1,160p' "$HTTP_TMP/chrome-nav-test.out" >&2
        sed -n '1,80p' "$HTTP_TMP/chrome-nav.log" >&2
        fail H-NAV-NAVY-COMPUTED 'Chromium no verificó estilos navy'
    fi
    kill -- "-$NAV_CHROME_PID" 2>/dev/null || kill "$NAV_CHROME_PID" 2>/dev/null || true
    wait "$NAV_CHROME_PID" 2>/dev/null || true
    NAV_CHROME_PID=""
else
    fail H-NAV-NAVY-COMPUTED 'google-chrome o node no están disponibles'
fi

settings_form 'HTTP Test Store' -F 'nav_style=white'
assert_status H-NAV-SAVE-WHITE 302
assert_sql H-NAV-SAVE-WHITE 'white' "SELECT setting_value FROM store_settings WHERE setting_key='nav_style'"
pass H-NAV-SAVE-WHITE

if [[ -n "$CHROME_BIN" ]] && command -v node >/dev/null 2>&1; then
    NAV_CHROME_PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    NAV_CHROME_COMMAND=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$NAV_CHROME_PORT" "--remote-allow-origins=*"
        "--user-data-dir=$HTTP_TMP/chrome-nav-profile-white"
        about:blank)
    setsid "${NAV_CHROME_COMMAND[@]}" >"$HTTP_TMP/chrome-nav-white.out" 2>"$HTTP_TMP/chrome-nav-white.log" & NAV_CHROME_PID=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$NAV_CHROME_PORT/json/list" >/dev/null && break; sleep .05; done
    if timeout 45 node "$ROOT/tests/helpers/chrome_nav_theme.mjs" \
        "$NAV_CHROME_PORT" "$HTTP_BASE_URL" white >"$HTTP_TMP/chrome-nav-white-test.out" 2>>"$HTTP_TMP/chrome-nav-white.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-nav-white-test.out"
        pass H-NAV-WHITE-COMPUTED
    else
        sed -n '1,160p' "$HTTP_TMP/chrome-nav-white-test.out" >&2
        fail H-NAV-WHITE-COMPUTED 'Chromium no verificó estilos white'
    fi
    kill -- "-$NAV_CHROME_PID" 2>/dev/null || kill "$NAV_CHROME_PID" 2>/dev/null || true
    wait "$NAV_CHROME_PID" 2>/dev/null || true
    NAV_CHROME_PID=""
else
    fail H-NAV-WHITE-COMPUTED 'google-chrome o node no están disponibles'
fi

printf 'Prueba de mensajes internos en admin_settings...\n'
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
sql "CREATE TRIGGER settings_internal_fail BEFORE UPDATE ON store_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SQLSTATE internal path=/workspace/secret.sql'"
settings_form 'HTTP Leak Probe'
assert_status H-SETTINGS-INTERNAL 200
assert_body_contains H-SETTINGS-INTERNAL 'No se pudo guardar la configuración. Intentá nuevamente.'
assert_body_excludes H-SETTINGS-INTERNAL 'SQLSTATE'
assert_body_excludes H-SETTINGS-INTERNAL '/workspace/'
assert_body_excludes H-SETTINGS-INTERNAL 'secret.sql'
assert_body_excludes H-SETTINGS-INTERNAL 'Stack trace'
assert_body_excludes H-SETTINGS-INTERNAL 'PDOException'
rg -q 'admin_settings:|SQLSTATE|settings_internal_fail' "$SERVER_LOG" ||
    fail H-SETTINGS-INTERNAL 'el error interno no quedó registrado en el log del servidor'
sql 'DROP TRIGGER IF EXISTS settings_internal_fail'
pass H-SETTINGS-INTERNAL

printf 'Pruebas Chromium del hero temático...\n'
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
HERO_ALT_TITLE='Hero alt audit title'
HERO_ALT_SUBTITLE='Hero alt audit subtitle'
settings_form 'HTTP Test Store' \
    -F "hero_title=$HERO_ALT_TITLE" \
    -F "hero_subtitle=$HERO_ALT_SUBTITLE" \
    -F 'brand_primary_color=#7a1f1f' \
    -F 'brand_secondary_color=#c45c26' \
    -F 'brand_navy_color=#1b1030' \
    -F 'brand_background_color=#f7f1ea' \
    -F 'brand_text_color=#1f1320' \
    -F 'nav_style=navy'
assert_status H-HERO-THEME-SAVE 302
assert_sql H-HERO-THEME-SAVE '#7a1f1f' "SELECT setting_value FROM store_settings WHERE setting_key='brand_primary_color'"
assert_sql H-HERO-THEME-SAVE '#1b1030' "SELECT setting_value FROM store_settings WHERE setting_key='brand_navy_color'"
assert_sql H-HERO-THEME-SAVE "$HERO_ALT_TITLE" "SELECT setting_value FROM store_settings WHERE setting_key='hero_title'"
pass H-HERO-THEME-SAVE

if [[ -n "$CHROME_BIN" ]] && command -v node >/dev/null 2>&1; then
    HERO_CHROME_PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    HERO_CHROME_COMMAND=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$HERO_CHROME_PORT" "--remote-allow-origins=*"
        "--user-data-dir=$HTTP_TMP/chrome-hero-profile"
        about:blank)
    setsid "${HERO_CHROME_COMMAND[@]}" >"$HTTP_TMP/chrome-hero.out" 2>"$HTTP_TMP/chrome-hero.log" & HERO_CHROME_PID=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$HERO_CHROME_PORT/json/list" >/dev/null && break; sleep .05; done
    if timeout 45 node "$ROOT/tests/helpers/chrome_hero_theme.mjs" \
        "$HERO_CHROME_PORT" "$HTTP_BASE_URL" alt >"$HTTP_TMP/chrome-hero-alt.out" 2>>"$HTTP_TMP/chrome-hero.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-hero-alt.out"
        pass H-HERO-THEME-ALT
    else
        sed -n '1,160p' "$HTTP_TMP/chrome-hero-alt.out" >&2
        sed -n '1,80p' "$HTTP_TMP/chrome-hero.log" >&2
        fail H-HERO-THEME-ALT 'Chromium no verificó el hero alternativo'
    fi
    kill -- "-$HERO_CHROME_PID" 2>/dev/null || kill "$HERO_CHROME_PID" 2>/dev/null || true
    wait "$HERO_CHROME_PID" 2>/dev/null || true
    HERO_CHROME_PID=""
else
    fail H-HERO-THEME-ALT 'google-chrome o node no están disponibles'
fi

settings_form 'HTTP Test Store' \
    -F "hero_title=$HERO_ALT_TITLE" \
    -F "hero_subtitle=$HERO_ALT_SUBTITLE" \
    -F 'brand_primary_color=#7a1f1f' \
    -F 'brand_secondary_color=#c45c26' \
    -F 'brand_navy_color=#1b1030' \
    -F 'hero_overlay=strong' \
    -F "hero_background_file=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-HERO-OVERLAY-SAVE 302
BACKGROUND_HERO_THEME="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'")"
[[ "$BACKGROUND_HERO_THEME" =~ ^assets/images/settings/[a-f0-9]{32}\.png$ && -f "$ROOT/$BACKGROUND_HERO_THEME" ]] ||
    fail H-HERO-OVERLAY-SAVE "hero background inválido <$BACKGROUND_HERO_THEME>"
CREATED_IMAGES+=("$BACKGROUND_HERO_THEME")
pass H-HERO-OVERLAY-SAVE

if [[ -n "$CHROME_BIN" ]] && command -v node >/dev/null 2>&1; then
    HERO_CHROME_PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    HERO_CHROME_COMMAND=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$HERO_CHROME_PORT" "--remote-allow-origins=*"
        "--user-data-dir=$HTTP_TMP/chrome-hero-overlay-profile"
        about:blank)
    setsid "${HERO_CHROME_COMMAND[@]}" >"$HTTP_TMP/chrome-hero-overlay.out" 2>"$HTTP_TMP/chrome-hero-overlay.log" & HERO_CHROME_PID=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$HERO_CHROME_PORT/json/list" >/dev/null && break; sleep .05; done
    if timeout 45 node "$ROOT/tests/helpers/chrome_hero_theme.mjs" \
        "$HERO_CHROME_PORT" "$HTTP_BASE_URL" overlay >"$HTTP_TMP/chrome-hero-overlay-test.out" 2>>"$HTTP_TMP/chrome-hero-overlay.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-hero-overlay-test.out"
        pass H-HERO-OVERLAY-ALT
    else
        sed -n '1,160p' "$HTTP_TMP/chrome-hero-overlay-test.out" >&2
        fail H-HERO-OVERLAY-ALT 'Chromium no verificó overlay navy alternativo'
    fi
    kill -- "-$HERO_CHROME_PID" 2>/dev/null || kill "$HERO_CHROME_PID" 2>/dev/null || true
    wait "$HERO_CHROME_PID" 2>/dev/null || true
    HERO_CHROME_PID=""
else
    fail H-HERO-OVERLAY-ALT 'google-chrome o node no están disponibles'
fi

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=restore_cyberleo'
assert_status H-HERO-THEME-RESTORE-POST 302
assert_sql H-HERO-THEME-RESTORE-POST '#0057b8' "SELECT setting_value FROM store_settings WHERE setting_key='brand_primary_color'"
assert_sql H-HERO-THEME-RESTORE-POST '' "SELECT setting_value FROM store_settings WHERE setting_key='hero_background'"
assert_sql H-HERO-THEME-RESTORE-POST "$HERO_ALT_TITLE" "SELECT setting_value FROM store_settings WHERE setting_key='hero_title'"
assert_sql H-HERO-THEME-RESTORE-POST "$HERO_ALT_SUBTITLE" "SELECT setting_value FROM store_settings WHERE setting_key='hero_subtitle'"
pass H-HERO-THEME-RESTORE-POST

if [[ -n "$CHROME_BIN" ]] && command -v node >/dev/null 2>&1; then
    HERO_CHROME_PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    HERO_CHROME_COMMAND=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$HERO_CHROME_PORT" "--remote-allow-origins=*"
        "--user-data-dir=$HTTP_TMP/chrome-hero-restore-profile"
        about:blank)
    setsid "${HERO_CHROME_COMMAND[@]}" >"$HTTP_TMP/chrome-hero-restore.out" 2>"$HTTP_TMP/chrome-hero-restore.log" & HERO_CHROME_PID=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$HERO_CHROME_PORT/json/list" >/dev/null && break; sleep .05; done
    if timeout 45 node "$ROOT/tests/helpers/chrome_hero_theme.mjs" \
        "$HERO_CHROME_PORT" "$HTTP_BASE_URL" restore >"$HTTP_TMP/chrome-hero-restore-test.out" 2>>"$HTTP_TMP/chrome-hero-restore.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-hero-restore-test.out"
        rg -F --quiet "$HERO_ALT_TITLE" "$HTTP_TMP/chrome-hero-restore-test.out" ||
            fail H-HERO-THEME-RESTORE 'no conservó hero_title en la portada'
        rg -F --quiet "$HERO_ALT_SUBTITLE" "$HTTP_TMP/chrome-hero-restore-test.out" ||
            fail H-HERO-THEME-RESTORE 'no conservó hero_subtitle en la portada'
        pass H-HERO-THEME-RESTORE
    else
        sed -n '1,160p' "$HTTP_TMP/chrome-hero-restore-test.out" >&2
        fail H-HERO-THEME-RESTORE 'Chromium no verificó el hero restaurado'
    fi
    kill -- "-$HERO_CHROME_PID" 2>/dev/null || kill "$HERO_CHROME_PID" 2>/dev/null || true
    wait "$HERO_CHROME_PID" 2>/dev/null || true
    HERO_CHROME_PID=""
else
    fail H-HERO-THEME-RESTORE 'google-chrome o node no están disponibles'
fi

printf 'Pruebas HTTP Etapa 2 (contenido de portada)...\n'
request GET index.php
assert_status H-HOME2-DEFAULT 200
assert_body_contains H-HOME2-DEFAULT 'id="beneficios"'
assert_body_contains H-HOME2-DEFAULT 'Envíos y entregas'
assert_body_excludes H-HOME2-DEFAULT 'id="site-announcement"'
assert_body_excludes H-HOME2-DEFAULT 'id="promo-banner"'
pass H-HOME2-DEFAULT

settings_form 'HTTP Test Store' \
    -F 'announcement_enabled=1' \
    -F 'announcement_text=Aviso Stage2 <b>HTML</b>' \
    -F 'announcement_url=category.php?id=1' \
    -F 'announcement_style=navy' \
    -F 'promo_enabled=1' \
    -F 'promo_title=Promo Stage2' \
    -F 'promo_text=Texto promo Stage2' \
    -F 'promo_button_text=Ver oferta' \
    -F 'promo_button_url=#productos-destacados' \
    -F 'home_order_featured=3' \
    -F 'home_order_promo=4' \
    -F 'home_order_categories=2' \
    -F 'home_order_benefits=1' \
    -F 'footer_description=Footer Stage2' \
    -F 'footer_instagram_text=Instagram Stage2' \
    -F 'footer_whatsapp_text=WhatsApp Stage2' \
    -F 'footer_show_business_hours=1' \
    -F 'business_hours=Lunes a Viernes 9 a 18' \
    -F 'footer_show_location=1' \
    -F 'business_location=Buenos Aires' \
    -F 'instagram_url=https://instagram.com/cyberleo' \
    -F "promo_image_file=@$HTTP_TMP/tiny.png;type=image/png"
assert_status H-HOME2-SAVE 302
assert_sql H-HOME2-SAVE '1' "SELECT setting_value FROM store_settings WHERE setting_key='announcement_enabled'"
assert_sql H-HOME2-SAVE 'Aviso Stage2 HTML' "SELECT setting_value FROM store_settings WHERE setting_key='announcement_text'"
assert_sql H-HOME2-SAVE 'benefits,categories,featured,promo' "SELECT setting_value FROM store_settings WHERE setting_key='home_section_order'"
PROMO_IMAGE="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='promo_image'")"
[[ "$PROMO_IMAGE" =~ ^assets/images/settings/[a-f0-9]{32}\.png$ && -f "$ROOT/$PROMO_IMAGE" ]] ||
    fail H-HOME2-SAVE "promo_image inválida <$PROMO_IMAGE>"
CREATED_IMAGES+=("$PROMO_IMAGE")
pass H-HOME2-SAVE

request GET index.php
assert_status H-HOME2-FRONT 200
assert_body_contains H-HOME2-FRONT 'id="site-announcement"'
assert_body_contains H-HOME2-FRONT 'Aviso Stage2 HTML'
assert_body_excludes H-HOME2-FRONT '<b>HTML</b>'
assert_body_contains H-HOME2-FRONT 'id="promo-banner"'
assert_body_contains H-HOME2-FRONT 'Promo Stage2'
assert_body_contains H-HOME2-FRONT 'Footer Stage2'
assert_body_contains H-HOME2-FRONT 'Lunes a Viernes 9 a 18'
assert_body_contains H-HOME2-FRONT 'Buenos Aires'
php -r '
$body=file_get_contents($argv[1]);
$b=strpos($body,"id=\"beneficios\"");
$c=strpos($body,"id=\"categorias\"");
$f=strpos($body,"id=\"productos-destacados\"");
$p=strpos($body,"id=\"promo-banner\"");
if($b===false||$c===false||$f===false||$p===false){fwrite(STDERR,"missing section\n"); exit(1);}
if(!($b<$c && $c<$f && $f<$p)){fwrite(STDERR,"bad order b=$b c=$c f=$f p=$p\n"); exit(1);}
' "$HTTP_BODY" || fail H-HOME2-ORDER 'orden alternativo incorrecto en HTML'
pass H-HOME2-ORDER
pass H-HOME2-FRONT

settings_form 'HTTP Test Store' \
    -F 'announcement_url=https://evil.example/phish'
assert_status H-HOME2-BAD-URL 200
assert_body_contains H-HOME2-BAD-URL 'aviso superior'
assert_sql H-HOME2-BAD-URL 'category.php?id=1' "SELECT setting_value FROM store_settings WHERE setting_key='announcement_url'"
pass H-HOME2-BAD-URL

settings_form 'HTTP Test Store' \
    -F 'home_order_featured=1' \
    -F 'home_order_promo=1' \
    -F 'home_order_categories=3' \
    -F 'home_order_benefits=4'
assert_status H-HOME2-BAD-ORDER 200
assert_body_contains H-HOME2-BAD-ORDER 'orden de portada'
assert_sql H-HOME2-BAD-ORDER 'benefits,categories,featured,promo' "SELECT setting_value FROM store_settings WHERE setting_key='home_section_order'"
pass H-HOME2-BAD-ORDER

settings_form 'HTTP Test Store' \
    -F 'benefit_1_icon=bi-not-allowed' \
    -F 'announcement_enabled=1' \
    -F 'announcement_text=Aviso Stage2' \
    -F 'announcement_url=category.php?id=1' \
    -F 'promo_enabled=1' \
    -F 'promo_title=Promo Stage2' \
    -F 'promo_text=Texto promo Stage2' \
    -F 'promo_button_text=Ver oferta' \
    -F 'promo_button_url=#productos-destacados' \
    -F 'home_order_featured=3' \
    -F 'home_order_promo=4' \
    -F 'home_order_categories=2' \
    -F 'home_order_benefits=1'
assert_status H-HOME2-BAD-ICON 200
assert_body_contains H-HOME2-BAD-ICON 'Ícono'
pass H-HOME2-BAD-ICON

# Restaurar settings válidos Stage2 para Chromium
settings_form 'HTTP Test Store' \
    -F 'announcement_enabled=1' \
    -F 'announcement_text=Aviso Stage2' \
    -F 'announcement_url=category.php?id=1' \
    -F 'announcement_style=navy' \
    -F 'promo_enabled=1' \
    -F 'promo_title=Promo Stage2' \
    -F 'promo_text=Texto promo Stage2' \
    -F 'promo_button_text=Ver oferta' \
    -F 'promo_button_url=#productos-destacados' \
    -F 'home_order_featured=3' \
    -F 'home_order_promo=4' \
    -F 'home_order_categories=2' \
    -F 'home_order_benefits=1' \
    -F 'footer_description=Footer Stage2' \
    -F 'footer_instagram_text=Instagram Stage2' \
    -F 'footer_whatsapp_text=WhatsApp Stage2' \
    -F 'footer_show_business_hours=1' \
    -F 'business_hours=Lunes a Viernes 9 a 18' \
    -F 'footer_show_location=1' \
    -F 'business_location=Buenos Aires' \
    -F 'instagram_url=https://instagram.com/cyberleo'
assert_status H-HOME2-SAVE2 302
pass H-HOME2-SAVE2

run_home2_chrome() {
    local mode=$1
    local id=$2
    if [[ -z "$CHROME_BIN" ]] || ! command -v node >/dev/null 2>&1; then
        fail "$id" 'google-chrome o node no están disponibles'
        return
    fi
    local port
    port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    local cmd=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$port" "--remote-allow-origins=*"
        "--user-data-dir=$HTTP_TMP/chrome-home2-$mode-profile"
        about:blank)
    local pid=""
    setsid "${cmd[@]}" >"$HTTP_TMP/chrome-home2-$mode.out" 2>"$HTTP_TMP/chrome-home2-$mode.log" & pid=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$port/json/list" >/dev/null && break; sleep .05; done
    if timeout 45 node "$ROOT/tests/helpers/chrome_home_content.mjs" \
        "$port" "$HTTP_BASE_URL" "$mode" >"$HTTP_TMP/chrome-home2-$mode-test.out" 2>>"$HTTP_TMP/chrome-home2-$mode.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-home2-$mode-test.out"
        pass "$id"
    else
        sed -n '1,160p' "$HTTP_TMP/chrome-home2-$mode-test.out" >&2
        sed -n '1,80p' "$HTTP_TMP/chrome-home2-$mode.log" >&2
        fail "$id" "Chromium Stage2 mode=$mode falló"
    fi
    kill -- "-$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
}

run_home2_chrome alt-order H-HOME2-CHROME-ALT
run_home2_chrome banner H-HOME2-CHROME-BANNER
run_home2_chrome benefits H-HOME2-CHROME-BENEFITS
run_home2_chrome footer H-HOME2-CHROME-FOOTER
run_home2_chrome mobile H-HOME2-CHROME-MOBILE

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=save' \
    -F 'store_name=HTTP Test Store' \
    -F 'whatsapp_number=5491100000000' \
    -F 'instagram_url=https://instagram.com/cyberleo' \
    -F 'hero_title=HTTP hero' \
    -F 'hero_subtitle=HTTP subtitle' \
    -F 'reservation_minutes=120' \
    -F 'admin_email=admin-http@example.test' \
    -F 'mail_from=store-http@example.test' \
    -F 'payment_methods=Efectivo' \
    -F 'brand_primary_color=#0057b8' \
    -F 'brand_secondary_color=#00aeef' \
    -F 'brand_navy_color=#071a33' \
    -F 'brand_background_color=#f3f8fc' \
    -F 'brand_text_color=#111827' \
    -F 'brand_font=system' \
    -F 'nav_style=white' \
    -F 'button_radius=medium' \
    -F 'card_radius=medium' \
    -F 'hero_button_text=Explorar catálogo' \
    -F 'hero_button_url=#productos-destacados' \
    -F 'hero_height=normal' \
    -F 'hero_alignment=center' \
    -F 'hero_overlay=medium' \
    -F 'show_search=1' \
    -F 'announcement_enabled=1' \
    -F 'announcement_text=Aviso Stage2' \
    -F 'announcement_style=primary' \
    -F 'promo_enabled=1' \
    -F 'promo_title=Promo Stage2' \
    -F 'promo_text=x' \
    -F 'promo_button_text=Ver oferta' \
    -F 'promo_button_url=#' \
    -F 'home_order_featured=3' \
    -F 'home_order_promo=4' \
    -F 'home_order_categories=2' \
    -F 'home_order_benefits=1' \
    -F 'benefits_enabled=1' \
    -F 'benefit_1_icon=bi-truck' \
    -F 'benefit_1_title=Envíos y entregas' \
    -F 'benefit_1_text=Coordinamos la entrega o retiro de tu compra.' \
    -F 'benefit_2_icon=bi-shield-check' \
    -F 'benefit_2_title=Compra segura' \
    -F 'benefit_2_text=Stock actualizado y pedido confirmado por WhatsApp.' \
    -F 'benefit_3_icon=bi-headset' \
    -F 'benefit_3_title=Atención personalizada' \
    -F 'benefit_3_text=Te asesoramos para elegir la mejor opción.' \
    -F 'footer_description=Footer Stage2' \
    -F 'footer_instagram_text=Instagram Stage2' \
    -F 'footer_whatsapp_text=WhatsApp Stage2' \
    -F 'footer_show_logo=1' \
    -F 'footer_show_instagram=1' \
    -F 'footer_show_whatsapp=1' \
    -F 'business_hours=' \
    -F 'business_location='
assert_status H-HOME2-HIDDEN-SAVE 302
assert_sql H-HOME2-HIDDEN-SAVE '0' "SELECT setting_value FROM store_settings WHERE setting_key='show_featured_products'"
assert_sql H-HOME2-HIDDEN-SAVE '0' "SELECT setting_value FROM store_settings WHERE setting_key='show_categories'"
pass H-HOME2-HIDDEN-SAVE
run_home2_chrome hidden H-HOME2-CHROME-HIDDEN

printf 'Prueba show_search=0 (sin excepción JS)...\n'
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=save' \
    -F 'store_name=HTTP Test Store' \
    -F 'whatsapp_number=5491100000000' \
    -F 'instagram_url=https://instagram.com/cyberleo' \
    -F 'hero_title=HTTP hero' \
    -F 'hero_subtitle=HTTP subtitle' \
    -F 'reservation_minutes=120' \
    -F 'admin_email=admin-http@example.test' \
    -F 'mail_from=store-http@example.test' \
    -F 'payment_methods=Efectivo' \
    -F 'brand_primary_color=#0057b8' \
    -F 'brand_secondary_color=#00aeef' \
    -F 'brand_navy_color=#071a33' \
    -F 'brand_background_color=#f3f8fc' \
    -F 'brand_text_color=#111827' \
    -F 'brand_font=system' \
    -F 'nav_style=white' \
    -F 'button_radius=medium' \
    -F 'card_radius=medium' \
    -F 'hero_button_text=Explorar catálogo' \
    -F 'hero_button_url=#productos-destacados' \
    -F 'hero_height=normal' \
    -F 'hero_alignment=center' \
    -F 'hero_overlay=medium' \
    -F 'show_categories=1' \
    -F 'show_featured_products=1' \
    -F 'announcement_style=primary' \
    -F 'announcement_text=' \
    -F 'announcement_url=' \
    -F 'promo_title=' \
    -F 'promo_text=' \
    -F 'promo_button_text=Ver más' \
    -F 'promo_button_url=#' \
    -F 'home_order_featured=1' \
    -F 'home_order_promo=2' \
    -F 'home_order_categories=3' \
    -F 'home_order_benefits=4' \
    -F 'benefits_enabled=1' \
    -F 'benefit_1_icon=bi-truck' \
    -F 'benefit_1_title=Envíos y entregas' \
    -F 'benefit_1_text=Coordinamos la entrega o retiro de tu compra.' \
    -F 'benefit_2_icon=bi-shield-check' \
    -F 'benefit_2_title=Compra segura' \
    -F 'benefit_2_text=Stock actualizado y pedido confirmado por WhatsApp.' \
    -F 'benefit_3_icon=bi-headset' \
    -F 'benefit_3_title=Atención personalizada' \
    -F 'benefit_3_text=Te asesoramos para elegir la mejor opción.' \
    -F 'footer_description=Tecnología, periféricos y soluciones para tu equipo.' \
    -F 'footer_instagram_text=Seguinos en Instagram' \
    -F 'footer_whatsapp_text=Contactar por WhatsApp' \
    -F 'footer_show_logo=1' \
    -F 'footer_show_instagram=1' \
    -F 'footer_show_whatsapp=1' \
    -F 'business_hours=' \
    -F 'business_location='
assert_status H-HOME2-SEARCH-OFF-SAVE 302
assert_sql H-HOME2-SEARCH-OFF-SAVE '0' "SELECT setting_value FROM store_settings WHERE setting_key='show_search'"
pass H-HOME2-SEARCH-OFF-SAVE

request GET index.php
assert_status H-HOME2-SEARCH-OFF 200
assert_body_excludes H-HOME2-SEARCH-OFF 'id="searchProducts"'
assert_body_excludes H-HOME2-SEARCH-OFF 'id="searchResults"'
assert_body_contains H-HOME2-SEARCH-OFF 'id="productos-destacados"'
assert_body_contains H-HOME2-SEARCH-OFF 'id="categorias"'
assert_body_contains H-HOME2-SEARCH-OFF 'id="beneficios"'
pass H-HOME2-SEARCH-OFF

run_home2_chrome search-hidden H-HOME2-CHROME-SEARCH-HIDDEN

settings_form 'HTTP Test Store' \
    -F 'show_search=1' \
    -F 'show_categories=1' \
    -F 'show_featured_products=1' \
    -F 'home_order_featured=1' \
    -F 'home_order_promo=2' \
    -F 'home_order_categories=3' \
    -F 'home_order_benefits=4'
assert_status H-HOME2-SEARCH-ON-SAVE 302
assert_sql H-HOME2-SEARCH-ON-SAVE '1' "SELECT setting_value FROM store_settings WHERE setting_key='show_search'"
pass H-HOME2-SEARCH-ON-SAVE

# Instagram vacío no muestra enlace roto
settings_form 'HTTP Test Store' \
    -F 'instagram_url=' \
    -F 'footer_show_instagram=1' \
    -F 'footer_description=Footer Stage2' \
    -F 'footer_instagram_text=Instagram Stage2' \
    -F 'footer_whatsapp_text=WhatsApp Stage2' \
    -F 'home_order_featured=1' \
    -F 'home_order_promo=2' \
    -F 'home_order_categories=3' \
    -F 'home_order_benefits=4'
assert_status H-HOME2-IG-EMPTY-SAVE 302
request GET index.php
assert_status H-HOME2-IG-EMPTY 200
assert_body_excludes H-HOME2-IG-EMPTY 'Instagram Stage2'
assert_body_excludes H-HOME2-IG-EMPTY 'href=""'
pass H-HOME2-IG-EMPTY

# CSRF inválido en restore home
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F 'csrf_token=invalid-token' \
    -F 'settings_action=restore_home_content'
assert_status H-HOME2-RESTORE-CSRF 403
assert_sql H-HOME2-RESTORE-CSRF 'Footer Stage2' "SELECT setting_value FROM store_settings WHERE setting_key='footer_description'"
pass H-HOME2-RESTORE-CSRF

# Conservar identidad Stage1 y commercial al restaurar contenido
sql "UPDATE store_settings SET setting_value='#112233' WHERE setting_key='brand_primary_color'"
sql "UPDATE store_settings SET setting_value='5491199999999' WHERE setting_key='whatsapp_number'"
sql "INSERT INTO store_settings(setting_key,setting_value) VALUES('payment_methods','Efectivo, Mercado Pago') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
PROMO_BEFORE_RESTORE="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='promo_image'")"
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=restore_home_content'
assert_status H-HOME2-RESTORE 302
assert_header_contains H-HOME2-RESTORE 'Location: admin_settings.php?home_restored=1'
assert_sql H-HOME2-RESTORE '0' "SELECT setting_value FROM store_settings WHERE setting_key='announcement_enabled'"
assert_sql H-HOME2-RESTORE '0' "SELECT setting_value FROM store_settings WHERE setting_key='promo_enabled'"
assert_sql H-HOME2-RESTORE 'featured,promo,categories,benefits' "SELECT setting_value FROM store_settings WHERE setting_key='home_section_order'"
assert_sql H-HOME2-RESTORE 'Tecnología, periféricos y soluciones para tu equipo.' "SELECT setting_value FROM store_settings WHERE setting_key='footer_description'"
assert_sql H-HOME2-RESTORE '#112233' "SELECT setting_value FROM store_settings WHERE setting_key='brand_primary_color'"
assert_sql H-HOME2-RESTORE '5491199999999' "SELECT setting_value FROM store_settings WHERE setting_key='whatsapp_number'"
assert_sql H-HOME2-RESTORE 'Efectivo, Mercado Pago' "SELECT setting_value FROM store_settings WHERE setting_key='payment_methods'"
assert_sql H-HOME2-RESTORE '' "SELECT setting_value FROM store_settings WHERE setting_key='promo_image'"
if [[ -n "$PROMO_BEFORE_RESTORE" && -f "$ROOT/$PROMO_BEFORE_RESTORE" ]]; then
    fail H-HOME2-RESTORE 'promo_image no fue limpiada tras restore'
fi
pass H-HOME2-RESTORE

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=restore_home_content'
assert_status H-HOME2-RESTORE2 302
assert_sql H-HOME2-RESTORE2 '1' "SELECT setting_value FROM store_settings WHERE setting_key='benefits_enabled'"
pass H-HOME2-RESTORE2

run_home2_chrome restore H-HOME2-CHROME-RESTORE
run_home2_chrome default H-HOME2-CHROME-DEFAULT

run_nav_unify_chrome() {
    local mode=$1
    local id=$2
    if [[ -z "$CHROME_BIN" ]] || ! command -v node >/dev/null 2>&1; then
        fail "$id" 'google-chrome o node no están disponibles'
        return
    fi
    local port
    port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    local cmd=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$port" "--remote-allow-origins=*"
        "--user-data-dir=$HTTP_TMP/chrome-nav-unify-$mode-profile"
        about:blank)
    local pid=""
    setsid "${cmd[@]}" >"$HTTP_TMP/chrome-nav-unify-$mode.out" 2>"$HTTP_TMP/chrome-nav-unify-$mode.log" & pid=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$port/json/list" >/dev/null && break; sleep .05; done
    if timeout 60 node "$ROOT/tests/helpers/chrome_nav_unify.mjs" \
        "$port" "$HTTP_BASE_URL" "$mode" >"$HTTP_TMP/chrome-nav-unify-$mode-test.out" 2>>"$HTTP_TMP/chrome-nav-unify-$mode.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-nav-unify-$mode-test.out"
        pass "$id"
    else
        sed -n '1,200p' "$HTTP_TMP/chrome-nav-unify-$mode-test.out" >&2
        sed -n '1,80p' "$HTTP_TMP/chrome-nav-unify-$mode.log" >&2
        fail "$id" "Chromium nav unify mode=$mode falló"
    fi
    kill -- "-$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
}

# Ensure default Stage-2 footer/benefits for unify checks
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=restore_home_content'
assert_status H-NAV-UNIFY-RESTORE 302
pass H-NAV-UNIFY-RESTORE

run_nav_unify_chrome unify H-NAV-UNIFY-DESKTOP
run_nav_unify_chrome mobile H-NAV-UNIFY-MOBILE

# Footer toggles off: no empty contact column
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
settings_form 'HTTP Test Store' \
    -F 'footer_show_logo=1' \
    -F 'footer_description=Tecnología, periféricos y soluciones para tu equipo.' \
    -F 'footer_instagram_text=Seguinos en Instagram' \
    -F 'footer_whatsapp_text=Contactar por WhatsApp' \
    -F 'home_order_featured=1' \
    -F 'home_order_promo=2' \
    -F 'home_order_categories=3' \
    -F 'home_order_benefits=4' \
    -F 'benefits_enabled=1' \
    -F 'benefits_section_title=¿Por qué elegir CyberLeo?' \
    -F 'benefit_1_icon=bi-truck' \
    -F 'benefit_1_title=Envíos y entregas' \
    -F 'benefit_1_text=Coordinamos la entrega o retiro de tu compra.' \
    -F 'benefit_2_icon=bi-shield-check' \
    -F 'benefit_2_title=Compra segura' \
    -F 'benefit_2_text=Stock actualizado y pedido confirmado por WhatsApp.' \
    -F 'benefit_3_icon=bi-headset' \
    -F 'benefit_3_title=Atención personalizada' \
    -F 'benefit_3_text=Te asesoramos para elegir la mejor opción.'
assert_status H-NAV-FOOTER-TOGGLES-SAVE 302
# Explicitly clear optional footer toggles (unchecked checkboxes omitted)
sql "UPDATE store_settings SET setting_value='0' WHERE setting_key IN ('footer_show_instagram','footer_show_whatsapp','footer_show_business_hours','footer_show_location')"
pass H-NAV-FOOTER-TOGGLES-SAVE
run_nav_unify_chrome footer-toggles H-NAV-FOOTER-TOGGLES

# Error interno no expuesto
sql "CREATE TRIGGER settings_home_fail BEFORE UPDATE ON store_settings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SQLSTATE internal path=/workspace/secret.sql'"
settings_form 'HTTP Test Store' \
    -F 'announcement_enabled=1' \
    -F 'announcement_text=No debe persistir'
assert_status H-HOME2-INTERNAL 200
assert_body_contains H-HOME2-INTERNAL 'No se pudo guardar'
assert_body_excludes H-HOME2-INTERNAL '/workspace/secret.sql'
assert_body_excludes H-HOME2-INTERNAL 'SQLSTATE'
sql 'DROP TRIGGER IF EXISTS settings_home_fail'
pass H-HOME2-INTERNAL

printf 'Pruebas HTTP Etapa 3 (catálogo y tarjetas)...\n'
sql "UPDATE products SET description=CONCAT(COALESCE(description,''), ' ', REPEAT('detalle extendido ', 40)), price_sale=CASE WHEN id=1 THEN ROUND(price*0.8,2) ELSE price_sale END, destacados=IF(id<=2,id,destacados) WHERE id<=2"
request GET index.php
assert_status H-CATALOG3-DEFAULT 200
assert_body_contains H-CATALOG3-DEFAULT 'product-cols-3'
assert_body_contains H-CATALOG3-DEFAULT 'Productos Destacados'
assert_body_contains H-CATALOG3-DEFAULT 'product-card-elevated'
assert_body_contains H-CATALOG3-DEFAULT 'product-fit-contain'
assert_body_contains H-CATALOG3-DEFAULT 'product-height-normal'
assert_body_contains H-CATALOG3-DEFAULT 'ver-mas'
assert_body_contains H-CATALOG3-DEFAULT 'stock-display'
assert_body_contains H-CATALOG3-DEFAULT 'product-share'
assert_body_contains H-CATALOG3-DEFAULT 'Agregar al carrito'
assert_body_contains H-CATALOG3-DEFAULT 'assets/js/catalog-cards.js'
pass H-CATALOG3-DEFAULT

sql "UPDATE products SET description=CONCAT(description, ' ', REPEAT('detalle extendido ', 40)), price_sale=CASE WHEN id=1 THEN price*0.8 ELSE price_sale END, destacados=IF(id<=4,id,destacados) WHERE id<=4"
sql "UPDATE products SET image=NULL WHERE id=3"
sql "DELETE FROM product_images WHERE product_id=3"
SAFE_IMG="assets/images/products/$(php -r 'echo str_repeat("a",32);').png"
base64 --decode "$ROOT/tests/fixtures/tiny.png.b64" >"$ROOT/$SAFE_IMG"
CREATED_IMAGES+=("$SAFE_IMG")
file "$ROOT/$SAFE_IMG" | rg -q 'PNG image' || fail H-CATALOG3-FIXTURE-IMG 'fixture PNG inválido'
sql "UPDATE products SET image='$SAFE_IMG' WHERE id=1"
sql "DELETE FROM product_images WHERE product_id=1"
sql "INSERT INTO product_images(product_id,image_path,is_main) VALUES(1,'$SAFE_IMG',1),(1,'$SAFE_IMG',0)"
BAD_IMG_SQL="javascript:alert(1)"
sql "UPDATE products SET image='$BAD_IMG_SQL' WHERE id=2"

settings_form 'HTTP Test Store' \
    -F 'featured_section_title=Destacados Alt' \
    -F 'featured_empty_text=Vacío destacados alt' \
    -F 'catalog_empty_text=Vacío catálogo alt' \
    -F 'featured_columns=4' \
    -F 'catalog_columns=4' \
    -F 'product_card_style=minimal' \
    -F 'product_image_fit=cover' \
    -F 'product_image_height=large' \
    -F 'product_card_alignment=center' \
    -F 'product_description_mode=expandable' \
    -F 'product_description_length=100' \
    -F 'product_show_category_badge=1' \
    -F 'product_show_stock=1' \
    -F 'product_show_sale_badge=1' \
    -F 'product_show_old_price=1' \
    -F 'product_sale_badge_text=OFERTÓN' \
    -F 'product_show_share_buttons=1' \
    -F 'product_share_whatsapp=1' \
    -F 'product_share_facebook=1' \
    -F 'product_share_copy=1' \
    -F 'product_add_button_text=Sumar al carrito' \
    -F 'product_out_of_stock_text=Agotado' \
    -F 'catalog_show_breadcrumbs=1' \
    -F 'catalog_show_product_count=1' \
    -F 'catalog_show_subcategory_filter=1'
assert_status H-CATALOG3-SAVE 302
assert_sql H-CATALOG3-SAVE 'Destacados Alt' "SELECT setting_value FROM store_settings WHERE setting_key='featured_section_title'"
assert_sql H-CATALOG3-SAVE '4' "SELECT setting_value FROM store_settings WHERE setting_key='featured_columns'"
assert_sql H-CATALOG3-SAVE '4' "SELECT setting_value FROM store_settings WHERE setting_key='catalog_columns'"
assert_sql H-CATALOG3-SAVE 'minimal' "SELECT setting_value FROM store_settings WHERE setting_key='product_card_style'"
assert_sql H-CATALOG3-SAVE 'cover' "SELECT setting_value FROM store_settings WHERE setting_key='product_image_fit'"
assert_sql H-CATALOG3-SAVE 'OFERTÓN' "SELECT setting_value FROM store_settings WHERE setting_key='product_sale_badge_text'"
assert_sql H-CATALOG3-SAVE 'Sumar al carrito' "SELECT setting_value FROM store_settings WHERE setting_key='product_add_button_text'"
pass H-CATALOG3-SAVE

request GET index.php
assert_status H-CATALOG3-HOME 200
assert_body_contains H-CATALOG3-HOME 'Destacados Alt'
assert_body_contains H-CATALOG3-HOME 'product-cols-4'
assert_body_contains H-CATALOG3-HOME 'product-card-minimal'
assert_body_contains H-CATALOG3-HOME 'product-fit-cover'
assert_body_contains H-CATALOG3-HOME 'product-height-large'
assert_body_contains H-CATALOG3-HOME 'product-card-align-center'
assert_body_contains H-CATALOG3-HOME 'OFERTÓN'
assert_body_contains H-CATALOG3-HOME 'Sumar al carrito'
assert_body_contains H-CATALOG3-HOME 'Copiar enlace'
assert_body_contains H-CATALOG3-HOME 'bi-link-45deg'
pass H-CATALOG3-HOME

request GET 'category.php?id=1'
assert_status H-CATALOG3-CATEGORY 200
assert_body_contains H-CATALOG3-CATEGORY 'product-cols-4'
assert_body_contains H-CATALOG3-CATEGORY 'product-card-minimal'
assert_body_contains H-CATALOG3-CATEGORY 'aria-label="breadcrumb"'
assert_body_contains H-CATALOG3-CATEGORY 'producto'
assert_body_contains H-CATALOG3-CATEGORY 'Filtrar por subcategoría'
assert_body_contains H-CATALOG3-CATEGORY 'Sumar al carrito'
pass H-CATALOG3-CATEGORY

PREV_TITLE="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='featured_section_title'")"
PREV_STOCK_SHOW="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='product_show_stock'")"
PREV_FIT="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='product_image_fit'")"
settings_form 'HTTP Test Store' \
    -F 'featured_columns=9'
assert_status H-CATALOG3-BAD-OPTION 200
assert_body_contains H-CATALOG3-BAD-OPTION 'Opción inválida'
assert_sql H-CATALOG3-BAD-OPTION '4' "SELECT setting_value FROM store_settings WHERE setting_key='featured_columns'"
assert_sql H-CATALOG3-BAD-OPTION "$PREV_TITLE" "SELECT setting_value FROM store_settings WHERE setting_key='featured_section_title'"
pass H-CATALOG3-BAD-OPTION

# Booleano inválido en el recorrido real del formulario (no debe guardar nada)
settings_form 'HTTP Test Store' \
    --form-string 'product_show_stock=banana' \
    --form-string 'featured_section_title=NO DEBE GUARDARSE' \
    -F 'featured_columns=4' \
    -F 'catalog_columns=4' \
    -F 'product_image_fit=cover'
assert_status H-CATALOG3-BAD-BOOLEAN 200
assert_body_contains H-CATALOG3-BAD-BOOLEAN 'Booleano inválido'
assert_body_excludes H-CATALOG3-BAD-BOOLEAN 'SQLSTATE'
assert_body_excludes H-CATALOG3-BAD-BOOLEAN '/workspace/'
assert_body_excludes H-CATALOG3-BAD-BOOLEAN 'Stack trace'
assert_sql H-CATALOG3-BAD-BOOLEAN "$PREV_STOCK_SHOW" "SELECT setting_value FROM store_settings WHERE setting_key='product_show_stock'"
assert_sql H-CATALOG3-BAD-BOOLEAN "$PREV_TITLE" "SELECT setting_value FROM store_settings WHERE setting_key='featured_section_title'"
assert_sql H-CATALOG3-BAD-BOOLEAN "$PREV_FIT" "SELECT setting_value FROM store_settings WHERE setting_key='product_image_fit'"
assert_sql H-CATALOG3-BAD-BOOLEAN '4' "SELECT setting_value FROM store_settings WHERE setting_key='featured_columns'"
pass H-CATALOG3-BAD-BOOLEAN

# Restaurar fixture de título/fit tras el intento inválido (sigue siendo la config alternativa previa)
settings_form 'HTTP Test Store' \
    -F 'featured_section_title=Destacados Alt' \
    -F 'featured_empty_text=Vacío destacados alt' \
    -F 'catalog_empty_text=Vacío catálogo alt' \
    -F 'featured_columns=4' \
    -F 'catalog_columns=4' \
    -F 'product_card_style=minimal' \
    -F 'product_image_fit=cover' \
    -F 'product_image_height=large' \
    -F 'product_card_alignment=center' \
    -F 'product_description_mode=expandable' \
    -F 'product_description_length=100' \
    -F 'product_sale_badge_text=OFERTÓN' \
    -F 'product_add_button_text=Sumar al carrito' \
    -F 'product_out_of_stock_text=Agotado'
assert_status H-CATALOG3-BAD-BOOLEAN-RESTORE 302
pass H-CATALOG3-BAD-BOOLEAN-RESTORE

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=save' \
    -F 'store_name=HTTP Test Store' \
    -F 'whatsapp_number=5491100000000' \
    -F 'instagram_url=' \
    -F 'hero_title=HTTP hero' \
    -F 'hero_subtitle=HTTP subtitle' \
    -F 'reservation_minutes=120' \
    -F 'admin_email=admin-http@example.test' \
    -F 'mail_from=store-http@example.test' \
    -F 'payment_methods=Efectivo' \
    -F 'brand_primary_color=#0057b8' \
    -F 'brand_secondary_color=#00aeef' \
    -F 'brand_navy_color=#071a33' \
    -F 'brand_background_color=#f3f8fc' \
    -F 'brand_text_color=#111827' \
    -F 'brand_font=system' \
    -F 'nav_style=white' \
    -F 'button_radius=medium' \
    -F 'card_radius=medium' \
    -F 'hero_button_text=Explorar catálogo' \
    -F 'hero_button_url=#productos-destacados' \
    -F 'hero_height=normal' \
    -F 'hero_alignment=center' \
    -F 'hero_overlay=medium' \
    -F 'show_search=1' \
    -F 'show_categories=1' \
    -F 'show_featured_products=1' \
    -F 'announcement_style=primary' \
    -F 'announcement_text=' \
    -F 'announcement_url=' \
    -F 'promo_title=' \
    -F 'promo_text=' \
    -F 'promo_button_text=Ver más' \
    -F 'promo_button_url=#' \
    -F 'home_order_featured=1' \
    -F 'home_order_promo=2' \
    -F 'home_order_categories=3' \
    -F 'home_order_benefits=4' \
    -F 'benefits_enabled=1' \
    -F 'benefit_1_icon=bi-truck' \
    -F 'benefit_1_title=Envíos y entregas' \
    -F 'benefit_1_text=Coordinamos la entrega o retiro de tu compra.' \
    -F 'benefit_2_icon=bi-shield-check' \
    -F 'benefit_2_title=Compra segura' \
    -F 'benefit_2_text=Stock actualizado y pedido confirmado por WhatsApp.' \
    -F 'benefit_3_icon=bi-headset' \
    -F 'benefit_3_title=Atención personalizada' \
    -F 'benefit_3_text=Te asesoramos para elegir la mejor opción.' \
    -F 'footer_description=Tecnología, periféricos y soluciones para tu equipo.' \
    -F 'footer_instagram_text=Seguinos en Instagram' \
    -F 'footer_whatsapp_text=Contactar por WhatsApp' \
    -F 'footer_show_logo=1' \
    -F 'footer_show_instagram=1' \
    -F 'footer_show_whatsapp=1' \
    -F 'business_hours=' \
    -F 'business_location=' \
    -F 'featured_section_title=Destacados Alt' \
    -F 'featured_empty_text=Vacío destacados alt' \
    -F 'catalog_empty_text=Vacío catálogo alt' \
    -F 'featured_columns=4' \
    -F 'catalog_columns=4' \
    -F 'product_card_style=minimal' \
    -F 'product_image_fit=cover' \
    -F 'product_image_height=large' \
    -F 'product_card_alignment=center' \
    -F 'product_description_mode=hidden' \
    -F 'product_description_length=100' \
    -F 'product_sale_badge_text=OFERTÓN' \
    -F 'product_add_button_text=Sumar al carrito' \
    -F 'product_out_of_stock_text=Agotado'
assert_status H-CATALOG3-HIDDEN-SAVE 302
assert_sql H-CATALOG3-HIDDEN-SAVE '0' "SELECT setting_value FROM store_settings WHERE setting_key='product_show_stock'"
assert_sql H-CATALOG3-HIDDEN-SAVE '0' "SELECT setting_value FROM store_settings WHERE setting_key='product_show_share_buttons'"
assert_sql H-CATALOG3-HIDDEN-SAVE '0' "SELECT setting_value FROM store_settings WHERE setting_key='catalog_show_breadcrumbs'"
assert_sql H-CATALOG3-HIDDEN-SAVE 'hidden' "SELECT setting_value FROM store_settings WHERE setting_key='product_description_mode'"
pass H-CATALOG3-HIDDEN-SAVE

request GET 'category.php?id=1'
assert_status H-CATALOG3-HIDDEN 200
assert_body_excludes H-CATALOG3-HIDDEN 'description-container'
assert_body_excludes H-CATALOG3-HIDDEN 'product-share'
assert_body_excludes H-CATALOG3-HIDDEN 'product-sale-badge'
assert_body_excludes H-CATALOG3-HIDDEN 'aria-label="breadcrumb"'
assert_body_excludes H-CATALOG3-HIDDEN 'Filtrar por subcategoría'
assert_body_excludes H-CATALOG3-HIDDEN '(Stock:'
pass H-CATALOG3-HIDDEN

settings_form 'HTTP XSS Catalog' \
    --form-string 'featured_section_title=<script>globalThis.catalogXssExecuted=1</script>XSS Title' \
    --form-string 'featured_empty_text=<img src=x onerror=globalThis.catalogXssExecuted=2>Vacío' \
    --form-string 'catalog_empty_text=<svg onload=globalThis.catalogXssExecuted=3>Cat' \
    --form-string 'product_sale_badge_text=<script>bad</script>BADGE' \
    --form-string 'product_add_button_text=<img src=x onerror=1>Botón' \
    --form-string 'product_out_of_stock_text=<b>OOS</b>' \
    -F 'featured_columns=3' \
    -F 'catalog_columns=3' \
    -F 'product_card_style=elevated' \
    -F 'product_image_fit=contain' \
    -F 'product_image_height=normal' \
    -F 'product_card_alignment=left' \
    -F 'product_description_mode=expandable' \
    -F 'product_description_length=200' \
    -F 'product_show_category_badge=1' \
    -F 'product_show_stock=1' \
    -F 'product_show_sale_badge=1' \
    -F 'product_show_old_price=1' \
    -F 'product_show_share_buttons=1' \
    -F 'product_share_whatsapp=1' \
    -F 'product_share_facebook=1' \
    -F 'product_share_copy=1' \
    -F 'catalog_show_breadcrumbs=1' \
    -F 'catalog_show_product_count=1' \
    -F 'catalog_show_subcategory_filter=1'
assert_status H-CATALOG3-XSS-SAVE 302
sql "UPDATE products SET name='<script>globalThis.catalogXssExecuted=9</script>Prod', description='Desc <img src=x onerror=globalThis.catalogXssExecuted=8>', category_id=1 WHERE id=1"
sql "UPDATE categories SET name='<b>CatXSS</b>' WHERE id=1"
request GET index.php
assert_status H-CATALOG3-XSS 200
assert_body_excludes H-CATALOG3-XSS '<script>globalThis.catalogXssExecuted=1</script>'
assert_body_excludes H-CATALOG3-XSS '<img src=x onerror=globalThis.catalogXssExecuted'
assert_body_excludes H-CATALOG3-XSS '<script>globalThis.catalogXssExecuted=9</script>'
assert_body_excludes H-CATALOG3-XSS 'onerror="'
assert_body_excludes H-CATALOG3-XSS "onerror='"
assert_body_contains H-CATALOG3-XSS 'XSS Title'
assert_body_contains H-CATALOG3-XSS '&lt;script&gt;'
pass H-CATALOG3-XSS

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F 'csrf_token=invalid-csrf' \
    -F 'settings_action=restore_catalog_display'
assert_status H-CATALOG3-RESTORE-CSRF 403
assert_sql H-CATALOG3-RESTORE-CSRF '3' "SELECT setting_value FROM store_settings WHERE setting_key='featured_columns'"
pass H-CATALOG3-RESTORE-CSRF

sql "UPDATE store_settings SET setting_value='#abcdef' WHERE setting_key='brand_primary_color'"
sql "INSERT INTO store_settings(setting_key,setting_value) VALUES('brand_primary_color','#abcdef') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
sql "INSERT INTO store_settings(setting_key,setting_value) VALUES('footer_description','Footer Keep Stage2') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
sql "INSERT INTO store_settings(setting_key,setting_value) VALUES('whatsapp_number','5491100000000') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
STOCK_BEFORE="$(sql 'SELECT stock FROM products WHERE id=1')"

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=restore_catalog_display'
assert_status H-CATALOG3-RESTORE 302
assert_header_contains H-CATALOG3-RESTORE 'Location: admin_settings.php?catalog_restored=1'
assert_sql H-CATALOG3-RESTORE '3' "SELECT setting_value FROM store_settings WHERE setting_key='featured_columns'"
assert_sql H-CATALOG3-RESTORE 'elevated' "SELECT setting_value FROM store_settings WHERE setting_key='product_card_style'"
assert_sql H-CATALOG3-RESTORE 'Productos Destacados' "SELECT setting_value FROM store_settings WHERE setting_key='featured_section_title'"
assert_sql H-CATALOG3-RESTORE '#abcdef' "SELECT setting_value FROM store_settings WHERE setting_key='brand_primary_color'"
assert_sql H-CATALOG3-RESTORE 'Footer Keep Stage2' "SELECT setting_value FROM store_settings WHERE setting_key='footer_description'"
assert_sql H-CATALOG3-RESTORE '5491100000000' "SELECT setting_value FROM store_settings WHERE setting_key='whatsapp_number'"
assert_sql H-CATALOG3-RESTORE "$STOCK_BEFORE" 'SELECT stock FROM products WHERE id=1'
pass H-CATALOG3-RESTORE

# Restore alternate config for browser/cart/images checks
settings_form 'HTTP Test Store' \
    -F 'featured_section_title=Destacados Alt' \
    -F 'featured_columns=4' \
    -F 'catalog_columns=4' \
    -F 'product_card_style=bordered' \
    -F 'product_image_fit=cover' \
    -F 'product_image_height=compact' \
    -F 'product_card_alignment=center' \
    -F 'product_description_mode=expandable' \
    -F 'product_description_length=100' \
    -F 'product_show_category_badge=1' \
    -F 'product_show_stock=1' \
    -F 'product_show_sale_badge=1' \
    -F 'product_show_old_price=1' \
    -F 'product_sale_badge_text=OFERTÓN' \
    -F 'product_show_share_buttons=1' \
    -F 'product_share_whatsapp=1' \
    -F 'product_share_facebook=1' \
    -F 'product_share_copy=1' \
    -F 'product_add_button_text=Sumar al carrito' \
    -F 'product_out_of_stock_text=Agotado' \
    -F 'catalog_show_breadcrumbs=1' \
    -F 'catalog_show_product_count=1' \
    -F 'catalog_show_subcategory_filter=1'
assert_status H-CATALOG3-ALT-READY 302
pass H-CATALOG3-ALT-READY

request GET index.php
assert_status H-CATALOG3-IMAGES 200
assert_body_contains H-CATALOG3-IMAGES 'product-fit-cover'
assert_body_contains H-CATALOG3-IMAGES 'Sin imagen'
assert_body_excludes H-CATALOG3-IMAGES 'javascript:alert(1)'
assert_body_contains H-CATALOG3-IMAGES "$SAFE_IMG"
pass H-CATALOG3-IMAGES

run_catalog3_chrome() {
    local mode=$1
    local id=$2
    if [[ -z "$CHROME_BIN" ]] || ! command -v node >/dev/null 2>&1; then
        fail "$id" 'google-chrome o node no están disponibles'
    fi
    local port
    port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    local profile="$HTTP_TMP/chrome-catalog-$mode-profile"
    mkdir -p "$profile"
    local cmd=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$port" "--remote-allow-origins=*"
        "--user-data-dir=$profile"
        about:blank)
    setsid "${cmd[@]}" >"$HTTP_TMP/chrome-catalog-$mode.out" 2>"$HTTP_TMP/chrome-catalog-$mode.log" & local cpid=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$port/json/list" >/dev/null && break; sleep .05; done
    if timeout 55 node "$ROOT/tests/helpers/chrome_catalog_display.mjs" \
        "$port" "$HTTP_BASE_URL" "$mode" >"$HTTP_TMP/chrome-catalog-$mode-test.out" 2>>"$HTTP_TMP/chrome-catalog-$mode.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-catalog-$mode-test.out"
        rg -F --quiet '"browserErrors": 0' "$HTTP_TMP/chrome-catalog-$mode-test.out" ||
            fail "$id" 'browserErrors distinto de 0'
        pass "$id"
    else
        sed -n '1,200p' "$HTTP_TMP/chrome-catalog-$mode-test.out" >&2
        sed -n '1,80p' "$HTTP_TMP/chrome-catalog-$mode.log" >&2
        fail "$id" "Chromium catalog mode=$mode falló"
    fi
    kill -- "-$cpid" 2>/dev/null || kill "$cpid" 2>/dev/null || true
    wait "$cpid" 2>/dev/null || true
}

CHROME_BIN="$(command -v google-chrome-stable || command -v google-chrome || true)"

# Defaults for B-CATALOG3-DEFAULT
settings_form 'HTTP Test Store'
assert_status H-CATALOG3-DEFAULT-RESET 302
pass H-CATALOG3-DEFAULT-RESET
sql "UPDATE products SET name='Producto Seguro', description=CONCAT('Descripción segura ', REPEAT('detalle ', 50)), destacados=IF(id<=4,id,0) WHERE id<=4"
sql "UPDATE categories SET name='Notebooks' WHERE id=1"
run_catalog3_chrome default B-CATALOG3-DEFAULT

settings_form 'HTTP Test Store' \
    -F 'featured_section_title=Destacados Alt' \
    -F 'featured_columns=4' \
    -F 'catalog_columns=4' \
    -F 'product_card_style=minimal' \
    -F 'product_image_fit=cover' \
    -F 'product_image_height=large' \
    -F 'product_card_alignment=center' \
    -F 'product_description_mode=expandable' \
    -F 'product_description_length=100' \
    -F 'product_sale_badge_text=OFERTÓN' \
    -F 'product_add_button_text=Sumar al carrito'
assert_status H-CATALOG3-ALT-HOME-SAVE 302
# Ensure product 1 still has the real PNG fixture before cover/contain probes
sql "UPDATE products SET image='$SAFE_IMG' WHERE id=1"
[[ -f "$ROOT/$SAFE_IMG" ]] || fail B-CATALOG3-IMAGE-COVER 'fixture PNG ausente'
run_catalog3_chrome alt-home B-CATALOG3-ALT-HOME
run_catalog3_chrome image-cover B-CATALOG3-IMAGE-COVER
run_catalog3_chrome alt-category B-CATALOG3-ALT-CATEGORY
run_catalog3_chrome cols-4 B-CATALOG3-4-COLUMNS
run_catalog3_chrome mobile-390 B-CATALOG3-MOBILE-390
run_catalog3_chrome description-expand B-CATALOG3-DESCRIPTION-EXPAND
run_catalog3_chrome cart B-CATALOG3-CART
run_catalog3_chrome copy B-CATALOG3-COPY
run_catalog3_chrome image-error B-CATALOG3-IMAGE-ERROR

settings_form 'HTTP Test Store' \
    -F 'featured_columns=2' \
    -F 'catalog_columns=2'
run_catalog3_chrome cols-2 B-CATALOG3-2-COLUMNS
settings_form 'HTTP Test Store' \
    -F 'featured_columns=3' \
    -F 'catalog_columns=3' \
    -F 'product_image_fit=contain' \
    -F 'product_image_height=normal'
run_catalog3_chrome cols-3 B-CATALOG3-3-COLUMNS
run_catalog3_chrome image-contain B-CATALOG3-IMAGE-CONTAIN

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=save' \
    -F 'store_name=HTTP Test Store' \
    -F 'whatsapp_number=5491100000000' \
    -F 'instagram_url=' \
    -F 'hero_title=HTTP hero' \
    -F 'hero_subtitle=HTTP subtitle' \
    -F 'reservation_minutes=120' \
    -F 'admin_email=admin-http@example.test' \
    -F 'mail_from=store-http@example.test' \
    -F 'payment_methods=Efectivo' \
    -F 'brand_primary_color=#0057b8' \
    -F 'brand_secondary_color=#00aeef' \
    -F 'brand_navy_color=#071a33' \
    -F 'brand_background_color=#f3f8fc' \
    -F 'brand_text_color=#111827' \
    -F 'brand_font=system' \
    -F 'nav_style=white' \
    -F 'button_radius=medium' \
    -F 'card_radius=medium' \
    -F 'hero_button_text=Explorar catálogo' \
    -F 'hero_button_url=#productos-destacados' \
    -F 'hero_height=normal' \
    -F 'hero_alignment=center' \
    -F 'hero_overlay=medium' \
    -F 'show_search=1' \
    -F 'show_categories=1' \
    -F 'show_featured_products=1' \
    -F 'announcement_style=primary' \
    -F 'announcement_text=' \
    -F 'announcement_url=' \
    -F 'promo_title=' \
    -F 'promo_text=' \
    -F 'promo_button_text=Ver más' \
    -F 'promo_button_url=#' \
    -F 'home_order_featured=1' \
    -F 'home_order_promo=2' \
    -F 'home_order_categories=3' \
    -F 'home_order_benefits=4' \
    -F 'benefits_enabled=1' \
    -F 'benefit_1_icon=bi-truck' \
    -F 'benefit_1_title=Envíos y entregas' \
    -F 'benefit_1_text=Coordinamos la entrega o retiro de tu compra.' \
    -F 'benefit_2_icon=bi-shield-check' \
    -F 'benefit_2_title=Compra segura' \
    -F 'benefit_2_text=Stock actualizado y pedido confirmado por WhatsApp.' \
    -F 'benefit_3_icon=bi-headset' \
    -F 'benefit_3_title=Atención personalizada' \
    -F 'benefit_3_text=Te asesoramos para elegir la mejor opción.' \
    -F 'footer_description=Tecnología, periféricos y soluciones para tu equipo.' \
    -F 'footer_instagram_text=Seguinos en Instagram' \
    -F 'footer_whatsapp_text=Contactar por WhatsApp' \
    -F 'footer_show_logo=1' \
    -F 'footer_show_instagram=1' \
    -F 'footer_show_whatsapp=1' \
    -F 'business_hours=' \
    -F 'business_location=' \
    -F 'featured_section_title=Productos Destacados' \
    -F 'featured_empty_text=No hay productos destacados disponibles.' \
    -F 'catalog_empty_text=No hay productos disponibles en esta categoría.' \
    -F 'featured_columns=3' \
    -F 'catalog_columns=3' \
    -F 'product_card_style=elevated' \
    -F 'product_image_fit=contain' \
    -F 'product_image_height=normal' \
    -F 'product_card_alignment=left' \
    -F 'product_description_mode=hidden' \
    -F 'product_description_length=200' \
    -F 'product_sale_badge_text=LIQUIDACIÓN' \
    -F 'product_add_button_text=Agregar al carrito' \
    -F 'product_out_of_stock_text=Sin stock'
run_catalog3_chrome hidden B-CATALOG3-HIDDEN

settings_form 'HTTP XSS Catalog' \
    --form-string 'featured_section_title=<script>globalThis.catalogXssExecuted=1</script>XSS Title' \
    --form-string 'featured_empty_text=<img src=x onerror=globalThis.catalogXssExecuted=2>Vacío' \
    --form-string 'catalog_empty_text=<svg onload=globalThis.catalogXssExecuted=3>Cat' \
    --form-string 'product_sale_badge_text=<script>bad</script>BADGE' \
    --form-string 'product_add_button_text=<img src=x onerror=1>Botón' \
    -F 'featured_columns=3' \
    -F 'catalog_columns=3' \
    -F 'product_description_mode=expandable' \
    -F 'product_show_stock=1' \
    -F 'product_show_sale_badge=1' \
    -F 'product_show_share_buttons=1'
sql "UPDATE products SET name='<script>globalThis.catalogXssExecuted=9</script>Prod', description='Desc <img src=x onerror=globalThis.catalogXssExecuted=8>' WHERE id=1"
run_catalog3_chrome xss B-CATALOG3-XSS

# H-CATALOG3-CART HTTP smoke: schema already validated in Chromium; confirm markup datasets
settings_form 'HTTP Test Store' \
    -F 'product_add_button_text=Sumar al carrito' \
    -F 'product_out_of_stock_text=Agotado' \
    -F 'product_show_stock=1' \
    -F 'featured_columns=3'
request GET index.php
assert_status H-CATALOG3-CART 200
assert_body_contains H-CATALOG3-CART 'data-add-text="Sumar al carrito"'
assert_body_contains H-CATALOG3-CART 'data-oos-text="Agotado"'
assert_body_contains H-CATALOG3-CART 'data-product-id='
assert_body_contains H-CATALOG3-CART 'data-product-price='
pass H-CATALOG3-CART

printf 'Pruebas HTTP Etapa 4 (carrito / checkout)...\n'
settings_form 'HTTP Test Store'
assert_status H-CHECKOUT4-DEFAULT-RESET 302
pass H-CHECKOUT4-DEFAULT-RESET

request GET cart.php
assert_status H-CHECKOUT4-DEFAULT 200
assert_body_contains H-CHECKOUT4-DEFAULT 'Carrito de Compras'
assert_body_contains H-CHECKOUT4-DEFAULT 'Productos en tu carrito'
assert_body_contains H-CHECKOUT4-DEFAULT 'Resumen del pedido'
assert_body_contains H-CHECKOUT4-DEFAULT 'Enviar Pedido por WhatsApp'
assert_body_contains H-CHECKOUT4-DEFAULT 'assets/js/cart-checkout.js'
assert_body_contains H-CHECKOUT4-DEFAULT 'cart-checkout-boot'
assert_body_contains H-CHECKOUT4-DEFAULT 'cart-layout-standard'
assert_body_contains H-CHECKOUT4-DEFAULT 'Información de envío'
assert_body_contains H-CHECKOUT4-DEFAULT 'Métodos de pago'
assert_body_excludes H-CHECKOUT4-DEFAULT 'cart-reservation-note'
pass H-CHECKOUT4-DEFAULT

request GET admin_settings.php
assert_status H-CHECKOUT4-ADMIN 200
assert_body_contains H-CHECKOUT4-ADMIN 'Carrito y pedido'
assert_body_contains H-CHECKOUT4-ADMIN 'checkout-preview-card'
assert_body_contains H-CHECKOUT4-ADMIN 'assets/js/checkout-preview.js'
assert_body_contains H-CHECKOUT4-ADMIN 'Restaurar carrito y pedido'
pass H-CHECKOUT4-ADMIN

settings_form 'HTTP Checkout Alt' \
    -F 'cart_page_title=Carrito Alt' \
    -F 'cart_items_title=Items Alt' \
    -F 'cart_summary_title=Resumen Alt' \
    -F 'cart_total_label=Importe:' \
    -F 'cart_order_button_text=Pedir por WhatsApp' \
    -F 'cart_continue_button_text=Volver al catálogo' \
    -F 'cart_layout=compact' \
    -F 'cart_image_fit=contain' \
    -F 'cart_image_size=large' \
    -F 'cart_show_images=1' \
    -F 'cart_show_sale_badge=1' \
    -F 'cart_show_old_price=1' \
    -F 'cart_show_stock_status=1' \
    -F 'cart_show_delivery_info=1' \
    -F 'cart_show_delivery_methods=1' \
    -F 'cart_delivery_methods=Retiro en local, Envío a domicilio' \
    -F 'cart_show_payment_methods=1' \
    -F 'cart_show_reservation_note=1' \
    -F 'cart_summary_sticky=1' \
    -F 'cart_terms_enabled=1' \
    -F 'cart_terms_text=Condiciones de compra aplicables' \
    -F 'cart_terms_url=terminos.php' \
    -F 'cart_reservation_text=Reserva por {minutes} minutos.' \
    -F 'order_whatsapp_template=Pedido #{order_id}

{items}

Total: {total}

Tienda: {store_name}'
assert_status H-CHECKOUT4-SAVE 302
assert_sql H-CHECKOUT4-SAVE 'Carrito Alt' "SELECT setting_value FROM store_settings WHERE setting_key='cart_page_title'"
assert_sql H-CHECKOUT4-SAVE 'compact' "SELECT setting_value FROM store_settings WHERE setting_key='cart_layout'"
assert_sql H-CHECKOUT4-SAVE '1' "SELECT setting_value FROM store_settings WHERE setting_key='cart_show_delivery_methods'"
assert_sql H-CHECKOUT4-SAVE '1' "SELECT setting_value FROM store_settings WHERE setting_key='cart_show_reservation_note'"
assert_sql H-CHECKOUT4-SAVE '1' "SELECT setting_value FROM store_settings WHERE setting_key='cart_terms_enabled'"
pass H-CHECKOUT4-SAVE

request GET cart.php
assert_status H-CHECKOUT4-ALT 200
assert_body_contains H-CHECKOUT4-ALT 'Carrito Alt'
assert_body_contains H-CHECKOUT4-ALT 'Items Alt'
assert_body_contains H-CHECKOUT4-ALT 'Resumen Alt'
assert_body_contains H-CHECKOUT4-ALT 'Importe:'
assert_body_contains H-CHECKOUT4-ALT 'Pedir por WhatsApp'
assert_body_contains H-CHECKOUT4-ALT 'cart-layout-compact'
assert_body_contains H-CHECKOUT4-ALT 'cart-summary-sticky'
assert_body_contains H-CHECKOUT4-ALT 'Retiro en local'
assert_body_contains H-CHECKOUT4-ALT 'Envío a domicilio'
assert_body_contains H-CHECKOUT4-ALT 'Reserva por'
assert_body_contains H-CHECKOUT4-ALT 'Condiciones de compra aplicables'
assert_body_contains H-CHECKOUT4-ALT 'terminos.php'
pass H-CHECKOUT4-ALT

settings_form 'HTTP Checkout Alt' \
    -F 'cart_page_title=Carrito Oculto' \
    -F 'cart_show_images=0' \
    -F 'cart_show_sale_badge=0' \
    -F 'cart_show_old_price=0' \
    -F 'cart_show_stock_status=0' \
    -F 'cart_show_delivery_info=0' \
    -F 'cart_show_payment_methods=0' \
    -F 'cart_show_reservation_note=0' \
    -F 'cart_terms_enabled=0' \
    -F 'cart_layout=standard'
assert_status H-CHECKOUT4-HIDDEN-SAVE 302
request GET cart.php
assert_status H-CHECKOUT4-HIDDEN 200
assert_body_contains H-CHECKOUT4-HIDDEN 'Carrito Oculto'
assert_body_excludes H-CHECKOUT4-HIDDEN 'cart-delivery-block'
assert_body_excludes H-CHECKOUT4-HIDDEN 'cart-payment-block'
assert_body_excludes H-CHECKOUT4-HIDDEN 'cart-reservation-note'
assert_body_excludes H-CHECKOUT4-HIDDEN 'cart-terms-block'
pass H-CHECKOUT4-HIDDEN

PREV_TITLE="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='cart_page_title'")"
settings_form 'HTTP Checkout Alt' \
    -F 'cart_page_title=No Debe Guardarse' \
    -F 'cart_layout=neon'
assert_status H-CHECKOUT4-BAD-OPTION 200
assert_body_contains H-CHECKOUT4-BAD-OPTION 'Opción inválida'
assert_sql H-CHECKOUT4-BAD-OPTION "$PREV_TITLE" "SELECT setting_value FROM store_settings WHERE setting_key='cart_page_title'"
pass H-CHECKOUT4-BAD-OPTION

PREV_SHOW="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='cart_show_images'")"
settings_form 'HTTP Checkout Alt' \
    -F 'cart_page_title=No Bool' \
    -F 'cart_show_images=banana'
assert_status H-CHECKOUT4-BAD-BOOLEAN 200
assert_body_contains H-CHECKOUT4-BAD-BOOLEAN 'Booleano inválido'
assert_body_excludes H-CHECKOUT4-BAD-BOOLEAN 'SQLSTATE'
assert_body_excludes H-CHECKOUT4-BAD-BOOLEAN '/workspace/'
assert_body_excludes H-CHECKOUT4-BAD-BOOLEAN 'Stack trace'
assert_sql H-CHECKOUT4-BAD-BOOLEAN "$PREV_SHOW" "SELECT setting_value FROM store_settings WHERE setting_key='cart_show_images'"
assert_sql H-CHECKOUT4-BAD-BOOLEAN "$PREV_TITLE" "SELECT setting_value FROM store_settings WHERE setting_key='cart_page_title'"
pass H-CHECKOUT4-BAD-BOOLEAN

settings_form 'HTTP Checkout Alt' \
    -F 'cart_page_title=No Template' \
    --form-string 'order_whatsapp_template=Hola {order_id} sin items ni total'
assert_status H-CHECKOUT4-BAD-TEMPLATE 200
assert_body_contains H-CHECKOUT4-BAD-TEMPLATE 'Plantilla de WhatsApp inválido'
assert_sql H-CHECKOUT4-BAD-TEMPLATE "$PREV_TITLE" "SELECT setting_value FROM store_settings WHERE setting_key='cart_page_title'"
pass H-CHECKOUT4-BAD-TEMPLATE

settings_form 'HTTP Checkout Alt' \
    -F 'cart_page_title=No URL' \
    -F 'cart_terms_enabled=1' \
    -F 'cart_terms_text=Leé los términos' \
    -F 'cart_terms_url=javascript:alert(1)'
assert_status H-CHECKOUT4-BAD-URL 200
assert_body_contains H-CHECKOUT4-BAD-URL 'URL de términos inválido'
assert_sql H-CHECKOUT4-BAD-URL "$PREV_TITLE" "SELECT setting_value FROM store_settings WHERE setting_key='cart_page_title'"
pass H-CHECKOUT4-BAD-URL

settings_form 'HTTP XSS Checkout' \
    --form-string 'cart_page_title=<script>globalThis.checkoutXssExecuted=1</script>XSS Cart' \
    --form-string 'cart_delivery_text=<img src=x onerror=globalThis.checkoutXssExecuted=2>Envío' \
    --form-string 'cart_order_button_text=<svg onload=globalThis.checkoutXssExecuted=3>WA' \
    -F 'cart_show_images=1' \
    -F 'cart_show_delivery_info=1' \
    -F 'cart_show_payment_methods=1' \
    -F 'cart_layout=standard'
assert_status H-CHECKOUT4-XSS-SAVE 302
request GET cart.php
assert_status H-CHECKOUT4-XSS 200
assert_body_excludes H-CHECKOUT4-XSS '<script>globalThis.checkoutXssExecuted=1</script>'
assert_body_excludes H-CHECKOUT4-XSS '<img src=x onerror=globalThis.checkoutXssExecuted'
assert_body_excludes H-CHECKOUT4-XSS 'onerror="'
assert_body_excludes H-CHECKOUT4-XSS "onerror='"
assert_body_contains H-CHECKOUT4-XSS 'XSS Cart'
assert_body_contains H-CHECKOUT4-XSS 'Envío'
pass H-CHECKOUT4-XSS

request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
sql "UPDATE store_settings SET setting_value='compact' WHERE setting_key='cart_layout'"
request POST admin_settings.php \
    -F 'csrf_token=invalid' \
    -F 'settings_action=restore_checkout_display'
assert_status H-CHECKOUT4-RESTORE-CSRF 403
assert_sql H-CHECKOUT4-RESTORE-CSRF 'compact' "SELECT setting_value FROM store_settings WHERE setting_key='cart_layout'"
pass H-CHECKOUT4-RESTORE-CSRF

sql "UPDATE store_settings SET setting_value='#fedcba' WHERE setting_key='brand_primary_color'"
sql "UPDATE store_settings SET setting_value='Footer Keep Checkout' WHERE setting_key='footer_description'"
sql "UPDATE store_settings SET setting_value='4' WHERE setting_key='featured_columns'"
sql "UPDATE store_settings SET setting_value='5491199988877' WHERE setting_key='whatsapp_number'"
sql "UPDATE store_settings SET setting_value='Transferencia, Efectivo' WHERE setting_key='payment_methods'"
sql "UPDATE store_settings SET setting_value='77' WHERE setting_key='reservation_minutes'"
STOCK_BEFORE="$(sql 'SELECT stock FROM products WHERE id=1')"
request GET admin_settings.php
CSRF_TOKEN="$(csrf_from_body)"
request POST admin_settings.php \
    -F "csrf_token=$CSRF_TOKEN" \
    -F 'settings_action=restore_checkout_display'
assert_status H-CHECKOUT4-RESTORE 302
assert_header_contains H-CHECKOUT4-RESTORE 'Location: admin_settings.php?checkout_restored=1'
assert_sql H-CHECKOUT4-RESTORE 'standard' "SELECT setting_value FROM store_settings WHERE setting_key='cart_layout'"
assert_sql H-CHECKOUT4-RESTORE 'Carrito de Compras' "SELECT setting_value FROM store_settings WHERE setting_key='cart_page_title'"
assert_sql H-CHECKOUT4-RESTORE '#fedcba' "SELECT setting_value FROM store_settings WHERE setting_key='brand_primary_color'"
assert_sql H-CHECKOUT4-RESTORE 'Footer Keep Checkout' "SELECT setting_value FROM store_settings WHERE setting_key='footer_description'"
assert_sql H-CHECKOUT4-RESTORE '4' "SELECT setting_value FROM store_settings WHERE setting_key='featured_columns'"
assert_sql H-CHECKOUT4-RESTORE '5491199988877' "SELECT setting_value FROM store_settings WHERE setting_key='whatsapp_number'"
assert_sql H-CHECKOUT4-RESTORE 'Transferencia, Efectivo' "SELECT setting_value FROM store_settings WHERE setting_key='payment_methods'"
assert_sql H-CHECKOUT4-RESTORE '77' "SELECT setting_value FROM store_settings WHERE setting_key='reservation_minutes'"
assert_sql H-CHECKOUT4-RESTORE "$STOCK_BEFORE" 'SELECT stock FROM products WHERE id=1'
pass H-CHECKOUT4-RESTORE

request GET cart.php
assert_status H-CHECKOUT4-CART 200
assert_body_contains H-CHECKOUT4-CART 'Carrito de Compras'
assert_body_contains H-CHECKOUT4-CART 'cart-layout-standard'
pass H-CHECKOUT4-CART

settings_form 'HTTP WA Template' \
    -F 'whatsapp_number=5491100000000' \
    -F 'store_name=CyberLeo WA' \
    --form-string 'order_whatsapp_template=CONFIRMAR #{order_id}
{items}
TOTAL={total}
SHOP={store_name}'
assert_status H-CHECKOUT4-WHATSAPP-TEMPLATE-SAVE 302
pass H-CHECKOUT4-WHATSAPP-TEMPLATE-SAVE

sql 'UPDATE products SET stock=8, name="Notebook Pro", price=1000.00, price_sale=NULL WHERE id=1'
reset_orders
CHECKOUT_KEY="$(printf 'c%.0s' {1..64})"
request POST create_order.php -H 'Content-Type: application/json' \
    --data "{\"idempotencyKey\":\"$CHECKOUT_KEY\",\"items\":[{\"productId\":1,\"quantity\":2}]}"
assert_status H-CHECKOUT4-WHATSAPP-TEMPLATE 200
WA_URL="$(json_value whatsappUrl)"
WA_ORDER="$(json_value orderId)"
[[ "$WA_URL" == https://wa.me/5491100000000?text=* ]] || fail H-CHECKOUT4-WHATSAPP-TEMPLATE "URL inválida: $WA_URL"
php -r '
$u=$argv[1]; $oid=$argv[2];
$q=parse_url($u, PHP_URL_QUERY); parse_str($q, $p);
$msg=rawurldecode($p["text"] ?? "");
if (!str_contains($msg, "CONFIRMAR #".$oid)) { fwrite(STDERR, "missing order\n"); exit(1); }
if (!str_contains($msg, "Notebook Pro x 2 = \$2.000,00")) { fwrite(STDERR, "bad items: $msg\n"); exit(1); }
if (!str_contains($msg, "TOTAL=\$2.000,00")) { fwrite(STDERR, "bad total\n"); exit(1); }
if (!str_contains($msg, "SHOP=CyberLeo WA")) { fwrite(STDERR, "bad shop\n"); exit(1); }
' "$WA_URL" "$WA_ORDER" || fail H-CHECKOUT4-WHATSAPP-TEMPLATE 'mensaje WhatsApp inválido'
assert_sql H-CHECKOUT4-WHATSAPP-TEMPLATE 6 'SELECT stock FROM products WHERE id=1'
pass H-CHECKOUT4-WHATSAPP-TEMPLATE

# Idempotencia / regresión de pedido con plantilla restaurada
settings_form 'HTTP Test Store' -F 'whatsapp_number=5491100000000' -F 'store_name=HTTP Test Store'
assert_status H-CHECKOUT4-ORDER-RESET 302
sql 'UPDATE products SET stock=8 WHERE id=1'
reset_orders
REG_KEY="$(printf 'd%.0s' {1..64})"
request POST create_order.php -H 'Content-Type: application/json' \
    --data "{\"idempotencyKey\":\"$REG_KEY\",\"items\":[{\"productId\":1,\"quantity\":1}]}"
assert_status H-CHECKOUT4-ORDER-REGRESSION 200
REG_ID="$(json_value orderId)"
REG_URL="$(json_value whatsappUrl)"
request POST create_order.php -H 'Content-Type: application/json' \
    --data "{\"idempotencyKey\":\"$REG_KEY\",\"items\":[{\"productId\":1,\"quantity\":1}]}"
assert_status H-CHECKOUT4-ORDER-REGRESSION-IDEM 200
[[ "$(json_value orderId)" == "$REG_ID" ]] || fail H-CHECKOUT4-ORDER-REGRESSION 'idempotencia rota'
[[ "$REG_URL" == https://wa.me/* ]] || fail H-CHECKOUT4-ORDER-REGRESSION 'wa.me inválida'
php -r '
$u=$argv[1]; $oid=$argv[2];
$q=parse_url($u, PHP_URL_QUERY); parse_str($q, $p);
$msg=rawurldecode($p["text"] ?? "");
if (!str_contains($msg, "pedido #".$oid)) { fwrite(STDERR, "missing default order line\n"); exit(1); }
if (!str_contains($msg, "Total:")) { fwrite(STDERR, "missing total\n"); exit(1); }
' "$REG_URL" "$REG_ID" || fail H-CHECKOUT4-ORDER-REGRESSION 'mensaje default inválido'
assert_sql H-CHECKOUT4-ORDER-REGRESSION 1 "SELECT COUNT(*) FROM orders WHERE idempotency_key='$REG_KEY'"
assert_sql H-CHECKOUT4-ORDER-REGRESSION 7 'SELECT stock FROM products WHERE id=1'
pass H-CHECKOUT4-ORDER-REGRESSION
pass H-CHECKOUT4-ORDER-REGRESSION-IDEM

settings_form 'HTTP Test Store' \
    -F 'cart_page_title=Leak Probe Cart' \
    -F 'cart_layout=invalid-layout-value'
assert_status H-CHECKOUT4-INTERNAL-ERROR 200
assert_body_contains H-CHECKOUT4-INTERNAL-ERROR 'Opción inválida'
assert_body_excludes H-CHECKOUT4-INTERNAL-ERROR 'SQLSTATE'
assert_body_excludes H-CHECKOUT4-INTERNAL-ERROR 'Stack trace'
assert_body_excludes H-CHECKOUT4-INTERNAL-ERROR '/workspace/'
assert_body_excludes H-CHECKOUT4-INTERNAL-ERROR 'PDOException'
pass H-CHECKOUT4-INTERNAL-ERROR

PREV_DELIVERY="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='cart_delivery_methods'")"
PREV_LAYOUT="$(sql "SELECT setting_value FROM store_settings WHERE setting_key='cart_layout'")"
settings_form 'HTTP Test Store' \
    -F 'cart_page_title=No Long Method' \
    -F 'cart_layout=compact' \
    -F 'cart_show_delivery_methods=1' \
    -F "cart_delivery_methods=$(php -r 'echo str_repeat("z",51);')"
assert_status H-CHECKOUT4-BAD-METHOD-LEN 200
assert_body_contains H-CHECKOUT4-BAD-METHOD-LEN 'Formas de entrega'
assert_sql H-CHECKOUT4-BAD-METHOD-LEN "$PREV_DELIVERY" "SELECT setting_value FROM store_settings WHERE setting_key='cart_delivery_methods'"
assert_sql H-CHECKOUT4-BAD-METHOD-LEN "$PREV_LAYOUT" "SELECT setting_value FROM store_settings WHERE setting_key='cart_layout'"
assert_sql H-CHECKOUT4-BAD-METHOD-LEN 'Carrito de Compras' "SELECT setting_value FROM store_settings WHERE setting_key='cart_page_title'"
pass H-CHECKOUT4-BAD-METHOD-LEN

settings_form 'HTTP Test Store' \
    -F 'cart_show_delivery_info=0' \
    -F 'cart_show_delivery_methods=1' \
    -F 'cart_delivery_methods=Retiro solo, Envío solo' \
    -F 'cart_delivery_title=NO DEBE VERSE INFO' \
    -F 'cart_delivery_text=Texto informativo oculto' \
    -F 'cart_show_images=1' \
    -F 'cart_show_payment_methods=1'
assert_status H-CHECKOUT4-METHODS-ONLY-SAVE 302
assert_sql H-CHECKOUT4-METHODS-ONLY-SAVE '0' "SELECT setting_value FROM store_settings WHERE setting_key='cart_show_delivery_info'"
assert_sql H-CHECKOUT4-METHODS-ONLY-SAVE '1' "SELECT setting_value FROM store_settings WHERE setting_key='cart_show_delivery_methods'"
pass H-CHECKOUT4-METHODS-ONLY-SAVE
request GET cart.php
assert_status H-CHECKOUT4-METHODS-ONLY 200
assert_body_contains H-CHECKOUT4-METHODS-ONLY 'cart-delivery-block'
assert_body_contains H-CHECKOUT4-METHODS-ONLY 'cart-delivery-methods-list'
assert_body_contains H-CHECKOUT4-METHODS-ONLY 'cart-delivery-methods-title'
assert_body_contains H-CHECKOUT4-METHODS-ONLY 'Retiro solo'
assert_body_contains H-CHECKOUT4-METHODS-ONLY 'Envío solo'
# Título/texto informativo solo se renderizan con cart_show_delivery_info=1 (no confundir con JSON boot).
assert_body_excludes H-CHECKOUT4-METHODS-ONLY 'cart-delivery-text'
assert_body_excludes H-CHECKOUT4-METHODS-ONLY 'bi-info-circle me-2'
php -r '
$html = file_get_contents($argv[1]);
$html = preg_replace("#<script\\b[^>]*>.*?</script>#is", "", $html) ?? $html;
if (str_contains($html, "NO DEBE VERSE INFO") || str_contains($html, "Texto informativo oculto")) {
    fwrite(STDERR, "info title/text leaked outside boot JSON\n");
    exit(1);
}
' "$HTTP_BODY" || fail H-CHECKOUT4-METHODS-ONLY 'título/texto informativo visibles en HTML'
pass H-CHECKOUT4-METHODS-ONLY

settings_form 'HTTP Test Store' \
    -F 'cart_terms_enabled=1' \
    -F 'cart_terms_text=' \
    -F 'cart_terms_url=terminos.php' \
    -F 'cart_show_delivery_info=1' \
    -F 'cart_show_payment_methods=1'
assert_status H-CHECKOUT4-TERMS-URL-ONLY-SAVE 302
request GET cart.php
assert_status H-CHECKOUT4-TERMS-URL-ONLY 200
assert_body_contains H-CHECKOUT4-TERMS-URL-ONLY 'cart-terms-block'
assert_body_contains H-CHECKOUT4-TERMS-URL-ONLY 'cart-terms-link'
assert_body_contains H-CHECKOUT4-TERMS-URL-ONLY 'href="terminos.php"'
assert_body_contains H-CHECKOUT4-TERMS-URL-ONLY 'Ver más'
assert_body_excludes H-CHECKOUT4-TERMS-URL-ONLY 'cart-terms-text'
pass H-CHECKOUT4-TERMS-URL-ONLY

run_checkout4_chrome() {
    local mode=$1
    local id=$2
    if [[ -z "$CHROME_BIN" ]] || ! command -v node >/dev/null 2>&1; then
        fail "$id" 'google-chrome o node no disponibles'
    fi
    local port
    port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    local profile="$HTTP_TMP/chrome-checkout-$mode-profile"
    mkdir -p "$profile"
    local cmd=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$port" "--remote-allow-origins=*"
        "--user-data-dir=$profile"
        about:blank)
    setsid "${cmd[@]}" >"$HTTP_TMP/chrome-checkout-$mode.out" 2>"$HTTP_TMP/chrome-checkout-$mode.log" & local cpid=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$port/json/list" >/dev/null && break; sleep .05; done
    if timeout 55 env HTTP_TEST_ADMIN_PASSWORD="$WINNING_PASSWORD" node "$ROOT/tests/helpers/chrome_checkout_display.mjs" \
        "$port" "$HTTP_BASE_URL" "$mode" >"$HTTP_TMP/chrome-checkout-$mode-test.out" 2>>"$HTTP_TMP/chrome-checkout-$mode.log"; then
        sed -n '1,40p' "$HTTP_TMP/chrome-checkout-$mode-test.out"
        rg -F --quiet '"browserErrors": 0' "$HTTP_TMP/chrome-checkout-$mode-test.out" ||
            fail "$id" 'browserErrors distinto de 0'
        pass "$id"
    else
        sed -n '1,200p' "$HTTP_TMP/chrome-checkout-$mode-test.out" >&2
        sed -n '1,80p' "$HTTP_TMP/chrome-checkout-$mode.log" >&2
        fail "$id" "Chromium checkout mode=$mode falló"
    fi
    kill -- "-$cpid" 2>/dev/null || kill "$cpid" 2>/dev/null || true
    wait "$cpid" 2>/dev/null || true
}

CHROME_BIN="$(command -v google-chrome-stable || command -v google-chrome || true)"
settings_form 'HTTP Test Store'
sql "UPDATE products SET name='Producto Seguro', stock=8, price=129999.00, price_sale=99999.00, image='$SAFE_IMG' WHERE id=1"
[[ -f "$ROOT/$SAFE_IMG" ]] || base64 --decode "$ROOT/tests/fixtures/tiny.png.b64" >"$ROOT/$SAFE_IMG"
run_checkout4_chrome default B-CHECKOUT4-DEFAULT
run_checkout4_chrome populated B-CHECKOUT4-POPULATED
run_checkout4_chrome empty B-CHECKOUT4-EMPTY

settings_form 'HTTP Checkout Chrome Alt' \
    -F 'cart_page_title=Carrito Alt' \
    -F 'cart_layout=standard' \
    -F 'cart_show_delivery_info=1' \
    -F 'cart_show_delivery_methods=1' \
    -F 'cart_delivery_methods=Retiro, Envío' \
    -F 'cart_show_reservation_note=1' \
    -F 'cart_terms_enabled=1' \
    -F 'cart_terms_text=Términos demo' \
    -F 'cart_terms_url=terminos.php' \
    -F 'cart_show_images=1' \
    -F 'cart_show_payment_methods=1'
run_checkout4_chrome alt B-CHECKOUT4-ALT

settings_form 'HTTP Checkout Chrome Compact' \
    -F 'cart_layout=compact' \
    -F 'cart_image_fit=contain' \
    -F 'cart_image_size=compact' \
    -F 'cart_show_images=1'
run_checkout4_chrome compact B-CHECKOUT4-COMPACT
run_checkout4_chrome mobile-390 B-CHECKOUT4-MOBILE-390
run_checkout4_chrome quantity B-CHECKOUT4-QUANTITY
run_checkout4_chrome remove B-CHECKOUT4-REMOVE
run_checkout4_chrome out-of-stock B-CHECKOUT4-OUT-OF-STOCK
run_checkout4_chrome image-error B-CHECKOUT4-IMAGE-ERROR
run_checkout4_chrome storage-corrupt B-CHECKOUT4-STORAGE-CORRUPT

settings_form 'HTTP XSS Checkout Chrome' \
    --form-string 'cart_page_title=<script>globalThis.checkoutXssExecuted=1</script>XSS Cart' \
    --form-string 'cart_delivery_text=<img src=x onerror=globalThis.checkoutXssExecuted=2>Envío' \
    -F 'cart_show_delivery_info=1'
sql "UPDATE products SET name='<script>globalThis.checkoutXssExecuted=9</script>Prod' WHERE id=1"
run_checkout4_chrome xss B-CHECKOUT4-XSS

# Admin session already authenticated for preview/restore
run_checkout4_chrome preview B-CHECKOUT4-PREVIEW
sleep 2
run_checkout4_chrome restore B-CHECKOUT4-RESTORE
run_checkout4_chrome preview-bad-url B-CHECKOUT4-PREVIEW-BAD-URL

settings_form 'HTTP Test Store' \
    -F 'cart_show_delivery_info=0' \
    -F 'cart_show_delivery_methods=1' \
    -F 'cart_delivery_methods=Retiro solo, Envío solo' \
    -F 'cart_delivery_title=NO DEBE VERSE INFO' \
    -F 'cart_delivery_text=Texto informativo oculto'
run_checkout4_chrome delivery-methods-only B-CHECKOUT4-METHODS-ONLY

settings_form 'HTTP Test Store' \
    -F 'cart_terms_enabled=1' \
    -F 'cart_terms_text=' \
    -F 'cart_terms_url=terminos.php'
run_checkout4_chrome terms-url-only B-CHECKOUT4-TERMS-URL-ONLY

settings_form 'HTTP Test Store'
sql "UPDATE products SET name='Producto Seguro', stock=8 WHERE id=1"
run_checkout4_chrome idempotency-corrupt B-CHECKOUT4-IDEMPOTENCY-CORRUPT
run_checkout4_chrome idempotency-no-uuid B-CHECKOUT4-IDEMPOTENCY-NO-UUID

settings_form 'HTTP Test Store'
sql "UPDATE products SET name='HTTP order product', description='Order fixture', destacados=1, stock=8 WHERE id=1"

# Restaurar payload XSS del fixture para la regresión H-XSS-*
sql "UPDATE products SET name='<script>globalThis.xssExecuted=1;document.title=\"XSS_EXECUTED\"</script>', description='\"><img src=x onerror=globalThis.xssExecuted=2>', stock=2 WHERE id=2"
sql "UPDATE products SET name='HTTP order product', description='Order fixture', destacados=1 WHERE id=1"
sql "UPDATE categories SET name='HTTP fixtures' WHERE id=1"
settings_form 'HTTP Test Store'

printf 'Prueba XSS por HTTP...\n'
sql 'UPDATE products SET stock=2 WHERE id=2'
request GET index.php
assert_status H-XSS-HTTP 200
assert_body_excludes H-XSS-HTTP '<script>globalThis.xssExecuted=1;document.title="XSS_EXECUTED"</script>'
assert_body_excludes H-XSS-HTTP '"><img src=x onerror=globalThis.xssExecuted=2>'
assert_body_contains H-XSS-HTTP '&lt;script&gt;globalThis.xssExecuted=1;document.title=&quot;XSS_EXECUTED&quot;&lt;/script&gt;'
pass H-XSS-HTTP
CHROME_BIN="$(command -v google-chrome-stable || command -v google-chrome || true)"
if [[ -n "$CHROME_BIN" ]] && command -v node >/dev/null 2>&1; then
    CHROME_PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
    CHROME_COMMAND=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
        --disable-background-networking --disable-extensions --disable-component-update
        --disable-dev-shm-usage "--remote-debugging-port=$CHROME_PORT" "--remote-allow-origins=*"
        "--user-data-dir=$HTTP_TMP/chrome-profile"
        about:blank)
    setsid "${CHROME_COMMAND[@]}" >"$HTTP_TMP/chrome.out" 2>"$HTTP_TMP/chrome.log" & CHROME_PID=$!
    for _ in {1..100}; do curl -sf "http://127.0.0.1:$CHROME_PORT/json/list" >/dev/null && break; sleep .05; done
    if HTTP_TEST_ADMIN_PASSWORD="$WINNING_PASSWORD" timeout 45 node "$ROOT/tests/helpers/chrome_xss.mjs" \
        "$CHROME_PORT" "$HTTP_BASE_URL" >"$HTTP_TMP/chrome-test.out" 2>>"$HTTP_TMP/chrome.log"; then
        sed -n '1,80p' "$HTTP_TMP/chrome-test.out"
        pass H-XSS-BROWSER
    else
        cp "$HTTP_TMP/chrome.log" /tmp/cyberleo-chrome.log
        cp "$HTTP_TMP/chrome-test.out" /tmp/cyberleo-chrome-test.out
        printf '  Chromium command:'
        printf ' %q' "${CHROME_COMMAND[@]}"
        printf '\n'
        printf '  Browser test output (/tmp/cyberleo-chrome-test.out):\n' >&2
        sed -n '1,160p' "$HTTP_TMP/chrome-test.out" >&2
        printf '  Chromium log (/tmp/cyberleo-chrome.log):\n' >&2
        sed -n '1,160p' "$HTTP_TMP/chrome.log" >&2
        fail H-XSS-BROWSER 'Chromium headless o la prueba CDP fallaron'
    fi
else
    fail H-XSS-BROWSER 'google-chrome o node no están disponibles'
fi

printf 'Pruebas HTTP Etapa 5 (sistema / endurecimiento)...\n'
HTTP_COOKIE="$HTTP_TMP/system5-anon.cookie"
: >"$HTTP_COOKIE"
request GET admin_system.php
assert_status H-SYSTEM5-AUTH 302
pass H-SYSTEM5-AUTH

HTTP_COOKIE="$ADMIN_COOKIE"
request GET admin_system.php
if [[ "$HTTP_STATUS" == "302" ]]; then
  request GET admin_login.php
  CSRF_TOKEN="$(csrf_from_body)"
  request POST admin_login.php \
    --data-urlencode "csrf_token=$CSRF_TOKEN" \
    --data-urlencode 'username=http-admin' \
    --data-urlencode "password=$WINNING_PASSWORD"
  request GET admin_system.php
fi
assert_status H-SYSTEM5-PANEL 200
assert_body_contains H-SYSTEM5-PANEL 'Estado del sistema'
assert_body_contains H-SYSTEM5-PANEL 'PASS'
assert_body_excludes H-SYSTEM5-PANEL 'APP_SECRET='
assert_body_excludes H-SYSTEM5-PANEL 'DB_PASS='
assert_body_excludes H-SYSTEM5-PANEL 'SQLSTATE'
assert_body_excludes H-SYSTEM5-PANEL 'Stack trace'
assert_body_excludes H-SYSTEM5-PANEL '/workspace/'
assert_body_contains H-SYSTEM5-PANEL 'admin_system.php'
pass H-SYSTEM5-PANEL

request GET admin_settings.php
assert_status H-SYSTEM5-SETTINGS-LINK 200
assert_body_contains H-SYSTEM5-SETTINGS-LINK 'admin_system.php'
assert_body_contains H-SYSTEM5-SETTINGS-LINK 'Ver sistema'
pass H-SYSTEM5-SETTINGS-LINK

for path in \
  'scripts/install_store.php' \
  'migrations/001_add_orders_stock_settings.php' \
  'tests/run.sh' \
  'docs/INSTALL_NEW_STORE.md' \
  'cron/expire_reservations.php' \
  'schema.sql' \
  'README.md' \
  'includes/config.local.php' \
  '.env' \
  'dump.sql' \
  'backups/x.zip' \
  'dist/cyberleo-hostinger.zip' \
  'cyberleo-backup-20260902T120000Z-deadbeef.zip' \
  'backup.zip' \
  'backup.tar.gz'
do
  request GET "$path"
  [[ "$HTTP_STATUS" == 403 || "$HTTP_STATUS" == 404 ]] || fail H-SYSTEM5-PRIVATE "esperado 403/404 para $path, got $HTTP_STATUS"
done
pass H-SYSTEM5-PRIVATE

# Archivos de respaldo presentes en la raíz deben bloquearse (403/404).
printf 'x' >"$ROOT/cyberleo-backup-20260902T120000Z-deadbeef.zip"
printf 'x' >"$ROOT/backup.zip"
printf 'x' >"$ROOT/backup.tar.gz"
for path in \
  'cyberleo-backup-20260902T120000Z-deadbeef.zip' \
  'backup.zip' \
  'backup.tar.gz'
do
  request GET "$path"
  [[ "$HTTP_STATUS" == 403 || "$HTTP_STATUS" == 404 ]] || fail H-SYSTEM5-BACKUP-ARCH "esperado 403/404 para $path, got $HTTP_STATUS"
done
rm -f "$ROOT/cyberleo-backup-20260902T120000Z-deadbeef.zip" "$ROOT/backup.zip" "$ROOT/backup.tar.gz"
pass H-SYSTEM5-BACKUP-ARCH

request GET includes/
[[ "$HTTP_STATUS" == 403 || "$HTTP_STATUS" == 404 ]] || fail H-SYSTEM5-LISTING "directory listing includes/ code=$HTTP_STATUS"
assert_body_excludes H-SYSTEM5-LISTING 'Index of'
pass H-SYSTEM5-LISTING

run_system5_chrome() {
  local mode=$1
  local id=$2
  if [[ -z "$CHROME_BIN" ]] || ! command -v node >/dev/null 2>&1; then
    fail "$id" 'google-chrome o node no disponibles'
  fi
  local port
  port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT); fclose($s);')"
  local profile="$HTTP_TMP/chrome-system5-$mode-profile"
  mkdir -p "$profile"
  local cmd=(env -u DBUS_SESSION_BUS_ADDRESS "$CHROME_BIN" --headless=new --no-sandbox --disable-gpu --no-first-run
    --disable-background-networking --disable-extensions --disable-component-update
    --disable-dev-shm-usage "--remote-debugging-port=$port" "--remote-allow-origins=*"
    "--user-data-dir=$profile" about:blank)
  setsid "${cmd[@]}" >"$HTTP_TMP/chrome-system5-$mode.out" 2>"$HTTP_TMP/chrome-system5-$mode.log" & local cpid=$!
  for _ in {1..100}; do curl -sf "http://127.0.0.1:$port/json/list" >/dev/null && break; sleep .05; done
  if timeout 55 env HTTP_TEST_ADMIN_PASSWORD="$WINNING_PASSWORD" node "$ROOT/tests/helpers/chrome_admin_system.mjs" \
    "$port" "$HTTP_BASE_URL" "$mode" >"$HTTP_TMP/chrome-system5-$mode-test.out" 2>>"$HTTP_TMP/chrome-system5-$mode.log"; then
    sed -n '1,40p' "$HTTP_TMP/chrome-system5-$mode-test.out"
    rg -F --quiet '"browserErrors": 0' "$HTTP_TMP/chrome-system5-$mode-test.out" || fail "$id" 'browserErrors != 0'
    pass "$id"
  else
    sed -n '1,120p' "$HTTP_TMP/chrome-system5-$mode-test.out" >&2
    fail "$id" "Chromium system mode=$mode falló"
  fi
  kill -- "-$cpid" 2>/dev/null || kill "$cpid" 2>/dev/null || true
  wait "$cpid" 2>/dev/null || true
}
CHROME_BIN="$(command -v google-chrome-stable || command -v google-chrome || true)"
run_system5_chrome desktop B-SYSTEM5-DESKTOP
run_system5_chrome mobile B-SYSTEM5-MOBILE-390

if rg --ignore-case --quiet 'PHP (Warning|Fatal error)|Stack trace:|\\[500\\]:' "$SERVER_LOG"; then
    fail SERVER_LOG 'se detectaron warnings, fatals, stack traces o respuestas 500'
fi
printf 'OK: servidor PHP (4 workers), pedidos, auth, CSRF, imágenes, correo mock y XSS HTTP.\n'
