# 00 · Discovery report and architecture decisions

> Status: **Draft for owner review** · Generated 18 Oct 2026 · Source design: Claude Design canvas "Pluto ERP" (version 1792288896-65b7; the product was formerly LedgerFlow and renamed Pluto on 21 Oct 2026)
>
> Items marked **⚑ APPROVAL** need a decision from the product owner before they are treated as final. Nothing in this package is application code.

---

## 1. What was inspected

| Artifact | Kind | Inspected? | Notes |
|---|---|---|---|
| `project/canvas.json` | Canvas index: 182 artboard entries, 22 row titles | ✅ Fully | Artboard codes and titles (A1–M8, dialogs) |
| `Main.dc.html` (01 · Product architecture) | Sitemap, navigation, templates, journeys, delivery plan | ✅ Fully | Declares 13 modules, 157 entries, 142 distinct screens, 15 reused |
| `DesignSystem.dc.html` (02) + `ds/pluto/tokens.json` + `lf-theme.css` | Design tokens and component gallery | ✅ Fully | "Night Ledger" system: Hanken Grotesk / Newsreader / JetBrains Mono, teal brand, navy shell, light + dark themes |
| `Shell.dc.html` (04) | Sidebar, top bar, menus | ✅ | Company switcher (3 companies), FY selector, quick-create, profile |
| `Dashboard.dc.html` (A1–A8, 03, 05–07) | Prototype module | ✅ | 8 lenses; dark, tablet, phone variants |
| `Parties.dc.html` (B1–B8, C1–C8) | Prototype module | ✅ | Customers and suppliers share one layout |
| `Inventory.dc.html` (D1–D14) | Prototype module | ✅ | |
| `Trade.dc.html` (E1–E12, F1–F10, 4 dialogs) | Prototype module | ✅ | Sales and purchases share one layout |
| `Banking.dc.html` (G1–G13) | Prototype module | ✅ | Includes working reconciliation |
| `IncExp.dc.html` (I1–I9) | Prototype module | ✅ | |
| `Accounting.dc.html` (H1–H14) | Prototype module | ✅ | Vouchers, COA, reverse dialog |
| `Statements.dc.html` (J1, H15–H19, J6, J7) | Prototype module | ✅ | Reports centre and statements |
| `Settings.dc.html` (K1–K15) | Prototype module | ✅ | Roles matrix, periods, tax, backup |
| `States.dc.html` (L1–L15) | Prototype module | ✅ | Search, notifications, approvals drawer, error states |
| `LC.dc.html` (M1–M8, LC dialogs) | Prototype module | ✅ | Largest module (159 KB) |
| `App.dc.html` (08) | Clickable router linking every module | ✅ | |
| ~170 wrapper artboards (e.g. `Pur-Create.dc.html`) | Each one mounts a module with a start view | ✅ Sampled, and listed via the index | Each one only sets `start-view`, `start-tab` or `start-dialog` on a module; they contain no separate content |
| `ds/pluto/components/bundle.js/.css` | Component library code | ⚠️ Not read line by line | Component **names and props** were taken from how the screens use them (36 components). Their internals don't affect the backend. |
| `artifact-type/*` | Files belonging to the design editor | ➖ Not relevant | These belong to the editor, not to your product |

**Limitation:** the screens are interactive prototypes running on sample data ("Prototype with sample data. Nothing is saved"). The business rules below were therefore taken from the screen copy, helper text, banners, posting previews and the logic inside each prototype. That evidence is strong, but it is not a signed-off specification.

## 2. Page inventory summary

The full per-page analysis is in [02-UI-Inventory.md](02-UI-Inventory.md). Totals:

