#!/usr/bin/env bash
# RNF-2 / ADR-002 · Two librarians lend the LAST copy at the same time: exactly one gets 201 and the other 409.
# Requires the API running with several workers (e.g. `PHP_CLI_SERVER_WORKERS=4 php artisan serve` or the container).
# Usage: API=http://localhost:8000/api/v1 ADMIN_USERNAME=admin ADMIN_PASSWORD=... tests/concurrency/last-copy.sh
set -euo pipefail
API="${API:-http://localhost:8000/api/v1}"
J='Content-Type: application/json'
json() { python3 -c "import sys,json;print(json.load(sys.stdin)$1)"; }

TOKEN=$(curl -sf -X POST "$API/auth/login" -H "$J" -d "{\"username\":\"$ADMIN_USERNAME\",\"password\":\"$ADMIN_PASSWORD\"}" | json "['access_token']")
A="Authorization: Bearer $TOKEN"
ISBN=$(printf '979%010d' $((RANDOM * RANDOM)))
BOOK=$(curl -sf -X POST "$API/books" -H "$A" -H "$J" -d "{\"title\":\"Último ejemplar $ISBN\",\"author\":\"Prueba\",\"isbn\":\"$ISBN\",\"category_id\":\"33333333-3333-4333-8333-333333333333\",\"total_copies\":1}" | json "['id']")
M1=$(curl -sf -X POST "$API/members" -H "$A" -H "$J" -d "{\"full_name\":\"Concurrente A\",\"document_number\":\"CA$ISBN\"}" | json "['id']")
M2=$(curl -sf -X POST "$API/members" -H "$A" -H "$J" -d "{\"full_name\":\"Concurrente B\",\"document_number\":\"CB$ISBN\"}" | json "['id']")

OUT=$(mktemp)
for M in "$M1" "$M2"; do
  curl -s -o /dev/null -w '%{http_code}\n' -X POST "$API/loans" -H "$A" -H "$J" -d "{\"member_id\":\"$M\",\"items\":[{\"book_id\":\"$BOOK\"}]}" >> "$OUT" &
done
wait
CODES=$(sort "$OUT" | tr '\n' ' ')
AVAILABLE=$(curl -sf "$API/books/$BOOK" -H "$A" | json "['available_copies']")
echo "codes: $CODES · available: $AVAILABLE"
[ "$CODES" = "201 409 " ] && [ "$AVAILABLE" = "0" ] && echo "OK: one wins, the other gets 409" || { echo "FAIL"; exit 1; }
