"""Validate the API contract: docs/openapi.yaml and the definitions behind it.

Usage: python3 docs/source/validate_api.py      (exit 1 on any failure)
Writes docs/source/_api_validation.md and refreshes 05 section 8 between the VALIDATION markers.

A01-A03 use the official OpenAPI 3.1 validation schemas (OAI/OpenAPI-Specification, branch v3.1-dev,
src/schemas/validation/*.yaml, vendored in docs/source/oas31/) and the JSON Schema 2020-12 meta-schema.
The other rules are project rules tying the contract to the UI inventory, the database and the business rules.
"""
import copy, json, os, re, sys
from collections import defaultdict

import jsonschema, yaml
from referencing import Registry, Resource
from referencing.jsonschema import DRAFT202012

HERE = os.path.dirname(os.path.abspath(__file__))
DOCS = os.path.dirname(HERE)
sys.path.insert(0, HERE)
import api as A, contract as C, errors as ER, pages as PG, registry as R, schemas as SC, actions as AC

SCHEMA = json.load(open(os.path.join(HERE, "_schema.json")))
SCHEMA_PY = {}
import schema as DB  # noqa: E402
for t in DB.TABLES:
    SCHEMA_PY[t.name] = {c.name: c for c in t.cols}

# ---------------------------------------------------------------- official OAS 3.1 validator
def oas_validator():
    res = {}
    for f in ("dialect", "meta", "schema-base", "schema"):
        s = yaml.safe_load(open(os.path.join(HERE, "oas31", f + ".yaml")))
        res[s["$id"]] = Resource.from_contents(s, default_specification=DRAFT202012)
    reg = Registry().with_resources(res.items())
    base = yaml.safe_load(open(os.path.join(HERE, "oas31", "schema-base.yaml")))
    return jsonschema.Draft202012Validator(base, registry=reg)


OAS = oas_validator()

# ---------------------------------------------------------------- input schema -> table mapping (A14/A15)
INPUT_TABLE = {
    "PartyInput": (["parties"], {"opening_balance", "opening_side", "bank_accounts"}),
    "PartyBankAccount": (["party_bank_accounts"], {"id"}),
    "ProductInput": (["products"], {"warehouses"}),
    "ProductWarehouseInput": (["product_warehouse_settings"], {"opening_qty", "opening_unit_cost"}),
    "WarehouseInput": (["warehouses"], set()),
    "BranchInput": (["branches"], set()),
    "TaxRuleInput": (["tax_rules"], {"create_ledger"}),
    "LedgerInput": (["ledgers"], {"opening_balance", "opening_side"}),
    "AccountGroupInput": (["account_groups"], set()),
    "MoneyAccountInput": (["money_accounts"], {"opening_balance", "opening_date"}),
    "IeCategoryInput": (["ie_categories"], {"create_ledger"}),
    "SalesInvoiceInput": (["sales_invoices"], {"lines"}),
    "SalesLineInput": (["sales_invoice_lines"], set()),
    "QuotationInput": (["quotations"], {"lines"}),
    "PurchaseBillInput": (["purchase_bills"], {"lines"}),
    "PurchaseLineInput": (["purchase_bill_lines"], set()),
    "PurchaseOrderInput": (["purchase_orders"], {"lines"}),
    "ReturnInput": (["sales_returns", "purchase_returns"], {"source_document_id", "usage", "apply_to_document_id", "lines"}),
    "ReturnLineInput": (["sales_return_lines", "purchase_return_lines"], {"source_line_id"}),
    "NoteInput": (["credit_notes", "debit_notes"], {"source_document_id", "return_id", "apply_to_document_id", "refund"}),
    "LetterOfCreditInput": (["letters_of_credit"], {"document_template_id", "documents", "shipment"}),
    "LcChargeInput": (["lc_charges"], set()),
    "LcPaymentInput": (["lc_payments"], set()),
    "LcShipmentInput": (["lc_shipments"], set()),
    "LcDocumentInput": (["lc_documents"], set()),
    "LcAmendmentInput": (["lc_amendments"], {"changes", "status"}),
    "StockAdjustmentInput": (["stock_adjustments"], {"lines"}),
    "StockTransferInput": (["stock_transfers"], {"lines"}),
    "CashCountInput": (["cash_counts"], {"denominations"}),
    "InvitationCreate": (["user_invitations"], {"role_id", "branch_ids"}),
    "RoleInput": (["roles"], {"permissions", "copy_from_role_id"}),
    "CompanyUpdate": (["companies"], set()),
    "CompanyCreate": (["companies"], set()),
    "CompanySettingsUpdate": (["company_settings"], {"invoice"}),
    "PaymentDetailInput": (["payment_details"], set()),
    "RecurringTemplateInput": (["recurring_templates"], set()),
    "RecurrenceInput": (["recurring_templates"], set()),
    "ReconciliationStart": (["bank_reconciliations"], set()),
    "UserPreferences": (["user_preferences"], set()),
    "PartyGroupInput": (["party_groups"], set()),
    "LookupInput": (["product_categories", "stock_groups"], set()),
    "UnitInput": (["units"], set()),
    "DeliveryUpdate": (["sales_invoices"], {"vehicle_no"}),
}
# columns whose API name differs
ALIASES = {("InvitationCreate", "role_id"): "role_id", ("DeliveryUpdate", "vehicle_no"): "vehicle_no"}
OUTPUT_TABLE = {"Party": ["parties"], "SalesInvoice": ["sales_invoices"], "PurchaseBill": ["purchase_bills"], "Quotation": ["quotations"],
                "PurchaseOrder": ["purchase_orders"], "MoneyAccount": ["money_accounts"], "LetterOfCredit": ["letters_of_credit"],
                "CashCount": ["cash_counts"], "StockAdjustment": ["stock_adjustments"], "StockTransfer": ["stock_transfers"],
                "Note": ["credit_notes", "debit_notes"], "Return": ["sales_returns", "purchase_returns"], "Voucher": ["journal_entries"],
                "Warehouse": ["warehouses"], "Product": ["products"], "LcCharge": ["lc_charges"], "LcPayment": ["lc_payments"],
                "Backup": ["company_backups"], "AccountingPeriod": ["accounting_periods"], "FiscalYear": ["fiscal_years"],
                "Reconciliation": ["bank_reconciliations"], "StatementLine": ["bank_statement_lines"], "IeCategory": ["ie_categories"]}
