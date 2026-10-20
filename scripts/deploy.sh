#!/usr/bin/env bash
# Releases the latest main to the live stack on the host (backlog P0.4-BE-03). Run on the host machine.
# cloudflared, mysql and redis are not restarted, so the tunnel URL stays the same.
#   ./scripts/deploy.sh
#   SKIP_GIT=1 ./scripts/deploy.sh      deploy the checked-out code as it is (rehearsals)
LIVE=1
source "$(dirname "$0")/lib.sh"
[ -f .env.live ] || die "No .env.live: run ./scripts/live.sh init first."

fail() { echo "Deploy failed — previous containers may still be running; check ./scripts/live.sh logs" >&2; exit 1; }
trap fail ERR

if [ "${SKIP_GIT:-0}" != "1" ]; then
  [ -z "$(git status --porcelain)" ] || die "Uncommitted changes in this checkout; deploy needs a clean main."
  say "Updating to the latest main"
  git fetch origin
  git checkout main
  git pull --ff-only origin main
fi
sha="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
before="$("$(dirname "$0")/tunnel-url.sh" 2>/dev/null || true)"

say "Pre-deploy backup"
"$(dirname "$0")/backup.sh"

say "Building the production image ($sha)"
dc build app

say "Replacing app, worker, scheduler and nginx"
dc up -d --no-deps --wait app worker scheduler nginx

say "Migrating and caching"
artisan migrate --force
artisan config:cache
artisan route:cache
artisan queue:restart

say "Smoke test"
curl -fs "http://localhost:$(env_get HTTP_PORT)/api/ready" >/dev/null
after="$("$(dirname "$0")/tunnel-url.sh" 2>/dev/null || true)"
dc exec -T app sh -c 'mkdir -p storage/app && echo "$1 $2" >> storage/app/deploy.log' sh "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$sha"

trap - ERR
say "Deployed $sha"
echo "  Tunnel URL: ${after:-not found}"
[ -z "$before" ] || [ "$before" = "$after" ] || echo "  WARNING: the tunnel URL changed — follow docs/runbooks/tunnel-url-changed.md"
