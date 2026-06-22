set -e

DIR="$(cd "$(dirname "$0")" && pwd)/backups"
KEEP="${KEEP:-14}"
DB="${DB_NAME:-library_sys}"
ROOT_PW="${MYSQL_ROOT_PASSWORD:-root}"
CONTAINER="${DB_CONTAINER:-lms_db}"

mkdir -p "$DIR"
TS=$(date +%Y%m%d_%H%M%S)
OUT="$DIR/library_sys_$TS.sql.gz"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backing up $DB -> $OUT"
docker exec "$CONTAINER" sh -c "exec mysqldump -uroot -p\"$ROOT_PW\" --single-transaction --routines --triggers --databases $DB" 2>/dev/null | gzip > "$OUT"

# Refuse to keep an empty/failed dump
if [ ! -s "$OUT" ]; then
  echo "ERROR: backup is empty — dump failed. Removing." >&2
  rm -f "$OUT"
  exit 1
fi
echo "OK: $(du -h "$OUT" | cut -f1) written."

# Rotate: delete everything older than the newest $KEEP
ls -1t "$DIR"/library_sys_*.sql.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | while IFS= read -r old; do
  rm -f "$old"
  echo "Rotated out: $(basename "$old")"
done
echo "Done. Keeping newest $KEEP backup(s)."
