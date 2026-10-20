"""Build generated documentation and run cross-document consistency checks.

  python3 docs/source/build_db.py      # 04-Database-Design.md (+ _schema.json)
  python3 docs/source/build_docs.py    # 01 (tables), 02, 05, 09 (tables), openapi.yaml + checks

Templates in docs/source/*.tpl.md contain <!-- GEN:name --> markers that are replaced here.
"""
import json, os, re, sys
from collections import defaultdict, OrderedDict

HERE = os.path.dirname(os.path.abspath(__file__))
DOCS = os.path.dirname(HERE)
sys.path.insert(0, HERE)
import registry as R, pages as PG, api as A, schemas as SC, tests as TS, errors as ER, actions as AC
import contract as C
import yaml

SCHEMA = json.load(open(os.path.join(HERE, "_schema.json")))
PAGES = OrderedDict((p["code"], p) for p in PG.P)



# Protocol-level codes every client handles once, centrally (08 §7); the page map lists only the business codes.
GENERIC_ERRORS = {"validation_failed", "malformed_json", "company_required", "unauthenticated", "token_expired", "token_invalid", "permission_denied",
                  "not_a_member", "not_found", "server_error", "company_maintenance", "idempotency_key_reused", "idempotency_in_progress",
                  "if_match_required", "version_conflict", "rate_limited"}


def schema_link(name):
    page = name.startswith("Page<")
    base = name[5:-1] if page else name
    return ("page of " if page else "") + f"[`{base}`](#schema-{base.lower()})"

def pages_by_module():
    """Pages grouped by module (modules in order of first appearance, pages in definition order)."""
    first = {}
    for p in PAGES.values():
        first.setdefault(p["module"], len(first))
    return sorted(PAGES.items(), key=lambda kv: first[kv[1]["module"]])

errors, warnings = [], []

# ======================================================================= schema compiler
DEC = {"money": ("^-?\\d{1,16}(\\.\\d{1,2})?$", "118450.00"), "rate": ("^\\d{1,12}(\\.\\d{1,6})?$", "121.500000"),
       "qty": ("^-?\\d{1,15}(\\.\\d{1,3})?$", "24.000"), "cost": ("^-?\\d{1,14}(\\.\\d{1,4})?$", "845.0000"), "pct": ("^\\d{1,3}(\\.\\d{1,4})?$", "5.0000")}


def comp_type(spec):
    nullable = "?" in spec
    t = spec.replace("?", "").replace("!", "")
    if t.startswith("["):
        inner = t[1:-1]
        out = {"type": "array", "items": comp_type(inner)}
    elif t.startswith("ref:"):
        name = t[4:]
        if name not in SC.S:
            errors.append(f"schema ref to unknown {name}")
        out = {"$ref": f"#/components/schemas/{name}"}
        if nullable:
            return {"oneOf": [out, {"type": "null"}]}
        return out
    elif t.startswith("enum:"):
        out = {"type": "string", "enum": t[5:].split("|")}
    elif t.startswith("str:"):
        out = {"type": "string", "maxLength": int(t[4:])}
    else:
        out = {"id": {"type": "integer", "format": "int64", "minimum": 1}, "str": {"type": "string"}, "text": {"type": "string", "maxLength": 65535},
               "int": {"type": "integer"}, "bool": {"type": "boolean"}, "date": {"type": "string", "format": "date"},
               "datetime": {"type": "string", "format": "date-time"}, "email": {"type": "string", "format": "email", "maxLength": 190},
               "obj": {"type": "object", "additionalProperties": True}, "ccy": {"type": "string", "pattern": "^[A-Z]{3}$", "example": "BDT"},
               "uuid": {"type": "string", "format": "uuid"},
               "errcode": {"type": "string", "enum": sorted(ER.E)},
               "errmap": {"type": "object", "additionalProperties": {"type": "array", "items": {"type": "string"}},
                          "description": "Field errors keyed by Laravel dot path, e.g. lines.2.quantity"}}.get(t)
        if t in DEC:
            out = {"type": "string", "pattern": DEC[t][0], "example": DEC[t][1], "description": f"Decimal as string ({t})"}
        if out is None:
            errors.append(f"unknown shorthand type {spec}")
            out = {}
        out = dict(out)
    if nullable and "type" in out:
        out["type"] = [out["type"], "null"]
    return out


def compile_schema(name):
    desc, props = SC.S[name]
    if name == "Binary":
        return {"type": "string", "format": "binary", "description": desc}
    o = {"type": "object", "description": desc, "properties": {k: comp_type(v) for k, v in props.items()}}
    for k in props:
        if (name, k) in SC.SERVER_DEFAULTS:
            o["properties"][k]["description"] = (o["properties"][k].get("description", "") + " When omitted: " + SC.SERVER_DEFAULTS[(name, k)] + ".").strip()
    req = [k for k, v in props.items() if "!" in v]
    if req:
        o["required"] = req
    if name in STRICT_INPUTS:
        o["additionalProperties"] = False  # unknown request fields are rejected with 422 validation_failed
    return o


STRICT_INPUTS = C.input_schema_names()


# ======================================================================= openapi
def path_params(p):
    return re.findall(r"{(\w+)}", p)


