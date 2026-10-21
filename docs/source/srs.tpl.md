# 01 · Software Requirements Specification (SRS)

> Pluto ERP · version 0.9 (draft for approval) · 18 Oct 2026
> The requirement tables (§5, §6, §8), role matrix (§4.2) and traceability matrices (§16) are generated from `docs/source/registry.py` and `tests.py`; every ID is checked by the build.

## 1. Introduction

### 1.1 Purpose
This SRS defines what Pluto ERP must do, for the developers, testers and the product owner. It is derived from the approved UI design (see [02-UI-Inventory](02-UI-Inventory.md)) and the discovery findings ([00](00-Discovery-and-Decisions.md)).

### 1.2 Product scope
Pluto is a multi-company, web-based ERP for Bangladeshi businesses that buy and sell physical goods (wholesalers, distributors, retailers) and for service firms. It combines:
- **Operations:** sales (quotations, invoices, returns, credit notes, receipts), purchases (orders, bills, returns, debit notes, payments), customers and suppliers, inventory across warehouses, and import letters of credit (LC) with landed cost.
- **Money:** cash, bank and mobile-wallet accounts, transfers, bank reconciliation, other income and expenses with budgets.
- **Books:** double-entry accounting generated automatically from simple business forms, plus manual vouchers, the chart of accounts, financial statements and reports.
- **Administration:** company setup, users, roles and permissions, approval limits, period locking, tax rules (user-defined), invoice design, security, backup and export.

**Out of scope for v1:** payroll, fixed-asset register (depreciation is a journal template), manufacturing/BOM, POS hardware, e-commerce integration, native mobile apps, customs/shipping-line integrations, statutory e-filing, multi-currency ledgers beyond LC/import documents, batch/expiry tracking, cost centres.

### 1.3 Definitions
| Term | Meaning |
|---|---|
| Ledger | One account in the books (a bank, a customer, an expense) |
| Group | A node of the chart of accounts that ledgers roll up into |
| Voucher / journal entry | A balanced set of debit and credit lines; every posting is one |
| Post | Make a document final: it then affects balances, stock and reports, and becomes immutable |
| Reversal | A new entry with every debit and credit swapped, linked to the original |
| Allocation | Applying a receipt, payment or note to a specific invoice or bill |
| Weighted average cost | Stock cost per unit = total stock value ÷ total quantity, recomputed on every receipt |
| Landed cost | Supplier invoice value plus import charges (bank, insurance, C&F, port, duties, transport), spread over the goods |
| LC | Letter of credit: a bank's undertaking to pay an overseas supplier against documents |
| FY | Financial year; for Bangladesh, 1 July – 30 June |
| Lakh / crore | 1,00,000 / 1,00,00,000 |

## 2. Business objectives
| ID | Objective | Measure |
|---|---|---|
| OBJ-1 | Let non-accountants record business events through simple forms while the books stay correct | 100% of postings balance; no manual journal needed for routine sales, purchases, receipts, payments, returns and stock moves |
| OBJ-2 | Give owners a reliable daily picture of cash, receivables, payables and stock | Dashboard reflects posted data within 60 s |
| OBJ-3 | Reduce month-end close effort | Bank reconciliation plus statements in under 1 day for a business with about 1,000 vouchers per month |
| OBJ-4 | Control risk | Approval limits, period locks, immutable audit trail, role-based access |
| OBJ-5 | Track imports end to end | Every LC's commitments, documents, charges, payments and landed cost visible in one place |

## 3. Stakeholders
| Stakeholder | Interest |
|---|---|
| Business owner / director | Profitability, cash, control; approves big payments; restores backups |
| Finance manager | Runs the books; approves payments and journals; locks periods |
| Accountant | Enters vouchers, reconciles, prepares statements |
| Sales staff | Quotations, invoices, customer payments |
| Store keeper | Receives, counts and transfers stock |
| Auditor / viewer | Read-only access to books and exports |
| Bank (external) | Receives LC applications; source of statements and debit advices |
| Tax adviser (external) | Confirms tax rules (06 §14) |
| Product owner (you) | Approves scope and architecture decisions |
| Development and operations team | Build and run the system |

## 4. User types and roles

### 4.1 User types
- **Signed-in member:** a user with an active membership of the active company, and exactly one role in it.
- **Owner:** the system role with every permission. At least one per company (BR-060).
- **Invited user:** has a pending invitation and no access until accepted.
- **Anonymous:** sees only the sign-in, password-reset and invitation-accept screens.

### 4.2 Role-permission matrix (default templates, editable in K11)
V = view, C = create, E = edit, A = approve, D = delete. Rows in `code` style are special permissions.

<!-- GEN:roles -->

Approval limits (defaults): Finance manager pays up to ৳ 5,00,000 without approval; Accountant pays up to ৳ 50,000 and posts journals up to ৳ 1,00,000; Owner has no limit.

## 5. Functional requirements

<!-- GEN:fr -->

## 6. Non-functional requirements

<!-- GEN:nfr -->

