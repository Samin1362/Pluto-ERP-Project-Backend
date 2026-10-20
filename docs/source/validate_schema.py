"""MySQL 8.0 rule validator for the LedgerFlow schema.

Usage: python3 docs/source/validate_schema.py   (non-zero exit on any failure)
Writes docs/source/_validation.json, which build_db.py renders into 14-Physical-Schema-and-Migrations.md.
"""
import json, os, re, sys
from collections import defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import physical as P
import schema as S

TB = P.TB
results = []  # (rule, title, checked, failures[list])


def rule(rid, title, checked, failures):
    rid = f"V{len(results) + 1:02d}"  # sequential ids in report order
    results.append({"id": rid, "title": title, "checked": checked, "failures": failures})


def all_fks():
    return [(t, c) for t in TB.values() for c in t.cols if c.fk]


GLOBAL_TABLES = {"currencies", "users", "permissions", "companies"}
CASCADING = {"CASCADE", "SET NULL"}

# V01 primary keys
rule("V01", "Every table has a primary key", len(TB), [t.name for t in TB.values() if not t.pk])

# V02 FK targets
f = []
for t, c in all_fks():
    pt, pc = c.fk.split(".")
    if pt not in TB:
        f.append(f"{t.name}.{c.name} → unknown table {pt}")
    elif not P.is_unique_col(TB[pt], pc):
        f.append(f"{t.name}.{c.name} → {pt}.{pc} is not a PK/unique key")
rule("V02", "Every FK references an existing primary or unique key", len(all_fks()), f)

# V03 type match
f = []
for t, c in all_fks():
    pt, pc = c.fk.split(".")
    ptype = TB[pt].col(pc).type
    if c.type != ptype:
        f.append(f"{t.name}.{c.name} {c.type} ≠ {pt}.{pc} {ptype}")
rule("V03", "FK column type equals referenced column type exactly (incl. UNSIGNED, CHAR length)", len(all_fks()), f)

# V04 SET NULL needs nullable; self-references nullable
f = [f"{t.name}.{c.name}" for t, c in all_fks() if c.on_delete == "SET NULL" and not c.null]
f += [f"{t.name}.{c.name} (self-reference must be nullable)" for t, c in all_fks() if c.fk.split('.')[0] == t.name and not c.null]
rule("V04", "ON DELETE SET NULL only on nullable columns; self-references nullable", len(all_fks()), f)

# V05 CHECK on FK column with cascading action (MySQL error 3823)
f, n = [], 0
for t in TB.values():
    for name, expr in P.checks(t):
        n += 1
        for colname in P.check_cols(expr):
            try:
                c = t.col(colname)
            except StopIteration:
                continue
            if c.fk and (c.on_delete in CASCADING or c.on_update in CASCADING):
                f.append(f"{t.name}.{name} uses {colname} (ON DELETE {c.on_delete}/ON UPDATE {c.on_update})")
rule("V05", "No CHECK constraint uses a column whose FK has a CASCADE/SET NULL action (MySQL error 3823)", n, f)

# V06 stored generated columns
f, n = [], 0
for t in TB.values():
    for c in t.cols:
        if c.generated:
            n += 1
            for b in P.generated_bases(c):
                bc = t.col(b)
                if bc.fk and (bc.on_delete in CASCADING or bc.on_update in CASCADING):
                    f.append(f"{t.name}.{c.name} is generated from {b}, whose FK uses ON DELETE {bc.on_delete}/ON UPDATE {bc.on_update} (MySQL error 3104/3108)")
            if c.fk:
                f.append(f"{t.name}.{c.name}: FK on a generated column")
rule("V06", "Stored generated columns: base-column FKs have no cascading action", n, f)

# V07/V08 CHECK column references valid, no AUTO_INCREMENT column
f, n = [], 0
for t in TB.values():
    names = {c.name for c in t.cols}
    for name, expr in P.checks(t):
        n += 1
        for colname in P.check_cols(expr):
            if colname not in names:
                f.append(f"{t.name}.{name}: unknown column {colname}")
            elif colname == "id" and t.pk == ["id"]:
                f.append(f"{t.name}.{name}: references AUTO_INCREMENT id")
rule("V07", "CHECK expressions reference only existing, non-AUTO_INCREMENT columns", n, f)

