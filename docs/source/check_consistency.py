"""Cross-document checks that the generators cannot enforce by construction.

1. Every relative Markdown link points to an existing file, and every #anchor to an existing heading or <a id>.
2. Every relationship drawn in ANY Mermaid erDiagram (generated or hand-written) is a real FK in schema.py.
3. Account-mapping keys in 06 section 3 and 04 section 11.3 are the same set.
4. Every BR/FR/NFR/TC/GAP/DEC id mentioned in prose exists in the registries or the discovery report.
5. Endpoints quoted in hand-written docs (`METHOD /path`) exist in api.py.
"""
import glob, json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
DOCS = os.path.dirname(HERE)
ROOT = os.path.dirname(DOCS)
sys.path.insert(0, HERE)
import registry as R, tests as TS, api as A

SCHEMA = json.load(open(os.path.join(HERE, "_schema.json")))
files = sorted(glob.glob(os.path.join(DOCS, "*.md"))) + [os.path.join(ROOT, "README.md")]
text = {f: open(f).read() for f in files}
errors, info = [], []


def slug(h):
    h = re.sub(r"<[^>]+>", "", h).strip().lower()
    h = re.sub(r"[`*_~]", "", h)
    h = re.sub(r"[^\w\- ]", "", h, flags=re.UNICODE)
    return h.replace(" ", "-")


anchors = {}
for f, t in text.items():
    a = set(re.findall(r'<a id="([^"]+)"', t))
    for m in re.finditer(r"^#{1,6} (.+)$", t, re.M):
        a.add(slug(m.group(1)))
    anchors[os.path.abspath(f)] = a

# 1 links
nlinks = 0
for f, t in text.items():
    body = re.sub(r"```.*?```", "", t, flags=re.S)
    for m in re.finditer(r"\]\(([^)\s]+)\)", body):
        href = m.group(1)
        if href.startswith(("http://", "https://", "mailto:")):
            continue
        nlinks += 1
        path, _, frag = href.partition("#")
        target = os.path.abspath(os.path.join(os.path.dirname(f), path)) if path else os.path.abspath(f)
        if not os.path.exists(target):
            errors.append(f"{os.path.basename(f)}: broken link {href}")
            continue
        if frag and target.endswith(".md") and frag not in anchors.get(target, set()):
            errors.append(f"{os.path.basename(f)}: missing anchor #{frag} in {os.path.basename(target)}")

# 2 ER relationships
nrel = 0
for f, t in text.items():
    for blk in re.findall(r"```mermaid\n(erDiagram.*?)```", t, re.S):
        for m in re.finditer(r"^\s*(\w+)\s+[|}o]{2}--[|{o]{2}\s+(\w+)\s*:\s*\"(\w+)\"", blk, re.M):
            parent, child, col = m.groups()
            nrel += 1
            fkt = SCHEMA.get(child, {}).get("fks", {}).get(col)
            if not fkt or fkt.split(".")[0] != parent:
                errors.append(f"{os.path.basename(f)}: ER line {parent} -> {child}.{col} is not a real FK")

# 3 mapping keys
s06 = text[os.path.join(DOCS, "06-Accounting-and-Business-Rules.md")]
s04 = text[os.path.join(DOCS, "04-Database-Design.md")]
sec3 = s06.split("## 3.")[1].split("## 4.")[0]
keys06 = set(re.findall(r"^\| `([a-z_]+)` \|", sec3, re.M))
sec113 = s04.split("### 11.3")[1].split("### 11.4")[0]
keys04 = set(re.findall(r"\| `([a-z_]+)`[^|]*\|\s*$", sec113, re.M))
missing_in_04 = keys06 - keys04
missing_in_06 = {k for k in keys04 - keys06 if k not in ("sales_default",)} - keys06
if missing_in_04:
    errors.append("mapping keys in 06 but not seeded in 04: " + ", ".join(sorted(missing_in_04)))
if missing_in_06:
    errors.append("mapping keys seeded in 04 but undocumented in 06: " + ", ".join(sorted(missing_in_06)))

# 4 ids in prose
disc = text[os.path.join(DOCS, "00-Discovery-and-Decisions.md")]
known = set(R.BR) | set(R.FR) | set(R.NFR) | {t["id"] for t in TS.TC}
known |= set(re.findall(r"\| (GAP-\d+|RISK-\d+|DEC-\d+|AS-\d+|CF-\d+) \|", disc))
for f, t in text.items():
    for i in set(re.findall(r"\b((?:BR|FR|NFR|GAP|RISK|DEC|AS|CF)-\d{2,3}|TC-[A-Z0-9]+-\d{3})\b", t)):
        if i not in known and not f.endswith("13-Review-Issues.md"):  # 13 proposes new ids
            errors.append(f"{os.path.basename(f)}: unknown id {i}")

# 5 endpoints quoted in hand-written docs
eps = {(e["method"], e["path"]) for e in A.EP}
generated = {"05-API-Specification.md", "02-UI-Inventory.md", "01-SRS.md", "13-Review-Issues.md"}  # 13 proposes new endpoints
for f, t in text.items():
    if os.path.basename(f) in generated:
        continue
    for m in re.finditer(r"`(GET|POST|PUT|PATCH|DELETE) (/[^`\s]*)`", t):
        method, path = m.groups()
        path = re.sub(r"^/api/v1", "", path).split("?")[0]
        if (method, path) not in eps and not path.startswith("/api/"):
            errors.append(f"{os.path.basename(f)}: endpoint `{method} {path}` not in api.py")

# 6 anyone who can open a page can call the GET endpoints it uses (action-gated reads excepted)
import pages as PG
PAGES = {p["code"]: p for p in PG.P}
ACTION_GATED = {("J1", "/export-jobs/{id}"),  # polled only after the user started an export (reports.export)
                ("K15", "/backups/{id}/download")}  # Owner-only action on K15 (R-11); Finance manager sees the list but not the button
nperm = 0
for e in A.EP:
    if e["method"] != "GET" or e["perm"] in ("public", "auth", "auth.self"):
        continue
    er = set(R.roles_with(e["perm"]))
    for pc in e["pages"]:
        if pc == "system" or (pc, e["path"]) in ACTION_GATED:
            continue
        p = PAGES[pc]
        pr = set(R.roles_with(p["perm"])) if p["perm"] not in ("auth.self", "public") else set()
        nperm += 1
        if pr - er:
            errors.append(f"page {pc} ({p['perm']}) reads {e['path']} ({e['perm']}) but roles {sorted(pr - er)} cannot")

print(f"page_read_permission_pairs={nperm}")
print(f"files={len(files)} links={nlinks} er_relationships={nrel} mapping_keys={len(keys06)} errors={len(errors)}")
for e in errors:
    print("ERROR", e)
json.dump({"links": nlinks, "er_relationships": nrel, "mapping_keys": sorted(keys06), "errors": errors}, open(os.path.join(HERE, "_consistency.json"), "w"), indent=1)
sys.exit(1 if errors else 0)