## 7. Assumptions and constraints
**Assumptions:** AS-01 to AS-12 in [00 §4](00-Discovery-and-Decisions.md#4-assumptions-not-explicit-in-the-design-and-what-they-affect). Each one lists its consequence if wrong.

**Constraints:**
| ID | Constraint |
|---|---|
| CON-1 | Frontend React + TypeScript; backend Laravel (PHP 8.3); MySQL 8.0; REST/JSON; JWT; OpenAPI; Git (as requested) |
| CON-2 | ORM: **Laravel Eloquent** (approved 18 Oct 2026; Prisma evaluated and rejected, [00 §6](00-Discovery-and-Decisions.md#6-orm-compatibility-evaluation--decided-eloquent)) |
| CON-3 | The existing UI design and design system are binding |
| CON-4 | Base currency BDT; foreign currency only on LC, import PO and import bill documents |
| CON-5 | No tax rate may be hard-coded or assumed |
| CON-6 | Hosting provider not yet chosen; deployment must stay provider-neutral |

## 8. Business rules

<!-- GEN:br -->

## 9. Use cases

| ID | Use case | Primary actor | Pre-conditions | Main flow | Alternate / exception flows | Post-conditions |
|---|---|---|---|---|---|---|
| UC-01 | Bill a customer | Sales staff | Customer and products exist; open period | Open E3 → choose customer (balance, overdue and limit shown) → add lines → server totals → Preview (E4) → Save invoice → confirm Post | Insufficient stock → line error; overdue/limit → warning; lines invalid → banner; save draft instead | Invoice posted; stock reduced; customer ledger debited; COGS posted |
| UC-02 | Record a customer payment | Sales staff / accountant | Customer has a balance | E7 → amount, account, method → auto-allocate oldest first → adjust → confirm | Amount > open total → excess kept as advance; cheque fields required for cheque | RV posted; invoices paid or part paid |
| UC-03 | Receive a supplier bill | Accountant / store keeper (view) | Supplier and products exist | F3 → supplier, supplier bill no., warehouse, lines → Save bill → Post | Duplicate supplier bill no. → error; from PO → lines prefilled | Stock in; average cost updated; payable posted; PO received qty updated |
| UC-04 | Pay a supplier | Accountant | Open bills | F5 → allocate → Save payment | Above limit → submitted for approval (UC-12); insufficient funds → blocked | PV posted or pending approval |
| UC-05 | Return goods | Sales / accountant | Posted invoice or bill | E8/F6 → quantities and reasons → Save return → E9/F7 choose usage → Post note | Quantity above returnable → blocked; refund → payment | Stock and balances adjusted; note posted |
| UC-06 | Count and adjust stock | Store keeper | Warehouse | D10 → counted quantities → Post adjustment | System quantity changed since draft → recount prompt | Stock corrected; value difference posted |
| UC-07 | Transfer stock | Store keeper | Stock at source | D11 → route and lines → Dispatch → destination Receives | Qty > available → blocked; cancel dispatched → stock back | Quantities moved; value unchanged |
| UC-08 | Reconcile the bank | Accountant | Statement file | G10 → import → accept suggestions → create missing entries → pair → Finish | Difference ≠ 0 → cannot finish; duplicate file → rejected | Reconciled to statement date |
| UC-09 | Enter a journal voucher | Accountant | Open period | H9 → template or lines → balance check → Post | Unbalanced → Post disabled; above limit → submit | Journal posted or pending |
| UC-10 | Close a month | Finance manager | Month reconciled | Review H1 health → TB/P&L/BS → K4 Lock period | Earlier period open → blocked | Period locked; later edits need entries in an open period |
| UC-11 | Manage an import LC | Finance manager | Supplier (international), optional PO | M3 sections → Submit → mark Opened → charges and margin → amendments → shipments → payment at bank rate → import bill → landed cost → Close | Bank refuses → status refused; expiry → expired with unused amount; close with open items → override reason | LC closed; goods costed with landed cost; margin released |
| UC-12 | Approve a voucher | Finance manager / owner | Pending approval request | Notification → L4 drawer → Approve (posts) or Reject with comment | Self-approval → forbidden; period locked meanwhile → error | Posted or rejected; requester notified |
| UC-13 | Set up a company | Owner | Signed in | Create company → K2 details → K4 FY → K6 tax rules (optional) → K7 invoice design → K9 invite → K11 roles | Seat limit reached → blocked | Company ready; COA, roles, sequences seeded |
| UC-14 | Find anything | Any member | — | Ctrl K → type → choose a result | No results → suggestions | Navigated; results limited by permissions |

## 10. Acceptance criteria

Acceptance criteria are written as Given/When/Then and map one-to-one to the test cases in [09](09-Testing-Strategy.md). Here are representative criteria per module; each FR is accepted when every test listed against it in §16 and 09 passes.

| FR | Acceptance criterion |
|---|---|
| FR-001 | **Given** a user with 2FA on, **when** they submit a correct password, **then** no token is issued until a valid TOTP is entered; 5 failures lock the account for 15 min (TC-AUTH-001…004) |
| FR-005 | **Given** membership in two companies, **when** the user switches company, **then** every list, dashboard and report shows only the newly selected company's data, and requests for the other company's IDs return 404 (TC-AUTH-007, 009) |
| FR-021 | **Given** a new customer with mobile 01711-284530 and opening ৳ 1,85,000 Dr, **when** saved, **then** code CUS-NNNN is assigned, a ledger under Sundry debtors exists, and an opening entry is posted (TC-ACC-014, TC-NFR-011) |
| FR-041 | **Given** a draft invoice whose lines exceed stock, **when** posted, **then** posting fails with per-line errors and nothing is written; **given** sufficient stock, **then** journal, stock and balance update atomically, exactly once (TC-SAL-003…005) |
| FR-043 | **Given** two open invoices, **when** a receipt larger than the oldest is saved with auto-allocate, **then** the oldest is fully paid, the next part paid, and any excess shown as an advance (TC-SAL-007…009) |
| FR-051 | **Given** the supplier's bill number was already entered, **when** saved again, **then** it is rejected naming the existing bill (TC-PUR-001) |
| FR-064 | **Given** a statement with unmatched lines, **when** Finish is pressed, **then** it is refused until every line is handled and the difference is ৳ 0.00 (TC-BNK-004) |
| FR-073 | **Given** a journal out of balance by ৳ 120, **then** Post is disabled and the banner shows ৳ 120.00; the API rejects unbalanced entries regardless of the UI (TC-ACC-001, 004) |
| FR-076 | **Given** posted data, **then** the trial balance debits equal credits, P&L net profit equals income − expenses, and the balance sheet balances (TC-ACC-005, TC-INV-007) |
| FR-082 | **Given** September is locked, **when** a voucher dated 28 Sep is posted, **then** it is rejected with the period name (TC-ACC-004, 015) |
| FR-097 | **Given** an LC of US$ 38,400 ± 5%, **when** payments would exceed US$ 40,320, **then** they are rejected (TC-LC-005) |
| FR-098 | **Given** capitalised charges and partly sold goods, **when** landed cost is allocated, **then** the unsold share raises the average cost and the sold share goes to COGS (TC-LC-008) |

## 11. Error handling requirements
| ID | Requirement |
|---|---|
| ERR-1 | Every API error uses the Problem Details format with a stable `code` and `request_id` ([05 §3](05-API-Specification.md#3-error-response-format-rfc-9457-problem-details)) |
| ERR-2 | Validation errors are returned per field (dot notation) and shown inline plus in a summary banner (L8) |
| ERR-3 | Business-rule violations (locked period, insufficient stock or funds, unbalanced entry) are 422 with a plain-language message that says what to do |
| ERR-4 | Conflicts (stale version, already posted) are 409. The UI keeps the user's input and offers reload (L11) |
| ERR-5 | Partial failure is impossible for financial operations (NFR-040); bulk actions report per-item results |
| ERR-6 | Unexpected errors show a generic message with the error reference; details are logged server-side only |
| ERR-7 | Offline: reading stays possible from the screen; saving is paused with a banner; retries reuse the same Idempotency-Key |
| ERR-8 | Widget-level failures on dashboards do not break the page (L11) |

## 12. Security requirements
NFR-010 to NFR-016 above, detailed in [07-Authentication-and-Authorization](07-Authentication-and-Authorization.md): TLS everywhere; short-lived JWT plus rotating HttpOnly refresh cookie; Argon2id; TOTP 2FA; lockout and rate limits; server-side permission checks on every endpoint; company isolation; field-level cost masking; private file storage with signed URLs; no secrets in the frontend; full audit log; OWASP ASVS L2.

## 13. Performance and scalability requirements
NFR-001 to NFR-006. Design levers: covering indexes on `journal_lines` and documents (04 §10); paginated lists; denormalized balance caches with nightly verification; dashboard caching (60 s); queues for heavy work; stateless API nodes; read replica for reports when a company exceeds about 5 million lines.

## 14. Backup and recovery requirements
NFR-030 to NFR-032: nightly physical backup plus binary logs (RPO 15 min), RTO 4 h, monthly restore drill, per-company logical snapshots per retention setting, a pre-restore snapshot always taken, restore Owner-only. Details: [11 §8](11-Deployment-and-Operations.md).

## 15. Audit and traceability requirements
NFR-020 to NFR-022: every financial or security-relevant action is logged append-only with user, time, IP, request id and before/after values. Every journal entry links to its source document; every stock movement links to its document and journal; reversals link both ways; deleted draft numbers are logged as voided; approval decisions are kept with comments. The activity timelines on E5, F4, H13, M4 and K10 read from this log.

## 16. Traceability matrices (UI page → action → API → service → tables → rules → tests)

<!-- GEN:trace -->

## 17. Requirements that are not yet testable
| Item | Why | Needed to make it testable |
|---|---|---|
| Bangladesh VAT/Mushak outputs | Requirements unknown (RISK-01) | Adviser-confirmed list of required forms and fields |
| NFR-041 availability | Hosting not chosen | Hosting decision and monitoring SLOs |
| NFR-001 at scale | Real data volumes unknown | Owner estimate of vouchers/month and companies |
| Branch isolation strictness | DEC-11 open | Owner decision |
| FIFO valuation | Deferred (DEC-05) | Decision to include |
