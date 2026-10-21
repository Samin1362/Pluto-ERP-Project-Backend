# Runbook: the tunnel URL changed

**When:** the live site shows "The Pluto server is offline", or the host machine / Docker / `cloudflared` restarted. The Cloudflare quick tunnel gets a new random `https://<words>.trycloudflare.com` URL every time `cloudflared` starts, and the Vercel frontend must be pointed at it. Backlog P0.4-BE-06; takes about two minutes.

## 1. On the host machine (backend repo)
```bash
./scripts/live.sh ps            # all services Up / healthy? If not: ./scripts/live.sh up
./scripts/tunnel-url.sh         # copy the URL it prints
curl "$(./scripts/tunnel-url.sh)/api/ready"     # must print {"status":"ready",...}
```
A fresh URL needs about 30 seconds before it answers (empty reply until then); the old URL answers `530`.
Rehearsed 20 Oct 2026: `toll-checking-forever-intranet` → `curve-enemies-true-remedies`, ready after ~30 s.

## 2. Point Vercel at the new URL (frontend repo, anyone with Vercel access)
```bash
./scripts/point-backend.sh https://<new-words>.trycloudflare.com
```
This updates `BACKEND_ORIGIN` for Production and Preview and redeploys production (backlog P0.6-FE-03).

## 3. Check
Open `https://pluto-erp-frontend.vercel.app`, sign in, open Customers. Sign-in works → done.

## 4. Tell the team
Post the new URL in the team chat ("Live backend moved to …").

## Notes
- `./scripts/deploy.sh` does **not** restart `cloudflared`, so normal releases keep the URL. `./scripts/live.sh down` does.
- Later (P17, decision C2): a domain with a named Cloudflare tunnel gives a fixed URL and retires this runbook. A small host script that detects a change and calls the Vercel API is an optional stop-gap.
