#!/usr/bin/env bash
# Fresh backend for the frontend's end-to-end tests (backlog P0.5-BE-01). Used by the frontend's scripts/e2e.sh.
#   E2E_PASSWORD=<any 10+ chars> ./scripts/e2e-prepare.sh
# Starts app-e2e + nginx-e2e (http://localhost:8081) on the pluto_e2e database, wipes it, seeds reference
# data and creates the company and users the E2E tests expect:
#   owner@e2e.pluto.test (Owner), viewer@e2e.pluto.test (Viewer), store@e2e.pluto.test (Store keeper)
# Your dev database is never touched. Needs the dev stack set up once (./scripts/setup.sh).
source "$(dirname "$0")/lib.sh"
: "${E2E_PASSWORD:?Set E2E_PASSWORD (10+ characters; the E2E tests sign in with it)}"
[ "$(printf %s "$E2E_PASSWORD" | wc -c)" -ge 10 ] || die "E2E_PASSWORD needs at least 10 characters."
[ -f .env ] || die "No .env yet: run ./scripts/setup.sh first."

e2e() { dc --profile e2e "$@"; }

say "Starting the E2E API on port $(env_get E2E_HTTP_PORT | grep . || echo 8081)"
e2e up -d --wait app-e2e nginx-e2e

# Safety: refuse to wipe anything but the E2E database.
db="$(e2e exec -T app-e2e php artisan tinker --execute='echo config("database.connections.mysql.database");' 2>/dev/null | tail -1)"
[ "$db" = "pluto_e2e" ] || die "app-e2e points at '$db', not pluto_e2e; refusing to reset it."

say "Resetting pluto_e2e"
e2e exec -T app-e2e php artisan migrate:fresh --seed --force
e2e exec -T app-e2e php artisan pluto:jwt-keys

say "Creating the E2E company and users"
e2e exec -T -e PLUTO_OWNER_PASSWORD="$E2E_PASSWORD" app-e2e php artisan pluto:company:create "Meghna Traders Ltd." \
   --owner-email=owner@e2e.pluto.test --owner-name="Rafiq Hasan"
for role in viewer store; do
  e2e exec -T -e PLUTO_USER_PASSWORD="$E2E_PASSWORD" app-e2e php artisan pluto:user:add 1 "$role@e2e.pluto.test" \
     --role="$role" --name="E2E $role"
done

say "E2E API ready: http://localhost:$(env_get E2E_HTTP_PORT | grep . || echo 8081)  (stop it: docker compose --profile e2e stop app-e2e nginx-e2e)"
