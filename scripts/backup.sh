#!/usr/bin/env bash
# MySQL backup of the live database (backlog P0.4-BE-04): backups/pluto-YYYYmmdd-HHMM.sql.gz, kept 14 days.
#   ./scripts/backup.sh            live stack (default; the nightly 02:00 run is the `backup` service)
#   LIVE=0 ./scripts/backup.sh     developer stack
LIVE="${LIVE:-1}"
source "$(dirname "$0")/lib.sh"

db="$(env_get DB_DATABASE)"; db="${db:-pluto}"
mkdir -p backups
file="backups/${db}-$(date +%Y%m%d-%H%M%S).sql.gz"
dc exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --routines --triggers --set-gtid-purged=OFF --no-tablespaces "$1"' mysqldump "$db" \
  | gzip > "$file.part"
[ -s "$file.part" ] && gzip -t "$file.part" || { rm -f "$file.part"; die "Backup failed (empty or broken file)."; }
mv "$file.part" "$file"
find backups -name "${db}-*.sql.gz" -mtime +14 -delete
say "Backup written: $file ($(du -h "$file" | cut -f1))"
