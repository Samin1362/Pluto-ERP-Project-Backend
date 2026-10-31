"""Contract review of 18 Oct 2026: what changed in the API and why. Rendered into 05 section 12."""
import api as A, pages as PG

BASELINE = {"endpoints": 280, "schemas": 191, "pages": 155, "actions": 448}

REASONS = {
    "R-03": "No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03)",
    "R-07": "Sidebar list screens had no list endpoint; sales users could not see receipts without accounting.view (13 R-07)",
    "R-08": "Advances and open credits could never be applied later, and a wrong allocation could not be undone (13 R-08)",
    "R-27": "The voucher forms show a live double-entry and balance; DEC-09 makes the server authoritative (13 R-27)",
    "C-01": "Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary)",
    "C-02": "BR-025 lets drafts be deleted, but only invoices, bills, vouchers and stock documents had a delete endpoint",
    "C-03": "The approval drawer (L4) shows one request with its facts; only a list existed",
    "C-04": "B1/C1 'Mark inactive (bulk)' had no endpoint",
    "C-05": "D1 'Import' had no endpoint",
}

REMOVED = [
    ("GET", "/products/{id}/movements", "Redundant: identical to `GET /stock-movements?product_id=` (same table, same schema, same permission)."),
    ("POST", "/sales-invoices/{id}/duplicate", "Redundant and inconsistent: vouchers are duplicated on the client; duplicating now opens E3 prefilled, so no draft or number is created until the user saves."),
    ("PATCH", "/me", "No designed screen edits the user's own name or phone (K14 has none of these fields). Re-add with the screen."),
    ("POST", "/payment-terms", "No designed screen adds payment terms; the six terms are seeded and chosen in K3, B2 and C2."),
    ("PATCH", "/units/{id}", "Units are seeded and added inline in D2; no screen edits a unit."),
    ("DELETE", "/units/{id}", "As above; no screen deletes a unit."),
]
RENAMED = [
    ("GET /incexp/overview", "GET /income-expenses/overview", "Consistent resource naming (no abbreviations)"),
    ("GET /lc/dashboard", "GET /lc/overview", "Same `…/overview` pattern as the other modules"),
]
CHANGED = [
    ("POST /auth/refresh, POST /auth/logout", "Security is the refresh cookie (`refreshCookie`); logout no longer needs a valid access token (R-36)."),
    ("POST /auth/login", "A locked account answers 401 `invalid_credentials` and throttling 429 with Retry-After, instead of 423 (R-12, API part)."),
    ("POST/PUT /vouchers, /vouchers/{id}/post, /submit, /calculate", "Contextual permission: journals need accounting.*, cash vouchers banking.* or accounting.* (R-09)."),
    ("POST /approvals/{id}/approve, /reject", "Contextual: the request's required_permission, never the submitter (R-09, BR-008)."),
    ("GET /audit-logs, attachments", "Contextual: the module permission of the record they belong to (R-09)."),
    ("GET /ledgers", "`purpose` parameter; without accounting.view only ledgers for that purpose are returned (R-26)."),
    ("POST /sales-invoices/{id}/post", "Optional `receipt` posts a cash sale's receipt in the same transaction (R-28)."),
    ("POST /customer-receipts", "No approval step (receipts are outside BR-007); approval_requests removed (R-22)."),
    ("POST /purchase-returns/{id}/post, POST /debit-notes/{id}/post", "The return's entry is the debit note's entry; posting a return-based note separately is refused with `created_from_return` (R-04)."),
    ("Stock adjustment, transfer and movement endpoints", "BR-052 cost masking declared; value fields omitted without inventory.view_costs (R-24)."),
    ("POST /invitations, accept", "Branch restriction stored in `user_invitation_branches` (R-18)."),
    ("Reports with `format`", "200 returns the xlsx/pdf/csv file directly; 202 + ExportJob above 5,000 rows."),
    ("All PUT/PATCH with If-Match", "Missing If-Match is 428 `if_match_required` (RFC 6585) instead of 412; a stale version stays 409 `version_conflict` (BR-049)."),
    ("All request bodies", "Strict: unknown properties are rejected (`additionalProperties: false`)."),
    ("Party schema", "Adds `port_of_loading` and `default_purchase_ledger_id`, which PartyInput accepts but the response did not return (found by the slice-1 contract test, 19 Oct 2026). Additive."),
    ("POST /party-groups", "Idempotent (Idempotency-Key, like every other create) and audited; a name already used for that party type is a 422 on `name` (P2.1-BE-02, 22 Oct 2026). Additive."),
    ("GET /parties", "Adds the `country_code` filter so C1 can list international suppliers (P2.1-BE-01, 22 Oct 2026). Additive."),
    ("POST /me/two-factor, POST /me/two-factor/confirm", "Declare 409 `invalid_transition` (set-up while 2-step is already on, or confirm without a set-up) and 429 `rate_limited` on confirm; found while implementing 2-step in slice 1 (19 Oct 2026). Additive."),
    ("All company-scoped endpoints", "May return 503 `company_maintenance` with Retry-After while that company is restored from a backup (11 §8); previously promised in operations but missing from the contract."),
    ("GET /backups/{id}/download", "Permission `settings.restore` (Owner) instead of `settings.backup`; an Owner-only action on K15, no longer a page load (R-11)."),
    ("Database operations (second pass)", "Corrected derived writes: bulk post now posts like the single post; 13 system-entry postings lock number_sequences for the SJ number (R-06); voucher post updates rather than inserts its lines; landed cost updates stock_balances (R-02); allocations create no journal lines; quote/PO convert create the draft document. Rule A23 now enforces these invariants."),
    ("AccountGroup schema", "Adds `used_in_cash_vouchers` and `sort_order` (accepted by AccountGroupInput but not returned, so H3 could not prefill its edit form) and `balance_side` (the balance is an amount, like AccountNode); `children` are AccountNode items (P7.1-BE-01, 25 Oct 2026). Additive."),
    ("POST /supplier-payments", "Declares 422 `backdated_confirmation_required` (BR-053; the payment voucher asks for confirmation like any voucher) (P8.1-BE-01, 26 Oct 2026). Additive."),
    ("Financial reports (DEC-13 / R-25)", "TB, P&L, balance sheet, cash flow, general ledger and the aging reports need the new `reports.financial` (Owner, Finance manager, Accountant; owner decision 25 Oct 2026); Sales staff and Viewer keep `reports.view` for operational reports. H1 and J1 no longer list them as loads (H1 loads `/accounting/overview`; J1 only links to the reports) (P9.0-SETUP-01, 27 Oct 2026). Dashboard money widgets follow in P14."),
    ("GET /reports/trial-balance", "Adds the `branch_id` filter, like the P&L (P9.1-BE-01, 27 Oct 2026). Additive."),
    ("Quotation endpoints", "New 409 `already_converted` (TC-SAL-014) on convert; 409 `invalid_transition` declared on PUT, send, status and convert; 422 `inactive_reference` on create/PUT/convert (P10.1-BE-01, 28 Oct 2026). Additive."),
    ("Quotation schema, PartyInput", "Quotation returns what E10 edits (warehouse_id, payment_term_id, notes, internal_note, sent_at) and converted_invoice_number; PartyInput takes `status` active|prospect on create so E10 can quote a prospect, who becomes active when converted (P10.1-BE-01, 28 Oct 2026). Additive."),
    ("Purchase order endpoints", "PurchaseOrder returns warehouse_id, payment_term_id, exchange_rate, source_note, notes, internal_note, sent_at and the bills made from it; 409 `invalid_transition` declared on PUT, send, status and convert (P10.2-BE-01, 28 Oct 2026). Additive."),
    ("Returns and notes", "Return adds warehouse_id, notes, usage, apply_to_document_id, posted_at, cancel_reason, note_status and audit fields; Note adds its source invoice/bill and return, apply_to_document_id, refund, allocations, posted_at, cancel_reason and amount in words; not_draft / foreign_document / insufficient_stock / invalid_transition declared where they occur (P10.3-BE, 28 Oct 2026). Additive."),
    ("Purchase returns and debit notes", "not_draft / foreign_document / return_qty_exceeds / insufficient_stock / invalid_transition declared where they occur; descriptions state how lines are valued and how manual debit notes post (P10.4-BE, 28 Oct 2026). Additive."),
    ("Stock adjustments and transfers", "StockAdjustment returns warehouse_name, notes, line_count, posted_at and audit fields; StockTransfer returns both warehouse names, vehicle, driver, notes, line_count, transit_loss_value (costs only), its entries and audit fields, so D10/D11 can show and edit what they saved (P11.1-BE-02/03, 31 Oct 2026). Additive."),
    ("Cash counts and reconciliation", "CashCount returns the account name, denominations and its adjustment's number, status and approval request (G2 shows where an adjustment stands); Reconciliation returns the account name, unhandled_count and book_entries (posted book lines not yet matched, G10's right pane); POST …/lines/pair with one line marks it ignored (P12.1-BE-03/05, 31 Oct 2026). Additive."),
    ("Attachments and income/expense (P13)", "GET /attachments lists one record's files (Attachments tabs); AttachmentUpload.attachable_id is optional for a voucher still being written (pending file linked by POST /ie-entries attachment_ids, I6 Remove attachment); RecurringTemplate returns ends_after_count, ends_on, description and last_entry_id; GET /recurring-templates takes page, per_page, kind, active; GET /ie-entries meta adds total_amount. Additive (P13.1, 31 Oct 2026)."),
    ("GET /reports/income, GET /reports/expenses", "Need reports.financial instead of reports.view: they are profit-and-loss figures, which owner decision DEC-13 / C7 keeps from Sales staff and Viewer (P13.1-BE-05)."),
    ("Dashboard lenses A2–A8", "Load only `GET /dashboard/lenses/{lens}`; the overlapping report and list endpoints were removed from their page lists."),
]
KEPT_OVERLAPS = [
    ("GET /parties/{id}/ledger", "GET /ledgers/{id}/entries", "Same lines, different permission (parties.view vs accounting.view); sales users must see a customer ledger without the chart of accounts."),
    ("GET /money-accounts/{id}/book", "GET /ledgers/{id}/entries", "Same reason with banking.view."),
    ("GET /parties/outstanding", "GET /reports/receivables-aging, payables-aging", "Outstanding screens (parties.view, with actions) vs aging reports (reports.view, exportable). One query service serves both."),
    ("GET /ie-entries, GET /money-transactions", "GET /vouchers", "Filtered, permission-scoped views (banking.view) of journal lines; vouchers need accounting.view."),
    ("GET /customer-receipts, GET /supplier-payments", "GET /vouchers?entry_type=", "Same reason with sales.view and purchases.view."),
]
OPEN = [
    ("DEC-02", "Access token in memory + refresh cookie (assumed throughout the contract). If tokens move to a BFF/session cookie, `bearerAuth` changes and CSRF headers are added."),
    ("R-02 / R-13", "Per-warehouse average cost and cash-credit accounts are reflected in the schema the API reads; reverting either changes response fields, not endpoints."),
    ("R-24", "Store keeper: confirm that goods are received by purchasing (no purchases.create for the role)."),
    ("Design sign-off", "Seven NEW list/recurring screens and the NEW actions listed in 12.6 need design approval before the frontend builds them."),
]