| Module | Code | Screens in design | Prototype file | Template mix |
|---|---|---|---|---|
| Dashboard | A1–A8 | 8 (+3 theme/breakpoint variants) | Dashboard | Overview, Lens |
| Customers | B1–B8 | 8 | Parties | List, Form, Detail, Ledger, Report, Print |
| Suppliers | C1–C8 | 8 | Parties | same as B |
| Products and inventory | D1–D14 | 14 (+ service variant of D2) | Inventory | |
| Sales | E1–E12 | 12 + 3 dialogs | Trade | |
| Purchases | F1–F10 | 10 + 1 dialog | Trade | |
| Cash and banking | G1–G13 | 13 | Banking | |
| Accounting | H1–H19 | 19 (H15–H19 are statements) + reverse dialog + unbalanced state | Accounting / Statements | |
| Income and expenses | I1–I9 | 9 (+ validation state) | IncExp | |
| Reports | J1–J18 | 3 own screens (J1, J6, J7); 15 reuse other modules | Statements | Hub |
| Users and settings | K1–K15 | 15 + 4 dialogs/drawers | Settings | Settings, Matrix |
| Search, alerts, states | L1–L15 | 15 | States | |
| LC Management | M1–M8 | 8 screen types; M4 has 7 tabs and 4 extra state boards; M8 has 8 reports; 3 dialogs/drawers | LC | |

## 3. Confirmed facts (taken directly from the design)

| ID | Fact | Evidence |
|---|---|---|
| CF-01 | The target user is a Bangladeshi wholesaler, retailer or service firm selling physical goods; the base currency is BDT and amounts are grouped in lakh/crore | Main sitemap intro; Settings › Currency |
| CF-02 | Multiple companies per user, chosen with a company switcher; each company has its own FY and data | Shell menu "Your companies", "Add a company" |
| CF-03 | A company has **locations** (branches); reports filter by branch; warehouses hold stock | Settings K1 Locations; report branch filters; D7 |
| CF-04 | Six roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Permissions cover 9 modules × {View, Create, Edit, Approve, Delete}; roles can be created and duplicated | K11 |
| CF-05 | Approval limits per role for payments and journal vouchers; vouchers above the limit wait for an approver | K11, L4 approval drawer |
| CF-06 | Voucher workflow: Draft → Submitted for approval → Approved → Posted; posted vouchers are reversed with a linked reversing entry | H13 audit trail, reverse dialog |
| CF-07 | Accounting periods are monthly and can be locked; a locked period rejects new or edited vouchers; FY close moves profit to retained earnings | K4, L11 |
| CF-08 | Inventory valuation is **weighted average** (a company setting; FIFO is offered as an option) | D2, K3, D13 |
| CF-09 | Stock is tracked per warehouse with per-warehouse reorder level and reorder quantity; services are never stocked | D2, D4 |
| CF-10 | Every customer, supplier, bank, cash account and warehouse inventory has its **own ledger** under a control group | B2 "Ledger created for you", H2 COA |
| CF-11 | Sales invoice posting creates: Dr customer, Cr sales/income (per item), Dr COGS, Cr warehouse inventory, and reduces stock | E5 Ledger entries tab |
| CF-12 | Purchase bill posting creates: Dr warehouse inventory, Cr supplier, increases stock, updates average cost | F3 "When you save", F4 entries |
| CF-13 | Receipts and payments are allocated to open invoices/bills, oldest first; an unapplied excess becomes an advance on the party account | E7, F5 |
| CF-14 | A return creates a credit or debit note, which can reduce an invoice/bill, stay as credit, or be refunded | E8, E9, F6, F7 |
| CF-15 | Posted invoices can't be edited; corrections are made with credit notes; cancelling posts a reversal and returns the stock | Post / Cancel dialogs |
| CF-16 | Drafts never touch the books or stock; reports read posted vouchers only | E6 banner, J1 |
| CF-17 | Quotations and purchase orders don't post; they convert into an invoice or bill | E10, F8 |
| CF-18 | Stock adjustment posts the value difference; stock transfer moves quantity between warehouses (Draft → Dispatched → Received) | D10, D11 |
| CF-19 | Bank reconciliation matches imported statement lines (CSV/Excel) to book entries, can create missing entries, and must reach a difference of 0 to finish | G10 |
| CF-20 | The tax engine assumes **no** rates; users define tax rules (name, rate, applies to, ledger) | K6, P&L note |
| CF-21 | LC: an import LC in foreign currency (USD/EUR/CNY), linked to a PO or an intended import, with tolerance, planning exchange rate, margin deposit, tenor (sight/usance), Incoterm, dates, required-document checklist, optional shipment lots, amendments, charges, payments at the bank's rate, landed cost summary and 8 reports | M1–M8 |
| CF-22 | **Shipment details live inside LC Details, not in a separate module** | Main sitemap note on module M |
| CF-23 | Customs duties and taxes are entered as assessed; Pluto does not calculate them | M7 note |
| CF-24 | Attachments (JPG, PNG, PDF up to 10 MB) on expenses, bank entries and documents | I6, G6 |
| CF-25 | Security: 2-step verification (TOTP), a "require 2-step for everyone" setting, an idle sign-out, and a list of signed-in sessions with sign-out | K14 |
| CF-26 | Daily automatic backups with retention, manual backups, restore (Owner only, type RESTORE to confirm), and a month-end export pack | K15 |
| CF-27 | Optimistic concurrency: "Someone else changed this invoice … your edits are kept in a draft" | L11 |
| CF-28 | A global search and command palette (Ctrl K) whose results respect the user's role | L1, L2 |
| CF-29 | Notifications: in-app, email and SMS per event, plus a daily summary email | K12, L3 |
| CF-30 | Number series per document, restarting each FY (INV-2026-, BILL-2026-, RV-, PV-, JV-, CV-, QT-) | K3 |

