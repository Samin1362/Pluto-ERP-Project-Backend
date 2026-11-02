# Runbook: switch to the named tunnel (fixed address)

**When:** once the owner has a domain on Cloudflare (owner decision 1 Nov 2026, PEP-407: hosting decided later, so
the stack supports both). After this, the API keeps one address (`https://api.<domain>`) through restarts, and
[tunnel-url-changed.md](tunnel-url-changed.md) is no longer needed. Backlog P17.1-BE-01; about 15 minutes.

The same steps apply on a VPS: copy the repo and `.env.live` there, run `./scripts/live.sh init`, then follow this page.

## 1. Cloudflare (owner, in the browser)
1. Buy or move the domain into Cloudflare (Websites → Add a site); wait until it is **Active**.
2. Zero Trust → Networks → Tunnels → **Create a tunnel** → Cloudflared → name it `pluto-live`.
3. Copy the **token** shown in the install command (the long string after `--token`). Do not run the command.
4. Public hostname: subdomain `api`, domain `<domain>`, service **HTTP** `nginx:80`. Save.

## 2. Host machine (backend repo)
```bash
# in .env.live (keep the file in the password manager):
#   TUNNEL_TOKEN=<token from step 1.3>
#   PUBLIC_API_HOST=api.<domain>
./scripts/live.sh down cloudflared        # stops the quick tunnel
./scripts/live.sh up                      # starts cloudflared-named (profile named-tunnel)
./scripts/tunnel-url.sh                   # prints https://api.<domain>
curl "$(./scripts/tunnel-url.sh)/api/ready"   # {"status":"ready",...}
```
`./scripts/live.sh` chooses the tunnel by itself: a non-empty `TUNNEL_TOKEN` runs the named tunnel, an empty one
the quick tunnel. To go back, empty `TUNNEL_TOKEN` and run `./scripts/live.sh up` again.

## 3. Point Vercel at it once (frontend repo)
```bash
./scripts/point-backend.sh https://api.<domain>
```

## 4. Check
Sign in on the Vercel site, open Customers. Then restart the host's Docker and check again: the address must not change.

## Notes
- The tunnel connects outwards; nothing listens on the internet. nginx stays on `127.0.0.1:8090` for local checks.
- `APP_URL`, `APP_FRONTEND_URL` and `PLUTO_ALLOWED_ORIGINS` stay on the Vercel address: browsers talk to Vercel,
  which proxies `/api` to `BACKEND_ORIGIN` with `X-Pluto-Proxy-Secret`.
- Rotate the token: Zero Trust → the tunnel → Refresh token, then steps 2 and 4.
