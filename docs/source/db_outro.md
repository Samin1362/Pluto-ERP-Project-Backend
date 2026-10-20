
## 10. Index and uniqueness recommendations

The per-table indexes are listed in section 6. The reasoning behind them:

| Access path (screen) | Index | Why |
|---|---|---|
| Ledger detail, GL, trial balance, statements (H7, H15–H19) | `journal_lines (company_id, ledger_id, is_posted, entry_date)` | Range scan of one ledger's posted lines by date; TB = GROUP BY ledger over the same index |
| Voucher list, day book (H8, J1) | `journal_entries (company_id, entry_date, status)`, `(company_id, entry_type, status)` | Filter by date range, type tab and status |
| Invoice/bill lists by tab (E2, F2) | `sales_invoices (company_id, status, due_date)`, `(company_id, invoice_date)` | Overdue = posted, unpaid, due_date < today |
| Party open documents (B4, E7 allocation) | `sales_invoices (party_id, payment_status)` | Oldest-first allocation list |
| Aging (A5, A6, J6, J7, B7, C7) | Same as above + `bill_allocations (sales_invoice_id)` | Balance per document |
| Stock card, movements (D4, D9) | `stock_movements (company_id, product_id, warehouse_id, moved_on)` | Running balance per product/warehouse |
| Low stock (D12, A8) | `stock_balances (product_id, warehouse_id)` UQ + `product_warehouse_settings (product_id, warehouse_id)` UQ | Join on the same pair |
| LC dashboard and expiry (M1, M8) | `letters_of_credit (company_id, status, expiry_date)` | Active LCs expiring within N days |
| Reconciliation (G10) | `bank_statement_lines (company_id, money_account_id, status)` | "To match" filter |
| Global search (L1) | `parties (company_id, name)`, `(company_id, mobile)`, `products (company_id, name)`, barcode UQ, document number UQs | Prefix search on names; exact on numbers/barcodes. MySQL FULLTEXT on `parties.name`, `products.name` is an optional later optimisation |
| Login | `users (email)` UQ | |
| Refresh rotation | `user_sessions (refresh_token_hash)` UQ, `(family_id)` | Reuse detection revokes the family |
| Idempotency | `idempotency_keys (company_id, user_id, idempotency_key)` UQ | Duplicate-submit protection |

**Business uniqueness rules** enforced in the database:
`(company_id, number)` on every document, `(company_id, voucher_number)` on journal entries, `(company_id, party_id, supplier_bill_no)` (duplicate supplier bill), `(company_id, code)` on parties, products, ledgers, groups, warehouses and branches, `(company_id, barcode)`, `ledger_id` UNIQUE on parties, money_accounts and ie_categories (1:1), `journal_entry_id` UNIQUE on each document (one posting per document, which blocks double posting), `journal_entries.reverses_entry_id` UNIQUE (an entry can be reversed only once), `bank_statement_lines.matched_journal_line_id` UNIQUE (one book line matches one statement line), `bank_statement_imports (money_account_id, file_sha256)` (no re-import), `(company_id, fy_scope, document_type)` on number sequences (`fy_scope` = 0 for never-reset series).

## 11. Seed and reference data strategy

Seeders are idempotent: they `upsert` by natural key, so they can run on every deploy. There are three layers:

### 11.1 Global reference data (`ReferenceDataSeeder`, every environment)
- `currencies`: BDT (৳, 2), USD, EUR, CNY, INR, GBP, SGD, JPY.
- `permissions`: `{module}.{action}` for modules `dashboard, sales, purchases, parties, inventory, banking, lc, accounting, reports, settings` × actions `view, create, edit, approve, delete`, plus `inventory.view_costs`, `reports.export`, `accounting.lock_period`, `accounting.close_year`, `banking.reconcile`, `settings.users`, `settings.roles`, `settings.backup`, `settings.restore`. Income and expenses fall under `banking`. LC gets its own module (the K11 matrix has no LC row; see GAP-23 in 02).

### 11.2 Company bootstrap (`CompanyProvisioner` service, run when a company is created, inside one transaction)
1. `company_settings` with defaults; `payment_terms`: Cash (0), Net 7, Net 14, Net 15 (default), Net 30, Net 45.
2. `fiscal_years` for the current FY (1 Jul – 30 Jun) and 12 `accounting_periods` (current month `open`, later months `future`).
3. Roles from templates, using the K11 matrix exactly: Owner (system, all permissions), Finance manager (payments limit ৳ 5,00,000), Accountant (journal limit ৳ 1,00,000, payment limit ৳ 50,000), Sales staff, Store keeper (no `inventory.view_costs`), Viewer.
4. The default chart of accounts below, with `account_mappings`.
5. `number_sequences` for the FY, one per document type:

