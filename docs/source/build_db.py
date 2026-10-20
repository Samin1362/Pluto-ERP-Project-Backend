"""Generate docs/04-Database-Design.md from schema.py and run schema integrity checks.

Usage:  python3 docs/source/build_db.py
Exit code is non-zero if any integrity check fails.
"""
import os, re, sys
from collections import defaultdict, OrderedDict

sys.path.insert(0, os.path.dirname(__file__))
import schema as S

HERE = os.path.dirname(os.path.abspath(__file__))
DOCS = os.path.dirname(HERE)
TB = OrderedDict((t.name, t) for t in S.TABLES)
SYSTEM_COLS = {"company_id", "created_by", "updated_by"}
errors = []

# ---------------------------------------------------------------- integrity checks
def key_of(table, col):
    t = TB[table]
    if t.pk == [col]:
        return True
    return any(u == [col] for u in t.uniques)

for t in TB.values():
    names = [c.name for c in t.cols]
    if len(names) != len(set(names)):
        errors.append(f"{t.name}: duplicate column names")
    for c in t.cols:
        if c.fk:
            pt, pc = c.fk.split(".")
            if pt not in TB:
                errors.append(f"{t.name}.{c.name}: FK to unknown table {pt}")
            elif pc not in [x.name for x in TB[pt].cols]:
                errors.append(f"{t.name}.{c.name}: FK to unknown column {pt}.{pc}")
            elif not key_of(pt, pc):
                errors.append(f"{t.name}.{c.name}: FK target {pt}.{pc} is not a primary or unique key")
            if c.on_delete == "SET NULL" and not c.null:
                errors.append(f"{t.name}.{c.name}: ON DELETE SET NULL on a NOT NULL column")
            if "MONEY" in c.type or c.type.startswith(("FLOAT", "DOUBLE")):
                errors.append(f"{t.name}.{c.name}: float type")
    for grp in t.uniques + t.indexes + [t.pk]:
        for col in grp:
            if col not in names:
                errors.append(f"{t.name}: index/unique references unknown column {col}")
    for c in t.cols:
        if c.type.upper().startswith(("FLOAT", "DOUBLE", "REAL")):
            errors.append(f"{t.name}.{c.name}: floating-point type is forbidden")
    if t.junction and t.pk == ["id"] and not t.uniques:
        errors.append(f"{t.name}: junction table without a unique pair")

# dependency graph (ignore self references)
deps = {t: set() for t in TB}
for t in TB.values():
    for c in t.cols:
        if c.fk:
            pt = c.fk.split(".")[0]
            if pt != t.name:
                deps[t.name].add(pt)

# topological levels
levels, placed = [], set()
remaining = set(TB)
while remaining:
    lvl = sorted(x for x in remaining if deps[x] <= placed)
    if not lvl:
        errors.append("Circular foreign-key dependency among: " + ", ".join(sorted(remaining)))
        break
    levels.append(lvl)
    placed |= set(lvl)
    remaining -= set(lvl)

# ---------------------------------------------------------------- helpers
def card(child, col):
    unique = child.pk == [col.name] or any(u == [col.name] for u in child.uniques)
    if child.junction and col.name in child.pk + (child.uniques[0] if child.uniques else []):
        return "many-to-many (via junction)" if not unique else "one-to-one"
    return ("one-to-one" if unique else "one-to-many") + ("" if not col.null else " (optional)")

def mer_type(t):
    t = t.upper()
    for k, v in [("BIGINT", "bigint"), ("SMALLINT", "smallint"), ("TINYINT", "tinyint"), ("INT", "int"), ("DECIMAL", "decimal"),
                 ("VARCHAR", "varchar"), ("CHAR", "char"), ("BOOLEAN", "boolean"), ("DATETIME", "datetime"), ("DATE", "date"),
                 ("JSON", "json"), ("TEXT", "text")]:
        if t.startswith(k):
            return v
    return "string"

def rel_line(child, col, show=True):
    pt = col.fk.split(".")[0]
    unique = child.pk == [col.name] or any(u == [col.name] for u in child.uniques)
    left = "|o" if col.null else "||"
    right = "o|" if unique else "o{"
    return f'    {pt} {left}--{right} {child.name} : "{col.name}"'

