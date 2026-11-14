# UAT with the owner's data, sign-offs and go-live

Backlog P17.3-VAL-01 (PEP-419) and P17.3-VAL-02 (PEP-420). These steps need the owner, the company's accountant and a
tax adviser; the development team prepares and supports them but cannot complete them alone. Tick each box with the
date and initials, and keep this page (or a printed copy) with the go-live records.

## Part A · UAT on a staging copy (PEP-419)

**Staging:** a separate stack (e.g. `./scripts/live.sh` on a second machine, or the same host with another project name
and port) with a fresh database — never the live one. Defects are logged in Jira (project PEP, type Bug) with severity:
Sev-1 wrong money / data loss / no access, Sev-2 a main task blocked without a workaround, Sev-3 workaround exists,
Sev-4 cosmetic. **Exit rule: no open Sev-1 or Sev-2.**

**Owner decision (14 Nov 2026):** while the live stack runs on one laptop that has no memory for a second stack, UAT
runs on the live stack as the real company's own company (company isolation is proven by PEP-486's sweep). If the
test month goes wrong, that company is removed and created again before C7. The data pack the owner fills is kept
outside the repos (`E:\Pluto-ERP\uat\`).

| # | Step | Who | Done |
|---|---|---|---|
| A1 | Create the real company (`pluto:company:create`), set the financial year, tax rules (from the adviser, B2), number series and invoice design | Owner + dev | ☐ |
| A2 | Invite the real users with their roles; each signs in and turns on 2-step verification | Owner | ☐ |
| A3 | Import opening data (runbook opening-data-import.md): banks, ledgers, customers, suppliers, stock — dry runs first | Accountant + dev | ☐ |
| A4 | Opening trial balance equals the old books' closing balances; 3301 shows only the expected equity difference | Accountant | ☐ |
| A5 | Run one real month in staging: sales invoices (cash and credit), receipts, purchase bills, supplier payments, returns, expenses, a stock transfer and an adjustment | Owner + staff | ☐ |
| A6 | One import, if any is open: LC, charges, payment, landed cost (or a rehearsal with last year's LC) | Accountant | ☐ |
| A7 | Bank reconciliation of every bank account for the month from the bank's statement file | Accountant | ☐ |
| A8 | Month-end: trial balance, P&L, balance sheet, receivables and payables aging agree with the accountant's own working | Accountant | ☐ |
| A9 | Lock the month (period lock), try to post into it (refused), unlock with the right role | Accountant | ☐ |
| A10 | Restore drill on staging (monitoring.md §4): books identical after restore | Dev | ☐ |
| A11 | Phones: a salesperson raises an invoice and records a receipt on a phone | Sales staff | ☐ |
| A12 | Defects: none open at Sev-1/Sev-2 (list the Jira keys closed) | Dev | ☐ |

## Part B · Sign-offs (PEP-420)

| # | Sign-off | Evidence | Signed by / date |
|---|---|---|---|
| B1 | **Accountant: posting rules (06)** (sheet: accountant-posting-rules-signoff.md). Each document type's journal checked in staging against 06 §5–§13 (invoice, receipt, bill, payment, returns, notes, stock, cash count, LC, landed cost, year-end) | the A5–A9 vouchers | |
| B2 | **Tax adviser: 06 §14 items (NFR-070)** (questions: tax-adviser-questions.md). Answer each row of 06 §14 (VAT registration and Mushak forms, rates and exemptions, SD, VDS/TDS/AIT, import duties and what is recoverable, LC regulation, invoice numbering, record retention, statement format). Anything Pluto does not do yet becomes a dated Jira item | written answers attached | |
| B3 | **Owner: UAT exit.** Part A complete, no Sev-1/2 open, the owner accepts go-live on the date below | Part A | |

## Part C · Go-live checklist

| # | Item | Done |
|---|---|---|
| C1 | Hosting decided (PEP-407): named tunnel with a domain (named-tunnel.md) or VPS; `https://<host>/api/ready` answers `ready` | ☐ |
| C2 | Vercel points at the production API (`point-backend.sh`); `PROXY_SHARED_SECRET` matches `.env.live` | ☐ |
| C3 | `.env.live` complete and stored in the password manager: APP_KEY, DB passwords, Gmail SMTP app password (`MAIL_MAILER=smtp`), `PLUTO_ALERT_EMAIL`, `SENTRY_LARAVEL_DSN`, `MYSQL_BUFFER_POOL_SIZE`, tunnel token | ☐ |
| C4 | Live migrations run (`./scripts/deploy.sh`); `pluto:integrity` passes | ☐ |
| C5 | Monitoring on (monitoring.md §2): uptime monitor, Sentry (API and web, `VITE_SENTRY_DSN` on Vercel), ops check e-mail received once by test | ☐ |
| C6 | Nightly backup verified the morning after; restore drill done on a live backup (monitoring.md §4, log the result) | ☐ |
| C7 | Real company created on live; users invited; opening data imported (same files as UAT, after the cut-off day) and A4 repeated on live | ☐ |
| C8 | Final backup taken immediately before opening to users; file name recorded here: ________________ | ☐ |
| C9 | Old system frozen at the cut-off date (read-only); staff told the go-live date and how to get help | ☐ |
| C10 | First week: daily check of the ops e-mail, Sentry and the trial balance; first month-end with the accountant | ☐ |

**Go-live date:** ____________  **Project live (owner):** ____________
