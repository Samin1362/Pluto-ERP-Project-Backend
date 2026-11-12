#!/usr/bin/env bash
# Switch the live stack from MAIL_MAILER=log to real Gmail SMTP, then send a test e-mail.
# Usage (Git Bash, in backend/):  ./scripts/set-gmail.sh
# Needs a Gmail app password: Google Account -> Security -> 2-Step Verification (on) -> App passwords.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env.live ] || { echo "No .env.live here" >&2; exit 1; }

read -rp "Gmail address that will send Pluto's mail: " addr
[[ "$addr" == *@* ]] || { echo "That is not an e-mail address" >&2; exit 1; }
read -rsp "Gmail app password (16 letters, hidden): " pass; echo
pass="${pass// /}"
[[ "$pass" =~ ^[A-Za-z]{16}$ ]] || { echo "An app password is 16 letters (spaces are fine)" >&2; exit 1; }

cp .env.live ".env.live.bak-$(date +%Y%m%d%H%M%S)"
sed -i -E \
  -e "s|^MAIL_MAILER=.*|MAIL_MAILER=smtp|" \
  -e "s|^MAIL_HOST=.*|MAIL_HOST=smtp.gmail.com|" \
  -e "s|^MAIL_PORT=.*|MAIL_PORT=587|" \
  -e "s|^MAIL_USERNAME=.*|MAIL_USERNAME=${addr}|" \
  -e "s|^MAIL_PASSWORD=.*|MAIL_PASSWORD=${pass}|" \
  -e "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=${addr}|" \
  .env.live
echo "==> .env.live updated (backup kept next to it)"

echo "==> Restarting app, worker and scheduler with the new settings (the tunnel is not touched)"
./scripts/live.sh up --no-deps app worker scheduler >/dev/null
./scripts/live.sh artisan config:cache >/dev/null
./scripts/live.sh artisan queue:restart >/dev/null

echo "==> Sending a test e-mail to ${addr}"
docker exec pluto-erp-live-app-1 php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Mail::raw("Pluto can now send real e-mail.", function ($m) use ($argv) {
  $m->to($argv[1])->subject("Pluto test e-mail");
});
echo "sent\n";' "$addr"
echo "==> Done. Check the inbox (and Spam) of ${addr}."