# V09 identifier lengths and schema-wide uniqueness of constraint names
f, seen, n = [], {}, 0
for t in TB.values():
    idents = [("table", t.name)] + [("column", c.name) for c in t.cols]
    for cols in t.uniques:
        idents.append(("unique", P.index_name(t, cols, True)[0]))
    for cols in t.indexes:
        idents.append(("index", P.index_name(t, cols, False)[0]))
    for c in t.cols:
        if c.fk:
            idents.append(("foreign", P.fk_name(t, c)[0]))
    for name, _ in P.checks(t):
        idents.append(("check", name))
    for kind, ident in idents:
        n += 1
        if len(ident) > P.MAX_IDENT:
            f.append(f"{kind} {ident} is {len(ident)} chars")
        if kind in ("foreign", "check"):  # schema-wide namespaces in MySQL
            key = (kind, ident)
            if key in seen:
                f.append(f"duplicate {kind} name {ident}")
            seen[key] = t.name
        if kind in ("unique", "index"):
            key = (t.name, ident)
            if key in seen:
                f.append(f"duplicate index name {ident} on {t.name}")
            seen[key] = t.name
rule("V09", "Identifiers ≤ 64 chars; FK and CHECK names unique schema-wide; index names unique per table", n, f)

# V10 every FK column leads an index
f = []
for t, c in all_fks():
    leads = [g[0] for g in t.uniques + t.indexes] + [t.pk[0]]
    if c.name not in leads:
        f.append(f"{t.name}.{c.name}")
rule("V10", "Every FK column is the leftmost column of an index (no implicit InnoDB index)", len(all_fks()), f)

# V11 redundant indexes (non-unique index that is a left prefix of another key)
f, n = [], 0
for t in TB.values():
    keys = [list(u) for u in t.uniques] + [list(i) for i in t.indexes] + [list(t.pk)]
    for ix in t.indexes:
        n += 1
        for other in keys:
            if other is not ix and len(other) >= len(ix) and other[:len(ix)] == ix and other != ix:
                f.append(f"{t.name} index ({', '.join(ix)}) is a prefix of ({', '.join(other)})")
                break
        if sum(1 for k in keys if k == ix) > 1:
            f.append(f"{t.name} index ({', '.join(ix)}) duplicated")
rule("V11", "No redundant index (left prefix of another key)", n, f)

# V12 index key length
f, n = [], 0
for t in TB.values():
    for cols in t.uniques + t.indexes + [t.pk]:
        n += 1
        b = sum(P.col_bytes(t.col(x).type) for x in cols)
        if b > 3072:
            f.append(f"{t.name} ({', '.join(cols)}) {b} bytes")
        for x in cols:
            if t.col(x).type in ("TEXT", "JSON"):
                f.append(f"{t.name}.{x}: TEXT/JSON in an index")
rule("V12", "Index key length ≤ 3072 bytes (utf8mb4, DYNAMIC rows); no TEXT/JSON keys", n, f)

# V13 row size
f = []
for t in TB.values():
    b = sum(P.col_bytes(c.type) for c in t.cols)
    if b > 65535:
        f.append(f"{t.name}: {b} bytes")
rule("V13", "Maximum row size < 65,535 bytes", len(TB), f)


# V14 decimal precision classes
def expected_decimal(name):
    if name in ("exchange_rate", "bank_rate", "planning_rate"):
        return S.RATE
    if name.endswith("_pct") or name in ("rate", "tax_rate", "warn_at_percent"):
        return S.PCT
    if "qty" in name or "quantity" in name or name == "reorder_level":
        return S.QTY
    if name in ("unit_cost", "avg_unit_cost", "avg_cost_after", "last_purchase_cost"):
        return S.COST
    return S.MONEY


f, n = [], 0
inventory = defaultdict(list)
for t in TB.values():
    for c in t.cols:
        if c.type.startswith("DECIMAL"):
            n += 1
            exp = expected_decimal(c.name)
            inventory[c.type].append(f"{t.name}.{c.name}")
            if c.type != exp:
                f.append(f"{t.name}.{c.name} is {c.type}, expected {exp}")
        if c.type.upper().startswith(("FLOAT", "DOUBLE", "REAL")):
            f.append(f"{t.name}.{c.name} floating point")
rule("V14", "Decimal precision by class: money (18,2), quantity (18,3), unit cost (18,4), FX rate (18,6), percent (7,4); no FLOAT/DOUBLE", n, f)

