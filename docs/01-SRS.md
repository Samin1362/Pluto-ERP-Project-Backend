# 01 · Software Requirements Specification (SRS)

> LedgerFlow ERP · version 0.9 (draft for approval) · 18 Oct 2026
> The requirement tables (§5, §6, §8), role matrix (§4.2) and traceability matrices (§16) are generated from `docs/source/registry.py` and `tests.py`; every ID is checked by the build.

## 1. Introduction

### 1.1 Purpose
This SRS defines what LedgerFlow ERP must do, for the developers, testers and the product owner. It is derived from the approved UI design (see [02-UI-Inventory](02-UI-Inventory.md)) and the discovery findings ([00](00-Discovery-and-Decisions.md)).

### 1.2 Product scope
LedgerFlow is a multi-company, web-based ERP for Bangladeshi businesses that buy and sell physical goods (wholesalers, distributors, retailers) and for service firms. It combines:
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

| Module | Owner | Finance manager | Accountant | Sales staff | Store keeper | Viewer |
|---|---|---|---|---|---|---|
| dashboard | VCEAD | V | V | V | — | V |
| sales | VCEAD | VCEA | VCE | VCE | — | V |
| purchases | VCEAD | VCEA | VCE | — | V | V |
| parties | VCEAD | VCE | VCE | VC | — | V |
| inventory | VCEAD | VA | V | V | VCE | V |
| banking | VCEAD | VCEA | VCE | — | — | V |
| lc | VCEAD | VCEA | VCE | — | — | V |
| accounting | VCEAD | VCEA | VCEA | — | — | V |
| reports | VCEAD | VC | VC | V | — | V |
| settings | VCEAD | VE | — | — | — | — |
| `inventory.view_costs` | ✓ | ✓ | ✓ | ✓ | — | ✓ |
| `reports.export` | ✓ | ✓ | ✓ | — | — | — |
| `accounting.lock_period` | ✓ | ✓ | — | — | — | — |
| `accounting.close_year` | ✓ | — | — | — | — | — |
| `banking.reconcile` | ✓ | ✓ | ✓ | — | — | — |
| `settings.users` | ✓ | — | — | — | — | — |
| `settings.roles` | ✓ | — | — | — | — | — |
| `settings.backup` | ✓ | ✓ | — | — | — | — |
| `settings.restore` | ✓ | — | — | — | — | — |
| `auth.self` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

Approval limits (defaults): Finance manager pays up to ৳ 5,00,000 without approval; Accountant pays up to ৳ 50,000 and posts journals up to ৳ 1,00,000; Owner has no limit.

## 5. Functional requirements