PARAM_DEF = {
    "page": {"schema": {"type": "integer", "minimum": 1, "default": 1}, "description": "Page number"},
    "per_page": {"schema": {"type": "integer", "minimum": 1, "maximum": 100, "default": 25}, "description": "Rows per page (max 100)"},
    "sort": {"schema": {"type": "string"}, "description": "Sort field; prefix '-' for descending, e.g. -date"},
    "q": {"schema": {"type": "string", "maxLength": 100}, "description": "Free-text search"},
    "date_from": {"schema": {"type": "string", "format": "date"}, "description": "Start date (inclusive)"},
    "date_to": {"schema": {"type": "string", "format": "date"}, "description": "End date (inclusive)"},
    "as_of": {"schema": {"type": "string", "format": "date"}, "description": "As-of date (default today)"},
    "format": {"schema": {"type": "string", "enum": ["json", "xlsx", "pdf", "csv"], "default": "json"}, "description": "Output format (non-JSON requires reports.export)"},
    "dry_run": {"schema": {"type": "boolean", "default": True}, "description": "Validate and preview without saving"},
    "purpose": {"schema": {"type": "string", "enum": ["income_for_product", "expense_for_product", "purchase_for_supplier", "tax", "category", "any"]},
                "description": "Picker purpose; required without accounting.view (R-26)"},
    "status": {"schema": {"type": "string", "maxLength": 30}, "description": "Status filter (values as in the resource's status enum)"},
}
STATUS_TEXT = {200: "OK", 201: "Created", 202: "Accepted (queued or awaiting approval)", 204: "No content", 400: "Malformed request", 401: "Not authenticated",
               403: "Not permitted", 404: "Not found (also for other companies' records)", 409: "Conflict (version mismatch, state not allowed, duplicate)",
               410: "Gone (expired)", 413: "Payload too large", 415: "Unsupported media type", 422: "Validation or business-rule failure",
               428: "Precondition required (If-Match missing)", 429: "Too many requests", 500: "Unexpected server error", 503: "Company temporarily unavailable (restore in progress)"}


def default_codes(e):
    return C.statuses(e)


def op_id(e):
    words = re.sub(r"[{}]", "", e["path"]).replace("-", "/").strip("/").split("/")
    return e["method"].lower() + "".join(w[:1].upper() + w[1:] for w in words if w)