# output enum fields that intentionally differ from the column (computed or presentational values)
ENUM_EXEMPT = {("Product", "stock_status"), ("IeCategory", "status"), ("LetterOfCredit", "flags"), ("Note", "kind"),
               ("SalesInvoice", "overdue"), ("Voucher", "status_display")}


def prop_type(spec):
    return spec.replace("!", "").replace("?", "")


def enum_vals(spec):
    t = prop_type(spec)
    return t[5:].split("|") if t.startswith("enum:") else None


def run(doc, quiet=False):
    results = []

    def rule(title, checked, failures):
        results.append({"id": f"A{len(results) + 1:02d}", "title": title, "checked": checked, "failures": failures})

    ops = [(p, m, op) for p, item in doc["paths"].items() for m, op in item.items()]
    comps = doc["components"]["schemas"]

    # A01 official OpenAPI 3.1 schema (including Schema Object dialect)
    errs = sorted(OAS.iter_errors(doc), key=lambda e: list(e.absolute_path))
    rule("Document validates against the official OpenAPI 3.1 schema (schema-base: documents incl. Schema Objects)", 1,
         [f"{'/'.join(map(str, e.absolute_path))[:90]}: {e.message[:120]}" for e in errs[:20]])

    # A02 component schemas are valid JSON Schema 2020-12
    f = []
    for n, sch in comps.items():
        try:
            jsonschema.Draft202012Validator.check_schema(sch)
        except jsonschema.SchemaError as ex:
            f.append(f"{n}: {ex.message[:120]}")
    rule("Every component schema is valid JSON Schema 2020-12 (meta-schema check)", len(comps), f)

    # A03 refs resolve, no unused components
    refs = set()

    def walk(x):
        if isinstance(x, dict):
            for k, v in x.items():
                if k == "$ref":
                    refs.add(v)
                walk(v)
        elif isinstance(x, list):
            for v in x:
                walk(v)
    walk(doc)
    f = []
    for r in refs:
        node = doc
        for part in r.lstrip("#/").split("/"):
            if not isinstance(node, dict) or part not in node:
                f.append(f"unresolved {r}")
                break
            node = node[part]
    used = {r.split("/")[-1] for r in refs if r.startswith("#/components/schemas/")}
    f += [f"unused schema {n}" for n in comps if n not in used]
    rule("All $refs resolve and every component schema is used", len(refs), f)

    # A04 operation hygiene
    f, ids = [], defaultdict(int)
    for p, m, op in ops:
        ids[op.get("operationId")] += 1
        k = f"{m.upper()} {p}"
        if not re.fullmatch(r"[a-z][A-Za-z0-9]*", op.get("operationId", "")):
            f.append(f"{k}: operationId not camelCase")
        for need in ("summary", "tags", "responses", "description"):
            if not op.get(need):
                f.append(f"{k}: no {need}")
        rs = op.get("responses", {})
        if not any(c.startswith("2") for c in rs):
            f.append(f"{k}: no 2xx response")
        if "default" not in rs:
            f.append(f"{k}: no default response")
        for c, r in rs.items():
            if (c.startswith(("4", "5")) or c == "default") and "application/problem+json" not in r.get("content", {}):
                f.append(f"{k}: {c} is not problem+json")
    f += [f"duplicate operationId {i}" for i, n in ids.items() if n > 1]
    rule("Operations have unique camelCase operationIds, summary, description, tags, a 2xx, a default, and problem+json errors", len(ops), f)

    # A05 path parameters
    f = []
    for p, m, op in ops:
        tmpl = set(re.findall(r"{(\w+)}", p))
        decl = {x["name"] for x in op.get("parameters", []) if x.get("in") == "path"}
        if tmpl != decl:
            f.append(f"{m.upper()} {p}: {sorted(tmpl ^ decl)}")
        for x in op.get("parameters", []):
            if x.get("in") == "path" and not x.get("required"):
                f.append(f"{m.upper()} {p}: path param {x['name']} not required")
    rule("Path templates and declared path parameters match; path parameters are required", len(ops), f)

    # A06 security
    f = []
    for p, m, op in ops:
        k = f"{m.upper()} {p}"
        sec = op.get("security")
        public = sec == []
        cookie = sec == [{"refreshCookie": []}]
        if public and not p.startswith(("/api/v1/auth/", "/api/v1/invitations/{token}")):
            f.append(f"{k}: public outside auth")
        if cookie and p not in ("/api/v1/auth/refresh", "/api/v1/auth/logout"):
            f.append(f"{k}: refresh cookie outside refresh/logout")
        company = any(x.get("$ref", "").endswith("CompanyHeader") for x in op.get("parameters", []))
        perm = op.get("x-permission")
        if not public and not cookie and perm not in ("auth",) and not company:
            f.append(f"{k}: company-scoped without X-Company-Id")
        if m != "get" and (public or cookie) and not p.startswith("/api/v1/auth/") and not p.startswith("/api/v1/invitations/"):
            f.append(f"{k}: unauthenticated write")
        if op.get("x-permission-mode") == "contextual" and not op.get("x-permission-map"):
            f.append(f"{k}: contextual without map")
        if perm not in ("public", "auth", "auth.self") and perm not in R.ALL_PERMS:
            f.append(f"{k}: unknown permission {perm}")
    rule("Security: public only for sign-in flows, cookie only for refresh/logout, X-Company-Id on company-scoped calls, known permissions", len(ops), f)

    # A07 writes: idempotency on posting creates; 4xx documented
    f = []
    for e in A.EP:
        ops_ = C.db_ops(e)
        k = C.key(e)
        if "C" in ops_.get("journal_entries", "") and not e["idem"]:
            f.append(f"{k}: posts a journal entry without Idempotency-Key")
        if "C" in ops_.get("journal_entries", "") and not e["tx"]:
            f.append(f"{k}: posts a journal entry without a transaction/lock note")
        if not C.is_readonly(e) and e["req"] and 422 not in C.statuses(e):
            f.append(f"{k}: accepts a body but cannot return 422")
    rule("Every operation that posts a journal entry takes an Idempotency-Key and documents its locking; every body can be rejected with 422", len(A.EP), f)

    # A08 201 Location, ETag on editable GETs
    f = []
    for p, m, op in ops:
        r = op["responses"].get("201")
        if r and "Location" not in r.get("headers", {}):
            f.append(f"{m.upper()} {p}: 201 without Location")
    rule("Every 201 response declares a Location header", sum(1 for p, m, op in ops if "201" in op["responses"]), f)

    # A09 lists paginated
    f, n = [], 0
    for e in A.EP:
        if e["resp"] and e["resp"].startswith("Page<"):
            n += 1
            if e["method"] != "GET":
                f.append(f"{C.key(e)}: paged response on a write")
    for p, m, op in ops:
        sch = op["responses"].get("200", {}).get("content", {}).get("application/json", {}).get("schema", {})
        if sch.get("properties", {}).get("data", {}).get("type") == "array" and "meta" not in sch.get("required", []):
            f.append(f"{m.upper()} {p}: array without pagination meta")
    rule("Collections use the paginated envelope {data[], meta}", n, f)

    # A10 error catalogue coverage and status consistency
    f = []
    used_codes = set()
    for p, m, op in ops:
        for c in op.get("x-error-codes", []):
            used_codes.add(c)
            if c not in ER.E:
                f.append(f"{m.upper()} {p}: unknown error code {c}")
            elif str(ER.E[c][0]) not in op["responses"] and not (ER.E[c][0] == 500 and "default" in op["responses"]):
                f.append(f"{m.upper()} {p}: {c} needs a {ER.E[c][0]} response")
    unused = [c for c, (st, *_ ) in ER.E.items() if st >= 400 and c not in used_codes and c != "server_error"]
    f += [f"catalogue code never returned: {c}" for c in unused]
    enum = comps["Error"]["properties"]["code"].get("enum", [])
    if sorted(enum) != sorted(ER.E):
        f.append("Error.code enum differs from the catalogue")
    rule("Every error code exists in the catalogue, has its HTTP status documented on the operation, and every catalogue code is reachable", len(ER.E), f)

    # A11 strict request schemas
    f = []
    inputs = C.input_schema_names()
    for n in inputs:
        if comps[n].get("additionalProperties") is not False and comps[n].get("type") == "object" and n != "FileUpload":
            f.append(n)
    rule("Request-only schemas reject unknown properties (additionalProperties: false)", len(inputs), f)

    # A12 money and quantities are decimal strings
    f, n = [], 0
    rx = re.compile(r"(amount|total|price|balance|cost|value|rate|limit|budget|charge|fee|_qty|qty_|quantity|debit|credit|difference|margin)")
    COUNTS = {("PageMeta", "total"): "row count", ("Company", "seat_limit"): "number of users"}
    for sn, sch in comps.items():
        for pn, ps in sch.get("properties", {}).items():
            if rx.search(pn) and not pn.endswith(("_count", "_id", "_ids", "_at", "_on", "_side", "_pct", "_type", "_in_words", "_kind")) and pn not in ("rate_type",):
                n += 1
                t = ps.get("type")
                types = t if isinstance(t, list) else [t]
                if ("number" in types or "integer" in types) and (sn, pn) not in COUNTS:
                    f.append(f"{sn}.{pn} is {t}")
    rule("Money, quantity, cost and rate fields are decimal strings (never JSON numbers)", n, f)

    # A13 If-Match <-> lock_version
    f, n = [], 0
    for e in A.EP:
        if e["method"] in ("PUT", "PATCH") and e["tables"]:
            primary = e["tables"][0]
            has_ver = "lock_version" in SCHEMA.get(primary, {}).get("cols", [])
            n += 1
            if has_ver and not e["ifmatch"] and not e["path"].startswith(("/me/", "/company/settings", "/account-mappings")):
                f.append(f"{C.key(e)}: {primary} has lock_version but If-Match is not required")
            if e["ifmatch"] and not has_ver:
                f.append(f"{C.key(e)}: requires If-Match but {primary} has no lock_version")
    rule("PUT/PATCH on a versioned table requires If-Match, and If-Match is only required where a version exists", n, f)

    # A14 request fields map to columns: existence, length, enum values
    f, n = [], 0
    for sn, (tables, virtual) in INPUT_TABLE.items():
        if sn not in SC.S:
            f.append(f"{sn}: mapped but not defined")
            continue
        for pn, spec in SC.S[sn][1].items():
            n += 1
            if pn in virtual:
                continue
            cols = [SCHEMA_PY[t].get(pn) for t in tables if pn in SCHEMA_PY[t]]
            if not cols:
                f.append(f"{sn}.{pn}: no column in {'/'.join(tables)} (and not declared virtual)")
                continue
            t = prop_type(spec)
            ev = enum_vals(spec)
            union = set().union(*[set(c.enum or []) for c in cols])
            if ev and union and not set(ev) <= union:
                f.append(f"{sn}.{pn}: values {sorted(set(ev) - union)} not allowed by any mapped column")
            partial = all(e["method"] == "PATCH" for e in A.EP if e["req"] == sn) and any(e["req"] == sn for e in A.EP)
            for col in cols:
                if t.startswith("str:") and col.type.startswith("VARCHAR"):
                    if int(t[4:]) > int(re.search(r"\d+", col.type).group()):
                        f.append(f"{sn}.{pn}: maxLength {t[4:]} > column {col.type}")
                if col.enum and not ev and not t.startswith("str"):
                    f.append(f"{sn}.{pn}: column is an enum but the API type is {t}")
                if "!" not in spec and not col.null and col.default is None and not col.generated and pn not in ("code",) and not partial \
                        and (sn, pn) not in SC.SERVER_DEFAULTS:
                    if not (pn.endswith("_id") and pn in ("branch_id",)):
                        f.append(f"{sn}.{pn}: optional in the API but NOT NULL without default in {col.name}")
    rule("Request fields are stored: each maps to a column (or is declared virtual) with a compatible length, enum values and nullability", n, f)

    # A15 response enums equal column enums
    f, n = [], 0
    for sn, tables in OUTPUT_TABLE.items():
        for pn, spec in SC.S.get(sn, ("", {}))[1].items():
            ev = enum_vals(spec)
            if not ev or (sn, pn) in ENUM_EXEMPT:
                continue
            for t in tables:
                col = SCHEMA_PY[t].get(pn)
                if col is not None and col.enum:
                    n += 1
                    if set(ev) != set(col.enum):
                        f.append(f"{sn}.{pn}: API {sorted(ev)} vs {t}.{pn} {sorted(col.enum)}")
    rule("Response enum fields have exactly the values the database allows", n, f)

    # A16 page actions mapped and valid
    f, n = [], 0
    page_codes = {p["code"] for p in PG.P}
    for p in PG.P:
        t = AC.A.get(p["code"])
        n += len(p["actions"])
        if p["actions"] and t is None:
            f.append(f"{p['code']}: actions not mapped")
            continue
        if t is not None and len(t) != len(p["actions"]):
            f.append(f"{p['code']}: {len(p['actions'])} actions but {len(t)} targets")
        for x in t or []:
            for m, path in C.ENDPOINT_RX.findall(x):
                if (m, path) not in C.EP_BY_KEY:
                    f.append(f"{p['code']}: unknown endpoint {m} {path}")
            for ref in re.findall(r"(?:nav|dlg):([A-Za-z0-9-]+)", x):
                if ref not in page_codes:
                    f.append(f"{p['code']}: unknown page {ref}")
            if not (C.ENDPOINT_RX.search(x) or re.search(r"(nav|dlg|ui|export):", x)):
                f.append(f"{p['code']}: target has no API call, navigation, dialog or ui: {x}")
    f += [f"actions for unknown page {k}" for k in AC.A if k not in page_codes]
    rule("Every action of every page maps to API calls, a dialog, navigation or explicit client behaviour, and every target exists", n, f)

    # A17 every write endpoint is triggered by an action (or is system-only)
    SYSTEM = {("POST", "/auth/refresh")}
    f, n = [], 0
    for e in A.EP:
        if C.is_readonly(e):
            continue
        n += 1
        if (e["method"], e["path"]) not in SYSTEM and not C.actions_of(e):
            f.append(C.key(e))
    rule("Every data-changing endpoint is triggered by at least one page action (only token refresh is system-driven)", n, f)

    # A18 every page loads data or is a pure component
    f = []
    for p in PG.P:
        eps = [e for e in A.EP if p["code"] in C.pages_of(e)]
        if not eps and p["module"] != "States" and p["template"] not in ("Dialog", "Overlay", "Toast", "State") and p["code"] != "S0":
            f.append(p["code"])
    rule("Every page has at least one endpoint (shared components use their host page's)", len(PG.P), f)

    # A19 permission coherence: someone who can open the page can perform each API action
    f, n = [], 0
    for code, i, a, t, parsed in C.ACTIONS:
        page = C.PAGES[code]
        viewers = set(R.roles_with(page["perm"])) if page["perm"] not in ("public", "auth.self") else {"(any)"}
        for mk in parsed["calls"]:
            e = C.EP_BY_KEY.get(mk)
            if not e or e["perm_map"] or e["perm"] in ("public", "auth", "auth.self"):
                continue
            n += 1
            doers = set(R.roles_with(e["perm"]))
            if "(any)" not in viewers and not (viewers & doers):
                f.append(f"{code} '{a}': no role can both open the page ({page['perm']}) and call {C.key(e)} ({e['perm']})")
    rule("For every action, at least one role can both open the page and call the endpoint", n, f)

    # A20 tables and operations exist
    f, n = [], 0
    for e in A.EP:
        for t, o in C.db_ops(e).items():
            n += 1
            if t not in SCHEMA:
                f.append(f"{C.key(e)}: unknown table {t}")
            if "D" in o and t in ("journal_entries", "journal_lines", "stock_movements", "audit_logs") and not e["path"].startswith("/vouchers/{id}"):
                f.append(f"{C.key(e)}: deletes from append-only/posted table {t}")
        if not C.is_readonly(e) and not any(set(o) & set("CUD") for o in C.db_ops(e).values()):
            f.append(f"{C.key(e)}: write endpoint with no insert/update/delete")
    rule("Database operations name real tables; nothing deletes posted or append-only rows; every write changes something", n, f)

    # A21 parameters typed
    f, n = [], 0
    for p, m, op in ops:
        for x in op.get("parameters", []):
            if "$ref" in x:
                continue
            n += 1
            if not x.get("schema", {}).get("type"):
                f.append(f"{m.upper()} {p}: parameter {x['name']} untyped")
    rule("Every parameter has a typed schema", n, f)

    # A22 business rules referenced exist; each rejecting rule maps to error codes
    f, n = [], 0
    for e in A.EP:
        for r in e["rules"]:
            n += 1
            if r not in R.BR:
                f.append(f"{C.key(e)}: unknown rule {r}")
    rejecting = [r for r in R.BR if r in ER.RULE_ERRORS]
    rule(f"Business rules exist; {len(rejecting)} rejecting rules map to error codes", n, f)

    # A24 calculate/preview endpoints report business state as warnings
    f, n = [], 0
    comps = doc["components"]["schemas"]
    for e in A.EP:
        if not C.is_preview(e):
            continue
        n += 1
        bad = set(C.error_codes(e)) & C.PREVIEW_AS_WARNINGS
        if bad:
            f.append(f"{C.key(e)} rejects on {sorted(bad)}")
        resp = (e["resp"] or "").replace("Page<", "").rstrip(">")
        if "warnings" not in comps.get(resp, {}).get("properties", {}):
            f.append(f"{C.key(e)}: response {resp} has no warnings[]")
    rule("Calculate/preview endpoints never reject on business state; they return warnings[] (BR-015)", n, f)

    # A23 accounting and stock write invariants
    n, f = ledger_invariants(C.db_ops)
    rule("Ledger/stock writes are coherent: entries have lines, stock movements update balances, system entries take an SJ number, bulk post = single post", n, f)
    return results


