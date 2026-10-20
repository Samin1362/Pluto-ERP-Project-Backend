"""Page registry: every screen in the LedgerFlow design (canvas codes) with its UI contract.

Field spec string: "Label | type | req/opt | rule"   (type = text, number, money, pct, date, select, segmented,
switch, checkbox, textarea, file, readonly, table)
APIs and database entities are NOT listed here: they are derived from api.py (each endpoint names its pages),
so the page -> API -> table chain cannot drift.
"""

P = []


def page(code, name, module, template, perm, purpose, layout="", fields=(), actions=(), table=None, validation=(),
         workflows=(), states=(), deps=(), rules=(), fr=(), artboard="", note=""):
    P.append(dict(code=code, name=name, module=module, template=template, perm=perm, purpose=purpose, layout=layout,
                  fields=list(fields), actions=list(actions), table=table, validation=list(validation), workflows=list(workflows),
                  states=list(states), deps=list(deps), rules=list(rules), fr=list(fr), artboard=artboard, note=note))


SHELL = "App shell: sidebar (company switcher, module nav with badges), top bar (breadcrumbs, search/Ctrl K, FY selector, quick-create, help, notifications, profile)."

# ======================================================================= Shell & auth (missing screens marked NEW)
page("S0", "App shell", "Shell", "Shell", "dashboard.view",
     "Persistent navigation, company and FY context, quick create, search and notifications on every page.",
     SHELL + " Responsive: icon rail 900–1199 px, drawer < 900 px.",
     fields=["Company switcher | select | req | Only companies where the membership is active",
             "Financial-year selector | select | req | Open and closed FYs; 'Custom period…' sets report range"],
     actions=["Switch company (reloads all data)", "Open command palette (Ctrl K)", "Quick create menu: Sales invoice Alt S, Purchase bill Alt P, Receipt Alt R, Payment Alt Y, Contra Alt C, Journal Alt J, Customer, Supplier, Product",
              "Open notifications", "Profile menu: profile, security, sign out"],
     workflows=["Find anything fast"], deps=["L2", "L3", "L5"], fr=["FR-005", "FR-012", "FR-013"], artboard="Shell.dc.html (04)")
page("S1", "Sign in (NEW: not in design)", "Shell", "Auth", "public",
     "Authenticate with e-mail and password; second step asks the TOTP or a recovery code when 2-step is on.",
     "Centered card on paper background using the LedgerFlow logo, Field, Input and Button components.",
     fields=["Email | text | req | Valid e-mail", "Password | password | req | ", "Authenticator code | text | cond | 6 digits; shown only after step 1 when 2FA is on"],
     actions=["Sign in", "Use a recovery code", "Forgot password"],
     validation=["Generic error 'E-mail or password is incorrect' (no account enumeration)", "Lockout message after 5 failures shows the retry time"],
     rules=["BR-061"], fr=["FR-001"], note="GAP-13")
page("S2", "Forgot / reset password (NEW)", "Shell", "Auth", "public",
     "Request a reset link and set a new password from the link.", "Same card layout as S1.",
     fields=["Email | text | req | ", "New password | password | req | >= 10 chars, not breached", "Confirm password | password | req | Must match"],
     actions=["Send link", "Set password"], validation=["Always shows 'If the address exists, we sent a link'"], fr=["FR-003"], note="GAP-13")
page("S3", "Accept invitation (NEW)", "Shell", "Auth", "public",
     "Invited user sets name and password and joins the company.", "Card layout.",
     fields=["Name | text | req | ", "Password | password | req | as S2"], actions=["Join company"], fr=["FR-006"], note="GAP-13")

# ======================================================================= A · Dashboard
page("A1", "Main dashboard", "Dashboard", "Overview", "dashboard.view",
     "Morning view of the business: KPIs, revenue vs expenses, cash, money owed, upcoming payments, quick actions, recent transactions, inventory alerts.",
     "Greeting header with period segmented control and two actions; reconciliation banner; 4 KPI StatTiles; 12-month bar chart with hover; cash position list; money-owed aging bars; upcoming payments; quick actions; recent transactions table; inventory alerts.",
     fields=["Period | segmented | req | This month / quarter / FY to date"],
     actions=["New invoice (Alt S)", "Record receipt", "Reconcile (from banner)", "Dismiss banner", "Open any tile or row (deep link)"],
     table={"cols": ["Date", "Voucher", "Type", "Party", "Amount (৳)"], "filters": [], "search": False},
     workflows=["Bill a customer", "Pay a supplier"], deps=["A2", "A5", "A6", "A7", "A8", "E3", "G10"], fr=["FR-010"],
     artboard="Dashboard.dc.html (03, 05 dark, 06 tablet, 07 phone)")
for code, name, desc, deps in [
    ("A2", "Financial overview", "Condensed P&L share of revenue, net profit by month, key ratios (gross/net margin, current ratio, debtor and creditor days).", ["H16", "H17"]),
    ("A3", "Sales overview lens", "Sales by category bars and top customers table.", ["E1", "E11"]),
    ("A4", "Expense overview lens", "Expenses by category against budget.", ["I4", "I9"]),
    ("A5", "Outstanding receivables", "Aging buckets and customers with balances; select to send statements.", ["B7", "J6"]),
    ("A6", "Outstanding payables", "Aging buckets and suppliers; upcoming bills.", ["C7", "J7"]),
    ("A7", "Cash and bank summary", "30-day combined balance, accounts with reconciliation status, recent movements.", ["G1", "G10"]),
    ("A8", "Inventory alerts", "Items below reorder level (products only), stock value by warehouse.", ["D12", "F8"])]:
    page(code, name, "Dashboard", "Lens", "dashboard.view", desc,
         "Same shell and page header as A1 with lens sub-navigation; KPI tiles, one chart or bar list, one table linking onward.",
         actions=["Open row (deep link)", "View all"], table={"cols": ["per lens"], "filters": ["Period"], "search": False},
         deps=deps, fr=["FR-011"], artboard=f"Dash-{code}.dc.html")

# ======================================================================= B/C · Parties
for side, pre, noun, docs in [("customer", "B", "Customer", "invoices"), ("supplier", "C", "Supplier", "bills")]:
    cust = side == "customer"
    page(f"{pre}1", f"{noun} list", "Parties", "List", "parties.view",
         f"Every {side} with contact, terms, {'receivable' if cust else 'payable'} balance and status.",
         "Page header with Import/Export/New; 4 summary tiles; toolbar (search, status segmented All/With balance/Overdue, area select, sort); bulk bar on selection; selectable DataTable; pagination.",
         fields=["Search | text | opt | name, code or mobile", "Status | segmented | opt | all, balance, overdue", "Area | select | opt | district", "Sort | select | opt | balance desc (default), name, recent"],
         actions=[f"New {side}", "Open row", "Edit (row)", "Send statements (bulk)", "Export (bulk)", "Mark inactive (bulk)"],
         table={"cols": [noun, "Contact", "Mobile", "Area", "Terms", "Receivable (৳)" if cust else "Payable (৳)", "Status"], "filters": ["status", "area"], "search": True},
         deps=[f"{pre}2", f"{pre}4"], fr=["FR-020"], artboard="Parties.dc.html (B1)" if cust else "Supp-List.dc.html")
    page(f"{pre}2", f"Add {side}", "Parties", "Form", "parties.create",
         f"Create a {side}; its ledger is created under {'1201 Sundry debtors' if cust else '2101 Sundry creditors'}.",
         "Grouped cards: Basic details, Contact, Accounting" + ("" if cust else ", Payment details") + ", Notes; right 'Before you save' checklist; sticky footer Cancel / Save and add another / Save.",
         fields=["Name | text | req | As on documents; max 190", f"{noun} code | readonly | — | Generated {'CUS' if cust else 'SUP'}-NNNN",
                 "Type | select | opt | " + ("Business, Individual, Walk-in counter" if cust else "Manufacturer, Distributor, Service provider, Individual"),
                 f"{noun} group | select | opt | ", "Contact person | text | opt | ", "Mobile number | text | req | BR-063 (+88 prefix shown)",
                 "Email | text | opt | valid e-mail", "Alternate number | text | opt | ", "Address | text | opt | ", "District | select | opt | ", "Area or thana | text | opt | ",
                 "Opening balance | money | opt | >= 0; as of FY start; locked after first document (BR-028)",
                 "Balance type | segmented | req | " + ("They owe you (Dr) / Advance received (Cr)" if cust else "Advance paid (Dr) / You owe them (Cr)"),
                 "Payment terms | select | opt | default company terms"]
                + (["Credit limit | money | opt | >= 0; warns before invoicing past it"] if cust else ["Default purchase account | select | opt | expense/purchase ledger for non-stock lines",
                                                                                                        "Bank | select | opt | ", "Account name | text | opt | ", "Account number | text | opt | ", "Branch | text | opt | "])
                + ["Control ledger | readonly | — | group the ledger rolls up to", "Tax registration number | text | opt | ", "Notes | textarea | opt | team only"],
         actions=["Cancel (unsaved-changes dialog if dirty)", "Save and add another", "Save (Ctrl S)"],
         validation=[f"Name required: 'Enter the {side}'s name'", "Mobile required and 11 digits starting 01: 'Enter 11 digits starting 01, e.g. 01711-284530'",
                     "Banner 'Fix N fields to save this " + side + "' listing errors", "Possible-duplicate warning when name or mobile matches an existing party (non-blocking)"],
         rules=["BR-063", "BR-028"], deps=[f"{pre}1", "H2"], fr=["FR-021"], artboard=f"{'Cust' if cust else 'Supp'}-Add.dc.html")
    page(f"{pre}3", f"Edit {side}", "Parties", "Form", "parties.edit",
         f"Edit a {side}. Opening balance is read-only once documents exist; shows current balance and audit line.",
         f"Same form as {pre}2 with info banner, 'Make inactive' zone, current balance in the aside, 'Unsaved changes' badge.",
         fields=[f"Same as {pre}2 | — | — | opening balance read-only"], actions=["Cancel", "Save changes (If-Match)", "Make inactive"],
         validation=[f"As {pre}2", "409 conflict banner: 'Someone else changed this record' with reload"], rules=["BR-026", "BR-049"],
         deps=[f"{pre}4"], fr=["FR-021", "FR-025"], artboard=f"{'Cust' if cust else 'Supp'}-Edit.dc.html")
    page(f"{pre}4", f"{noun} details", "Parties", "Detail", "parties.view",
         f"Everything about one {side}: KPIs, open {docs}, 6-month trend, contact, terms" + (", credit used" if cust else "") + " and activity.",
         "Identity header (initials, name, status badge, code, contact) with Edit / Statement / New " + ("invoice" if cust else "bill") + "; tabs Overview · History · Ledger; 4 StatTiles; open documents card with Record payment; monthly bars; aside.",
         actions=["Edit", "Statement", f"New {'invoice (Alt S)' if cust else 'bill (Alt P)'}", "Record payment", "Switch tab"],
         table={"cols": ["Invoice" if cust else "Bill", "Date", "Due", "Status", "Amount (৳)", "Paid (৳)", "Balance (৳)"], "filters": [], "search": False},
         deps=[f"{pre}5", f"{pre}6", f"{pre}8", "E7" if cust else "F5"], fr=["FR-022"], artboard=f"{'Cust' if cust else 'Supp'}-Details.dc.html")
    page(f"{pre}5", f"{noun} transaction history", "Parties", "List", "parties.view",
         f"All documents of the {side}.", "Detail header + History tab: search, document type select, date range, export; table; pagination.",
         fields=["Search | text | opt | number or reference", "Document type | select | opt | " + ("Invoices, Receipts, Credit notes, Returns" if cust else "Bills, Payments, Debit notes, Purchase orders"), "From / to | date | opt | to >= from"],
         actions=["Open document", "Export"], table={"cols": ["Date", "Number", "Type", "Reference", "Due", "Amount (৳)", "Status"], "filters": ["type", "date"], "search": True},
         deps=[f"{pre}4"], fr=["FR-023"], artboard=f"{'Cust' if cust else 'Supp'}-History.dc.html")
    page(f"{pre}6", f"{noun} ledger", "Parties", "Ledger", "parties.view",
         f"The {side}'s ledger with opening, debits, credits and running Dr/Cr balance.",
         "Date range, detailed/summary density, print, export; summary strip (opening, invoiced, received, closing); ledger table with debit/credit washes.",
         fields=["From / to | date | req | default current month to date", "Density | segmented | opt | detailed/summary"],
         actions=["Print", "Export", "Open voucher"], table={"cols": ["Date", "Voucher", "Particulars", "Debit (৳)", "Credit (৳)", "Balance (৳)"], "filters": ["date"], "search": False},
         deps=["H7"], fr=["FR-023"], artboard=f"{'Cust' if cust else 'Supp'}-Ledger.dc.html")
    page(f"{pre}7", f"Outstanding {'balances' if cust else 'bills'}", "Parties", "Report", "parties.view",
         f"Open {docs} across {side}s by age bucket (not due, 1–30, 31–60, 61–90, 90+).",
         "Bucket buttons (total + 5) acting as filters; segmented filter; search; selectable table with totals and row actions (statement, record payment).",
         actions=["Filter by bucket", "Send reminders/statements (bulk)", "Record payment (row)"],
         table={"cols": [noun, "Invoice" if cust else "Bill", "Date", "Due", "Age", "Balance (৳)"], "filters": ["bucket"], "search": True},
         deps=["J6" if cust else "J7"], fr=["FR-023"], artboard=f"{'Cust' if cust else 'Supp'}-Outstanding.dc.html")
    page(f"{pre}8", f"{noun} statement", "Parties", "Print", "parties.view",
         "Printable statement of account (A4) with summary, ledger lines and aging.",
         "Toolbar (period, aging checkbox, PDF, Print, E-mail); paper-white statement with company header, party block, summary, compact ledger, aging row and footer.",
         fields=["From / to | date | req | ", "Show ageing summary | checkbox | opt | default on"],
         actions=["PDF", "Print", f"Email to {side}"], deps=[f"{pre}6"], fr=["FR-024"], artboard=f"{'Cust' if cust else 'Supp'}-Statement.dc.html")

