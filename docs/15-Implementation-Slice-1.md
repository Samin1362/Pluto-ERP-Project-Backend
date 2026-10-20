# 15 · Implementation slice 1: foundations, sign-in, roles, Customers

> Status: implemented 19 Oct 2026; completed the same day with 2-step verification, browser tests for every Customers action, and a CI workflow. Scope chosen with the request "project setup, authentication, role-based authorization, and one master-data workflow end to end". The purchase-bill slice of [10 §P1](10-Development-Roadmap.md) is **not** part of this slice; it remains the next step.

## 1. Scope

| Area | Delivered | Contract / design reference |
|---|---|---|
| Project setup | Laravel 12 API in `backend/`, React 19 + TypeScript SPA in `frontend/`, served by Laravel from `backend/public/app` | 03, 08 |
| Database | All 83 approved migrations (unchanged), reference seeders (currencies, permissions) | 04, 14 |
| Authentication | `POST /auth/login`, `/auth/two-factor`, `/auth/refresh`, `/auth/logout`, `GET /me` | 05 §4, 07 §3 |
| 2-step verification | TOTP set-up with QR (`POST /me/two-factor`, `/confirm`), 10 single-use recovery codes, turn off with password (`DELETE /me/two-factor`), sign-in second step (S1), company-wide requirement enforced (`require_two_factor`) | 07 §5, 02 S1 and K14 |
| Authorization | Company membership (`X-Company-Id`), six role templates, `perm:` middleware, company global scope | 07 §4–5 |
| Company setup | `ledgerflow:company:create` provisions settings, payment terms, head-office branch, roles, chart of accounts with mappings, fiscal year and periods, number series | 06, 14 |
| Customers | B1 list (search, status, group, sort, pagination), B2 add, B3 edit with optimistic locking, deactivate/reactivate/delete, B4 details (Overview tab) | 02 B1–B4, 05 parties |
| Lookups | `GET /payment-terms`, `GET /party-groups` | 05 |
| Settings › Security | K14 with the 2-step row only; the Settings section rail from the design, other sections open "not in this release" | 02 K14 |
| Shared states | 403 page (role named), 404 page, "not in this release" page for unbuilt modules | 02 Z-states |