## 4. Assumptions (not explicit in the design) and what they affect

| ID | Assumption | Consequence if wrong |
|---|---|---|
| AS-01 | A single shared MySQL database, with `company_id` on every tenant table (row-level tenancy) | Database-per-tenant would change migrations, backup and connection management |
| AS-02 | One party record is **either** a customer **or** a supplier (`party_type`) | If one party can be both, we need a role junction or two flags, plus netting rules |
| AS-03 | ⚑ Revised 18 Oct 2026 (review R-02): weighted average cost is a **moving average per product and warehouse**, because inventory posts to a ledger per warehouse; the design's identical ৳ 845 in both warehouses is consistent with equal purchase costs. Company valuation = sum over warehouses. Owner may revert to a company-wide average with one inventory ledger | A per-warehouse average changes the stock_balances keys and the transfer costing |
| AS-04 | Users are not restricted to branches unless rows are added to `company_user_branches` (empty = all branches) | If strict branch isolation is required everywhere, every list and report query gains a branch filter, and tests grow |
| AS-05 | One selling unit per product, with no unit-of-measure conversion | Purchasing in cartons and selling in pieces would need a units-conversion table |
| AS-06 | No batch, lot or expiry tracking for stock (the design has none) | Expiry-driven write-offs would need batch tables |
| AS-07 | Sales are posted **net of line discounts**, matching the design's journal | Some businesses want "Discount allowed" shown separately (configurable mapping provided) |
| AS-08 | A purchase bill is the goods receipt; there is no separate GRN (matches F3 "Items go into stock when you save") | Receiving before the bill arrives would need a GRN document |
| AS-09 | The LC module is financial and document tracking; customs and shipping-line integrations are out of scope | |
| AS-10 | Hosting provider unknown; the docs describe a provider-neutral deployment | |
| AS-11 | English UI; Bangla is used only on printed documents (K5 "Language on printed documents") | Full Bangla UI would need i18n of every screen |
| AS-12 | "Price list: Wholesale A" is a simple two-tier price (standard / wholesale) per customer | Full price lists would need price_list tables |

## 5. Contradictions, gaps and risks found in the design