# ======================================================================= D · Inventory
page("D1", "Product list", "Inventory", "List", "inventory.view", "All products and services with cost, price, on hand and stock status.",
     "Header (Import, Export, New item); toolbar: search (name, code, barcode), type segmented, category, warehouse, stock-level selects; selectable table; pagination.",
     fields=["Search | text | opt | name, code or barcode", "Type | segmented | opt | all/product/service", "Category | select | opt | ", "Warehouse | select | opt | ", "Stock | select | opt | in/low/out"],
     actions=["New item", "Import", "Export", "Open", "Edit (row)"],
     table={"cols": ["Item", "Type", "Category", "Unit", "Cost (৳)*", "Price (৳)", "On hand", "Stock"], "filters": ["type", "category", "warehouse", "stock"], "search": True},
     rules=["BR-052"], deps=["D2", "D4"], fr=["FR-030"], artboard="Inventory.dc.html (D1)", note="* hidden without inventory.view_costs")
page("D2", "Add product or service", "Inventory", "Form", "inventory.create",
     "Create an item. Choosing Service hides stock fields (D2 service variant).",
     "Kind cards (Physical product / Service); cards Basic details, Pricing, Inventory (products only) with per-warehouse grid; aside invoice-line preview with margin; footer.",
     fields=["Kind | segmented | req | product or service", "Name | text | req | include pack size", "Item code | text | opt | suggested from category prefix; unique",
             "Category | select | opt | ", "Unit | select | req | ", "Barcode | text | opt | unique (products)", "Purchase cost | money | opt | products; >= 0",
             "Selling price | money | req | >= 0", "Wholesale price | money | opt | >= 0", "Tax rule | select | opt | from K6", "Income account | select | req | income ledger",
             "Track stock | switch | — | on for products", "Stock group | select | opt | ", "Valuation method | readonly | — | company setting",
             "Preferred supplier | select | opt | ", "Per warehouse: opening qty, reorder at, reorder qty | number | opt | >= 0; opening qty only on create"],
     actions=["Cancel", "Save and add another", "Save item"],
     validation=["Name and selling price required", "Code and barcode unique in company", "Opening quantity requires a cost (posts opening stock, BR-028)"],
     rules=["BR-028", "BR-052"], deps=["D5", "D6", "D7", "K6"], fr=["FR-031"], artboard="Inv-Add.dc.html, Inv-Service.dc.html")
page("D3", "Edit product", "Inventory", "Form", "inventory.edit", "Edit an item. Quantities change only through stock documents.",
     "As D2 with info banner linking to Stock adjustment and history panel (created, price changes).",
     fields=["Same as D2 | — | — | on-hand quantity read-only; kind locked once used"], actions=["Cancel", "Save changes", "Go to stock adjustment"],
     rules=["BR-026", "BR-049"], deps=["D10"], fr=["FR-031"], artboard="Inv-Edit.dc.html")
page("D4", "Product details", "Inventory", "Detail", "inventory.view", "Stock by warehouse, recent movements, item facts and price history.",
     "Header actions Edit / Adjust / Transfer; 4 StatTiles (on hand, value*, sold 30 days, margin*); stock-by-warehouse table; recent movements; aside facts and price history.",
     actions=["Edit", "Adjust", "Transfer", "All movements"], table={"cols": ["Warehouse", "On hand", "Reorder at", "Value (৳)*", "Status"], "filters": [], "search": False},
     rules=["BR-052"], deps=["D9", "D10", "D11"], fr=["FR-032"], artboard="Inv-Details.dc.html")
page("D5", "Product categories", "Inventory", "List", "inventory.view", "Two-level category tree with code prefixes, item counts and stock value; inline drawer to add.",
     "Table with indentation and row actions; inline Drawer 'New category'.",
     fields=["Name | text | req | unique", "Parent category | select | opt | top level if empty; max 2 levels", "Code prefix | text | opt | 2–10 uppercase", "Default unit | select | opt | ", "Default stock group | select | opt | "],
     actions=["New category", "Edit", "Reorder (drag)", "Delete (only if unused)"], table={"cols": ["Category", "Code prefix", "Items", "Stock value (৳)"], "filters": [], "search": False},
     deps=["D2"], fr=["FR-033"], artboard="Inv-Categories.dc.html")
page("D6", "Stock groups", "Inventory", "Tree", "inventory.view", "Reporting hierarchy for valuation, with items, share and value.",
     "Info banner (groups vs categories); tree table; Print; New stock group.", actions=["New stock group", "Edit", "Print"],
     table={"cols": ["Stock group", "Items", "Share", "Value (৳)"], "filters": [], "search": False}, deps=["D13"], fr=["FR-033"], artboard="Inv-Groups.dc.html")
page("D7", "Warehouse list", "Inventory", "List", "inventory.view", "Cards per warehouse: status, address, manager, stock value, products, below reorder, space used.",
     "Card grid + 'Add a warehouse' card.", actions=["Open", "Transfer", "New warehouse (drawer: code, name, branch, address, manager, phone, inventory ledger, default for sales, status, opens on)"],
     validation=["Code unique; branch required; inventory ledger auto-created under 1301 unless chosen"], deps=["D8", "D11"], fr=["FR-033"], artboard="Inv-Warehouses.dc.html")
page("D8", "Warehouse details", "Inventory", "Detail", "inventory.view", "Stock in one warehouse with search and below-reorder filter; facts and recent transfers.",
     "4 tiles; stock table with search and checkbox; pagination; aside.", fields=["Search | text | opt | ", "Only below reorder level | checkbox | opt | "],
     actions=["Edit", "Count stock", "Transfer"], table={"cols": ["Code", "Product", "On hand", "Reorder at", "Value (৳)*", "Status"], "filters": ["below_reorder"], "search": True},
     deps=["D10", "D11"], fr=["FR-033"], artboard="Inv-Warehouse.dc.html")
page("D9", "Stock movement history", "Inventory", "List", "inventory.view", "Every quantity change from invoices, bills, transfers, returns and adjustments.",
     "Toolbar: search, movement type, warehouse, date range; table; pagination; Export.",
     fields=["Search | text | opt | product or reference", "Movement type | select | opt | ", "Warehouse | select | opt | ", "From / to | date | opt | "],
     actions=["Open source document", "Export"], table={"cols": ["Date", "Reference", "Product", "Movement", "Warehouse", "In", "Out", "Balance"], "filters": ["type", "warehouse", "date"], "search": True},
     deps=["D4"], fr=["FR-034"], artboard="Inv-Movements.dc.html")
page("D10", "Stock adjustment", "Inventory", "Form", "inventory.create", "Correct quantities after a count, damage or expiry; posts the value change.",
     "Details card (number, date, warehouse, reason); count grid (in system, counted, difference, unit cost*, value*); notes; aside 'What gets posted' with BalanceStatus; footer Save draft / Post adjustment.",
     fields=["Adjustment number | readonly | — | ADJ series", "Date | date | req | open period", "Warehouse | select | req | ", "Reason | select | req | count difference, damaged, expired, theft or loss, free samples",
             "Lines: product, counted qty | table | req | counted >= 0; at least one line with difference", "Notes | textarea | opt | "],
     actions=["Import count sheet", "Add product", "Remove line", "Save draft", "Post adjustment"],
     validation=["Counted quantity must be a number >= 0", "System quantity is re-read at posting; if it changed since the draft, the user is asked to recount"],
     rules=["BR-029", "BR-004", "BR-003"], workflows=["Inventory movement"], deps=["K16"], fr=["FR-035"], artboard="Inv-Adjust.dc.html")
page("D11", "Stock transfer", "Inventory", "Form", "inventory.create", "Move stock between warehouses: Draft > Dispatched > Received.",
     "Step indicator; Route card (from, to, number, dispatch date, vehicle, driver); items grid (available, transfer, after); aside summary; footer Save draft / Dispatch transfer; received later by destination.",
     fields=["From | select | req | ", "To | select | req | != From", "Dispatch date | date | req | ", "Vehicle | text | opt | ", "Driver | text | opt | ", "Lines: product, quantity | table | req | 0 < qty <= available"],
     actions=["Add product", "Save draft", "Dispatch transfer", "Confirm receipt (destination)", "Cancel draft"],
     validation=["'Only N available' inline per line", "Banner 'You can't move more than the source warehouse holds'"],
     rules=["BR-030", "BR-014"], workflows=["Inventory movement"], deps=["D7"], fr=["FR-036"], artboard="Inv-Transfer.dc.html")
