"""Generate the baseline Laravel migrations, the reference MySQL 8.0 DDL and the schema contract.

Usage: python3 docs/source/build_migrations.py
Outputs:
  backend/database/migrations/2026_10_18_NNNNNN_create_<table>_table.php  (one per table, dependency order)
  backend/database/schema-contract.json   (expected physical schema; used by the PHP harness and the MySQL contract test)
  docs/schema/ledgerflow-mysql8.sql       (reference DDL, equivalent to running the migrations)

The migrations are the *baseline*. After the first deployment the files are frozen and schema changes are made
with new incremental migrations (expand -> migrate -> contract, 04 section 13); this generator is then retired
or kept only to regenerate the documentation.
"""
import glob, json, os, re, shutil, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import physical as P
import schema as S

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
MIG = os.path.join(ROOT, "database", "migrations")
CONTRACT = os.path.join(ROOT, "database", "schema-contract.json")
SQL = os.path.join(ROOT, "docs", "schema", "ledgerflow-mysql8.sql")
DATE_PREFIX = "2026_10_18"
TB = P.TB


def php_str(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def column_type(c):
    """information_schema.COLUMNS.COLUMN_TYPE as MySQL 8.0.19+ reports it."""
    T = c.type.upper()
    if T == "BOOLEAN":
        return "tinyint(1)"
    return c.type.lower()


def laravel_column(t, c):
    T = c.type.upper()
    n = php_str(c.name)
    if c.name == "id" and t.pk == ["id"]:
        call = "$table->id()"
    elif T == "BIGINT UNSIGNED":
        call = f"$table->unsignedBigInteger({n})"
    elif T == "INT UNSIGNED":
        call = f"$table->unsignedInteger({n})"
    elif T == "SMALLINT UNSIGNED":
        call = f"$table->unsignedSmallInteger({n})"
    elif T == "TINYINT UNSIGNED":
        call = f"$table->unsignedTinyInteger({n})"
    elif T == "INT":
        call = f"$table->integer({n})"
    elif T.startswith("VARCHAR"):
        call = f"$table->string({n}, {re.search(r'[0-9]+', T).group()})"
    elif T.startswith("CHAR"):
        call = f"$table->char({n}, {re.search(r'[0-9]+', T).group()})"
    elif T.startswith("DECIMAL"):
        p, s_ = P.decimal_ps(T)
        call = f"$table->decimal({n}, {p}, {s_})"
    elif T == "BOOLEAN":
        call = f"$table->boolean({n})"
    elif T == "DATE":
        call = f"$table->date({n})"
    elif T == "DATETIME":
        call = f"$table->dateTime({n})"
    elif T == "JSON":
        call = f"$table->json({n})"
    elif T == "TEXT":
        call = f"$table->text({n})"
    else:
        raise ValueError(f"{t.name}.{c.name}: no Laravel mapping for {c.type}")
    mods = []
    if c.generated:
        mods.append(f"->storedAs({php_str(c.generated)})")
        if not c.null:
            # Laravel's MySQL grammar emits NOT NULL for generated columns only when asked explicitly (found by SchemaContractTest on MySQL 8.0)
            mods.append("->nullable(false)")
    if c.null and not (c.name == "id"):
        mods.append("->nullable()")
    if c.default is not None:
        d = c.default
        if T == "BOOLEAN":
            mods.append("->default(" + ("true" if d == "1" else "false") + ")")
        elif d.startswith("'"):
            mods.append(f"->default({php_str(d[1:-1])})")
        else:
            mods.append(f"->default({d})")
    if c.name in t.pk and t.pk != ["id"] and len(t.pk) == 1:
        mods.append("->primary()")
    note = c.note
    if c.enum:
        note = ("One of: " + ", ".join(c.enum) + (". " + note if note else ""))
    if note:
        mods.append(f"->comment({php_str(note[:1000])})")
    return call + "".join(mods) + ";"


def fk_action(a):
    return {"RESTRICT": "restrictOnDelete()", "CASCADE": "cascadeOnDelete()", "SET NULL": "nullOnDelete()"}[a]


def migration(t, seq, level, total):
    deps = sorted(P.deps_of(t))
    selfref = [c.name for c in t.cols if c.fk and c.fk.split(".")[0] == t.name]
    lines = []
    lines.append("<?php\n")
    lines.append("use Illuminate\\Database\\Migrations\\Migration;")
    lines.append("use Illuminate\\Database\\Schema\\Blueprint;")
    lines.append("use Illuminate\\Support\\Facades\\DB;")
    lines.append("use Illuminate\\Support\\Facades\\Schema;\n")
    lines.append("/**")
    lines.append(f" * Table `{t.name}` ({t.module}): migration {seq} of {total}, dependency level {level}.")
    lines.append(f" * {t.purpose}")
    lines.append(f" * Depends on: {', '.join(deps) or 'nothing'}." + (f" Self-reference: {', '.join(selfref)}." if selfref else ""))
    lines.append(" *")
    lines.append(" * Generated from docs/source/schema.py by docs/source/build_migrations.py (baseline schema).")
    lines.append(" * After the first deployment, never edit this file: add a new migration instead.")
    lines.append(" */")
    lines.append("return new class extends Migration")
    lines.append("{")
    lines.append("    public function up(): void")
    lines.append("    {")
    lines.append(f"        Schema::create({php_str(t.name)}, function (Blueprint $table) {{")
    lines.append("            $table->engine('InnoDB');")
    lines.append("            $table->charset('utf8mb4');")
    lines.append("            $table->collation('utf8mb4_0900_ai_ci');")
    lines.append(f"            $table->comment({php_str(t.purpose[:2000])});\n")
    for c in t.cols:
        lines.append("            " + laravel_column(t, c))
    if len(t.pk) > 1:
        lines.append(f"\n            $table->primary([{', '.join(php_str(x) for x in t.pk)}]);")
    if t.uniques or t.indexes:
        lines.append("")
    for u in t.uniques:
        lines.append(f"            $table->unique([{', '.join(php_str(x) for x in u)}], {php_str(P.index_name(t, u, True)[0])});")
    for ix in t.indexes:
        lines.append(f"            $table->index([{', '.join(php_str(x) for x in ix)}], {php_str(P.index_name(t, ix, False)[0])});")
    fks = [c for c in t.cols if c.fk]
    if fks:
        lines.append("")
    for c in fks:
        pt, pc = c.fk.split(".")
        lines.append(f"            $table->foreign({php_str(c.name)}, {php_str(P.fk_name(t, c)[0])})->references({php_str(pc)})->on({php_str(pt)})"
                     f"->{fk_action(c.on_delete)}->restrictOnUpdate();")
    lines.append("        });")
    cks = P.checks(t)
    if cks:
        lines.append("")
        lines.append("        // Laravel's schema builder has no CHECK API; MySQL 8.0.16+ and MariaDB enforce these.")
        lines.append("        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {")
        lines.append("            DB::statement(<<<'SQL'")
        body = ",\n".join(f"                    ADD CONSTRAINT {P.q(name)} CHECK ({expr})" for name, expr in cks)
        lines.append(f"                ALTER TABLE {P.q(t.name)}")
        lines.append(body)
        lines.append("                SQL);")
        lines.append("        }")
    lines.append("    }\n")
    lines.append("    public function down(): void")
    lines.append("    {")
    lines.append(f"        Schema::dropIfExists({php_str(t.name)});")
    lines.append("    }")
    lines.append("};")
    return "\n".join(lines) + "\n"


def sql_default(c):
    if c.default is None:
        return " NULL" if c.null else ""
    d = c.default
    return f" DEFAULT {d}" if d.startswith("'") else f" DEFAULT {d if c.type.upper() != 'BOOLEAN' else d}"


def sql_col(t, c):
    T = column_type(c)
    s = f"  {P.q(c.name)} {T}"
    if c.generated:
        s += f" GENERATED ALWAYS AS ({c.generated}) STORED"
    s += " NULL" if c.null else " NOT NULL"
    if c.name == "id" and t.pk == ["id"]:
        s += " AUTO_INCREMENT"
    if c.default is not None:
        s += f" DEFAULT {c.default}"
    note = c.note if not c.enum else "One of: " + ", ".join(c.enum) + (". " + c.note if c.note else "")
    if note:
        s += " COMMENT '" + note[:1000].replace("'", "''") + "'"
    return s


def ddl(t):
    parts = [sql_col(t, c) for c in t.cols]
    parts.append(f"  PRIMARY KEY ({', '.join(P.q(x) for x in t.pk)})")
    for u in t.uniques:
        parts.append(f"  UNIQUE KEY {P.q(P.index_name(t, u, True)[0])} ({', '.join(P.q(x) for x in u)})")
    for ix in t.indexes:
        parts.append(f"  KEY {P.q(P.index_name(t, ix, False)[0])} ({', '.join(P.q(x) for x in ix)})")
    for c in t.cols:
        if c.fk:
            pt, pc = c.fk.split(".")
            parts.append(f"  CONSTRAINT {P.q(P.fk_name(t, c)[0])} FOREIGN KEY ({P.q(c.name)}) REFERENCES {P.q(pt)} ({P.q(pc)}) "
                         f"ON DELETE {c.on_delete} ON UPDATE {c.on_update}")
    for name, expr in P.checks(t):
        parts.append(f"  CONSTRAINT {P.q(name)} CHECK ({expr})")
    return (f"CREATE TABLE {P.q(t.name)} (\n" + ",\n".join(parts) +
            f"\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='{t.purpose[:2000].replace(chr(39), chr(39) * 2)}';\n")


def contract(t, seq, level, fname):
    return {
        "seq": seq, "level": level, "migration": fname, "module": t.module,
        "columns": [{"name": c.name, "type": column_type(c), "nullable": bool(c.null), "default": None if c.default is None else c.default.strip("'"),
                     "generated": c.generated, "auto_increment": c.name == "id" and t.pk == ["id"]} for c in t.cols],
        "primary": list(t.pk),
        "unique": {P.index_name(t, u, True)[0]: list(u) for u in t.uniques},
        "index": {P.index_name(t, ix, False)[0]: list(ix) for ix in t.indexes},
        "foreign": {P.fk_name(t, c)[0]: {"column": c.name, "references_table": c.fk.split(".")[0], "references_column": c.fk.split(".")[1],
                                         "on_delete": c.on_delete, "on_update": c.on_update} for c in t.cols if c.fk},
        "check": {name: expr for name, expr in P.checks(t)},
    }


def main():
    lv = P.levels()
    level_of = {n: i for i, l in enumerate(lv) for n in l}
    order = P.order()
    # Remove only the files this generator owns (2026_10_18_*); framework migrations such as 0001_01_01_* stay.
    os.makedirs(MIG, exist_ok=True)
    for f in glob.glob(os.path.join(MIG, "2026_10_18_*_create_*_table.php")):
        os.remove(f)
    os.makedirs(os.path.dirname(SQL), exist_ok=True)
    out_contract, sql = {}, ["-- LedgerFlow ERP: reference MySQL 8.0 DDL, generated from docs/source/schema.py.",
                             "-- Equivalent to running backend/database/migrations in order. For review and CI diffing; deploy with `php artisan migrate`.",
                             "SET NAMES utf8mb4;", ""]
    for seq, name in enumerate(order, 1):
        t = TB[name]
        fname = f"{DATE_PREFIX}_{seq * 100:06d}_create_{name}_table.php"
        open(os.path.join(MIG, fname), "w").write(migration(t, seq, level_of[name], len(order)))
        out_contract[name] = contract(t, seq, level_of[name], fname)
        sql.append(f"-- {seq}. {name} (level {level_of[name]})")
        sql.append(ddl(t))
    json.dump({"generated_from": "docs/source/schema.py", "mysql": "8.0", "charset": "utf8mb4", "collation": "utf8mb4_0900_ai_ci",
               "tables": out_contract}, open(CONTRACT, "w"), indent=1)
    open(SQL, "w").write("\n".join(sql))
    print(f"migrations={len(order)} contract_tables={len(out_contract)} sql_bytes={sum(len(x) for x in sql)}")


if __name__ == "__main__":
    main()