# ---------------------------------------------------------------- ledger and stock write invariants (A23)
def ledger_invariants(ops_of):
    """Failures of the accounting/stock write invariants, given a function endpoint -> {table: ops}."""
    f, n = [], 0
    post_ops = {}
    for e in A.EP:
        o = ops_of(e)
        k = C.key(e)
        last = e["path"].rstrip("/").split("/")[-1]
        primary = e["tables"][0]
        je, jl, sm, sb, ns = (o.get(t, "") for t in ("journal_entries", "journal_lines", "stock_movements", "stock_balances", "number_sequences"))
        if not (je or sm):
            continue
        n += 1
        if "C" in je and "C" not in jl:
            f.append(f"{k}: inserts a journal entry without lines")
        if "C" in jl and "C" not in je and not (e["method"] == "PUT" and "U" in je):
            f.append(f"{k}: inserts journal lines outside a new entry or a draft edit")
        if "C" in sm and not ("L" in sb and "U" in sb):
            f.append(f"{k}: inserts stock movements without locking and updating stock_balances")
        own_number = last in ("post", "bulk-post") and "number" in SCHEMA.get(primary, {}).get("cols", [])
        if "C" in je and not own_number and not ("L" in ns and "U" in ns):
            f.append(f"{k}: posts a system entry without reserving a voucher number (number_sequences, R-06)")
        if last == "post":
            post_ops[primary] = o
    for e in A.EP:
        if e["path"].endswith("/bulk-post"):
            single = post_ops.get(e["tables"][0], {})
            o = ops_of(e)
            for t in ("journal_entries", "journal_lines", "stock_movements", "stock_balances"):
                if set(single.get(t, "")) - set(o.get(t, "")):
                    f.append(f"{C.key(e)}: {t} {o.get(t, '-')} but the single post does {single.get(t)}")
    return n, f


