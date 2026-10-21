#!/bin/sh
# Nightly MySQL backup inside the live stack (backlog P0.4-BE-04). Runs in the `backup` service, so the host
# needs no cron (macOS blocks cron from ~/Desktop; Windows has none). Same file names and 14-day retention as
# scripts/backup.sh. BACKUP_AT=HHMM in TZ (default 0200 Asia/Dhaka); BACKUP_NOW=1 runs once at start.
set -u
DB="${DB_DATABASE:-pluto}"
AT="${BACKUP_AT:-0200}"
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"

backup() {
  file="/backups/${DB}-$(date +%Y%m%d-%H%M%S).sql.gz"
  if mysqldump -h mysql -uroot --single-transaction --routines --triggers --set-gtid-purged=OFF --no-tablespaces "$DB" \
       | gzip > "$file.part" && gzip -t "$file.part" && [ -s "$file.part" ]; then
    mv "$file.part" "$file"
    find /backups -name "${DB}-*.sql.gz" -mtime +14 -delete
    echo "$(date '+%F %T %Z') backup written: $file ($(du -h "$file" | cut -f1))"
  else
    rm -f "$file.part"
    echo "$(date '+%F %T %Z') BACKUP FAILED" >&2
  fi
}

echo "$(date '+%F %T %Z') nightly backup of '$DB' at $AT"
[ "${BACKUP_NOW:-0}" = "1" ] && backup
last=""
while true; do
  now="$(date +%H%M)"; day="$(date +%F)"
  if [ "$now" = "$AT" ] && [ "$last" != "$day" ]; then
    backup; last="$day"
  fi
  sleep 30
done