page("D12", "Low-stock report", "Inventory", "Report", "inventory.view", "Items at or below reorder level with suggested order quantity and value.",
     "Warehouse select, All/Out/Low segmented, group by supplier checkbox; 4 tiles; selectable table.",
     actions=["Create purchase orders (from selection)", "Print", "Export"],
     table={"cols": ["Product", "Warehouse", "On hand", "Reorder at", "Status", "Preferred supplier", "Suggested", "Order value (৳)*"], "filters": ["warehouse", "status"], "search": False},
     workflows=["Restock low items"], deps=["F8"], fr=["FR-037"], artboard="Inv-LowStock.dc.html")
page("D13", "Stock valuation report", "Inventory", "Report", "inventory.view_costs", "Quantity and weighted-average value per item by stock group, as of a date.",
     "As-of date, warehouse, items/groups segmented; paper statement; footer agreement with ledger 1301.",
     fields=["As of | date | req | ", "Warehouse | select | opt | ", "Show | segmented | opt | items/groups"], actions=["Print", "Export"],
     table={"cols": ["Code", "Item", "Quantity", "Avg cost (৳)", "Value (৳)"], "filters": ["warehouse"], "search": False},
     rules=["BR-016"], deps=["D6", "H15"], fr=["FR-037"], artboard="Inv-Valuation.dc.html")
page("D14", "Inventory summary", "Inventory", "Overview", "inventory.view", "Stock value by group, in/out flow, fastest movers, needs attention.",
     "4 tiles; group value bars; in/out monthly bars; movers table; attention list.", actions=["Transfer", "Adjust stock", "Valuation", "Low-stock report"],
     table={"cols": ["Product", "Sold", "On hand", "Days of cover", "Revenue (৳)"], "filters": [], "search": False}, deps=["D12", "D13"], fr=["FR-037"], artboard="Inv-Summary.dc.html")

# ======================================================================= E/F · Sales & purchases
page("E1", "Sales overview", "Sales", "Overview", "sales.view", "Sales this month, invoices, waiting to be paid, open quotations; monthly bars; status breakdown; recent; top products.",
     "4 tiles; 12-month bars; status stacked bar with drill buttons; recent invoices card; top products.", actions=["New quotation", "New invoice", "Drill to status tab", "View all"],
     deps=["E2", "E3", "E10"], fr=["FR-040"], artboard="Sal-Overview.dc.html")
page("E2", "Sales invoice list", "Sales", "List", "sales.view", "Every invoice with status tabs (All, Drafts, Unpaid, Overdue, Paid).",
     "Tabs with counts; search, date range, customer select; selectable table with totals; row actions print/more; pagination; empty state with Clear filters.",
     fields=["Status tab | tabs | opt | ", "Search | text | opt | invoice, customer or amount", "From / to | date | opt | ", "Customer | select | opt | "],
     actions=["New invoice", "Export", "Open", "Print (row)", "Post selected drafts (bulk, L6)"],
     table={"cols": ["Invoice", "Date", "Customer", "Due", "Status", "Amount (৳)", "Balance (৳)"], "filters": ["status", "date", "party"], "search": True},
     deps=["E3", "E5"], fr=["FR-040"], artboard="Trade.dc.html (E2)")
page("E3", "Create sales invoice", "Sales", "Form", "sales.create", "Pick a customer, add items and check server-calculated totals before saving.",
     "Banners (overdue warning, errors); header fields; items grid (item, qty, unit, price, disc %, tax, amount); Find item / Paste from sheet; notes and internal note; aside party card (balance, overdue, credit limit bar), totals, amount in words, 'When you save' effects; sticky footer.",
     fields=["Customer | select | req | active customer; quick-create allowed", "Invoice number | readonly | — | next in INV series", "Customer PO | text | opt | ",
             "Invoice date | date | req | open period (BR-004)", "Due date | date | req | >= invoice date; default from terms (BR-055)", "Payment terms | select | opt | ",
             "Dispatch from | select | req | warehouse", "Lines: item | select | req | active product/service",
             "Lines: qty | number | req | > 0; unit precision", "Lines: price | money | req | >= 0; default selling or wholesale by price tier",
             "Lines: disc % | pct | opt | 0–100", "Lines: tax | select | opt | tax rule (only when rules exist)", "Notes for the customer | textarea | opt | printed", "Internal note | textarea | opt | team only"],
     actions=["Find item", "Paste from sheet", "Add line (Ctrl Enter)", "Remove line", "Cancel (unsaved dialog)", "Save draft", "Preview", "Print", "Save invoice (Ctrl S) > Post dialog"],
     validation=["'Fix N lines to save' banner; lines with qty <= 0 marked red", "Insufficient stock per line (BR-014) returned as line errors",
                 "Credit-limit and overdue are warnings only (BR-015)", "Totals always from POST /sales-invoices/calculate (BR-010)"],
     rules=["BR-010", "BR-011", "BR-012", "BR-013", "BR-014", "BR-015", "BR-016", "BR-055", "BR-064", "BR-006", "BR-050"],
     workflows=["Bill a customer", "Sales-to-payment-to-accounting"], deps=["B2", "D2", "K6", "E4", "E5"], fr=["FR-041"], artboard="Sal-Create.dc.html")
page("E4", "Invoice preview", "Sales", "Print", "sales.view", "How the customer will see the invoice before posting.",
     "Preview badge bar (Back to edit, PDF, Print, Save and post); A4 invoice per K7 design settings.", actions=["Back to edit", "PDF", "Print", "Save and post"],
     deps=["E3", "K7"], fr=["FR-041"], artboard="Sal-Preview.dc.html")
page("E5", "Invoice details", "Sales", "Detail", "sales.view", "A posted invoice: summary strip, lines, payments, ledger entries, activity, customer, delivery, attachments.",
     "Header with More (duplicate, credit note, PDF, cancel), Print, Record payment; summary strip; tabs Invoice / Payments / Ledger entries / Activity; aside.",
     actions=["Record payment (Alt R)", "Print", "Duplicate", "Create credit note", "Download PDF", "Cancel invoice (dialog)", "Attach file"],
     table={"cols": ["#", "Item", "Code", "Qty", "Price (৳)", "Disc", "Amount (৳)"], "filters": [], "search": False},
     rules=["BR-024", "NFR-022"], deps=["E7", "E8", "Sal-DlgCancel"], fr=["FR-042"], artboard="Sal-Details.dc.html")
page("E6", "Edit draft invoice", "Sales", "Form", "sales.edit", "Change a draft; posting assigns the date and locks it.",
     "As E3 plus 'Draft, not yet posted' banner, Delete draft, Unsaved changes badge; primary 'Post invoice'.",
     fields=["Same as E3 | — | — | "], actions=["Delete draft (dialog)", "Save draft", "Preview", "Post invoice"],
     rules=["BR-025", "BR-049"], deps=["Sal-DlgDelete", "Sal-DlgPost"], fr=["FR-041", "FR-046"], artboard="Sal-Edit.dc.html")
page("E7", "Customer payment", "Sales", "Form", "sales.create", "Record money received and apply it to open invoices (oldest first).",
     "Header fields; allocation table with apply inputs and 'Re-apply oldest first'; narration; aside summary (applied, kept as advance, balance after) and posting preview; confirm dialog.",
     fields=["Received from | select | req | customer with balance", "Voucher number | readonly | — | RV series", "Date | date | req | open period", "Amount received | money | req | > 0",
             "Deposit to | select | req | money account (use_for_receipts)", "Method | segmented | req | cash, cheque, bank transfer, bKash",
             "Cheque number, cheque date, drawn on | text/date | cond | when method = cheque", "Transaction reference | text | cond | bank/bKash",
             "Apply (per open invoice) | money | opt | 0 <= apply <= balance", "Narration | textarea | opt | "],
     actions=["Re-apply oldest first", "Cancel", "Print voucher", "Save receipt (confirm dialog)"],
     validation=["'Enter an amount greater than zero'", "Apply above balance marked invalid", "Warning banner when unapplied > 0: kept as advance"],
     rules=["BR-020", "BR-021", "BR-065", "BR-004", "BR-050", "BR-053"], workflows=["Bill a customer", "Sales-to-payment-to-accounting"], deps=["E5", "B6"], fr=["FR-043"], artboard="Sal-Payment.dc.html")
page("E8", "Sales return", "Sales", "Form", "sales.create", "Goods coming back against an original invoice; creates a credit note.",
     "Header fields; items table (original, rate, return qty, reason, value); stock segmented (return to stock / write off); aside value and effect.",
     fields=["Customer | readonly | — | from invoice", "Original invoice | select | req | posted invoice of the customer", "Return number | readonly | — | SR series",
             "Date | date | req | ", "Return qty per line | number | req | 0 <= qty <= original - returned (BR-022)", "Reason per line | select | req | ", "Stock | segmented | req | restock/write off"],
     actions=["Cancel", "Save draft", "Save return and create credit note"], validation=["'Quantity too high' banner"], rules=["BR-022", "BR-021"],
     deps=["E9"], fr=["FR-044"], artboard="Sal-Return.dc.html")
page("E9", "Credit note", "Sales", "Form", "sales.create", "Post the credit and choose: reduce invoice, keep as credit, or refund.",
     "Header (party, number, date, created from, reason, amount); usage choice cards; aside effect on balances and posting preview.",
     fields=["Reason | select | req | ", "Amount | money | req | from return; editable only for price corrections", "Usage | radio cards | req | apply/credit/refund"],
     actions=["Back", "Print", "Post credit note"], rules=["BR-023", "BR-064"], deps=["E8", "E5"], fr=["FR-044"], artboard="Sal-Credit.dc.html")
page("E10", "Sales quotation", "Sales", "Form", "sales.create", "An offer to a customer or prospect; nothing posts; converts to an invoice.",
     "Invoice form variant: Valid until, Proposed terms, Terms of offer, info banner.", fields=["Customer or prospect | select | req | ", "Valid until | date | req | >= quotation date", "Lines | table | req | as E3"],
     actions=["Save draft", "Preview", "Send quotation", "Convert to invoice (accepted)"], rules=["BR-056"], deps=["E3"], fr=["FR-045"], artboard="Sal-Quote.dc.html")
page("E11", "Sales report", "Sales", "Report", "reports.view", "Gross, returns, net sales and share, grouped by customer, category or month.",
     "Date range, branch, Group by segmented; 4 tiles; table with totals; Print / Export.", fields=["From / to | date | req | ", "Branch | select | opt | ", "Group by | segmented | req | customer/category/month"],
     actions=["Print", "Export"], table={"cols": ["Customer|Category|Month", "Invoices", "Gross (৳)", "Returns (৳)", "Net (৳)", "Share"], "filters": ["date", "branch"], "search": False},
     deps=["J1"], fr=["FR-047"], artboard="Sal-Report.dc.html", note="GAP-10 salesperson")
page("E12", "Sales return report", "Sales", "Report", "reports.view", "Every return and the credit note that settled it; share by reason.",
     "Date range, customer, reason; 4 tiles; table; 'By reason' bars.", actions=["Print", "Export"],
     table={"cols": ["Return", "Date", "Customer", "Against", "Items", "Reason", "Credit note", "Status", "Value (৳)"], "filters": ["date", "party", "reason"], "search": False},
     deps=["E8"], fr=["FR-047"], artboard="Sal-ReturnReport.dc.html")
