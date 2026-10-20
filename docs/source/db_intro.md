# 04 · Database design

> Status: **Draft for Laravel + Eloquent + MySQL 8.0.** ORM approved 18 Oct 2026 ([00 §6](00-Discovery-and-Decisions.md#6-orm-compatibility-evaluation--decided-eloquent)). Laravel migrations own the schema.
>
> **Generated file.** Sections 5–9 come from `docs/source/schema.py` via `python3 docs/source/build_db.py`. Change the schema there and regenerate; don't hand-edit those sections.

## 1. Scope

The schema covers every entity the designed screens need (see [02-UI-Inventory](02-UI-Inventory.md)): tenancy and access, the chart of accounts and journal, parties, inventory, sales, purchases, cash and bank, income and expenses, and LC management. Shipments are part of the LC model (`lc_shipments`), so there is no separate shipment-management module (CF-22).

Engine: **MySQL 8.0.16+** (CHECK constraints are enforced from 8.0.16), InnoDB, `utf8mb4` / `utf8mb4_0900_ai_ci`, `sql_mode` including `STRICT_ALL_TABLES`, server time zone UTC.

## 2. Conventions

| Topic | Rule |
|---|---|
| Names | `snake_case`; tables plural (`sales_invoices`); FKs `<singular>_id`; booleans `is_`/`has_`/verb (`track_stock`) |
| Keys | `id BIGINT UNSIGNED AUTO_INCREMENT` surrogate PK. Exceptions: `currencies.code` (natural ISO key) and pure junctions (composite PK). Public identifiers in URLs are the numeric id. Human document numbers (`INV-2026-0588`) are unique business keys, never PKs |
| Tenancy | Every business table has `company_id NOT NULL` → `companies(id) ON DELETE RESTRICT`. Business unique keys always include `company_id` (e.g. `UNIQUE(company_id, number)`). Child "line" tables inherit tenancy from their header and carry no `company_id`; they are only accessible through the header |
| Money | Base currency (BDT) amounts `DECIMAL(18,2)`; foreign amounts `DECIMAL(18,2)` + currency code; FX rates `DECIMAL(18,6)`; unit costs `DECIMAL(18,4)`; quantities `DECIMAL(18,3)`; percentages `DECIMAL(7,4)`. **No FLOAT/DOUBLE anywhere** (checked by the build script). PHP uses `brick/math` BigDecimal; JSON carries amounts as strings |
| Enums | `VARCHAR(30)` + `CHECK (col IN (...))` mirrored by PHP 8.1 backed enums. Adding a value is an additive migration; MySQL `ENUM` is avoided because reordering or removing values rewrites the table |
| Time | `DATETIME` in UTC for events (not `TIMESTAMP`, whose range ends 19 Jan 2038; Laravel `timezone` = UTC); `DATE` for business dates (invoice date, voucher date), which are interpreted in the company time zone (Asia/Dhaka) |
| Audit columns | `created_by`, `updated_by` → `users` `ON DELETE SET NULL`; `created_at`, `updated_at` |
| Concurrency | Editable aggregates carry `lock_version INT`; the API exposes it as an `ETag` and requires `If-Match` on update (CF-27) |
| Deletion | Financial rows (journal, documents once posted, stock movements, audit) are **never deleted**. Master data with history is **deactivated**; master data without history may be soft-deleted (`deleted_at`) or hard-deleted |
| FK actions | Default `ON DELETE RESTRICT, ON UPDATE RESTRICT` (surrogate keys never change; MySQL 8.0 also forbids CHECK constraints and stored generated columns on columns used by a cascading action). `CASCADE` only from a draft header to its lines and to pure dependants (template items, denominations, user-owned rows); `SET NULL` only for optional references between master-data rows. Never `SET NULL` on posted financial rows or append-only tables. Users are never hard-deleted (deactivated), so actor columns (`created_by`, `posted_by`, …) are RESTRICT |

## 3. Candidate entities: included, merged or excluded

| Candidate (from the brief) | Decision | Justification |
|---|---|---|
| Companies | **Included** `companies`, `company_settings` | Company switcher, K1–K7 (CF-02) |
| Branches and warehouses | **Included** `branches`, `warehouses` | K1 Locations; D7/D8; report branch filters |
| Users, roles, permissions, assignments | **Included** `users`, `roles`, `permissions`, `role_permissions`, `company_users`, `company_user_branches` | K8–K11; one role per membership |
| Products, categories, units, stock movements | **Included** `products`, `product_categories`, `units`, `stock_groups`, `stock_movements`, `stock_balances` (also holds value and average cost per warehouse, R-02), `product_warehouse_settings` | D1–D14 |
| Customers and suppliers | **Merged** into `parties` (+ `party_groups`, `party_bank_accounts`) | Identical layouts and fields (B/C); one ledger per party. ⚑ DEC-04 |
| Purchase orders and lines | **Included** | F8; LC links to a PO |
| Sales orders and lines | **Excluded** | No sales-order screen exists. Quotation → invoice covers the flow (E10). Can be added later |
| Inventory receipts and issues | **Merged** into documents | A purchase bill is the receipt (F3) and a posted invoice is the issue; both write `stock_movements`. A separate GRN/issue note has no screen (AS-08) |
| Accounts and account groups | **Included** `account_groups`, `ledgers` | H2–H7 |
| Journal entries and lines | **Included** `journal_entries`, `journal_lines` | The single posting store every report reads |
| Vouchers and voucher sequences | **Merged**: vouchers are `journal_entries` with `entry_type` journal/payment/receipt/contra; sequences are `number_sequences` | H8–H13 show vouchers as journal entries with a type |
| Payments and receipts | **Included** as `journal_entries` + `payment_details` + `bill_allocations` | One model serves E7, F5, G6, G7, H10, H11 and I3/I6 |
| Tax configuration | **Included** `tax_rules` | K6 (user-defined only) |
| Bank accounts and bank transactions | **Included** `money_accounts`, `bank_statement_imports`, `bank_statement_lines`, `bank_reconciliations`, `cash_counts` | G1–G13. "Bank transactions" are journal lines on bank ledgers |
| Audit logs | **Included** `audit_logs` | Activity timelines everywhere, H13 audit trail |
| LC applications, amendments, bank charges, import expenses | **Included** `letters_of_credit`, `lc_amendments`, `lc_amendment_changes`, `lc_charges`, `lc_payments`, `lc_documents`, `lc_document_templates(_items)`, `lc_shipments`, `landed_cost_allocations(_lines)` | M1–M8 |
| Cost centres, batches/expiry, cheque books, price lists, UoM conversion | **Excluded (deferred)** | Not designed (GAP-11, GAP-21, AS-05, AS-06, AS-12) |

## 4. Key design patterns

### 4.1 Documents post to one journal
Every business document that affects the books holds a nullable, **unique** `journal_entry_id`, which is filled at posting. The journal entry points back with `source_type` + `source_id`, used for navigation only. Integrity is enforced from the document side, because the document → journal FK is a real foreign key. Reports read only `journal_lines` with `is_posted = 1`; `entry_date` and `is_posted` are copied onto the lines at posting so ledger queries use one index: `(company_id, ledger_id, is_posted, entry_date)`.

### 4.2 Balanced and immutable
A CHECK makes each line either a debit or a credit, never both. The posting service asserts `SUM(debit) = SUM(credit)` and writes the totals to the header; a header CHECK rejects `status='posted'` with unequal totals. Posted entries and lines are never updated (except to set `status='reversed'` on the original) or deleted. A database trigger blocks `UPDATE/DELETE` on posted `journal_lines` as a second line of defence (see 06 §15).

### 4.3 Parties, banks and warehouses own ledgers
Creating a customer creates a ledger under the mapped *Sundry debtors* group (CF-10), with `parties.ledger_id` UNIQUE (1:1). The same applies to `money_accounts.ledger_id`, `warehouses.inventory_ledger_id` and `ie_categories.ledger_id`.

### 4.4 Settlement
`bill_allocations` links the party-ledger line of a receipt, payment, credit note, debit note or advance to the invoice or bill it settles. `sales_invoices.amount_settled` and `balance_due` are a denormalized cache, updated in the same transaction under a row lock; a nightly job compares them with `SUM(bill_allocations)`.

### 4.5 Stock
`stock_movements` is the append-only stock ledger. `stock_balances` (quantity, value and moving average per product and warehouse; AS-03 as revised by R-02) is a cache maintained only by the stock service, inside the posting transaction and under `SELECT … FOR UPDATE`. A nightly job proves that `stock_balances.qty_on_hand` = `SUM(quantity_in − quantity_out)` and `total_value` = `SUM(value_in − value_out)` of the movements.

### 4.6 Numbering
`number_sequences` holds one row per (company, FY, document type). Numbers are taken with `SELECT … FOR UPDATE` in the same transaction as the first save; deleted draft numbers are voided and audited, never reused (DEC-06).

### 4.7 Polymorphic references
Only `attachments`, `audit_logs`, `notifications`, `approval_requests`, `journal_entries.source_*` and `stock_movements.source_*` use type + id pairs (the full list with integrity notes is in [14 §5.5](14-Physical-Schema-and-Migrations.md#55-polymorphic-references-no-fk-by-design)). That's because they attach to many tables; real FKs would mean dozens of nullable columns. The application validates them, and a nightly orphan check reports problems. Financial relationships never rely on polymorphic keys.
