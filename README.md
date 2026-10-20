# Pluto-ERP-Project · Backend (`pluto-erp-backend`)

Laravel 12 API for LedgerFlow ERP (the product name used in code and docs). The full setup guide ("Run locally", Docker, live deploy) is written in backlog task P0.9-BE-01; until then see `backlog.md` §2 and Phase 0.

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
| GitHub | Personal account **`samin1362`**: private repos `samin1362/pluto-erp-backend` and `samin1362/pluto-erp-frontend`. Masum is added as a collaborator (Write). |
| Jira | Project **Pluto-ERP-Project**, key **`PEP`**, at `saminisrak1991.atlassian.net`. Branches and commits carry the issue key: `feat/PEP-123-P4.3-BE-02-…`, commit `PEP-123 P4.3-BE-02: …`. |
| Live backend host | **Samin's Mac** (Apple silicon, macOS 26) running Docker Desktop, exposed through a Cloudflare quick tunnel (`*.trycloudflare.com`). Samin restarts it and runs `scripts/point-backend.sh` when the tunnel URL changes. The live API is only up while this Mac is awake and online, so turn off sleep on power (P0.4-BE-05 checklist). |
| Frontend hosting | Vercel project `pluto-erp-frontend`; plan still open (C3). |
| Naming | Repos, Jira, Vercel and Docker Compose use "Pluto"; code identifiers keep "LedgerFlow" (`ledgerflow:*`, `LEDGERFLOW_*`, `lf_rt`, docs 00–15). |

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
