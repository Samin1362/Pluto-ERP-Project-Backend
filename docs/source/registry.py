"""Shared identifiers: roles, business rules (BR), functional (FR) and non-functional (NFR) requirements.

Every document and the API/test registries reference these IDs; check_refs.py fails the build
if any reference points to an ID that does not exist here.
"""

# ---------------------------------------------------------------- roles (K11 matrix, seeded exactly)
MODULES = ["dashboard", "sales", "purchases", "parties", "inventory", "banking", "lc", "accounting", "reports", "settings"]
ACTIONS = ["view", "create", "edit", "approve", "delete"]
# V C E A D per module; LC row added (GAP-23): mirrors Purchases for Finance manager/Accountant.
ROLE_MATRIX = {
    "owner":      {m: "VCEAD" for m in MODULES},
    "finance":    {"dashboard": "V", "sales": "VCEA", "purchases": "VCEA", "parties": "VCE", "inventory": "VA", "banking": "VCEA",
                   "lc": "VCEA", "accounting": "VCEA", "reports": "VC", "settings": "VE"},
    "accountant": {"dashboard": "V", "sales": "VCE", "purchases": "VCE", "parties": "VCE", "inventory": "V", "banking": "VCE",
                   "lc": "VCE", "accounting": "VCEA", "reports": "VC", "settings": ""},
    "sales":      {"dashboard": "V", "sales": "VCE", "purchases": "", "parties": "VC", "inventory": "V", "banking": "", "lc": "",
                   "accounting": "", "reports": "V", "settings": ""},
    "store":      {"dashboard": "", "sales": "", "purchases": "V", "parties": "", "inventory": "VCE", "banking": "", "lc": "",
                   "accounting": "", "reports": "", "settings": ""},
    "viewer":     {"dashboard": "V", "sales": "V", "purchases": "V", "parties": "V", "inventory": "V", "banking": "V", "lc": "V",
                   "accounting": "V", "reports": "V", "settings": ""},
}
ROLE_NAMES = {"owner": "Owner", "finance": "Finance manager", "accountant": "Accountant", "sales": "Sales staff",
              "store": "Store keeper", "viewer": "Viewer"}
SPECIAL_PERMS = {  # permission -> roles that hold it by default
    "inventory.view_costs": ["owner", "finance", "accountant", "sales", "viewer"],
    "reports.export": ["owner", "finance", "accountant"],
    "accounting.lock_period": ["owner", "finance"],
    "accounting.close_year": ["owner"],
    "banking.reconcile": ["owner", "finance", "accountant"],
    "settings.users": ["owner"],
    "settings.roles": ["owner"],
    "settings.backup": ["owner", "finance"],
    "settings.restore": ["owner"],
    "auth.self": ["owner", "finance", "accountant", "sales", "store", "viewer"],
}
LETTER = {"V": "view", "C": "create", "E": "edit", "A": "approve", "D": "delete"}


def roles_with(perm):
    if perm in (None, "", "public"):
        return ["(anyone)"]
    if perm in SPECIAL_PERMS:
        return [ROLE_NAMES[r] for r in SPECIAL_PERMS[perm]]
    mod, act = perm.split(".")
    letter = [k for k, v in LETTER.items() if v == act][0]
    return [ROLE_NAMES[r] for r, m in ROLE_MATRIX.items() if letter in m.get(mod, "")]


ALL_PERMS = sorted({f"{m}.{a}" for m in MODULES for a in ACTIONS} | set(SPECIAL_PERMS))

