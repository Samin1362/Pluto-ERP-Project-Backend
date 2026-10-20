"""Generate PHP mirrors of contract data so the backend cannot drift from the documentation:
  backend/app/Support/Problem/ErrorCatalogue.php  from errors.py (error codes, statuses, titles)
  backend/app/Domain/Access/RoleTemplates.php      from registry.py (permission catalogue and K11 role matrix)
Usage: python3 docs/source/build_php_contract.py"""
import os, sys
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import errors as ER, registry as R
out = os.path.join(os.path.dirname(os.path.dirname(HERE)), "app", "Support", "Problem", "ErrorCatalogue.php")
lines = ["<?php", "", "namespace App\\Support\\Problem;", "",
         "/**", " * GENERATED from docs/source/errors.py by docs/source/build_php_contract.py. Do not edit by hand.",
         " * code => [HTTP status, title]. Codes are stable API (05 §3).", " */",
         "final class ErrorCatalogue", "{", "    public const CODES = ["]
for code, (status, title, refs) in sorted(ER.E.items()):
    t = title.replace("\\", "\\\\").replace("'", "\\'")
    lines.append(f"        '{code}' => [{status}, '{t}'],")
lines += ["    ];", "",
          "    public static function status(string $code): int", "    {", "        return self::CODES[$code][0] ?? 500;", "    }", "",
          "    public static function title(string $code): string", "    {", "        return self::CODES[$code][1] ?? 'Something went wrong';", "    }", "}", ""]
open(out, "w").write("\n".join(lines))
print(f"{len(ER.E)} codes -> {out}")

# ---- role templates
role_out = os.path.join(os.path.dirname(os.path.dirname(HERE)), "app", "Domain", "Access", "RoleTemplates.php")
perms = [p for p in R.ALL_PERMS if p != "auth.self"]
def held(role):
    out = []
    for p in perms:
        if p in R.SPECIAL_PERMS:
            if role in R.SPECIAL_PERMS[p]:
                out.append(p)
        else:
            mod, act = p.split(".")
            letter = [k for k, v in R.LETTER.items() if v == act][0]
            if letter in R.ROLE_MATRIX[role].get(mod, ""):
                out.append(p)
    return out
L = ["<?php", "", "namespace App\\Domain\\Access;", "", "/**",
     " * GENERATED from docs/source/registry.py by docs/source/build_php_contract.py. Do not edit by hand.",
     " * Permission catalogue (04 §11.1) and the K11 role matrix (01 §4.2) seeded for every new company.", " */",
     "final class RoleTemplates", "{", "    public const PERMISSIONS = ["]
for p in perms:
    L.append(f"        '{p}',")
L += ["    ];", "", "    /** slug => [name, permissions] (owner is the system role holding everything, BR-060) */", "    public const ROLES = ["]
for r, name in R.ROLE_NAMES.items():
    L.append(f"        '{r}' => ['{name}', [" + ", ".join(f"'{p}'" for p in held(r)) + "]],")
L += ["    ];", "}", ""]
open(role_out, "w").write("\n".join(L))
print(f"{len(perms)} permissions, {len(R.ROLE_NAMES)} roles -> {role_out}")