# V15 *_id without FK must be declared polymorphic
f, n = [], 0
for t in TB.values():
    for c in t.cols:
        if c.name.endswith("_id") and c.type == S.ID and not c.fk and not c.generated:
            n += 1
            if f"{t.name}.{c.name}" not in S.POLYMORPHIC:
                f.append(f"{t.name}.{c.name}")
rule("V15", "Every BIGINT *_id column has a FK, or is declared polymorphic", n, f)

# V16 defaults
f, n = [], 0
for t in TB.values():
    for c in t.cols:
        if c.default is None:
            continue
        n += 1
        d = c.default
        if c.type in ("TEXT", "JSON"):
            f.append(f"{t.name}.{c.name}: literal default on {c.type}")
        if c.enum and d.strip("'") not in c.enum:
            f.append(f"{t.name}.{c.name}: default {d} not in enum")
        if c.type == "BOOLEAN" and d not in ("0", "1"):
            f.append(f"{t.name}.{c.name}: boolean default {d}")
        if c.type.startswith(("DECIMAL", "INT", "SMALLINT", "TINYINT", "BIGINT")) and not re.fullmatch(r"-?\d+(\.\d+)?", d):
            f.append(f"{t.name}.{c.name}: numeric default {d}")
        if c.type.startswith(("VARCHAR", "CHAR")) and not (d.startswith("'") and d.endswith("'")):
            f.append(f"{t.name}.{c.name}: string default not quoted")
        if c.type.startswith(("VARCHAR", "CHAR")) and d.startswith("'"):
            ln = int(re.search(r"\d+", c.type).group())
            if len(d) - 2 > ln:
                f.append(f"{t.name}.{c.name}: default longer than column")
for t in TB.values():
    for c in t.cols:
        if c.enum:
            for v in c.enum:
                if len(v) > 30:
                    f.append(f"{t.name}.{c.name}: enum value {v} longer than VARCHAR(30)")
rule("V16", "Defaults are valid for their type; enum defaults and values fit", n, f)

# V17 reserved words (allowed only because every generated identifier is back-quoted)
reserved_cols = sorted(f"{t.name}.{c.name}" for t in TB.values() for c in t.cols if c.name.upper() in P.RESERVED)
f = []
for t in TB.values():
    for name, expr in P.checks(t):
        for w in re.findall(r"(?<!`)\b([A-Za-z_]+)\b(?!`)", re.sub(r"'[^']*'", "", expr)):
            if w.upper() in P.RESERVED and w.upper() not in P.SQL_WORDS and w in {c.name for c in t.cols}:
                f.append(f"{t.name}.{name}: unquoted reserved word {w}")
rule("V17", "Reserved-word columns are always back-quoted in generated SQL (" + ", ".join(reserved_cols) + ")", len(reserved_cols), f)

# V18 one journal entry per document link
JE_MANY = {"journal_lines.journal_entry_id", "stock_movements.journal_entry_id", "recurring_templates.last_entry_id"}
f, n = [], 0
for t, c in all_fks():
    if c.fk == "journal_entries.id" and t.name != "journal_entries":
        key = f"{t.name}.{c.name}"
        if key in JE_MANY:
            continue
        n += 1
        if not P.is_unique_col(t, c.name):
            f.append(key)
f += [] if P.is_unique_col(TB["journal_entries"], "reverses_entry_id") else ["journal_entries.reverses_entry_id"]
rule("V18", "Document → journal-entry links are one-to-one (UNIQUE), which blocks double posting", n, f)

# V19 deletion rules on financial / append-only tables
FIN = {t.name for t in TB.values()
       if any(c.fk == "journal_entries.id" and f"{t.name}.{c.name}" not in JE_MANY for c in t.cols) or t.append_only}
FIN |= {"journal_entries", "journal_lines", "bill_allocations", "payment_details"}
for t in list(TB.values()):
    for c in t.cols:
        if c.fk and c.on_delete == "CASCADE" and c.fk.split(".")[0] in FIN:
            FIN.add(t.name)  # line tables of financial headers
SETNULL_OK = {"lc_charges.landed_cost_allocation_id": "only draft allocations can be deleted; posted ones are reversed"}
f, n = [], 0
for t in TB.values():
    if t.name not in FIN:
        continue
    for c in t.cols:
        if not c.fk:
            continue
        n += 1
        key = f"{t.name}.{c.name}"
        parent = c.fk.split(".")[0]
        if c.on_delete == "SET NULL" and key not in SETNULL_OK:
            f.append(f"{key} SET NULL would alter a financial row")
        if c.on_delete == "CASCADE" and parent not in FIN | {"companies"}:
            f.append(f"{key} CASCADE from non-financial {parent}")
        if t.append_only and c.on_delete != "RESTRICT":
            f.append(f"{key} on append-only table must be RESTRICT")
