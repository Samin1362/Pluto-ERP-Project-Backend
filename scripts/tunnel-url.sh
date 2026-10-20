#!/usr/bin/env bash
# Prints the current public URL of the Cloudflare quick tunnel (backlog P0.4-BE-01), e.g.
#   https://bright-owl-random-words.trycloudflare.com
# Looks at the live stack first, then at a developer stack started with `docker compose --profile live up -d`.
source "$(dirname "$0")/lib.sh"

url_from() { "$@" logs --no-color cloudflared 2>/dev/null | grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1 || true; }

url=""
[ -f .env.live ] && url="$(url_from docker compose -p pluto-erp-live --env-file .env.live -f docker-compose.yml -f docker-compose.live.yml --profile live)"
[ -n "$url" ] || url="$(url_from docker compose --profile live)"
[ -n "$url" ] || die "No tunnel URL found. Is cloudflared running? (./scripts/live.sh up, then ./scripts/live.sh logs cloudflared)"
echo "$url"
