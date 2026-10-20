"""Test cases (TC) and workflow traceability chains. IDs are referenced by 01-SRS, 09-Testing and 10-Roadmap."""

TC = []


def t(id_, suite, level, scenario, expected, refs):
    TC.append(dict(id=id_, suite=suite, level=level, scenario=scenario, expected=expected, refs=refs))


A, U, F, I, D, E2E, FE, P = "Accounting balance", "Unit", "Feature/API", "Integration", "Database", "E2E", "Frontend", "Performance"

# ---------------------------------------------------------------- authentication & authorization
S = "Authentication and authorization"
t("TC-AUTH-001", S, F, "Login with correct e-mail and password, 2FA off", "200 with access token, refresh cookie set HttpOnly+Secure+SameSite=Strict; session row created; audit 'login'", ["FR-001", "NFR-010"])
t("TC-AUTH-002", S, F, "Login with wrong password", "401 generic message; failed_login_count incremented; audit 'login_failed'; no hint whether the e-mail exists", ["FR-001", "NFR-013"])
t("TC-AUTH-003", S, F, "Sixth failed login within 15 minutes from one source", "429 with Retry-After for that source; the real user signing in from another IP still succeeds; after 50 failures in an hour the account answers 401 invalid_credentials (no 423, no account enumeration, R-12)", ["NFR-013"])
t("TC-AUTH-004", S, F, "Login with 2FA on, then /auth/two-factor with valid, invalid and recovery code", "Step 1 returns challenge only (no token); valid TOTP issues tokens; invalid 401; recovery code works once", ["FR-001", "FR-004"])
t("TC-AUTH-005", S, F, "Refresh rotation; then replay the old refresh token", "First refresh issues new token and rotates cookie; replay revokes the whole family and returns 401", ["FR-001", "NFR-010"])
t("TC-AUTH-006", S, F, "Access token expired or signature tampered", "401 token_expired / token_invalid; no data returned", ["NFR-012"])
t("TC-AUTH-007", S, F, "Valid token, X-Company-Id of a company the user is not a member of", "403 not_a_member; no data leakage", ["BR-037", "NFR-012"])
t("TC-AUTH-008", S, F, "Every protected route called without each required permission (generated from openapi x-permission)", "403 permission_denied for each; 2xx with it", ["NFR-012"])
t("TC-AUTH-009", S, F, "Fetch another company's invoice id while active in my company", "404 (never 403, never data)", ["BR-037"])
t("TC-AUTH-010", S, F, "Store keeper requests products and stock valuation", "Product payloads omit cost/value/margin; valuation endpoint 403", ["BR-052"])
t("TC-AUTH-011", S, F, "Deactivate a user while they hold a session", "Their next API call and refresh both 401; sessions revoked", ["BR-061", "FR-008"])
t("TC-AUTH-012", S, F, "Change password", "All other sessions revoked; current kept", ["BR-061"])
t("TC-AUTH-013", S, F, "Remove Owner role from the last active Owner / edit Owner role permissions", "422 last_owner / 403 system_role", ["BR-060"])
t("TC-AUTH-014", S, F, "Invite when seats are full", "409 seat_limit_reached", ["BR-059", "FR-006"])
t("TC-AUTH-015", S, F, "Branch-restricted user lists invoices and posts to another branch", "List shows only their branches; create for other branch 403", ["BR-038"])
t("TC-AUTH-016", S, F, "Password reset request for unknown and known e-mail; reuse of a used token", "Both 202; token single-use; expired after 60 min", ["FR-003"])
t("TC-AUTH-017", S, FE, "Access token is never written to localStorage/sessionStorage/cookies by the SPA", "Storage inspection after login is empty of tokens", ["NFR-014", "NFR-010"])
t("TC-AUTH-018", S, F, "Rate limit on /auth/login per IP", "429 with Retry-After after threshold", ["NFR-013"])
t("TC-AUTH-019", S, F, "Create a custom role without approval limits, then with 0.00 limits", "422 validation_failed (both limits required, R-10); with 0.00 limits a ৳ 1 payment returns 202 approval_required", ["BR-007", "BR-060"])
t("TC-AUTH-020", S, F, "PUT a versioned resource without If-Match", "428 if_match_required; nothing changed", ["BR-049"])
t("TC-AUTH-021", S, F, "Contextual permission: role with banking.create but not accounting.create creates a payment voucher, then a journal voucher", "Payment voucher 201; journal voucher 403 permission_denied (x-permission-map, R-09)", ["BR-037"])
t("TC-AUTH-022", S, F, "Logout with an expired access token and a valid refresh cookie", "204; cookie cleared; session revoked (R-36)", ["FR-002"])
t("TC-AUTH-023", S, F, "Finance manager requests GET /backups/{id}/download", "403 permission_denied; Owner gets a 5-minute signed URL (R-11)", ["BR-062"])