Out of scope: suppliers UI (the `parties` API serves both types, but only the Customers pages exist); the other K14 rows (change password, the Owner's "require 2-step for everyone" switch, idle sign-out, signed-in sessions), which need the Settings module; password reset; invitations; every transaction module.

## 2. Deviations from the approved documents

| # | Document says | Implemented | Why |
|---|---|---|---|
| D1 | DEC-02: `php-open-source-saver/jwt-auth` | `lcobucci/jwt` 5 (the library jwt-auth wraps) with a small `JwtService`; ES256, same claims and lifetimes as 07 §3 | Direct control over `sid`/`pwv` claims and family revocation; one dependency fewer. Behaviour matches 07. |
| D2 | Vite, Vitest, @playwright/test | esbuild build, `node:test` unit tests, the Playwright library under `node:test` | The build environment had no npm registry access; these tools were the ones available. Swap back without code changes to `src/`. |
| D3 | React 18 | React 19.2 | The existing design-system bundle (`ds/ledgerflow`) ships with and expects React 19. |
| D4 | Opening balance entered on B2 | Must be `0.00` (422 otherwise); field read-only | Posting an opening balance needs the journal module (06 §OB). Enabled with the accounting slice. |
| D5 | B1 summary tiles (receivable, overdue, credit-limit) | Only the count tile, from `meta.total` and the status filter | The contract has no summary endpoint. Proposed addition (not in the contract yet): a read-only parties summary endpoint returning receivable, overdue and over-limit totals per type, to review in 05. |
| D6 | B4 tabs: Overview, Invoices, Receipts, Statement, Notes | Overview only; other tabs show "not in this release" | Those tabs need the sales and banking modules. |
| D7 | Fiscal year label from company settings | Computed in the SPA assuming a July start (Bangladesh default) | `GET /me` does not return the FY; proposed addition to `MeResponse`. |
| D8 | Finance role journal approval limit not specified | Assumed `500000.00` in the Finance role template | ⚑ Needs business confirmation before accounting goes live. |
| D9 | Party schema in 05 | Added `port_of_loading` and `default_purchase_ledger_id` to the `Party` response schema | Columns exist in 14; the contract test found the gap. Recorded in `contract_review.py`. |
| D10 | `amr` claim per sign-in (07 §3.1); `user_sessions` has no column for it | Whether a session family was verified with a code is kept in the cache store (`amr-otp:<family>`, lifetime of the refresh token); refresh copies it into the new token | Avoids changing the approved schema. Fails safe: if the entry is lost the sign-in counts as password-only and, where a company requires 2-step, the user signs in again. A column on `user_sessions` would be the durable alternative. |
| D11 | `POST /me/two-factor` declared 200/401; confirm without 409/429 | Contract now declares 409 `invalid_transition` (set-up while already on, confirm without set-up) and 429 on confirm | Found while implementing; additive. Recorded in `contract_review.py`, 05 and openapi.yaml regenerated. |
| D12 | Rate limits fixed in 05 §1 (auth 20/min per IP, 120/min per user) | Same defaults, now configurable (`AUTH_RATE_LIMIT_PER_IP`, `API_RATE_LIMIT_PER_USER`); the E2E run raises them because the tests browse much faster than a person | Limits themselves are tested in PHPUnit. See open item on shared office IPs. |
| D13 | Entering a valid code while setting up counts as the second factor | After confirm, the current sign-in is marked verified; the next token refresh carries `otp` | Lets a user who is blocked by the company requirement continue without signing in again. |

## 3. Security notes
- No secrets in the repository: JWT keys are generated per environment into `storage/keys/` (ignored); user passwords come from a hidden prompt or `LEDGERFLOW_OWNER_PASSWORD` / `LEDGERFLOW_USER_PASSWORD`.
- The SPA holds the access token in memory only. The refresh token is an HttpOnly, Secure, SameSite=Strict cookie scoped to `/api/v1/auth`, rotated on every use; reuse of a rotated token revokes the session family; logout revokes the family.
- Cookie endpoints require `X-Requested-With` and an allowed `Origin` (CSRF).
- Login throttling per (e-mail, IP) and per account (R-12); unknown e-mails cost the same Argon2id verification as known ones.
- Every company-owned model refuses to query without a resolved company (`BelongsToCompany` throws), so a missing scope fails loudly rather than leaking.
- 2-step: RFC 6238 (SHA-1, 30 s, 6 digits, ±1 step); a code is accepted once (a replayed or older step is refused); a challenge lasts 5 minutes and dies after 5 wrong codes; wrong codes are also limited per user (10 per 15 minutes, then 429). No session or cookie exists until the second step succeeds. The secret is stored with Laravel's `encrypted` cast; recovery codes are stored only as HMAC-SHA256 digests inside an encrypted column. Failures, enabling, disabling and recovery-code use are audited.
- The per-user rate limit runs after JWT authentication (the middleware implements `AuthenticatesRequests`, which Laravel orders before throttling).

## 4. How to run

```bash
# Backend (PHP 8.3, Composer 2, MySQL 8.0)
cd backend
composer install
cp .env.example .env && php artisan key:generate       # set DB_* in .env
php artisan migrate && php artisan db:seed
php artisan ledgerflow:jwt-keys
php artisan ledgerflow:company:create "Meghna Traders Ltd." --owner-email=you@example.com --owner-name="Your Name"   # prompts for a password
php artisan ledgerflow:user:add 1 viewer@example.com --role=viewer                                                  # optional
# Frontend
cd ../frontend && npm install && npm run build:embed
cd ../backend && php artisan serve       # open http://127.0.0.1:8000
```

Tests: `cd backend && php vendor/bin/phpunit` (needs an empty MySQL database `ledgerflow_test`); `cd frontend && npm run typecheck && npm test`; end-to-end: `E2E_PASSWORD=… scripts/run-e2e.sh` (drops and recreates the MySQL database `ledgerflow_e2e`, starts and stops the server). CI: `.github/workflows/ci.yml` runs the same four groups on GitHub Actions.

## 5. Test results (actual runs, 19 Oct 2026)

Environment: PHP 8.3.6, Laravel 12.70, MySQL 8.0.46 (strict `sql_mode`, utf8mb4_0900_ai_ci), Node 22.22, Chromium via Playwright 1.56.

| Suite | Command | Result |
|---|---|---|
| Migrations | `migrate` → `migrate:rollback` → `migrate` → `db:seed` on an empty MySQL database | 85 up (83 LedgerFlow + 2 framework), 85 down, 85 up; 385 foreign keys and 148 CHECK constraints, matching 14 |
| Backend | `php vendor/bin/phpunit` on MySQL | **99 tests, 6950 assertions, 0 failures** |
| ‣ Authentication | login, generic 401, per-source throttling, account lockout, disabled user, validation and malformed JSON, refresh rotation and reuse detection, CSRF header, logout (incl. expired token), forged/missing tokens and password change, request id, per-IP limit on /auth, per-user limit on authenticated calls | 14 passed |
| ‣ 2-step verification | set-up (QR, encrypted secret, wrong/malformed code, 10 recovery codes stored as digests, already-on refused), no token or session before the code, code then refresh keeps `otp`, single-use challenge, replay refused, 5 wrong codes kill a challenge, per-user 429, 5-minute expiry, recovery code once, field validation, turn off with password, company requirement (blocked until enrolled, enrolment unblocks after refresh, cannot turn off, other company unaffected) | 9 passed |
| ‣ TOTP unit | RFC 6238 test vectors, base32, ±1 step window, otpauth URI | 9 passed |
| ‣ Company access | `X-Company-Id` and active membership required, deactivated membership loses access at once, `/me` permissions per company, role edits apply on the next request, 6 roles × 7 customer calls | 46 passed (5 tests, one with 42 data rows) |
| ‣ Customer workflow | create (code, ledger, Location, ETag, audit), validation codes, cross-company references rejected, list filters/search/sort/pages, balance from posted lines and history blocking delete, If-Match 428/409, deactivate/reactivate/delete, duplicate warning, idempotent replay, form lookups | 10 passed |
| ‣ OpenAPI conformance | real responses of every slice endpoint (incl. the 2-step ones) validated against `docs/openapi.yaml`, plus a negative check | 4 passed |
| ‣ Schema contract | server version, tables, columns, keys, FKs, CHECKs and rejected rows against `schema-contract.json` | 7 passed |
| Frontend types | `npm run typecheck` (strict) | no errors |
| Frontend unit | `npm test` (money formatting, form schema, API client refresh and 2-step handling) | 13 passed |
| End to end | `scripts/run-e2e.sh` against MySQL, run twice | **9 of 9 passed both times** (about 80 s); audit entries, deleted/reactivated rows and 2-step state checked in the database afterwards |
| Generated files | every generator in `docs/source` run again | no file changed (the CI docs job relies on this) |
| CI workflow | `.github/workflows/ci.yml` | YAML parses; **not run**: GitHub Actions is not reachable from this environment |

The end-to-end tests cover:
- **Sign-in:** redirect to sign-in, wrong password message, sign-in; session kept after a page reload; sign-out (the next sign-in starts at Customers, not at the last page).
- **Customers:** B2 validation messages and mobile format; create with CUS code, ledger 1201-NNN and Net 15 default; duplicate-name warning; B1 count tile and search; B3 edit; a concurrent edit giving 409 with the user's input kept, then reload and save; deactivate and the Inactive filter; reactivate; delete after typing the name (button disabled until it matches); "Save and add another"; the discard-changes dialog (keep editing, discard, no prompt when nothing changed); the 404 page for a deleted record and an unknown address; list, details and form at phone width (390 px) with no sideways scrolling.
- **Roles:** Viewer without the New button and denied on B2; Store keeper denied with the role named; a not-yet-built module page.
- **2-step:** set-up from the profile menu (QR, key, wrong code refused, 10 recovery codes); sign-in with a code (malformed and wrong codes refused); recovery code works once; turn off needs the password; cancelling set-up leaves it off; a company requirement sends a password-only sign-in to Security, enrolling unblocks the same sign-in, and turning off is refused while required. The requirement itself is switched in the database by the test, since its switch belongs to the Settings module.

**Not covered by any automated test:** keyboard-only use and screen readers, browsers other than Chromium, the "Download as text" button for recovery codes, the CI workflow itself.

### Defects found by the tests and fixed
| Found by | Defect | Fix |
|---|---|---|
| Schema contract test on MySQL | Generated columns `idempotency_keys.scope_company_id` and `number_sequences.fy_scope` were created NULL-able (Laravel omits NOT NULL on `storedAs` unless told) | `build_migrations.py` emits `->nullable(false)`; both migrations regenerated. The generator also no longer deletes the framework migrations |
| OpenAPI conformance test | Party response had two fields the contract lacked | D9 above |
| E2E | Default payment term not preselected on B2 (the form defaults were the same mutated object, so React never saw a change) | Fresh defaults object; constant frozen |
| E2E | Data arriving after the user started typing (refetch, payment terms) could reset typed values and clear validation messages | Reset keeps dirty values, errors and submit count |
| Feature tests | Logout left rotated refresh tokens usable | Logout and reuse revoke the whole session family |
| Per-user limit test | The per-user rate limit ran before authentication, so it counted per IP: everyone behind one office IP shared 120 calls a minute | JWT middleware implements `AuthenticatesRequests`, ordering it before throttling |
| E2E | Refusing to turn 2-step off (company requires it) showed "Sign in again with your authenticator code" | Only company-scoped 403s mark the sign-in as lacking 2-step |
| E2E | After an explicit sign-out, the next sign-in (possibly another person) returned to the previous user's last page | Return-to-page only after an expired session |
| E2E | On the code step, the field error repeated the instruction text instead of saying what was wrong | "The code has 6 digits" / "Enter the code from your authenticator app" |
| 2-step tests | Authenticator apps showed the issuer "Laravel" (taken from APP_NAME) | Own setting `TWO_FACTOR_ISSUER`, default LedgerFlow |

## 6. Open items
- D5 and D7 contract additions to approve.
- D8 Finance approval limit to confirm.
- Rate limits for shared office IPs: 05 §1 allows 20 calls a minute per IP to `/auth/*`, and every page load and token refresh is one of them, so an office of many users behind one public IP can see 429s. Suggest counting only failed sign-ins per IP, or raising the limit; needs a decision before go-live.
- D10: decide whether to add an `amr` column to `user_sessions` (schema change) instead of the cache entry.
- Run the CI workflow on GitHub once the repository is pushed; it has not run yet.
- Docker compose (10 §P0) is not set up.

## 7. Files created or modified in this slice

**Created, backend** (`backend/`)
- Laravel 12 skeleton files (`artisan`, `bootstrap/providers.php`, `public/index.php`, standard `config/*.php`, `routes/console.php`), framework migrations `0001_01_01_000001/2` (cache, jobs)
- `composer.json`, `phpunit.xml`, `.env.example`, `.gitignore` (adds `/storage/keys`); `config/ledgerflow.php`, `config/hashing.php`, `config/auth.php`; `bootstrap/app.php`; `routes/api.php`, `routes/web.php`
- `app/Support/`: `CurrentCompany`, `Decimal`, `Problem/{ApiProblem, ProblemRenderer, ErrorCatalogue (generated)}`
- `app/Models/`: `Concerns/{BelongsToCompany, HasLockVersion, TracksActor}`; `User, Company, Currency, Permission, Role, CompanyUser, Branch, UserSession, AuditLog, PaymentTerm, PartyGroup, Party, PartyBankAccount, AccountGroup, Ledger, AccountMapping, NumberSequence, IdempotencyKey, CompanySetting`
- `app/Domain/Access/{JwtService, AuthService, TwoFactorService, Totp, PermissionResolver, CompanyProvisioner, RoleTemplates (generated)}`, `app/Domain/Shared/{AuditLogger, DocumentNumberService}`, `app/Domain/Parties/{PartyService, PartyBalances}`, `app/Queries/PartyQuery`
- `app/Http/Middleware/{RequestId, ForceJsonApi, AuthenticateJwt, ResolveCompany, RequirePermission, Idempotency}`; `app/Http/Requests/{StrictRequest, Auth/LoginRequest, Auth/TwoFactorRequest, Auth/TwoFactorConfirmRequest, Auth/PasswordConfirmRequest, Parties/PartyRequest, Parties/PartyListRequest}`; `app/Http/Resources/{PartyResource, MeResource}`; `app/Http/Controllers/Api/V1/{Auth/AuthController, MeController, Me/TwoFactorController, Parties/PartyController, Parties/LookupController}`; `app/Providers/AppServiceProvider`
- `app/Console/Commands/{GenerateJwtKeys, CreateCompany, AddUser, Concerns/ReadsPassword}`; `database/seeders/{ReferenceDataSeeder, DatabaseSeeder}`
- `tests/TestCase.php`; `tests/Feature/{Auth/AuthenticationTest, Auth/TwoFactorTest, Access/CompanyAccessTest, Parties/CustomerWorkflowTest, Contract/OpenApiConformanceTest}.php`; `tests/Unit/TotpTest.php`
- Dependency `bacon/bacon-qr-code` 3.1 (QR SVG for the authenticator set-up)
- `public/app/` (built SPA, output of `npm run build:embed`)

**Modified, backend:** `database/migrations/2026_10_18_001500_create_idempotency_keys_table.php`, `…002900_create_number_sequences_table.php` (regenerated, NOT NULL generated columns); `tests/Feature/Database/SchemaContractTest.php` (insertOrIgnore for BDT). The skeleton's users migration and factories were removed (the approved schema replaces them).

**Created, frontend** (`frontend/`)
- `package.json`, `tsconfig.json`, `build.mjs`, `index.html`, `.gitignore`, `scripts/offline-node-modules.sh`; `public/settings.css` (the design's settings layout rules, copied unchanged)
- `public/` design-system assets copied unchanged from the design (`lf-theme.css`, `ds/ledgerflow/components/bundle.{js,css}`, fonts, `tokens.json`)
- `src/`: `main.tsx`, `ds.ts`; `api/{client, contract.gen (generated), parties, me}.ts`; `auth/session.tsx`; `app/{Shell, guards, router, toast}.tsx`; `pages/SignIn.tsx`; `pages/customers/{CustomerList, CustomerForm, CustomerDetails, shared}.tsx`; `pages/settings/{SettingsLayout, Security}.tsx`; `pages/states/{Forbidden, NotFound, NotInRelease}.tsx`; `lib/{format, customerSchema}.ts`
- `tests/run-unit.mjs`, `tests/unit/{format, customerSchema, client}.test.ts`, `tests/e2e/{helpers.mjs, customers.e2e.test.mjs, two-factor.e2e.test.mjs}`

**Created, other:** `.github/workflows/ci.yml`; `scripts/run-e2e.sh`; `docs/15-Implementation-Slice-1.md`; `docs/source/build_ts_types.py`; `docs/source/build_php_contract.py` (renamed from `build_php_errors.py`)

**Modified, docs:** `README.md` (status, stack, setup, test commands, index); `docs/source/schemas.py` (Party: D9), `api.py` (2-step responses: D11) and `contract_review.py`; regenerated `docs/05-API-Specification.md` and `docs/openapi.yaml`; `docs/source/build_migrations.py` (NOT NULL generated columns; keeps framework migrations)