def er_diagram(tables, with_attrs=True, external=True, skip_system=True):
    names = {t.name for t in tables}
    out = ["```mermaid", "erDiagram"]
    rels, ext = [], set()
    for t in tables:
        for col in t.cols:
            if not col.fk or (skip_system and col.name in SYSTEM_COLS):
                continue
            pt = col.fk.split(".")[0]
            if pt == "users" and col.name not in ("user_id",):
                continue  # actor columns (posted_by, decided_by...) omitted for legibility
            if pt not in names:
                if not external:
                    continue
                ext.add(pt)
            rels.append(rel_line(t, col))
    for t in tables:
        if with_attrs:
            out.append(f"    {t.name} {{")
            for col in t.cols:
                if col.name in ("created_at", "updated_at", "created_by", "updated_by", "lock_version", "deleted_at"):
                    continue
                tag = "PK" if col.name in t.pk else ("FK" if col.fk else "")
                if tag == "PK" and col.fk:
                    tag = "PK, FK"
                if not tag and any(u == [col.name] for u in t.uniques):
                    tag = "UK"
                out.append(f"        {mer_type(col.type)} {col.name}{(' ' + tag) if tag else ''}")
            out.append("    }")
    for e in sorted(ext):
        out.append(f"    {e} {{\n        bigint id PK\n    }}" if TB[e].pk == ["id"] else f"    {e} {{\n        char code PK\n    }}")
    out += sorted(set(rels))
    out.append("```")
    return "\n".join(out)

def col_row(t, c):
    key = []
    if c.name in t.pk: key.append("PK")
    if c.fk: key.append(f"FK → `{c.fk}`")
    if any(u == [c.name] for u in t.uniques): key.append("UQ")
    note = c.note
    if c.enum:
        note = ("enum: " + " \\| ".join(c.enum)) + (f". {note}" if note else "")
    rule = ""
    if c.fk:
        rule = f"ON DELETE {c.on_delete} / ON UPDATE {c.on_update}"
    default = c.default if c.default is not None else ("—" if not c.null else "NULL")
    return f"| `{c.name}` | {c.type} | {'yes' if c.null else 'no'} | {default} | {' · '.join(key) or '—'} | {rule or '—'} | {note} |"

# ---------------------------------------------------------------- render
intro = open(os.path.join(HERE, "db_intro.md")).read()
outro = open(os.path.join(HERE, "db_outro.md")).read()
o = [intro]

# overview ER (core only, no attributes)
core = ["companies", "branches", "users", "company_users", "roles", "permissions", "role_permissions", "account_groups", "ledgers",
        "journal_entries", "journal_lines", "bill_allocations", "parties", "products", "warehouses", "stock_balances", "stock_movements",
        "sales_invoices", "sales_invoice_lines", "purchase_bills", "purchase_bill_lines", "money_accounts", "payment_details",
        "letters_of_credit", "lc_charges", "lc_payments", "landed_cost_allocations"]
o.append("\n## 5. Entity-relationship diagrams\n")
o.append("Diagrams are generated from the same definition as the data dictionary (section 6), so every line in a diagram corresponds to a real foreign key. "
         "To stay readable they omit three column families that exist on most tables: `company_id` (→ companies), `created_by`/`updated_by` and actor columns such as `posted_by` (→ users). "
         "Notation: `||--o{` mandatory one-to-many, `|o--o{` optional one-to-many, `||--o|` one-to-one, `|o--o|` optional one-to-one. Tables from other modules appear as stubs with only their key.\n")
o.append("### 5.1 Core overview (entities only)\n")
o.append("The backbone: tenancy and access on top; master data (parties, products, warehouses, ledgers) in the middle; documents post to journal entries, which hold the lines every report reads.\n")
o.append(er_diagram([TB[n] for n in core], with_attrs=False, external=False))
for i, m in enumerate(S.MODULE_ORDER, 2):
    ts = [t for t in TB.values() if t.module == m]
    o.append(f"\n### 5.{i} {m} module\n")
    o.append(er_diagram(ts))

# data dictionary
o.append("\n## 6. Data dictionary\n")
o.append("Standard columns are listed in each table: `id` (PK), `company_id` (tenant), `lock_version` (optimistic locking, where editable), `created_by`/`updated_by`, `created_at`/`updated_at`. "
         "All FKs are indexed. \"Default —\" means a value is required on insert.\n")