# ---------------------------------------------------------------- accounting balance
S = A
t("TC-ACC-001", S, U, "JournalBuilder with unequal debits and credits", "Throws UnbalancedEntry; nothing persisted", ["BR-001"])
t("TC-ACC-002", S, D, "Insert a journal line with both debit and credit > 0, or both 0", "CHECK constraint violation", ["BR-002"])
t("TC-ACC-003", S, D, "UPDATE or DELETE a posted journal line directly in SQL", "Trigger raises error; row unchanged", ["BR-003"])
t("TC-ACC-004", S, F, "Post a voucher dated in a locked period", "422 period_locked naming the period", ["BR-004", "FR-073"])
t("TC-ACC-005", S, F, "Trial balance after the full demo seed", "Total debit = total credit; suspense = 0", ["BR-001", "NFR-042"])
t("TC-ACC-006", S, F, "Draft, submitted and rejected vouchers exist; run TB, ledger, P&L", "None of their amounts appear", ["BR-005"])
t("TC-ACC-007", S, F, "Reverse a posted JV, then reverse it again", "First 201 creates swapped entry linked both ways; second 409 already_reversed", ["BR-003", "BR-009", "FR-074"])
t("TC-ACC-008", S, F, "Reverse a system entry (invoice journal) via /vouchers/{id}/reverse", "409 use_document_cancel", ["BR-003"])
t("TC-ACC-009", S, F, "Journal above Accountant limit (৳ 1,00,000)", "202 submitted; approval request created; balances unchanged until approved", ["BR-007", "FR-073"])
t("TC-ACC-010", S, F, "Submitter tries to approve own voucher", "403 self_approval_forbidden", ["BR-008", "FR-014"])
t("TC-ACC-011", S, F, "Approver approves; rejects with and without comment", "Approve posts; reject without comment 422; with comment status rejected", ["FR-014"])
t("TC-ACC-012", S, F, "Every posting service (property-based: random documents)", "Each resulting entry balances and lines satisfy BR-002", ["BR-001", "BR-002", "NFR-061"])
t("TC-ACC-013", S, F, "Edit ledger code after entries exist; regroup to a different nature", "422 code_locked; 422 nature_change_forbidden", ["BR-027"])
t("TC-ACC-014", S, F, "Party with opening balance ৳ 1,85,000 Dr", "opening_balance entry dated FY start: Dr party, Cr opening balance adjustment; opening locked after first invoice", ["BR-028"])
t("TC-ACC-015", S, F, "Lock October while September open; unlock September while October locked", "422 lock_out_of_order both ways", ["BR-033", "FR-082"])
t("TC-ACC-016", S, F, "Close FY before end date / with an open period / correctly", "422, 422, then year-end entry moves net profit to retained earnings; P&L ledgers zero in next FY", ["BR-034", "FR-082"])
t("TC-ACC-017", S, F, "Change base currency after the first entry", "422 base_currency_locked", ["BR-035"])
t("TC-ACC-018", S, F, "Voucher dated 10 days back without and with confirm_backdated", "422 backdated_confirmation_required, then 201", ["BR-053"])
t("TC-ACC-019", S, U, "Money arithmetic: 3 lines of 33.335 rounding and totals", "Each line rounded half-up to 2 dp; total = sum of rounded lines; no float used (static analysis bans float casts in Domain)", ["BR-010"])
t("TC-ACC-020", S, U, "Line calc: qty 10 x 2,340.00, 2% discount, 5% tax", "gross 23,400.00; discount 468.00; tax 1,146.60; total 24,078.60", ["BR-011"])
t("TC-ACC-021", S, U, "Invoice rounding nearest_1 on 1,18,449.60", "rounding +0.40 to rounding ledger; total 1,18,450.00", ["BR-012"])
t("TC-ACC-022", S, F, "Tax rule 7.5% on a line; no rule on another", "Tax posted to the rule's ledger only for the first line", ["BR-036", "FR-084"])
t("TC-ACC-023", S, F, "Number sequence: 50 parallel invoice creations", "50 distinct consecutive numbers, no duplicates; deleted draft number audited as voided, never reissued", ["BR-006"])
t("TC-ACC-024", S, F, "Account mapping for cogs set to an income ledger", "422 nature_mismatch", ["BR-064", "FR-075"])

