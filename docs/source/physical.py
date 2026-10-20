"""Physical MySQL 8.0 model derived from schema.py.

Shared by validate_schema.py, build_migrations.py and build_db.py so that constraint names,
CHECK expressions, dependency order and cardinalities are computed in exactly one place.
"""
import hashlib, os, re, sys
from collections import OrderedDict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import schema as S

TB = OrderedDict((t.name, t) for t in S.TABLES)
MAX_IDENT = 64

# MySQL 8.0 reserved words that could plausibly be column names (subset of the official list).
RESERVED = set("""ACCESSIBLE ADD ALL ALTER ANALYZE AND AS ASC BEFORE BETWEEN BIGINT BINARY BLOB BOTH BY CALL CASCADE CASE
CHANGE CHAR CHARACTER CHECK COLLATE COLUMN CONDITION CONSTRAINT CONTINUE CONVERT CREATE CROSS CUBE CURRENT_DATE
CURRENT_TIME CURRENT_TIMESTAMP CURRENT_USER CURSOR DATABASE DATABASES DEC DECIMAL DECLARE DEFAULT DELAYED DELETE DESC
DESCRIBE DETERMINISTIC DISTINCT DIV DOUBLE DROP DUAL EACH ELSE ELSEIF EMPTY ENCLOSED ESCAPED EXCEPT EXISTS EXIT
EXPLAIN FALSE FETCH FLOAT FOR FORCE FOREIGN FROM FULLTEXT FUNCTION GENERATED GET GRANT GROUP GROUPING GROUPS HAVING
IF IGNORE IN INDEX INFILE INNER INOUT INSERT INT INTEGER INTERVAL INTO IS ITERATE JOIN KEY KEYS KILL LAG LATERAL LEAD
LEADING LEAVE LEFT LIKE LIMIT LINEAR LINES LOAD LOCALTIME LOCALTIMESTAMP LOCK LONG LOOP MATCH MAXVALUE MOD MODIFIES
NATURAL NOT NULL NUMERIC OF ON OPTIMIZE OPTION OR ORDER OUT OUTER OVER PARTITION PRECISION PRIMARY PROCEDURE PURGE
RANGE RANK READ READS REAL RECURSIVE REFERENCES REGEXP RELEASE RENAME REPEAT REPLACE REQUIRE RESIGNAL RESTRICT RETURN
REVOKE RIGHT RLIKE ROW ROWS SCHEMA SCHEMAS SELECT SENSITIVE SEPARATOR SET SHOW SIGNAL SMALLINT SPATIAL SPECIFIC SQL
STARTING STORED SYSTEM TABLE TERMINATED THEN TINYINT TO TRAILING TRIGGER TRUE UNDO UNION UNIQUE UNLOCK UNSIGNED
UPDATE USAGE USE USING VALUES VARBINARY VARCHAR VARYING VIRTUAL WHEN WHERE WHILE WINDOW WITH WRITE XOR ZEROFILL""".split())

SQL_WORDS = {"AND", "OR", "NOT", "IS", "NULL", "IN", "IFNULL", "TRUE", "FALSE", "BETWEEN", "LIKE"}


def q(ident):
    return f"`{ident}`"


def short(name, kind_suffix):
    """Laravel's default name ({table}_{cols}_{suffix}); hashed when longer than 64 characters."""
    full = f"{name}_{kind_suffix}".lower()
    if len(full) <= MAX_IDENT:
        return full, False
    h = hashlib.sha1(full.encode()).hexdigest()[:8]
    keep = MAX_IDENT - len(kind_suffix) - 10
    return f"{full[:keep]}_{h}_{kind_suffix}", True


def index_name(t, cols, unique):
    return short(f"{t.name}_{'_'.join(cols)}", "unique" if unique else "index")


def fk_name(t, col):
    return short(f"{t.name}_{col.name}", "foreign")


def check_cols(expr):
    no_str = re.sub(r"'[^']*'", "", expr)
    toks = re.findall(r"`?([A-Za-z_][A-Za-z0-9_]*)`?", no_str)
    return [x for x in toks if x.upper() not in SQL_WORDS and not x.isdigit()]


def quote_expr(t, expr):
    """Back-quote every column identifier of the table inside a CHECK expression."""
    names = {c.name for c in t.cols}
    parts = re.split(r"('[^']*')", expr)
    out = []
    for p in parts:
        if p.startswith("'"):
            out.append(p)
        else:
            out.append(re.sub(r"(?<![`A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]*)(?![`A-Za-z0-9_(])",
                              lambda m: q(m.group(1)) if m.group(1) in names else m.group(1), p))
    return "".join(out)


def checks(t):
    """All CHECK constraints of a table as (name, expression), enum checks included."""
    out = []
    for c in t.cols:
        if c.enum:
            vals = ", ".join("'" + v + "'" for v in c.enum)
            out.append((short(f"{t.name}_{c.name}", "check")[0], f"{q(c.name)} IN ({vals})"))
    for i, ck in enumerate(t.checks, 1):
        out.append((short(f"{t.name}_rule{i}", "check")[0], quote_expr(t, ck)))
    return out


def deps_of(t):
    return {c.fk.split(".")[0] for c in t.cols if c.fk and c.fk.split(".")[0] != t.name}


def levels():
    placed, remaining, lv = set(), set(TB), []
    while remaining:
        lvl = sorted(x for x in remaining if deps_of(TB[x]) <= placed)
        if not lvl:
            raise RuntimeError("cycle among " + ", ".join(sorted(remaining)))
        lv.append(lvl)
        placed |= set(lvl)
        remaining -= set(lvl)
    return lv


def order():
    return [n for lvl in levels() for n in lvl]


def is_unique_col(t, name):
    return t.pk == [name] or any(u == [name] for u in t.uniques)


def cardinality(t, c):
    """(parent side, child side) in crow's-foot words."""
    parent = "zero or one" if c.null else "exactly one"
    child = "zero or one" if is_unique_col(t, c.name) else "zero or many"
    return parent, child


def decimal_ps(type_):
    m = re.match(r"DECIMAL\((\d+),(\d+)\)", type_)
    return (int(m.group(1)), int(m.group(2))) if m else None


def col_bytes(type_):
    """Upper bound of a column's in-row storage (utf8mb4), used for row-size and index-length checks."""
    T = type_.upper()
    if T.startswith("VARCHAR"):
        n = int(re.search(r"\d+", T).group()) * 4
        return n + (1 if n <= 255 else 2)
    if T.startswith("CHAR"):
        return int(re.search(r"\d+", T).group()) * 4
    if T.startswith("DECIMAL"):
        p, s_ = decimal_ps(T)
        def digits(d):
            return (d // 9) * 4 + [0, 1, 1, 2, 2, 3, 3, 4, 4, 4][d % 9]
        return digits(p - s_) + digits(s_)
    for k, v in [("BIGINT", 8), ("SMALLINT", 2), ("TINYINT", 1), ("INT", 4), ("BOOLEAN", 1), ("DATETIME", 5), ("DATE", 3),
                 ("JSON", 12), ("TEXT", 12)]:
        if T.startswith(k):
            return v
    raise ValueError(type_)


def generated_bases(c):
    return re.findall(r"`([A-Za-z_][A-Za-z0-9_]*)`", c.generated or "")
