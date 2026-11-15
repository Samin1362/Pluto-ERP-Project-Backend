#!/usr/bin/env bash
# Switch the live stack to real e-mail through Brevo SMTP (PEP-506), then send a test e-mail.
# Usage (Git Bash, in backend/):  ./scripts/set-brevo.sh
# Needs, from https://app.brevo.com:
#   - SMTP & API -> SMTP: the "Login" shown there (an address like 9a1b2c001@smtp-brevo.com) and a new SMTP key
#   - Senders, domains & dedicated IPs -> Senders: the address Pluto's mail comes from, added and verified
# The key is typed hidden and only written to .env.live (never printed). A backup of .env.live is kept next to it.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env.live ] || { echo "No .env.live here" >&2; exit 1; }

read -rp "Brevo SMTP login (shown on Brevo's SMTP page): " login
[[ "$login" == *@* ]] || { echo "The SMTP login looks like an e-mail address (e.g. 9a1b2c001@smtp-brevo.com)" >&2; exit 1; }
read -rsp "Brevo SMTP key (hidden): " key; echo
key="${key// /}"
[ ${#key} -ge 20 ] || { echo "That is too short for a Brevo SMTP key; generate one on Brevo's SMTP page" >&2; exit 1; }
read -rp "Sender address (verified in Brevo, e.g. mbillah0194@gmail.com): " from
[[ "$from" == *@* ]] || { echo "That is not an e-mail address" >&2; exit 1; }
read -rp "Send the test e-mail to: " to
[[ "$to" == *@* ]] || to="$from"

cp .env.live ".env.live.bak-$(date +%Y%m%d%H%M%S)"
# Set each setting, adding it when the line does not exist yet.
put() { if grep -q "^$1=" .env.live; then sed -i -E "s|^$1=.*|$1=$2|" .env.live; else echo "$1=$2" >> .env.live; fi; }
put MAIL_MAILER smtp
put MAIL_HOST smtp-relay.brevo.com
put MAIL_PORT 587
put MAIL_SCHEME ""
put MAIL_USERNAME "$login"
put MAIL_PASSWORD "$key"
put MAIL_FROM_ADDRESS "$from"
put MAIL_FROM_NAME "Pluto"
unset key
echo "==> .env.live updated (backup kept next to it)"

echo "==> Restarting app, worker and scheduler with the new settings (the tunnel is not touched)"
./scripts/live.sh up --no-deps --force-recreate app worker scheduler >/dev/null
./scripts/live.sh artisan config:cache >/dev/null || true
./scripts/live.sh artisan queue:restart >/dev/null || true

echo "==> Sending a test e-mail to ${to}"
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html pluto-erp-live-app-1 php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
  Illuminate\Support\Facades\Mail::raw("Pluto can now send real e-mail through Brevo.", function ($m) use ($argv) {
    $m->to($argv[1])->subject("Pluto test e-mail");
  });
  echo "sent\n";
} catch (Throwable $e) {
  $why = str_contains($e->getMessage(), "535") ? "Brevo refused the SMTP key (535 Authentication failed). Use a NEW key from SMTP & API > SMTP tab (it starts with xsmtpsib-, not xkeysib-), and check the SMTP tab says your SMTP account is active."
    : (str_contains($e->getMessage(), "550") || str_contains($e->getMessage(), "sender") ? "Brevo refused the sender address: verify it under Senders, domains & dedicated IPs > Senders." : substr($e->getMessage(), 0, 300));
  fwrite(STDERR, "NOT SENT: {$why}\n");
  exit(1);
}' "$to"
echo "==> Done. Check the inbox (and Spam) of ${to}."