# ---------------------------------------------------------------- sales
S = "Sales workflow"
t("TC-SAL-001", S, F, "Create invoice without customer, with qty 0, due before invoice date", "422 with errors.party_id, errors['lines.0.quantity'], errors.due_date", ["BR-013", "FR-041"])
t("TC-SAL-002", S, F, "POST /sales-invoices/calculate for the E3 sample", "Totals and words exactly as specified in 06 §5 example; warnings PARTY_OVERDUE", ["BR-010", "BR-011", "BR-015"])
t("TC-SAL-003", S, F, "Post invoice for 40 jars with 18 on hand, negative stock off/on", "422 insufficient_stock on line 1 / posts with negative balance when allowed", ["BR-014"])
t("TC-SAL-004", S, F, "Post the E5 sample invoice", "Journal: Dr Karim 1,18,450; Cr Sales 1,18,150; Cr Delivery income 300; Dr COGS = sum(qty x avg); Cr Inventory Tejgaon same; stock reduced; unit_cost stored", ["BR-064", "BR-016", "FR-041"])
t("TC-SAL-005", S, F, "Post the same invoice twice with the same and with a new Idempotency-Key", "Same response replayed; second key 409 already_posted; exactly one journal entry", ["BR-009", "BR-050"])
t("TC-SAL-006", S, F, "Invoice exceeding credit limit", "Posts with warning CREDIT_LIMIT_EXCEEDED in response", ["BR-015"])
t("TC-SAL-007", S, F, "Customer receipt 3,00,000 against invoices 2,74,700 and 1,18,450 (auto oldest first)", "Allocations 2,74,700 and 25,300; RV posted Dr bank Cr customer; payment statuses paid/part_paid", ["BR-020", "BR-065", "FR-043"])
t("TC-SAL-008", S, F, "Receipt with manual allocation above an invoice balance; allocation to another customer's invoice", "422 allocation_exceeds_balance; 422 foreign_document", ["BR-020", "BR-021"])
t("TC-SAL-009", S, F, "Receipt larger than all open invoices", "Excess left unallocated as advance; next invoice can apply it", ["BR-020"])
t("TC-SAL-010", S, F, "Sales return 12 of 60 packs, restock; then return 50 more", "Stock +12 at original cost (Dr Inventory Cr COGS); second 422 return_qty_exceeds", ["BR-022", "FR-044"])
t("TC-SAL-011", S, F, "Credit note usage apply / credit / refund", "Apply: allocation reduces invoice; credit: stays on account; refund: requires refund block and posts PV", ["BR-023", "FR-044"])
t("TC-SAL-012", S, F, "Cancel posted invoice with a receipt applied", "Cancellation entry reverses journal and COGS; stock back; allocation reversed so receipt becomes advance; reason stored", ["BR-024", "FR-046"])
t("TC-SAL-013", S, F, "Delete a posted invoice; delete a draft", "409 not_draft; 204 and number voided", ["BR-025", "BR-006"])
t("TC-SAL-014", S, F, "Convert accepted quotation; convert again", "Draft invoice with same lines/prices; second 409 already_converted; quotation never posts", ["BR-056", "FR-045"])
t("TC-SAL-015", S, F, "Due date default from Net 14 terms", "due = date + 14 days unless supplied", ["BR-055"])
t("TC-SAL-016", S, F, "Bulk post 12 drafts where 1 lacks stock", "11 posted, 1 failed with code; each in its own transaction", ["BR-009", "FR-041"])
t("TC-SAL-017", S, F, "Edit a draft with a stale If-Match", "409 version_conflict with current version in body", ["BR-049"])
t("TC-SAL-018", S, F, "Cancel a posted credit note applied to an invoice", "Mirror entry with an SJ voucher number; its allocations get reversed_at; invoice balance and payment status restored (R-03)", ["BR-003", "BR-020"])
t("TC-SAL-019", S, F, "Apply a ৳ 25,300 advance to a later invoice, then remove the allocation", "201 allocation, invoice part_paid; DELETE sets reversed_at, invoice unpaid, advance unapplied again (R-08)", ["BR-020", "BR-021"])
t("TC-SAL-020", S, F, "Calculate an invoice whose quantity exceeds stock, then post it", "Calculate: 200 with a warnings[] entry; post: 422 insufficient_stock", ["BR-014", "BR-015"])
t("TC-SAL-021", S, F, "Post a cash sale with receipt in the post body", "Invoice and its RV posted in one transaction; invoice paid; either both or neither exist after a forced failure (R-28)", ["BR-020", "BR-009"])