# ---- List screens for sidebar items that open only a form in the design (NEW: GAP-24, API contract review 18 Oct 2026)
page("E13", "Quotation list (NEW)", "Sales", "List", "sales.view", "Every quotation with customer, date, valid until, total and status; the entry point for the sidebar item Quotations.",
     "List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.",
     fields=["Search | text | opt | number or customer", "Status | segmented | opt | all, draft, sent, accepted, declined, expired, converted", "Date range | date | opt | "],
     actions=["New quotation", "Open", "Mark accepted / declined / expired (row)", "Convert to invoice (row, accepted)", "Print (row)", "Delete draft (row)", "Export"],
     table={"cols": ["Number", "Customer", "Date", "Valid until", "Total (৳)", "Status"], "filters": ["status", "date"], "search": True},
     rules=["BR-056", "BR-025"], deps=["E10"], fr=["FR-045"], note="GAP-24")
page("E14", "Payments received (NEW)", "Sales", "List", "sales.view", "Customer receipts (RV) with customer, account, method, amount and unapplied advance; the entry point for the sidebar item Payments received.",
     "List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.",
     fields=["Search | text | opt | voucher or customer", "Account | select | opt | money account", "Unapplied only | switch | opt | ", "Date range | date | opt | "],
     actions=["New receipt", "Open (drawer)", "Print voucher (row)", "Export"],
     table={"cols": ["Voucher", "Date", "Customer", "Account", "Method", "Amount (৳)", "Unapplied (৳)"], "filters": ["account", "unapplied", "date"], "search": True},
     rules=["BR-065", "BR-020"], deps=["E7", "B4"], fr=["FR-043"], note="GAP-24")
page("E15", "Returns and credit notes (NEW)", "Sales", "List", "sales.view", "Sales returns and credit notes in one list with type, source invoice, amount, usage and status; the entry point for the sidebar item Returns and credit notes.",
     "List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.",
     fields=["Type | segmented | opt | all, returns, credit notes", "Status | select | opt | draft, posted, cancelled", "Date range | date | opt | "],
     actions=["New return", "New credit note", "Open", "Print credit note (row)", "Delete draft (row)", "Cancel posted (row, dialog)", "Export"],
     table={"cols": ["Number", "Type", "Date", "Customer", "Invoice", "Amount (৳)", "Usage", "Status"], "filters": ["type", "status", "date"], "search": True},
     rules=["BR-022", "BR-023", "BR-025", "BR-003"], deps=["E8", "E9"], fr=["FR-044"], note="GAP-24")
for code, name, desc in [("Sal-DlgPost", "Dialog · Post invoice", "Confirms posting with customer, total and due; explains credit-note correction."),
                         ("Sal-DlgCancel", "Dialog · Cancel invoice", "Danger dialog with required reason (customer cancelled, entered by mistake, wrong customer, duplicate)."),
                         ("Sal-DlgDelete", "Dialog · Delete draft", "Danger dialog; drafts never reached the books.")]:
    page(code, name, "Sales", "Dialog", "sales.edit" if "Delete" not in name else "sales.delete", desc, "LedgerFlow Dialog with summary rows and footer Keep / Confirm.",
         actions=["Keep", "Confirm"], rules=["BR-024", "BR-025", "BR-009"], deps=["E5", "E6"], fr=["FR-041", "FR-046"], artboard=code + ".dc.html")

page("F1", "Purchase overview", "Purchases", "Overview", "purchases.view", "Purchases this month, bills, owed to suppliers, open POs; bars; status; recent; top suppliers.",
     "As E1 with supplier language.", actions=["New purchase order", "New bill"], deps=["F2", "F3", "F8"], fr=["FR-050"], artboard="Pur-Overview.dc.html")
page("F2", "Purchase bill list", "Purchases", "List", "purchases.view", "Every supplier bill with tabs All, Drafts, Unpaid, Part paid, Overdue, Paid.",
     "As E2 plus Supplier ref column.", fields=["Search | text | opt | bill, supplier or reference", "From / to | date | opt | ", "Supplier | select | opt | "],
     actions=["New bill", "Export", "Open"], table={"cols": ["Bill", "Date", "Supplier", "Supplier ref", "Due", "Status", "Amount (৳)", "Balance (৳)"], "filters": ["status", "date", "party"], "search": True},
     deps=["F3", "F4"], fr=["FR-050"], artboard="Pur-List.dc.html")
page("F3", "Create purchase bill", "Purchases", "Form", "purchases.create", "Enter the supplier's bill as received; items go into stock when posted.",
     "As E3 with 'Cost' column, 'Receive into', supplier's bill number (required) and last-cost hints; import mode adds currency, exchange rate, LC and lot (GAP-06).",
     fields=["Supplier | select | req | active supplier", "Bill number | readonly | — | BILL series", "Supplier's bill number | text | req | unique per supplier (BR-018)",
             "Bill date | date | req | open period", "Due date | date | req | >= bill date", "Payment terms | select | opt | ", "Receive into | select | req | warehouse",
             "Purchase order | select | opt | open PO of the supplier", "Currency / exchange rate | select/rate | cond | import mode; rate > 0",
             "Letter of credit / lot | select | cond | import mode", "Lines: item, qty, cost, disc %, tax | table | req | qty > 0; cost >= 0", "Notes, internal note | textarea | opt | "],
     actions=["Find item", "Add line", "Save draft", "Print", "Save bill > Post dialog"],
     validation=["Duplicate supplier bill number: 'This supplier's bill CST-0918 is already entered as BILL-2026-0219'", "Line errors as E3"],
     rules=["BR-018", "BR-019", "BR-017", "BR-010", "BR-011", "BR-004", "BR-006", "BR-050", "BR-043"], workflows=["Purchase-to-inventory-to-accounting", "Restock low items"],
     deps=["C2", "D2", "F8", "M4"], fr=["FR-051", "FR-098"], artboard="Pur-Create.dc.html")
page("F4", "Purchase bill details", "Purchases", "Detail", "purchases.view", "Bill summary, lines, payments, ledger entries, activity, receipt of goods, attachments.",
     "As E5 with 'Receipt of goods' facts and linked return.", actions=["Record payment (Alt Y)", "Print", "Create debit note", "Cancel bill (dialog)"],
     rules=["BR-024"], deps=["F5", "F6"], fr=["FR-051"], artboard="Pur-Details.dc.html")
page("F5", "Supplier payment", "Purchases", "Form", "purchases.create", "Money out to a supplier, applied to open bills.",
     "As E7 with 'Pay to', 'Pay from' (account balances shown), and confirm dialog.", fields=["Pay to | select | req | ", "Amount paid | money | req | > 0 and <= account balance (BR-031)", "Pay from | select | req | money account (use_for_payments)", "Method and cheque/reference | — | cond | as E7", "Apply per bill | money | opt | "],
     actions=["Re-apply oldest first", "Print voucher", "Save payment (confirm)"], rules=["BR-020", "BR-031", "BR-007", "BR-065", "BR-043"],
     workflows=["Pay a supplier"], deps=["F4", "C6", "G12", "Pur-DlgPay"], fr=["FR-052"], artboard="Pur-Payment.dc.html")
page("F6", "Purchase return", "Purchases", "Form", "purchases.create", "Goods going back to a supplier; creates a debit note.",
     "As E8; stock options 'Send back' / 'Already disposed'.", fields=["Original bill | select | req | ", "Return qty, reason per line | table | req | BR-022"],
     actions=["Save draft", "Save return and create debit note"], rules=["BR-022", "BR-057"], deps=["F7"], fr=["FR-053"], artboard="Pur-Return.dc.html")
page("F7", "Debit note", "Purchases", "Form", "purchases.create", "Reduce what you owe: apply to a bill, keep as credit, or ask for a refund.",
     "As E9.", fields=["Reason | select | req | ", "Amount | money | req | ", "Usage | radio cards | req | "], actions=["Back", "Print", "Post debit note"],
     rules=["BR-023", "BR-057"], deps=["F6"], fr=["FR-053"], artboard="Pur-Debit.dc.html", note="GAP-03")
page("F8", "Purchase order", "Purchases", "Form", "purchases.create", "Ask a supplier for goods; nothing posts until received.",
     "Invoice form variant with Expected delivery, Deliver to, Created from.", fields=["Supplier | select | req | ", "Order date, expected delivery | date | req/opt | ", "Deliver to | select | req | ", "Currency | select | opt | BDT default; foreign for imports", "Lines | table | req | "],
     actions=["Save draft", "Send order", "Convert to bill", "Create LC"], rules=["BR-056"], workflows=["Restock low items", "Import and LC"], deps=["D12", "F3", "M3"], fr=["FR-054"], artboard="Pur-Order.dc.html")
page("F9", "Purchase report", "Purchases", "Report", "reports.view", "Purchases and returns by supplier, category or month.", "As E11.",
     actions=["Print", "Export"], deps=["J1"], fr=["FR-055"], artboard="Pur-Report.dc.html")
page("F10", "Purchase return report", "Purchases", "Report", "reports.view", "Every return to suppliers and its debit note.", "As E12.",
     actions=["Print", "Export"], deps=["F6"], fr=["FR-055"], artboard="Pur-ReturnReport.dc.html")
# ---- (NEW: GAP-24)
page("F11", "Purchase orders (NEW)", "Purchases", "List", "purchases.view", "Every purchase order with supplier, dates, currency, total and receipt status; the entry point for the sidebar item Purchase orders.",
     "List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.",
     fields=["Search | text | opt | number or supplier", "Status | segmented | opt | all, draft, sent, partly received, received, closed, cancelled", "Date range | date | opt | "],
     actions=["New purchase order", "Open", "Convert to bill (row)", "Close or cancel (row)", "Print (row)", "Delete draft (row)", "Export"],
     table={"cols": ["Number", "Supplier", "Order date", "Expected", "Currency", "Total", "Status"], "filters": ["status", "date"], "search": True},
     rules=["BR-056", "BR-025"], deps=["F8"], fr=["FR-054"], note="GAP-24")
page("F12", "Payments made (NEW)", "Purchases", "List", "purchases.view", "Supplier payments (PV) with supplier, account, method, amount and approval status; the entry point for the sidebar item Payments made.",
     "List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.",
     fields=["Search | text | opt | voucher or supplier", "Status | select | opt | posted, awaiting approval, rejected", "Account | select | opt | ", "Date range | date | opt | "],
     actions=["New payment", "Open (drawer)", "Print voucher (row)", "Export"],
     table={"cols": ["Voucher", "Date", "Supplier", "Account", "Method", "Amount (৳)", "Status"], "filters": ["status", "account", "date"], "search": True},
     rules=["BR-065", "BR-007"], deps=["F5", "C4"], fr=["FR-052"], note="GAP-24")
page("F13", "Returns and debit notes (NEW)", "Purchases", "List", "purchases.view", "Purchase returns and debit notes in one list; the entry point for the sidebar item Returns and debit notes.",
     "List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.",
     fields=["Type | segmented | opt | all, returns, debit notes", "Status | select | opt | draft, posted, cancelled", "Date range | date | opt | "],
     actions=["New return", "New debit note", "Open", "Print debit note (row)", "Delete draft (row)", "Cancel posted (row, dialog)", "Export"],
     table={"cols": ["Number", "Type", "Date", "Supplier", "Bill", "Amount (৳)", "Usage", "Status"], "filters": ["type", "status", "date"], "search": True},
     rules=["BR-022", "BR-023", "BR-025", "BR-003", "BR-057"], deps=["F6", "F7"], fr=["FR-053"], note="GAP-24")
