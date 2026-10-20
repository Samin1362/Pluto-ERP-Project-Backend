# CLAUDE.md · Pluto-ERP-Project backend

Laravel 12 / PHP 8.3 API for **LedgerFlow ERP** (product name in code and docs; project name Pluto-ERP-Project): a multi-company ERP for Bangladeshi trading firms. Business forms (invoices, bills, receipts, stock moves, LC charges) generate double-entry journals through posting services; posted records are immutable and corrected by reversal. Money is BDT with lakh/crore formatting; the financial year runs July–June. MySQL 8.0 strict, row tenancy by `company_id` + `X-Company-Id`, JWT ES256 access tokens + `lf_rt` refresh cookie, TOTP 2-step, REST `/api/v1`.

## Where tasks come from
Each piece of work is one **backlog task key** (e.g. `P4.3-BE-02`) with a Jira issue in project **PEP** whose description holds the full task text (the master copy is `backlog.md`, kept by the tech lead outside the repos). Read that task's **Read first**, **Do**, **Contract** and **Done when** sections; do only that task.

## Rules
1. Work on **one task key at a time**. Put the Jira key and task key in the branch name and every commit message (CONTRIBUTING.md §2).
2. Before coding, read the task's **Read first** sections and the backend recipe below. Do not re-read the whole docs folder.
3. **Never invent API fields.** Field names come from `src/api/contract.gen.ts` (frontend) or `docs/openapi.yaml` (backend). If something is missing, stop and add a note to the task: "Contract gap: …".
4. **Never compute money, tax, stock or balances in the frontend.** The backend returns them (see 08 §16).
5. Copy the existing pattern files named in the task; do not introduce new libraries unless the task says so.
6. When unsure between two behaviours, the order of authority is: `docs/01-SRS.md` business rules (BR-xxx) → `docs/06` posting rules → `docs/05` API → `docs/02` UI.
7. Finish with the task's **Done when** list, then run the checks in §4.6 below.
8. Git is run **by the developer manually**. AI sessions do not commit, push or open PRs unless the developer asks.
9. **Never edit generated files by hand**: `docs/01,02,04,05,09,14*.md`, `docs/openapi.yaml`, `database/migrations/*`, `database/schema-contract.json`, `app/Support/Problem/ErrorCatalogue.php`, `app/Domain/Access/RoleTemplates.php`, `contract/*`. Change `docs/source/*.py` and run `make docs`.
10. Keep code identifiers named LedgerFlow (`ledgerflow:*` commands, `LEDGERFLOW_*` env, `ledgerflow` databases, `lf_rt`); only repos, Jira, Vercel and Compose use "Pluto".

## Commands (Docker only; see README)
```bash
./scripts/setup.sh     # first time
make up / make down    # start / stop the dev stack (API http://localhost:8080)
make test              # PHPUnit against ledgerflow_test (never the dev DB)
make lint / make fix   # Laravel Pint
make docs              # regenerate docs, contract and generated code, then validate
make sh                # shell in the app container (php artisan …)
```
Live stack (host machine only): `./scripts/live.sh`, `./scripts/deploy.sh`, `./scripts/tunnel-url.sh`; runbook `docs/runbooks/tunnel-url-changed.md`.

## Backend recipe
### 4.1 Layering (NFR-060)
```
routes/api.php → middleware (jwt → company → perm:<x> → idempotent)
  → FormRequest (extends app/Http/Requests/StrictRequest.php: rejects unknown fields; company-scoped Rule::exists)
  → thin Controller (app/Http/Controllers/Api/V1/<Module>/…Controller.php)
  → Domain service (app/Domain/<Module>/…Service.php: the ONLY place that changes data; DB::transaction)
  → Eloquent models (app/Models, use Concerns/BelongsToCompany, HasLockVersion, TracksActor)
  → API Resource (app/Http/Resources/…Resource.php: exact JSON of the contract; cost masking via inventory.view_costs)
Read-heavy lists/reports → app/Queries/…Query.php (query builder, company_id first).
```
Pattern files: `PartyController.php`, `PartyRequest.php`, `PartyListRequest.php`, `PartyService.php`, `PartyResource.php`, `PartyQuery.php`, `routes/api.php`.

### 4.2 Contract rules (05 §1)
- JSON `snake_case`; envelope `{data}` / `{data, meta}`; money/qty/rates as **decimal strings**; dates `YYYY-MM-DD`.
- Lists paginate (`page`, `per_page` ≤ 100); unknown query params → 422.
- Editable resources return `ETag: W/"<lock_version>"`; PUT/PATCH need `If-Match` (428 / 409 `version_conflict`); the SPA sends it as `X-If-Match` (Vercel's edge answers 412 to a standard `If-Match` after a successful edit) and `AcceptXIfMatch` maps it back — keep reading `If-Match` in controllers.
- Create and post endpoints use the `idempotent` middleware.
- Errors: throw `App\Support\Problem\ApiProblem('<code>', …)`; codes must exist in `ErrorCatalogue` (generated from `docs/source/errors.py`).
- Every mutation writes `audit_logs` through `App\Domain\Shared\AuditLogger`.
- Numbers come from `App\Domain\Shared\DocumentNumberService` (reserved at first save, never reused, BR-006).

### 4.3 Money and posting (from P4 on)
- Money maths only with `brick/math` `BigDecimal` (installed in P4.1). Scale 2 money, 4 unit cost, 6 rate, HALF_UP per line (BR-010).
- Only posting services create journal entries, through `JournalBuilder` → `PostingService` (06 §4). Lock order: document → number_sequences → stock_balances (by product_id, warehouse_id ascending).
- Every posting feature test uses the `BooksAreConsistent` assertion trait (09 §8, built in P4.1).

### 4.4 When the API contract changes
The contract is generated. Never hand-edit `docs/05-*.md`, `docs/openapi.yaml`, migrations, `ErrorCatalogue.php`, `RoleTemplates.php` or `contract/contract.gen.ts`.
1. Edit `docs/source/api.py` / `schemas.py` / `errors.py` / `schema.py` / `registry.py` (and `contract_review.py` with a one-line reason).
2. Run `make docs`: regenerates everything and runs the validators.
3. Commit the source **and** generated files together. Write "**Contract changed**" in the PR description so the frontend dev syncs.
4. Additive changes only during v1 (new fields/endpoints). Breaking changes need a discussion first.

### 4.5 Tests for every endpoint (09 §4)
Feature test class per endpoint group in `tests/Feature/<Module>/`, named with test IDs (`test_TC_PUR_002_…`). Each endpoint gets: happy path · validation (422 codes) · permissions (add rows to the role matrix in `tests/Feature/Access/CompanyAccessTest.php` style) · company isolation (other company's id → 404) · idempotency replay (if idempotent) · ETag/If-Match (if editable). Add the endpoint to `tests/Feature/Contract/OpenApiConformanceTest.php`.

### 4.6 Backend checks before PR
```bash
make test          # PHPUnit on MySQL inside Docker
make docs          # only if docs/source changed; must leave no diff afterwards
make lint          # Laravel Pint (make fix applies it)
```