# ---------------------------------------------------------------- purchases
S = "Purchase workflow"
t("TC-PUR-001", S, F, "Bill without supplier bill number; duplicate supplier bill number", "422 required; 422 duplicate_supplier_bill naming the existing bill", ["BR-018", "FR-051"])
t("TC-PUR-002", S, F, "Post BILL with 740 jars at 846.00 into Narayanganj", "Dr Inventory NGJ 6,26,040; Cr supplier; stock +740; movement purchase_in", ["BR-019", "FR-051"])
t("TC-PUR-003", S, U, "Average cost: 100 @ 800 on hand, receive 50 @ 860", "New average 820.0000; value 1,23,000.00", ["BR-017"])
t("TC-PUR-004", S, U, "Average cost when on-hand qty is 0 or negative", "Average = incoming unit cost", ["BR-017"])
t("TC-PUR-005", S, F, "Bill line for a service item (transport)", "Debit line's expense ledger, no stock movement", ["BR-019"])
t("TC-PUR-006", S, F, "Supplier payment above Accountant payment limit", "202 submitted; approval required; bill balance unchanged", ["BR-007", "FR-052"])
t("TC-PUR-007", S, F, "Payment larger than bank book balance", "422 insufficient_funds", ["BR-031"])
t("TC-PUR-008", S, F, "Purchase return 6 jars", "Cr Inventory at original bill cost; draft debit note created; stock -6", ["BR-057", "BR-022", "FR-053"])
t("TC-PUR-009", S, F, "Bill converted from PO", "PO received_quantity updated; PO status partly_received/received", ["BR-056", "FR-054"])
t("TC-PUR-010", S, F, "Cancel posted bill whose stock was partly sold", "422 insufficient_stock unless negative allowed; otherwise reversal at bill cost", ["BR-024", "BR-014"])
t("TC-PUR-011", S, F, "Cancel a posted purchase return", "Stock restored at the return cost; its debit note cancelled in the same transaction; supplier balance restored; SJ voucher number (R-03)", ["BR-003", "BR-057"])
t("TC-PUR-012", S, F, "Post a debit note created by a purchase return", "409 created_from_return; the supplier was debited once, by the return (R-04)", ["BR-057"])

# ---------------------------------------------------------------- inventory
S = "Inventory consistency"
t("TC-INV-001", S, D, "After every feature test: SUM(movements in - out) per product/warehouse vs stock_balances", "Equal for every pair (assertion helper runs in tearDown)", ["NFR-042", "NFR-022"])
t("TC-INV-002", S, F, "Stock adjustment: counted 16 vs system 18 jars at 845", "Movement out 2; Dr Stock adjustment 1,690; Cr Inventory 1,690", ["BR-029", "FR-035"])
t("TC-INV-003", S, F, "Adjustment gain", "Dr Inventory, Cr Stock adjustment ledger", ["BR-029"])
t("TC-INV-004", S, F, "Transfer from = to; qty > available; valid transfer same ledger; different ledgers", "422; 422; no journal; Dr destination Cr source via stock in transit", ["BR-030", "FR-036"])
t("TC-INV-005", S, F, "Edit a product's on-hand quantity via PATCH", "Field ignored/422 read_only; quantities only change via documents", ["BR-003", "FR-031"])
t("TC-INV-006", S, F, "Delete a product with movements", "409 has_history; deactivate works; inactive product rejected on new invoice", ["BR-026"])
t("TC-INV-007", S, F, "Stock valuation as of date = inventory group balance in TB", "Equal to the paisa", ["BR-016", "FR-037"])

# ---------------------------------------------------------------- banking
S = "Cash and bank"
t("TC-BNK-001", S, F, "Bank transfer 1,00,000 with 50 charge", "Dr To 1,00,000; Dr Bank charges 50; Cr From 1,00,050", ["BR-065", "FR-063"])
t("TC-BNK-002", S, F, "Cash transfer above cash balance", "422 insufficient_funds", ["BR-031"])
t("TC-BNK-003", S, F, "Import the same statement file twice", "Second 409 duplicate_statement", ["FR-064"])
t("TC-BNK-004", S, F, "Finish reconciliation with an unmatched line / non-zero difference / all done", "422 lines_unhandled; 422 difference_not_zero; 200 and last_reconciled_on set", ["BR-032"])
t("TC-BNK-005", S, F, "Match one book line to two statement lines", "Second 409 (unique matched_journal_line_id)", ["BR-032"])
t("TC-BNK-006", S, F, "Cash count difference of -500", "Draft entry Dr Cash short/over, Cr Cash; requires approval before posting", ["BR-058", "FR-065"])
t("TC-BNK-007", S, F, "Expense entry with no category / zero amount / valid with attachment", "422 / 422 / Dr category ledger Cr account; attachment linked", ["BR-054", "FR-066"])
t("TC-BNK-008", S, F, "Recurring monthly expense run by scheduler", "Creates a draft on next_run_on; nothing posted; next_run_on advanced", ["BR-048"])
t("TC-BNK-009", S, F, "Upload a .exe renamed .pdf; an 11 MB PDF", "415 file_type_not_allowed; 413 file_too_large", ["BR-051", "NFR-016"])
t("TC-BNK-010", S, F, "Reverse a posted cash-count adjustment", "Mirror entry with an SJ number; cash_counts.status = reversed; book balance restored (R-03)", ["BR-058", "BR-003"])

