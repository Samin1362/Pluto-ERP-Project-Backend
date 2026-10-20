# Shared helpers for scripts/*.sh (backlog P0.2-BE-05). Source it; do not run it.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DEMO_COMPANY="Meghna Traders Ltd."
DEMO_OWNER_EMAIL="owner@ledgerflow.local"
TEST_ROLES=(viewer store accountant sales finance)

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# Read one value from .env (empty if missing).
env_get() { [ -f .env ] && awk -F= -v k="$1" '$1==k { sub(/^[^=]*=/, ""); print; exit }' .env || true; }

# Set KEY=VALUE in .env, replacing an existing line or appending one.
env_set() {
  local tmp; tmp="$(mktemp)"
  awk -F= -v k="$1" -v v="$2" 'BEGIN { done = 0 } $1==k { print k "=" v; done = 1; next } { print } END { if (!done) print k "=" v }' .env > "$tmp"
  cat "$tmp" > .env && rm -f "$tmp"
}

random_secret() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "${1:-24}"; }

dc() { docker compose "$@"; }
artisan() { dc exec -T app php artisan "$@"; }

# Run SQL as MySQL root inside the mysql container; prints rows without headers.
mysql_root() { dc exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B "$@"' mysql "$@"; }

# Ask for a password unless the variable is already set; exports it.
need_password() {
  local var="$1" label="$2"
  if [ -z "${!var:-}" ]; then
    [ -t 0 ] || die "Set $var (10+ characters) for $label."
    read -r -s -p "Password for $label (10+ characters, set $var to skip this prompt): " "$var"; echo
  fi
  [ "$(printf %s "${!var}" | wc -c)" -ge 10 ] || die "$var must have at least 10 characters."
  export "${var?}"
}

company_count() { mysql_root -e "SELECT COUNT(*) FROM \`$(env_get DB_DATABASE)\`.companies" | tr -d '[:space:]'; }

ensure_demo_company() {
  if [ "$(company_count)" != "0" ]; then
    say "A company already exists; demo company not created."
    return
  fi
  need_password LEDGERFLOW_OWNER_PASSWORD "the demo owner ($DEMO_OWNER_EMAIL)"
  say "Creating demo company \"$DEMO_COMPANY\""
  dc exec -T -e LEDGERFLOW_OWNER_PASSWORD app php artisan ledgerflow:company:create "$DEMO_COMPANY" \
     --owner-email="$DEMO_OWNER_EMAIL" --owner-name="Demo Owner"
}

ensure_test_users() {
  need_password LEDGERFLOW_USER_PASSWORD "the test users"
  for role in "${TEST_ROLES[@]}"; do
    dc exec -T -e LEDGERFLOW_USER_PASSWORD app php artisan ledgerflow:user:add 1 "$role@ledgerflow.local" --role="$role" --name="Test $role"
  done
}