| document_type | Prefix | Example | Reset |
|---|---|---|---|
| sales_invoice | INV-{FY}- | INV-2026-0588 | FY |
| quotation | QT-{FY}- | QT-2026-0044 | FY |
| sales_return | SR-{FY}- | SR-2026-0020 | FY |
| credit_note | CN-{FY}- | CN-2026-0032 | FY |
| purchase_order | PO-{FY}- | PO-2026-0121 | FY |
| purchase_bill | BILL-{FY}- | BILL-2026-0234 | FY |
| purchase_return | PR-{FY}- | PR-2026-0009 | FY |
| debit_note | DN-{FY}- | DN-2026-0010 | FY |
| receipt | RV-{FY}- | RV-2026-0922 | FY |
| payment | PV-{FY}- | PV-2026-0464 | FY |
| journal | JV-{FY}- | JV-2026-0423 | FY |
| contra | CV-{FY}- | CV-2026-0063 | FY |
| expense | EXP-{FY}- | EXP-2026-0143 | FY |
| income | INC-{FY}- | INC-2026-0088 | FY |
| stock_adjustment | ADJ-{FY}- | ADJ-2026-0042 | FY |
| stock_transfer | TRF-{FY}- | TRF-2026-0118 | FY |
| letter_of_credit | LC-{FY}- | LC-2026-022 (padding 3) | FY |
| challan | DC-{FY}- | DC-2026-0412 | FY |
| system_journal | SJ-{FY}- | SJ-2026-0001 | FY |
| customer | CUS- | CUS-0187 | never |
| supplier | SUP- | SUP-0065 | never |

`{FY}` is the calendar year in which the FY starts. Under DEC-06 a number is consumed at first save.

### 11.3 Default chart of accounts (seeded per company; editable afterwards)
It reproduces the designed H2 chart and adds the ledgers needed by posting rules that the design lacked (GAP-04, marked ✚).

| Code | Name | Type | Nature | Mapping key |
|---|---|---|---|---|
| 1 | Assets | Primary | Asset | |
| 11 | Current assets | Group | Asset | |
| 1100 | Cash and bank | Group | Asset | `cash_bank_group` |
| 1101-01 | Cash in hand | Ledger (cash) | Asset | |
| 1201 | Sundry debtors | Group | Asset | `sundry_debtors_group` |
| 1301 | Inventory | Group | Asset | `inventory_group` |
| 1301-01 | Inventory, main warehouse | Ledger (inventory) | Asset | default for first warehouse |
| 1401 | Advances and deposits | Ledger | Asset | |
| 1402 ✚ | Tax receivable | Ledger (tax) | Asset | |
| 1403 ✚ | LC margin deposits | Ledger | Asset | `lc_margin` |
| 1404 ✚ | Import goods in transit (LC) | Ledger | Asset | `goods_in_transit` |
| 1405 ✚ | Stock in transit (between warehouses) | Ledger | Asset | `stock_in_transit` |
| 12 | Fixed assets | Group | Asset | |
| 1501–1503 | Delivery vans; Furniture and fixtures; Office equipment | Ledger | Asset | |
| 1509 | Accumulated depreciation | Ledger | Asset (contra) | |
| 2 | Liabilities | Primary | Liability | |
| 21 | Current liabilities | Group | Liability | |
| 2101 | Sundry creditors | Group | Liability | `sundry_creditors_group` |
| 2201 | Salaries payable | Ledger | Liability | |
| 2202 | Accrued expenses | Ledger | Liability | |
| 2205 ✚ | Tax payable | Ledger (tax) | Liability | |
| 2301 | Bank cash credit | Ledger | Liability | |
| 2302 ✚ | LC acceptance liability | Ledger | Liability | `lc_acceptance` |
| 9999 ✚ | Suspense | Ledger (system) | Liability | `suspense` |
| 22 | Long-term liabilities | Group | Liability | |
| 3 | Equity | Primary | Equity | |
| 3101 | Share capital | Ledger | Equity | |
| 3201 | Retained earnings | Ledger (system) | Equity | `retained_earnings` |
| 3301 ✚ | Opening balance adjustment | Ledger (system) | Equity | `opening_balance_adjustment` |
| 4 | Income | Primary | Income | |
| 41 | Sales | Group | Income | |
| 4101 | Sales, wholesale | Ledger | Income | `sales_default` |
| 4102 | Sales, retail counter | Ledger | Income | |
| 4109 | Sales returns | Ledger | Income (contra) | `sales_returns` |
| 4110 ✚ | Discount allowed | Ledger | Income (contra) | `discount_allowed` (used only when "post discounts separately" is on) |
| 42 | Other income | Group | Income | |
| 4201 | Delivery income | Ledger | Income | |
| 4302–4304 | Interest received; Scrap and carton sales; Commission received | Ledger | Income | |
| 4305 ✚ | Discount received | Ledger | Income | `discount_received` |
| 5 | Cost of sales | Primary | Expense | |
| 5001 | Cost of goods sold | Ledger (system) | Expense | `cogs` |
| 5002 ✚ | Stock shortage, damage and gains | Ledger (system) | Expense | `stock_adjustment` |
| 5101 ✚ | Purchases, non-stock items | Ledger | Expense | `purchases_non_stock` |
| 5109 ✚ | Purchase returns, non-stock items | Ledger | Expense (contra) | `purchase_returns_non_stock` |
| 6 | Expenses | Primary | Expense | |
| 61 | Operating expenses | Group | Expense | |
| 6001, 6101, 6105, 6110, 6199, 6201 | Salaries and wages; Rent; Transport and delivery; Electricity and utilities; Other expenses; Marketing and promotion | Ledger | Expense | linked to `ie_categories` |
| 6198 ✚ | Cash short and over | Ledger (system) | Expense | `cash_short_over` |
| 62 | Depreciation and finance | Group | Expense | |
| 6501 | Depreciation | Ledger | Expense | |
| 6601 | Interest on loans | Ledger | Expense | |
| 6603 ✚ | Exchange gain or loss | Ledger (system) | Expense | `fx_gain_loss` |
| 6702 | Bank charges | Ledger (system) | Expense | `bank_charges` |
| 6799 ✚ | Rounding off | Ledger (system) | Expense | `rounding` |