page("Pur-DlgPay", "Dialog · Confirm supplier payment", "Purchases", "Dialog", "purchases.create", "Summary of payment before saving.",
     "Dialog with To, Amount, Applied, From.", actions=["Cancel", "Save payment"], rules=["BR-050"], deps=["F5"], fr=["FR-052"], artboard="Pur-DlgPay.dc.html")

# ======================================================================= G · Cash and bank
page("G1", "Cash and bank overview", "Banking", "Overview", "banking.view", "Every place money sits and what moved this month.",
     "4 tiles; account buttons with balance, status and share; 30-day combined chart; quick actions; reconciliation banner; recent movements.",
     actions=["Transfer", "Money paid", "Money received", "Reconcile", "Open account"], deps=["G2", "G5", "G6", "G7", "G10"], fr=["FR-060"], artboard="Banking.dc.html (G1)")
page("G2", "Cash account details", "Banking", "Detail", "banking.view", "Cash book this month and the last cash count by denomination.",
     "Cash-book card; aside cash count (denominations, counted total, match badge) and account facts.", actions=["Cash transfer", "Count cash (records cash count; difference needs approval)"],
     fields=["Denomination counts | number | req | >= 0 integers"], rules=["BR-058"], deps=["G11"], fr=["FR-060", "FR-065"], artboard="Bnk-Cash.dc.html")
page("G3", "Bank account list", "Banking", "List", "banking.view", "Bank and wallet accounts with reconciliation status and book balance.",
     "Table with totals and actions; info banner on statement import.", actions=["Add account", "Open", "Import statement"],
     table={"cols": ["Bank", "Branch", "Account", "Type", "Reconciled to", "Status", "Book balance (৳)"], "filters": [], "search": False}, deps=["G4", "G5"], fr=["FR-060"], artboard="Bnk-Banks.dc.html")
page("G4", "Add bank account", "Banking", "Form", "banking.create", "Add a bank, mobile-wallet or card account; its ledger is created.",
     "Account type segmented; Account details card; Opening balance card; Use-for switches; aside invoice preview.",
     fields=["Account type | segmented | req | bank/wallet/card/cash credit (NEW option, R-13)", "Overdraft / cash-credit limit | money | cond | NEW (R-13); >= 0; bank and cash credit only", "Bank or provider | select | req | ", "Branch | text | opt | ", "Account name | text | req | ", "Account number | text | req | >= 10 digits for banks",
             "Routing number | text | opt | 9 digits", "Currency | readonly | — | BDT", "Opening balance | money | opt | ", "As of | date | opt | ", "Ledger | readonly | — | created under 1100",
             "Receiving customer payments / Paying suppliers / Show on invoices | switch | opt | "],
     actions=["Cancel", "Add account"], validation=["'Enter the full account number, at least 10 digits'"], rules=["BR-028"], deps=["G3"], fr=["FR-061"], artboard="Bnk-AddBank.dc.html")
page("G5", "Bank account details", "Banking", "Detail", "banking.view", "Bank book this month and statement imports.",
     "Bank-book card; aside facts and statement imports list.", actions=["Reconcile", "Import statement", "Bank book"], deps=["G10", "G12"], fr=["FR-060"], artboard="Bnk-Bank.dc.html")
for code, name, desc, kind in [("G6", "Money received", "Non-invoice money in: other income, owner or loan, or a customer (redirects to E7).", "receipt"),
                               ("G7", "Money paid", "Non-bill money out: an expense, owner or loan, or a supplier (redirects to F5).", "payment")]:
    page(code, name, "Banking", "Form", "banking.create", desc,
         "Who segmented; party/payee; category account; voucher number; date; amount; account; method; reference; narration; attachment; aside balance before/after and posting.",
         fields=["Received from / Paid to | segmented | req | customer / other / owner or loan", "Payer / Payee | text | req | ", "Income / Expense account | select | req | ledger",
                 "Date | date | req | ", "Amount | money | req | > 0" + (" and <= account balance" if kind == "payment" else ""), "Account | select | req | money account", "Method | segmented | req | ",
                 "Reference | text | opt | ", "Narration | text | opt | ", "Attachment | file | opt | JPG/PNG/PDF <= 10 MB"],
         actions=["Cancel", "Save and new", "Save"], validation=["'Enter an amount greater than zero'"],
         rules=["BR-065", "BR-031", "BR-051", "BR-004", "BR-050"], deps=["G1"], fr=["FR-062"], artboard=f"Bnk-{'Received' if kind == 'receipt' else 'Paid'}.dc.html")
for code, name, kind in [("G8", "Cash transfer", "cash"), ("G9", "Bank transfer", "bank")]:
    page(code, name, "Banking", "Form", "banking.create", "Move money between your own accounts (contra entry).",
         "From/To cards with now/after balances; amount; date; voucher number; " + ("method, bank charge, bank reference" if kind == "bank" else "handed to") + "; narration; aside contra explanation and posting.",
         fields=["From | select | req | ", "To | select | req | != From", "Amount | money | req | > 0, <= source balance", "Date | date | req | "]
                + (["Transfer method | select | opt | BEFTN, RTGS, NPSB, Cheque", "Bank charge | money | opt | >= 0; posts to Bank charges", "Bank reference | text | opt | "] if kind == "bank" else ["Handed to | text | opt | "]),
         actions=["Cancel", "Save transfer"], validation=["'More than the source account holds'"], rules=["BR-031", "BR-065"], deps=["G1"], fr=["FR-063"], artboard=f"Bnk-{'Cash' if kind == 'cash' else 'Bank'}Transfer.dc.html")
page("G10", "Bank reconciliation", "Banking", "Workspace", "banking.reconcile", "Match statement lines to the books; create missing entries; finish at zero difference.",
     "Summary strip (statement, book, matched, difference); filter segmented; accept all; import; two-column rows (statement | books) with Match / Create receipt / Record charge / Mark as pair / Undo; footer Save progress / Finish.",
     fields=["Filter | segmented | opt | to match / matched / all", "Statement file | file | cond | CSV or XLSX"],
     actions=["Import statement", "Accept all suggestions", "Match", "Create receipt", "Record charge", "Mark as pair", "Undo", "Save progress", "Finish reconciliation"],
     validation=["Finish disabled until all lines handled and difference 0 (BR-032)", "Re-import of the same file rejected"],
     rules=["BR-032", "BR-065"], workflows=["Close the month"], deps=["G5", "H15"], fr=["FR-064"], artboard="Bnk-Reconcile.dc.html")
for code, name in [("G11", "Cash book"), ("G12", "Bank book")]:
    page(code, name, "Banking", "Ledger", "banking.view", f"{name}: receipts and payments with running balance for one account.",
         "Account select, date range, every-entry / daily-totals; summary; table.", fields=["Account | select | req | ", "From / to | date | req | ", "View | segmented | opt | "],
         actions=["Open voucher", "Print", "Export"], table={"cols": ["Date", "Voucher", "Particulars", "Receipts (৳)", "Payments (৳)", "Balance (৳)"], "filters": ["account", "date"], "search": False},
         deps=["J1"], fr=["FR-060", "FR-076"], artboard=f"Bnk-{name.split()[0]}Book.dc.html")
page("G13", "Cash and bank transactions", "Banking", "List", "banking.view", "All money in, out and transfers across accounts.",
     "Search, In/Out/Transfers segmented, account select; table; pagination.", actions=["Open voucher"],
     table={"cols": ["Date", "Voucher", "Account", "Particulars", "Type", "In (৳)", "Out (৳)"], "filters": ["type", "account"], "search": True}, deps=["G1"], fr=["FR-060"], artboard="Bnk-History.dc.html")

# ======================================================================= I · Income and expenses
for code, name, kind, tmpl, perm, desc, fr in [
        ("I1", "Income overview", "income", "Overview", "banking.view", "Other income FY to date, by category and month, recent entries.", "FR-068"),
        ("I4", "Expense overview", "expense", "Overview", "banking.view", "Expenses FY to date, by category, by month against budget, missing receipts.", "FR-068")]:
    page(code, name, "IncExp", tmpl, perm, desc, "4 tiles; category bars; month bars (budget line for expenses); recent table.", actions=[f"Add {kind}", f"{kind.title()} report"],
         deps=["I2" if kind == "income" else "I5"], fr=[fr], artboard="IncExp.dc.html" if kind == "expense" else "Inc-Overview.dc.html")
for code, name, kind in [("I2", "Income transactions", "income"), ("I5", "Expense transactions", "expense")]:
    page(code, name, "IncExp", "List", "banking.view", f"Posted lines on {kind} category ledgers, whatever voucher created them (GAP-16).",
         "Search, category, date range, 'Missing receipt only'; selectable table with totals; row actions attach receipt / edit.",
         fields=["Search | text | opt | ", "Category | select | opt | ", "From / to | date | opt | ", "Missing receipt only | checkbox | opt | "],
         actions=["Attach receipt", "Open voucher", "Add " + kind],
         table={"cols": ["Date", "Number", "Description", "Category", "Paid from" if kind == "expense" else "Received into", "Receipt", "Amount (৳)"], "filters": ["category", "date", "missing_receipt"], "search": True},
         deps=["I3" if kind == "income" else "I6"], fr=["FR-068"], artboard=f"{'Inc' if kind == 'income' else 'Exp'}-List.dc.html")
for code, name, kind in [("I3", "Add income", "income"), ("I6", "Add expense", "expense")]:
    page(code, name, "IncExp", "Form", "banking.create", f"Lightweight {kind} entry with receipt attachment and optional recurrence.",
         "Fields grid; attachment drop zone; recurring switch reveals Repeats / Next on / Ends; aside summary" + (", budget bar" if kind == "expense" else "") + " and posting preview.",
         fields=["Amount | money | req | > 0", "Date | date | req | ", "Category | select | req | ", "Paid from / Received into | select | req | money account",
                 "Payment method | segmented | opt | ", ("Paid to" if kind == "expense" else "Received from") + " | text | opt | ", "Reference | text | opt | ",
                 "Tax rule | select | opt | ", "Notes | text | opt | ", "Receipt or document | file | opt | JPG/PNG/PDF <= 10 MB",
                 "Recurring | switch | opt | ", "Repeats | select | cond | weekly/monthly/quarterly/yearly", "Next on | date | cond | ", "Ends | select | cond | never/after 12/on date"],
         actions=["Cancel", "Save and add another", "Save " + kind], validation=["'Enter an amount greater than zero'", "'Choose a category'", "Budget warning at 90% (non-blocking)"],
         rules=["BR-054", "BR-048", "BR-051", "BR-031", "BR-004"], deps=["I7", "G1"], fr=["FR-066"], artboard=f"{'Inc' if kind == 'income' else 'Exp'}-Add.dc.html, Exp-AddErr.dc.html")
