"""Derived contract facts per endpoint, shared by build_docs.py (05, openapi.yaml) and validate_api.py.

  pages_of(e)      pages that load or trigger the endpoint (declared pages + page actions in actions.py)
  actions_of(e)    [(page, action text, target)] that call it
  db_ops(e)        {table: "RCUDL"} R read, C insert, U update, D delete, L SELECT ... FOR UPDATE
  error_codes(e)   application error codes it can return (errors.py)
  statuses(e)      HTTP status codes it can return
  validation(e)    request validation summary (field rules from the request schema + business rules)
"""
import json, os, re, sys
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import api as A, actions as AC, errors as ER, pages as PG, registry as R, schemas as SC

SCHEMA = json.load(open(os.path.join(HERE, "_schema.json")))
PAGES = {p["code"]: p for p in PG.P}
EP_BY_KEY = {(e["method"], e["path"]): e for e in A.EP}
ENDPOINT_RX = re.compile(r"\b(GET|POST|PUT|PATCH|DELETE) (/[^\s|→()?]*)")


def key(e):
    return f"{e['method']} {e['path']}"


def path_params(p):
    return re.findall(r"{(\w+)}", p)


# ---------------------------------------------------------------- page actions
def parse_target(t):
    eps = [(m, p) for m, p in ENDPOINT_RX.findall(t)]
    exports = re.findall(r"export:(GET) (/[^\s|→()?]*)", t)
    navs = re.findall(r"nav:([A-Za-z0-9*-]+)", t)
    dlgs = re.findall(r"dlg:([A-Za-z0-9-]+)", t)
    calls = [x for x in eps if x not in exports]
    for m, p in exports:
        e = EP_BY_KEY.get((m, p))
        if e and "format" in e["params"]:
            calls.append((m, p))  # re-called with ?format=
        else:
            calls.append(("POST", "/export-jobs"))
            calls.append(("GET", "/export-jobs/{id}"))
    return {"calls": calls, "exports": exports, "navs": navs, "dlgs": dlgs, "ui": t.startswith("ui:") or " ui:" in t or "→ ui:" in t}


ACTIONS = []  # (page, index, action text, target, parsed)
for p in PG.P:
    for i, (a, t) in enumerate(zip(p["actions"], AC.A.get(p["code"], []))):
        ACTIONS.append((p["code"], i + 1, a, t, parse_target(t)))

_by_ep = defaultdict(list)
for code, i, a, t, parsed in ACTIONS:
    for c in parsed["calls"]:
        _by_ep[c].append((code, a, t))


def actions_of(e):
    return _by_ep.get((e["method"], e["path"]), [])


def pages_of(e):
    s = [p for p in e["pages"] if p != "system"]
    for code, a, t in actions_of(e):
        if code not in s:
            s.append(code)
    return s or ["system"]


# ---------------------------------------------------------------- database operations
POSTING_ACTIONS = {"bulk-post", "post", "cancel", "reverse", "dispatch", "receive", "close", "pay", "approve", "payments", "landed-cost", "create-entry", "finish", "restore"}
POSTING_CREATES = {"/customer-receipts", "/supplier-payments", "/vouchers", "/ie-entries", "/cash-counts", "/lc-charges", "/allocations"}
LEDGER_SIDE = {"journal_entries": "C", "journal_lines": "C", "stock_movements": "C", "stock_balances": "LU", "payment_details": "C", "bill_allocations": "C",
               "number_sequences": "LU", "approval_requests": "C", "notifications": "C", "sales_invoices": "LU", "purchase_bills": "LU",
               "sales_invoice_lines": "U", "purchase_bill_lines": "U", "purchase_order_lines": "U", "purchase_orders": "U", "credit_notes": "C",
               "debit_notes": "C", "lc_charges": "U", "letters_of_credit": "LU", "landed_cost_allocation_lines": "C", "cash_count_denominations": "C",
               "landed_cost_allocations": "C", "stock_transfer_lines": "U", "sales_return_lines": "U", "purchase_return_lines": "U"}
