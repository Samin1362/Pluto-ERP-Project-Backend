# Runbook: monitoring, alerts and the restore drill

Backlog P17.1-BE-06 (11 §7–§10). Everything here works on the current host and on a VPS; nothing names a domain yet
(owner decision 1 Nov 2026: hosting decided later).

## 1. What watches the system
| Check | How | Who hears about it |
|---|---|---|
| API up and its database/Redis reachable | external uptime monitor on `https://<public host>/api/ready` every 5 min (UptimeRobot or Better Stack, free tier); 200 = ready, 503 names the failing part | the owner's e-mail / phone app |
| Unexpected errors (500s, failed jobs, critical logs) | Sentry, backend (`SENTRY_LARAVEL_DSN` in `.env.live`) and frontend (`VITE_SENTRY_DSN` on Vercel) | Sentry e-mail alerts |
| Nightly backup ran, is fresh and not empty; no failed queued jobs | `pluto:ops:check`, 08:00 Dhaka in the live scheduler (reads `./backups` read-only) | `PLUTO_ALERT_EMAIL` (and Sentry as a critical log) |
| Books consistent | `pluto:integrity`, 02:00 Dhaka | log; a failure is a critical log line → Sentry |

## 2. Turn them on (once)
1. **Sentry:** create a free organisation at sentry.io, two projects: `pluto-api` (Laravel) and `pluto-web` (React).
   Put the API project's DSN in `.env.live` as `SENTRY_LARAVEL_DSN=…`, then `./scripts/deploy.sh`. Put the web
   project's DSN in Vercel as `VITE_SENTRY_DSN` (Production) and redeploy. No personal data, request bodies or SQL
   values are sent (config/sentry.php; the frontend scrubs the same way).
2. **E-mail:** Gmail / Google Workspace app password in `.env.live` (`MAIL_MAILER=smtp`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
   `MAIL_FROM_ADDRESS`), and `PLUTO_ALERT_EMAIL=<owner's address>`. Test: `./scripts/live.sh artisan pluto:ops:check`.
3. **Uptime monitor:** add an HTTP(s) monitor for `https://<public host>/api/ready`, keyword `"ready"`, every 5 minutes,
   alert after 2 failures. With the quick tunnel the address changes on restart — update the monitor with the
   Vercel repoint (tunnel-url-changed.md); with the named tunnel (named-tunnel.md) it never changes.

## 3. When an alert arrives
- **API down / 503:** `./scripts/live.sh ps` → restart what is unhealthy with `./scripts/live.sh up`; `./scripts/live.sh logs app`.
  503 `{"database":"fail"}` → `./scripts/live.sh logs mysql`; disk full is the usual cause (`docker system df`).
- **Backup missing or old:** `./scripts/live.sh logs backup`; run one now with `./scripts/backup.sh`; check free disk.
- **Failed jobs:** `./scripts/live.sh artisan queue:failed`, read the error, fix, `queue:retry all`.
- **Integrity check failed:** stop posting (tell the team), run `./scripts/live.sh artisan pluto:integrity` for the detail
  and escalate to the developer — never edit journal rows by hand.

## 4. Restore drill (TC-NFR-005) — every quarter and before go-live
```bash
./scripts/backup.sh                                            # or take last night's file from ./backups
RESTORE_DB=pluto_drill ./scripts/restore.sh backups/pluto-<date>.sql.gz   # type RESTORE
```
Then compare the books (must be identical) and drop the copy:
```bash
q='SELECT company_id, COUNT(*), SUM(debit), SUM(credit) FROM journal_lines WHERE is_posted=1 GROUP BY company_id'
./scripts/live.sh exec -T mysql sh -c "MYSQL_PWD=\$MYSQL_ROOT_PASSWORD mysql -uroot -N -e \"$q\" pluto"
./scripts/live.sh exec -T mysql sh -c "MYSQL_PWD=\$MYSQL_ROOT_PASSWORD mysql -uroot -N -e \"$q\" pluto_drill"
./scripts/live.sh exec -T mysql sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot -e "DROP DATABASE pluto_drill"'
```
Record the date, file, duration and result in the go-live checklist. Target: under 4 hours, trial balance equal.

**Drill log**
| Date | Stack | Backup | Duration | Result |
|---|---|---|---|---|
| 1 Nov 2026 | developer (dev data) | pluto-20261101-201114.sql.gz, 116 KB + files 56 KB | 85 s backup + restore | 102 posted lines, Dr = Cr = ৳ 34,26,745.00 and per-line checksum identical; 4 immutability triggers restored |