n = 0
for m in S.MODULE_ORDER:
    o.append(f"\n### 6.{S.MODULE_ORDER.index(m) + 1} {m}\n")
    for t in [x for x in TB.values() if x.module == m]:
        n += 1
        flags = []
        if t.tenant: flags.append("tenant-scoped")
        if t.junction: flags.append("junction")
        if t.append_only: flags.append("append-only")
        if any(c.name == "lock_version" for c in t.cols): flags.append("optimistic lock")
        if any(c.name == "deleted_at" for c in t.cols): flags.append("soft delete")
        o.append(f"\n#### `{t.name}`\n\n**Purpose:** {t.purpose}  \n**Flags:** {', '.join(flags) or '—'}  \n**Primary key:** ({', '.join(t.pk)})\n")
        o.append("| Column | Type | Null | Default | Keys | FK rule | Meaning / rules |\n|---|---|---|---|---|---|---|")
        for c in t.cols:
            o.append(col_row(t, c))
        extra = []
        for u in t.uniques: extra.append(f"- **Unique** ({', '.join(u)})")
        for ix in t.indexes: extra.append(f"- Index ({', '.join(ix)})")
        for ck in t.checks: extra.append(f"- **Check** `{ck}`")
        for c in t.cols:
            if c.enum: extra.append(f"- **Check** `{c.name} IN ({', '.join(repr(v) for v in c.enum)})`")
        rel = [f"- `{c.name}` → `{c.fk.split('.')[0]}`: {card(t, c)}" for c in t.cols if c.fk and c.name not in SYSTEM_COLS]
        o.append("\n" + "\n".join(extra))
        if rel:
            o.append("\n**Relationships:**\n" + "\n".join(rel))
o.append(f"\n_Total: {n} application tables._\n")

# relationship matrix
o.append("\n## 7. Relationship matrix\n")
o.append("Every foreign key except the uniform `company_id`, `created_by` and `updated_by` columns (on all tenant tables: many rows to one company/user, RESTRICT and SET NULL respectively).\n")
o.append("| # | Child table | FK column | Parent table | Cardinality (parent : child) | Required | On delete |\n|---|---|---|---|---|---|---|")
k = 0
for t in TB.values():
    for c in t.cols:
        if c.fk and c.name not in SYSTEM_COLS:
            k += 1
            pt = c.fk.split('.')[0]
            o.append(f"| {k} | `{t.name}` | `{c.name}` | `{pt}` | {card(t, c)} | {'no' if c.null else 'yes'} | {c.on_delete} |")
o.append("\n**Many-to-many relationships** are only ever implemented through junction tables:\n")
for t in TB.values():
    if t.junction:
        parents = (["companies"] if t.tenant else []) + [c.fk.split('.')[0] for c in t.cols if c.fk and c.name not in SYSTEM_COLS]
        o.append(f"- `{parents[0]}` ⇄ `{parents[1]}` via **`{t.name}`**" + (f" (payload: `{parents[2]}`)" if len(parents) > 2 else ""))

# dependency list
o.append("\n## 8. Foreign-key dependency list\n")
o.append("For each table, the tables it references (must exist first). Self-references are noted separately and are created in the same migration.\n")
o.append("| Table | Depends on | Self-reference |\n|---|---|---|")
for t in TB.values():
    selfr = [c.name for c in t.cols if c.fk and c.fk.split('.')[0] == t.name]
    o.append(f"| `{t.name}` | {', '.join('`'+d+'`' for d in sorted(deps[t.name])) or '—'} | {', '.join(selfr) or '—'} |")

# migration order
o.append("\n## 9. Migration order\n")
o.append("Computed by topological sort of section 8, so every referenced table is created first. Tables in the same level are independent of each other. "
         "Laravel file names use a date prefix plus a level-ordered sequence, e.g. `2026_10_18_000100_create_currencies_table.php`. Each migration creates one table with all of its columns, indexes, CHECKs and FKs; no migration needs a later ALTER to close a cycle, because the graph has none. The generated files, constraint names and verification are in [14 · Physical schema and migrations](14-Physical-Schema-and-Migrations.md).\n")
seq = 0
for i, lvl in enumerate(levels):
    o.append(f"\n**Level {i}**\n")
    for t in lvl:
        seq += 1
        o.append(f"{seq}. `{t}`")

o.append(outro)
md = "\n".join(o)
open(os.path.join(DOCS, "04-Database-Design.md"), "w").write(md)

# export machine-readable artefacts for cross-checks
import json
json.dump({t.name: {"module": t.module, "cols": [c.name for c in t.cols],
                    "fks": {c.name: c.fk for c in t.cols if c.fk}} for t in TB.values()},
          open(os.path.join(HERE, "_schema.json"), "w"), indent=1)

print(f"tables={len(TB)} fks={sum(1 for t in TB.values() for c in t.cols if c.fk)} levels={len(levels)} errors={len(errors)}")
for e in errors:
    print("ERROR", e)
sys.exit(1 if errors else 0)