REVERSING = {"cancel", "reverse"}
# POST to a sub-collection of a parent (e.g. /letters-of-credit/{id}/payments) creates a row of the primary table.
NESTED_CREATES = {"documents", "amendments", "shipments", "payments", "landed-cost"}
OVERRIDES = {
    ("POST", "/approvals/{id}/approve"): {"journal_entries": "LU", "journal_lines": "U"},
    ("POST", "/approvals/{id}/reject"): {"journal_entries": "U"},
    ("POST", "/vouchers/{id}/submit"): {"journal_entries": "LU", "approval_requests": "C", "notifications": "C"},
    ("POST", "/vouchers/{id}/reverse"): {"journal_entries": "LUC", "bill_allocations": "U"},
    ("DELETE", "/allocations/{id}"): {"bill_allocations": "LU", "payment_details": "U", "sales_invoices": "LU", "purchase_bills": "LU"},
    ("POST", "/auth/login"): {"users": "U", "user_sessions": "C", "audit_logs": "C", "company_users": "R"},
    ("POST", "/auth/refresh"): {"user_sessions": "LUC"},
    ("POST", "/auth/logout"): {"user_sessions": "U"},
    ("POST", "/fiscal-years/{id}/close"): {"journal_entries": "C", "journal_lines": "C", "number_sequences": "LU", "accounting_periods": "R"},
    ("POST", "/backups/{id}/restore"): {"company_backups": "C"},
    ("POST", "/invitations/{token}/accept"): {"users": "C", "company_users": "C", "company_user_branches": "C", "user_invitations": "LU"},
}
NO_AUDIT = {("POST", "/auth/refresh"), ("POST", "/notifications/{id}/read"), ("POST", "/notifications/read-all"), ("PUT", "/me/preferences"),
            ("PUT", "/me/notification-preferences")}


def is_create(e):
    return e["method"] == "POST" and not path_params(e["path"]) and not e["path"].endswith(("/calculate", "/send", "/read-all", "/revoke-others", "/bulk-post",
                                                                                            "/bulk-deactivate", "/import", "/count-sheet", "/from-low-stock",
                                                                                            "/login", "/two-factor", "/refresh", "/logout", "/forgot", "/reset"))


# Business-state conditions that a calculate/preview endpoint reports in warnings[] instead of rejecting (BR-015).
PREVIEW_AS_WARNINGS = {"insufficient_stock", "insufficient_funds", "backdated_confirmation_required", "unbalanced_entry", "charge_allocated",
                       "period_locked", "allocation_exceeds_balance", "credit_limit_exceeded"}


def is_preview(e):
    return e["path"].endswith(("/calculate", "/preview"))


def is_readonly(e):
    return e["method"] == "GET" or e["path"].endswith(("/calculate", "/preview", "/count-sheet")) or e["path"] == "/search"


def line_tables(primary):
    stem = primary[:-1] if primary.endswith("s") else primary
    return {t for t in SCHEMA if t.startswith(stem + "_") and t.endswith(("_lines", "_denominations", "_items", "_changes"))}


def db_ops(e):
    ops = {t: set("R") for t in e["tables"]}
    if is_readonly(e):
        return {t: "R" for t in e["tables"]}
    m, path = e["method"], e["path"]
    primary = e["tables"][0]
    last = path.rstrip("/").split("/")[-1]
    action = None if last.startswith("{") else last
    lines = line_tables(primary) & set(e["tables"])
    if is_create(e) or (m == "POST" and action in NESTED_CREATES):
        ops[primary] = set("C")
        for t in lines:
            ops[t] = set("C")
    elif m == "PUT":
        ops[primary] = set("U")
        for t in lines:
            ops[t] = set("DC")
    elif m == "PATCH":
        ops[primary] = set("U")
    elif m == "DELETE":
        soft = "deleted_at" in SCHEMA.get(primary, {}).get("cols", [])
        ops[primary] = set("U" if (soft or primary == "bill_allocations") else "D")
        for t in lines:
            ops[t] = set("D")
    elif m == "POST":
        ops[primary] = set("LU")
    posting = (action in POSTING_ACTIONS) or (is_create(e) and path in POSTING_CREATES) or path.endswith("/landed-cost")
    if posting:
        for t, o in LEDGER_SIDE.items():
            if t in ops and t != primary:
                ops[t] |= set(o)
        if action in REVERSING:
            for t in ("journal_entries", "bill_allocations"):
                if t in ops:
                    ops[t] |= set("U")
    for t, o in OVERRIDES.get((m, path), {}).items():
        ops[t] = set(o)
    for t, o in (e.get("ops") or {}).items():
        ops[t] = set(o)
    if (m, path) not in NO_AUDIT and not path.startswith("/auth/password"):
        ops.setdefault("audit_logs", set()).add("C")
    if e["idem"]:
        ops.setdefault("idempotency_keys", set()).update("RC")
    order = "LRCUD"
    out = {}
    for t, o in ops.items():
        if o - {"R"}:
            o = o - {"R"}  # a written table is read implicitly
        out[t] = "".join(c for c in order if c in o)
    return out


def ops_text(e):
    groups = defaultdict(list)
    names = {"L": "lock", "R": "read", "C": "insert", "U": "update", "D": "delete"}
    for t, o in db_ops(e).items():
        for c in o:
            groups[c].append(t)
    return "; ".join(f"{names[c]}: " + ", ".join(sorted(groups[c])) for c in "LCUDR" if groups[c])