| ID | Module | Requirement | Pages | Endpoints |
|---|---|---|---|---|
| FR-001 | Auth | Users sign in with e-mail and password, plus a TOTP code when 2-step verification is on. | S1 | 3 |
| FR-002 | Auth | Users can sign out of the current session, or of every session except the current one. | K14 | 2 |
| FR-003 | Auth | Users can reset a forgotten password by e-mail link (single use, 60 min). | S2 | 2 |
| FR-004 | Auth | Users can enable or disable 2-step verification and regenerate recovery codes. | K14 | 3 |
| FR-005 | Auth | A user belonging to several companies can switch the active company; every screen then shows only that company's data. | S0 | 2 |
| FR-006 | Access | Owners invite users by e-mail with a role; invitations expire after 7 days. | S3, K8, K9 | 6 |
| FR-007 | Access | Owners create, duplicate and edit roles, ticking View/Create/Edit/Approve/Delete per module, and set approval limits. | K11 | 7 |
| FR-008 | Access | Owners deactivate and reactivate users. | K10 | 3 |
| FR-009 | Access | Users list and revoke their own signed-in sessions. | K14 | 2 |
| FR-010 | Dashboard | The dashboard shows KPI tiles, revenue vs expenses, cash position, money owed by age, upcoming payments, recent transactions and inventory alerts for a chosen period. | A1 | 1 |
| FR-011 | Dashboard | Seven dashboard lenses (financial, sales, expenses, receivables, payables, cash and bank, inventory) summarise a module and deep-link into it. | A2, A3, A4, A5, A6, A7, A8 | 1 |
| FR-012 | Search | Global search and the Ctrl K palette find parties, documents, vouchers, ledgers and products, filtered by the user's permissions. | S0, L1, L2 | 1 |
| FR-013 | Notifications | Users receive in-app notifications (approvals, alerts) and can mark them read; preferences choose in-app, e-mail and SMS per event. | S0, K12, L3 | 5 |
| FR-014 | Approvals | Approvers see pending vouchers, and approve or reject them with a comment. | H13, L4 | 4 |
| FR-020 | Parties | Users list, search, filter and sort customers and suppliers, with balance, overdue and status. | B1, C1 | 2 |
| FR-021 | Parties | Users add and edit customers and suppliers (contact, accounting, payment details); a ledger is created automatically. | B2, B3, C2, C3 | 4 |
| FR-022 | Parties | Users view party details: KPIs, open documents, monthly trend, contact, terms and activity. | B4, C4 | 2 |
| FR-023 | Parties | Users view a party's transaction history, ledger with running balance, and outstanding documents by age. | B5, B6, B7, C5, C6, C7 | 3 |
| FR-024 | Parties | Users print, download or e-mail a statement of account with an aging summary. | B8, C8 | 3 |
| FR-025 | Parties | Users make a party inactive; parties without history can be deleted. | B3, C3, L7 | 3 |
| FR-030 | Inventory | Users list products and services with stock status, and filter by type, category, warehouse and stock level. | D1 | 2 |
| FR-031 | Inventory | Users add and edit products (stock-tracked) and services (no stock fields), with pricing, accounts and per-warehouse reorder levels. | D2, D3 | 3 |
| FR-032 | Inventory | Users view product details: stock by warehouse, recent movements and price history. | D4 | 1 |
| FR-033 | Inventory | Users manage product categories, stock groups, units and warehouses. | D5, D6, D7, D8 | 15 |
| FR-034 | Inventory | Users view the stock movement history with filters. | D9 | 1 |
| FR-035 | Inventory | Users post stock adjustments from a count, with the value change posted to the books. | D10 | 8 |
| FR-036 | Inventory | Users dispatch and receive stock transfers between warehouses. | D11 | 8 |
| FR-037 | Inventory | Users view low-stock, stock valuation and inventory summary reports, and create purchase orders from low stock. | D12, D13, D14 | 4 |
| FR-040 | Sales | Users view the sales overview and the invoice list with status tabs, search, date and customer filters. | E1, E2 | 2 |
| FR-041 | Sales | Users create, edit, preview, save as draft and post sales invoices with server-calculated totals. | E3, E4, E6, Sal-DlgPost, Sal-DlgCancel, Sal-DlgDelete, L5, L6 | 5 |
| FR-042 | Sales | Users view invoice details: lines, payments, ledger entries, activity, delivery and attachments. | E5 | 4 |
| FR-043 | Sales | Users record customer receipts and allocate them to open invoices. | E7, E14 | 7 |
| FR-044 | Sales | Users create sales returns and credit notes and choose how the credit is used. | E8, E9, E15 | 14 |
| FR-045 | Sales | Users create quotations, send them and convert accepted ones to invoices. | E10, E13 | 9 |
| FR-046 | Sales | Users cancel posted invoices with a reason and delete drafts. | E6, Sal-DlgPost, Sal-DlgCancel, Sal-DlgDelete | 2 |
| FR-047 | Sales | Users view sales and sales-return reports grouped by customer, category or month. | E11, E12 | 3 |
| FR-050 | Purchases | Users view the purchase overview and bill list. | F1, F2 | 2 |
| FR-051 | Purchases | Users create and post purchase bills that receive stock. | F3, F4 | 7 |
| FR-052 | Purchases | Users record supplier payments and allocate them to bills. | F5, F12, Pur-DlgPay | 6 |
| FR-053 | Purchases | Users create purchase returns and debit notes. | F6, F7, F13 | 14 |
| FR-054 | Purchases | Users create purchase orders and convert them to bills. | F8, F11 | 10 |
| FR-055 | Purchases | Users view purchase and purchase-return reports. | F9, F10 | 3 |
| FR-060 | Banking | Users view the cash and bank overview, cash and bank account details, books and transaction history. | G1, G2, G3, G5, G11, G12, G13 | 5 |
| FR-061 | Banking | Users add bank, wallet and card accounts with opening balance. | G4 | 2 |
| FR-062 | Banking | Users record money received and money paid that are not invoice-related. | G6, G7 | 1 |
| FR-063 | Banking | Users record cash and bank transfers (contra), including bank charges. | G8, G9 | 1 |
| FR-064 | Banking | Users import bank statements and reconcile them in a two-pane workspace. | G10 | 9 |
| FR-065 | Banking | Users record cash counts by denomination. | G2 | 2 |
| FR-066 | IncExp | Users record income and expenses with category, account, method, attachment and optional recurrence. | I3, I6, I10 | 6 |
| FR-067 | IncExp | Users manage income/expense categories with ledgers and monthly budgets. | I7 | 3 |
| FR-068 | IncExp | Users view income and expense overviews, lists and reports by category and month. | I1, I4, I2, I5, I8, I9 | 4 |
| FR-070 | Accounting | Users view the accounting overview with books-health checks. | H1 | 1 |
| FR-071 | Accounting | Users view and maintain the chart of accounts (groups and ledgers). | H2, H3, H4, H5, H6 | 8 |
| FR-072 | Accounting | Users view a ledger with running balance and monthly closing balances. | H7 | 2 |
| FR-073 | Accounting | Users create journal, payment, receipt and contra vouchers with a live balance check, save drafts, submit for approval and post. | H9, H10, H11, H12 | 6 |
| FR-074 | Accounting | Users view voucher details with audit trail, reverse posted vouchers, delete drafts and print vouchers. | H8, H13, Acc-DlgReverse, H14 | 6 |
| FR-075 | Accounting | Users configure the account mappings used by automatic postings. | K16 | 2 |
| FR-076 | Reports | Users view trial balance, profit and loss, balance sheet, cash flow, general ledger, day book, and receivables/payables aging, and export them (xlsx, pdf, csv). | G11, G12, H15, H16, H17, H18, H19, J1, J6, J7 | 9 |
| FR-080 | Settings | Owners view and edit the company profile, logo and locations. | K1, K2 | 7 |
| FR-081 | Settings | Users with settings edit configure preferences: numbering, defaults and controls. | K3 | 5 |
| FR-082 | Settings | Users manage financial years and lock accounting periods; Owners close the year. | K4 | 4 |
| FR-083 | Settings | Users set currency display and regional formats. | K5 | 2 |
| FR-084 | Settings | Users define tax rules and how tax shows on documents. | K6 | 4 |
| FR-085 | Settings | Users customise invoice layout with a live preview. | K7 | 2 |
| FR-086 | Settings | Users set notification preferences, appearance and security settings for themselves. | K12, K13, K14 | 3 |
| FR-087 | Settings | Owners configure backups, download backups, prepare the export pack and restore from a backup. | K15 | 6 |
| FR-090 | LC | Users view the LC dashboard: KPIs, alerts, deadlines, commitments by bank, pipeline, active LCs and recent activity. | M1 | 1 |
| FR-091 | LC | Users list and filter LCs by status, bank, supplier and attention flags. | M2 | 1 |
| FR-092 | LC | Users create an LC in sections (supplier and purchase, terms, banks and dates, documents, shipment, review), save drafts and submit to the bank. | M3 | 4 |
| FR-093 | LC | Users view LC details with overview, terms, documents, shipment, charges and payments, amendments and activity. | M4 | 3 |
| FR-094 | LC | Users record LC amendments with field-level changes and status. | M5 | 3 |
| FR-095 | LC | Users maintain document checklists and templates and update document status, with scans attached. | M6 | 7 |
| FR-096 | LC | Users record shipment lots and milestones inside an LC. | M4 | 2 |
| FR-097 | LC | Users record LC charges and import expenses, and payments at the bank's rate. | M7, LC-DlgPay, LC-DrawerCharge | 7 |
| FR-098 | LC | Users receive LC goods through an import purchase bill and allocate landed cost. | F3, M4, M7 | 5 |
| FR-099 | LC | Users close an LC, after checks, and view the eight LC reports. | M8, LC-DlgClose | 2 |