page("I7", "Expense categories and budgets", "IncExp", "List", "banking.view", "Categories with their ledger, monthly budget, spent, used % and status.",
     "Table with progress; totals; inline drawer New category.", fields=["Name | text | req | ", "Ledger account | select | req | new or existing expense ledger", "Monthly budget | money | opt | >= 0", "Warn me at 90% | switch | opt | "],
     actions=["New category", "Edit category", "This month / FY to date"], deps=["I6"], fr=["FR-067"], artboard="Exp-Cats.dc.html")
page("I10", "Recurring entries (NEW)", "IncExp", "List", "banking.view", "Recurring income, expense and journal templates with frequency, next run and remaining occurrences. Each run creates a draft for confirmation (BR-048).",
     "List template (NEW, GAP-25): toolbar (kind, active); DataTable with row menu.",
     fields=["Kind | segmented | opt | all, income, expense, journal", "Active | switch | opt | "],
     actions=["Pause or resume (row)", "Edit schedule (row)", "End (row)"],
     table={"cols": ["Kind", "Description", "Frequency", "Next run", "Done / ends", "Status"], "filters": ["kind", "active"], "search": False},
     rules=["BR-048"], deps=["I3", "I6"], fr=["FR-066"], note="GAP-25")
for code, name, kind in [("I8", "Income report", "income"), ("I9", "Expense report", "expense")]:
    page(code, name, "IncExp", "Report", "reports.view", f"{kind.title()} by category and month (or quarter).", "Date range, branch, by month/quarter; tiles; statement table.",
         actions=["Print", "Export"], table={"cols": ["Category", "Ledger", "Jul..Oct (৳)", "Total (৳)", "Share"], "filters": ["date", "branch"], "search": False}, deps=["J1"], fr=["FR-068"], artboard=f"{'Inc' if kind == 'income' else 'Exp'}-Report.dc.html")

# ======================================================================= H · Accounting
page("H1", "Accounting overview", "Accounting", "Overview", "accounting.view", "State of the books: totals, books-health checks, where the money sits, recent vouchers, voucher shortcuts.",
     "4 tiles; health checklist with actions (TB agrees, unreconciled lines, drafts, suspense empty, last locked period); group bars; recent vouchers; Enter-a-voucher buttons.",
     actions=["Trial balance", "Chart of accounts", "Journal voucher (Alt J)", "Payment (Alt Y)", "Receipt (Alt R)", "Contra (Alt C)"], deps=["H2", "H8", "H9", "H15"], fr=["FR-070"], artboard="Accounting.dc.html (H1)")
page("H2", "Chart of accounts", "Accounting", "Tree", "accounting.view", "Groups and ledgers with live balances; expand/collapse; trial-balance check.",
     "Search, expand all, groups only, show zero balances; tree rows with kind badge, nature, balance, open action; footer balance check.",
     fields=["Find account | text | opt | ", "Show zero balances | checkbox | opt | "], actions=["New group", "New ledger", "Export", "Open group", "Open ledger"],
     deps=["H3", "H4", "H7"], fr=["FR-071"], artboard="Acc-COA.dc.html")
page("H3", "Account group details", "Accounting", "Detail", "accounting.view", "Group balance, sub-accounts and settings, with its path in the chart.",
     "4 tiles; accounts table; aside group settings and path.", actions=["Edit group (name, parent within nature, sort)", "Ledger in this group"],
     table={"cols": ["Code", "Ledger", "Kind", "Share", "Balance (৳)"], "filters": [], "search": False}, deps=["H4"], fr=["FR-071"], artboard="Acc-Group.dc.html")
page("H4", "Add ledger account", "Accounting", "Form", "accounting.create", "Create a ledger under a group; nature and normal balance follow the group.",
     "Ledger card; Opening balance card; Behaviour card; aside 'Where it will appear'.",
     fields=["Ledger name | text | req | unique in company", "Under group | select | req | ", "Ledger code | text | opt | next free code suggested", "Nature | readonly | — | from group", "Normal balance | readonly | — | ",
             "Opening amount | money | opt | ", "Side | segmented | opt | Dr/Cr", "Track bill by bill | switch | opt | ", "Show in payment and receipt vouchers | switch | opt | ",
             "Tax rule | select | opt | ", "Description | text | opt | "],
     actions=["Cancel", "Save and add another", "Create ledger"], validation=["'Enter a ledger name'", "Code unique"], rules=["BR-027", "BR-028"], deps=["H2"], fr=["FR-071"], artboard="Acc-AddLedger.dc.html", note="GAP-21 cost centre switch hidden")
page("H5", "Edit ledger account", "Accounting", "Form", "accounting.edit", "Rename or regroup a ledger; code and opening locked once entries exist.",
     "As H4 with entries banner, FY summary aside, Make inactive.", fields=["Same as H4 | — | — | code/opening read-only when entries exist"], actions=["Cancel", "Save changes", "Make inactive"],
     rules=["BR-027", "BR-026", "BR-049"], deps=["H7"], fr=["FR-071"], artboard="Acc-EditLedger.dc.html")
page("H6", "Ledger list", "Accounting", "List", "accounting.view", "All ledgers with nature and balance; nature segmented; density.",
     "Search with '/' shortcut; nature segmented; density; table with totals.", actions=["Open", "Edit"],
     table={"cols": ["Code", "Ledger", "Nature", "Balance (৳)"], "filters": ["nature"], "search": True}, deps=["H7"], fr=["FR-071"], artboard="Acc-Ledgers.dc.html")
page("H7", "Ledger details", "Accounting", "Ledger", "accounting.view", "Entries with running balance; opening/debits/credits/closing; monthly closing balances.",
     "Date range, period segmented, show narration; summary; ledger table; aside facts and monthly bars.", fields=["From / to | date | req | ", "Period | segmented | opt | month/FY/custom", "Show narration | checkbox | opt | "],
     actions=["Open voucher", "Print", "Export"], table={"cols": ["Date", "Voucher", "Particulars", "Debit (৳)", "Credit (৳)", "Balance (৳)"], "filters": ["date"], "search": False}, deps=["H13"], fr=["FR-072"], artboard="Acc-Ledger.dc.html")
page("H8", "Voucher list", "Accounting", "List", "accounting.view", "All vouchers with type tabs, search, date range and status filter. Also serves the Day book (J1).",
     "Tabs All/Journal/Payment/Receipt/Contra; search; dates; status; selectable table; pagination.", fields=["Type tab | tabs | opt | ", "Search | text | opt | number, ledger or narration", "From / to | date | opt | ", "Status | select | opt | posted/draft/submitted/reversed"],
     actions=["Open", "Print (row)", "Duplicate (row)"], table={"cols": ["Date", "Voucher", "Type", "Ledgers", "Narration", "Status", "Amount (৳)"], "filters": ["type", "date", "status"], "search": True},
     deps=["H13"], fr=["FR-074"], artboard="Acc-Vouchers.dc.html")
page("H9", "Journal voucher", "Accounting", "Voucher", "accounting.create", "Adjustments with no cash: debit and credit lines with a live balance check.",
     "Header (number, date, reference, template); entries grid (ledger, line narration, debit, credit) with washes; 'Put difference on last line'; totals; narration; BalanceStatus; out-of-balance banner (H9 unbalanced); footer.",
     fields=["Voucher number | readonly | — | JV series", "Date | date | req | open period", "Reference | text | opt | ", "Template | select | opt | depreciation, accrue salaries, accrue interest, reclassify, blank",
             "Lines: ledger | select | req | active ledger", "Lines: debit or credit | money | req | exactly one > 0 (BR-002)", "Line narration | text | opt | ", "Narration | textarea | req | "],
     actions=["Add line", "Remove line", "Put difference on last line", "Cancel", "Save draft", "Submit for approval (above limit)", "Post voucher"],
     validation=["Post disabled while out of balance: banner shows the difference", "At least two lines", "Server re-validates BR-001"],
     rules=["BR-001", "BR-002", "BR-004", "BR-007", "BR-008", "BR-053", "BR-050"], workflows=["Close the month", "Accounting posting"], deps=["H13"], fr=["FR-073"], artboard="Acc-Journal.dc.html, Acc-JournalUnbal.dc.html")
for code, name, kind in [("H10", "Payment voucher", "payment"), ("H11", "Receipt voucher", "receipt"), ("H12", "Contra voucher", "contra")]:
    page(code, name, "Accounting", "Voucher", "accounting.create",
         {"payment": "Money out of cash or bank to one or more ledgers.", "receipt": "Money into cash or bank from one or more ledgers.", "contra": "Between your own cash and bank accounts."}[kind],
         "Header (number, date, account with current/after balance, method); lines (ledger, against reference, narration, amount); total and words; narration; generated double-entry table; footer Preview / Post.",
         fields=["Account | select | req | money account", "Lines: ledger | select | req | ", "Against | text | opt | bill/invoice reference (allocates when it matches)", "Amount | money | req | > 0", "Narration | textarea | opt | "],
         actions=["Add line", "Cancel", "Preview (H14)", "Post / Submit"], validation=["Badge 'More than the account holds' blocks (BR-031)"],
         rules=["BR-065", "BR-031", "BR-007", "BR-008", "BR-020", "BR-004"], deps=["H14"], fr=["FR-073"], artboard=f"Acc-{kind.title()}.dc.html")
page("H13", "Voucher details", "Accounting", "Detail", "accounting.view", "Summary strip, entries, narration, audit trail, linked documents.",
     "Header Reverse / More (duplicate, print, delete draft); strip; entries table; narration; aside audit trail and linked.",
     actions=["Reverse (dialog)", "Duplicate", "Print", "Approve / Reject (when pending)", "Delete (drafts only, GAP-07)"], rules=["BR-003", "BR-025"], deps=["Acc-DlgReverse", "H14"], fr=["FR-074", "FR-014"], artboard="Acc-Voucher.dc.html")
page("Acc-DlgReverse", "Dialog · Reverse voucher", "Accounting", "Dialog", "accounting.create", "Creates a reversing entry dated today (or chosen open date) with every line swapped; both linked.",
     "Info dialog with date and reason.", fields=["Reversal date | date | req | open period", "Reason | text | req | "], actions=["Cancel", "Create reversing entry"],
     rules=["BR-003", "BR-004", "BR-009"], deps=["H13"], fr=["FR-074"], artboard="Acc-DlgReverse.dc.html")
page("H14", "Printable voucher", "Accounting", "Print", "accounting.view", "A4 voucher with entries, amount in words, narration and signature boxes.",
     "Paper layout with company header, voucher meta, table, signatures.", actions=["Back", "PDF", "Print"], deps=["H13"], fr=["FR-074"], artboard="Acc-Print.dc.html")
for code, name, desc in [("H15", "Trial balance", "Closing debit and credit of every ledger (or group) as of a date, with checks."),
                         ("H16", "Profit and loss", "Revenue, COGS, gross profit, operating expenses, other income, finance costs; compared with last year."),
                         ("H17", "Balance sheet", "Assets, equity and liabilities as of a date, compared with last year end."),
                         ("H18", "Cash flow statement", "Indirect method reconciled to the cash and bank group."),
                         ("H19", "General ledger", "One block per chosen ledger: opening, entries, closing.")]:
    page(code, name, "Reports", "Statement", "reports.view", desc,
         "Toolbar (date or period, compare, detail/level, basis badge); paper statement; agreement check; aside checks or ratios; rows drill into the general ledger.",
         fields=["As of / period | date | req | ", "Compare | segmented | opt | prior/none", "Detail | segmented | opt | ledgers/totals"],
         actions=["Print", "Export (xlsx, pdf, csv)", "Drill into ledger"], rules=["BR-005", "BR-001"], workflows=["Close the month"], deps=["H7"], fr=["FR-076"], artboard=f"Rpt-{ {'H15': 'TB', 'H16': 'PL', 'H17': 'BS', 'H18': 'CF', 'H19': 'GL'}[code]}.dc.html")