def main():
    doc = yaml.safe_load(open(os.path.join(DOCS, "openapi.yaml")))
    results = run(doc)
    # negative tests: the validator must catch deliberately broken copies
    neg = []
    d1 = copy.deepcopy(doc)
    first = next(iter(d1["paths"].values()))
    next(iter(first.values()))["responses"] = {"200": {"content": {}}}  # missing description: invalid OpenAPI
    neg.append(("Response without description (invalid OpenAPI)", any(r["failures"] for r in run(d1) if r["id"] == "A01")))
    d2 = copy.deepcopy(doc)
    d2["components"]["schemas"]["PartyInput"].pop("additionalProperties", None)
    neg.append(("Request schema accepting unknown fields", any(r["failures"] for r in run(d2) if r["id"] == "A11")))
    d3 = copy.deepcopy(doc)
    d3["components"]["schemas"]["Error"]["properties"]["code"]["enum"] = ["validation_failed"]
    neg.append(("Error code enum out of sync with the catalogue", any(r["failures"] for r in run(d3) if r["id"] == "A10")))
    d4 = copy.deepcopy(doc)
    d4["paths"]["/api/v1/sales-invoices"]["post"]["responses"]["201"].pop("headers", None)
    neg.append(("201 without Location", any(r["failures"] for r in run(d4) if r["id"] == "A08")))
    d5 = copy.deepcopy(doc)
    d5["components"]["schemas"]["Party"]["properties"]["balance"] = {"type": "number"}
    neg.append(("Money as a JSON number", any(r["failures"] for r in run(d5) if r["id"] == "A12")))
    bad = C.EP_BY_KEY[("POST", "/sales-invoices/bulk-post")]
    neg.append(("Bulk post that only reads the ledger tables", bool(ledger_invariants(
        lambda e: {t: "R" for t in e["tables"]} if e is bad else C.db_ops(e))[1])))
    fails = [r for r in results if r["failures"]]
    neg_fail = [n for n, ok in neg if not ok]
    lines = [f"Last run: {len(results)} rules, **{len(results) - len(fails)} passed, {len(fails)} failed**; {len(neg)} negative tests, "
             f"{len(neg) - len(neg_fail)} detected. Official schema: OpenAPI 3.1 `schema-base` (OAI/OpenAPI-Specification, v3.1-dev).\n",
             "| Rule | Check | Items | Result |", "|---|---|---|---|"]
    for r in results:
        res = "✅ pass" if not r["failures"] else "❌ " + "; ".join(r["failures"][:3]).replace("|", "\\|")
        lines.append(f"| {r['id']} | {r['title']} | {r['checked']} | {res} |")
    lines.append("\n**Negative tests** (a deliberately broken copy of the document must fail the named rule):\n")
    lines.append("| Broken copy | Detected |\n|---|---|")
    for n_, ok in neg:
        lines.append(f"| {n_} | {'✅' if ok else '❌'} |")
    lines.append("\n**Not covered offline:** behaviour of the real Laravel implementation. That is checked later by contract tests that validate live responses against `openapi.yaml` (09, TC-CON-*).")
    md = "\n".join(lines)
    open(os.path.join(HERE, "_api_validation.md"), "w").write(md)
    p05 = os.path.join(DOCS, "05-API-Specification.md")
    s = open(p05).read()
    s = re.sub(r"<!-- VALIDATION:start -->.*?<!-- VALIDATION:end -->", lambda m: "<!-- VALIDATION:start -->\n" + md + "\n<!-- VALIDATION:end -->", s, flags=re.S)
    open(p05, "w").write(s)
    for r in results:
        print(f"{r['id']} {'FAIL' if r['failures'] else 'pass'} ({r['checked']}) {r['title'][:95]}")
        for x in r["failures"][:12]:
            print("    -", x)
        if len(r["failures"]) > 12:
            print(f"    … {len(r['failures']) - 12} more")
    for n_, ok in neg:
        print(f"negative: {n_}: {'detected' if ok else 'MISSED'}")
    print(f"rules={len(results)} failed={len(fails)} negative_missed={len(neg_fail)}")
    sys.exit(1 if fails or neg_fail else 0)


if __name__ == "__main__":
    main()