## 6. Non-functional requirements

| ID | Category | Requirement | Tests |
|---|---|---|---|
| NFR-001 | Performance | List and detail APIs respond within 300 ms (p95), and reports over one FY within 2 s (p95), at 50 concurrent users per company and 1 million journal lines. | TC-NFR-001, TC-NFR-002 |
| NFR-002 | Performance | Posting a 50-line invoice completes within 1 s (p95). | TC-NFR-003 |
| NFR-003 | Performance | Lists are paginated server-side (default 25, maximum 100 rows); no endpoint returns an unbounded collection. | TC-NFR-001 |
| NFR-004 | Performance | First meaningful paint of the SPA within 2.5 s on a 10 Mbps connection; route chunks are lazy-loaded. | Verified by review / operations runbook |
| NFR-005 | Scalability | Stateless API nodes scale horizontally behind a load balancer; sessions are not held in memory. | Verified by review / operations runbook |
| NFR-006 | Scalability | Exports, statement imports, backups, notifications and recurring entries run on queue workers. | Verified by review / operations runbook |
| NFR-010 | Security | All traffic uses TLS 1.2+ with HSTS; cookies are Secure, HttpOnly and SameSite=Strict. | TC-AUTH-001, TC-AUTH-005, TC-AUTH-017, TC-NFR-009 |
| NFR-011 | Security | Passwords are hashed with Argon2id; minimum 10 characters, checked against a breached-password list. | Verified by review / operations runbook |
| NFR-012 | Security | Every endpoint except public auth checks authentication, company membership and a named permission server-side. | TC-AUTH-006, TC-AUTH-007, TC-AUTH-008, TC-FE-003 |
| NFR-013 | Security | Brute-force protection: 5 failed logins per (account, source) in 15 min blocks that source (429, Retry-After); 50 failures per account per hour locks the account with an e-mailed unlock link; responses never reveal whether an account exists or is locked; IP rate limits on auth endpoints. | TC-AUTH-002, TC-AUTH-003, TC-AUTH-018 |
| NFR-014 | Security | Secrets live only in server environment or a secret manager, never in the frontend bundle or the repository. | TC-AUTH-017, TC-NFR-010 |
| NFR-015 | Security | OWASP ASVS L2 controls: input validation, output encoding, CSRF protection for cookie endpoints, security headers (CSP, X-Frame-Options). | TC-NFR-009 |
| NFR-016 | Security | Uploaded files are stored privately, served only through signed short-lived URLs, and type-checked by content. | TC-BNK-009 |
| NFR-020 | Audit | Every create, update, post, approve, reject, reverse, cancel, delete, login and permission change is recorded in audit_logs with user, time, IP, request id, and old and new values. | TC-NFR-004 |
| NFR-021 | Audit | Audit logs are append-only and retained for at least 8 years. | TC-NFR-004 |
| NFR-022 | Traceability | Every journal entry links to its source document, and every stock movement to its source document and journal entry. | TC-INV-001 |
| NFR-030 | Recovery | RPO <= 15 minutes, using binary-log point-in-time recovery. | TC-CON-006, TC-NFR-005 |
| NFR-031 | Recovery | RTO <= 4 hours for a full restore; restore is rehearsed monthly. | TC-NFR-005 |
| NFR-032 | Recovery | Daily logical per-company backups are kept per the retention setting (30/90/365 days). | TC-NFR-006 |
| NFR-040 | Reliability | Financial operations are atomic: a document, its stock movements and its journal commit or roll back together. | TC-CON-001, TC-CON-002, TC-CON-004, TC-CON-005 |
| NFR-041 | Reliability | Monthly availability 99.5% during business hours (08:00–22:00 Asia/Dhaka). | Verified by review / operations runbook |
| NFR-042 | Reliability | Nightly integrity jobs verify trial balance, stock and settlement caches, and alert on any difference. | TC-ACC-005, TC-INV-001, TC-NFR-007 |
| NFR-050 | Usability | The UI follows the LedgerFlow design system exactly (tokens, components, layouts), in light and dark themes. | TC-FE-004 |
| NFR-051 | Usability | WCAG 2.2 AA: keyboard operable, visible focus, 4.5:1 contrast, labelled inputs, polite live regions for toasts. | TC-FE-001, TC-FE-006 |
| NFR-052 | Usability | Responsive breakpoints per design: >=1440, >=1200, 900–1199 icon rail, <900 drawer, <640 stacked rows. | TC-FE-007 |
| NFR-053 | Usability | Amounts are shown in lakh/crore grouping with the ৳ symbol per company settings; negative amounts in parentheses. | TC-FE-004 |
| NFR-054 | Usability | Keyboard shortcuts per design (Ctrl K, Alt S/P/R/Y/J/C, Ctrl S, Ctrl Enter). | TC-FE-005 |
| NFR-060 | Maintainability | Backend layered into controllers, form requests, policies, services, models; controllers contain no business logic. | TC-DB-005 |
| NFR-061 | Maintainability | Backend coverage >= 85% lines on the Domain layer, and 100% of posting rules have balance tests. | TC-ACC-012 |
| NFR-062 | Maintainability | OpenAPI spec is the contract; CI fails if the implemented routes and the spec diverge. | TC-NFR-008 |
| NFR-070 | Compliance | Bangladesh tax and regulatory items listed in 06 section 14 are confirmed with a qualified adviser before production use. | Verified by review / operations runbook |
| NFR-071 | Compliance | Financial records are retained for at least 8 years and are exportable. | Verified by review / operations runbook |
| NFR-080 | Localisation | Dates in the company time zone (Asia/Dhaka); printed documents in English, Bangla or both. | Verified by review / operations runbook |

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