# ---------------------------------------------------------------- LC
S = "LC and import"
t("TC-LC-001", S, F, "Submit LC without issuing bank; mark opened without bank reference", "422 issuing_bank required; 422 bank_reference required", ["BR-039", "FR-092"])
t("TC-LC-002", S, U, "LC state machine: every allowed and forbidden transition", "Allowed per 06 §12.1; others 409 invalid_transition; closed/cancelled read-only", ["BR-040"])
t("TC-LC-003", S, F, "Amendment requested then accepted", "LC unchanged while requested; on accepted expiry and shipment dates updated; changes recorded", ["BR-041", "FR-094"])
t("TC-LC-004", S, F, "Record payment US$ 38,400 at 122.10 with margin 9,33,120 applied", "amount_bdt 46,88,640.00; Dr supplier 46,88,640 (advance until the import bill); Cr LC margin 9,33,120; Cr bank 37,55,520", ["BR-042", "BR-044", "FR-097"])
t("TC-LC-005", S, F, "Payment exceeding amount x (1 + tolerance)", "422 exceeds_tolerance", ["BR-042"])
t("TC-LC-006", S, F, "Import bill US$ 38,400 booked at 121.50 and settled by an LC payment made at 122.10", "On allocation, FX loss 23,040.00 posted (Dr Exchange gain or loss, Cr supplier); supplier ledger nets to zero", ["BR-043"])
t("TC-LC-011", S, F, "Usance LC: acceptance at 119.60 then settlement at maturity at 120.40", "Acceptance: Dr supplier Cr LC acceptance liability; settlement: Dr liability Cr bank; difference to exchange gain or loss", ["BR-042", "BR-043"])
t("TC-LC-012", S, F, "Reverse a posted LC payment", "Mirror entry; margin and supplier balance restored; refused with 409 already_reversed on a second attempt (R-03)", ["BR-044", "BR-003"])
t("TC-LC-013", S, F, "Apply more margin than was deposited on the LC", "422 margin_exceeds_deposit (R-32)", ["BR-044"])
t("TC-LC-007", S, F, "Margin deposit flagged include_in_landed_cost", "422 margin_not_landed_cost (also DB CHECK)", ["BR-044"])
t("TC-LC-008", S, F, "Landed cost 2,40,000 allocated by value when 40% of goods already sold", "60% Dr Inventory (avg cost up), 40% Dr COGS; Cr Goods in transit; lines sum to total", ["BR-045", "FR-098"])
t("TC-LC-009", S, F, "Close LC with unpaid charge; with override reason", "422 listing open items; with reason 200 and audit", ["BR-046", "FR-099"])
t("TC-LC-010", S, F, "Customs duty charge", "Amount stored as entered; no calculation performed", ["BR-047"])

# ---------------------------------------------------------------- concurrency
S = "Concurrency and duplicate submission"
t("TC-CON-001", S, I, "Two users post invoices for the last 10 units simultaneously (parallel processes on MySQL)", "Exactly one succeeds; other 422 insufficient_stock; balance never negative", ["BR-014", "NFR-040"])
t("TC-CON-002", S, I, "Two receipts allocate to the same invoice simultaneously", "Sum of allocations <= invoice total; second gets 422 if exceeding", ["BR-020", "NFR-040"])
t("TC-CON-003", S, I, "Double-click Save (two identical requests, same Idempotency-Key, 5 ms apart)", "One document; second waits on lock then replays the first response", ["BR-050", "BR-009"])
t("TC-CON-004", S, I, "Kill the DB connection mid-posting", "Transaction rolled back: no partial stock movement or journal", ["NFR-040"])
t("TC-CON-005", S, I, "Deadlock probe: post bill and invoice touching the same 3 products in opposite line order", "No deadlock (locks taken in product-id order); both succeed", ["NFR-040"])
t("TC-CON-006", S, I, "Schema-driven contract test: call every operation in openapi.yaml with valid and invalid examples against the running API", "Every response (2xx and problem+json) validates against its declared schema and status list; no undeclared status or error code", ["NFR-030"])

# ---------------------------------------------------------------- database constraints
S = "Database constraints"
t("TC-DB-001", S, D, "Insert a row referencing a missing FK; delete a party with invoices", "FK violation; RESTRICT blocks delete", ["BR-026"])
t("TC-DB-002", S, D, "Duplicate (company_id, number) on sales_invoices across two companies and within one", "Allowed across companies; rejected within one", ["BR-006", "BR-037"])
t("TC-DB-003", S, D, "Posted entry with total_debit <> total_credit inserted directly", "CHECK violation", ["BR-001"])
t("TC-DB-004", S, D, "bill_allocations with both or neither target", "CHECK violation", ["BR-020"])
t("TC-DB-005", S, D, "Run migrations up, down to zero, up again on MySQL 8.0", "No errors; schema identical (schema dump diff empty)", ["NFR-060"])
t("TC-DB-006", S, D, "Static scan of migrations for FLOAT/DOUBLE", "None found", ["BR-010"])
t("TC-DB-007", S, D, "SchemaContractTest on MySQL 8.0 (backend/tests/Feature/Database)", "information_schema matches schema-contract.json; CHECK, UNIQUE and RESTRICT rules reject invalid rows", ["BR-020", "BR-030"])

