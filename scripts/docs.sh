#!/usr/bin/env bash
# Regenerates the documentation, the API contract and the generated code from docs/source, checks them, and
# refreshes contract/ for the frontend (backlog P0.7-BE-01). Run with `make docs`; needs only Docker.
#   docs 01/02/05/09 + openapi.yaml (build_docs) · 04 (build_db) · migrations + schema-contract.json
#   ErrorCatalogue.php + RoleTemplates.php · contract/contract.gen.ts · contract/openapi.yaml + VERSION
# Not included: build_schema_doc.py (docs 14), which records the PHP harness run and the PHP version; run it
# deliberately inside the app container when the schema changes (see docs 14).
source "$(dirname "$0")/lib.sh"

say "Building the docs toolbox image"
docker build -q -t pluto-erp-docs:py3.12 docker/docs >/dev/null

run() { docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$ROOT":/work -w /work pluto-erp-docs:py3.12 "$@"; }

say "Generating"
run sh -c 'set -e
  python docs/source/build_db.py
  python docs/source/build_docs.py
  python docs/source/build_migrations.py
  python docs/source/build_php_contract.py
  python docs/source/build_ts_types.py' | grep -E '^(tables=|endpoints=|migrations=|[0-9]+ (codes|permissions|interfaces))' || true

say "Checking"
run sh -c 'set -e
  python docs/source/validate_api.py | tail -1
  python docs/source/validate_schema.py | tail -1
  python docs/source/check_consistency.py | tail -1'

say "Refreshing contract/ for the frontend"
cp docs/openapi.yaml contract/openapi.yaml
version="$(awk '/^  version:/ { print $2; exit }' docs/openapi.yaml)"
# Content hash instead of a commit id, so regenerating an unchanged contract leaves git clean.
hash="$(cat contract/openapi.yaml contract/contract.gen.ts | shasum -a 256 | cut -c1-12)"
printf '%s+%s\n' "$version" "$hash" > contract/VERSION
say "Contract $(cat contract/VERSION). If it changed, tell the frontend to run scripts/sync-contract.sh."
rm -rf docs/source/__pycache__
