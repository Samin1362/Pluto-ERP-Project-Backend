"""Generate contract/contract.gen.ts (copied into the frontend by its scripts/sync-contract.sh) from schemas.py - the same definitions that produce openapi.yaml -
so the React client's types cannot drift from the approved contract.

Mapping (mirrors build_docs.comp_type): `!` required, `?` nullable (and optional), neither = optional.
Money, quantities, rates and percentages are decimal STRINGS (05 §1), never numbers.
Usage: python3 docs/source/build_ts_types.py
"""
import os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import errors as ER, schemas as SC

OUT = os.path.join(os.path.dirname(os.path.dirname(HERE)), "contract", "contract.gen.ts")
SCALAR = {"id": "number", "int": "number", "bool": "boolean", "str": "string", "text": "string", "date": "string", "datetime": "string",
          "email": "string", "ccy": "string", "uuid": "string", "money": "Decimal", "rate": "Decimal", "qty": "Decimal", "cost": "Decimal",
          "pct": "Decimal", "obj": "Record<string, unknown>", "errcode": "ErrorCode", "errmap": "Record<string, string[]>"}


def ts_type(spec):
    t = spec.replace("?", "").replace("!", "")
    if t.startswith("["):
        inner = ts_type(t[1:-1])
        return f"Array<{inner}>"
    if t.startswith("ref:"):
        return t[4:]
    if t.startswith("enum:"):
        return " | ".join(f"'{v}'" for v in t[5:].split("|"))
    if t.startswith("str:"):
        return "string"
    if t not in SCALAR:
        raise SystemExit(f"unknown type {spec}")
    return SCALAR[t]


lines = ["// GENERATED from docs/source/schemas.py by docs/source/build_ts_types.py - do not edit by hand.",
         "// Field types follow docs/openapi.yaml (API contract 1.0.0-rc.1).", "",
         "/** Decimal as string, e.g. \"118450.00\". Money never travels as a JSON number (05 §1). */",
         "export type Decimal = string", "",
         "export type ErrorCode =\n  | " + "\n  | ".join(f"'{c}'" for c in sorted(ER.E)), ""]
for name, (desc, props) in sorted(SC.S.items()):
    if name == "Binary":
        continue
    lines.append(f"/** {desc.replace('*/', '*\\/')} */")
    lines.append(f"export interface {name} {{")
    for k, v in props.items():
        key = k if k.isidentifier() else f"'{k}'"
        typ = ts_type(v)
        if "!" in v:
            lines.append(f"  {key}: {typ}")
        elif "?" in v:
            lines.append(f"  {key}?: {typ} | null")
        else:
            lines.append(f"  {key}?: {typ}")
    lines.append("}")
    lines.append("")
os.makedirs(os.path.dirname(OUT), exist_ok=True)
open(OUT, "w").write("\n".join(lines))
print(f"{len(SC.S) - 1} interfaces -> {OUT}")