FILE_TYPES = {"xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "pdf": "application/pdf", "csv": "text/csv"}
MULTIPART = ("FileUpload", "AttachmentUpload", "StatementImport", "ProductImport")


def data_schema(resp):
    m = re.match(r"Page<(.*)>", resp)
    if m:
        return {"type": "object", "required": ["data", "meta"], "properties": {"data": {"type": "array", "items": {"$ref": f"#/components/schemas/{m.group(1)}"}},
                                                                               "meta": {"$ref": "#/components/schemas/PageMeta"}}}
    return {"type": "object", "required": ["data"], "properties": {"data": {"$ref": f"#/components/schemas/{resp}"}}}


def build_openapi():
    paths = defaultdict(dict)
    for e in A.EP:
        params = [{"name": p, "in": "path", "required": True, "schema": {"type": "string" if p in ("token", "lens", "report") else "integer", **({} if p in ("token", "lens", "report") else {"format": "int64", "minimum": 1})}}
                  for p in path_params(e["path"])]
        for q in e["params"]:
            d = PARAM_DEF.get(q, {"schema": {"type": "string"}, "description": q.replace("_", " ")})
            params.append({"name": q, "in": "query", "required": False, **d})
        if e["perm"] not in ("public", "auth"):
            params.append({"$ref": "#/components/parameters/CompanyHeader"})
        if e["idem"]:
            params.append({"$ref": "#/components/parameters/IdempotencyKey"})
        if e["ifmatch"]:
            params.append({"$ref": "#/components/parameters/IfMatch"})
        codes = C.error_codes(e)
        ops = C.db_ops(e)
        op = {"operationId": op_id(e), "summary": e["summary"], "tags": [e["tag"] or "Misc"],
              "description": (e["desc"] + "\n\n" if e["desc"] else "") +
                             f"**Permission:** {C.perm_text(e)}  \n**Database:** {C.ops_text(e)}  \n**Rules:** {', '.join(e['rules']) or '—'}" +
                             (f"  \n**Transaction:** {e['tx']}" if e["tx"] else ""),
              "x-permission": e["perm"], "x-business-rules": e["rules"], "x-requirements": e["fr"], "x-pages": C.pages_of(e),
              "x-page-actions": [f"{pg}: {a}" for pg, a, t in C.actions_of(e)], "x-tables": e["tables"], "x-db-operations": ops, "x-error-codes": codes}
        if e["perm_map"]:
            op["x-permission-mode"] = "contextual"
            op["x-permission-map"] = e["perm_map"]
        if e["added"]:
            op["x-contract-change"] = e["added"]
        if params:
            op["parameters"] = params
        if e["security"] == "refreshCookie":
            op["security"] = [{"refreshCookie": []}]
        elif e["perm"] == "public":
            op["security"] = []
        if e["req"]:
            ct = "multipart/form-data" if e["req"] in MULTIPART else "application/json"
            op["requestBody"] = {"required": True, "content": {ct: {"schema": {"$ref": f"#/components/schemas/{e['req']}"}}}}
        resp = {}
        by_status = defaultdict(list)
        for c in codes:
            by_status[ER.E[c][0]].append(c)
        for code in default_codes(e):
            if code in (200, 201, 202):
                r = {"description": STATUS_TEXT[code]}
                if code == 202 and "format" in e["params"]:
                    r["description"] = "Export too large for a direct download: an export job to poll"
                    r["content"] = {"application/json": {"schema": data_schema("ExportJob")}}
                elif e["resp"] == "Binary":
                    r["content"] = {"application/pdf": {"schema": {"$ref": "#/components/schemas/Binary"}}}
                elif e["resp"]:
                    r["content"] = {"application/json": {"schema": data_schema(e["resp"])}}
                    if code == 200 and "format" in e["params"]:
                        for f, mt in FILE_TYPES.items():
                            r["content"][mt] = {"schema": {"$ref": "#/components/schemas/Binary"}}
                    if code == 202:
                        r["description"] = "Accepted: above the approval limit, saved as submitted and waiting for approval (BR-007)"
                headers = {}
                if code == 201:
                    headers["Location"] = {"schema": {"type": "string"}, "description": "URL of the created resource"}
                if e["resp"] and e["resp"] != "Binary" and not e["resp"].startswith("Page<") and (e["ifmatch"] or e["method"] in ("GET", "PUT", "PATCH")):
                    headers["ETag"] = {"schema": {"type": "string"}, "description": "W/\"<version>\" for If-Match"}
                if headers:
                    r["headers"] = headers
                resp[str(code)] = r
            elif code == 204:
                resp["204"] = {"description": STATUS_TEXT[204]}
            else:
                cs = by_status.get(code, [])
                r = {"description": STATUS_TEXT[code] + (": " + ", ".join(f"`{x}`" for x in cs) if cs else ""),
                     "content": {"application/problem+json": {"schema": {"$ref": "#/components/schemas/Error"}}}}
                if code in (429, 503) or "rate_limited" in cs:
                    r["headers"] = {"Retry-After": {"schema": {"type": "integer"}, "description": "Seconds to wait"}}
                resp[str(code)] = r
        resp["default"] = {"description": "Unexpected error (`server_error`)", "content": {"application/problem+json": {"schema": {"$ref": "#/components/schemas/Error"}}}}
        op["responses"] = resp
        paths["/api/v1" + e["path"]][e["method"].lower()] = op
    used = set()
    for e in A.EP:
        for k in ("req", "resp"):
            if e[k]:
                used.add(re.sub(r"Page<(.*)>", r"\1", e[k]))
    used.add("ExportJob")
    comps = {n: compile_schema(n) for n in SC.S}
    tag_desc = {"Auth": "Sign-in, tokens, password reset, invitations", "Me": "The signed-in user's own profile, security and preferences",
                "Company": "Company profile, settings, branches, numbering, tax rules, account mappings", "Fiscal years": "Financial years and period locks",
                "Users and roles": "Members, invitations, roles and permissions", "Platform": "Search, notifications, approvals, attachments, audit, dashboard, backups, exports",
                "Parties": "Customers and suppliers", "Inventory": "Products, warehouses, stock adjustments and transfers",
                "Sales": "Quotations, invoices, receipts, returns, credit notes", "Purchases": "Purchase orders, bills, payments, returns, debit notes",
                "Banking": "Money accounts, cash counts, statement import, reconciliation", "Income and expenses": "Categories, quick entries, recurring templates",
                "Accounting": "Chart of accounts, ledgers, vouchers", "Reports": "Financial statements and operational reports", "LC": "Letters of credit and landed cost"}
    doc = {
        "openapi": "3.1.0",
        "info": {"title": "LedgerFlow ERP API", "version": "1.0.0-rc.1",
                 "summary": "REST/JSON contract of LedgerFlow ERP v1 (candidate for approval).",
                 "description": "Status: release candidate for approval, 18 Oct 2026. Backend Laravel 11/12 + Eloquent. All amounts are decimal strings. "
                                "Company-scoped endpoints require X-Company-Id. Errors are application/problem+json with a stable `code` (see 05 section 3). "
                                "Vendor extensions: x-permission(-mode/-map), x-db-operations, x-error-codes, x-pages, x-page-actions, x-business-rules. "
                                "Generated from docs/source/api.py; do not edit by hand.",
                 "license": {"name": "Proprietary"}},
        "servers": [{"url": "https://{host}", "variables": {"host": {"default": "api.ledgerflow.example"}}}],
        "security": [{"bearerAuth": []}],
        "tags": [{"name": t, "description": tag_desc.get(t, t)} for t in sorted({e["tag"] or "Misc" for e in A.EP})],
        "paths": dict(paths),
        "components": {
            "securitySchemes": {"bearerAuth": {"type": "http", "scheme": "bearer", "bearerFormat": "JWT", "description": "15-minute ES256 access token held in memory"},
                                "refreshCookie": {"type": "apiKey", "in": "cookie", "name": "lf_rt", "description": "Rotating refresh token; HttpOnly, Secure, SameSite=Strict, Path=/api/v1/auth"}},
            "parameters": {
                "CompanyHeader": {"name": "X-Company-Id", "in": "header", "required": True, "schema": {"type": "integer", "format": "int64", "minimum": 1}, "description": "Active company. Membership is verified on every request."},
                "IdempotencyKey": {"name": "Idempotency-Key", "in": "header", "required": False, "schema": {"type": "string", "format": "uuid"}, "description": "UUID v4 per user action; replays within 24 h return the original response (BR-050)."},
                "IfMatch": {"name": "If-Match", "in": "header", "required": True, "schema": {"type": "string", "pattern": "^W/\"[0-9]+\"$"}, "description": "ETag from the last GET (W/\"<version>\"). Missing: 428; stale: 409 version_conflict (BR-049)."}},
            "schemas": comps},
    }
    unused = sorted(set(SC.S) - used - {"Error", "PageMeta"} - {n for n in SC.S if any(f"ref:{n}" in v for _, p in SC.S.values() for v in p.values())})
    if unused:
        errors.append("schemas defined but unused: " + ", ".join(unused))
    return doc


# ======================================================================= structural OpenAPI validation (offline)
def validate_openapi(doc):
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
    for r in refs:
        parts = r.lstrip("#/").split("/")
        node = doc
        for p in parts:
            if not isinstance(node, dict) or p not in node:
                errors.append(f"openapi: unresolved $ref {r}")
                break
            node = node[p]
    ids = [op["operationId"] for p in doc["paths"].values() for op in p.values()]
    dup = {i for i in ids if ids.count(i) > 1}
    if dup:
        errors.append("openapi: duplicate operationIds " + ", ".join(sorted(dup)))
    for path, ops in doc["paths"].items():
        pp = set(path_params(path))
        for m, op in ops.items():
            declared = {p["name"] for p in op.get("parameters", []) if "name" in p and p.get("in") == "path"}
            if declared != pp:
                errors.append(f"openapi: path params mismatch {m.upper()} {path}")
            if not op.get("responses"):
                errors.append(f"openapi: no responses {m} {path}")
    return len(refs), len(ids)


# ======================================================================= cross checks
TABLES = set(SCHEMA)
for e in A.EP:
    key = f"{e['method']} {e['path']}"
    if e["perm"] not in ("public", "auth") and e["perm"] not in R.ALL_PERMS:
        errors.append(f"{key}: unknown permission {e['perm']}")
    for t in e["tables"]:
        if t not in TABLES:
            errors.append(f"{key}: unknown table {t}")
    for p in e["pages"]:
        if p != "system" and p not in PAGES:
            errors.append(f"{key}: unknown page {p}")
    for r in e["rules"]:
        if r not in R.BR:
            errors.append(f"{key}: unknown rule {r}")
    for f in e["fr"]:
        if f not in R.FR:
            errors.append(f"{key}: unknown FR {f}")
    for k in ("req", "resp"):
        if e[k] and re.sub(r"Page<(.*)>", r"\1", e[k]) not in SC.S:
            errors.append(f"{key}: unknown schema {e[k]}")
    if e["method"] in ("POST", "PUT", "PATCH", "DELETE") and e["perm"] in ("public",) and not e["path"].startswith(("/auth", "/invitations")):
        errors.append(f"{key}: data-changing endpoint without authorization")
    # If-Match coverage against lock_version is checked by validate_api.py (rule A-13).
seen = defaultdict(int)
for e in A.EP:
    seen[(e["method"], e["path"])] += 1
for k, n in seen.items():
    if n > 1:
        errors.append(f"duplicate endpoint {k}")

page_eps = defaultdict(list)
for e in A.EP:
    for p in C.pages_of(e):
        page_eps[p].append(e)
for code, p in PAGES.items():
    for r in p["rules"]:
        if r not in R.BR and r not in R.NFR:
            errors.append(f"page {code}: unknown rule {r}")
    for f in p["fr"]:
        if f not in R.FR:
            errors.append(f"page {code}: unknown FR {f}")
    for d in p["deps"]:
        if d not in PAGES:
            errors.append(f"page {code}: unknown dependency {d}")
    if p["perm"] not in ("public",) and p["perm"] not in R.ALL_PERMS:
        errors.append(f"page {code}: unknown permission {p['perm']}")
    if not page_eps.get(code) and p["module"] != "States" and p["template"] not in ("Dialog",) and code not in ("S0",):
        errors.append(f"page {code} has no API mapping")
fr_cov = {f for e in A.EP for f in e["fr"]} | {f for p in PAGES.values() for f in p["fr"]}
for f in R.FR:
    if f not in fr_cov:
        errors.append(f"{f} not covered by any page or endpoint")
br_cov = {r for e in A.EP for r in e["rules"]} | {r for p in PAGES.values() for r in p["rules"]}
for r in R.BR:
    if r not in br_cov:
        warnings.append(f"{r} not referenced by any page or endpoint")
tc_ids = [t["id"] for t in TS.TC]
if len(tc_ids) != len(set(tc_ids)):
    errors.append("duplicate test ids")
for t in TS.TC:
    for ref in t["refs"]:
        if ref not in R.BR and ref not in R.FR and ref not in R.NFR:
            errors.append(f"{t['id']}: unknown reference {ref}")
br_tested = {r for t in TS.TC for r in t["refs"] if r.startswith("BR")}
for r in R.BR:
    if r not in br_tested:
        errors.append(f"{r} has no test case")
for w in TS.WORKFLOWS:
    for row in w["rows"]:
        if row["page"] not in PAGES:
            errors.append(f"workflow {w['name']}: unknown page {row['page']}")
        if not any(f"{e['method']} {e['path']}" == row["api"] for e in A.EP):
            errors.append(f"workflow {w['name']}: unknown endpoint {row['api']}")
        for t in row["tables"]:
            if t not in TABLES:
                errors.append(f"workflow {w['name']}: unknown table {t}")
        for r in row["rules"]:
            if r not in R.BR:
                errors.append(f"workflow {w['name']}: unknown rule {r}")
        for t in row["tests"]:
            if t not in tc_ids:
                errors.append(f"workflow {w['name']}: unknown test {t}")


# ======================================================================= renderers
def md_cell(x):
    return str(x).replace("|", "\\|").replace("\n", " ")


def anchor(e):
    return op_id(e).lower()


GENERIC_CODES = {"unauthenticated", "token_expired", "token_invalid", "permission_denied", "company_required", "not_a_member", "not_found",
                 "validation_failed", "malformed_json", "server_error", "rate_limited", "idempotency_key_reused", "idempotency_in_progress",
                 "version_conflict", "if_match_required"}


def ep_link(m, path):
    e = C.EP_BY_KEY.get((m, path))
    return f"[`{m} {path}`](#{anchor(e)})" if e else f"`{m} {path}`"


def target_md(t):
    return md_cell(C.ENDPOINT_RX.sub(lambda mm: ep_link(mm.group(1), mm.group(2)), t))


def render_api():
    import contract_review as CR
    tpl = open(os.path.join(HERE, "api.tpl.md")).read()
    out = []
    tags = OrderedDict()
    for e in A.EP:
        tags.setdefault(e["tag"] or "Misc", []).append(e)
    idx = ["| # | Method | URL | Purpose | Permission | Success |", "|---|---|---|---|---|---|"]
    n = 0
    for tag, eps in tags.items():
        for e in eps:
            n += 1
            idx.append(f"| {n} | `{e['method']}` | [`/api/v1{e['path']}`](#{anchor(e)}) | {md_cell(e['summary'])} | {md_cell(C.perm_text(e))} | {C.success_status(e)} |")
    for tag, eps in tags.items():
        out.append(f"\n### {tag}\n")
        for e in eps:
            out.append(f"\n<a id=\"{anchor(e)}\"></a>\n#### `{e['method']} /api/v1{e['path']}`\n")
            out.append(f"{e['summary']}." + (f" {e['desc']}" if e["desc"] else "") + (f" _Contract change: {e['added']}._" if e["added"] else ""))
            pp = path_params(e["path"])
            codes = C.error_codes(e)
            biz = [c for c in codes if c not in GENERIC_CODES]
            gen = [c for c in codes if c in GENERIC_CODES]
            acts = C.actions_of(e)
            auth = ("Refresh cookie `lf_rt`" if e["security"] == "refreshCookie" else "None (public, rate-limited)") if e["perm"] == "public" else \
                "Bearer JWT" + (" + `X-Company-Id`" if e["perm"] != "auth" else "")
            roles = "" if e["perm"] in ("public", "auth", "auth.self") or e["perm_map"] else " — roles: " + ", ".join(R.roles_with(e["perm"]))
            resp = "—" if not e["resp"] else (f"`data[]` of [`{re.sub(r'Page<(.*)>', r'\1', e['resp'])}`](#schema-{re.sub(r'Page<(.*)>', r'\1', e['resp']).lower()}) + `meta`"
                                              if e["resp"].startswith("Page<") else ("file (`application/pdf`)" if e["resp"] == "Binary" else f"`data`: [`{e['resp']}`](#schema-{e['resp'].lower()})"))
            rows = [("Auth", auth), ("Permission", C.perm_text(e) + roles),
                    ("Path / query params", (", ".join(f"`{p}`" for p in pp) or "—") + " / " + (", ".join(f"`{q}`" for q in e["params"]) or "—")),
                    ("Request body", f"[`{e['req']}`](#schema-{e['req'].lower()})" if e["req"] else "—"),
                    ("Validation", "<br>".join(md_cell(x) for x in C.validation(e)) or "Query parameters only (unknown parameters: 422)." if e["params"] else "<br>".join(md_cell(x) for x in C.validation(e)) or "—"),
                    ("Success", f"{C.success_status(e)} " + resp + (" · 202 when above the approval limit" if 202 in C.statuses(e) and "format" not in e["params"] else "") +
                     (" · `format=xlsx|pdf|csv` returns the file; 202 + ExportJob above 5,000 rows" if "format" in e["params"] else "")),
                    ("Status codes", ", ".join(str(c) for c in C.statuses(e))),
                    ("Error codes", ("**" + ", ".join(f"`{c}`" for c in biz) + "**; " if biz else "") + "standard: " + ", ".join(f"`{c}`" for c in gen)),
                    ("Headers", ", ".join(x for x, y in [("`Idempotency-Key`", e["idem"]), ("`If-Match` (required)", e["ifmatch"]), ("`Location` on 201", 201 in C.statuses(e))] if y) or "—"),
                    ("Database", C.ops_text(e)),
                    ("Business rules", ", ".join(e["rules"]) or "—"),
                    ("Requirements", ", ".join(e["fr"]) or "—"),
                    ("Pages and actions", ", ".join(C.pages_of(e)) + ("<br>" + "<br>".join(md_cell(f"{pg} · {a}") for pg, a, t in acts) if acts else "")),
                    ("Transaction / concurrency", e["tx"] or ("Single-row write; optimistic lock via If-Match." if e["ifmatch"] else ("Read-only; consistent snapshot." if C.is_readonly(e) else "Single transaction.")))]
            out.append("\n| | |\n|---|---|\n" + "\n".join(f"| **{k}** | {v if k in ('Validation', 'Pages and actions', 'Error codes') else md_cell(v)} |" for k, v in rows))
    sch = []
    for name, (desc, props) in SC.S.items():
        strict = " Unknown properties are rejected." if name in STRICT_INPUTS else ""
        sch.append(f"\n<a id=\"schema-{name.lower()}\"></a>\n#### `{name}`\n\n{desc}{strict}\n")
        if props:
            sch.append("| Property | Type | Required |\n|---|---|---|")
            for k, v in props.items():
                sch.append(f"| `{k}` | `{md_cell(v.replace('!', '').replace('?', ' | null'))}` | {'yes' if '!' in v else 'no'} |")
    # error catalogue
    use = defaultdict(int)
    for e in A.EP:
        for c in C.error_codes(e):
            use[c] += 1
    errs = ["| Code | HTTP | Title (shown to the user) | Rules | Endpoints |", "|---|---|---|---|---|"]
    for c, (st, title, refs) in sorted(ER.E.items(), key=lambda kv: (kv[1][0], kv[0])):
        errs.append(f"| `{c}` | {st} | {md_cell(title)} | {', '.join(refs) or '—'} | {use.get(c, 0) if st >= 400 else '— (outcome)'} |")
    # page action map
    pm = []
    mod = None
    for code, p in pages_by_module():
        if p["module"] != mod:
            mod = p["module"]
            pm.append(f"\n### {mod}\n")
        loads = [e for e in page_eps.get(code, []) if e["method"] == "GET" and code in e["pages"]]
        pm.append(f"\n<a id=\"actions-{code.lower()}\"></a>\n#### {code} · {md_cell(p['name'])}\n")
        pm.append(f"View permission `{p['perm']}`. **Loads:** " + (", ".join(ep_link(e["method"], e["path"]) for e in loads) if loads else "nothing of its own (host page data)") + ".\n")
        if p["actions"]:
            pm.append("| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |\n|---|---|---|---|---|---|---|")
            for i, (a, t) in enumerate(zip(p["actions"], AC.A.get(code, [])), 1):
                parsed = C.parse_target(t)
                calls = [C.EP_BY_KEY[c] for c in parsed["calls"] if c in C.EP_BY_KEY]
                perms = sorted({C.perm_text(x) for x in calls})
                succ = sorted({str(C.success_status(x)) for x in calls})
                io = []
                for x in calls:
                    if x["method"] == "GET" and not x["req"]:
                        continue
                    io.append(f"{schema_link(x['req']) if x['req'] else '—'} → {schema_link(x['resp']) if x['resp'] else '—'}")
                biz = sorted({c for x in calls for c in C.error_codes(x)} - GENERIC_ERRORS)
                pm.append(f"| {i} | {md_cell(a)} | {target_md(t)} | {md_cell(', '.join(perms)) or '—'} | {'<br>'.join(dict.fromkeys(io)) or '—'} | "
                          f"{', '.join(succ) or '—'} | {', '.join(f'`{c}`' for c in biz) or '—'} |")
        else:
            pm.append("_No actions of its own._")
    for j, (name, ref) in PG.REUSED.items():
        pm.append(f"\n- {j} · {name}: same as [{ref}](#actions-{ref.lower()}) (reused screen, Reports breadcrumb)")
    s = tpl.replace("<!-- GEN:index -->", "\n".join(idx)).replace("<!-- GEN:endpoints -->", "\n".join(out)).replace("<!-- GEN:schemas -->", "\n".join(sch))
    vpath = os.path.join(HERE, "_api_validation.md")
    vtxt = open(vpath).read() if os.path.exists(vpath) else "_Run `validate_api.py` to fill this section._"
    s = s.replace("<!-- GEN:pagemap -->", "\n".join(pm)).replace("<!-- GEN:errors -->", "\n".join(errs)).replace("<!-- GEN:review -->", CR.render(md_cell, ep_link))
    s = s.replace("<!-- GEN:validation -->", "<!-- VALIDATION:start -->\n" + vtxt + "\n<!-- VALIDATION:end -->")
    nact = sum(len(p["actions"]) for p in PAGES.values())
    s = s.replace("<!-- GEN:count -->", f"{len(A.EP)} endpoints, {len(SC.S)} schemas, {len(ER.E)} error codes, {len(PAGES)} pages with {nact} mapped actions")
    open(os.path.join(DOCS, "05-API-Specification.md"), "w").write(s)


DEFAULT_STATES = {
    "List": "Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).",
    "Form": "Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).",
    "Detail": "Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.",
    "Overview": "Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.",
    "Lens": "As Overview.",
    "Report": "Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.",
    "Statement": "As Report, plus agreement check (green) or difference (warning).",
    "Ledger": "As Report; opening and closing always shown, even with no entries.",
    "Print": "Loading preview skeleton; PDF generation toast; print uses the browser dialog.",
    "Voucher": "As Form, plus the live BalanceStatus (balanced / out of balance by ৳ X); Post disabled while unbalanced.",
    "Tree": "Loading skeleton rows; empty search 'No accounts match'.",
    "Workspace": "Loading lines; 'Everything is reconciled' empty state (L9); errors per action with Undo.",
    "Settings": "Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.",
    "Matrix": "As Settings.", "Hub": "Search with no-match state.", "Dialog": "Confirm button busy while the request runs; errors shown inside the dialog.",
    "Drawer": "As Dialog.", "Shell": "Offline banner; notification badge updates.", "Auth": "Busy button; generic errors; lockout timer.",
    "Overlay": "Results stream as you type; 'No results'.", "State": "Is itself a state pattern.", "Toast": "—",
}


def render_ui():
    tpl = open(os.path.join(HERE, "ui.tpl.md")).read()
    rows = ["| Code | Page | Module | Template | View permission | Endpoints | Design source |", "|---|---|---|---|---|---|---|"]
    for code, p in PAGES.items():
        rows.append(f"| [{code}](#page-{code.lower()}) | {md_cell(p['name'])} | {p['module']} | {p['template']} | `{p['perm']}` | {len(page_eps.get(code, []))} | {md_cell(p['artboard'] or '—')} |")
    for j, (name, ref) in PG.REUSED.items():
        rows.append(f"| {j} | {name} | Reports | → {ref} | as {ref} | — | reused |")
    det = []
    mod = None
    for code, p in pages_by_module():
        if p["module"] != mod:
            mod = p["module"]
            det.append(f"\n### {mod}\n")
        eps = page_eps.get(code, [])
        ents = sorted({t for e in eps for t in e["tables"]})
        det.append(f"\n<a id=\"page-{code.lower()}\"></a>\n#### {code} · {p['name']}\n")
        det.append(f"1. **Purpose:** {p['purpose']}")
        det.append(f"2. **Components and layout:** {p['layout'] or 'See template ' + p['template']}" + (f" _Source: {p['artboard']}._" if p["artboard"] else ""))
        if p["fields"]:
            det.append("3. **Inputs** (and 4. required/optional):\n\n   | Field | Type | Req. | Rule |\n   |---|---|---|---|")
            for f in p["fields"]:
                parts = [x.strip() for x in f.split("|")] + ["", "", ""]
                det.append(f"   | {md_cell(parts[0])} | {md_cell(parts[1])} | {md_cell(parts[2])} | {md_cell(parts[3])} |")
        else:
            det.append("3–4. **Inputs:** none (read-only page; filters listed below)")
        det.append(f"5. **Actions → API** ([full map](05-API-Specification.md#actions-{code.lower()})): " +
                   ("<br>".join(f"{md_cell(a)} → {md_cell(t)}" for a, t in zip(p["actions"], AC.A.get(code, []))) if p["actions"] else "—"))
        if p["table"]:
            t = p["table"]
            det.append(f"6. **Table:** columns {', '.join(t['cols'])}" + (f"; filters {', '.join(t['filters'])}" if t["filters"] else "") + ("; search" if t["search"] else "") + "; server-side pagination and sorting.")
        else:
            det.append("6. **Tables:** —")
        v = p["validation"] + [f"{r}: {R.BR[r]}" for r in p["rules"] if r in R.BR]
        det.append("7. **Validation and rules:** " + ("<br>".join(md_cell(x) for x in v) if v else "Standard server validation (422)."))
        det.append(f"8. **Roles allowed:** `{p['perm']}` → " + ", ".join(R.roles_with(p["perm"])) + ". Actions additionally check their own permission (see endpoints).")
        det.append("9. **APIs:** " + (", ".join(f"[`{e['method']} {e['path']}`](05-API-Specification.md#{anchor(e)})" for e in eps) if eps else "none of its own (uses host page data)"))
        det.append("10. **Entities:** " + (", ".join(f"`{x}`" for x in ents) if ents else "—"))
        det.append("11. **Workflows:** " + (", ".join(p["workflows"]) if p["workflows"] else "—") + (" · FR: " + ", ".join(p["fr"]) if p["fr"] else ""))
        det.append("12. **States:** " + DEFAULT_STATES.get(p["template"], "Standard.") + (" " + " ".join(p["states"]) if p["states"] else ""))
        det.append("13. **Depends on:** " + (", ".join(f"[{d}](#page-{d.lower()})" for d in p["deps"]) if p["deps"] else "—") + (f" · Note: {p['note']}" if p["note"] else ""))
    s = tpl.replace("<!-- GEN:summary -->", "\n".join(rows)).replace("<!-- GEN:details -->", "\n".join(det))
    s = s.replace("<!-- GEN:count -->", f"{len(PAGES)} page entries (incl. dialogs, 4 new auth/settings screens and 7 new list screens, GAP-24/25) + {len(PG.REUSED)} reused report entries")
    open(os.path.join(DOCS, "02-UI-Inventory.md"), "w").write(s)


def render_srs():
    tpl = open(os.path.join(HERE, "srs.tpl.md")).read()
    fr = ["| ID | Module | Requirement | Pages | Endpoints |", "|---|---|---|---|---|"]
    for k, (m, t) in R.FR.items():
        ps = [c for c, p in PAGES.items() if k in p["fr"]]
        ne = sum(1 for e in A.EP if k in e["fr"])
        fr.append(f"| {k} | {m} | {md_cell(t)} | {', '.join(ps) or '—'} | {ne} |")
    br = ["| ID | Rule | Enforced by (endpoints) | Tests |", "|---|---|---|---|"]
    for k, t in R.BR.items():
        ne = sum(1 for e in A.EP if k in e["rules"])
        tc = [x["id"] for x in TS.TC if k in x["refs"]]
        br.append(f"| {k} | {md_cell(t)} | {ne} | {', '.join(tc)} |")
    nfr = ["| ID | Category | Requirement | Tests |", "|---|---|---|---|"]
    for k, (c, t) in R.NFR.items():
        tc = [x["id"] for x in TS.TC if k in x["refs"]]
        nfr.append(f"| {k} | {c} | {md_cell(t)} | {', '.join(tc) or 'Verified by review / operations runbook'} |")
    roles = ["| Module | " + " | ".join(R.ROLE_NAMES.values()) + " |", "|---|" + "---|" * len(R.ROLE_NAMES)]
    for m in R.MODULES:
        roles.append(f"| {m} | " + " | ".join(R.ROLE_MATRIX[r].get(m, "") or "—" for r in R.ROLE_NAMES) + " |")
    for sp, rs in R.SPECIAL_PERMS.items():
        roles.append(f"| `{sp}` | " + " | ".join("✓" if r in rs else "—" for r in R.ROLE_NAMES) + " |")
    trace = []
    for w in TS.WORKFLOWS:
        trace.append(f"\n#### {w['name']}\n\n{w['desc']}\n\n| Step | UI page | User action | API endpoint | Backend service | Tables | Business rules | Tests |\n|---|---|---|---|---|---|---|---|")
        for i, r in enumerate(w["rows"], 1):
            trace.append(f"| {i} | {r['page']} | {md_cell(r['action'])} | `{r['api']}` | `{r['service']}` | {', '.join(r['tables'])} | {', '.join(r['rules'])} | {', '.join(r['tests'])} |")
    s = (tpl.replace("<!-- GEN:fr -->", "\n".join(fr)).replace("<!-- GEN:br -->", "\n".join(br)).replace("<!-- GEN:nfr -->", "\n".join(nfr))
         .replace("<!-- GEN:roles -->", "\n".join(roles)).replace("<!-- GEN:trace -->", "\n".join(trace)))
    open(os.path.join(DOCS, "01-SRS.md"), "w").write(s)


def render_tests():
    tpl = open(os.path.join(HERE, "testing.tpl.md")).read()
    groups = OrderedDict()
    for t in TS.TC:
        groups.setdefault(t["suite"], []).append(t)
    out = []
    for g, ts in groups.items():
        out.append(f"\n### {g}\n\n| ID | Level | Scenario | Expected result | Covers |\n|---|---|---|---|---|")
        for t in ts:
            out.append(f"| {t['id']} | {t['level']} | {md_cell(t['scenario'])} | {md_cell(t['expected'])} | {', '.join(t['refs'])} |")
    s = tpl.replace("<!-- GEN:cases -->", "\n".join(out)).replace("<!-- GEN:count -->", str(len(TS.TC)))
    open(os.path.join(DOCS, "09-Testing-Strategy.md"), "w").write(s)


doc = build_openapi()
nrefs, nops = validate_openapi(doc)


class NoAlias(yaml.SafeDumper):
    def ignore_aliases(self, data):
        return True


open(os.path.join(DOCS, "openapi.yaml"), "w").write("# Generated by docs/source/build_docs.py from docs/source/api.py and schemas.py. Do not edit by hand.\n" +
                                                    yaml.dump(doc, Dumper=NoAlias, sort_keys=False, allow_unicode=True, width=160))
render_api()
render_ui()
render_srs()
render_tests()
yaml.safe_load(open(os.path.join(DOCS, "openapi.yaml")))  # re-parse

print(f"endpoints={len(A.EP)} operations={nops} schemas={len(SC.S)} refs={nrefs} pages={len(PAGES)} FR={len(R.FR)} BR={len(R.BR)} NFR={len(R.NFR)} tests={len(TS.TC)} workflows={len(TS.WORKFLOWS)}")
for w in warnings:
    print("WARN", w)
for e in errors:
    print("ERROR", e)
json.dump({"errors": errors, "warnings": warnings, "counts": {"endpoints": len(A.EP), "schemas": len(SC.S), "pages": len(PAGES), "tests": len(TS.TC)}},
          open(os.path.join(HERE, "_checks.json"), "w"), indent=1)
sys.exit(1 if errors else 0)
