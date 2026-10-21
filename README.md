# Pluto ERP

A multi-company ERP for Bangladeshi businesses that buy and sell physical goods. People record business events on simple forms (invoices, bills, receipts, stock counts, LC charges), and validated posting rules generate the double-entry accounting automatically.

> **Status: implementation slice 1 (19 Oct 2026):** project setup, sign-in with 2-step verification, role-based access and the Customers workflow (list, add, edit, details) run end to end on Laravel + MySQL with the React UI (frontend: `Pluto-ERP-Project-Frontend` repo). Everything else is documentation only. Details, deviations and test results: [docs/15](docs/15-Implementation-Slice-1.md). ⚑ Open decisions: [docs/00 §7](docs/00-Discovery-and-Decisions.md#7-other-decisions-requiring-approval).

> **Team docs:** [CONTRIBUTING.md](CONTRIBUTING.md) (Jira, branches, PRs, Definition of Done) · [CLAUDE.md](CLAUDE.md) (rules and recipe for AI sessions) · [runbook: tunnel URL changed](docs/runbooks/tunnel-url-changed.md)

## Business objectives
1. Correct books without accounting expertise: every posting balances; posted records are immutable and corrected by reversal.
2. A daily view of cash, receivables, payables and stock.
3. Faster month-end: reconciliation, statements and period locking.
4. Control: roles, approval limits, audit trail, company isolation.
5. Import management: letters of credit, charges, payments at bank rates and landed cost.

## Technology stack (Option A, approved)
| Layer | Choice |
|---|---|
| Frontend | React 19 + TypeScript (strict), esbuild (Vite planned, 15 D2), React Router, TanStack Query, React Hook Form + zod, the Pluto design system |
| Backend | Laravel 12 on PHP 8.3, layered (controllers → form requests → policies → domain services → Eloquent) |
| ORM | **Eloquent**, approved 18 Oct 2026 (Prisma was evaluated and rejected; see docs/00 §6) |
| Database | MySQL 8.0 (InnoDB, utf8mb4, strict, CHECK constraints) |
| Auth | JWT access token (15 min, in memory) + rotating HttpOnly refresh cookie; ES256 via `lcobucci/jwt` (15 D1); TOTP 2-step verification with recovery codes |
| API | REST/JSON `/api/v1`, documented with OpenAPI 3.1 ([docs/openapi.yaml](docs/openapi.yaml)) |
| Infrastructure | Redis (cache, queues, rate limits), S3-compatible object storage, Horizon workers, scheduler |
| Version control | Git; trunk-based with protected `main` |

## Major modules
Dashboard · Customers · Suppliers · Products and inventory (multi-warehouse, weighted average) · Sales · Purchases · LC management (with shipment lots inside LC Details) · Cash and bank (reconciliation) · Income and expenses (budgets, recurring drafts) · Accounting (COA, vouchers, approvals, periods) · Reports (TB, P&L, BS, cash flow, GL, aging) · Settings (company, users, roles, tax rules, invoice design, security, backup) · Search, notifications and shared states.

## Prerequisites
Only **Docker** (Docker Desktop on macOS/Windows, Docker Engine + Compose v2 on Linux) and **git**. PHP, Composer, MySQL and Redis run in containers. Python 3.12 with PyYAML is needed only to rebuild the docs (`docs/source`).

Windows: work inside **WSL2 Ubuntu** with Docker Desktop's WSL integration turned on, and clone the repo inside the WSL filesystem (`~/…`, not `/mnt/c/…`), otherwise file access is very slow.
Without Ubuntu (Docker Desktop only, repo on a Windows drive, scripts run from **Git Bash**) everything works but the shared drive is slow (artisan ~13 s, one API request ~5 s, first setup ~25 min). Turn on the local speed-ups in `docker-compose.fast-local.yml` (vendor/ in a Docker volume, MySQL without disk syncs; local only, never the live stack): add `COMPOSE_PATH_SEPARATOR=:` and `COMPOSE_FILE=docker-compose.yml:docker-compose.fast-local.yml` to `.env`, then `./scripts/setup.sh`. Measured on a Windows 11 laptop: artisan 13 s → 1 s, API request 5 s → 0.3 s, migrations 11 min → 2.5 min. If it is still slow, exclude Docker's disk from Windows Defender real-time scanning (admin PowerShell: `Add-MpPreference -ExclusionPath "$env:LOCALAPPDATA\Docker\wsl"`).

## Repository layout
```
Pluto-ERP-Project-Backend/
  app/ bootstrap/ config/ database/ routes/ tests/   Laravel 12 API
  database/migrations/        83 generated migrations (dependency order) + schema-contract.json
  contract/contract.gen.ts    generated TypeScript types, copied into the frontend repo
  docker/                     php (Dockerfile, ini), nginx, mysql init
  docker-compose.yml          local stack (app, nginx, mysql, redis, mailpit, worker, scheduler)
  scripts/                    setup.sh, seed-test-users.sh, reset-db.sh, e2e-prepare.sh, live.sh, deploy.sh, backup.sh …
  Makefile                    daily commands (make help)
  docs/                       specifications 00–15, openapi.yaml, schema/, source/ (generators)
  .github/                    CI, PR template, CODEOWNERS
```

## Run locally
```bash
git clone https://github.com/Samin1362/Pluto-ERP-Project-Backend.git
cd Pluto-ERP-Project-Backend
./scripts/setup.sh          # first time: .env, images, composer, migrations, keys, demo company (asks for a password)
./scripts/seed-test-users.sh   # optional: viewer@, store@, accountant@, sales@, finance@ pluto.local
```
`setup.sh` is safe to run again. When it finishes:

| What | Where |
|---|---|
| API | `http://localhost:8080/api/v1` (health: `/api/health`, readiness: `/api/ready`) |
| Demo sign-in | `owner@pluto.local` with the password you typed (set `PLUTO_OWNER_PASSWORD` to skip the prompt) |
| Mailpit (all outgoing e-mail) | `http://localhost:8025` |
| MySQL from a GUI client (TablePlus, DBeaver…) | `localhost:33060`, user `pluto`, password = `DB_PASSWORD` in `.env` |

Daily commands (`make help` lists them):

| Command | Does |
|---|---|
| `make up` / `make down` | start (and wait until healthy) / stop; data is kept in Docker volumes |
| `make logs` | follow all logs |
| `make sh` | shell in the app container (run `php artisan …` there) |
| `make migrate` | run new migrations |
| `make test` | PHPUnit against the separate `pluto_test` database |
| `make fresh` | wipe the local database and recreate demo company + test users (asks first) |
| `make tinker` | Laravel REPL |
| `make docs` | regenerate docs 01/02/04/05/09, `openapi.yaml`, migrations, generated PHP and `contract/` from `docs/source`, then run the documentation gates; commit the result (a clean `git status` afterwards means everything was in sync) |

Troubleshooting:
- **Port already in use** (another project on 8080, 8025 or 33060): set `HTTP_PORT`, `MAILPIT_PORT` or `DB_FORWARD_PORT` in `.env`, then `make up`.
- **Apple silicon** is fine: every image is multi-arch.
- **Slow migrations** on first run: Docker Desktop needs memory; give it at least 4 GB and stop other projects' containers if it is crowded.
- **Start from zero**: `docker compose down -v` deletes the database volume, then `./scripts/setup.sh`.

### Upgrading after the rename to Pluto (P1.7)
The databases are now `pluto`, `pluto_test` and `pluto_e2e` with the user `pluto`, the env variables start with `PLUTO_`, the artisan commands with `pluto:` and the demo sign-in is `owner@pluto.local` (the former names are in the history note of docs 00). A checkout from before the rename still has the old names in `.env` and in its MySQL volume:

- **Local data is disposable (normal case):** in `.env` set `DB_DATABASE=pluto` and `DB_USERNAME=pluto` and rename the remaining old-prefix variables to `PLUTO_…` (compare with `.env.example`), then `docker compose down -v && ./scripts/setup.sh`.
- **Keep your local data:** before editing `.env`, note the old database name (`OLD_DB=$(grep ^DB_DATABASE= .env | cut -d= -f2)`) and dump it:
  `docker compose exec -T mysql sh -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysqldump -uroot --single-transaction $OLD_DB" > old-local.sql`,
  then do the step above, then load it into `pluto`: `docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot pluto' < old-local.sql` (the dump drops and recreates each table, replacing what setup created).

The live host is switched separately (P1.7-BE-04, host owner only).

## Environment configuration
`.env` (copied from `.env.example` by `setup.sh`, never committed). Live values are set in `.env.live` on the host (P0.4). Secrets are shared through a password manager, never in git or chat.

| Variable | Purpose | Local default | Live |
|---|---|---|---|
| `APP_KEY` | encryption key (2-step secrets, cookies) | generated by `setup.sh` | own random key |
| `APP_ENV`, `APP_DEBUG` | environment, debug pages | `local`, `true` | `production`, `false` |
| `APP_URL` | public URL of the API | `http://localhost:8080` | tunnel URL |
| `HTTP_PORT`, `DB_FORWARD_PORT`, `MAILPIT_PORT` | ports on your machine | `8080`, `33060`, `8025` | — |
| `LOCAL_UID`, `LOCAL_GID` | container user = you, so created files are yours | `id -u` / `id -g` (setup.sh) | host user |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` | MySQL connection | `mysql`, `3306`, `pluto`, `pluto` | same |
| `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD` | MySQL passwords (also create the MySQL user on first start) | random (setup.sh) | strong, unique |
| `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Redis (cache, queues) | `phpredis`, `redis`, `6379`, none | same |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `SESSION_DRIVER` | drivers | `redis`, `redis`, `array` | same |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_*` | outgoing mail | Mailpit (`smtp`, `mailpit`, `1025`) | `log` until SMTP is chosen (C4) |
| `HASH_DRIVER`, `ARGON_*` | password hashing (07 §2) | `argon2id`, 64 MiB, 4 passes | same |
| `JWT_PRIVATE_KEY_PATH`, `JWT_PUBLIC_KEY_PATH` | ES256 access-token keys (relative to the project or absolute) | `storage/keys/…` (`pluto:jwt-keys`) | same, own keys |
| `JWT_KEY_ID`, `JWT_TTL_SECONDS` | token key id, lifetime | `k1`, `900` | same |
| `REFRESH_COOKIE_SECURE`, `REFRESH_TTL_DAYS` | refresh cookie `pl_rt` | `true`, `14` | same |
| `PLUTO_ALLOWED_ORIGINS` | origins allowed to refresh/log out (CSRF defence) | `http://localhost:5173,http://localhost:8080` | Vercel URL(s) |
| `PLUTO_ALLOWED_ORIGIN_PATTERNS` | extra allowed origins by pattern (`*` = part of one DNS label, https only) | empty | `https://pluto-erp-frontend-*-<team>.vercel.app` (previews) |
| `PROXY_SHARED_SECRET` | secret header `X-Pluto-Proxy-Secret` from the Vercel proxy; only then are `X-Forwarded-For/-Host/-Proto` trusted | empty (trust no proxy) | long random value, same as on Vercel |
| `APP_FRONTEND_URL` | frontend address for links in e-mails and invitations | `http://localhost:5173` | `https://pluto-erp-frontend.vercel.app` |
| `TWO_FACTOR_ISSUER` | name in authenticator apps | `Pluto` | same |
| `AUTH_RATE_LIMIT_PER_IP`, `API_RATE_LIMIT_PER_USER` | requests per minute | `20`, `120` | same (C12) |
| `PLUTO_OWNER_PASSWORD`, `PLUTO_USER_PASSWORD` | shell variables for `setup.sh` / `seed-test-users.sh` (not in `.env`) | prompted | — |

## Testing commands
```bash
make test                                    # PHPUnit in Docker (pluto_test; never touches your dev data)
make docs                                    # regenerate docs, migrations, generated PHP/TS and contract/, then run the gates (Docker only)
# CI runs the docs gates and PHPUnit on every push and pull request (.github/workflows/ci.yml)
```
Frontend checks and end-to-end tests live in the frontend repo (`Pluto-ERP-Project-Frontend`). Its `scripts/e2e.sh` calls this repo's `scripts/e2e-prepare.sh`, which starts a separate API on **http://localhost:8081** (`--profile e2e`: `app-e2e` + `nginx-e2e`) on the `pluto_e2e` database with its own Redis databases, wipes it and creates `owner@`, `viewer@` and `store@e2e.pluto.test` with the password in `E2E_PASSWORD`. Your dev data is never touched. Stop it with `docker compose --profile e2e stop app-e2e nginx-e2e`.

## Live backend (host machine)
The live API runs on one always-on machine (decision C1: Samin's Mac) with Docker, and the internet reaches it through a **Cloudflare quick tunnel** (`https://<random>.trycloudflare.com`, no account or domain needed). The Vercel frontend calls it through its `/api` proxy. The live stack is a separate Compose project (`pluto-erp-live`): its own containers, database volume and port **8090**, so it never touches the developer stack on the same machine.

```bash
cp .env.live.example .env.live     # or take the real .env.live from the password manager
./scripts/live.sh init             # first time: random secrets, prod image, migrate, keys, tunnel
./scripts/live.sh artisan pluto:company:create "Company Ltd." --owner-email=owner@example.com --owner-name="Owner"
./scripts/tunnel-url.sh            # prints the public URL
curl "$(./scripts/tunnel-url.sh)/api/ready"
```

| Command | Does |
|---|---|
| `./scripts/live.sh up` / `down` / `ps` / `logs [service]` | start (waits until healthy, prints the URL) / stop / status / logs |
| `./scripts/live.sh artisan …` | artisan in the live app |
| `./scripts/deploy.sh` | release the latest `main`: clean-checkout check, pull, backup, build, replace app/worker/scheduler/nginx, migrate, cache, smoke test. **cloudflared is not restarted, so the URL stays the same** |
| `./scripts/backup.sh` | `backups/pluto-YYYYmmdd-HHMMSS.sql.gz` (kept 14 days) |
| `./scripts/restore.sh <file>` | restore after typing `RESTORE` (safety backup first); `RESTORE_DB=pluto_e2e` for a test restore |

**The tunnel URL changes whenever the `cloudflared` container restarts** (reboot, Docker restart, `live.sh down`). Then follow [the runbook](docs/runbooks/tunnel-url-changed.md): it takes about two minutes.

### Nightly backup
The live stack includes a `backup` service (`docker/backup/nightly.sh`) that dumps the database to `backups/pluto-YYYYmmdd-HHMMSS.sql.gz` every night at **02:00 Dhaka time** and deletes files older than 14 days. No host cron is needed, so it works the same on macOS (where cron cannot read `~/Desktop`), Windows and Linux. Change the time with `BACKUP_AT=HHMM` / `BACKUP_TZ` in `.env.live`; check it with `./scripts/live.sh logs backup`. `./scripts/backup.sh` makes an extra backup at any time (deploys do this automatically).
**Weekly:** copy the newest file in `backups/` off the machine (Google Drive or an external disk). A backup that only lives on the host does not survive a dead disk.

### Host machine checklist
- [ ] Docker Desktop installed and set to **start at login**; the user logs in automatically after a restart.
- [ ] The machine **never sleeps while on power** (macOS: System Settings → Energy / Battery → Options → "Prevent automatic sleeping when the display is off"); OS updates restart it outside working hours.
- [ ] Repo cloned at a fixed path; `.env.live` taken from the password manager (never from chat).
- [ ] `./scripts/live.sh logs backup` shows the nightly backups; weekly off-machine copy done by: Samin.
- [ ] After any reboot: `./scripts/live.sh up` → `./scripts/tunnel-url.sh` → if the URL changed, follow the runbook.
- [ ] Live site down? Call Samin (host owner); Masum can check the Vercel side.

## Documentation index
| # | Document | What it answers |
|---|---|---|
| 00 | [Discovery and decisions](docs/00-Discovery-and-Decisions.md) | What was inspected, confirmed facts, assumptions, gaps, **ORM evaluation**, decisions needing approval |
| 01 | [SRS](docs/01-SRS.md) | Scope, stakeholders, roles, FR/NFR/BR with IDs, use cases, acceptance, traceability matrices |
| 02 | [UI inventory](docs/02-UI-Inventory.md) | Every designed page: fields, actions, tables, validation, roles, APIs, entities, states |
| 03 | [System architecture](docs/03-System-Architecture.md) | Context, components, request flow, auth, workflows, security boundaries |
| 04 | [Database design](docs/04-Database-Design.md) | 83 tables, ER diagrams, data dictionary, relationships, FK list, migration order, seeds, backups |
| 05 | [API specification](docs/05-API-Specification.md) + [openapi.yaml](docs/openapi.yaml) | **Contract 1.0.0-rc.1, awaiting approval:** 309 endpoints, 197 schemas, 70 error codes; every page action mapped; missing/redundant endpoint review |
| 06 | [Accounting and business rules](docs/06-Accounting-and-Business-Rules.md) | Posting rules per workflow, valuation, LC and landed cost, reversals, tax items to confirm |
| 07 | [Authentication and authorization](docs/07-Authentication-and-Authorization.md) | Tokens, claims, storage trade-offs, RBAC, isolation, revocation, rate limits, secrets |
| 08 | [Frontend–backend integration](docs/08-Frontend-Backend-Integration.md) | React structure, API client, forms, refresh strategy, CORS |
| 09 | [Testing strategy](docs/09-Testing-Strategy.md) | Test levels, tooling, 148 test cases with IDs |
| 10 | [Development roadmap](docs/10-Development-Roadmap.md) | Phases P0–P10, first vertical slice, exact first steps |
| 11 | [Deployment and operations](docs/11-Deployment-and-Operations.md) | Environments, CI/CD, queues, monitoring, backup and restore, runbooks |
| 12 | [Validation report](docs/12-Validation-Report.md) | Cross-checks performed and their results; open issues |
| 13 | [Review issues](docs/13-Review-Issues.md) | Prioritized consistency review (40 issues) with exact corrections; schema items applied (see its status table) |
| 14 | [Physical schema and migrations](docs/14-Physical-Schema-and-Migrations.md) | Final MySQL 8.0 schema: verification, full ER diagram, every relationship, migration order, how to run |
| 15 | [Implementation slice 1](docs/15-Implementation-Slice-1.md) | What is built (sign-in with 2-step, roles, Customers), deviations from 00–14, how to run, actual test results |

## How the documentation stays consistent
`docs/04`, `docs/14`, the Laravel migrations, `docs/05`, `docs/openapi.yaml`, and the generated tables of `docs/01`, `docs/02` and `docs/09` are **built from one set of definitions** in `docs/source/`. The build fails if any FK points to a missing key, any endpoint references an unknown table, rule, page or schema, any page lacks an API, any business rule lacks a test, or the FK graph has a cycle. Edit the sources, rebuild, and commit both.

## Project decisions

Recorded 20 Oct 2026 (backlog task P0.1-SETUP-01, Jira PEP-3). Change a decision by editing this section in a PR and updating the Jira project description.

### Team

| Role | Person | Jira |
|---|---|---|
| Backend dev, tech lead, Jira admin, host owner | Md. Samin Israk | assignee for BE, SETUP, VAL and "Both" tasks |
| Frontend dev | Masum Billah | assignee for FE tasks |

### Tools and accounts

| Item | Decision |
|---|---|
| GitHub | Personal account **`samin1362`**: private repos `Samin1362/Pluto-ERP-Project-Backend` and `Samin1362/Pluto-ERP-Project-Frontend`. Masum is added as a collaborator (Write). |
| Jira | Project **Pluto-ERP-Project**, key **`PEP`**, at `saminisrak1991.atlassian.net`. Branches and commits carry the issue key: `feat/PEP-123-P4.3-BE-02-…`, commit `PEP-123 P4.3-BE-02: …`. |
| Live backend host | **Samin's Mac** (Apple silicon, macOS 26) running Docker Desktop, exposed through a Cloudflare quick tunnel (`*.trycloudflare.com`). Samin restarts it and runs `scripts/point-backend.sh` when the tunnel URL changes. The live API is only up while this Mac is awake and online, so turn off sleep on power (P0.4-BE-05 checklist). |
| Frontend hosting | Vercel project `pluto-erp-frontend`; plan still open (C3). |
| Naming | The product is **Pluto** everywhere (owner decision 21 Oct 2026, Jira PEP-436/440): UI, code (`pluto:*` commands, `PLUTO_*` env, `pluto*` databases, `pl_rt` cookie, `X-Pluto-Proxy-Secret`), docs and repos. |

### API contract

`docs/openapi.yaml` version **`1.0.0-rc.1`** is the working contract for v1. The "no endpoint until approved" note in docs 05 is waived for this team. Any change goes through backlog §4.4: the backend updates the generators, runs `make docs`, and the frontend syncs the contract. The version becomes `1.0.0` when the client signs off.

### Ways of working

- **PR review:** within **1 working day**; urgent fixes the same day.
- One feature at a time: backend → frontend → validation → live (backlog §3).
- Git is run by hand by each developer; squash-merge PRs into `main`.
- Secrets (Jira token, `.env.live`, `PROXY_SHARED_SECRET`, passwords) are never committed; share them through a password manager.

### Open decisions

Samin owns every open decision and collects the client's answers for the business ones. A decision is due before the phase in "Needed by" starts.

| # | Decision | Needed by | Status |
|---|---|---|---|
| C1 | Host machine for the live backend | P0.4 | **Decided:** Samin's Mac (above) |
| C2 | Domain + named tunnel (or VPS) for production | P17 | Open |
| C3 | Vercel plan: Hobby is non-commercial; Pro for a paid client project? | P0.6 | Open |
| C4 | SMTP provider for e-mails | P5 | Open (client); live uses the `log` mail driver until then |
| C5 | File storage beyond the host disk (e.g. Cloudflare R2) | P13 | Open (client) |
| C6 | Finance manager journal approval limit (docs 15 D8, assumed ৳ 5,00,000) | P7 | Open (client) |
| C7 | DEC-13: financial reports and dashboard figures for Sales staff and Viewer | P9 | Open (client) |
| C8 | DEC-11: branch-restricted users needed? | P15 | Open (client) |
| C9 | DEC-04: can one business be both customer and supplier? | P10 | Open (client); today: no |
| C10 | VAT / Mushak requirements | P17 | Open (client + tax adviser) |
| C11 | DEC-10: do LC charges and payments post to the books in v1? | P16 | Open (client); docs assume yes |
| C12 | Rate limits for offices sharing one IP | P17 | Open (backend) |
| C13 | docs 15 D10: `amr` column on `user_sessions` vs a cache entry | P15 | Open (backend) |
| C14 | Bangla on printed documents (needs a PDF engine with Bangla shaping) | P15 | Open (client); English PDFs until then |
| C15 | GAP-10: salesperson on invoices and in sales reports | P10 | Open (client); default: dropped |
| C16 | Parties summary endpoint (docs 15 D5) | P6 | Open (backend) |
