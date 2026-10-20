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
    ("POST /me/two-factor, POST /me/two-factor/confirm", "Declare 409 `invalid_transition` (set-up while 2-step is already on, or confirm without a set-up) and 429 `rate_limited` on confirm; found while implementing 2-step in slice 1 (19 Oct 2026). Additive."),
    ("All company-scoped endpoints", "May return 503 `company_maintenance` with Retry-After while that company is restored from a backup (11 §8); previously promised in operations but missing from the contract."),
    ("GET /backups/{id}/download", "Permission `settings.restore` (Owner) instead of `settings.backup`; an Owner-only action on K15, no longer a page load (R-11)."),
    ("Database operations (second pass)", "Corrected derived writes: bulk post now posts like the single post; 13 system-entry postings lock number_sequences for the SJ number (R-06); voucher post updates rather than inserts its lines; landed cost updates stock_balances (R-02); allocations create no journal lines; quote/PO convert create the draft document. Rule A23 now enforces these invariants."),
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
    ("DEC-13 / R-25", "Sales staff and Viewer can read the dashboard's cash and P&L widgets and the financial statements. If restricted, add `reports.financial` and mask those widgets; no path changes."),
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