| ID | Rule | Enforced by (endpoints) | Tests |
|---|---|---|---|
| BR-001 | Every posted journal entry balances: total debit = total credit > 0. | 30 | TC-ACC-001, TC-ACC-005, TC-ACC-012, TC-DB-003 |
| BR-002 | Each journal line carries a debit or a credit greater than zero, never both and never neither. | 2 | TC-ACC-002, TC-ACC-012 |
| BR-003 | Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited. | 14 | TC-ACC-003, TC-ACC-007, TC-ACC-008, TC-SAL-018, TC-PUR-011, TC-INV-005, TC-BNK-010, TC-LC-012 |
| BR-004 | A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected. | 34 | TC-ACC-004 |
| BR-005 | Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports. | 7 | TC-ACC-006 |
| BR-006 | Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. | 20 | TC-ACC-023, TC-SAL-013, TC-DB-002 |
| BR-007 | A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts. | 7 | TC-AUTH-019, TC-ACC-009, TC-PUR-006 |
| BR-008 | Nobody approves a voucher they submitted (segregation of duties). | 2 | TC-ACC-010 |
| BR-009 | A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. | 22 | TC-ACC-007, TC-SAL-005, TC-SAL-016, TC-SAL-021, TC-CON-003 |
| BR-010 | Money uses decimal arithmetic only. Line amounts are rounded half-up to 2 decimals; document totals are the sum of rounded lines; the server's calculation is authoritative. | 4 | TC-ACC-019, TC-SAL-002, TC-DB-006, TC-FE-002 |
| BR-011 | Line gross = quantity x unit price; discount = gross x discount% ; tax = (gross - discount) x tax rate; line total = gross - discount + tax. | 4 | TC-ACC-020, TC-SAL-002 |
| BR-012 | Invoice rounding (company setting: none, nearest 1, nearest 5 taka) is posted to the mapped rounding ledger. | 2 | TC-ACC-021 |
| BR-013 | A sales invoice needs an active customer, a warehouse, at least one line with quantity > 0 and price >= 0, and a due date on or after the invoice date. | 3 | TC-SAL-001 |
| BR-014 | Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock. | 7 | TC-SAL-003, TC-SAL-020, TC-PUR-010, TC-CON-001 |
| BR-015 | Exceeding a customer's credit limit or invoicing a customer with overdue invoices shows a warning but does not block. | 1 | TC-SAL-002, TC-SAL-006, TC-SAL-020 |
| BR-016 | Cost of goods sold is quantity x the product's moving weighted average cost in the issuing warehouse at posting; the unit cost is stored on the invoice line. | 2 | TC-SAL-004, TC-INV-007 |
| BR-017 | On every stock-in to a warehouse, that warehouse's average cost = (warehouse value + incoming value) / (warehouse quantity + incoming quantity); when current quantity <= 0 the incoming unit cost becomes the average. | 5 | TC-PUR-003, TC-PUR-004 |
| BR-018 | A purchase bill requires the supplier's bill number, unique per supplier within the company. | 3 | TC-PUR-001 |
| BR-019 | Posting a bill increases stock in the receiving warehouse and debits that warehouse's inventory ledger for stocked lines, or the line's expense ledger for non-stock lines; the supplier is credited. | 1 | TC-PUR-002, TC-PUR-005 |
| BR-020 | Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger. | 9 | TC-SAL-007, TC-SAL-008, TC-SAL-009, TC-SAL-018, TC-SAL-019, TC-SAL-021, TC-CON-002, TC-DB-004, TC-DB-007 |
| BR-021 | Allocations, notes and returns may reference only documents of the same party and company. | 5 | TC-SAL-008, TC-SAL-019 |
| BR-022 | Returned quantity per line <= original quantity - quantity already returned. | 5 | TC-SAL-010, TC-PUR-008 |
| BR-023 | A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note). | 5 | TC-SAL-011 |
| BR-024 | Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances. | 4 | TC-SAL-012, TC-PUR-010 |
| BR-025 | Only drafts can be deleted; a posted document can only be cancelled or reversed. | 17 | TC-SAL-013 |
| BR-026 | A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. | 9 | TC-INV-006, TC-DB-001 |
| BR-027 | A ledger's code and opening balance are locked once it has entries; the group may change only within the same nature. | 3 | TC-ACC-013 |
| BR-028 | Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document. | 5 | TC-ACC-014 |
| BR-029 | A stock adjustment posts the value of the difference: a shortage debits the stock-adjustment ledger and credits inventory; a gain does the opposite. | 2 | TC-INV-002, TC-INV-003 |
| BR-030 | A stock transfer requires different warehouses and quantity <= available at the source; total value is unchanged; a journal is posted only if the warehouses use different inventory ledgers. | 3 | TC-INV-004, TC-DB-007 |
| BR-031 | A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set). | 8 | TC-PUR-007, TC-BNK-002 |
| BR-032 | A reconciliation finishes only when every statement line is matched, created, paired or ignored and the difference is zero. | 1 | TC-BNK-004, TC-BNK-005 |
| BR-033 | Periods lock in date order by users with accounting.lock_period; unlocking needs the same permission, a reason, and is audited. | 2 | TC-ACC-015 |
| BR-034 | A financial year closes only after its end date with every period locked; closing posts a year-end entry moving net profit to retained earnings. | 1 | TC-ACC-016 |
| BR-035 | The base currency cannot change once any journal entry exists. | 1 | TC-ACC-017 |
| BR-036 | No tax rate is assumed: tax is calculated only from user-defined tax rules attached to a line, and posts to the rule's ledger. | 3 | TC-ACC-022 |
| BR-037 | Every read and write is scoped to the active company; any reference to another company's record is rejected as not found. | 3 | TC-AUTH-007, TC-AUTH-009, TC-AUTH-021, TC-DB-002 |
| BR-038 | When a membership has branch restrictions, the user can only see and create records of those branches. | 3 | TC-AUTH-015 |
| BR-039 | Submitting an LC to the bank requires supplier, amount > 0, issuing bank and expiry date; marking it opened requires the bank reference and opening date. | 1 | TC-LC-001 |
| BR-040 | LC status follows the state machine in 06 section 12.1; closed and cancelled LCs are read-only. | 3 | TC-LC-002 |
| BR-041 | LC amendment changes apply to the LC only when the amendment is accepted; each accepted change is recorded with old and new values. | 3 | TC-LC-003 |
| BR-042 | LC payment taka amount = foreign amount x bank rate (rounded to 2 dp); total paid may not exceed LC amount x (1 + tolerance). | 1 | TC-LC-004, TC-LC-005, TC-LC-011 |
| BR-043 | Differences between the rate at which a foreign bill was booked and the rate at which it is paid post to the exchange gain or loss ledger. | 2 | TC-LC-006, TC-LC-011 |
| BR-044 | An LC margin deposit is a refundable asset, never part of landed cost; it is applied to the payment or released at closure. The margin applied to payments may not exceed the margin deposited for that LC. | 3 | TC-LC-004, TC-LC-012, TC-LC-013, TC-LC-007 |
| BR-045 | LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold. | 5 | TC-LC-008 |
| BR-046 | An LC can be closed only when the supplier is fully paid (or the LC expired), no charges are unpaid and no documents are missing or discrepant, or with an explicit override reason. | 1 | TC-LC-009 |
| BR-047 | Customs duties and taxes are entered as assessed; LedgerFlow does not calculate them. | 1 | TC-LC-010 |
| BR-048 | Recurring templates create drafts only; nothing posts automatically. | 2 | TC-BNK-008 |
| BR-049 | Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. | 10 | TC-AUTH-020, TC-SAL-017 |
| BR-050 | Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects. | 0 | TC-SAL-005, TC-CON-003 |
| BR-051 | Attachments must be JPG, PNG or PDF up to 10 MB, verified by content type sniffing. | 2 | TC-BNK-009 |
| BR-052 | Users without inventory.view_costs never receive cost, value or margin fields from the API. | 16 | TC-AUTH-010 |
| BR-053 | A voucher dated more than the configured number of days in the past requires explicit confirmation. | 5 | TC-ACC-018 |
| BR-054 | An expense or income entry needs amount > 0, a category and a money account; it posts the category ledger against the money account. | 1 | TC-BNK-007 |
| BR-055 | Due date defaults to document date + the party's payment-term days and may be edited. | 2 | TC-SAL-015 |
| BR-056 | Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities. | 5 | TC-SAL-014, TC-PUR-009 |
| BR-057 | A purchase return credits inventory at the original bill cost of the returned lines; the supplier is debited through the debit note. | 2 | TC-PUR-008, TC-PUR-011, TC-PUR-012 |
| BR-058 | A cash-count difference posts to the cash short/over ledger and requires approval. | 2 | TC-BNK-006, TC-BNK-010 |
| BR-059 | Active plus invited users cannot exceed the company's seat limit. | 3 | TC-AUTH-014 |
| BR-060 | The Owner role always has every permission and a company always keeps at least one active Owner. | 3 | TC-AUTH-013, TC-AUTH-019 |
| BR-061 | Deactivating a user or changing their password revokes all their sessions immediately. | 4 | TC-AUTH-011, TC-AUTH-012 |
| BR-062 | Restore is Owner-only, requires typing RESTORE, and always takes a pre-restore snapshot first. | 1 | TC-AUTH-023, TC-NFR-006 |
| BR-063 | Local party mobile numbers have 11 digits starting 01 (01XXX-XXXXXX); e-mail addresses must be valid when given. | 2 | TC-NFR-011 |
| BR-064 | Posting a sales invoice creates: Dr customer (total), Cr each line's income ledger (net), Cr tax ledgers (tax), Cr/Dr rounding; and for stocked lines Dr COGS, Cr warehouse inventory (cost). | 2 | TC-ACC-024, TC-SAL-004 |
| BR-065 | A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger. | 5 | TC-SAL-007, TC-BNK-001 |

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