# ---------------------------------------------------------------- business rules
BR = {
 "BR-001": "Every posted journal entry balances: total debit = total credit > 0.",
 "BR-002": "Each journal line carries a debit or a credit greater than zero, never both and never neither.",
 "BR-003": "Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.",
 "BR-004": "A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.",
 "BR-005": "Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.",
 "BR-006": "Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.",
 "BR-007": "A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.",
 "BR-008": "Nobody approves a voucher they submitted (segregation of duties).",
 "BR-009": "A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.",
 "BR-010": "Money uses decimal arithmetic only. Line amounts are rounded half-up to 2 decimals; document totals are the sum of rounded lines; the server's calculation is authoritative.",
 "BR-011": "Line gross = quantity x unit price; discount = gross x discount% ; tax = (gross - discount) x tax rate; line total = gross - discount + tax.",
 "BR-012": "Invoice rounding (company setting: none, nearest 1, nearest 5 taka) is posted to the mapped rounding ledger.",
 "BR-013": "A sales invoice needs an active customer, a warehouse, at least one line with quantity > 0 and price >= 0, and a due date on or after the invoice date.",
 "BR-014": "Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock.",
 "BR-015": "Exceeding a customer's credit limit or invoicing a customer with overdue invoices shows a warning but does not block.",
 "BR-016": "Cost of goods sold is quantity x the product's moving weighted average cost in the issuing warehouse at posting; the unit cost is stored on the invoice line.",
 "BR-017": "On every stock-in to a warehouse, that warehouse's average cost = (warehouse value + incoming value) / (warehouse quantity + incoming quantity); when current quantity <= 0 the incoming unit cost becomes the average.",
 "BR-018": "A purchase bill requires the supplier's bill number, unique per supplier within the company.",
 "BR-019": "Posting a bill increases stock in the receiving warehouse and debits that warehouse's inventory ledger for stocked lines, or the line's expense ledger for non-stock lines; the supplier is credited.",
 "BR-020": "Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.",
 "BR-021": "Allocations, notes and returns may reference only documents of the same party and company.",
 "BR-022": "Returned quantity per line <= original quantity - quantity already returned.",
 "BR-023": "A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).",
 "BR-024": "Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances.",
 "BR-025": "Only drafts can be deleted; a posted document can only be cancelled or reversed.",
 "BR-026": "A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents.",
 "BR-027": "A ledger's code and opening balance are locked once it has entries; the group may change only within the same nature.",
 "BR-028": "Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.",
 "BR-029": "A stock adjustment posts the value of the difference: a shortage debits the stock-adjustment ledger and credits inventory; a gain does the opposite.",
 "BR-030": "A stock transfer requires different warehouses and quantity <= available at the source; total value is unchanged; a journal is posted only if the warehouses use different inventory ledgers.",
 "BR-031": "A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).",
 "BR-032": "A reconciliation finishes only when every statement line is matched, created, paired or ignored and the difference is zero.",
 "BR-033": "Periods lock in date order by users with accounting.lock_period; unlocking needs the same permission, a reason, and is audited.",
 "BR-034": "A financial year closes only after its end date with every period locked; closing posts a year-end entry moving net profit to retained earnings.",
 "BR-035": "The base currency cannot change once any journal entry exists.",
 "BR-036": "No tax rate is assumed: tax is calculated only from user-defined tax rules attached to a line, and posts to the rule's ledger.",
 "BR-037": "Every read and write is scoped to the active company; any reference to another company's record is rejected as not found.",
 "BR-038": "When a membership has branch restrictions, the user can only see and create records of those branches.",
 "BR-039": "Submitting an LC to the bank requires supplier, amount > 0, issuing bank and expiry date; marking it opened requires the bank reference and opening date.",
 "BR-040": "LC status follows the state machine in 06 section 12.1; closed and cancelled LCs are read-only.",
 "BR-041": "LC amendment changes apply to the LC only when the amendment is accepted; each accepted change is recorded with old and new values.",
 "BR-042": "LC payment taka amount = foreign amount x bank rate (rounded to 2 dp); total paid may not exceed LC amount x (1 + tolerance).",
 "BR-043": "Differences between the rate at which a foreign bill was booked and the rate at which it is paid post to the exchange gain or loss ledger.",
 "BR-044": "An LC margin deposit is a refundable asset, never part of landed cost; it is applied to the payment or released at closure. The margin applied to payments may not exceed the margin deposited for that LC.",
 "BR-045": "LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold.",
 "BR-046": "An LC can be closed only when the supplier is fully paid (or the LC expired), no charges are unpaid and no documents are missing or discrepant, or with an explicit override reason.",
 "BR-047": "Customs duties and taxes are entered as assessed; LedgerFlow does not calculate them.",
 "BR-048": "Recurring templates create drafts only; nothing posts automatically.",
 "BR-049": "Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.",
 "BR-050": "Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.",
 "BR-051": "Attachments must be JPG, PNG or PDF up to 10 MB, verified by content type sniffing.",
 "BR-052": "Users without inventory.view_costs never receive cost, value or margin fields from the API.",
 "BR-053": "A voucher dated more than the configured number of days in the past requires explicit confirmation.",
 "BR-054": "An expense or income entry needs amount > 0, a category and a money account; it posts the category ledger against the money account.",
 "BR-055": "Due date defaults to document date + the party's payment-term days and may be edited.",
 "BR-056": "Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities.",
 "BR-057": "A purchase return credits inventory at the original bill cost of the returned lines; the supplier is debited through the debit note.",
 "BR-058": "A cash-count difference posts to the cash short/over ledger and requires approval.",
 "BR-059": "Active plus invited users cannot exceed the company's seat limit.",
 "BR-060": "The Owner role always has every permission and a company always keeps at least one active Owner.",
 "BR-061": "Deactivating a user or changing their password revokes all their sessions immediately.",
 "BR-062": "Restore is Owner-only, requires typing RESTORE, and always takes a pre-restore snapshot first.",
 "BR-063": "Local party mobile numbers have 11 digits starting 01 (01XXX-XXXXXX); e-mail addresses must be valid when given.",
 "BR-064": "Posting a sales invoice creates: Dr customer (total), Cr each line's income ledger (net), Cr tax ledgers (tax), Cr/Dr rounding; and for stocked lines Dr COGS, Cr warehouse inventory (cost).",
 "BR-065": "A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.",
}