Bank, customer and supplier ledgers are created when you add a money account or party, as sub-codes: 1102-NN banks, 1103-NN wallets, 1201-NNN customers, 2101-NNN suppliers. Mapping keys are documented with their posting rules in [06 §3](06-Accounting-and-Business-Rules.md#3-configurable-account-mappings).

### 11.4 Demo data (`DemoCompanySeeder`, local and staging only)
This builds the "Meghna Traders Ltd." dataset from the design (3 warehouses, about 15 products, 8 customers, 8 suppliers, 5 LCs). Demo data is created **through the domain services** (post bill → post invoice), never by inserting journal lines directly. That way it also acts as a smoke test of the posting rules. It is never run in production; the seeder refuses if `APP_ENV=production`.

## 12. Backup and migration rollback strategy

**Backups** (details in [11-Deployment-and-Operations](11-Deployment-and-Operations.md)):
- **Physical**: nightly full backup (Percona XtraBackup or a managed snapshot), plus continuous **binary logs** for point-in-time recovery. Target RPO 15 minutes, RTO 4 hours (NFR-030/031).
- **Logical, per company** (`company_backups`, K15): a consistent `REPEATABLE READ` export of one company's rows (`--single-transaction` semantics). It excludes credentials, sessions, reset tokens, idempotency keys and the audit log, and restore never rewrites `audit_logs` (R-11, 11 §8). It is used for company restore and the auditor pack. Restore runs in maintenance mode for that company only, takes a `pre_restore` snapshot first, and is Owner-only.
- Monthly restore drill into staging, with the trial balance compared against production.

**Migrations:**
1. Migrations are **forward-only in production**. Every migration still implements `down()`, which is used in development, CI and staging rehearsal.
2. Use **expand → migrate → contract** for changes to live tables. Add the nullable column or new table, deploy code that writes both, backfill in batches, then remove the old column in a later release. A destructive step never shares a release with the code change that needs it.
3. MySQL DDL is not transactional. A failed migration is rolled back by **restoring the pre-deploy snapshot**, which is taken automatically by the deploy pipeline immediately before `php artisan migrate --force`, or by a hand-written corrective migration.
4. Large-table changes (`journal_lines`, `stock_movements`, `audit_logs`) use `ALGORITHM=INPLACE, LOCK=NONE` where MySQL supports it, or gh-ost/pt-online-schema-change.
5. CI runs `migrate:fresh --seed`, then the full test suite, then `migrate:rollback` all the way down and `migrate` again, to prove `down()` works.