#### WF-1 Purchase to inventory to accounting

Supplier bill received into a warehouse, stock and average cost updated, payable posted, then paid.

| Step | UI page | User action | API endpoint | Backend service | Tables | Business rules | Tests |
|---|---|---|---|---|---|---|---|
| 1 | F3 | Enter the supplier's bill; totals refresh | `POST /purchase-bills/calculate` | `DocumentCalculator` | products, tax_rules | BR-010, BR-011 | TC-ACC-020 |
| 2 | F3 | Save bill (draft) | `POST /purchase-bills` | `PurchaseBillService::createDraft` | purchase_bills, purchase_bill_lines, number_sequences | BR-018, BR-006 | TC-PUR-001, TC-ACC-023 |
| 3 | F3 | Post bill (confirm dialog) | `POST /purchase-bills/{id}/post` | `PurchaseBillPostingService` | stock_movements, stock_balances, journal_entries, journal_lines | BR-019, BR-017, BR-001, BR-004, BR-009 | TC-PUR-002, TC-PUR-003, TC-SAL-005 |
| 4 | F5 | Record supplier payment and allocate | `POST /supplier-payments` | `PartyPaymentService` | journal_entries, journal_lines, payment_details, bill_allocations | BR-020, BR-031, BR-007 | TC-PUR-006, TC-PUR-007 |
| 5 | L4 | Approver approves the payment | `POST /approvals/{id}/approve` | `ApprovalService` | approval_requests, journal_entries | BR-007, BR-008 | TC-ACC-010, TC-ACC-011 |
| 6 | G12 | Bank book shows the payment | `GET /money-accounts/{id}/book` | `LedgerQuery` | journal_lines | BR-005 | TC-E2E-002 |

