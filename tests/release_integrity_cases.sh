#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/release-integrity.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

check() {
    local id=$1 expected=$2 release=$3
    set +e
    php "$ROOT/tests/release_integrity_test.php" "$release" >"$WORK/$id.out" 2>"$WORK/$id.err"
    local actual=$?
    set -e
    [[ "$actual" -eq "$expected" ]] || { printf '%s: esperado %s, recibido %s\n' "$id" "$expected" "$actual" >&2; exit 1; }
    printf '%s PASS\n' "$id"
}

mkdir -p "$WORK/01/release/assets"
printf image >"$WORK/01/release/assets/image.webp"
printf 'body{background:url("assets/image.webp")}' >"$WORK/01/release/index.css"
check RLS-01 0 "$WORK/01/release"

mkdir -p "$WORK/02/release"
printf 'body{background:url("missing.webp")}' >"$WORK/02/release/index.css"
check RLS-02 1 "$WORK/02/release"

mkdir -p "$WORK/03/release/css"
printf outside >"$WORK/03/outside.webp"
printf 'body{background:url("../../outside.webp")}' >"$WORK/03/release/css/style.css"
check RLS-03 1 "$WORK/03/release"

mkdir -p "$WORK/04/release/assets"
printf outside >"$WORK/04/outside.webp"
ln -s "$WORK/04/outside.webp" "$WORK/04/release/assets/image.webp"
printf 'body{background:url("assets/image.webp")}' >"$WORK/04/release/index.css"
check RLS-04 1 "$WORK/04/release"

mkdir -p "$WORK/05/release/css" "$WORK/05/release/assets"
printf image >"$WORK/05/release/assets/image.webp"
printf 'body{background:url("../assets/image.webp")}' >"$WORK/05/release/css/style.css"
check RLS-05 0 "$WORK/05/release"

mkdir -p "$WORK/06/release"
printf 'a{background:url("https://example.test/a.png")}b{background:url("data:image/png;base64,AA==")}c{background:url("#gradient")}' >"$WORK/06/release/style.css"
check RLS-06 0 "$WORK/06/release"
