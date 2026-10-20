"""Compare the model recorded by harness.php with backend/database/schema-contract.json (from schema.py).

Usage: python3 compare.py <harness_model.json> <schema-contract.json>   (non-zero exit on any difference)
"""
import json, sys

model, contract = json.load(open(sys.argv[1])), json.load(open(sys.argv[2]))["tables"]
diffs, counts = [], {"tables": 0, "columns": 0, "primary": 0, "unique": 0, "index": 0, "foreign": 0, "check": 0}

expected_order = [v["migration"] for k, v in sorted(contract.items(), key=lambda kv: kv[1]["seq"])]
if model["order"] != expected_order:
    diffs.append("migration file order differs from dependency order")

for name, exp in contract.items():
    got = model["tables"].get(name)
    if got is None:
        diffs.append(f"{name}: missing")
        continue
    counts["tables"] += 1
    o = got["options"]
    if (o.get("engine"), o.get("charset"), o.get("collation")) != ("InnoDB", "utf8mb4", "utf8mb4_0900_ai_ci"):
        diffs.append(f"{name}: table options {o}")
    ec = [(c["name"], c["type"], c["nullable"], c["default"], c["generated"], c["auto_increment"]) for c in exp["columns"]]
    gc = [(c["name"], c["type"], c["nullable"], c["default"], c["generated"], c["auto_increment"]) for c in got["columns"]]
    if ec != gc:
        for a, b in zip(ec, gc):
            if a != b:
                diffs.append(f"{name}: column expected {a} got {b}")
        if len(ec) != len(gc):
            diffs.append(f"{name}: {len(ec)} columns expected, {len(gc)} built")
    counts["columns"] += len(gc)
    for key in ("primary", "unique", "index", "foreign", "check"):
        e, g = exp[key], got[key]
        if isinstance(e, dict):
            g = g if isinstance(g, dict) else {}
            if e != g:
                for k in sorted(set(e) | set(g)):
                    if e.get(k) != g.get(k):
                        diffs.append(f"{name}.{key} {k}: expected {e.get(k)} got {g.get(k)}")
            counts[key] += len(g)
        else:
            if e != g:
                diffs.append(f"{name}.primary expected {e} got {g}")
            counts[key] += 1
extra = set(model["tables"]) - set(contract)
diffs += [f"{x}: not in contract" for x in sorted(extra)]
print("compared " + ", ".join(f"{k}={v}" for k, v in counts.items()) + f" differences={len(diffs)}")
for d in diffs[:50]:
    print("DIFF", d)
json.dump({"counts": counts, "differences": diffs}, open(sys.argv[1].replace(".json", "_compare.json"), "w"), indent=1)
sys.exit(1 if diffs else 0)