#### WF-2 Sales to payment to accounting

Invoice posted from stock, receivable recognised with COGS, customer pays, receipt allocated.

| Step | UI page | User action | API endpoint | Backend service | Tables | Business rules | Tests |
|---|---|---|---|---|---|---|---|
| 1 | E3 | Pick customer and items; totals and warnings refresh | `POST /sales-invoices/calculate` | `DocumentCalculator` | products, parties, stock_balances | BR-010, BR-011, BR-015 | TC-SAL-002 |
| 2 | E3 | Save invoice | `POST /sales-invoices` | `SalesInvoiceService::createDraft` | sales_invoices, sales_invoice_lines, number_sequences | BR-013, BR-006 | TC-SAL-001 |
| 3 | Sal-DlgPost | Post invoice | `POST /sales-invoices/{id}/post` | `SalesInvoicePostingService` | stock_movements, stock_balances, journal_entries, journal_lines | BR-014, BR-016, BR-064, BR-001, BR-009 | TC-SAL-003, TC-SAL-004, TC-SAL-005, TC-CON-001 |
| 4 | E7 | Record customer payment; allocate oldest first | `POST /customer-receipts` | `PartyPaymentService` | journal_entries, journal_lines, payment_details, bill_allocations, sales_invoices | BR-020, BR-021, BR-065 | TC-SAL-007, TC-SAL-008, TC-CON-002 |
| 5 | B6 | Customer ledger shows invoice and receipt | `GET /parties/{id}/ledger` | `LedgerQuery` | journal_lines, journal_entries | BR-005 | TC-E2E-001 |
| 6 | Sal-DlgCancel | Cancel invoice (correction path) | `POST /sales-invoices/{id}/cancel` | `SalesInvoiceCancellationService` | journal_entries, journal_lines, stock_movements, bill_allocations | BR-024, BR-003 | TC-SAL-012 |