rule("V19", f"Financial and append-only tables ({len(FIN)}) are never changed by a delete elsewhere: no SET NULL; CASCADE only from their own header", n, f)

# V20 ON UPDATE
f = [f"{t.name}.{c.name} ON UPDATE {c.on_update}" for t, c in all_fks() if c.on_update != "RESTRICT"]
rule("V20", "ON UPDATE RESTRICT on every FK (surrogate keys are immutable)", len(all_fks()), f)

# V21 acyclic
try:
    lv = P.levels()
    rule("V21", f"Dependency graph is acyclic ({len(lv)} migration levels; self-references created in the same migration)", len(TB), [])
except RuntimeError as e:
    rule("V21", "Dependency graph is acyclic", len(TB), [str(e)])

# V22 tenancy reachability
f, n = [], 0
for t in TB.values():
    if t.tenant or t.name in GLOBAL_TABLES:
        continue
    n += 1
    has_company = any(c.name == "company_id" for c in t.cols)
    parents = [c.fk.split(".")[0] for c in t.cols if c.fk and c.on_delete == "CASCADE"]
    if not has_company and not any(TB[p].tenant or p in GLOBAL_TABLES for p in parents):
        f.append(t.name)
rule("V22", "Every non-tenant table is global reference data or a dependant (CASCADE) of a tenant or global row", n, f)

# V23 tenant tables have an index led by company_id
f = [t.name for t in TB.values() if t.tenant and not any(g[0] == "company_id" for g in t.uniques + t.indexes)]
rule("V23", "Every tenant table has an index or unique key led by company_id", sum(1 for t in TB.values() if t.tenant), f)

# V24 junctions
f = [t.name for t in TB.values() if t.junction and len(t.pk) < 2 and not any(len(u) >= 2 for u in t.uniques)]
rule("V24", "Junction tables have a composite primary or unique key", sum(1 for t in TB.values() if t.junction), f)

# V25 append-only tables have no updated_at
f = [t.name for t in TB.values() if t.append_only and any(c.name == "updated_at" for c in t.cols)]
rule("V25", "Append-only tables have no updated_at", sum(1 for t in TB.values() if t.append_only), f)

# Nullable unique keys (multiple NULLs allowed by MySQL) - informational, must be intended
NULL_UNIQUE_OK = "optional 1:1 link; many rows may have none"
nullable_uniques = []
for t in TB.values():
    for u in t.uniques:
        nc = [x for x in u if t.col(x).null]
        if nc:
            nullable_uniques.append(f"{t.name} ({', '.join(u)}) — nullable: {', '.join(nc)}")

failed = [r for r in results if r["failures"]]
summary = {
    "tables": len(TB), "columns": sum(len(t.cols) for t in TB.values()), "fks": len(all_fks()),
    "uniques": sum(len(t.uniques) for t in TB.values()), "indexes": sum(len(t.indexes) for t in TB.values()),
    "checks": sum(len(P.checks(t)) for t in TB.values()), "levels": len(P.levels()) if not any(r["id"] == "V21" and r["failures"] for r in results) else 0,
    "decimal_inventory": {k: len(v) for k, v in sorted(inventory.items())},
    "reserved_cols": reserved_cols, "nullable_uniques": nullable_uniques, "financial_tables": sorted(FIN),
    "hashed_names": sorted({P.index_name(t, g, g in t.uniques)[0] for t in TB.values() for g in t.uniques + t.indexes if P.index_name(t, g, g in t.uniques)[1]}
                           | {P.fk_name(t, c)[0] for t, c in all_fks() if P.fk_name(t, c)[1]}),
}
json.dump({"results": results, "summary": summary}, open(os.path.join(os.path.dirname(__file__), "_validation.json"), "w"), indent=1, ensure_ascii=False)
for r in results:
    print(f"{r['id']} {'FAIL' if r['failures'] else 'pass'} ({r['checked']}) {r['title'][:90]}")
    for x in r["failures"]:
        print("     -", x)
print(f"rules={len(results)} failed={len(failed)}")
sys.exit(1 if failed else 0)
