#!/usr/bin/env bash
# Adds one user per role to company 1 for E2E and manual checks (backlog P0.2-BE-05):
#   viewer@, store@, accountant@, sales@, finance@ pluto.local
# Password from PLUTO_USER_PASSWORD (prompted when missing). Safe to run again.
source "$(dirname "$0")/lib.sh"
[ "$(company_count)" != "0" ] || die "No company yet. Run ./scripts/setup.sh first."
ensure_test_users
