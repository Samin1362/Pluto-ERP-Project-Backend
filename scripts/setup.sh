#!/usr/bin/env bash
# One-command local setup (backlog P0.2-BE-05). Safe to run again: every step skips work that is already done.
#   ./scripts/setup.sh
# Optional: PLUTO_OWNER_PASSWORD=... to skip the demo owner password prompt.
source "$(dirname "$0")/lib.sh"

command -v docker >/dev/null || die "Docker is not installed (https://docs.docker.com/get-docker/)."
docker info >/dev/null 2>&1 || die "Docker is not running. Start Docker Desktop and try again."

# 1. .env with random local passwords and your uid/gid
if [ ! -f .env ]; then
  say "Creating .env from .env.example"
  cp .env.example .env
fi
for key in DB_PASSWORD MYSQL_ROOT_PASSWORD; do
  if [ "$(env_get "$key")" = "change-me-locally" ] || [ -z "$(env_get "$key")" ]; then
    env_set "$key" "$(random_secret 24)"
  fi
done
[ "$(env_get LOCAL_UID)" = "$(id -u)" ] || env_set LOCAL_UID "$(id -u)"
[ "$(env_get LOCAL_GID)" = "$(id -g)" ] || env_set LOCAL_GID "$(id -g)"

# 2. Images and the database services
say "Building the app image and starting MySQL, Redis and Mailpit"
dc build
dc up -d --wait mysql redis mailpit

# 3. PHP dependencies (vendor/ lives in your checkout, mounted into the containers)
say "Installing PHP dependencies"
dc run --rm --no-deps app composer install --no-interaction --prefer-dist --no-progress

# 4. App key
if [ -z "$(env_get APP_KEY)" ]; then
  say "Generating APP_KEY"
  env_set APP_KEY "$(dc run --rm --no-deps app php artisan key:generate --show --no-ansi | tr -d '[:space:]')"
fi

say "Starting the full stack"
dc up -d --wait

# 5. Database schema and reference data
say "Migrating and seeding"
artisan migrate --force
artisan db:seed --force

# 6. Access-token signing keys
artisan pluto:jwt-keys

# 7. Demo company
ensure_demo_company

# 8. Where to go next
port="$(env_get HTTP_PORT)"; mail="$(env_get MAILPIT_PORT)"
cat <<INFO

  API          http://localhost:${port:-8080}/api/v1   (health: /api/ready)
  Mailpit      http://localhost:${mail:-8025}
  MySQL        localhost:$(env_get DB_FORWARD_PORT) · user $(env_get DB_USERNAME) · password in .env
  Sign in as   $DEMO_OWNER_EMAIL with the demo owner password you chose

  Test users:  ./scripts/seed-test-users.sh   ·   Tests: make test   ·   Stop: make down
INFO