# ---------------------------------------------------------------- functional requirements
FR = {
 # Auth & access
 "FR-001": ("Auth", "Users sign in with e-mail and password, plus a TOTP code when 2-step verification is on."),
 "FR-002": ("Auth", "Users can sign out of the current session, or of every session except the current one."),
 "FR-003": ("Auth", "Users can reset a forgotten password by e-mail link (single use, 60 min)."),
 "FR-004": ("Auth", "Users can enable or disable 2-step verification and regenerate recovery codes."),
 "FR-005": ("Auth", "A user belonging to several companies can switch the active company; every screen then shows only that company's data."),
 "FR-006": ("Access", "Owners invite users by e-mail with a role; invitations expire after 7 days."),
 "FR-007": ("Access", "Owners create, duplicate and edit roles, ticking View/Create/Edit/Approve/Delete per module, and set approval limits."),
 "FR-008": ("Access", "Owners deactivate and reactivate users."),
 "FR-009": ("Access", "Users list and revoke their own signed-in sessions."),
 # Dashboard / search / notifications
 "FR-010": ("Dashboard", "The dashboard shows KPI tiles, revenue vs expenses, cash position, money owed by age, upcoming payments, recent transactions and inventory alerts for a chosen period."),
 "FR-011": ("Dashboard", "Seven dashboard lenses (financial, sales, expenses, receivables, payables, cash and bank, inventory) summarise a module and deep-link into it."),
 "FR-012": ("Search", "Global search and the Ctrl K palette find parties, documents, vouchers, ledgers and products, filtered by the user's permissions."),
 "FR-013": ("Notifications", "Users receive in-app notifications (approvals, alerts) and can mark them read; preferences choose in-app, e-mail and SMS per event."),
 "FR-014": ("Approvals", "Approvers see pending vouchers, and approve or reject them with a comment."),
 # Parties
 "FR-020": ("Parties", "Users list, search, filter and sort customers and suppliers, with balance, overdue and status."),
 "FR-021": ("Parties", "Users add and edit customers and suppliers (contact, accounting, payment details); a ledger is created automatically."),
 "FR-022": ("Parties", "Users view party details: KPIs, open documents, monthly trend, contact, terms and activity."),
 "FR-023": ("Parties", "Users view a party's transaction history, ledger with running balance, and outstanding documents by age."),
 "FR-024": ("Parties", "Users print, download or e-mail a statement of account with an aging summary."),
 "FR-025": ("Parties", "Users make a party inactive; parties without history can be deleted."),
 # Inventory
 "FR-030": ("Inventory", "Users list products and services with stock status, and filter by type, category, warehouse and stock level."),
 "FR-031": ("Inventory", "Users add and edit products (stock-tracked) and services (no stock fields), with pricing, accounts and per-warehouse reorder levels."),
 "FR-032": ("Inventory", "Users view product details: stock by warehouse, recent movements and price history."),
 "FR-033": ("Inventory", "Users manage product categories, stock groups, units and warehouses."),
 "FR-034": ("Inventory", "Users view the stock movement history with filters."),
 "FR-035": ("Inventory", "Users post stock adjustments from a count, with the value change posted to the books."),
 "FR-036": ("Inventory", "Users dispatch and receive stock transfers between warehouses."),
 "FR-037": ("Inventory", "Users view low-stock, stock valuation and inventory summary reports, and create purchase orders from low stock."),
 # Sales
 "FR-040": ("Sales", "Users view the sales overview and the invoice list with status tabs, search, date and customer filters."),
 "FR-041": ("Sales", "Users create, edit, preview, save as draft and post sales invoices with server-calculated totals."),
 "FR-042": ("Sales", "Users view invoice details: lines, payments, ledger entries, activity, delivery and attachments."),
 "FR-043": ("Sales", "Users record customer receipts and allocate them to open invoices."),
 "FR-044": ("Sales", "Users create sales returns and credit notes and choose how the credit is used."),
 "FR-045": ("Sales", "Users create quotations, send them and convert accepted ones to invoices."),
 "FR-046": ("Sales", "Users cancel posted invoices with a reason and delete drafts."),
 "FR-047": ("Sales", "Users view sales and sales-return reports grouped by customer, category or month."),
 # Purchases
 "FR-050": ("Purchases", "Users view the purchase overview and bill list."),
 "FR-051": ("Purchases", "Users create and post purchase bills that receive stock."),
 "FR-052": ("Purchases", "Users record supplier payments and allocate them to bills."),
 "FR-053": ("Purchases", "Users create purchase returns and debit notes."),
 "FR-054": ("Purchases", "Users create purchase orders and convert them to bills."),
 "FR-055": ("Purchases", "Users view purchase and purchase-return reports."),
 # Banking / income-expense
 "FR-060": ("Banking", "Users view the cash and bank overview, cash and bank account details, books and transaction history."),
 "FR-061": ("Banking", "Users add bank, wallet and card accounts with opening balance."),
 "FR-062": ("Banking", "Users record money received and money paid that are not invoice-related."),
 "FR-063": ("Banking", "Users record cash and bank transfers (contra), including bank charges."),
 "FR-064": ("Banking", "Users import bank statements and reconcile them in a two-pane workspace."),
 "FR-065": ("Banking", "Users record cash counts by denomination."),
 "FR-066": ("IncExp", "Users record income and expenses with category, account, method, attachment and optional recurrence."),
 "FR-067": ("IncExp", "Users manage income/expense categories with ledgers and monthly budgets."),
 "FR-068": ("IncExp", "Users view income and expense overviews, lists and reports by category and month."),
 # Accounting
 "FR-070": ("Accounting", "Users view the accounting overview with books-health checks."),
 "FR-071": ("Accounting", "Users view and maintain the chart of accounts (groups and ledgers)."),
 "FR-072": ("Accounting", "Users view a ledger with running balance and monthly closing balances."),
 "FR-073": ("Accounting", "Users create journal, payment, receipt and contra vouchers with a live balance check, save drafts, submit for approval and post."),
 "FR-074": ("Accounting", "Users view voucher details with audit trail, reverse posted vouchers, delete drafts and print vouchers."),
 "FR-075": ("Accounting", "Users configure the account mappings used by automatic postings."),
 "FR-076": ("Reports", "Users view trial balance, profit and loss, balance sheet, cash flow, general ledger, day book, and receivables/payables aging, and export them (xlsx, pdf, csv)."),
 # Settings
 "FR-080": ("Settings", "Owners view and edit the company profile, logo and locations."),
 "FR-081": ("Settings", "Users with settings edit configure preferences: numbering, defaults and controls."),
 "FR-082": ("Settings", "Users manage financial years and lock accounting periods; Owners close the year."),
 "FR-083": ("Settings", "Users set currency display and regional formats."),
 "FR-084": ("Settings", "Users define tax rules and how tax shows on documents."),
 "FR-085": ("Settings", "Users customise invoice layout with a live preview."),
 "FR-086": ("Settings", "Users set notification preferences, appearance and security settings for themselves."),
 "FR-087": ("Settings", "Owners configure backups, download backups, prepare the export pack and restore from a backup."),
 # LC
 "FR-090": ("LC", "Users view the LC dashboard: KPIs, alerts, deadlines, commitments by bank, pipeline, active LCs and recent activity."),
 "FR-091": ("LC", "Users list and filter LCs by status, bank, supplier and attention flags."),
 "FR-092": ("LC", "Users create an LC in sections (supplier and purchase, terms, banks and dates, documents, shipment, review), save drafts and submit to the bank."),
 "FR-093": ("LC", "Users view LC details with overview, terms, documents, shipment, charges and payments, amendments and activity."),
 "FR-094": ("LC", "Users record LC amendments with field-level changes and status."),
 "FR-095": ("LC", "Users maintain document checklists and templates and update document status, with scans attached."),
 "FR-096": ("LC", "Users record shipment lots and milestones inside an LC."),
 "FR-097": ("LC", "Users record LC charges and import expenses, and payments at the bank's rate."),
 "FR-098": ("LC", "Users receive LC goods through an import purchase bill and allocate landed cost."),
 "FR-099": ("LC", "Users close an LC, after checks, and view the eight LC reports."),
}