#### WF-3 Import and LC

Import PO financed by an LC: charges and margin, shipment, payment at bank rate, import bill, landed cost, closure.

| Step | UI page | User action | API endpoint | Backend service | Tables | Business rules | Tests |
|---|---|---|---|---|---|---|---|
| 1 | F8 | Create import PO in USD | `POST /purchase-orders` | `PurchaseOrderService` | purchase_orders, purchase_order_lines | BR-056 | TC-PUR-009 |
| 2 | M3 | Create LC linked to PO; submit to bank | `POST /letters-of-credit` | `LetterOfCreditService::create` | letters_of_credit, lc_documents, number_sequences | BR-039, BR-006 | TC-LC-001 |
| 3 | M4 | Mark opened with bank reference | `POST /letters-of-credit/{id}/transition` | `LcStateMachine` | letters_of_credit, audit_logs | BR-040, BR-039 | TC-LC-002 |
| 4 | LC-DrawerCharge | Add margin deposit and opening commission | `POST /lc-charges` | `LcChargeService` | lc_charges, journal_entries, journal_lines | BR-044, BR-045 | TC-LC-007 |
| 5 | M5 | Record and accept amendment | `PATCH /lc-amendments/{id}` | `LcAmendmentService` | lc_amendments, lc_amendment_changes, letters_of_credit | BR-041 | TC-LC-003 |
| 6 | LC-DlgPay | Record payment at bank rate | `POST /letters-of-credit/{id}/payments` | `LcPaymentService` | lc_payments, journal_entries, journal_lines | BR-042, BR-043, BR-044 | TC-LC-004, TC-LC-005, TC-LC-006 |
| 7 | F3 | Receive goods: import bill linked to LC and lot | `POST /purchase-bills/{id}/post` | `PurchaseBillPostingService` | purchase_bills, stock_movements, journal_entries | BR-019, BR-043 | TC-PUR-002 |
| 8 | M4 | Allocate landed cost | `POST /letters-of-credit/{id}/landed-cost` | `LandedCostService` | landed_cost_allocations, landed_cost_allocation_lines, journal_entries | BR-045, BR-017 | TC-LC-008 |
| 9 | LC-DlgClose | Close LC | `POST /letters-of-credit/{id}/close` | `LcClosureService` | letters_of_credit, journal_entries | BR-046, BR-044 | TC-LC-009 |