def render(md, link):
    n_act = sum(len(p["actions"]) for p in PG.P)
    from schemas import S
    o = []
    o.append("### 12.1 Size\n")
    o.append("| | Before review | Contract |\n|---|---|---|")
    o.append(f"| Endpoints | {BASELINE['endpoints']} | {len(A.EP)} |")
    o.append(f"| Schemas | {BASELINE['schemas']} | {len(S)} |")
    o.append(f"| Pages | {BASELINE['pages']} | {len(PG.P)} |")
    o.append(f"| Page actions mapped to API or client behaviour | 0 of {BASELINE['actions']} | {n_act} of {n_act} |\n")
    added = [e for e in A.EP if e["added"]]
    o.append(f"### 12.2 Missing endpoints, now added ({len(added)})\n")
    o.append("| Endpoint | Ref | Why it was missing |\n|---|---|---|")
    for e in added:
        o.append(f"| {link(e['method'], e['path'])} | {e['added']} | {md(REASONS.get(e['added'], ''))} |")
    o.append(f"\n### 12.3 Redundant or unused endpoints, removed ({len(REMOVED)})\n")
    o.append("| Endpoint | Reason |\n|---|---|")
    for m, p, why in REMOVED:
        o.append(f"| `{m} {p}` | {md(why)} |")
    o.append("\n**Renamed:**\n")
    o.append("| Before | After | Reason |\n|---|---|---|")
    for a, b, why in RENAMED:
        o.append(f"| `{a}` | `{b}` | {why} |")
    o.append("\n### 12.4 Changed behaviour\n")
    o.append("| Endpoint(s) | Change |\n|---|---|")
    for a, b in CHANGED:
        o.append(f"| {md(a)} | {md(b)} |")
    o.append("\n### 12.5 Overlaps kept on purpose\n")
    o.append("Each pair returns similar data but serves a different permission audience. Removing either would force a broader permission on a role (07 §6). One query service backs both.\n")
    o.append("| Endpoint | Overlaps with | Why both exist |\n|---|---|---|")
    for a, b, why in KEPT_OVERLAPS:
        o.append(f"| `{a}` | `{b}` | {md(why)} |")
    o.append("\n### 12.6 UI additions that need design sign-off\n")
    new_pages = [p for p in PG.P if p["note"] in ("GAP-24", "GAP-25")]
    o.append("**New screens** (List template, no new components): " + ", ".join(f"{p['code']} {md(p['name'])}" for p in new_pages) + ".\n")
    o.append("**Actions added to designed screens** (each one needed by an endpoint that no designed control triggered):\n")
    o.append("| Page | Action | Kind |\n|---|---|---|")
    for code, acts in PG.DESIGN_ACTIONS.items():
        for a in acts:
            o.append(f"| {code} | {md(a)} | shown in the component's design description, missing from the inventory |")
    for code, acts in PG.ADDED_ACTIONS.items():
        for a in acts:
            o.append(f"| {code} | {md(a)} | NEW |")
    o.append("\n### 12.7 Open decisions that affect the contract\n")
    o.append("| Decision | Effect on the API |\n|---|---|")
    for a, b in OPEN:
        o.append(f"| {a} | {md(b)} |")
    return "\n".join(o)
