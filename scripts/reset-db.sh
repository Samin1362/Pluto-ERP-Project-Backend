#!/usr/bin/env bash
# Wipes the local database and starts again (backlog P0.2-BE-05): fresh schema and reference data, demo company,
# test users. JWT keys are kept, so nothing else needs to change. Local only; never run against live.
source "$(dirname "$0")/lib.sh"
[ "$(env_get APP_ENV)" != "production" ] || die "APP_ENV is production; refusing to reset."
echo "This deletes ALL data in the local '$(env_get DB_DATABASE)' database."
read -r -p "Type RESET to continue: " answer
[ "$answer" = "RESET" ] || die "Cancelled."
artisan migrate:fresh --seed --force
ensure_demo_company
ensure_test_users
say "Database reset."