# ---------------------------------------------------------------- frontend
S = "Frontend components and integration"
t("TC-FE-001", S, FE, "Form maps 422 errors to fields and the summary banner", "Each `errors` key appears under its field; banner count matches; focus on first error", ["NFR-051"])
t("TC-FE-002", S, FE, "Invoice editor calls /calculate (debounced) and never shows client-computed totals", "Mock server totals render; no arithmetic in the component (lint rule)", ["BR-010", "FR-041"])
t("TC-FE-003", S, FE, "ProtectedRoute without permission", "Renders Access denied (L14), not the page", ["NFR-012"])
t("TC-FE-004", S, FE, "Amount component in lakh grouping, negatives in parentheses, dark theme", "1,84,62,300.00; (৳ 1,25,000); contrast >= 4.5:1", ["NFR-053", "NFR-050"])
t("TC-FE-005", S, FE, "Keyboard: Ctrl K opens palette, Alt S opens new invoice, Ctrl S saves", "Handlers fire; focus managed; no conflicts", ["NFR-054"])
t("TC-FE-006", S, FE, "axe-core scan of every page story", "0 serious/critical violations", ["NFR-051"])
t("TC-FE-007", S, FE, "Responsive snapshots at 1440, 1200, 1024, 390", "Match design breakpoints (icon rail, drawer, stacked rows)", ["NFR-052"])

# ---------------------------------------------------------------- e2e
S = "End-to-end business workflows"
t("TC-E2E-001", S, E2E, "Bill a customer: login > E3 create > preview > post > E7 receipt > B6 ledger", "Ledger shows invoice and receipt; balance correct; toasts shown", ["FR-041", "FR-043", "FR-023"])
t("TC-E2E-002", S, E2E, "Pay a supplier: A6 > C7 > F4 > F5 > G12", "Bank book shows payment; bill paid", ["FR-052", "FR-060"])
t("TC-E2E-003", S, E2E, "Restock: D12 > create PO > convert to bill > post > D9", "Movements show purchase_in; low-stock row disappears", ["FR-037", "FR-054", "FR-051"])
t("TC-E2E-004", S, E2E, "Close the month: reconcile > JV > TB > P&L > BS > lock period", "Statements agree; period locked; later posting in it rejected", ["FR-064", "FR-076", "FR-082"])
t("TC-E2E-005", S, E2E, "Set up a business: create company > FY > tax rule > invoice design > invite > role", "Invited user signs in with the role's permissions", ["FR-080", "FR-084", "FR-006", "FR-007"])
t("TC-E2E-006", S, E2E, "Import: PO in USD > LC create > submit > opened > charges > shipment > payment > import bill > landed cost > close", "Inventory cost includes landed cost; LC closed; goods-in-transit ledger zero for this LC", ["FR-092", "FR-097", "FR-098", "FR-099"])
t("TC-E2E-007", S, E2E, "Find anything: Ctrl K > search invoice number > open > voucher", "Navigates correctly; sales staff never see ledger results", ["FR-012"])

# ---------------------------------------------------------------- non-functional
S = "Performance, audit, backup"
t("TC-NFR-001", S, P, "k6: 50 VUs list/detail mix on 1M journal lines", "p95 < 300 ms; error rate < 0.1%", ["NFR-001", "NFR-003"])
t("TC-NFR-002", S, P, "Trial balance and P&L for a full FY at 1M lines", "p95 < 2 s", ["NFR-001"])
t("TC-NFR-003", S, P, "Post a 50-line invoice", "p95 < 1 s", ["NFR-002"])
t("TC-NFR-004", S, F, "Every mutating endpoint writes an audit_logs row with request_id", "Generated test over openapi operations passes", ["NFR-020", "NFR-021"])
t("TC-NFR-005", S, I, "Restore drill: restore latest physical backup + binlogs to staging", "Completes < 4 h; TB equals production at the chosen time", ["NFR-030", "NFR-031"])
t("TC-NFR-006", S, F, "Company restore (Owner) with wrong confirmation text; as Finance manager", "422; 403; pre_restore snapshot created before restore", ["BR-062", "NFR-032"])
t("TC-NFR-007", S, I, "Nightly integrity job with a corrupted balance_due cache", "Job reports the mismatch and alerts", ["NFR-042"])
t("TC-NFR-008", S, I, "Contract test: implemented routes vs openapi.yaml", "No undocumented route, no unimplemented operation", ["NFR-062"])
t("TC-NFR-009", S, I, "Security headers and TLS (ZAP baseline scan on staging)", "HSTS, CSP, X-Frame-Options present; no high alerts", ["NFR-010", "NFR-015"])
t("TC-NFR-010", S, I, "Secrets scan of repo and built frontend bundle", "No keys, JWT secret or DB credentials found", ["NFR-014"])
t("TC-NFR-011", S, F, "Mobile number validation 01711-284530, 0171128453, +8801711284530", "Valid; invalid; normalised valid", ["BR-063"])

# ======================================================================= workflow traceability
WORKFLOWS = []


