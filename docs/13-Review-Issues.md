# 13 · Consistency review: prioritized issues

> Review of every file in the documentation package (README, 00–12, `openapi.yaml`, `docs/source/*`) on 18 Oct 2026, after the ORM decision (Eloquent).
> Each issue gives the files to change and the exact correction. Issues marked ⚑ need an owner decision first.
>
> **Status (18 Oct 2026, after schema finalization [14 §2](14-Physical-Schema-and-Migrations.md#2-changes-made-while-finalizing) and the API contract [05 §12](05-API-Specification.md#12-contract-review-18-oct-2026-missing-redundant-and-changed-endpoints)):**
> - **Applied:**
>   - Schema: R-01, R-02 ⚑ (recommended option), R-06, R-15, R-18 (as a junction table), R-19, R-21, R-29, R-30.
>   - Contract and docs: R-03, R-04, R-07, R-08, R-09, R-10, R-11, R-12, R-13 ⚑, R-14, R-20, R-22, R-26, R-27, R-28, R-31, R-32, R-33, R-34, R-35, R-36, R-39, R-40.
> - **Partly applied:**
>   - R-24 ⚑: BR-052 cost masking is declared on the stock endpoints; whether the Store keeper receives goods is still to decide.
> - **Open:**
>   - R-05 (financial-year rollover);
>   - R-16 (negative-stock costing), R-17 (stock value going negative);
>   - R-23 (roadmap migration list);
>   - R-25 ⚑ (DEC-13);
>   - R-37 (unrealised FX), R-38 (2FA challenge storage).
> The automated checks still pass (FK resolution, ER ↔ schema, page ↔ API, rule ↔ test, links, Mermaid). The issues below are the semantic problems those checks cannot see.

**Priority scale:** **P1 Critical**: wrong books, failed migrations, or no way to correct data; fix before Phase 1. **P2 High**: a missing capability or security weakness that a designed workflow needs; fix before the phase that builds it. **P3 Medium**: an inconsistency that would cause rework. **P4 Low**: wording or documentation polish.

## Summary

| ID | Priority | Area | Title |
|---|---|---|---|
| R-01 | P1 | Database | CHECK constraints on FK columns with `ON UPDATE CASCADE` will make three migrations fail on MySQL 8.0 |
| R-02 | P1 ⚑ | Accounting / DB | Company-wide average cost conflicts with per-warehouse inventory ledgers |
| R-03 | P1 | API / Accounting | Posted returns, notes, LC charges, LC payments and landed-cost allocations cannot be corrected |
| R-04 | P1 | Accounting / API | Purchase return and debit note would post the supplier debit twice |
| R-05 | P1 | Accounting | Financial-year rollover deadlock: no July postings until the old FY closes, and the year-end entry lands in a locked period |
| R-06 | P1 | Accounting / DB | System journal entries (cancellations, LC, landed cost, openings, year-end) have no voucher-number series |
| R-07 | P2 | UI / API | Six sidebar list screens are missing (quotations, payments received, returns and credit notes, POs, payments made, returns and debit notes) |
| R-08 | P2 | API | No endpoint to apply an advance or credit later, or to undo an allocation |
| R-09 | P2 | Security / API | Context-dependent permissions are declared as one static permission |
| R-10 | P2 | Security | New custom roles get unlimited payment and journal approval by default |
| R-11 | P2 | Security / Ops | Company backups may expose credentials, and restore erases the audit trail |
| R-12 | P2 | Security | Account lockout lets an attacker lock anyone out (DoS) |
| R-13 | P2 ⚑ | Accounting / DB | Cash-credit and overdraft accounts can't be used (BR-031 blocks them; no account type) |
| R-14 | P2 | Accounting / DB | Tax "recoverable" behaviour is described but has no field |
| R-15 | P2 | Database | Never-reset sequences (customer and supplier codes) are tied to a financial year |
| R-16 | P2 | Accounting | Negative-stock costing is undefined, so the ledger and valuation drift |
| R-17 | P2 | Accounting | Returns and cancellations at bill cost can drive stock value negative |
| R-18 | P3 | DB / API | `InvitationCreate.branch_ids` has no storage |
| R-19 | P3 | DB / API | `CashCountInput.note` has no column |
| R-20 | P3 | DB / API | `NoteInput.apply_to_document_id` has no column and no defined meaning |
| R-21 | P3 | DB / API | `idempotency_keys.company_id` is NOT NULL, but `POST /companies` is idempotent |
| R-22 | P3 | Roadmap | P2 receipts need `approval_requests`, which is scheduled for P3 |
| R-23 | P3 | Roadmap | P1 migration list is missing `party_bank_accounts` and is out of dependency order |
| R-24 | P3 ⚑ | Security / Roles | Store keeper: cost data leaks through adjustment and movement responses; "receives stock" contradicts the role matrix |
| R-25 | P3 ⚑ | Security / Roles | Sales staff and Viewer see cash and P&L on the dashboard and in financial statements (DEC-13) |
| R-26 | P3 | Security / API | The ledger picker opened to all members contradicts "Sales staff never see ledgers" |
| R-27 | P3 | API / Frontend | Voucher balance and double-entry preview have no server endpoint (conflicts with DEC-09) |
| R-28 | P3 | API / Accounting | "Cash sale posts invoice and receipt in one transaction" has no API |
| R-29 | P3 | Database | Reversed journal entries are not covered by the balance CHECK |
| R-30 | P3 | Database | Posted documents don't snapshot product and party details, so reprints change |
| R-31 | P3 | Rules | BR-003 (immutable) conflicts with editing delivery details on posted invoices |
| R-32 | P3 | Accounting | LC margin applied is not limited to the margin deposited |
| R-33 | P3 | API / Data | International suppliers are forced to have a Bangladeshi mobile number |
| R-34 | P3 | UI | Recurring-entries screen is missing although the API exists |
| R-35 | P4 | Frontend | Landing route `/dashboard` gives 403 for roles without dashboard access |
| R-36 | P4 | API / Security | Refresh and logout security not declared in OpenAPI; logout fails with an expired access token |
| R-37 | P4 | Accounting | Unrealised FX on open foreign payables at period end is not addressed |
| R-38 | P4 | Security | Two-factor challenge storage and the cookie same-site requirement are unspecified |
| R-39 | P4 | Docs | 04 §4.7 polymorphic list omits `stock_movements.source_*`; `password_reset_tokens` wrongly flagged append-only |
| R-40 | P4 | Docs / API | Small reference errors: TC-SAL-002 cites the wrong section; `LcPayment` lacks `payment_kind`; LC `document_template_id` not persisted |

---

## P1 · Critical

### R-01 · CHECK constraints on cascading FK columns fail on MySQL 8.0
**Problem.** MySQL 8.0 rejects a CHECK constraint on a column that is also used in a foreign-key referential action such as `ON UPDATE CASCADE` (error 3823). Every FK in the schema defaults to `ON UPDATE CASCADE`, and three tables put CHECKs on FK columns, so their migrations would fail:
- `bill_allocations`: `(sales_invoice_id IS NULL) <> (purchase_bill_id IS NULL)`
- `account_mappings`: `(ledger_id IS NULL) <> (account_group_id IS NULL)`
- `stock_transfers`: `from_warehouse_id <> to_warehouse_id`

`ON UPDATE CASCADE` is also pointless here, because surrogate `id` values never change.

**Files and correction.**
- `docs/source/schema.py`: in `class Col.__init__` and `def fk`, change the default `on_update="CASCADE"` to `on_update="RESTRICT"`. Regenerate 04.
- `docs/source/db_intro.md` §2 "FK actions" row: replace "Default `ON DELETE RESTRICT, ON UPDATE CASCADE`" with "Default `ON DELETE RESTRICT, ON UPDATE RESTRICT` (surrogate keys never change; this also keeps CHECK constraints legal on FK columns)".
- `docs/source/tests.py`: add `TC-DB-007 · Migrations create the CHECKs on bill_allocations, account_mappings and stock_transfers on MySQL 8.0 · No error 3823; constraints enforced`, referencing BR-020 and BR-030.

### R-02 · ⚑ Company-wide average cost vs per-warehouse inventory ledgers
**Problem.** AS-03 and `product_costs UNIQUE(product_id)` use one moving average per product across all warehouses. But inventory is posted to a ledger per warehouse (`warehouses.inventory_ledger_id`, 1301-01/1301-02).
- A purchase into warehouse A raises the average for stock in warehouse B without any entry on B's ledger.
- A sale from B then credits B's ledger at A-influenced cost.

Per-warehouse ledger balances therefore drift from per-warehouse valuation. D13's check ("agrees with 1301") only holds in total. Transfers (06 §11.4) "at the cost frozen at dispatch" and landed cost (06 §12.7) "debit the warehouse ledger for stock on hand in that warehouse" make the drift worse.

**Correction (recommended): per-warehouse moving average.**
- `docs/source/schema.py`, table `product_costs`: add `fk("warehouse_id", "warehouses")`; change `uniques=[["product_id"]]` to `[["product_id", "warehouse_id"]]`; purpose "Moving weighted average cost per product and warehouse".
- `docs/source/registry.py`:
  - BR-016: "…the product's moving weighted average cost **in the issuing warehouse** at posting…".
  - BR-017: "On every stock-in **to a warehouse**, that warehouse's average = (warehouse value + incoming value) ÷ (warehouse qty + incoming qty)…".
- `docs/06-Accounting-and-Business-Rules.md`:
  - §11.1: replace "company-wide per product" with "per product and warehouse"; remove "the system shows the same ৳ 845 in both warehouses" reasoning.
  - §11.4: "Transfer out at the source's average; transfer in adds that value to the destination (BR-017 applies)".
  - §12.7 step 3: use the receiving warehouse's quantity on hand.
- `docs/00-Discovery-and-Decisions.md`: AS-03 and DEC-05 become "per product and warehouse".
- `docs/source/pages.py`, D2 field "Valuation method": "Weighted average per warehouse".

**Alternative (owner choice):** keep the company-wide average and use **one** inventory ledger (1301) for all warehouses, with warehouses as a reporting dimension on `journal_lines.warehouse_id`. Then GAP-02 disappears too.

### R-03 · No correction path for several posted documents
**Problem.** 06 §15 says system entries can only be corrected through their document's cancel endpoint, and `POST /vouchers/{id}/reverse` returns 409 `use_document_cancel`. But only invoices, bills, stock transfers and stock adjustments have cancel or reverse endpoints. A posted **sales return, purchase return, credit note, debit note, LC charge, LC payment, landed-cost allocation or cash-count adjustment** cannot be corrected at all. Meanwhile 06 §2 lists `→ cancelled` states for returns and notes, and `lc_charges` has no `reversed` status.

**Correction.**
- `docs/source/api.py`: add these endpoints, each with `req="ReasonRequest"`, `idem=True`, `tx=TXP`, rules `["BR-003","BR-004","BR-009"]`, codes `[200, 409, 422]`:
  - `POST /sales-returns/{id}/cancel` (`sales.edit`; reverses the stock part; cancels its credit note if unapplied, otherwise 409 `note_applied`)
  - `POST /purchase-returns/{id}/cancel` (`purchases.edit`)
  - `POST /credit-notes/{id}/cancel` (`sales.edit`; marks allocations `reversed_at`)
  - `POST /debit-notes/{id}/cancel` (`purchases.edit`)
  - `POST /lc-charges/{id}/reverse` (`lc.edit`; refused once included in a posted landed-cost allocation)
  - `POST /lc-payments/{id}/reverse` (`lc.edit`), with matching `GET /letters-of-credit/{id}/payments`
  - `POST /landed-cost-allocations/{id}/reverse` (`lc.edit`; reverses the inventory/COGS split and the `landed_cost` movements)
- `docs/source/schema.py`, `lc_charges`: add `enum("posting_status", ["draft", "posted", "reversed"], default="'posted'")`.
- `docs/06-Accounting-and-Business-Rules.md` §15: add a row "Wrong return, note, LC charge, LC payment or allocation → its cancel/reverse endpoint posts a mirror entry".
- `docs/source/tests.py`: add TC-SAL-018 (cancel credit note un-applies it), TC-PUR-011 (cancel purchase return restores stock), TC-LC-012 (reverse LC payment restores margin and supplier balance).

### R-04 · Purchase return and debit note would debit the supplier twice
**Problem.** 06 §10 says that for a goods return "the stock entry above *is* the debit-note journal (one entry)". But the API defines `POST /purchase-returns/{id}/post` as "…and create a **draft** debit note", followed by `POST /debit-notes/{id}/post`, which "posts the debit note" again. TC-PUR-008 also expects a draft debit note. As written, the supplier would be debited twice.

**Correction.**
- `docs/source/api.py`:
  - `POST /purchase-returns/{id}/post` summary becomes: "Post the return: stock out at bill cost, Dr supplier / Cr inventory, and create the debit note as **posted** (same journal entry), applied per `usage`".
  - Add `usage` and `apply_to_document_id` to `ReturnInput` (`docs/source/schemas.py`).
  - `POST /debit-notes/{id}/post` summary becomes: "Post a debit note **not** created from a return (price difference, short supply, quality claim)"; it returns 409 `created_from_return` for return-based notes.
- `docs/06-Accounting-and-Business-Rules.md`:
  - §2: replace the "Sales/purchase return … Creates a draft credit/debit note" row with two rows. Sales returns create a **draft** credit note (posted separately: Dr sales returns / Cr customer). Purchase returns create a **posted** debit note sharing the return's entry.
  - §10: add "The debit note's `journal_entry_id` equals the return's; no second entry is posted".
- `docs/source/tests.py`, TC-PUR-008 expected result: "…; debit note created **posted**, sharing the return's journal entry; supplier debited once".

### R-05 · Financial-year rollover deadlock
**Problem.**
1. 06 §16 creates the next FY's sequences only at year-end close, and K4 creates periods only for the current FY. The design closes FY 2025-26 on 28 Jul, so every document dated in July would fail BR-004 (no open period in an open FY) for four weeks.
2. BR-034 requires every period locked, then posts a `year_end_close` entry *dated the last day*, which is inside a locked period. That violates BR-004.

**Correction.**
- `docs/06-Accounting-and-Business-Rules.md` §16: "The next FY (row, 12 periods, number sequences) is **created automatically 30 days before the current FY ends**, or on first use of a date in it, independent of closing. Closing only posts the year-end entry and sets `closed`."
- `docs/source/registry.py`:
  - BR-004: append "…; the only exception is the year-end closing entry posted by the FY-close service, which may be dated the last day of a locked FY."
  - BR-034: replace "… and opens…" wording accordingly.
- `docs/11-Deployment-and-Operations.md` §6 scheduler table: add "Open next financial year (FY row, periods, sequences) · daily check, 30 days before FY end".
- `docs/source/tests.py`: add TC-ACC-025 "Post an invoice dated 1 Jul before FY 2025-26 is closed · succeeds in FY 2026-27".

### R-06 · System journal entries have no voucher-number series
**Problem.** `journal_entries.voucher_number` is NOT NULL and UNIQUE per company. Documents with their own number (INV, BILL, RV…) can reuse it for their primary entry. But **cancellation entries, reversals of system entries, opening balances, LC charges, LC payments, landed-cost allocations, stock-transfer entries, cash-count adjustments and the year-end entry** have no defined series in 04 §11.2. An invoice's cancellation entry cannot reuse the INV number, because of the UNIQUE constraint.

**Correction.**
- `docs/source/db_outro.md` §11.2 sequences table: add the row `| system_journal | SJ-{FY}- | SJ-2026-0001 | FY |`.
- `docs/06-Accounting-and-Business-Rules.md` §4: add "Voucher numbers: a document's primary entry uses the document number. Every other system entry (cancellation, reversal of a system entry, opening_balance, lc_charge, lc_payment, landed_cost, stock_transfer, cash_count, year_end_close) takes the next `SJ` number. Manual reversals take the next `JV` number."

## P2 · High

### R-07 · Six sidebar list screens are missing
**Problem.** The design sidebar has Sales › *Quotations*, *Payments received*, *Returns and credit notes* and Purchases › *Purchase orders*, *Payments made*, *Returns and debit notes*. In the prototype each of these opens a **form** (E10, E7, E8 / F8, F5, F6); there is no list to find an existing quotation, receipt or note. The API also has no list for receipts, supplier payments, credit notes or debit notes. (`GET /vouchers` needs `accounting.view`, which Sales staff don't have.)

**Correction.**
- `docs/source/pages.py`: add pages, all on the List template:
  - E13 Quotation list (`sales.view`)
  - E14 Payments received (`sales.view`)
  - E15 Returns and credit notes (`sales.view`)
  - F11 Purchase orders (`purchases.view`)
  - F12 Payments made (`purchases.view`)
  - F13 Returns and debit notes (`purchases.view`)
  - Mark each one `note="GAP-24: list screen not in design"`.
- `docs/source/api.py`: add these, all with `params=LISTQ+DATEQ+["party_id"]`:
  - `GET /customer-receipts` (`sales.view`, tables journal_entries, payment_details, bill_allocations, `resp="Page<Voucher>"`)
  - `GET /supplier-payments` (`purchases.view`)
  - `GET /credit-notes` (`sales.view`, `resp="Page<Note>"`)
  - `GET /debit-notes` (`purchases.view`)
  - Add E13/F11 to the pages of `GET /quotations` and `GET /purchase-orders`, and E15/F13 to `GET /sales-returns` and `GET /purchase-returns`.
- `docs/00-Discovery-and-Decisions.md` §5: add the row "GAP-24 · Missing screens · sidebar list pages for quotations, receipts, returns/notes, POs, payments · Add List-template screens E13–E15, F11–F13".
- `docs/08-Frontend-Backend-Integration.md` §3: add routes `/sales/quotations`, `/sales/payments`, `/sales/returns`, `/purchases/orders`, `/purchases/payments`, `/purchases/returns`.

### R-08 · No endpoint to apply an advance later, or to undo an allocation
**Problem.** 06 §6 says "the unallocated remainder … can be allocated later from E7/B4". `payment_details.unapplied_amount` exists, and credit notes with usage `credit` stay open. But the API has no allocation endpoint, so advances and credits can never be applied to invoices or bills, and a wrong allocation cannot be undone.

**Correction.**
- `docs/source/api.py`:
  - Add `POST /allocations` (party-type permission `sales.create` or `purchases.create`; req `AllocationCreate {journal_line_id!, document_id!, amount!}`; tables bill_allocations, sales_invoices, purchase_bills, journal_lines, payment_details; rules BR-020, BR-021; `tx=TXP`; `idem=True`; pages B4, C4, E5, F4).
  - Add `DELETE /allocations/{id}` (sets `reversed_at`, restores balances; rules BR-020).
- `docs/source/schemas.py`: add `AllocationCreate`.
- `docs/source/pages.py`: B4/C4/E5/F4 actions gain "Apply advance or credit" and "Remove allocation".
- `docs/source/tests.py`: add TC-SAL-019 "Apply a ৳ 25,300 advance to a later invoice, then remove it · balances and payment status follow".

### R-09 · Context-dependent permissions declared as one static permission
**Problem.** Some endpoints need a different permission depending on the request:
- `POST /vouchers` declares `accounting.create`, but its description says banking pages call it with `banking.create`.
- `POST /approvals/{id}/approve|reject` declares `accounting.approve`, but the real check is the request's `required_permission` (e.g. `purchases.approve`).
- `GET /audit-logs` and `/attachments` depend on the target record's module.

TC-AUTH-008 generates permission tests from `x-permission`, so it would test the wrong permission. A role with `banking.create` but not `accounting.create` would be refused contrary to the design.

**Correction.**
- `docs/source/api.py`: for those endpoints set `perm="auth.self"` and add a new field `perm_map`:
  - vouchers: `{"journal": "accounting.create", "payment|receipt|contra": "banking.create|accounting.create"}`
  - approvals: `{"*": "approval_requests.required_permission"}`
  - audit logs and attachments: `{"*": "<module of auditable_type>.view|edit"}`
- `docs/source/build_docs.py`: emit `x-permission-mode: contextual` and `x-permission-map` in OpenAPI and in the 05 endpoint table.
- `docs/source/tests.py`, TC-AUTH-008: "…generated from `x-permission`, **and from `x-permission-map` for contextual endpoints**".

### R-10 · New custom roles get unlimited approval by default
**Problem.** `roles.payment_approval_limit` and `journal_approval_limit` treat NULL as "no limit", and `RoleInput` makes both nullable. A role created in K11 without typing limits would let its members post payments and journals of any size without approval.

**Correction.**
- `docs/source/schema.py`, `roles`:
  - Note on both columns: "NULL = no limit; allowed only when `is_system = 1` (Owner)".
  - Add check `"is_system = 1 OR (payment_approval_limit IS NOT NULL AND journal_approval_limit IS NOT NULL)"`.
- `docs/source/schemas.py`, `RoleInput`: make both `money!` (required).
- `docs/07-Authentication-and-Authorization.md` §6.2: "New and duplicated roles default both limits to ৳ 0.00 (everything needs approval) until set".
- `docs/source/tests.py`: add TC-AUTH-019 "Create a role without limits · 422; with 0 limits a ৳ 1 payment is submitted for approval".

### R-11 · Backups may expose credentials, and restore erases the audit trail
**Problem.** 11 §8 restores "that company's rows" and 04 §12 exports "one company's rows". Neither excludes credentials or the audit trail, and `GET /backups/{id}/download` is open to `settings.backup` (Finance manager). A company restore that replaces `audit_logs` would violate NFR-021 (append-only).

**Correction.**
- `docs/11-Deployment-and-Operations.md` §8 and `docs/source/db_outro.md` §12:
  - "Company snapshots **exclude** `users` credentials (password hash, 2FA secrets), `user_sessions`, `password_reset_tokens`, `idempotency_keys` and `audit_logs`."
  - "Restore never deletes or replaces `audit_logs`; it appends `restore_requested` and `restore_completed` entries."
  - "Snapshot files are encrypted (AES-256, per-company key in the secret manager)."
- `docs/source/api.py`: `GET /backups/{id}/download` permission becomes `settings.restore` (Owner).

### R-12 · Account lockout enables denial of service
**Problem.** 07 §9 locks the account after 5 failures in 15 minutes, for every source. Anyone who knows the Owner's e-mail can keep the Owner locked out. Also, the 423 response confirms that the account exists.

**Correction.**
- `docs/07-Authentication-and-Authorization.md` §9, lockout row: "Lock keyed per (account, IP /24 or device cookie); after 5 failures from one source that source is blocked for 15 min (doubling). A global account lock only applies after 50 failures per hour, with an e-mail unlock link. Return 401 with `Retry-After` instead of 423, so the response doesn't reveal that the account exists."
- `docs/source/registry.py`, NFR-013: same wording.
- `docs/source/tests.py`, TC-AUTH-003: "…attacker IP is blocked; the real user from another IP can still sign in".

### R-13 · ⚑ Cash-credit and overdraft accounts can't be used
**Problem.** The designed chart has "2301 Sonali Bank cash credit" (a liability). Bangladeshi wholesalers commonly pay suppliers from a cash-credit or overdraft account. BR-031 blocks any payment that takes an account below zero, and `money_accounts.account_type` has no cash-credit type; its ledger is always created under the asset group 1100.

**Correction.**
- `docs/source/schema.py`, `money_accounts`:
  - Add `cash_credit` to `account_type`.
  - Add `c("overdraft_limit", MONEY, null=True, note="Cash-credit/overdraft limit; NULL = none")`.
  - Note: "cash_credit ledgers are created under current liabilities (21)".
- `docs/source/registry.py`, BR-031: "…cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its `overdraft_limit`."
- `docs/source/pages.py`, G4: account type options gain "Cash credit / overdraft", plus a field "Limit | money | cond | cash credit".
- `docs/06-Accounting-and-Business-Rules.md` §13: add a cash-credit note.

### R-14 · Tax recoverability described but not modelled
**Problem.** 06 §8, §12.3 and §14 post input tax to the tax-rule ledger only "when recoverable", and otherwise into cost. `tax_rules` has no field saying which, so the posting rule can't be implemented.

**Correction.**
- `docs/source/schema.py`, `tax_rules`: add `c("is_recoverable", BOOL, default="1", note="Purchases: recoverable input tax goes to the rule's ledger; otherwise it is added to the line cost")`.
- `docs/source/schemas.py`: add `is_recoverable="bool"` to `TaxRuleInput` and `TaxRule`.
- `docs/source/pages.py`, K6 field list: add "Recoverable as input credit | switch | opt | purchases only".
- `docs/06-Accounting-and-Business-Rules.md` §8 debit row: "recoverable input tax (`is_recoverable = 1`): tax rule ledger; non-recoverable: added to the line's inventory or expense debit".

### R-15 · Never-reset sequences tied to a financial year
**Problem.** `number_sequences.fiscal_year_id` is NOT NULL with `UNIQUE(company_id, fiscal_year_id, document_type)`. Customer and supplier codes (`reset_policy = never`) would need a row per FY. A new row restarts at CUS-0001 and duplicates existing party codes, which `UNIQUE(company_id, code)` then rejects.

**Correction.**
- `docs/source/schema.py`, `number_sequences`:
  - `fk("fiscal_year_id", "fiscal_years", null=True)`.
  - Add a generated column `c("fy_scope", ID, note="GENERATED ALWAYS AS (IFNULL(fiscal_year_id, 0)) STORED")`.
  - Unique becomes `[["company_id", "fy_scope", "document_type"]]`.
  - Add check `"(reset_policy = 'never') = (fiscal_year_id IS NULL)"`.
- `docs/source/db_outro.md` §11.2: "never-reset series have no FY".

### R-16 · Negative-stock costing undefined
**Problem.** K3 allows "selling below zero stock". BR-017 then says that when quantity ≤ 0, the incoming unit cost becomes the average. That silently discards the value already issued at the old cost, so the inventory ledger no longer equals `product_costs.total_value`.

**Correction.**
- `docs/source/registry.py`, BR-017: append "When quantity before the receipt is negative, the incoming quantity first covers the shortfall. The difference between the incoming cost and the cost at which the shortfall was issued is posted to COGS (Dr/Cr cogs, Cr/Dr inventory) as a negative-stock true-up, so value and quantity reach zero together."
- `docs/06-Accounting-and-Business-Rules.md` §11.1: add a paragraph with an example, plus "Recommended: keep negative stock off in v1".
- `docs/source/tests.py`: add TC-INV-008 "Sell 5 with 0 on hand at avg 100, then receive 10 @ 120 · true-up ৳ 100 to COGS; value = 5 × 120".

### R-17 · Returns and cancellations at bill cost can make stock value negative
**Problem.** 06 §10 and §15 issue returned or cancelled stock at the **original bill cost**. If the average has since fallen, or most units were sold, `product_costs.total_value` can go below zero while quantity is still positive.

**Correction.**
- `docs/source/registry.py`, BR-057: append "…; if this would make the remaining average negative, the remaining value is used and the difference is posted to the stock-adjustment ledger."
- `docs/06-Accounting-and-Business-Rules.md` §10 and the §15 "Wrong invoice or bill" row: same rule.

## P3 · Medium

### R-18 · `InvitationCreate.branch_ids` has no storage
**Correction:** `docs/source/schema.py`, `user_invitations`: add `c("branch_ids", JSON, null=True, note="Copied to company_user_branches on accept")`. Or remove `branch_ids` from `InvitationCreate` in `schemas.py`.

### R-19 · `CashCountInput.note` has no column
**Correction:** `docs/source/schema.py`, `cash_counts`: add `c("note", V(255), null=True)`; `docs/source/schemas.py`, `CashCount`: add `note="str?"`.

### R-20 · `NoteInput.apply_to_document_id` undefined
**Correction:** `docs/source/schemas.py`, `NoteInput` description: "`apply_to_document_id` (required when usage = apply): the invoice or bill of the same party that the note's party line is allocated to on posting; persisted only as a `bill_allocations` row." Add BR-021 and BR-023 to `POST /credit-notes` and `POST /debit-notes` in `api.py`.

### R-21 · Idempotency on company creation without a company
**Correction:** `docs/source/schema.py`, `idempotency_keys`: make `company_id` nullable (`tenant=False`, explicit `fk("company_id","companies",null=True)`); add generated `scope_company BIGINT AS (IFNULL(company_id,0)) STORED`; unique `(scope_company, user_id, idempotency_key)`.

### R-22 · `approval_requests` scheduled after its first use
**Correction:** `docs/10-Development-Roadmap.md`: move `approval_requests` and `notifications` from the P3 "Migrations" row to P2 (P2 supplier payments need them; customer receipts reference them in `api.py`). Or remove `approval_requests` from `POST /customer-receipts`, since receipts don't need approval under BR-007.

### R-23 · P1 migration list incomplete and out of order
**Correction:** `docs/10-Development-Roadmap.md` P1 "Migrations": add `party_bank_accounts` (C2 supplier payment details), and state "created in the order of 04 §9" (the list currently shows `tax_rules` before `ledgers`, which it depends on).

### R-24 · ⚑ Store keeper role contradictions
**Problem.**
- 01 §3 says the Store keeper "Receives … stock", but goods are received by posting a bill (`purchases.create`), which this role doesn't have.
- The role can post stock adjustments (design K11), yet `StockAdjustment.net_value`, `posting_preview` and `StockMovement.unit_cost`/`value` aren't masked, so BR-052 ("sees no money figures") leaks.

**Correction.**
- `docs/source/api.py`: add `BR-052` to every stock-adjustments, stock-transfers, `/stock-movements` and `/products/{id}/movements` endpoint.
- `docs/source/schemas.py`: mark those value fields "omitted without inventory.view_costs".
- `docs/source/srs.tpl.md` §3: change the Store keeper row to "Counts and transfers stock; goods receipt is recorded by purchasing through the bill" (or ⚑ grant the Store keeper `purchases.create`).

### R-25 · ⚑ Sales staff financial visibility (DEC-13)
**Problem.** `GET /dashboard` (cash position, condensed P&L), the A2 and A7 lenses, and all `/reports/*` financial statements are reachable with `dashboard.view` or `reports.view`, which the Sales staff and Viewer roles hold.

**Correction (if DEC-13 is approved).**
- `docs/source/registry.py`: add special permission `reports.financial` (Owner, Finance manager, Accountant, Viewer).
- `docs/source/api.py`: TB, P&L, BS, cash flow, GL and both aging reports require `reports.financial`. `GET /dashboard` omits `cash_position`, `revenue_vs_expenses` and P&L widgets for users without `reports.financial` or `banking.view` (state this in its `desc`).

### R-26 · Ledger picker vs "Sales staff never see ledgers"
**Correction:** `docs/source/api.py`, `GET /ledgers`: add required param `purpose` (`income_for_product | expense_for_product | purchase_for_supplier | tax | category | any`). Without `accounting.view`, only `purpose` values allowed by the caller's create permission are accepted, and only the matching kinds are returned (id, code, name).

### R-27 · Voucher calculation has no server endpoint
**Problem.** H10–H12 show a "Double entry: built for you" table and H9 a live BalanceStatus. DEC-09 makes the server authoritative, but no voucher preview endpoint exists; 08 §7 hedges with a client-side sum.

**Correction:** `docs/source/api.py`: add `POST /vouchers/calculate` (`accounting.create`, req `VoucherInput`, resp `VoucherPreview {lines, total_debit, total_credit, difference, balanced, warnings}`, pages H9–H12, G6–G9, rules BR-001, BR-002, BR-031). `docs/08-Frontend-Backend-Integration.md` §7: "BalanceStatus comes from `/vouchers/calculate`; the local sum is only a typing indicator".

### R-28 · Cash sale in one transaction has no API
**Correction:** `docs/source/schemas.py`, `PostRequest`: add `receipt="ref:CashReceiptInput?"` (money_account_id, method, amount = total). `docs/source/api.py`, `POST /sales-invoices/{id}/post` desc: "With `receipt`, the invoice and its RV post in one transaction". Or delete the "Cash sale" paragraph in 06 §5.

### R-29 · Reversed entries not covered by the balance CHECK
**Correction:** `docs/source/schema.py`, `journal_entries` check: `"status NOT IN ('posted','reversed') OR total_debit = total_credit"`.

### R-30 · Posted documents lack snapshots
**Problem.** Invoice and bill lines store only `product_id`, and headers only `party_id`. Renaming a product, changing its unit or editing a customer's address changes how old posted invoices reprint.

**Correction:** `docs/source/schema.py`:
- Add `c("product_code", V(30))`, `c("product_name", V(190))`, `c("unit_name", V(30))` to `quotation_lines`, `sales_invoice_lines`, `purchase_order_lines` and `purchase_bill_lines` (filled at save, frozen at post).
- Add `c("party_snapshot", JSON, null=True, note="Name, address, phone, tax no. frozen at posting")` to `sales_invoices`, `purchase_bills`, `credit_notes` and `debit_notes`.

### R-31 · BR-003 vs editing delivery details on posted invoices
**Correction:** `docs/source/registry.py`, BR-003: append "Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may be updated after posting; every change is audited."

### R-32 · LC margin applied unbounded
**Correction:** `docs/source/registry.py`, BR-044: append "`margin_applied` may not exceed the LC's tagged `lc_margin` balance." `docs/source/tests.py`: add TC-LC-013 "Apply more margin than deposited · 422 margin_exceeds_deposit".

### R-33 · International suppliers forced to have a Bangladeshi mobile
**Correction:** `docs/source/schemas.py`, `PartyInput`: `mobile="str:20?"`, with the description "required when country_code = BD". `docs/source/registry.py`, BR-063: "Mobile numbers are required for parties in Bangladesh and have 11 digits starting 01…".

### R-34 · Recurring-entries screen missing
**Correction:** `docs/source/pages.py`: add page `I10 Recurring entries (NEW)` (List; perm `banking.view`; actions Pause, Edit, End; note GAP-25). Move `GET/PATCH /recurring-templates` pages to I10. Add GAP-25 to 00 §5.

## P4 · Low

### R-35 · Landing route for roles without dashboard
**Correction:** `docs/08-Frontend-Backend-Integration.md` §3: replace "`/` → `/dashboard`" with "`/` → first permitted of dashboard, sales, purchases, inventory…; Store keeper lands on `/inventory`".

### R-36 · Refresh and logout security declarations
**Correction:**
- `docs/source/build_docs.py`: give `/auth/refresh` and `/auth/logout` `security: [{refreshCookie: []}]`.
- `docs/source/api.py`: logout `perm="public"` (cookie-authenticated, so it works with an expired access token).
- `docs/07-Authentication-and-Authorization.md` §2: "Logout works with only the refresh cookie".

### R-37 · Unrealised FX on open foreign payables
**Correction:** `docs/06-Accounting-and-Business-Rules.md` §12.5: add "v1 does not revalue open foreign-currency payables at period end; differences are recognised on settlement. ⚑ The accountant to confirm whether month-end revaluation (IAS 21 / BFRS) is required."

### R-38 · Two-factor challenge storage and cookie same-site requirement
**Correction:** `docs/07-Authentication-and-Authorization.md`:
- §5: "The challenge token is a random id stored in Redis (`2fa:{id}` → user, attempts; TTL 5 min)."
- §3.2: "SameSite=Strict requires the SPA and API on the same registrable domain (e.g. `app.` and `api.ledgerflow.example`)."

### R-39 · Database documentation polish
**Correction:**
- `docs/source/db_intro.md` §4.7: add `stock_movements.source_type/source_id` to the polymorphic list (navigation only; integrity via `journal_entry_id` and the document's FK).
- `docs/source/schema.py`, `password_reset_tokens`: remove `append_only=True` (`used_at` is updated).

### R-40 · Small reference errors
**Correction:**
- `docs/source/tests.py`, TC-SAL-002: "…as in the 05 §5.2 example" (not 06 §5).
- `docs/source/schemas.py`, `LcPayment`: add `payment_kind="str!"`; `LetterOfCreditInput.document_template_id` description: "used on create only to copy checklist items; not stored".

## What was checked and found consistent
FK targets, cardinalities and migration order; ER diagrams vs schema (268 relationships); every page has APIs; page vs endpoint read permissions (200 pairs); every FR covered; every BR tested; mapping keys 06 ↔ 04; all links and anchors; all 24 Mermaid diagrams; the ORM decision is reflected everywhere; worked-example arithmetic in 05 and 06.

## Suggested order of correction
1. **Decide** R-02 (cost model), R-13 (cash credit), R-24 (Store keeper), R-25 (DEC-13).
2. **Apply** P1 items R-01, R-03 to R-06 and P2 items R-07 to R-17, mostly in `docs/source/*`, then regenerate.
3. **Apply** P3 and P4 items.
4. **Re-run** `build_db.py`, `build_docs.py`, `check_consistency.py` and `check_mermaid.py`, and update `12-Validation-Report.md`.