| ID | Type | Finding | Recommendation | ⚑ |
|---|---|---|---|---|
| GAP-01 | Missing screen | D10 says "Accounts come from Settings › Inventory", but no such page exists. There is no screen to configure **posting-account mappings** (sales, COGS, inventory, returns, discount, rounding, FX, stock loss, LC clearing) | Add a K-section screen **"Accounting defaults"** (K16) to edit `account_mappings` | ⚑ |
| GAP-02 | Contradiction | Inventory has a ledger per warehouse (1301-01, 1301-02), but D11 says transfers "don't … post to the books" | A transfer posts Dr destination inventory / Cr source inventory **only when the two warehouses use different inventory ledgers**. Total value is unchanged, so the screen copy stays true for the total | ⚑ |
| GAP-03 | Accounting inconsistency | F7 debit note posts Cr "Purchase returns". Under perpetual inventory, returned stock must leave the Inventory account | Purchase return credits **Inventory** at the original bill cost; a "Purchase returns" ledger is used only for non-stock lines | ⚑ |
| GAP-04 | Missing ledgers | Ledgers used in postings are absent from the H2 COA: Purchase returns, Stock shortage and damage, Rounding off, Exchange gain/loss, LC margin deposits, Import goods in transit, Tax payable 2205 / receivable 1402, 5101 Purchases, Suspense 9999, Opening balance adjustment | Seed them in the default chart (see 04 §8) | |
| GAP-05 | Missing logic | LC payments use the bank's rate while the plan uses a planning rate; there is **no foreign-exchange gain/loss** handling, and LC pages say "nothing is posted to the books" | Specify LC accounting fully (06 §12) with an FX gain/loss ledger | ⚑ |
| GAP-06 | Missing screen | Receiving LC goods into stock ("Lot 1 received at Tejgaon, 10 MT") has no screen | Use the **purchase bill form in import mode** (foreign currency, linked LC and lot), plus a "Landed cost allocation" action on the LC Charges tab | ⚑ |
| GAP-07 | Contradiction | H13 offers "Delete voucher" for a posted voucher; K3 has "Allow editing posted vouchers in open periods" | Never allow hard delete or in-place edit of posted vouchers. Remove the K3 toggle and hide Delete for posted vouchers (drafts only) | ⚑ |
| GAP-08 | Inconsistency | K3 numbering lists 7 series, but screens use about 15 (SR, PR, CN, DN, PO, ADJ, TRF, EXP, INC, LC, DC, STM, SRN) | Add all series to `number_sequences` seed and the K3 table | |
| GAP-09 | Inconsistency | E-list toast "Draft deleted · INV-2026-0589 is free to reuse" vs K3 "Numbers never repeat" | Numbers are reserved at first save; numbers of deleted drafts are **voided, not reused**, and the void is logged | ⚑ |
| GAP-10 | Inconsistency | J1 says the sales report groups "by … salesperson", but E11 has no salesperson option, and invoices have no salesperson field | Drop "salesperson" from J1 copy, or add `salesperson_user_id` to invoices | ⚑ |
| GAP-11 | Missing screen | Toast mentions "cheque book 7712xx is marked as used up", but there is no cheque-book screen | Treat as a future feature (cheque register) | |
| GAP-12 | Missing screen | No sales order or delivery challan screens, though challan DC-2026-0412 and vehicle are shown on E5 | Store challan, vehicle and delivery time on the invoice; print the challan from the invoice; no separate module | |
| GAP-13 | Missing screen | No user profile page, login, forgot-password or 2FA challenge screens | Add auth screens (follow the design system) | ⚑ |
| GAP-14 | Missing screen | No screen to manage locations (branches), units, or the LC document templates editor ("Edit templates") | Add simple list/drawer screens in Settings / Inventory | |
| GAP-15 | Ambiguity | The Store keeper role "sees no money figures", which is field-level masking, not a module permission | Add permission `inventory.view_costs`; the API omits cost and value fields without it | ⚑ |
| GAP-16 | Ambiguity | The expense list mixes EXP-numbered entries with PV-numbered payment vouchers | The expense list is a **view of posted lines on expense-category ledgers**, whatever voucher created them | |
| GAP-17 | Ambiguity | Usance LC acceptance liability (bank acceptance) is not designed | Model the acceptance as a liability to the bank until maturity (06 §12.6) | ⚑ |
| GAP-18 | Ambiguity | Cash count (G2) shows a match; there is no flow for differences | A shortage/excess posts to a "Cash short/over" ledger with approval | |
| GAP-19 | Out of scope signal | "Pluto on Android" in sessions | A native mobile app is out of scope; the web app is responsive | |
| GAP-20 | Contradiction | D6 says stock groups decide "where value rolls up in … the balance sheet", but the chart of accounts gives inventory a ledger per **warehouse** | The inventory ledger comes from the **warehouse**; stock groups are a reporting hierarchy for valuation reports only | ⚑ |
| GAP-21 | Missing field | H4 has "Require a cost centre on every entry", but cost centres appear nowhere else | Cost centres are deferred: the switch is hidden in v1 | |
| GAP-22 | Ambiguity | K15 offers company-level backup and restore, but a physical MySQL backup covers every company on the server | Two layers: system physical backups (operations), plus a per-company **logical snapshot** used by the K15 restore (see 11-Deployment-and-Operations) | ⚑ |
| GAP-23 | Missing row | The K11 roles matrix has no rows for LC Management or for Income and expenses | Add an `lc.*` permission row (defaults mirror Purchases); Income and expenses use `banking.*` | ⚑ |
| GAP-24 | Missing screens | The sidebar items Quotations, Payments received, Returns and credit notes (Sales) and Purchase orders, Payments made, Returns and debit notes (Purchases) open a form in the prototype; nothing lists existing records (review R-07) | Add List-template screens E13–E15 and F11–F13 with their list endpoints (05 §12.2) | ⚑ design sign-off |
| GAP-25 | Missing screen | Recurring income, expense and journal templates (I3/I6 "Repeat") have an API but no screen to pause, edit or end them (review R-34) | Add List-template screen I10 Recurring entries | ⚑ design sign-off |
| RISK-01 | Regulatory | Bangladesh VAT (Mushak forms), supplementary duty, AIT/VDS/TDS, and invoice requirements are not specified | Keep tax rules configurable and confirm with an adviser before go-live (06 §14) | ⚑ |
| RISK-02 | Technical | Prisma is requested with a Laravel backend (see §6) | ✅ Resolved: Eloquent approved | |

