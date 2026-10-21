#!/usr/bin/env bash
# Restores a backup made by backup.sh (backlog P0.4-BE-04). Takes a safety backup first.
#   ./scripts/restore.sh backups/pluto-20261020-020000.sql.gz          into the live database
#   RESTORE_DB=pluto_e2e ./scripts/restore.sh <file>                   into another database (test restore)
#   LIVE=0 ./scripts/restore.sh <file>                                      developer stack
LIVE="${LIVE:-1}"
source "$(dirname "$0")/lib.sh"

file="${1:-}"
[ -f "$file" ] || die "Usage: ./scripts/restore.sh <backup .sql.gz file>"
gzip -t "$file" || die "$file is not a valid gzip file."
app_db="$(env_get DB_DATABASE)"; app_db="${app_db:-pluto}"
target="${RESTORE_DB:-$app_db}"

echo "This REPLACES every table in the '$target' database with the contents of $file."
read -r -p "Type RESTORE to continue: " answer
[ "$answer" = "RESTORE" ] || die "Cancelled."

if [ "$target" = "$app_db" ]; then
  say "Safety backup of the current data"
  "$(dirname "$0")/backup.sh"
fi

say "Recreating database $target"
mysql_root -e "DROP DATABASE IF EXISTS \`$target\`; CREATE DATABASE \`$target\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; GRANT ALL PRIVILEGES ON \`$target\`.* TO '$(env_get DB_USERNAME)'@'%';"
say "Importing $file"
gunzip -c "$file" | dc exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$1"' mysql "$target"

if [ "$target" = "$app_db" ]; then
  artisan migrate --force
  artisan config:cache >/dev/null 2>&1 || true
fi
say "Restore into $target finished."