page("K16", "Accounting defaults (NEW: GAP-01)", "Accounting", "Settings", "settings.edit", "Configure account mappings used by automatic postings.",
     "Settings layout: one row per mapping key with ledger picker and explanation.", fields=["Ledger per mapping key | select | req | ledger of the right nature"],
     actions=["Save changes"], validation=["Nature check per key (e.g. cogs must be an expense ledger)"], rules=["BR-064"], deps=["H2"], fr=["FR-075"], note="GAP-01")

# ======================================================================= J · Reports
page("J1", "Reports centre", "Reports", "Hub", "reports.view", "Find any report; statements, books, receivables/payables, operations. Reports read posted vouchers only.",
     "Search; sections of report buttons with module tags; reused screens open with the Reports breadcrumb.", fields=["Find a report | text | opt | "], actions=["Open report"],
     deps=["H15", "H16", "H17", "H18", "H19", "J6", "J7", "E11", "F9", "I8", "I9", "G11", "G12", "D13", "D14", "B8", "C8", "H8"], fr=["FR-076"], artboard="Statements.dc.html (J1)")
for code, name, party in [("J6", "Receivables aging", "Customer"), ("J7", "Payables aging", "Supplier")]:
    page(code, name, "Reports", "Report", "reports.view", f"What {party.lower()}s {'owe' if party == 'Customer' else 'are owed'} by days past due.",
         "Total and overdue; stacked bucket bar; search; overdue-only; table with buckets and oldest-age badge; statement action.", fields=["As of | date | req | ", "Search | text | opt | ", "Overdue only | checkbox | opt | "],
         actions=["Statement (row)", "Print", "Export"], table={"cols": [party, "Not due", "1–30", "31–60", "61–90", "90+", "Total (৳)", "Oldest"], "filters": ["overdue"], "search": True},
         deps=["B8" if party == "Customer" else "C8"], fr=["FR-076"], artboard=f"Rpt-{'AR' if party == 'Customer' else 'AP'}.dc.html")
REUSED = {"J2": ("Sales report", "E11"), "J3": ("Purchase report", "F9"), "J4": ("Income report", "I8"), "J5": ("Expense report", "I9"), "J8": ("Cash flow report", "H18"),
          "J9": ("Cash book", "G11"), "J10": ("Bank book", "G12"), "J11": ("Inventory report", "D14"), "J12": ("Stock valuation", "D13"), "J13": ("Trial balance", "H15"),
          "J14": ("Profit and loss", "H16"), "J15": ("Balance sheet", "H17"), "J16": ("General ledger", "H19"), "J17": ("Customer statement", "B8"), "J18": ("Supplier statement", "C8")}

# ======================================================================= K · Settings
K = [
 ("K1", "Company profile", "Detail", "settings.view", "Identity, registration, contact and locations.", [], ["Edit company", "Manage locations (NEW, GAP-14)"], ["FR-080"]),
 ("K2", "Edit company", "Form", "settings.edit", "Legal name, trading name, business type, trade licence, tax registration, address, phone, email, website, logo.",
  ["Legal name | text | req | ", "Trading name | text | opt | ", "Business type | select | req | ", "Trade licence number | text | opt | ", "Tax registration number | text | opt | ",
   "Registered address | text | opt | ", "Phone | text | opt | ", "Email | text | opt | 'Enter an email like name@company.com'", "Website | text | opt | ", "Logo | file | opt | PNG/SVG >= 400 px wide"], ["Replace logo", "Remove logo", "Discard", "Save changes"], ["FR-080"]),
 ("K3", "Business preferences", "Settings", "settings.edit", "Numbering series, defaults (warehouse, terms, valuation, rounding) and controls.",
  ["Prefix / next number per series | table | req | next >= last used + 1", "Default warehouse | select | opt | ", "Default payment terms | select | opt | ", "Stock valuation | select | req | weighted average (FIFO later)",
   "Rounding on invoices | select | req | ", "Allow selling below zero stock | switch | opt | ", "Require approval above limits | switch | opt | ", "Warn when voucher date > 7 days back | switch | opt | "],
  ["Discard", "Save changes"], ["FR-081"]),
 ("K4", "Financial-year settings", "Settings", "settings.view", "Current FY, open and locked periods, close year.", [], ["Lock period", "Unlock period (reason)", "Close FY (after year end)"], ["FR-082"]),
 ("K5", "Currency and region", "Settings", "settings.edit", "Base currency (locked), symbol position, decimals, negatives, grouping, date format, time zone, week start, print language.",
  ["Symbol position | segmented | req | ", "Decimal places | segmented | req | 2 or 0", "Negative amounts | segmented | req | ", "Number grouping | buttons | req | lakh/million", "Date format | select | req | ", "Week starts on | select | req | ", "Language on printed documents | segmented | req | en/bn/both"],
  ["Discard", "Save changes"], ["FR-083"]),
 ("K6", "Tax configuration", "Settings", "settings.view", "Tax rules you define (none by default) and how tax shows on documents; 'Add tax rule' drawer.",
  ["Rule name | text | req | unique", "Rate | pct | req | 0–100; entered by user", "Applies to | select | req | sales/purchases/both", "Posts to ledger | select | req | tax payable/receivable or new", "Recoverable as input credit | switch | opt | NEW (R-14); purchases only; off = tax added to cost",
   "Prices entered | segmented | req | exclude/include tax", "Show tax registration number on invoices | switch | opt | "], ["Add tax rule", "Edit / deactivate rule", "Save"], ["FR-084"]),
 ("K7", "Invoice customization", "Settings", "settings.edit", "Layout, accent colour, what shows on the invoice, footer note; live A4 preview.",
  ["Layout | buttons | req | classic/banded/minimal", "Accent colour | swatches | req | ", "Logo / words / bank / signature / tax no. | switch | opt | ", "Footer note | text | opt | <= 255"], ["Discard", "Save changes"], ["FR-085"]),
 ("K8", "User list", "List", "settings.users", "People with role, last active and status; seats used.", ["Search people | text | opt | "], ["Invite user", "Open user"], ["FR-006"]),
 ("K9", "Invite user", "Dialog", "settings.users", "Email, name, role; uses a seat; link expires in 7 days.", ["Email address | text | req | valid; not already a member", "Name | text | opt | ", "Role | select | req | "], ["Cancel", "Send invite"], ["FR-006"]),
 ("K10", "User details", "Detail", "settings.users", "Profile, role badges, 2-step status, recent activity, what the role allows; deactivate dialog.", [], ["Edit role", "Change role", "Deactivate (dialog)", "Reactivate", "Resend invite"], ["FR-008"]),
 ("K11", "Roles and permissions", "Matrix", "settings.roles", "Role chips; module × action checkbox matrix; approval limits.", ["Permission cells | checkbox | — | Owner locked", "Payments without approval up to | money | opt | ", "Journal vouchers without approval up to | money | opt | "],
  ["New role", "Duplicate role", "Discard", "Save changes"], ["FR-007"]),
 ("K12", "Notification preferences", "Settings", "auth.self", "Per event: in app, email, SMS; daily summary time.", ["Event × channel | switch | opt | ", "Daily summary time | select | opt | "], ["Save changes"], ["FR-013", "FR-086"]),
 ("K13", "Appearance", "Settings", "auth.self", "Theme, density, text size, reduce motion, sidebar collapsed.", ["Theme | buttons | req | ", "Table density | segmented | req | ", "Text size | segmented | req | "], ["Save changes"], ["FR-086"]),
 ("K14", "Security", "Settings", "auth.self", "Password, 2-step, company-wide 2-step requirement (Owner), idle sign-out, signed-in sessions.",
  ["Current / new password | password | req | ", "2-step verification | switch | opt | requires TOTP confirmation", "Require 2-step for everyone | switch | opt | Owner only", "Sign out after inactivity | select | opt | "],
  ["Change password", "Enable/disable 2-step", "Sign out (session)", "Sign out everywhere else"], ["FR-002", "FR-004", "FR-009", "FR-086"]),
 ("K15", "Backup and export", "Settings", "settings.backup", "Month-end export pack, automatic backups, history, restore (Owner, type RESTORE).",
  ["Period | select | req | ", "Format | segmented | req | xlsx/pdf/both", "Reports | checkbox | req | at least one", "Daily backup | switch | opt | ", "Keep for | select | opt | 30/90/365 days", "Type RESTORE | text | req | restore dialog"],
  ["Prepare export pack", "Download backup", "Back up now", "Restore… (dialog)"], ["FR-087"]),
]
for code, name, tmpl, perm, desc, fields, actions, fr in K:
    page(code, name, "Settings", tmpl, perm, desc, "Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar.",
         fields=fields, actions=actions, fr=fr, artboard=f"Settings.dc.html ({code})",
         rules={"K3": ["BR-006"], "K4": ["BR-033", "BR-034"], "K5": ["BR-035"], "K6": ["BR-036"], "K8": ["BR-059"], "K9": ["BR-059"], "K10": ["BR-061", "BR-060"],
                "K11": ["BR-060", "BR-007"], "K14": ["BR-061"], "K15": ["BR-062"]}.get(code, []), workflows=["Set up a business"] if code in ("K2", "K4", "K6", "K7", "K9", "K11") else [])

# ======================================================================= L · Search, alerts, states
L = [
 ("L1", "Global search results", "List", "Grouped results (parties, invoices, vouchers, ledgers, products) with type tabs; respects role.", ["FR-012"]),
 ("L2", "Command palette", "Overlay", "Ctrl K quick search and actions.", ["FR-012"]),
 ("L3", "Notifications center", "List", "Tabs All/Unread/Approvals/Alerts; grouped by day; mark all read.", ["FR-013"]),
 ("L4", "Notification detail drawer", "Drawer", "Approval drawer for a pending voucher: amount vs limit, facts, context banner, comment, Reject / Approve.", ["FR-014"]),
 ("L5", "Quick-create menu", "Overlay", "Create menu with voucher shortcuts.", ["FR-041"]),
 ("L6", "Confirmation dialog", "Dialog", "Bulk 'Post 12 draft invoices?' with list and total.", ["FR-041"]),
 ("L7", "Delete confirmation", "Dialog", "Type the customer name to delete a party with no history.", ["FR-025"]),
 ("L8", "Validation errors", "State", "Banner with role=alert, inline field errors, focus moves to first error.", []),
 ("L9", "Empty states", "State", "First use, no matches, no data in period, all done.", []),
 ("L10", "Loading states", "State", "Skeletons matching final layout; 'Still loading…' after 8 s; actions disabled.", []),
 ("L11", "Error states", "State", "Offline banner; widget-level failure with error ref; period locked; concurrent edit conflict.", []),
 ("L12", "Success and other toasts", "Toast", "Toast rules: say what happened, 5 s, errors persist, max 3, polite live region.", []),
 ("L13", "Unsaved changes", "Dialog", "Lists changed fields; Save and leave / Discard / Keep editing.", []),
 ("L14", "Access denied", "State", "403 with role explanation and 'Ask Owner for access'.", []),
 ("L15", "Not found", "State", "404 with search and links.", []),
]
for code, name, tmpl, desc, fr in L:
    page(code, name, "States", tmpl, "auth.self" if code not in ("L4",) else "accounting.approve", desc, "Shared component patterns shown in context on real pages.",
         rules={"L4": ["BR-007", "BR-008"], "L7": ["BR-026"], "L11": ["BR-004", "BR-049"], "L1": ["BR-037", "BR-052"]}.get(code, []), fr=fr, artboard=f"States.dc.html ({code})")

