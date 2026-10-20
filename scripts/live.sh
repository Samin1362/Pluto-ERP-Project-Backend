#!/usr/bin/env bash
# Live stack on the host machine (backlog P0.4-BE-02): prod image, .env.live, Cloudflare quick tunnel.
#   ./scripts/live.sh init               first time: .env.live with random secrets, build, migrate, keys
#   ./scripts/live.sh up | down | ps     start (and wait until healthy) / stop / status
#   ./scripts/live.sh logs [service]     follow logs
#   ./scripts/live.sh artisan <args>     php artisan in the live app (e.g. ledgerflow:company:create …)
# The live stack is the Compose project "pluto-erp-live": its containers, database volume and port (8090)
# are separate from the developer stack on the same machine.
LIVE=1
source "$(dirname "$0")/lib.sh"

cmd="${1:-}"; shift || true

ensure_env_live() {
  if [ ! -f .env.live ]; then
    say "Creating .env.live from .env.live.example"
    cp .env.live.example .env.live
  fi
  for key in DB_PASSWORD MYSQL_ROOT_PASSWORD; do
    [ "$(env_get "$key")" != "generate" ] && [ -n "$(env_get "$key")" ] || env_set "$key" "$(random_secret 32)"
  done
  [ "$(env_get PROXY_SHARED_SECRET)" != "generate" ] && [ -n "$(env_get PROXY_SHARED_SECRET)" ] || env_set PROXY_SHARED_SECRET "$(random_secret 48)"
  [ -n "$(env_get APP_KEY)" ] || env_set APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
  [ "$(env_get LOCAL_UID)" = "$(id -u)" ] || env_set LOCAL_UID "$(id -u)"
  [ "$(env_get LOCAL_GID)" = "$(id -g)" ] || env_set LOCAL_GID "$(id -g)"
}

case "$cmd" in
  init)
    command -v docker >/dev/null && docker info >/dev/null 2>&1 || die "Docker is not running."
    ensure_env_live
    say "Building the production image and starting the live stack"
    dc build app
    dc up -d --wait
    say "Migrating, seeding reference data, creating signing keys, caching config"
    artisan migrate --force
    artisan db:seed --force
    artisan ledgerflow:jwt-keys
    artisan config:cache
    artisan route:cache
    if [ "$(company_count)" = "0" ]; then
      say "No company yet. Create the real one (asks for the owner's password):"
      echo "    ./scripts/live.sh artisan ledgerflow:company:create \"Company Ltd.\" --owner-email=owner@example.com --owner-name=\"Owner Name\""
    fi
    "$0" url || true
    echo "Put PROXY_SHARED_SECRET from .env.live into Vercel (P0.6-FE-03) and keep .env.live in the password manager."
    ;;
  up)    [ -f .env.live ] || die "No .env.live yet: run ./scripts/live.sh init"; dc up -d --wait "$@"; "$0" url || true ;;
  down)  dc down "$@" ;;
  ps)    dc ps "$@" ;;
  logs)  dc logs -f --tail=100 "$@" ;;
  artisan)
    # Pass the owner/user password variables through when they are set.
    envs=(); for v in LEDGERFLOW_OWNER_PASSWORD LEDGERFLOW_USER_PASSWORD; do [ -n "${!v:-}" ] && envs+=(-e "$v"); done
    if [ -t 0 ]; then dc exec ${envs[@]+"${envs[@]}"} app php artisan "$@"; else dc exec -T ${envs[@]+"${envs[@]}"} app php artisan "$@"; fi ;;
  url)   "$(dirname "$0")/tunnel-url.sh" ;;
  *)     sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