## 6. ORM compatibility evaluation (✅ decided: Eloquent)

### The facts
- **Prisma ORM** is a TypeScript/JavaScript ORM. Its query engine and generated client run inside a Node.js (or Bun/Deno) process. There is **no PHP client**, and Laravel cannot call Prisma in-process.
- **Laravel** is built around **Eloquent** (models, relationships, global scopes, events, transactions, migrations, factories, policies). Its validation, authorization, queues and testing tools all assume Eloquent or Laravel's query builder.

### Option A: recommended Laravel-native architecture
React + TypeScript (Vite) → REST/JSON → **Laravel 11/12 (PHP 8.3)** with **Eloquent** → MySQL 8.0. JWT through **`php-open-source-saver/jwt-auth`**, a maintained fork of `tymon/jwt-auth` with Laravel 10–12 support. OpenAPI is maintained as a spec-first `openapi.yaml` (optionally checked against code annotations with `zircote/swagger-php` or `dedoc/scramble`).

- One language and runtime on the server, so everything stays in one place: one transaction boundary for "post invoice → stock → journal".
- Laravel migrations own the schema; this ensures the documented foreign keys and indexes are what actually runs.
- DB::transaction plus `lockForUpdate()` give the row locks that stock and number sequences need.
- Eloquent global scopes enforce `company_id` isolation consistently.
- Large hiring pool and documentation; lowest operational cost.

### Option B: keeping Prisma (valid, but more costly)
Prisma can only be kept by adding a **separate Node.js/TypeScript service** that owns some or all data access:

```
React ──REST──▶ Laravel API (auth, validation, policies)
                    │  HTTP/gRPC (internal)
                    ▼
            Node.js "data service" (Prisma Client) ──▶ MySQL
```