def W(name, desc, rows):
    WORKFLOWS.append(dict(name=name, desc=desc, rows=[dict(page=r[0], action=r[1], api=r[2], service=r[3], tables=r[4], rules=r[5], tests=r[6]) for r in rows]))


W("WF-1 Purchase to inventory to accounting", "Supplier bill received into a warehouse, stock and average cost updated, payable posted, then paid.", [
    ("F3", "Enter the supplier's bill; totals refresh", "POST /purchase-bills/calculate", "DocumentCalculator", ["products", "tax_rules"], ["BR-010", "BR-011"], ["TC-ACC-020"]),
    ("F3", "Save bill (draft)", "POST /purchase-bills", "PurchaseBillService::createDraft", ["purchase_bills", "purchase_bill_lines", "number_sequences"], ["BR-018", "BR-006"], ["TC-PUR-001", "TC-ACC-023"]),
    ("F3", "Post bill (confirm dialog)", "POST /purchase-bills/{id}/post", "PurchaseBillPostingService", ["stock_movements", "stock_balances", "journal_entries", "journal_lines"], ["BR-019", "BR-017", "BR-001", "BR-004", "BR-009"], ["TC-PUR-002", "TC-PUR-003", "TC-SAL-005"]),
    ("F5", "Record supplier payment and allocate", "POST /supplier-payments", "PartyPaymentService", ["journal_entries", "journal_lines", "payment_details", "bill_allocations"], ["BR-020", "BR-031", "BR-007"], ["TC-PUR-006", "TC-PUR-007"]),
    ("L4", "Approver approves the payment", "POST /approvals/{id}/approve", "ApprovalService", ["approval_requests", "journal_entries"], ["BR-007", "BR-008"], ["TC-ACC-010", "TC-ACC-011"]),
    ("G12", "Bank book shows the payment", "GET /money-accounts/{id}/book", "LedgerQuery", ["journal_lines"], ["BR-005"], ["TC-E2E-002"]),
])
W("WF-2 Sales to payment to accounting", "Invoice posted from stock, receivable recognised with COGS, customer pays, receipt allocated.", [
    ("E3", "Pick customer and items; totals and warnings refresh", "POST /sales-invoices/calculate", "DocumentCalculator", ["products", "parties", "stock_balances"], ["BR-010", "BR-011", "BR-015"], ["TC-SAL-002"]),
    ("E3", "Save invoice", "POST /sales-invoices", "SalesInvoiceService::createDraft", ["sales_invoices", "sales_invoice_lines", "number_sequences"], ["BR-013", "BR-006"], ["TC-SAL-001"]),
    ("Sal-DlgPost", "Post invoice", "POST /sales-invoices/{id}/post", "SalesInvoicePostingService", ["stock_movements", "stock_balances", "journal_entries", "journal_lines"], ["BR-014", "BR-016", "BR-064", "BR-001", "BR-009"], ["TC-SAL-003", "TC-SAL-004", "TC-SAL-005", "TC-CON-001"]),
    ("E7", "Record customer payment; allocate oldest first", "POST /customer-receipts", "PartyPaymentService", ["journal_entries", "journal_lines", "payment_details", "bill_allocations", "sales_invoices"], ["BR-020", "BR-021", "BR-065"], ["TC-SAL-007", "TC-SAL-008", "TC-CON-002"]),
    ("B6", "Customer ledger shows invoice and receipt", "GET /parties/{id}/ledger", "LedgerQuery", ["journal_lines", "journal_entries"], ["BR-005"], ["TC-E2E-001"]),
    ("Sal-DlgCancel", "Cancel invoice (correction path)", "POST /sales-invoices/{id}/cancel", "SalesInvoiceCancellationService", ["journal_entries", "journal_lines", "stock_movements", "bill_allocations"], ["BR-024", "BR-003"], ["TC-SAL-012"]),
])
W("WF-3 Import and LC", "Import PO financed by an LC: charges and margin, shipment, payment at bank rate, import bill, landed cost, closure.", [
    ("F8", "Create import PO in USD", "POST /purchase-orders", "PurchaseOrderService", ["purchase_orders", "purchase_order_lines"], ["BR-056"], ["TC-PUR-009"]),
    ("M3", "Create LC linked to PO; submit to bank", "POST /letters-of-credit", "LetterOfCreditService::create", ["letters_of_credit", "lc_documents", "number_sequences"], ["BR-039", "BR-006"], ["TC-LC-001"]),
    ("M4", "Mark opened with bank reference", "POST /letters-of-credit/{id}/transition", "LcStateMachine", ["letters_of_credit", "audit_logs"], ["BR-040", "BR-039"], ["TC-LC-002"]),
    ("LC-DrawerCharge", "Add margin deposit and opening commission", "POST /lc-charges", "LcChargeService", ["lc_charges", "journal_entries", "journal_lines"], ["BR-044", "BR-045"], ["TC-LC-007"]),
    ("M5", "Record and accept amendment", "PATCH /lc-amendments/{id}", "LcAmendmentService", ["lc_amendments", "lc_amendment_changes", "letters_of_credit"], ["BR-041"], ["TC-LC-003"]),
    ("LC-DlgPay", "Record payment at bank rate", "POST /letters-of-credit/{id}/payments", "LcPaymentService", ["lc_payments", "journal_entries", "journal_lines"], ["BR-042", "BR-043", "BR-044"], ["TC-LC-004", "TC-LC-005", "TC-LC-006"]),
    ("F3", "Receive goods: import bill linked to LC and lot", "POST /purchase-bills/{id}/post", "PurchaseBillPostingService", ["purchase_bills", "stock_movements", "journal_entries"], ["BR-019", "BR-043"], ["TC-PUR-002"]),
    ("M4", "Allocate landed cost", "POST /letters-of-credit/{id}/landed-cost", "LandedCostService", ["landed_cost_allocations", "landed_cost_allocation_lines", "journal_entries"], ["BR-045", "BR-017"], ["TC-LC-008"]),
    ("LC-DlgClose", "Close LC", "POST /letters-of-credit/{id}/close", "LcClosureService", ["letters_of_credit", "journal_entries"], ["BR-046", "BR-044"], ["TC-LC-009"]),
])
W("WF-4 Inventory movement", "Counts and transfers keep stock ledger, balances and inventory ledgers in step.", [
    ("D10", "Create count adjustment", "POST /stock-adjustments", "StockAdjustmentService", ["stock_adjustments", "stock_adjustment_lines"], ["BR-006"], ["TC-INV-002"]),
    ("D10", "Post adjustment", "POST /stock-adjustments/{id}/post", "StockAdjustmentPostingService", ["stock_movements", "stock_balances", "journal_entries"], ["BR-029", "BR-004"], ["TC-INV-002", "TC-INV-003", "TC-INV-001"]),
    ("D11", "Dispatch transfer", "POST /stock-transfers/{id}/dispatch", "StockTransferService", ["stock_transfers", "stock_movements", "stock_balances"], ["BR-030", "BR-014"], ["TC-INV-004"]),
    ("D11", "Receive at destination", "POST /stock-transfers/{id}/receive", "StockTransferService", ["stock_movements", "stock_balances", "journal_entries"], ["BR-030"], ["TC-INV-004"]),
    ("D13", "Valuation agrees with TB", "GET /reports/inventory/valuation", "StockValuationQuery", ["stock_movements"], ["BR-016"], ["TC-INV-007"]),
])
W("WF-5 Accounting posting and month close", "Manual vouchers, approval, reconciliation, statements and period lock.", [
    ("H9", "Create and post journal voucher", "POST /vouchers", "VoucherService", ["journal_entries", "journal_lines"], ["BR-001", "BR-002", "BR-004", "BR-007"], ["TC-ACC-001", "TC-ACC-004", "TC-ACC-009"]),
    ("H13", "Reverse a wrong voucher", "POST /vouchers/{id}/reverse", "ReversalService", ["journal_entries", "journal_lines"], ["BR-003", "BR-009"], ["TC-ACC-007"]),
    ("G10", "Reconcile bank and finish", "POST /reconciliations/{id}/finish", "ReconciliationService", ["bank_reconciliations", "bank_statement_lines"], ["BR-032"], ["TC-BNK-004"]),
    ("H15", "Check trial balance", "GET /reports/trial-balance", "TrialBalanceQuery", ["journal_lines", "ledgers"], ["BR-001", "BR-005"], ["TC-ACC-005", "TC-ACC-006"]),
    ("K4", "Lock the period", "POST /accounting-periods/{id}/lock", "PeriodService", ["accounting_periods", "company_backups"], ["BR-033"], ["TC-ACC-015"]),
])
W("WF-6 Users, roles and access", "Owner sets up access; every request is checked for membership and permission.", [
    ("K11", "Create role with permissions and limits", "POST /roles", "RoleService", ["roles", "role_permissions"], ["BR-060", "BR-007"], ["TC-AUTH-013"]),
    ("K9", "Invite a user", "POST /invitations", "InvitationService", ["user_invitations"], ["BR-059"], ["TC-AUTH-014"]),
    ("S3", "Invitee accepts", "POST /invitations/{token}/accept", "InvitationService::accept", ["users", "company_users"], ["BR-059"], ["TC-E2E-005"]),
    ("S1", "Sign in", "POST /auth/login", "AuthService", ["users", "user_sessions"], ["BR-061"], ["TC-AUTH-001", "TC-AUTH-002", "TC-AUTH-003"]),
    ("K10", "Deactivate user", "POST /users/{id}/deactivate", "MembershipService", ["company_users", "user_sessions"], ["BR-061", "BR-060"], ["TC-AUTH-011"]),
])
