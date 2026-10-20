# 11 · Deployment and operations

> Provider-neutral by design (AS-10, DEC-12). ⚑ Confirm the hosting provider, region and any data-residency requirement (e.g. data kept in Bangladesh) before staging is built. Nothing here assumes a specific cloud.

## 1. Environments

| | Development | Staging | Production |
|---|---|---|---|
| Purpose | Local work | Pre-release verification, UAT, restore drills | Live |
| Runtime | docker-compose: `api` (PHP 8.3-FPM + Nginx), `worker`, `scheduler`, `mysql:8.0`, `redis:7`, `mailpit`, `minio`; Vite dev server proxying `/api` | Same images as production, smaller sizes | ≥ 2 API nodes behind a load balancer; ≥ 1 worker; 1 scheduler; managed or replicated MySQL 8.0; Redis; S3-compatible storage; CDN for the SPA |
| Data | Demo seed (Meghna Traders) | Anonymised production copy or demo seed; **never** real customer PII without approval | Real |
| Domains | `localhost:5173` / `:8000` | `app.staging.…`, `api.staging.…` | `app.ledgerflow.example`, `api.ledgerflow.example` |
| Mail | Mailpit | Sandbox SMTP | Production SMTP/API |
| Debug | `APP_DEBUG=true` | false | false |

## 2. Environment configuration
Backend `.env` keys (values come from the secret manager in staging and production; `.env.example` holds placeholders only):

