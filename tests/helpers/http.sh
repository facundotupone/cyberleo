#!/usr/bin/env bash

pass() {
    printf '  PASS %s\n' "$1"
}

fail() {
    printf '  FAIL %s: %s\n' "$1" "$2" >&2
    if [[ -s "${HTTP_BODY:-}" ]]; then
        sed -n '1,20p' "$HTTP_BODY" >&2
    fi
    exit 1
}

request() {
    local method=$1
    local path=$2
    shift 2
    HTTP_BODY="$HTTP_TMP/body"
    HTTP_HEADERS="$HTTP_TMP/headers"
    HTTP_STATUS="$(
        curl --silent --show-error --max-time 15 \
            --cookie "$HTTP_COOKIE" --cookie-jar "$HTTP_COOKIE" \
            --request "$method" --dump-header "$HTTP_HEADERS" \
            --output "$HTTP_BODY" --write-out '%{http_code}' \
            "$@" "$HTTP_BASE_URL/$path"
    )"
}

assert_status() {
    local id=$1
    local expected=$2
    [[ "$HTTP_STATUS" == "$expected" ]] || fail "$id" "HTTP $HTTP_STATUS (esperado $expected)"
}

assert_body_contains() {
    local id=$1
    local expected=$2
    if ! LC_ALL=C rg --fixed-strings --quiet -e "$expected" -- "$HTTP_BODY"; then
        fail "$id" "la respuesta no contiene <$expected>"
    fi
}

assert_body_excludes() {
    local id=$1
    local unexpected=$2
    if LC_ALL=C rg --fixed-strings --quiet -e "$unexpected" -- "$HTTP_BODY"; then
        fail "$id" "la respuesta contiene contenido inseguro <$unexpected>"
    fi
}

assert_header_contains() {
    local id=$1
    local expected=$2
    if ! LC_ALL=C rg --ignore-case --fixed-strings --quiet "$expected" "$HTTP_HEADERS"; then
        fail "$id" "los headers no contienen <$expected>"
    fi
}

json_value() {
    local key=$1
    php -r '
        $data = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($data) || !array_key_exists($argv[2], $data)) exit(2);
        echo is_bool($data[$argv[2]]) ? ($data[$argv[2]] ? "true" : "false") : $data[$argv[2]];
    ' "$HTTP_BODY" "$key"
}

csrf_from_body() {
    php -r '
        $html = file_get_contents($argv[1]);
        if (!preg_match("/name=\"csrf_token\" value=\"([a-f0-9]{64})\"/", $html, $match)) exit(2);
        echo $match[1];
    ' "$HTTP_BODY"
}