# ---------------------------------------------------------------- non-functional requirements
NFR = {
 "NFR-001": ("Performance", "List and detail APIs respond within 300 ms (p95), and reports over one FY within 2 s (p95), at 50 concurrent users per company and 1 million journal lines."),
 "NFR-002": ("Performance", "Posting a 50-line invoice completes within 1 s (p95)."),
 "NFR-003": ("Performance", "Lists are paginated server-side (default 25, maximum 100 rows); no endpoint returns an unbounded collection."),
 "NFR-004": ("Performance", "First meaningful paint of the SPA within 2.5 s on a 10 Mbps connection; route chunks are lazy-loaded."),
 "NFR-005": ("Scalability", "Stateless API nodes scale horizontally behind a load balancer; sessions are not held in memory."),
 "NFR-006": ("Scalability", "Exports, statement imports, backups, notifications and recurring entries run on queue workers."),
 "NFR-010": ("Security", "All traffic uses TLS 1.2+ with HSTS; cookies are Secure, HttpOnly and SameSite=Strict."),
 "NFR-011": ("Security", "Passwords are hashed with Argon2id; minimum 10 characters, checked against a breached-password list."),
 "NFR-012": ("Security", "Every endpoint except public auth checks authentication, company membership and a named permission server-side."),
 "NFR-013": ("Security", "Brute-force protection: 5 failed logins per (account, source) in 15 min blocks that source (429, Retry-After); 50 failures per account per hour locks the account with an e-mailed unlock link; responses never reveal whether an account exists or is locked; IP rate limits on auth endpoints."),
 "NFR-014": ("Security", "Secrets live only in server environment or a secret manager, never in the frontend bundle or the repository."),
 "NFR-015": ("Security", "OWASP ASVS L2 controls: input validation, output encoding, CSRF protection for cookie endpoints, security headers (CSP, X-Frame-Options)."),
 "NFR-016": ("Security", "Uploaded files are stored privately, served only through signed short-lived URLs, and type-checked by content."),
 "NFR-020": ("Audit", "Every create, update, post, approve, reject, reverse, cancel, delete, login and permission change is recorded in audit_logs with user, time, IP, request id, and old and new values."),
 "NFR-021": ("Audit", "Audit logs are append-only and retained for at least 8 years."),
 "NFR-022": ("Traceability", "Every journal entry links to its source document, and every stock movement to its source document and journal entry."),
 "NFR-030": ("Recovery", "RPO <= 15 minutes, using binary-log point-in-time recovery."),
 "NFR-031": ("Recovery", "RTO <= 4 hours for a full restore; restore is rehearsed monthly."),
 "NFR-032": ("Recovery", "Daily logical per-company backups are kept per the retention setting (30/90/365 days)."),
 "NFR-040": ("Reliability", "Financial operations are atomic: a document, its stock movements and its journal commit or roll back together."),
 "NFR-041": ("Reliability", "Monthly availability 99.5% during business hours (08:00–22:00 Asia/Dhaka)."),
 "NFR-042": ("Reliability", "Nightly integrity jobs verify trial balance, stock and settlement caches, and alert on any difference."),
 "NFR-050": ("Usability", "The UI follows the LedgerFlow design system exactly (tokens, components, layouts), in light and dark themes."),
 "NFR-051": ("Usability", "WCAG 2.2 AA: keyboard operable, visible focus, 4.5:1 contrast, labelled inputs, polite live regions for toasts."),
 "NFR-052": ("Usability", "Responsive breakpoints per design: >=1440, >=1200, 900–1199 icon rail, <900 drawer, <640 stacked rows."),
 "NFR-053": ("Usability", "Amounts are shown in lakh/crore grouping with the ৳ symbol per company settings; negative amounts in parentheses."),
 "NFR-054": ("Usability", "Keyboard shortcuts per design (Ctrl K, Alt S/P/R/Y/J/C, Ctrl S, Ctrl Enter)."),
 "NFR-060": ("Maintainability", "Backend layered into controllers, form requests, policies, services, models; controllers contain no business logic."),
 "NFR-061": ("Maintainability", "Backend coverage >= 85% lines on the Domain layer, and 100% of posting rules have balance tests."),
 "NFR-062": ("Maintainability", "OpenAPI spec is the contract; CI fails if the implemented routes and the spec diverge."),
 "NFR-070": ("Compliance", "Bangladesh tax and regulatory items listed in 06 section 14 are confirmed with a qualified adviser before production use."),
 "NFR-071": ("Compliance", "Financial records are retained for at least 8 years and are exportable."),
 "NFR-080": ("Localisation", "Dates in the company time zone (Asia/Dhaka); printed documents in English, Bangla or both."),
}