| Concern | Impact |
|---|---|
| Languages and services | PHP **and** TypeScript backends, two deployables, two dependency trees, two CI pipelines |
| Transactions | Posting an invoice needs stock, journal and sequence updates in **one** DB transaction. If Laravel validates and Node writes, the business logic has to move into the Node service (so Laravel becomes a thin proxy), or you need distributed transactions/sagas, which are unacceptable for accounting |
| Schema ownership | Prisma Migrate and Laravel migrations can't both own the schema. One must be chosen (Prisma), and Laravel models become read-only mirrors or are dropped |
| Locking | Prisma has no first-class `SELECT … FOR UPDATE`; it needs `$queryRaw` for row locks |
| Latency and failure modes | Every query becomes a network hop; new partial-failure cases (Laravel succeeded, Node timed out) |
| Authorization | Tenant isolation must be enforced in **both** services, or the Node service must trust Laravel completely |
| Team | Requires engineers fluent in both stacks |
| Honest conclusion | If Prisma is mandatory, the cleaner architecture is **Node.js/NestJS + Prisma for the whole backend**, with Laravel removed. Mixing both keeps the cost of two backends while gaining nothing from either |

### Recommendation
**Option A (Eloquent).** It satisfies every functional and consistency requirement with one transactional boundary, which matters most for an accounting system. This whole package is written for Option A. Where Option B would change something, the document says so in a "⚑ Option B note".

> ✅ **Decision (18 Oct 2026, product owner): Option A, Laravel Eloquent, approved.** Prisma is not used. Option B is kept above only as the record of the evaluation.

## 7. Other decisions requiring approval

| ID | Decision | Proposed | Alternatives |
|---|---|---|---|
| DEC-01 | ORM | ✅ **Approved 18 Oct 2026: Eloquent (Option A)** | Prisma via Node service (Option B), rejected |
| DEC-02 | JWT package and token storage | `php-open-source-saver/jwt-auth`; 15-minute access token kept **in memory**; rotating refresh token in an **HttpOnly, Secure, SameSite=Strict cookie** | Laravel Sanctum SPA cookies (not JWT, but simpler and very secure) |
| DEC-03 | Tenancy | One DB, `company_id` on every row, active company via `X-Company-Id` header checked on every request | DB per company |
| DEC-04 | Party model | `parties.party_type` = customer \| supplier | Both flags on one record |
| DEC-05 | Valuation | Moving weighted average per product and warehouse (AS-03 revised); FIFO deferred | FIFO cost layers in v1 |
| DEC-06 | Numbering | Reserve at first save; void deleted draft numbers, never reuse | Assign at posting (no draft numbers) |
| DEC-07 | Posted documents | Immutable; corrections only by reversal, credit/debit note or cancellation | Edit in open period (rejected for audit reasons) |
| DEC-08 | First vertical slice | Purchase bill (F3), see 10-Development-Roadmap | Customer receipt, or Sales invoice |
| DEC-09 | Totals calculation | Server `…/calculate` endpoint is authoritative; React shows its result (debounced) | Client-side estimate with decimal.js |
| DEC-10 | LC accounting depth in v1 | Charges, margin, payments, FX, landed cost (06 §12) | Track LCs without posting (as the prototype does) |
| DEC-11 | Branch restriction | Optional per user (`company_user_branches`) | No branch restriction, or mandatory |
| DEC-12 | Hosting | Provider-neutral Linux VM/container setup; to be confirmed | Managed PaaS |
| DEC-13 | Financial statements for Sales staff | Add `reports.financial` for TB/P&L/BS/GL/aging; Sales staff keeps operational reports only | Keep the design's "Reports: View" for everyone listed |

## 8. Questions for the owner (only those that change the design)

1. ~~**ORM**~~: ✅ answered: Eloquent (Option A).
2. **Branch isolation**: must some users (e.g. a depot manager) be limited to one branch's data?
3. **Party roles**: can one business be both a customer and a supplier, with netting of balances?
4. **VAT**: are any client companies VAT-registered and required to produce Mushak invoices (6.3) or registers from this system in v1?
5. **LC accounting**: should LC charges, margin and payments post to the books in v1 (recommended), or only be tracked?
6. **Numbering**: is it acceptable that a deleted draft leaves a logged gap in the number series?
7. **Hosting**: any preferred provider, region, or data-residency requirement (e.g. data must stay in Bangladesh)?