#### WF-4 Inventory movement

Counts and transfers keep stock ledger, balances and inventory ledgers in step.

| Step | UI page | User action | API endpoint | Backend service | Tables | Business rules | Tests |
|---|---|---|---|---|---|---|---|
| 1 | D10 | Create count adjustment | `POST /stock-adjustments` | `StockAdjustmentService` | stock_adjustments, stock_adjustment_lines | BR-006 | TC-INV-002 |
| 2 | D10 | Post adjustment | `POST /stock-adjustments/{id}/post` | `StockAdjustmentPostingService` | stock_movements, stock_balances, journal_entries | BR-029, BR-004 | TC-INV-002, TC-INV-003, TC-INV-001 |
| 3 | D11 | Dispatch transfer | `POST /stock-transfers/{id}/dispatch` | `StockTransferService` | stock_transfers, stock_movements, stock_balances | BR-030, BR-014 | TC-INV-004 |
| 4 | D11 | Receive at destination | `POST /stock-transfers/{id}/receive` | `StockTransferService` | stock_movements, stock_balances, journal_entries | BR-030 | TC-INV-004 |
| 5 | D13 | Valuation agrees with TB | `GET /reports/inventory/valuation` | `StockValuationQuery` | stock_movements | BR-016 | TC-INV-007 |

#### WF-5 Accounting posting and month close

Manual vouchers, approval, reconciliation, statements and period lock.

| Step | UI page | User action | API endpoint | Backend service | Tables | Business rules | Tests |
|---|---|---|---|---|---|---|---|
| 1 | H9 | Create and post journal voucher | `POST /vouchers` | `VoucherService` | journal_entries, journal_lines | BR-001, BR-002, BR-004, BR-007 | TC-ACC-001, TC-ACC-004, TC-ACC-009 |
| 2 | H13 | Reverse a wrong voucher | `POST /vouchers/{id}/reverse` | `ReversalService` | journal_entries, journal_lines | BR-003, BR-009 | TC-ACC-007 |
| 3 | G10 | Reconcile bank and finish | `POST /reconciliations/{id}/finish` | `ReconciliationService` | bank_reconciliations, bank_statement_lines | BR-032 | TC-BNK-004 |
| 4 | H15 | Check trial balance | `GET /reports/trial-balance` | `TrialBalanceQuery` | journal_lines, ledgers | BR-001, BR-005 | TC-ACC-005, TC-ACC-006 |
| 5 | K4 | Lock the period | `POST /accounting-periods/{id}/lock` | `PeriodService` | accounting_periods, company_backups | BR-033 | TC-ACC-015 |

#### WF-6 Users, roles and access

Owner sets up access; every request is checked for membership and permission.

| Step | UI page | User action | API endpoint | Backend service | Tables | Business rules | Tests |
|---|---|---|---|---|---|---|---|
| 1 | K11 | Create role with permissions and limits | `POST /roles` | `RoleService` | roles, role_permissions | BR-060, BR-007 | TC-AUTH-013 |
| 2 | K9 | Invite a user | `POST /invitations` | `InvitationService` | user_invitations | BR-059 | TC-AUTH-014 |
| 3 | S3 | Invitee accepts | `POST /invitations/{token}/accept` | `InvitationService::accept` | users, company_users | BR-059 | TC-E2E-005 |
| 4 | S1 | Sign in | `POST /auth/login` | `AuthService` | users, user_sessions | BR-061 | TC-AUTH-001, TC-AUTH-002, TC-AUTH-003 |
| 5 | K10 | Deactivate user | `POST /users/{id}/deactivate` | `MembershipService` | company_users, user_sessions | BR-061, BR-060 | TC-AUTH-011 |

## 17. Requirements that are not yet testable
| Item | Why | Needed to make it testable |
|---|---|---|
| Bangladesh VAT/Mushak outputs | Requirements unknown (RISK-01) | Adviser-confirmed list of required forms and fields |
| NFR-041 availability | Hosting not chosen | Hosting decision and monitoring SLOs |
| NFR-001 at scale | Real data volumes unknown | Owner estimate of vouchers/month and companies |
| Branch isolation strictness | DEC-11 open | Owner decision |
| FIFO valuation | Deferred (DEC-05) | Decision to include |
