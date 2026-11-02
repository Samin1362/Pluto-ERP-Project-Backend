# Performance tests (TC-NFR-001, -002, -003; backlog P17.1-BE-02)

Never against live data. The load goes to a disposable copy: the E2E database (`pluto_e2e`, reset by
`./scripts/e2e-prepare.sh`) or any database whose name ends in `_perf`.

## 1. Volume: 1 M journal lines
```bash
E2E_PASSWORD=<10+ chars> ./scripts/e2e-prepare.sh                     # fresh pluto_e2e with the E2E company
docker compose --profile e2e exec -T app-e2e php artisan pluto:perf:seed --lines=1000000   # ~5 min
```

## 2. Serve it with the production image
The developer image (debug on, code on the shared drive) is far slower than production and is not a fair
measurement. Run the production image beside it on the same Docker network:
```bash
docker build -f docker/php/Dockerfile --target prod -t pluto-erp-app:prod .
docker inspect pluto-erp-app-e2e-1 --format '{{range .Config.Env}}{{println .}}{{end}}' \
  | grep -vE '^(PATH|PHP|HOME|HOSTNAME|APP_CONFIG_CACHE|APP_ROUTES_CACHE|APP_ENV|APP_DEBUG|LOG_LEVEL|GPG_KEYS|PHPIZE)' | grep = > /tmp/perf.env
printf 'APP_ENV=production\nAPP_DEBUG=false\nLOG_LEVEL=warning\n' >> /tmp/perf.env
docker run -d --name pluto-perf-app --network pluto-erp_default --env-file /tmp/perf.env pluto-erp-app:prod
docker exec pluto-perf-app php artisan pluto:jwt-keys && docker exec pluto-perf-app php artisan config:cache && docker exec pluto-perf-app php artisan route:cache
docker run -d --name pluto-perf-nginx --network pluto-erp_default -v "$PWD/docker/nginx/default.conf:/etc/nginx/app.conf:ro" \
  -v "$PWD/public:/var/www/html/public:ro" nginx:1.28.0-alpine \
  sh -c "sed 's/set \$php_upstream app:9000/set \$php_upstream pluto-perf-app:9000/' /etc/nginx/app.conf > /etc/nginx/conf.d/default.conf && exec nginx -g 'daemon off;'"
```

## 3. Run k6 inside the Docker network
(On Windows/macOS the host → container port adds ~250 ms per request, which is not the server's time.)
```bash
docker run --rm -i --network pluto-erp_default -e BASE=http://pluto-perf-nginx -e EMAIL=owner@e2e.pluto.test \
  -e PASSWORD=<E2E password> grafana/k6:0.54.0 run - < tests/perf/nfr.js
```
Thresholds (k6 fails the run when one is missed): list/detail p95 < 300 ms with error rate < 0.1 % at 50 users;
trial balance and P&L for the year p95 < 2 s; posting a 50-line invoice p95 < 1 s.

## 4. Clean up
`docker rm -f pluto-perf-app pluto-perf-nginx`, then `./scripts/e2e-prepare.sh` before the next E2E run.

## Results
See docs/runbooks/performance-results.md.
