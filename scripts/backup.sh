#!/usr/bin/env bash
# MySQL backup of the live database (backlog P0.4-BE-04): backups/pluto-YYYYmmdd-HHMM.sql.gz, kept 14 days, and the
# attachments on the private disk (storage/app/private; P13.1-BE-01) as backups/pluto-files-YYYYmmdd-HHMM.tar.gz.
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

files="backups/${db}-files-$(date +%Y%m%d-%H%M%S).tar.gz"
dc exec -T app sh -c 'mkdir -p storage/app/private && tar -czf - -C storage/app private' > "$files.part"   && gzip -t "$files.part" && mv "$files.part" "$files" || { rm -f "$files.part"; die "Attachment backup failed."; }
find backups -name "${db}-files-*.tar.gz" -mtime +14 -delete
say "Attachments written: $files ($(du -h "$files" | cut -f1))"