```
APP_NAME=LedgerFlow  APP_ENV=production  APP_DEBUG=false  APP_URL=https://api.ledgerflow.example
APP_KEY=<secret>     APP_PREVIOUS_KEYS=<secret, during rotation>
DB_CONNECTION=mysql  DB_HOST=…  DB_PORT=3306  DB_DATABASE=ledgerflow  DB_USERNAME=lf_app  DB_PASSWORD=<secret>
DB_MIGRATION_USERNAME=lf_migrate  DB_MIGRATION_PASSWORD=<secret>
REDIS_HOST=…  REDIS_PASSWORD=<secret>  CACHE_STORE=redis  QUEUE_CONNECTION=redis
JWT_ALGO=ES256  JWT_PRIVATE_KEY=<path to mounted secret>  JWT_PUBLIC_KEY=<path>  JWT_TTL=15  JWT_REFRESH_TTL_DAYS=14
JWT_ISSUER=https://api.ledgerflow.example  JWT_AUDIENCE=ledgerflow-spa
CORS_ALLOWED_ORIGINS=https://app.ledgerflow.example
SESSION_COOKIE_DOMAIN=api.ledgerflow.example
FILESYSTEM_DISK=s3  AWS_ACCESS_KEY_ID=<secret>  AWS_SECRET_ACCESS_KEY=<secret>  AWS_BUCKET=…  AWS_ENDPOINT=…
MAIL_MAILER=smtp  MAIL_HOST=…  MAIL_PASSWORD=<secret>  MAIL_FROM_ADDRESS=no-reply@ledgerflow.example
SMS_PROVIDER=…  SMS_API_KEY=<secret>
SENTRY_LARAVEL_DSN=<secret>  LOG_CHANNEL=stderr  LOG_LEVEL=info
```
Frontend build variables: [08 §12](08-Frontend-Backend-Integration.md#12-environment-variables) (public only).

## 3. Database migrations in deployment
1. CI builds immutable images tagged with the commit SHA.
2. The deploy job takes a **pre-deploy snapshot** (logical, plus a binlog position mark).
3. It runs `php artisan migrate --force` once, as a one-off task using the **migration DB user**, before new API pods take traffic.
4. Migrations must be backward-compatible with the previous release (expand/contract, [04 §12](04-Database-Design.md#12-backup-and-migration-rollback-strategy)), so old and new pods can run together during a rolling deploy.
5. On failure: stop, keep old pods, restore the snapshot if any partial DDL applied, then fix forward.

## 4. HTTPS and secret management
TLS terminates at the load balancer (TLS 1.2+, modern ciphers), with automatic certificate renewal (ACME/Let's Encrypt or the provider). HSTS is preloaded once stable. Secrets live in a secret manager (cloud KMS/Secrets Manager, Vault, or SOPS-encrypted files with restricted access); they are injected as env or mounted files, never baked into images or committed. Rotation schedule: [07 §12](07-Authentication-and-Authorization.md#12-secret-and-key-management).

## 5. Build and deployment procedure
| Step | Backend | Frontend |
|---|---|---|
| CI on PR | composer install, Pint, PHPStan L8, deptrac, PHPUnit on MySQL 8.0, contract test, `composer audit` | npm ci, ESLint, stylelint, tsc, Vitest, `npm audit`, build |
| Docs gate | `python3 docs/source/build_db.py && python3 docs/source/build_docs.py && python3 docs/source/check_mermaid.py` | — |
| Main branch | Build image `api:<sha>`; push; deploy to staging; migrate; smoke + E2E | Build `dist/` with staging vars; upload to static host; invalidate CDN |
| Release | Tag `vX.Y.Z`; promote the same image to production (no rebuild); migrate; rolling restart; smoke tests (`/api/ready`, login, one GET per module) | Build with production vars; upload with long-cache hashed assets; `index.html` no-cache |
| Rollback | Redeploy the previous image (schema stays compatible thanks to expand/contract) | Re-point to the previous `dist/` |

Zero-downtime is achieved with rolling restarts. Workers finish their current job (`php artisan horizon:terminate`, then restart).

## 6. Queue workers and scheduled tasks
**Queues (Redis + Horizon):** `default` (e-mails, notifications), `exports` (export pack, report exports, statements), `imports` (bank statements, count sheets), `backups` (logical snapshots, restore), `postings-bulk` (bulk post, one job per document). Retries: 3 with backoff; failed jobs alert.

**Scheduler (`php artisan schedule:work` on one node, with `onOneServer()`):**
| Task | Schedule |
|---|---|
| Recurring templates → create drafts (BR-048) | daily 06:00 Asia/Dhaka |
| LC expiry, shipment-date and payment-due alerts; set `expired` | daily 07:00 |
| Overdue invoices and bills due in 3 days notifications | daily 07:30 |
| Daily summary e-mail (K12) | per user time (07:30/08:00/09:00) |
| Period-lock reminder (2 days before the planned lock) | daily |
| Integrity checks (TB balance, stock vs movements, cache vs allocations, orphan polymorphic refs) (NFR-042) | daily 02:00 |
| Per-company logical backups (K15) | daily 00:30 |
| Prune idempotency keys (> 24 h), expired invitations and sessions | hourly |
| Backup retention cleanup | daily |

## 7. Logging, monitoring and error reporting
Logs: JSON to stdout, shipped to central logging (Loki/ELK/cloud logging), kept 30 days hot and 1 year cold. Metrics and alerts: [03 §8](03-System-Architecture.md#8-logging-and-monitoring). Uptime checks every minute on `/api/health` and the SPA. Error reporting: Sentry for PHP and React, with release tracking, PII scrubbing (no amounts, names or e-mails in breadcrumbs) and alert routing to on-call. A status page is optional.

## 8. Backup and restore
| Layer | Method | Frequency | Retention | Purpose |
|---|---|---|---|---|
| Physical MySQL | XtraBackup or managed snapshot | nightly full | 35 days | Disaster recovery |
| Binary logs | Continuous (ROW format, GTID on) | continuous | 7 days | Point-in-time recovery, RPO ≤ 15 min (NFR-030) |
| Logical per company | `company_backups` job (consistent snapshot) | daily + manual + pre_lock + pre_restore | 30/90/365 days per K15 | Company restore, auditor packs |
| Object storage | Versioned bucket + cross-region/off-site replication | continuous | 90 days of versions | Attachments and exports |
| Off-site copy | Encrypted copy of physical backups to a second location | daily | 35 days | Site loss |

**Restore procedures:**
1. *Full point-in-time (operations, RTO ≤ 4 h):* provision a new MySQL instance → restore the latest full backup → replay binlogs up to the target time → run the integrity checks → switch the DB endpoint → verify the TB per company against the last known report.
2. *Single-company restore (K15, Owner):* maintenance flag for that company only (its API calls return 503 `company_maintenance`) → `pre_restore` snapshot → delete and re-insert that company's rows from the chosen snapshot in one transaction (ordered by the 04 §9 migration order) → integrity checks → lift maintenance → audit and notify.
   - **What a company snapshot contains (R-11):** the company's business rows only. It **excludes** user credentials (password hashes, 2FA secrets and recovery codes), `user_sessions`, `password_reset_tokens`, `idempotency_keys` and `audit_logs`. Snapshot files are encrypted (AES-256, per-company key in the secret manager), and downloading one needs `settings.restore` (Owner).
   - **Restore never deletes or replaces `audit_logs`** (NFR-021, append-only). It appends `restore_requested` and `restore_completed` entries instead.
3. *Monthly drill* on staging (TC-NFR-005), with a written result.

## 9. Database connection management
PHP-FPM: `pm.max_children` sized to the DB `max_connections` budget, e.g. 2 nodes × 40 children + workers 10 + headroom ≤ 150 (`max_connections=200`). With Octane, persistent connections per worker and `DB::reconnect` on failures. Timeouts: `wait_timeout=300`, `innodb_lock_wait_timeout=10` (posting fails fast and retries once on deadlock, error 1213). For many tenants or high concurrency, put ProxySQL in front. Slow-query log > 500 ms is reviewed weekly. The read replica (later) serves only `Queries/` reports, with lag monitoring; posting always uses the primary.

## 10. Recovery procedures (runbook summary)
| Incident | First actions |
|---|---|
| API error spike | Check Sentry release diff → roll back the image if it's a new release → check DB and Redis health |
| DB primary down | Fail over (managed) or promote the replica → update the endpoint → verify writes → incident note |
| Data corruption or bad migration | Stop writes (maintenance) → point-in-time restore to before the event (§8.1) → replay safe transactions if needed → integrity checks |
| Integrity check mismatch | Freeze posting for that company if the TB is unbalanced → investigate with audit logs → corrective journal by an accountant (never direct SQL on posted rows) |
| Queue backlog | Scale workers → inspect failed jobs → retry |
| Lost or stolen admin credentials | Revoke the user's sessions (`users:revoke-sessions`), reset the password, rotate any exposed secrets, review audit logs |
| JWT key compromise | Rotate keys immediately (new `kid`), invalidate all sessions (`sessions:revoke-all`), force re-login |
