#!/bin/bash
# End-to-end run against a disposable stack: fresh MySQL database `ledgerflow_e2e`, migrations, reference data,
# one company with an Owner, a Viewer and a Store keeper, throw-away JWT keys and APP_KEY, the built SPA,
# `php artisan serve` on 127.0.0.1:8000, then the Playwright tests in frontend/tests/e2e. The server is stopped on exit.
# The rate limits are raised for this run only: the tests browse far faster than a person (limits are tested in PHPUnit).
# Environment:
#   E2E_PASSWORD   password given to the test users (required; choose any, never commit one)
#   DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD   a MySQL 8.0 server where the user may DROP/CREATE `ledgerflow_e2e`
set -euo pipefail
: "${E2E_PASSWORD:?Set E2E_PASSWORD}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap '[ -n "${SERVER:-}" ] && kill "$SERVER" 2>/dev/null; pkill -P "${SERVER:-0}" 2>/dev/null || true; rm -rf "$WORK"' EXIT

export DB_CONNECTION=mysql DB_HOST="${DB_HOST:-127.0.0.1}" DB_PORT="${DB_PORT:-3306}" DB_DATABASE=ledgerflow_e2e \
       DB_USERNAME="${DB_USERNAME:-root}" DB_PASSWORD="${DB_PASSWORD:-}" \
       APP_ENV=local APP_DEBUG=false APP_URL=http://127.0.0.1:8000 APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" \
       LEDGERFLOW_ALLOWED_ORIGINS=http://127.0.0.1:8000 REFRESH_COOKIE_SECURE=true AUTH_RATE_LIMIT_PER_IP=600 API_RATE_LIMIT_PER_USER=6000 CACHE_STORE=database SESSION_DRIVER=array \
       JWT_PRIVATE_KEY_PATH="$WORK/jwt-private.pem" JWT_PUBLIC_KEY_PATH="$WORK/jwt-public.pem" LOG_CHANNEL=single

cd "$ROOT/backend"
php -r '$p = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        $p->exec("DROP DATABASE IF EXISTS ledgerflow_e2e");
        $p->exec("CREATE DATABASE ledgerflow_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");'
php artisan migrate --force --no-interaction -q
php artisan db:seed --force --no-interaction -q
php artisan ledgerflow:jwt-keys --force
LEDGERFLOW_OWNER_PASSWORD="$E2E_PASSWORD" php artisan ledgerflow:company:create "Meghna Traders Ltd." --owner-email=owner@e2e.ledgerflow.test --owner-name="Rafiq Hasan"
for role in viewer store; do
  LEDGERFLOW_USER_PASSWORD="$E2E_PASSWORD" php artisan ledgerflow:user:add 1 "$role@e2e.ledgerflow.test" --role="$role" --name="E2E $role"
done
(cd "$ROOT/frontend" && node build.mjs --embed > /dev/null)

PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 > "$WORK/serve.log" 2>&1 &
SERVER=$!
for _ in $(seq 50); do curl -fs -o /dev/null http://127.0.0.1:8000/sign-in && break; sleep 0.2; done

cd "$ROOT/frontend"
E2E_BASE_URL=http://127.0.0.1:8000 npm run -s e2e
