# Performance results (TC-NFR-001/002/003; backlog P17.1-BE-02)

Method: tests/perf/README.md — 1,000,000 posted journal lines (500,000 vouchers over ~45 ledgers, current financial
year) in a disposable copy (`pluto_e2e`), served by the **production image** (opcache, config and route cache, debug off),
measured from inside the Docker network.

**Host of these runs:** the developer laptop (Windows 11, Docker Desktop VM with 8 vCPU and 3.7 GB RAM shared by MySQL,
PHP, nginx, k6 and two other stacks). It is not the production host: the 50-user targets must be re-measured on the
chosen production host before go-live (go-live checklist C1), with the same script.

## What the 1 M-line run found, and the fixes (1 Nov 2026)

| Request | Before | After (laptop awake) | After (laptop, throttled after sleep) | Target |
|---|---|---|---|---|
| Trial balance (whole year) | 58 s | 0.36 s | 0.83 s | p95 < 2 s |
| Profit and loss (whole year) | 48 s | 0.36 s | 1.07 s | p95 < 2 s |
| Balance sheet | — | 0.70 s | 1.66 s | p95 < 2 s |
| Chart / ledger list, 100 with balances | 75 s | 0.30 s | 1.02 s | p95 < 300 ms |
| Voucher list, page 40 | 5.4 s | 0.12 s | 0.38 s | p95 < 300 ms |
| One ledger's statement, page 1 (~22 k lines) | 6 s | 0.17 s | 0.38 s | p95 < 300 ms |
| Customer list, invoice list, one voucher | 0.1–1 s | 0.04–0.11 s | 0.04–0.09 s | p95 < 300 ms |

Fixes (code, schema, configuration):
1. `journal_lines (company_id, ledger_id, is_posted, entry_date)` → **+ debit, credit** (covering): balances and the
   statements read the index only. Queries that started from `ledger_id` alone now also filter `company_id` so they use it.
2. `journal_entries`: **(company_id, entry_date)** added and (company_id, entry_date, status) → **(company_id, status,
   entry_date)**: the voucher list orders without a sort; the drafts beside the trial balance read only drafts.
3. Ledger list: `EXISTS` instead of `COUNT`; opening balances found from the few opening vouchers.
4. Ledger statement: running balance by a SQL window function (exact DECIMAL), only the requested page leaves MySQL.
5. Trial balance: the per-voucher balance scan runs only when Dr ≠ Cr (the nightly integrity check covers every voucher).
6. P&L / balance sheet: year-end closings subtracted instead of joining every line to its voucher.
7. MySQL `innodb_buffer_pool_size` 128 MB → **1 GB** (`MYSQL_BUFFER_POOL_SIZE`): the journals' indexes are ~0.5 GB at 1 M lines.
8. PHP-FPM workers 5 → **16** (`PHP_FPM_MAX_CHILDREN`): 5 workers queued requests at 50 users.

## Load test (k6, 50 users, 1 s think time) on the laptop

| Run | Throughput | Errors | Lists p95 | Reports p95 | Post 50-line invoice p95 |
|---|---|---|---|---|---|
| Before fixes 7–8 | 17 req/s | 0 % | 3.1 s | 4.0 s | 3.8 s |
| After fixes 1–8 (awake) | 24 req/s | 0 % | 2.7 s | 6.4 s | 2.8 s |
| After, throttled after sleep | 9.7 req/s | 0 % | 9.1 s | 30.7 s | 8.6 s |

Every request succeeded. Under 50 concurrent users the laptop VM saturates (MySQL and PHP share 3.7 GB with the load
generator and two other stacks, and the machine throttles after sleep), so the 50-user p95 targets are **not shown on
this hardware**. Single requests meet the targets with room to spare. **Action before go-live:** run
`tests/perf/nfr.js` on the production host (C1) with `MYSQL_BUFFER_POOL_SIZE` ≈ half its RAM and
`PHP_FPM_MAX_CHILDREN` ≈ RAM for PHP / 60 MB, and record the result here.