# ======================================================================= M · LC
page("M1", "LC dashboard", "LC", "Overview", "lc.view", "KPIs (active, commitments, outstanding, due in 30 days), alerts, 60-day deadlines, commitments by bank, pipeline, active LCs, recent activity.",
     "4 KPI blocks; 6 alert buttons; deadlines list; bank bars; stage pipeline; active table; activity list.", actions=["New LC", "Open alert", "Open LC", "All LCs"],
     table={"cols": ["LC", "Supplier", "Outstanding (US$)", "Expiry", "Status"], "filters": [], "search": False}, workflows=["Import and LC"], deps=["M2", "M4", "M8"], fr=["FR-090"], artboard="LC.dc.html (M1)")
page("M2", "LC list", "LC", "List", "lc.view", "All LCs with tabs All/Draft/Active/Closed/Expired, search, bank, supplier, needs-attention filter.",
     "Tabs; toolbar; table with totals; empty state.", fields=["Search | text | opt | LC number, supplier, goods or bank ref", "Issuing bank | select | opt | ", "Supplier | select | opt | ", "Needs attention only | checkbox | opt | "],
     actions=["New LC", "Open LC"], table={"cols": ["LC", "Supplier and goods", "Issuing bank", "Amount (US$)", "Outstanding (US$)", "Latest shipment", "Expiry", "Documents", "Status"], "filters": ["status", "bank", "supplier", "attention"], "search": True},
     deps=["M3", "M4"], fr=["FR-091"], artboard="LC-List.dc.html")
page("M3", "Create LC", "LC", "Form", "lc.create", "Six sections in any order: supplier and purchase, terms and amount, banks and dates, documents, shipment (optional), review; save draft or submit to bank.",
     "Info banner; section nav with done/error marks; section cards; sticky footer with draft number and total; Next section.",
     fields=["International supplier | select | req | supplier", "Link to | segmented | req | purchase order / intended import", "Purchase order | select | cond | open PO", "Intended import | text | cond | ",
             "Goods description | text | req | ", "Import authorization reference | text | opt | ", "LC type | select | req | ", "Payment tenor | segmented | req | sight/usance", "Usance days | number | cond | > 0",
             "Incoterm | select | req | ", "Currency | select | req | USD/EUR/CNY", "LC amount | money | req | > 0", "Tolerance | pct | opt | >= 0", "Planning exchange rate | rate | req | > 0",
             "Partial shipments / Transhipment / Confirmation | switch | opt | ", "Issuing bank | select | req | our bank account", "Advising bank | text | opt | ", "Margin deposit % | pct | opt | 0–100",
             "Margin from account | select | cond | when margin > 0", "Application date | date | opt | ", "Latest shipment date | date | opt | <= expiry", "Expiry date | date | req | > application date",
             "Presentation period | number | opt | days", "Opening status | select | req | ", "Bank reference | text | cond | required when opened", "Opening date | date | cond | ",
             "Required documents | checklist | opt | from template", "Record shipment information | switch | opt | ", "Port of loading / discharge, expected shipment, mode | — | cond | "],
     actions=["Cancel", "Save draft", "Next section", "Submit to bank", "Add document", "Change template"],
     validation=["Section marks '!' on errors; review lists missing supplier, amount, bank", "Banner 'Go back to the marked sections. You can still save a draft.'"],
     rules=["BR-039", "BR-040", "BR-006"], workflows=["Import and LC"], deps=["F8", "G4", "M6"], fr=["FR-092"], artboard="LC-Create*.dc.html")
page("M4", "LC details", "LC", "Detail", "lc.view", "One LC: status and flags, workflow milestones, summary strip, tabs Overview, Terms, Documents, Shipment, Charges and payments, Amendments, Activity.",
     "Header with LC picker and More (record payment, add charge, print, close); milestone stepper (steps can be 'Not needed'); tabs. Variants: LC-2026-016 usance due, -012 expired partly used, -018 two lots + amendments, -020 awaiting bank, -021 draft.",
     actions=["Record payment (dialog)", "Add charge (drawer)", "Record amendment", "Attach scan", "Add document", "Update document status", "Add/update shipment lot",
              "Receive goods (opens F3 in import mode)", "Allocate landed cost", "Open cost summary", "Close LC (dialog)", "Print LC summary"],
     rules=["BR-040", "BR-042", "BR-044", "BR-045", "BR-046"], workflows=["Import and LC"], deps=["M5", "M6", "M7", "LC-DlgPay", "LC-DlgClose", "LC-DrawerCharge", "F3"], fr=["FR-093", "FR-096", "FR-098"], artboard="LC-Details.dc.html + LC-Det*.dc.html (11)")
page("M5", "LC amendments", "LC", "List", "lc.view", "All amendments with filter; form to record one: LC, new expiry, new latest shipment, new amount, bank fee, other change, status; preview of changes.",
     "Filter segmented; table; form card with diff preview.", fields=["LC | select | req | active, opened", "New expiry date | date | opt | ", "New latest shipment | date | opt | <= new expiry",
                                                                    "New amount | money | opt | > 0", "Bank fee (৳) | money | opt | >= 0", "Other change | text | opt | ", "Status | segmented | req | requested/issued/accepted"],
     actions=["Clear", "Record amendment", "Open LC"], validation=["Record disabled until something changes", "Amount error when invalid"], rules=["BR-041"], deps=["M4"], fr=["FR-094"], artboard="LC-Amendments.dc.html")
page("M6", "LC documents", "LC", "Matrix", "lc.view", "Document status matrix across open LCs, follow-up list, default checklist template.",
     "KPIs; legend; LC × document-type grid; needs follow-up list; template list with Edit templates.", actions=["Open LC documents", "Edit templates (NEW editor, GAP-14)"], rules=[], deps=["M4"], fr=["FR-095"], artboard="LC-Documents.dc.html")
page("M7", "Charges and cost summary", "LC", "Report", "lc.view", "All charges with LC and paid filters; landed cost breakdown for an arrived LC with per-kg cost.",
     "KPIs; filters; charges table; landed-cost bar and rows; note 'Duties … LedgerFlow does not calculate them'.", actions=["Add charge", "Choose LC", "Allocate landed cost"],
     rules=["BR-045", "BR-047"], deps=["M4"], fr=["FR-097", "FR-098"], artboard="LC-Charges.dc.html")
page("M8", "LC reports", "LC", "Report", "lc.view", "Eight tabs: register, active and expired, outstanding commitments, upcoming expiry, document completion, amendment history, charges and payments, import cost summary.",
     "Tabs; date range; bank; segment; paper report table.", actions=["Export (xlsx, pdf)", "Open LC"], deps=["M4"], fr=["FR-099"], artboard="LC-Rpt*.dc.html (8)")
page("LC-DlgPay", "Dialog · Record LC payment", "LC", "Dialog", "lc.create", "Amount (US$), rate applied by bank, paid on, debited from; taka equivalent.",
     "Dialog.", fields=["Amount | money | req | > 0; total <= amount x (1 + tolerance)", "Rate applied by bank | rate | req | > 0", "Paid on | date | req | ", "Debited from | select | req | "],
     actions=["Cancel", "Record payment"], rules=["BR-042", "BR-043", "BR-044", "BR-050"], deps=["M4"], fr=["FR-097"], artboard="LC-DlgPay.dc.html")
page("LC-DlgClose", "Dialog · Close LC", "LC", "Dialog", "lc.edit", "Checklist of open items (supplier paid, unpaid charges, document issues, margin release).",
     "Warning dialog with checks.", fields=["Override reason | text | cond | when checks fail"], actions=["Cancel", "Close LC"], rules=["BR-046"], deps=["M4"], fr=["FR-099"], artboard="LC-DlgClose.dc.html")
page("LC-DrawerCharge", "Drawer · Add charge or import expense", "LC", "Drawer", "lc.create", "LC, type, amount, paid to, payment status, add to landed cost.",
     "Drawer.", fields=["LC | select | req | ", "Type | select | req | 13 types", "Amount (৳) | money | req | > 0", "Paid to | text | opt | ", "Payment status | segmented | req | unpaid/paid", "Paid from | select | cond | when paid", "Add to landed cost | switch | opt | not for margin"],
     actions=["Cancel", "Add charge"], rules=["BR-044", "BR-045", "BR-047"], deps=["M4"], fr=["FR-097"], artboard="LC-DrawerCharge.dc.html")


# ---- Actions added by the API contract review (18 Oct 2026). Marked NEW: the design does not show them; each needs design sign-off.
ADDED_ACTIONS = {
    "B4": ["Apply advance or credit (NEW, R-08)", "Remove allocation (NEW, R-08)"],
    "C4": ["Apply advance or credit (NEW, R-08)", "Remove allocation (NEW, R-08)"],
    "F4": ["Apply advance or credit (NEW, R-08)", "Remove allocation (NEW, R-08)"],
    "G2": ["Reverse cash-count adjustment (NEW, R-03)"],
    "M4": ["Reverse payment, charge or landed-cost allocation (NEW, R-03)", "Pay a charge (NEW, row)", "Remove attachment (NEW)"],
    "M7": ["Pay a charge (NEW, row)"],
    "S0": ["Create a company (NEW, company switcher)"],
    "K10": ["Revoke invitation (NEW)"],
    "K11": ["Delete custom role (NEW, only without members)"],
    "I6": ["Remove attachment (NEW)"],
    "B2": ["Add group inline (NEW)"], "C2": ["Add group inline (NEW)"],
    "D2": ["Add unit inline (NEW)"],
    "D3": ["Delete product (NEW, only without history)"],
    "D6": ["Delete stock group (NEW, only when unused)"],
    "D10": ["Delete draft (NEW)", "Reverse posted adjustment (NEW)"],
    "E5": ["Apply advance or credit (NEW, R-08)", "Remove allocation (NEW, R-08)", "Edit delivery details (NEW)", "E-mail to customer (NEW)"],
    "F3": ["Delete draft (NEW)"],
    "G5": ["Edit account (NEW)"],
    "M3": ["Delete draft (NEW)"],
}
# Actions the design shows (in the component description) but the inventory did not list.
DESIGN_ACTIONS = {
    "L3": ["Open notification (marks it read)", "Mark all read"],
    "L4": ["Approve", "Reject (comment required)"],
    "B3": ["Delete (L7 confirmation, only without history)"], "C3": ["Delete (L7 confirmation, only without history)"],
}
for _code, _extra in list(DESIGN_ACTIONS.items()) + list(ADDED_ACTIONS.items()):
    next(x for x in P if x["code"] == _code)["actions"].extend(_extra)
