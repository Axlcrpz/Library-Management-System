#!/usr/bin/env bash
# Quick per-endpoint throughput probe with ApacheBench. Logs in once with curl,
# reuses the PHP session cookie, then hammers each read endpoint at concurrency 50.
#
#   ./tests/load/ab.sh http://127.0.0.1:8080 admin@e2e.test 'adminadmin!'
#
# Use this to isolate a single endpoint's RPS/latency; use k6-mixed.js for a
# realistic blended workload.
set -euo pipefail

BASE="${1:-http://127.0.0.1:8080}"
USER="${2:?usage: ab.sh <base-url> <email> <password>}"
PASS="${3:?usage: ab.sh <base-url> <email> <password>}"
N="${N:-500}"      # requests per endpoint
C="${C:-50}"       # concurrency

command -v ab >/dev/null || { echo "ApacheBench (ab) not installed: apt-get install apache2-utils"; exit 1; }

JAR="$(mktemp)"
curl -s -c "$JAR" "$BASE/login.php" >/dev/null
curl -s -c "$JAR" -b "$JAR" --data-urlencode "mode=login" --data-urlencode "email=$USER" --data-urlencode "password=$PASS" "$BASE/login.php" >/dev/null
SID="$(awk '/PHPSESSID/ {print $7}' "$JAR" | tail -1)"
[ -n "$SID" ] || { echo "login failed — no session cookie"; rm -f "$JAR"; exit 1; }
COOKIE="PHPSESSID=$SID"
echo "Authenticated. Probing $BASE at -n $N -c $C"

probe() {
  echo; echo "=== $1 ==="
  ab -n "$N" -c "$C" -C "$COOKIE" "$BASE/$1" 2>/dev/null \
    | grep -E 'Requests per second|Time per request:|Failed requests|Non-2xx|  (50|95|99)%'
}

probe "health.php"
probe "api/library_handler.php?action=book_stats"
probe "api/library_handler.php?action=books_get&per_page=20"
probe "api/library_handler.php?action=inventory_stats"
probe "api/library_handler.php?action=books_get&per_page=20&q=the"
probe "api/library_handler.php?action=book_borrow_requests_get&scope=mine"

rm -f "$JAR"