# ---------------------------------------------------------------- errors and statuses
def error_codes(e):
    c = set(e["errors"])
    for r in e["rules"]:
        c.update(ER.RULE_ERRORS.get(r, []))
    if e["perm"] != "public":
        c.update(["unauthenticated", "token_expired", "token_invalid"])
        if e["perm"] != "auth":
            c.update(["permission_denied", "company_required", "not_a_member", "company_maintenance"])
    if path_params(e["path"]) and e["path"] not in ("/invitations/{token}",):
        c.add("not_found")
    if e["req"] or e["params"]:
        c.add("validation_failed")
    if e["req"] and e["req"] not in ("FileUpload", "AttachmentUpload", "StatementImport", "ProductImport"):
        c.add("malformed_json")
    if e["ifmatch"]:
        c.update(["version_conflict", "if_match_required"])
    if e["idem"]:
        c.update(["idempotency_key_reused", "idempotency_in_progress"])
    if e["perm"] == "public":
        c.add("rate_limited")
    if e["req"] in ("FileUpload", "AttachmentUpload", "StatementImport", "ProductImport"):
        c.update(["file_type_not_allowed", "file_too_large"])
    if e["method"] == "DELETE":
        c.discard("inactive_reference")
    if is_preview(e):
        c -= PREVIEW_AS_WARNINGS  # returned in warnings[] while the user edits; enforced on save/post
    c.add("server_error")
    return sorted(x for x in c if ER.E[x][0] >= 400)


def statuses(e):
    if e["codes"]:
        s = set(e["codes"])
    elif e["method"] == "GET":
        s = {200}
    elif e["method"] == "DELETE":
        s = {204}
    elif is_create(e):
        s = {201}
    else:
        s = {200}
    if any(r in ("BR-007",) for r in e["rules"]) and e["method"] == "POST" and not is_readonly(e):
        s.add(202)
    s.update(ER.E[c][0] for c in error_codes(e))
    s.discard(500)
    return sorted(s)


def success_status(e):
    return min(x for x in statuses(e) if x < 300)


# ---------------------------------------------------------------- permissions
def perm_text(e):
    if e["perm_map"]:
        return "contextual — " + "; ".join(f"{k} → `{v}`" for k, v in e["perm_map"].items())
    if e["perm"] == "public":
        return "public" + (" (refresh cookie)" if e["security"] == "refreshCookie" else "")
    if e["perm"] in ("auth", "auth.self"):
        return "any signed-in user" + (" who is an active member of the company" if e["perm"] == "auth.self" else "")
    return f"`{e['perm']}`"


# ---------------------------------------------------------------- validation summary
def _field_rule(name, spec):
    req = "!" in spec
    t = spec.replace("!", "").replace("?", "")
    nullable = "?" in spec
    if t.startswith("str:"):
        d = f"string ≤ {t[4:]}"
    elif t.startswith("enum:"):
        d = "one of " + "/".join(t[5:].split("|"))
    elif t in ("money", "qty", "cost", "rate", "pct"):
        d = {"money": "decimal string, 2 dp", "qty": "decimal string, 3 dp", "cost": "decimal string, 4 dp", "rate": "decimal string, 6 dp", "pct": "decimal string 0–100, 4 dp"}[t]
    elif t.startswith("["):
        d = "array of " + t[1:-1].replace("ref:", "")
    elif t.startswith("ref:"):
        d = t[4:]
    else:
        d = {"id": "id of a record in this company", "date": "YYYY-MM-DD", "datetime": "ISO 8601", "email": "e-mail", "ccy": "ISO 4217", "bool": "boolean",
             "int": "integer", "text": "text ≤ 65,535", "str": "string", "obj": "object", "uuid": "UUID"}.get(t, t)
    return f"`{name}`{'*' if req else ''} {d}{' (nullable)' if nullable else ''}"


def validation(e):
    out = []
    if e["req"] and e["req"] in SC.S:
        desc, props = SC.S[e["req"]]
        out.append("; ".join(_field_rule(k, v) for k, v in props.items()))
        out.append("Unknown fields are rejected (422).")
    for r in e["rules"]:
        if r in R.BR:
            out.append(f"{r}: {R.BR[r]}")
    return out


INPUT_ROOTS = sorted({e["req"] for e in A.EP if e["req"]})


def input_schema_names():
    seen, todo = set(), list(INPUT_ROOTS)
    while todo:
        n = todo.pop()
        if n in seen or n not in SC.S:
            continue
        seen.add(n)
        for v in SC.S[n][1].values():
            for ref in re.findall(r"ref:(\w+)", v):
                todo.append(ref)
    # schemas reachable from any response stay open (UserPreferences, AccountMappings, PartyBankAccount, ...)
    outs, todo = set(), [re.sub(r"Page<(.*)>", r"\1", e["resp"]) for e in A.EP if e["resp"]]
    while todo:
        n = todo.pop()
        if n in outs or n not in SC.S:
            continue
        outs.add(n)
        todo += re.findall(r"ref:(\w+)", " ".join(SC.S[n][1].values()))
    return {n for n in seen if n not in outs}
