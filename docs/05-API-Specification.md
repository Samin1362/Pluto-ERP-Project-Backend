# 05 · API specification

> Status: **Release candidate 1.0.0-rc.1 for approval** (18 Oct 2026), Laravel + Eloquent. **No endpoint is to be implemented until this contract is approved.** ⚑ Auth details (DEC-02) and the items in §12.7 are still open.
>
> **Generated file.** The endpoint reference (§9), schemas (§10), page action map (§11) and contract review (§12) come from `docs/source/api.py`, `schemas.py`, `actions.py`, `errors.py` and `contract_review.py`. The machine-readable contract is [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1), validated against the official OpenAPI 3.1 schema (§8). Size: 309 endpoints, 197 schemas, 70 error codes, 162 pages with 522 mapped actions.

## 1. Conventions

| Topic | Convention |
|---|---|
| Base URL | `https://{host}/api/v1`. The version is in the path; breaking changes go to `/api/v2`, while additive changes (new fields, endpoints or enum values) stay in v1 |
| Contract version | `info.version` is semantic: `1.0.0-rc.1` now, `1.0.0` on approval. Minor = additive, patch = documentation only. Breaking = removing or renaming a path, field, enum value or error `code`, tightening validation, or changing a status code; it needs `/api/v2` (or a deprecation period inside v1) |
| Style | Resource nouns in kebab-case plural (`/sales-invoices`). State changes that aren't plain field edits are **action sub-resources** (`POST /sales-invoices/{id}/post`, `/cancel`, `/reverse`). This keeps posting explicit, idempotent and separately authorized |
| Format | `application/json; charset=utf-8`. JSON keys are `snake_case`, the same as database columns and Laravel attributes; the React client keeps snake_case in its API types (see 08 §6) |
| Envelope | Single resource `{"data": {...}}`; list `{"data": [...], "meta": {"current_page", "per_page", "total", "last_page"}}`; errors use Problem Details (§3) |
| IDs | Numeric `id` (int64). Document numbers (`INV-2026-0588`) are fields, searchable via `q` |
| Money | **Decimal strings**, e.g. `"118450.00"` (2 dp base currency), rates `"121.500000"`, quantities `"24.000"`, unit costs `"845.0000"`. JSON numbers are never used for money, so no client parses money into a float |
| Dates | Business dates `YYYY-MM-DD` (company time zone). Timestamps are ISO 8601 UTC (`2026-10-14T05:20:11Z`) |
| Company scope | Every company-scoped request sends `X-Company-Id`. The server checks an **active membership** on every request; a JWT alone is never proof of access (07 §6) |
| Pagination | `page`, `per_page` (default 25, max 100). Lists are always paginated |
| Filtering and sorting | Explicit query params per endpoint (`status`, `party_id`, `date_from`, `date_to`…), plus `q` for free-text search; `sort=-date` for descending order. Unknown params return 422 |
| Request bodies | Strict: every request schema has `additionalProperties: false`, so a misspelt or extra field is a 422 `validation_failed`, never silently ignored |
| Concurrency | GET of an editable resource returns `ETag: W/"<version>"`. PUT/PATCH on a versioned table need `If-Match`, otherwise **428 `if_match_required`** (RFC 6585); a stale version returns **409 `version_conflict`** and the current version (BR-049 keeps 409 rather than RFC 9110's 412 so the client treats it as a merge conflict) |
| Idempotency | Create and post endpoints accept `Idempotency-Key` (UUID v4, generated once per user action and reused on retry). Within 24 h, the same key + same body replays the stored response; the same key + a different body returns 422 `idempotency_key_reused`; a replay while the first is still running returns 409 `idempotency_in_progress` (BR-050) |
| Permissions | Each endpoint declares `x-permission`. A few are **contextual** (`x-permission-mode: contextual`, `x-permission-map`): vouchers by entry type, approvals by the request's required permission, attachments and audit logs by the owning record's module, allocations by party type (R-09) |
| Async work | Long work (exports, statement e-mails, backups, restore) returns **202** with a job resource to poll (`GET /export-jobs/{id}`) |
| Files | Uploads use `multipart/form-data`; downloads go through a short-lived signed URL (5 min) or a streamed `application/pdf` |
| Rate limits | Auth endpoints: 5/min per IP+e-mail, 20/min per IP. Authenticated: 120/min per user, 30/min for exports. `429` carries `Retry-After` |
| Deprecation | `Deprecation` and `Sunset` headers, announced at least 90 days ahead |

## 2. Authentication summary
`POST /auth/login` returns a short-lived **access JWT** (15 minutes) in the body and sets a rotating **refresh token** in the `pl_rt` cookie (HttpOnly, Secure, SameSite=Strict, Path=/api/v1/auth). The SPA sends `Authorization: Bearer <access>` on every call. On a 401 `token_expired` it calls `POST /auth/refresh` once and retries. Full design: [07-Authentication-and-Authorization](07-Authentication-and-Authorization.md).

## 3. Error response format (RFC 9457 Problem Details)

```json
{
  "type": "https://docs.pluto.example/errors/validation_failed",
  "title": "Some fields need attention",
  "status": 422,
  "code": "validation_failed",
  "detail": "Fix 2 lines to save",
  "request_id": "4f0c7a62-8a8e-4c1f-9b55-0d2f3c1a9e10",
  "errors": {
    "party_id": ["Choose a customer"],
    "lines.1.quantity": ["Enter a quantity greater than zero"]
  }
}
```

`errors` keys use Laravel dot notation, so the React form can map them straight onto fields (08 §7). `request_id` is also in the `X-Request-Id` response header and in the logs. `code` is an enum in `openapi.yaml`; the catalogue below is complete, and each endpoint lists the codes it can return (§9, `x-error-codes`). Codes are stable API: renaming one is a breaking change. A 500 shows only `request_id` to the user, never a stack trace.

**Error catalogue** (from `docs/source/errors.py`; "Endpoints" = how many operations can return the code):

| Code | HTTP | Title (shown to the user) | Rules | Endpoints |
|---|---|---|---|---|
| `approval_required` | 202 | Above your approval limit: submitted for approval | BR-007 | — (outcome) |
| `company_required` | 400 | The X-Company-Id header is missing | BR-037 | 290 |
| `malformed_json` | 400 | The request body is not valid JSON or has the wrong type | — | 128 |
| `invalid_credentials` | 401 | E-mail or password is incorrect | BR-061 | 2 |
| `refresh_reuse_detected` | 401 | A refresh token was reused; every session of this sign-in was revoked | FR-001 | 1 |
| `refresh_token_invalid` | 401 | The session has ended; sign in again | FR-001 | 1 |
| `token_expired` | 401 | The access token has expired; refresh and retry | NFR-012 | 301 |
| `token_invalid` | 401 | The access token is not valid | NFR-012 | 301 |
| `two_factor_failed` | 401 | The authentication code is not valid | FR-004 | 2 |
| `unauthenticated` | 401 | Sign-in is required | NFR-010 | 301 |
| `branch_restricted` | 403 | This record belongs to a branch you cannot access | BR-038 | 3 |
| `not_a_member` | 403 | You are not an active member of this company | BR-037 | 290 |
| `permission_denied` | 403 | Your role does not allow this action | BR-037 | 290 |
| `self_approval_forbidden` | 403 | You cannot approve a voucher you submitted | BR-008 | 2 |
| `system_role` | 403 | The Owner role cannot be changed or deleted | BR-060 | 3 |
| `two_factor_required_by_company` | 403 | Your company requires 2-step verification | FR-004 | 1 |
| `not_found` | 404 | Not found (or it belongs to another company) | BR-037 | 162 |
| `already_posted` | 409 | This document has already been posted | BR-009 | 22 |
| `already_reversed` | 409 | This entry has already been reversed or cancelled | BR-003 | 14 |
| `charge_allocated` | 409 | The charge is part of a posted landed-cost allocation | BR-045 | 4 |
| `created_from_return` | 409 | This debit note belongs to a purchase return; cancel the return | BR-057 | 2 |
| `duplicate` | 409 | A record with the same unique value already exists | — | 20 |
| `duplicate_statement` | 409 | This statement file was already imported | BR-032 | 1 |
| `duplicate_supplier_bill` | 409 | This supplier's bill number is already entered | BR-018 | 3 |
| `has_history` | 409 | This record is used elsewhere; make it inactive instead | BR-026 | 9 |
| `idempotency_in_progress` | 409 | The same request is still being processed | BR-050 | 71 |
| `invalid_transition` | 409 | This status change is not allowed from the current status | BR-003, BR-040 | 9 |
| `last_owner` | 409 | A company must keep at least one active Owner | BR-060 | 3 |
| `not_draft` | 409 | Only a draft can be changed, posted or deleted | BR-025 | 18 |
| `note_applied` | 409 | Remove the note's allocations before cancelling | BR-023 | 1 |
| `role_in_use` | 409 | Move the members to another role first | BR-060 | 3 |
| `seat_limit_reached` | 409 | All seats are in use | BR-059 | 3 |
| `use_document_cancel` | 409 | System entries are corrected through their document | BR-003 | 14 |
| `version_conflict` | 409 | Someone else changed this record; reload to see their changes | BR-049 | 32 |
| `gone` | 410 | This link has expired | — | 2 |
| `file_too_large` | 413 | Files may be up to 10 MB | BR-051 | 5 |
| `file_type_not_allowed` | 415 | Only JPG, PNG or PDF up to 10 MB | BR-051 | 5 |
| `allocation_exceeds_balance` | 422 | The allocation is more than the document's open balance or the payment | BR-020, BR-023 | 12 |
| `backdated_confirmation_required` | 422 | The date is older than the back-date window; confirm to continue | BR-053 | 4 |
| `base_currency_locked` | 422 | The base currency cannot change once entries exist | BR-035 | 1 |
| `comment_required` | 422 | A comment is required to reject | FR-014 | 1 |
| `difference_not_zero` | 422 | The reconciliation difference must be zero | BR-032 | 1 |
| `exceeds_tolerance` | 422 | Payments would exceed the LC amount plus tolerance | BR-042 | 1 |
| `fiscal_year_not_closable` | 422 | The year has not ended or a period is still open | BR-034 | 1 |
| `foreign_document` | 422 | The document belongs to another party | BR-021 | 5 |
| `idempotency_key_reused` | 422 | This Idempotency-Key was used with a different request | BR-050 | 71 |
| `inactive_reference` | 422 | An inactive party, product, ledger, warehouse or account was used | BR-026 | 8 |
| `insufficient_funds` | 422 | The account would go below zero (or below its overdraft limit) | BR-031 | 7 |
| `insufficient_stock` | 422 | Not enough stock in the warehouse | BR-014, BR-030 | 8 |
| `invalid_mobile` | 422 | Mobile numbers have 11 digits starting 01 | BR-063 | 2 |
| `lc_not_closable` | 422 | Unpaid supplier, unpaid charges or missing documents; give an override reason | BR-046 | 1 |
| `lc_submit_incomplete` | 422 | Supplier, amount, issuing bank and expiry are needed | BR-039 | 1 |
| `ledger_locked` | 422 | Code or opening balance cannot change once the ledger has entries | BR-027 | 3 |
| `lines_unhandled` | 422 | Every statement line must be matched, created, paired or ignored | BR-032 | 1 |
| `lock_out_of_order` | 422 | Periods lock and unlock in date order | BR-033 | 2 |
| `mapping_missing` | 422 | A posting ledger is not mapped in Accounting defaults | BR-064, BR-065 | 6 |
| `margin_exceeds_deposit` | 422 | More margin applied than was deposited | BR-044 | 3 |
| `nature_mismatch` | 422 | The group must have the same nature (asset, liability, …) | BR-027 | 3 |
| `period_locked` | 422 | The date is in a locked period or outside an open financial year | BR-004, BR-033 | 34 |
| `purpose_required` | 422 | Choose what the ledger is for | BR-037 | 1 |
| `restore_confirmation_required` | 422 | Type RESTORE to confirm | BR-062 | 1 |
| `return_qty_exceeds` | 422 | More than the quantity still returnable | BR-022 | 5 |
| `rounding_ledger_missing` | 422 | Map the rounding ledger in Accounting defaults first | BR-012 | 2 |
| `same_warehouse` | 422 | Source and destination warehouses must differ | BR-030 | 3 |
| `unbalanced_entry` | 422 | Total debit must equal total credit | BR-001, BR-002 | 29 |
| `validation_failed` | 422 | The request has invalid fields | NFR-030 | 199 |
| `if_match_required` | 428 | If-Match header with the current version is required | BR-049 | 32 |
| `rate_limited` | 429 | Too many requests; try again later | NFR-013 | 9 |
| `server_error` | 500 | Something went wrong; quote the request id | NFR-031 | 309 |
| `company_maintenance` | 503 | This company is being restored from a backup; try again shortly (Retry-After) | BR-062 | 290 |

Non-blocking conditions (credit limit, overdue customer, low stock, budget, possible duplicate party) are returned as `warnings[]` in 2xx responses, not as errors (BR-015).

**Calculate and preview endpoints never reject on business state.** `POST /sales-invoices/calculate`, `/purchase-bills/calculate`, `/vouchers/calculate` and `/letters-of-credit/{id}/landed-cost/preview` return insufficient stock or funds, an unbalanced voucher, a back-dated date or an already-allocated charge in `warnings[]` (and `balanced`/`difference` for vouchers), because they run while the user is still typing. The same conditions are errors on save and post. They reject only on malformed input or missing configuration (`rounding_ledger_missing`, `mapping_missing`).

## 4. Transaction and concurrency rules (all posting endpoints)
1. Each posting endpoint runs in **one database transaction**. The document, its stock movements, its journal entry and the number sequence commit together or not at all (NFR-040).
2. Locks are taken in a fixed order to avoid deadlocks: `number_sequences` → document row → `stock_balances` rows ordered by (`product_id`, `warehouse_id`) → allocated invoice/bill rows ordered by `id`.
3. Double posting is impossible. The document row is locked `FOR UPDATE`, `status` is re-checked inside the lock, and `journal_entry_id` is UNIQUE (BR-009).
4. The period lock is re-checked inside the transaction (BR-004).
5. Successful responses are stored against the `Idempotency-Key` in the same transaction.

## 5. Representative examples

### 5.1 Sign in
```http
POST /api/v1/auth/login
Content-Type: application/json

{"email": "rafiq@meghnatraders.example", "password": "••••••••••", "device_label": "Chrome on Windows"}
```
```http
HTTP/1.1 200 OK
Set-Cookie: pl_rt=8Qm…; Path=/api/v1/auth; HttpOnly; Secure; SameSite=Strict; Max-Age=1209600

{"data": {"status": "authenticated", "access_token": "eyJhbGciOiJFUzI1NiIs…", "token_type": "Bearer", "expires_in": 900,
  "user": {"id": 2, "name": "Rafiq Hasan", "email": "rafiq@meghnatraders.example", "two_factor_enabled": true,
           "companies": [{"company_id": 1, "company_name": "Meghna Traders Ltd.", "role": "Finance manager", "status": "active"}]}}}
```
With 2FA on, the first response is `{"status": "two_factor_required", "challenge_token": "…"}` and no tokens are issued until `POST /auth/two-factor`.

### 5.2 Calculate, create and post a sales invoice
```http
POST /api/v1/sales-invoices/calculate
Authorization: Bearer eyJ…
X-Company-Id: 1

{"party_id": 7, "invoice_date": "2026-10-14", "warehouse_id": 1,
 "lines": [{"product_id": 11, "quantity": "24.000"},
           {"product_id": 16, "quantity": "10.000", "discount_pct": "2.0000"},
           {"product_id": 12, "quantity": "48.000"},
           {"product_id": 15, "quantity": "1.000"}]}
```
```json
{"data": {"lines": [{"line_no": 1, "gross": "22560.00", "discount_amount": "0.00", "tax_amount": "0.00", "line_total": "22560.00", "available_qty": "18.000"},
                    {"line_no": 2, "gross": "23400.00", "discount_amount": "468.00", "tax_amount": "0.00", "line_total": "22932.00", "available_qty": "154.000"},
                    {"line_no": 3, "gross": "12240.00", "discount_amount": "0.00", "tax_amount": "0.00", "line_total": "12240.00", "available_qty": "640.000"},
                    {"line_no": 4, "gross": "150.00", "discount_amount": "0.00", "tax_amount": "0.00", "line_total": "150.00", "available_qty": null}],
  "subtotal": "58350.00", "discount_total": "468.00", "tax_total": "0.00", "rounding_adjustment": "0.00", "total": "57882.00",
  "amount_in_words": "Taka fifty-seven thousand eight hundred eighty-two only",
  "party_balance_before": "212800.00", "party_balance_after": "270682.00", "credit_limit": "300000.00",
  "warnings": [{"code": "PARTY_OVERDUE", "message": "Rahman Super Shop has ৳ 2,12,800 overdue"},
               {"code": "LOW_STOCK", "message": "Only 18 jars at Tejgaon warehouse", "line_no": 1}],
  "effects": ["Adds the total to Rahman Super Shop's balance", "Posts sales income and cost of goods sold", "Takes items out of Tejgaon warehouse stock"]}}
```
`POST /sales-invoices` with the same body (plus `Idempotency-Key`) returns **201** with `number: "INV-2026-0588"` and `status: "draft"`. Then:
```http
POST /api/v1/sales-invoices/588/post
Idempotency-Key: 5b6a2d1e-0f38-4b0a-a7c9-2d9e1f40c3aa

{"acknowledge_warnings": ["PARTY_OVERDUE"]}
```
```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/problem+json

{"type": "https://docs.pluto.example/errors/insufficient_stock", "title": "Not enough stock", "status": 422, "code": "insufficient_stock",
 "detail": "Line 1 needs 24 jars; Tejgaon warehouse has 18.", "request_id": "…", "errors": {"lines.0.quantity": ["Only 18 available at Tejgaon warehouse"]}}
```
After the quantity is corrected, posting returns **200**. The `journal` array in the response holds the posted lines (Dr customer, Cr income ledgers, Dr COGS, Cr inventory) and `payment_status` is `"unpaid"`.

### 5.3 Customer receipt with allocation
```http
POST /api/v1/customer-receipts
Idempotency-Key: 0c1b…

{"party_id": 12, "date": "2026-10-14", "amount": "274700.00", "money_account_id": 1, "method": "cheque",
 "cheque_number": "448133", "cheque_date": "2026-10-14", "drawn_on": "Islami Bank, Mirpur branch",
 "auto_allocate": true, "narration": "Cheque received against INV-2026-0509. Deposited same day."}
```
```json
{"data": {"id": 9211, "voucher_number": "RV-2026-0922", "entry_type": "receipt", "status": "posted", "entry_date": "2026-10-14",
  "total_debit": "274700.00", "total_credit": "274700.00", "amount_in_words": "Taka two lakh seventy-four thousand seven hundred only",
  "lines": [{"line_no": 1, "ledger_code": "1102-01", "ledger_name": "Sonali Bank, current", "debit": "274700.00", "credit": "0.00"},
            {"line_no": 2, "ledger_code": "1201-012", "ledger_name": "Karim Distributors", "debit": "0.00", "credit": "274700.00"}],
  "payment": {"method": "cheque", "cheque_number": "448133", "unapplied_amount": "0.00",
              "allocations": [{"document_number": "INV-2026-0509", "amount": "274700.00"}]}}}
```

### 5.4 Version conflict
```http
PUT /api/v1/sales-invoices/586
If-Match: W/"3"
```
```json
{"type": "https://docs.pluto.example/errors/version_conflict", "title": "Someone else changed this invoice", "status": 409, "code": "version_conflict",
 "detail": "Shirin Akter saved INV-2026-0586 at 01:02. Reload to see her version; your edits are kept in a draft.", "request_id": "…", "errors": {"current_version": ["4"]}}
```

### 5.5 LC payment at the bank's rate
```http
POST /api/v1/letters-of-credit/19/payments

{"payment_date": "2026-11-28", "amount_foreign": "38400.00", "bank_rate": "122.100000", "money_account_id": 1,
 "margin_applied": "933120.00", "bank_reference": "SBL/TJG/IMP/26/0719/P1"}
```
```json
{"data": {"id": 31, "amount_foreign": "38400.00", "bank_rate": "122.100000", "amount_bdt": "4688640.00", "margin_applied": "933120.00",
  "fx_difference": "0.00", "status": "posted", "journal_entry_id": 9420}}
```
The journal debits the supplier ৳ 46,88,640 (an advance until the import bill is booked) and credits LC margin deposits ৳ 9,33,120 and the bank ৳ 37,55,520. `fx_difference` is zero now; when the import bill (booked at its own rate, e.g. 121.50 = ৳ 46,65,600) is settled by this payment, the ৳ 23,040 difference posts to exchange gain or loss (06 §12.5).

## 6. Status-code policy

`GET` returns 200. Creates return 201 with a `Location` header. Actions return 200, or 202 when queued (large exports) or awaiting approval (BR-007). Deletes return 204. Each endpoint's status list (§9) is **derived**: the success code, plus the HTTP status of every error code it can return. Those come from its business rules (`RULE_ERRORS`), path parameters (404), request body (400/422), If-Match (409/428), Idempotency-Key (409/422), authentication (401), company scope (400/403) and rate limits (429). Every operation also documents `default` → 500 `server_error`.

## 7. Endpoint index

| # | Method | URL | Purpose | Permission | Success |
|---|---|---|---|---|---|
| 1 | `POST` | [`/api/v1/auth/login`](#postauthlogin) | Sign in with e-mail and password | public | 200 |
| 2 | `POST` | [`/api/v1/auth/two-factor`](#postauthtwofactor) | Complete sign-in with a TOTP or recovery code | public | 200 |
| 3 | `POST` | [`/api/v1/auth/refresh`](#postauthrefresh) | Rotate the refresh cookie and issue a new access token | public (refresh cookie) | 200 |
| 4 | `POST` | [`/api/v1/auth/logout`](#postauthlogout) | Sign out this session (revokes refresh token, clears cookie) | public (refresh cookie) | 204 |
| 5 | `POST` | [`/api/v1/auth/password/forgot`](#postauthpasswordforgot) | Send a password-reset link | public | 202 |
| 6 | `POST` | [`/api/v1/auth/password/reset`](#postauthpasswordreset) | Set a new password with a reset token | public | 204 |
| 7 | `GET` | [`/api/v1/invitations/{token}`](#getinvitationstoken) | Show an invitation (company, role, e-mail) | public | 200 |
| 8 | `POST` | [`/api/v1/invitations/{token}/accept`](#postinvitationstokenaccept) | Accept an invitation (creates or links the user) | public | 200 |
| 9 | `GET` | [`/api/v1/me`](#getme) | Current user, memberships and effective permissions in the active company | any signed-in user | 200 |
| 10 | `PUT` | [`/api/v1/me/password`](#putmepassword) | Change own password (revokes other sessions) | any signed-in user | 204 |
| 11 | `POST` | [`/api/v1/me/two-factor`](#postmetwofactor) | Start 2FA set-up (returns otpauth URI and QR) | any signed-in user | 200 |
| 12 | `POST` | [`/api/v1/me/two-factor/confirm`](#postmetwofactorconfirm) | Confirm 2FA with a code; returns recovery codes once | any signed-in user | 201 |
| 13 | `DELETE` | [`/api/v1/me/two-factor`](#deletemetwofactor) | Turn off 2FA (requires password; blocked if the company requires 2FA) | any signed-in user | 204 |
| 14 | `GET` | [`/api/v1/me/sessions`](#getmesessions) | List own signed-in sessions | any signed-in user | 200 |
| 15 | `DELETE` | [`/api/v1/me/sessions/{id}`](#deletemesessionsid) | Revoke one session | any signed-in user | 204 |
| 16 | `POST` | [`/api/v1/me/sessions/revoke-others`](#postmesessionsrevokeothers) | Sign out everywhere else | any signed-in user | 204 |
| 17 | `GET` | [`/api/v1/me/preferences`](#getmepreferences) | Get appearance preferences | any signed-in user | 200 |
| 18 | `PUT` | [`/api/v1/me/preferences`](#putmepreferences) | Save appearance preferences | any signed-in user | 200 |
| 19 | `GET` | [`/api/v1/me/notification-preferences`](#getmenotificationpreferences) | Get own notification switches for the active company | any signed-in user who is an active member of the company | 200 |
| 20 | `PUT` | [`/api/v1/me/notification-preferences`](#putmenotificationpreferences) | Save own notification switches | any signed-in user who is an active member of the company | 200 |
| 21 | `POST` | [`/api/v1/companies`](#postcompanies) | Create a company (the creator becomes Owner; chart of accounts seeded) | any signed-in user | 201 |
| 22 | `GET` | [`/api/v1/company`](#getcompany) | Active company profile and locations | `settings.view` | 200 |
| 23 | `PATCH` | [`/api/v1/company`](#patchcompany) | Edit company identity and contact | `settings.edit` | 200 |
| 24 | `PUT` | [`/api/v1/company/logo`](#putcompanylogo) | Upload or replace the logo (PNG/SVG) | `settings.edit` | 200 |
| 25 | `DELETE` | [`/api/v1/company/logo`](#deletecompanylogo) | Remove the logo | `settings.edit` | 204 |
| 26 | `GET` | [`/api/v1/company/settings`](#getcompanysettings) | Preferences, controls, currency display, tax display, invoice design, security and backup settings | any signed-in user who is an active member of the company | 200 |
| 27 | `PATCH` | [`/api/v1/company/settings`](#patchcompanysettings) | Update settings (partial) | `settings.edit` | 200 |
| 28 | `GET` | [`/api/v1/branches`](#getbranches) | List locations (pickers and report filters) | any signed-in user who is an active member of the company | 200 |
| 29 | `POST` | [`/api/v1/branches`](#postbranches) | Add a location | `settings.edit` | 201 |
| 30 | `PATCH` | [`/api/v1/branches/{id}`](#patchbranchesid) | Edit a location | `settings.edit` | 200 |
| 31 | `GET` | [`/api/v1/payment-terms`](#getpaymentterms) | List payment terms | any signed-in user who is an active member of the company | 200 |
| 32 | `GET` | [`/api/v1/number-sequences`](#getnumbersequences) | Document numbering series for the current FY | `settings.view` | 200 |
| 33 | `PATCH` | [`/api/v1/number-sequences/{id}`](#patchnumbersequencesid) | Change prefix, padding or next number (never below the last used) | `settings.edit` | 200 |
| 34 | `GET` | [`/api/v1/tax-rules`](#gettaxrules) | List tax rules | any signed-in user who is an active member of the company | 200 |
| 35 | `POST` | [`/api/v1/tax-rules`](#posttaxrules) | Add a tax rule (user-entered rate) | `settings.edit` | 201 |
| 36 | `PATCH` | [`/api/v1/tax-rules/{id}`](#patchtaxrulesid) | Edit or deactivate a tax rule (rate changes apply to new documents only) | `settings.edit` | 200 |
| 37 | `GET` | [`/api/v1/account-mappings`](#getaccountmappings) | Posting-rule ledger mappings | `accounting.view` | 200 |
| 38 | `PUT` | [`/api/v1/account-mappings`](#putaccountmappings) | Save mappings (nature-checked per key) | `settings.edit` | 200 |
| 39 | `GET` | [`/api/v1/fiscal-years`](#getfiscalyears) | Financial years with period status | any signed-in user who is an active member of the company | 200 |
| 40 | `POST` | [`/api/v1/accounting-periods/{id}/lock`](#postaccountingperiodsidlock) | Lock a period (earlier periods must be locked) | `accounting.lock_period` | 200 |
| 41 | `POST` | [`/api/v1/accounting-periods/{id}/unlock`](#postaccountingperiodsidunlock) | Unlock a period with a reason (later periods must be open) | `accounting.lock_period` | 200 |
| 42 | `POST` | [`/api/v1/fiscal-years/{id}/close`](#postfiscalyearsidclose) | Close the financial year (posts year-end entry, opens next FY) | `accounting.close_year` | 200 |
| 43 | `GET` | [`/api/v1/users`](#getusers) | Members of the company with role and status | `settings.users` | 200 |
| 44 | `GET` | [`/api/v1/users/{id}`](#getusersid) | Member details, recent activity and role permissions | `settings.users` | 200 |
| 45 | `PATCH` | [`/api/v1/users/{id}`](#patchusersid) | Change a member's role or branch restriction | `settings.users` | 200 |
| 46 | `POST` | [`/api/v1/users/{id}/deactivate`](#postusersiddeactivate) | Deactivate a member (revokes sessions; their pending approvals stay queued) | `settings.users` | 200 |
| 47 | `POST` | [`/api/v1/users/{id}/reactivate`](#postusersidreactivate) | Reactivate a member (seat check) | `settings.users` | 200 |
| 48 | `POST` | [`/api/v1/invitations`](#postinvitations) | Invite someone by e-mail with a role | `settings.users` | 201 |
| 49 | `POST` | [`/api/v1/invitations/{id}/resend`](#postinvitationsidresend) | Resend an invitation (new token, new 7-day expiry) | `settings.users` | 200 |
| 50 | `DELETE` | [`/api/v1/invitations/{id}`](#deleteinvitationsid) | Revoke an invitation | `settings.users` | 204 |
| 51 | `GET` | [`/api/v1/roles`](#getroles) | Roles with member counts | `settings.roles` | 200 |
| 52 | `GET` | [`/api/v1/roles/{id}`](#getrolesid) | Role with permission matrix and approval limits | `settings.roles` | 200 |
| 53 | `POST` | [`/api/v1/roles`](#postroles) | Create a role (optionally copying another) | `settings.roles` | 201 |
| 54 | `PUT` | [`/api/v1/roles/{id}`](#putrolesid) | Replace a role's permissions and limits (Owner role is locked) | `settings.roles` | 200 |
| 55 | `DELETE` | [`/api/v1/roles/{id}`](#deleterolesid) | Delete a role with no members | `settings.roles` | 204 |
| 56 | `GET` | [`/api/v1/permissions`](#getpermissions) | Permission catalogue | `settings.roles` | 200 |
| 57 | `GET` | [`/api/v1/search`](#getsearch) | Global search across parties, documents, vouchers, ledgers and products (permission-filtered) | any signed-in user who is an active member of the company | 200 |
| 58 | `GET` | [`/api/v1/notifications`](#getnotifications) | Notification feed | any signed-in user who is an active member of the company | 200 |
| 59 | `POST` | [`/api/v1/notifications/{id}/read`](#postnotificationsidread) | Mark a notification read | any signed-in user who is an active member of the company | 204 |
| 60 | `POST` | [`/api/v1/notifications/read-all`](#postnotificationsreadall) | Mark all read | any signed-in user who is an active member of the company | 204 |
| 61 | `GET` | [`/api/v1/approvals`](#getapprovals) | Approval requests (pending for me, or all) | any signed-in user who is an active member of the company | 200 |
| 62 | `GET` | [`/api/v1/approvals/{id}`](#getapprovalsid) | One approval request with the document facts shown in the L4 drawer | contextual — any → `the requester, or a holder of the request's required_permission` | 200 |
| 63 | `POST` | [`/api/v1/approvals/{id}/approve`](#postapprovalsidapprove) | Approve and post the document | contextual — any → `the request's required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)` | 200 |
| 64 | `POST` | [`/api/v1/approvals/{id}/reject`](#postapprovalsidreject) | Reject with a required comment (document returns to draft as rejected) | contextual — any → `the request's required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)` | 200 |
| 65 | `POST` | [`/api/v1/attachments`](#postattachments) | Upload a file to a record (multipart) | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` | 201 |
| 66 | `GET` | [`/api/v1/attachments/{id}/url`](#getattachmentsidurl) | Short-lived signed download URL (5 minutes) | contextual — any → `<module>.view of the record named by type/id (e.g. sales.view for a sales_invoice)` | 200 |
| 67 | `DELETE` | [`/api/v1/attachments/{id}`](#deleteattachmentsid) | Remove an attachment (not from posted vouchers in locked periods) | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` | 204 |
| 68 | `GET` | [`/api/v1/audit-logs`](#getauditlogs) | Activity timeline of one record | contextual — any → `<module>.view of the record named by type/id (e.g. sales.view for a sales_invoice)` | 200 |
| 69 | `GET` | [`/api/v1/dashboard`](#getdashboard) | Main dashboard data for a period | `dashboard.view` | 200 |
| 70 | `GET` | [`/api/v1/dashboard/lenses/{lens}`](#getdashboardlenseslens) | Dashboard lens data (financial, sales, expenses, receivables, payables, cash, inventory) | `dashboard.view` | 200 |
| 71 | `GET` | [`/api/v1/backups`](#getbackups) | Backup history and settings | `settings.backup` | 200 |
| 72 | `POST` | [`/api/v1/backups`](#postbackups) | Take a manual backup now (queued) | `settings.backup` | 202 |
| 73 | `GET` | [`/api/v1/backups/{id}/download`](#getbackupsiddownload) | Signed URL of a backup file (Owner only: snapshots hold the whole company, R-11) | `settings.restore` | 200 |
| 74 | `POST` | [`/api/v1/backups/{id}/restore`](#postbackupsidrestore) | Restore the company from a backup (Owner; confirmation text RESTORE) | `settings.restore` | 202 |
| 75 | `POST` | [`/api/v1/export-jobs`](#postexportjobs) | Prepare the month-end export pack or a large report export | `reports.export` | 202 |
| 76 | `GET` | [`/api/v1/export-jobs/{id}`](#getexportjobsid) | Export job status and signed download URL when complete | `reports.export` | 200 |
| 77 | `GET` | [`/api/v1/parties`](#getparties) | List customers or suppliers | `parties.view` | 200 |
| 78 | `POST` | [`/api/v1/parties`](#postparties) | Create a customer or supplier (and its ledger, and opening balance entry) | `parties.create` | 201 |
| 79 | `GET` | [`/api/v1/parties/{id}`](#getpartiesid) | Party with contact, terms, bank details, KPIs and credit used | `parties.view` | 200 |
| 80 | `PATCH` | [`/api/v1/parties/{id}`](#patchpartiesid) | Edit a party (opening balance locked after first document) | `parties.edit` | 200 |
| 81 | `POST` | [`/api/v1/parties/{id}/deactivate`](#postpartiesiddeactivate) | Make inactive (hidden from new documents) | `parties.edit` | 200 |
| 82 | `POST` | [`/api/v1/parties/{id}/reactivate`](#postpartiesidreactivate) | Make active again | `parties.edit` | 200 |
| 83 | `POST` | [`/api/v1/parties/bulk-deactivate`](#postpartiesbulkdeactivate) | Make several parties inactive (each with history is deactivated, never deleted) | `parties.edit` | 200 |
| 84 | `DELETE` | [`/api/v1/parties/{id}`](#deletepartiesid) | Delete a party with no history (type the name to confirm in UI) | `parties.delete` | 204 |
| 85 | `GET` | [`/api/v1/parties/{id}/open-documents`](#getpartiesidopendocuments) | Open invoices or bills with balance, oldest due first | `parties.view` | 200 |
| 86 | `GET` | [`/api/v1/parties/{id}/transactions`](#getpartiesidtransactions) | Document history of a party | `parties.view` | 200 |
| 87 | `GET` | [`/api/v1/parties/{id}/ledger`](#getpartiesidledger) | Party ledger with opening, running and closing balance | `parties.view` | 200 |
| 88 | `GET` | [`/api/v1/parties/outstanding`](#getpartiesoutstanding) | Open documents across parties by age bucket | `parties.view` | 200 |
| 89 | `GET` | [`/api/v1/parties/{id}/statement`](#getpartiesidstatement) | Statement of account (JSON for the screen) | `parties.view` | 200 |
| 90 | `GET` | [`/api/v1/parties/{id}/statement/pdf`](#getpartiesidstatementpdf) | Statement of account as PDF (same parameters as the JSON statement) | `parties.view` | 200 |
| 91 | `POST` | [`/api/v1/parties/statements/send`](#postpartiesstatementssend) | E-mail statements to one or more parties (queued) | `parties.view` | 202 |
| 92 | `GET` | [`/api/v1/party-groups`](#getpartygroups) | Customer or supplier groups | `parties.view` | 200 |
| 93 | `POST` | [`/api/v1/party-groups`](#postpartygroups) | Add a group | `parties.create` | 201 |
| 94 | `GET` | [`/api/v1/products`](#getproducts) | List products and services with stock status | `inventory.view` | 200 |
| 95 | `POST` | [`/api/v1/products`](#postproducts) | Create a product or service (optional opening stock per warehouse) | `inventory.create` | 201 |
| 96 | `POST` | [`/api/v1/products/import`](#postproductsimport) | Import products from CSV/XLSX: dry_run=true validates and previews, false creates in one transaction | `inventory.create` | 200 |
| 97 | `GET` | [`/api/v1/products/{id}`](#getproductsid) | Product with stock by warehouse, costs (if permitted) and price history | `inventory.view` | 200 |
| 98 | `PATCH` | [`/api/v1/products/{id}`](#patchproductsid) | Edit a product (quantities never change here) | `inventory.edit` | 200 |
| 99 | `DELETE` | [`/api/v1/products/{id}`](#deleteproductsid) | Delete an unused product; used products are deactivated instead | `inventory.delete` | 204 |
| 100 | `GET` | [`/api/v1/stock-movements`](#getstockmovements) | All stock movements | `inventory.view` | 200 |
| 101 | `GET` | [`/api/v1/product-categories`](#getproductcategories) | List categorys | `inventory.view` | 200 |
| 102 | `POST` | [`/api/v1/product-categories`](#postproductcategories) | Create a category | `inventory.create` | 201 |
| 103 | `PATCH` | [`/api/v1/product-categories/{id}`](#patchproductcategoriesid) | Edit a category | `inventory.edit` | 200 |
| 104 | `DELETE` | [`/api/v1/product-categories/{id}`](#deleteproductcategoriesid) | Delete an unused category | `inventory.delete` | 204 |
| 105 | `GET` | [`/api/v1/stock-groups`](#getstockgroups) | List stock groups | `inventory.view` | 200 |
| 106 | `POST` | [`/api/v1/stock-groups`](#poststockgroups) | Create a stock group | `inventory.create` | 201 |
| 107 | `PATCH` | [`/api/v1/stock-groups/{id}`](#patchstockgroupsid) | Edit a stock group | `inventory.edit` | 200 |
| 108 | `DELETE` | [`/api/v1/stock-groups/{id}`](#deletestockgroupsid) | Delete an unused stock group | `inventory.delete` | 204 |
| 109 | `GET` | [`/api/v1/units`](#getunits) | List units | `inventory.view` | 200 |
| 110 | `POST` | [`/api/v1/units`](#postunits) | Create a unit | `inventory.create` | 201 |
| 111 | `GET` | [`/api/v1/warehouses`](#getwarehouses) | Warehouses with value, products, below-reorder count | `inventory.view` | 200 |
| 112 | `POST` | [`/api/v1/warehouses`](#postwarehouses) | Create a warehouse (inventory ledger created under 1301 unless given) | `inventory.create` | 201 |
| 113 | `GET` | [`/api/v1/warehouses/{id}`](#getwarehousesid) | Warehouse details and recent transfers | `inventory.view` | 200 |
| 114 | `PATCH` | [`/api/v1/warehouses/{id}`](#patchwarehousesid) | Edit a warehouse | `inventory.edit` | 200 |
| 115 | `GET` | [`/api/v1/warehouses/{id}/stock`](#getwarehousesidstock) | Stock in one warehouse | `inventory.view` | 200 |
| 116 | `GET` | [`/api/v1/stock-adjustments`](#getstockadjustments) | List StockAdjustment documents | `inventory.view` | 200 |
| 117 | `POST` | [`/api/v1/stock-adjustments`](#poststockadjustments) | Create a draft StockAdjustment | `inventory.create` | 201 |
| 118 | `GET` | [`/api/v1/stock-adjustments/{id}`](#getstockadjustmentsid) | Get a StockAdjustment | `inventory.view` | 200 |
| 119 | `PATCH` | [`/api/v1/stock-adjustments/{id}`](#patchstockadjustmentsid) | Edit a draft StockAdjustment | `inventory.edit` | 200 |
| 120 | `DELETE` | [`/api/v1/stock-adjustments/{id}`](#deletestockadjustmentsid) | Delete a draft StockAdjustment (number voided) | `inventory.delete` | 204 |
| 121 | `GET` | [`/api/v1/stock-transfers`](#getstocktransfers) | List StockTransfer documents | `inventory.view` | 200 |
| 122 | `POST` | [`/api/v1/stock-transfers`](#poststocktransfers) | Create a draft StockTransfer | `inventory.create` | 201 |
| 123 | `GET` | [`/api/v1/stock-transfers/{id}`](#getstocktransfersid) | Get a StockTransfer | `inventory.view` | 200 |
| 124 | `PATCH` | [`/api/v1/stock-transfers/{id}`](#patchstocktransfersid) | Edit a draft StockTransfer | `inventory.edit` | 200 |
| 125 | `DELETE` | [`/api/v1/stock-transfers/{id}`](#deletestocktransfersid) | Delete a draft StockTransfer (number voided) | `inventory.delete` | 204 |
| 126 | `POST` | [`/api/v1/stock-adjustments/{id}/post`](#poststockadjustmentsidpost) | Post an adjustment: stock movements + value journal | `inventory.create` | 200 |
| 127 | `POST` | [`/api/v1/stock-adjustments/{id}/reverse`](#poststockadjustmentsidreverse) | Reverse a posted adjustment | `inventory.approve` | 200 |
| 128 | `POST` | [`/api/v1/stock-adjustments/count-sheet`](#poststockadjustmentscountsheet) | Parse an uploaded count sheet into adjustment lines (no save) | `inventory.create` | 200 |
| 129 | `POST` | [`/api/v1/stock-transfers/{id}/dispatch`](#poststocktransfersiddispatch) | Dispatch: stock leaves the source (in transit) | `inventory.create` | 200 |
| 130 | `POST` | [`/api/v1/stock-transfers/{id}/receive`](#poststocktransfersidreceive) | Receive at destination (optionally with received quantities) | `inventory.create` | 200 |
| 131 | `POST` | [`/api/v1/stock-transfers/{id}/cancel`](#poststocktransfersidcancel) | Cancel a draft or dispatched transfer (dispatched stock returns to source) | `inventory.edit` | 200 |
| 132 | `GET` | [`/api/v1/reports/inventory/summary`](#getreportsinventorysummary) | Inventory summary (value by group, flow, movers, attention) | `inventory.view` | 200 |
| 133 | `GET` | [`/api/v1/reports/inventory/low-stock`](#getreportsinventorylowstock) | Items at or below reorder level with suggested quantities | `inventory.view` | 200 |
| 134 | `GET` | [`/api/v1/reports/inventory/valuation`](#getreportsinventoryvaluation) | Stock valuation at weighted average cost as of a date | `inventory.view_costs` | 200 |
| 135 | `GET` | [`/api/v1/reports/trial-balance`](#getreportstrialbalance) | Trial balance (JSON; xlsx/pdf/csv via format) | `reports.view` | 200 |
| 136 | `GET` | [`/api/v1/reports/profit-and-loss`](#getreportsprofitandloss) | Profit and loss (JSON; xlsx/pdf/csv via format) | `reports.view` | 200 |
| 137 | `GET` | [`/api/v1/reports/balance-sheet`](#getreportsbalancesheet) | Balance sheet (JSON; xlsx/pdf/csv via format) | `reports.view` | 200 |
| 138 | `GET` | [`/api/v1/reports/cash-flow`](#getreportscashflow) | Cash flow (indirect) (JSON; xlsx/pdf/csv via format) | `reports.view` | 200 |
| 139 | `GET` | [`/api/v1/reports/general-ledger`](#getreportsgeneralledger) | General ledger (JSON; xlsx/pdf/csv via format) | `reports.view` | 200 |
| 140 | `GET` | [`/api/v1/reports/receivables-aging`](#getreportsreceivablesaging) | Receivables aging (JSON; xlsx/pdf/csv via format) | `reports.view` | 200 |
| 141 | `GET` | [`/api/v1/reports/payables-aging`](#getreportspayablesaging) | Payables aging (JSON; xlsx/pdf/csv via format) | `reports.view` | 200 |
| 142 | `GET` | [`/api/v1/reports/sales`](#getreportssales) | Sales report | `reports.view` | 200 |
| 143 | `GET` | [`/api/v1/reports/sales-returns`](#getreportssalesreturns) | Sales return report | `reports.view` | 200 |
| 144 | `GET` | [`/api/v1/reports/purchases`](#getreportspurchases) | Purchase report | `reports.view` | 200 |
| 145 | `GET` | [`/api/v1/reports/purchase-returns`](#getreportspurchasereturns) | Purchase return report | `reports.view` | 200 |
| 146 | `GET` | [`/api/v1/reports/income`](#getreportsincome) | Income report | `reports.view` | 200 |
| 147 | `GET` | [`/api/v1/reports/expenses`](#getreportsexpenses) | Expense report | `reports.view` | 200 |
| 148 | `GET` | [`/api/v1/reports/lc/{report}`](#getreportslcreport) | LC reports: register, status, outstanding, expiry, docs, amendments, charges, cost | `lc.view` | 200 |
| 149 | `GET` | [`/api/v1/sales/overview`](#getsalesoverview) | Sales overview tiles, months, status breakdown, recent, top products | `sales.view` | 200 |
| 150 | `POST` | [`/api/v1/sales-invoices/calculate`](#postsalesinvoicescalculate) | Authoritative totals for an unsaved invoice (no write) | `sales.create` | 200 |
| 151 | `GET` | [`/api/v1/sales-invoices`](#getsalesinvoices) | List invoices | `sales.view` | 200 |
| 152 | `POST` | [`/api/v1/sales-invoices`](#postsalesinvoices) | Create a draft invoice (number reserved) | `sales.create` | 201 |
| 153 | `GET` | [`/api/v1/sales-invoices/{id}`](#getsalesinvoicesid) | Invoice with lines, payments (allocations), ledger entries and delivery | `sales.view` | 200 |
| 154 | `PUT` | [`/api/v1/sales-invoices/{id}`](#putsalesinvoicesid) | Replace a draft invoice (header and lines) | `sales.edit` | 200 |
| 155 | `PATCH` | [`/api/v1/sales-invoices/{id}/delivery`](#patchsalesinvoicesiddelivery) | Record challan, vehicle and delivery time (allowed after posting) | `sales.edit` | 200 |
| 156 | `DELETE` | [`/api/v1/sales-invoices/{id}`](#deletesalesinvoicesid) | Delete a draft (number voided, never reused) | `sales.delete` | 204 |
| 157 | `POST` | [`/api/v1/sales-invoices/{id}/post`](#postsalesinvoicesidpost) | Post: stock out at average cost, journal entry, customer balance | `sales.create` | 200 |
| 158 | `POST` | [`/api/v1/sales-invoices/bulk-post`](#postsalesinvoicesbulkpost) | Post several drafts (each in its own transaction; per-item results) | `sales.create` | 200 |
| 159 | `POST` | [`/api/v1/sales-invoices/{id}/cancel`](#postsalesinvoicesidcancel) | Cancel a posted invoice (reversal + stock back; payments become advance) | `sales.edit` | 200 |
| 160 | `GET` | [`/api/v1/sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf) | Invoice PDF per invoice design (also challan with ?variant=challan) | `sales.view` | 200 |
| 161 | `POST` | [`/api/v1/sales-invoices/{id}/send`](#postsalesinvoicesidsend) | E-mail the invoice PDF to the customer (queued) | `sales.edit` | 202 |
| 162 | `POST` | [`/api/v1/customer-receipts`](#postcustomerreceipts) | Record a customer payment with allocations (creates RV) | `sales.create` | 201 |
| 163 | `GET` | [`/api/v1/customer-receipts`](#getcustomerreceipts) | List customer receipts (RV) with party, account, method and allocation status | `sales.view` | 200 |
| 164 | `GET` | [`/api/v1/customer-receipts/{id}`](#getcustomerreceiptsid) | Get a customer receipt with its allocations | `sales.view` | 200 |
| 165 | `GET` | [`/api/v1/customer-receipts/{id}/pdf`](#getcustomerreceiptsidpdf) | Printable receipt voucher | `sales.view` | 200 |
| 166 | `POST` | [`/api/v1/allocations`](#postallocations) | Apply an advance, unapplied receipt/payment or open credit/debit note to an invoice or bill | contextual — party is a customer → `sales.create`; party is a supplier → `purchases.create` | 201 |
| 167 | `DELETE` | [`/api/v1/allocations/{id}`](#deleteallocationsid) | Remove an allocation (sets reversed_at; balances restored) | contextual — party is a customer → `sales.edit`; party is a supplier → `purchases.edit` | 204 |
| 168 | `GET` | [`/api/v1/quotations`](#getquotations) | List quotations | `sales.view` | 200 |
| 169 | `POST` | [`/api/v1/quotations`](#postquotations) | Create a quotation | `sales.create` | 201 |
| 170 | `GET` | [`/api/v1/quotations/{id}`](#getquotationsid) | Get a quotation | `sales.view` | 200 |
| 171 | `PUT` | [`/api/v1/quotations/{id}`](#putquotationsid) | Replace a draft or sent quotation | `sales.edit` | 200 |
| 172 | `POST` | [`/api/v1/quotations/{id}/send`](#postquotationsidsend) | Mark sent and e-mail it | `sales.edit` | 200 |
| 173 | `POST` | [`/api/v1/quotations/{id}/convert`](#postquotationsidconvert) | Convert an accepted quotation into a draft invoice | `sales.create` | 201 |
| 174 | `POST` | [`/api/v1/quotations/{id}/status`](#postquotationsidstatus) | Set accepted, declined or expired | `sales.edit` | 200 |
| 175 | `DELETE` | [`/api/v1/quotations/{id}`](#deletequotationsid) | Delete a draft quotation (number voided and audited) | `sales.delete` | 204 |
| 176 | `GET` | [`/api/v1/quotations/{id}/pdf`](#getquotationsidpdf) | Printable quotation (also attached when sent) | `sales.view` | 200 |
| 177 | `GET` | [`/api/v1/sales-returns`](#getsalesreturns) | List sales returns | `sales.view` | 200 |
| 178 | `POST` | [`/api/v1/sales-returns`](#postsalesreturns) | Create a draft return against an invoice | `sales.create` | 201 |
| 179 | `GET` | [`/api/v1/sales-returns/{id}`](#getsalesreturnsid) | Get a return | `sales.view` | 200 |
| 180 | `PUT` | [`/api/v1/sales-returns/{id}`](#putsalesreturnsid) | Replace a draft return | `sales.edit` | 200 |
| 181 | `POST` | [`/api/v1/sales-returns/{id}/post`](#postsalesreturnsidpost) | Post the return (stock back if restock) and create a draft credit note | `sales.create` | 200 |
| 182 | `DELETE` | [`/api/v1/sales-returns/{id}`](#deletesalesreturnsid) | Delete a draft sales return | `sales.delete` | 204 |
| 183 | `POST` | [`/api/v1/sales-returns/{id}/cancel`](#postsalesreturnsidcancel) | Cancel a posted return: mirror stock entry; its credit note is cancelled if unapplied | `sales.edit` | 200 |
| 184 | `GET` | [`/api/v1/credit-notes/{id}`](#getcreditnotesid) | Get a credit note | `sales.view` | 200 |
| 185 | `PUT` | [`/api/v1/credit-notes/{id}`](#putcreditnotesid) | Edit a draft credit note (reason, amount, usage) | `sales.edit` | 200 |
| 186 | `POST` | [`/api/v1/credit-notes`](#postcreditnotes) | Create a credit note without a return (price correction, goodwill) | `sales.create` | 201 |
| 187 | `POST` | [`/api/v1/credit-notes/{id}/post`](#postcreditnotesidpost) | Post the credit note and apply it per usage | `sales.create` | 200 |
| 188 | `GET` | [`/api/v1/credit-notes`](#getcreditnotes) | List credit notes | `sales.view` | 200 |
| 189 | `DELETE` | [`/api/v1/credit-notes/{id}`](#deletecreditnotesid) | Delete a draft credit note | `sales.delete` | 204 |
| 190 | `POST` | [`/api/v1/credit-notes/{id}/cancel`](#postcreditnotesidcancel) | Cancel a posted credit note (mirror entry; its allocations are reversed) | `sales.edit` | 200 |
| 191 | `GET` | [`/api/v1/credit-notes/{id}/pdf`](#getcreditnotesidpdf) | Printable credit note | `sales.view` | 200 |
| 192 | `GET` | [`/api/v1/purchases/overview`](#getpurchasesoverview) | Purchase overview | `purchases.view` | 200 |
| 193 | `POST` | [`/api/v1/purchase-bills/calculate`](#postpurchasebillscalculate) | Authoritative totals for an unsaved bill (incl. foreign currency) | `purchases.create` | 200 |
| 194 | `GET` | [`/api/v1/purchase-bills`](#getpurchasebills) | List bills | `purchases.view` | 200 |
| 195 | `POST` | [`/api/v1/purchase-bills`](#postpurchasebills) | Create a draft bill (local or import mode) | `purchases.create` | 201 |
| 196 | `GET` | [`/api/v1/purchase-bills/{id}`](#getpurchasebillsid) | Bill with lines, payments, ledger entries, receipt | `purchases.view` | 200 |
| 197 | `PUT` | [`/api/v1/purchase-bills/{id}`](#putpurchasebillsid) | Replace a draft bill | `purchases.edit` | 200 |
| 198 | `DELETE` | [`/api/v1/purchase-bills/{id}`](#deletepurchasebillsid) | Delete a draft bill | `purchases.delete` | 204 |
| 199 | `POST` | [`/api/v1/purchase-bills/{id}/post`](#postpurchasebillsidpost) | Post: stock in, average cost update, journal, supplier balance, PO received qty | `purchases.create` | 200 |
| 200 | `POST` | [`/api/v1/purchase-bills/{id}/cancel`](#postpurchasebillsidcancel) | Cancel a posted bill (reverse; stock out at bill cost; payments to advance) | `purchases.edit` | 200 |
| 201 | `POST` | [`/api/v1/supplier-payments`](#postsupplierpayments) | Record a supplier payment with allocations (creates PV; may need approval) | `purchases.create` | 201 |
| 202 | `GET` | [`/api/v1/supplier-payments`](#getsupplierpayments) | List supplier payments (PV) with party, account, method and status | `purchases.view` | 200 |
| 203 | `GET` | [`/api/v1/supplier-payments/{id}`](#getsupplierpaymentsid) | Get a supplier payment with its allocations | `purchases.view` | 200 |
| 204 | `GET` | [`/api/v1/supplier-payments/{id}/pdf`](#getsupplierpaymentsidpdf) | Printable payment voucher | `purchases.view` | 200 |
| 205 | `GET` | [`/api/v1/purchase-orders`](#getpurchaseorders) | List purchase orders | `purchases.view` | 200 |
| 206 | `POST` | [`/api/v1/purchase-orders`](#postpurchaseorders) | Create a purchase order | `purchases.create` | 201 |
| 207 | `POST` | [`/api/v1/purchase-orders/from-low-stock`](#postpurchaseordersfromlowstock) | Create draft POs grouped by preferred supplier from selected low-stock rows | `purchases.create` | 201 |
| 208 | `GET` | [`/api/v1/purchase-orders/{id}`](#getpurchaseordersid) | Get a purchase order | `purchases.view` | 200 |
| 209 | `PUT` | [`/api/v1/purchase-orders/{id}`](#putpurchaseordersid) | Replace a draft or sent order | `purchases.edit` | 200 |
| 210 | `POST` | [`/api/v1/purchase-orders/{id}/send`](#postpurchaseordersidsend) | Mark sent and e-mail it | `purchases.edit` | 200 |
| 211 | `POST` | [`/api/v1/purchase-orders/{id}/convert`](#postpurchaseordersidconvert) | Create a draft bill from remaining quantities | `purchases.create` | 201 |
| 212 | `POST` | [`/api/v1/purchase-orders/{id}/status`](#postpurchaseordersidstatus) | Close or cancel an order | `purchases.edit` | 200 |
| 213 | `DELETE` | [`/api/v1/purchase-orders/{id}`](#deletepurchaseordersid) | Delete a draft purchase order | `purchases.delete` | 204 |
| 214 | `GET` | [`/api/v1/purchase-orders/{id}/pdf`](#getpurchaseordersidpdf) | Printable purchase order (also attached when sent) | `purchases.view` | 200 |
| 215 | `GET` | [`/api/v1/purchase-returns`](#getpurchasereturns) | List purchase returns | `purchases.view` | 200 |
| 216 | `POST` | [`/api/v1/purchase-returns`](#postpurchasereturns) | Create a draft purchase return | `purchases.create` | 201 |
| 217 | `GET` | [`/api/v1/purchase-returns/{id}`](#getpurchasereturnsid) | Get a purchase return | `purchases.view` | 200 |
| 218 | `PUT` | [`/api/v1/purchase-returns/{id}`](#putpurchasereturnsid) | Replace a draft purchase return | `purchases.edit` | 200 |
| 219 | `POST` | [`/api/v1/purchase-returns/{id}/post`](#postpurchasereturnsidpost) | Post the return: stock out at bill cost and Dr supplier / Cr inventory; the debit note is created posted, sharing that journal entry, and applied per usage | `purchases.create` | 200 |
| 220 | `DELETE` | [`/api/v1/purchase-returns/{id}`](#deletepurchasereturnsid) | Delete a draft purchase return | `purchases.delete` | 204 |
| 221 | `POST` | [`/api/v1/purchase-returns/{id}/cancel`](#postpurchasereturnsidcancel) | Cancel a posted purchase return: stock back at the same cost, supplier credited, its debit note cancelled | `purchases.edit` | 200 |
| 222 | `GET` | [`/api/v1/debit-notes/{id}`](#getdebitnotesid) | Get a debit note | `purchases.view` | 200 |
| 223 | `PUT` | [`/api/v1/debit-notes/{id}`](#putdebitnotesid) | Edit a draft debit note | `purchases.edit` | 200 |
| 224 | `POST` | [`/api/v1/debit-notes`](#postdebitnotes) | Create a debit note without a return (price difference, quality claim) | `purchases.create` | 201 |
| 225 | `POST` | [`/api/v1/debit-notes/{id}/post`](#postdebitnotesidpost) | Post a debit note that was not created from a return (price difference, short supply, quality claim) and apply it per usage | `purchases.create` | 200 |
| 226 | `GET` | [`/api/v1/debit-notes`](#getdebitnotes) | List debit notes | `purchases.view` | 200 |
| 227 | `DELETE` | [`/api/v1/debit-notes/{id}`](#deletedebitnotesid) | Delete a draft debit note | `purchases.delete` | 204 |
| 228 | `POST` | [`/api/v1/debit-notes/{id}/cancel`](#postdebitnotesidcancel) | Cancel a posted debit note not created from a return (mirror entry; allocations reversed) | `purchases.edit` | 200 |
| 229 | `GET` | [`/api/v1/debit-notes/{id}/pdf`](#getdebitnotesidpdf) | Printable debit note | `purchases.view` | 200 |
| 230 | `GET` | [`/api/v1/banking/overview`](#getbankingoverview) | Accounts, combined balance series, quick stats, recent movements | `banking.view` | 200 |
| 231 | `GET` | [`/api/v1/money-accounts`](#getmoneyaccounts) | Cash, bank, wallet and card accounts with book balance and reconciliation status | any signed-in user who is an active member of the company | 200 |
| 232 | `POST` | [`/api/v1/money-accounts`](#postmoneyaccounts) | Add a bank, wallet or card account (ledger + opening balance) | `banking.create` | 201 |
| 233 | `GET` | [`/api/v1/money-accounts/{id}`](#getmoneyaccountsid) | Account details, statement imports, last cash count | `banking.view` | 200 |
| 234 | `PATCH` | [`/api/v1/money-accounts/{id}`](#patchmoneyaccountsid) | Edit account details and use-for switches | `banking.edit` | 200 |
| 235 | `GET` | [`/api/v1/money-accounts/{id}/book`](#getmoneyaccountsidbook) | Cash book or bank book with running balance | `banking.view` | 200 |
| 236 | `GET` | [`/api/v1/money-transactions`](#getmoneytransactions) | Cash and bank transactions across accounts | `banking.view` | 200 |
| 237 | `POST` | [`/api/v1/cash-counts`](#postcashcounts) | Record a cash count; a difference creates a draft adjustment awaiting approval | `banking.create` | 201 |
| 238 | `POST` | [`/api/v1/cash-counts/{id}/reverse`](#postcashcountsidreverse) | Reverse a posted cash-count adjustment (mirror entry) | `banking.approve` | 200 |
| 239 | `POST` | [`/api/v1/bank-statement-imports`](#postbankstatementimports) | Import a CSV/XLSX statement (multipart; column mapping) | `banking.reconcile` | 201 |
| 240 | `POST` | [`/api/v1/reconciliations`](#postreconciliations) | Start (or resume) a reconciliation for an account and statement date | `banking.reconcile` | 200 |
| 241 | `GET` | [`/api/v1/reconciliations/{id}`](#getreconciliationsid) | Reconciliation with statement lines, suggestions and difference | `banking.reconcile` | 200 |
| 242 | `POST` | [`/api/v1/reconciliations/{id}/lines/{lineId}/match`](#postreconciliationsidlineslineidmatch) | Match a statement line to a book line | `banking.reconcile` | 200 |
| 243 | `POST` | [`/api/v1/reconciliations/{id}/lines/{lineId}/create-entry`](#postreconciliationsidlineslineidcreateentry) | Create the missing receipt or bank-charge voucher and match it | `banking.reconcile` | 200 |
| 244 | `POST` | [`/api/v1/reconciliations/{id}/lines/pair`](#postreconciliationsidlinespair) | Mark two offsetting statement lines as a pair | `banking.reconcile` | 200 |
| 245 | `POST` | [`/api/v1/reconciliations/{id}/lines/{lineId}/undo`](#postreconciliationsidlineslineidundo) | Undo a match, pair or ignore | `banking.reconcile` | 200 |
| 246 | `POST` | [`/api/v1/reconciliations/{id}/accept-suggestions`](#postreconciliationsidacceptsuggestions) | Accept every same-amount, same-date suggestion | `banking.reconcile` | 200 |
| 247 | `POST` | [`/api/v1/reconciliations/{id}/finish`](#postreconciliationsidfinish) | Finish (all lines handled and difference zero) | `banking.reconcile` | 200 |
| 248 | `GET` | [`/api/v1/ie-categories`](#getiecategories) | Income or expense categories with ledger, budget and spent | `banking.view` | 200 |
| 249 | `POST` | [`/api/v1/ie-categories`](#postiecategories) | Create a category (new or existing ledger) | `banking.create` | 201 |
| 250 | `PATCH` | [`/api/v1/ie-categories/{id}`](#patchiecategoriesid) | Edit name, budget, warning | `banking.edit` | 200 |
| 251 | `GET` | [`/api/v1/ie-entries`](#getieentries) | Income or expense lines on category ledgers (any voucher) | `banking.view` | 200 |
| 252 | `POST` | [`/api/v1/ie-entries`](#postieentries) | Record an income or expense (creates INC/EXP voucher; optional recurrence) | `banking.create` | 201 |
| 253 | `GET` | [`/api/v1/recurring-templates`](#getrecurringtemplates) | Recurring entries | `banking.view` | 200 |
| 254 | `PATCH` | [`/api/v1/recurring-templates/{id}`](#patchrecurringtemplatesid) | Pause, edit or end a recurring entry | `banking.edit` | 200 |
| 255 | `GET` | [`/api/v1/income-expenses/overview`](#getincomeexpensesoverview) | Income or expense overview for the FY | `banking.view` | 200 |
| 256 | `GET` | [`/api/v1/accounting/overview`](#getaccountingoverview) | Totals and books-health checks | `accounting.view` | 200 |
| 257 | `GET` | [`/api/v1/account-groups`](#getaccountgroups) | Chart of accounts tree with balances | `accounting.view` | 200 |
| 258 | `POST` | [`/api/v1/account-groups`](#postaccountgroups) | Create a group | `accounting.create` | 201 |
| 259 | `GET` | [`/api/v1/account-groups/{id}`](#getaccountgroupsid) | Group details and children | `accounting.view` | 200 |
| 260 | `PATCH` | [`/api/v1/account-groups/{id}`](#patchaccountgroupsid) | Edit a group (name, parent within nature, order) | `accounting.edit` | 200 |
| 261 | `GET` | [`/api/v1/ledgers`](#getledgers) | Ledger list (balances only with accounting.view) | any signed-in user who is an active member of the company | 200 |
| 262 | `POST` | [`/api/v1/ledgers`](#postledgers) | Create a ledger (optional opening balance) | `accounting.create` | 201 |
| 263 | `GET` | [`/api/v1/ledgers/{id}`](#getledgersid) | Ledger details with FY summary | `accounting.view` | 200 |
| 264 | `PATCH` | [`/api/v1/ledgers/{id}`](#patchledgersid) | Edit a ledger (code and opening locked once used) | `accounting.edit` | 200 |
| 265 | `POST` | [`/api/v1/ledgers/{id}/deactivate`](#postledgersiddeactivate) | Make a ledger inactive (system-mapped ledgers refused) | `accounting.edit` | 200 |
| 266 | `GET` | [`/api/v1/ledgers/{id}/entries`](#getledgersidentries) | Ledger entries with running balance and monthly closings | `accounting.view` | 200 |
| 267 | `GET` | [`/api/v1/vouchers`](#getvouchers) | Voucher list / day book | `accounting.view` | 200 |
| 268 | `POST` | [`/api/v1/vouchers`](#postvouchers) | Create a journal, payment, receipt or contra voucher (draft or post immediately) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | 201 |
| 269 | `GET` | [`/api/v1/vouchers/{id}`](#getvouchersid) | Voucher with lines, allocations, payment details, audit trail, linked documents | `accounting.view` | 200 |
| 270 | `PUT` | [`/api/v1/vouchers/{id}`](#putvouchersid) | Replace a draft or rejected voucher | contextual — entry_type = journal → `accounting.edit`; entry_type in (payment, receipt, contra) → `banking.edit or accounting.edit` | 200 |
| 271 | `DELETE` | [`/api/v1/vouchers/{id}`](#deletevouchersid) | Delete a draft voucher (posted vouchers cannot be deleted) | `accounting.delete` | 204 |
| 272 | `POST` | [`/api/v1/vouchers/{id}/submit`](#postvouchersidsubmit) | Submit a draft for approval | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | 200 |
| 273 | `POST` | [`/api/v1/vouchers/{id}/post`](#postvouchersidpost) | Post a draft (or approved) voucher | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | 200 |
| 274 | `POST` | [`/api/v1/vouchers/{id}/reverse`](#postvouchersidreverse) | Create a linked reversing entry | `accounting.create` | 201 |
| 275 | `POST` | [`/api/v1/vouchers/calculate`](#postvoucherscalculate) | Authoritative balance and double-entry preview for an unsaved voucher (no write) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | 200 |
| 276 | `GET` | [`/api/v1/vouchers/{id}/pdf`](#getvouchersidpdf) | Printable voucher (A4) | `accounting.view` | 200 |
| 277 | `GET` | [`/api/v1/voucher-templates`](#getvouchertemplates) | Journal templates (depreciation, accruals, reclassify) | `accounting.view` | 200 |
| 278 | `GET` | [`/api/v1/lc/overview`](#getlcoverview) | LC KPIs, alerts, deadlines, by bank, pipeline, active, recent | `lc.view` | 200 |
| 279 | `GET` | [`/api/v1/letters-of-credit`](#getlettersofcredit) | List LCs | `lc.view` | 200 |
| 280 | `POST` | [`/api/v1/letters-of-credit`](#postlettersofcredit) | Create a draft LC (documents copied from template) | `lc.create` | 201 |
| 281 | `GET` | [`/api/v1/letters-of-credit/{id}`](#getlettersofcreditid) | LC with milestones, terms, documents, shipments, charges, payments, amendments, money position | `lc.view` | 200 |
| 282 | `PUT` | [`/api/v1/letters-of-credit/{id}`](#putlettersofcreditid) | Update a draft or awaiting-bank LC (opened LCs change only through amendments) | `lc.edit` | 200 |
| 283 | `DELETE` | [`/api/v1/letters-of-credit/{id}`](#deletelettersofcreditid) | Delete a draft LC | `lc.delete` | 204 |
| 284 | `POST` | [`/api/v1/letters-of-credit/{id}/transition`](#postlettersofcreditidtransition) | Change status: submit, mark_opened, mark_refused, documents_presented, cancel, expire, reopen | `lc.edit` | 200 |
| 285 | `POST` | [`/api/v1/letters-of-credit/{id}/close`](#postlettersofcreditidclose) | Close after checks (or with override reason); margin release posted | `lc.edit` | 200 |
| 286 | `GET` | [`/api/v1/letters-of-credit/{id}/documents`](#getlettersofcreditiddocuments) | Documents of an LC | `lc.view` | 200 |
| 287 | `POST` | [`/api/v1/letters-of-credit/{id}/documents`](#postlettersofcreditiddocuments) | Add a required document | `lc.edit` | 201 |
| 288 | `PATCH` | [`/api/v1/lc-documents/{id}`](#patchlcdocumentsid) | Update document status, dates, discrepancy note | `lc.edit` | 200 |
| 289 | `GET` | [`/api/v1/lc-documents/matrix`](#getlcdocumentsmatrix) | Document status matrix across open LCs and follow-up list | `lc.view` | 200 |
| 290 | `GET` | [`/api/v1/lc-document-templates`](#getlcdocumenttemplates) | Checklist templates with items | `lc.view` | 200 |
| 291 | `PUT` | [`/api/v1/lc-document-templates/{id}`](#putlcdocumenttemplatesid) | Replace a template's items | `lc.edit` | 200 |
| 292 | `POST` | [`/api/v1/lc-document-templates`](#postlcdocumenttemplates) | Create a template | `lc.create` | 201 |
| 293 | `GET` | [`/api/v1/lc-amendments`](#getlcamendments) | All amendments (filter pending/accepted) | `lc.view` | 200 |
| 294 | `POST` | [`/api/v1/letters-of-credit/{id}/amendments`](#postlettersofcreditidamendments) | Record an amendment with field changes | `lc.edit` | 201 |
| 295 | `PATCH` | [`/api/v1/lc-amendments/{id}`](#patchlcamendmentsid) | Advance amendment status; on accepted the LC is updated | `lc.edit` | 200 |
| 296 | `POST` | [`/api/v1/letters-of-credit/{id}/shipments`](#postlettersofcreditidshipments) | Add a shipment lot | `lc.edit` | 201 |
| 297 | `PATCH` | [`/api/v1/lc-shipments/{id}`](#patchlcshipmentsid) | Update a lot (status, B/L, vessel, ETD/ETA, arrived) | `lc.edit` | 200 |
| 298 | `GET` | [`/api/v1/lc-charges`](#getlccharges) | Charges across LCs (filter by LC and paid status) | `lc.view` | 200 |
| 299 | `POST` | [`/api/v1/lc-charges`](#postlccharges) | Add a charge or import expense (posts accrual or payment) | `lc.create` | 201 |
| 300 | `POST` | [`/api/v1/lc-charges/{id}/reverse`](#postlcchargesidreverse) | Reverse an LC charge (refused once included in a posted landed-cost allocation) | `lc.edit` | 200 |
| 301 | `POST` | [`/api/v1/lc-charges/{id}/pay`](#postlcchargesidpay) | Pay an unpaid charge | `lc.create` | 200 |
| 302 | `POST` | [`/api/v1/letters-of-credit/{id}/payments`](#postlettersofcreditidpayments) | Record a payment to the beneficiary at the bank's rate (applies margin, FX) | `lc.create` | 201 |
| 303 | `GET` | [`/api/v1/letters-of-credit/{id}/payments`](#getlettersofcreditidpayments) | Payments of an LC with bank rate, margin applied and FX difference | `lc.view` | 200 |
| 304 | `POST` | [`/api/v1/lc-payments/{id}/reverse`](#postlcpaymentsidreverse) | Reverse an LC payment (restores margin and supplier balance; mirror entry) | `lc.edit` | 200 |
| 305 | `POST` | [`/api/v1/letters-of-credit/{id}/landed-cost/preview`](#postlettersofcreditidlandedcostpreview) | Preview allocation of capitalised charges to an import bill's lines | `lc.view` | 200 |
| 306 | `POST` | [`/api/v1/letters-of-credit/{id}/landed-cost`](#postlettersofcreditidlandedcost) | Post a landed-cost allocation (inventory and COGS split) | `lc.create` | 201 |
| 307 | `POST` | [`/api/v1/landed-cost-allocations/{id}/reverse`](#postlandedcostallocationsidreverse) | Reverse a landed-cost allocation (inventory/COGS split and landed_cost movements) | `lc.edit` | 200 |
| 308 | `GET` | [`/api/v1/letters-of-credit/{id}/pdf`](#getlettersofcreditidpdf) | Printable LC summary | `lc.view` | 200 |
| 309 | `GET` | [`/api/v1/letters-of-credit/{id}/cost-summary`](#getlettersofcreditidcostsummary) | Landed cost breakdown and per-unit cost | `lc.view` | 200 |

## 8. Contract validation

`python3 docs/source/validate_api.py` checks the generated `openapi.yaml` and the definitions behind it. The results below come from the last run and are written by the script; it exits non-zero on any failure.

<!-- VALIDATION:start -->
Last run: 24 rules, **24 passed, 0 failed**; 6 negative tests, 6 detected. Official schema: OpenAPI 3.1 `schema-base` (OAI/OpenAPI-Specification, v3.1-dev).

| Rule | Check | Items | Result |
|---|---|---|---|
| A01 | Document validates against the official OpenAPI 3.1 schema (schema-base: documents incl. Schema Objects) | 1 | ✅ pass |
| A02 | Every component schema is valid JSON Schema 2020-12 (meta-schema check) | 197 | ✅ pass |
| A03 | All $refs resolve and every component schema is used | 200 | ✅ pass |
| A04 | Operations have unique camelCase operationIds, summary, description, tags, a 2xx, a default, and problem+json errors | 309 | ✅ pass |
| A05 | Path templates and declared path parameters match; path parameters are required | 309 | ✅ pass |
| A06 | Security: public only for sign-in flows, cookie only for refresh/logout, X-Company-Id on company-scoped calls, known permissions | 309 | ✅ pass |
| A07 | Every operation that posts a journal entry takes an Idempotency-Key and documents its locking; every body can be rejected with 422 | 309 | ✅ pass |
| A08 | Every 201 response declares a Location header | 49 | ✅ pass |
| A09 | Collections use the paginated envelope {data[], meta} | 47 | ✅ pass |
| A10 | Every error code exists in the catalogue, has its HTTP status documented on the operation, and every catalogue code is reachable | 70 | ✅ pass |
| A11 | Request-only schemas reject unknown properties (additionalProperties: false) | 90 | ✅ pass |
| A12 | Money, quantity, cost and rate fields are decimal strings (never JSON numbers) | 202 | ✅ pass |
| A13 | PUT/PATCH on a versioned table requires If-Match, and If-Match is only required where a version exists | 39 | ✅ pass |
| A14 | Request fields are stored: each maps to a column (or is declared virtual) with a compatible length, enum values and nullability | 377 | ✅ pass |
| A15 | Response enum fields have exactly the values the database allows | 29 | ✅ pass |
| A16 | Every action of every page maps to API calls, a dialog, navigation or explicit client behaviour, and every target exists | 522 | ✅ pass |
| A17 | Every data-changing endpoint is triggered by at least one page action (only token refresh is system-driven) | 176 | ✅ pass |
| A18 | Every page has at least one endpoint (shared components use their host page's) | 162 | ✅ pass |
| A19 | For every action, at least one role can both open the page and call the endpoint | 322 | ✅ pass |
| A20 | Database operations name real tables; nothing deletes posted or append-only rows; every write changes something | 1283 | ✅ pass |
| A21 | Every parameter has a typed schema | 522 | ✅ pass |
| A22 | Business rules exist; 43 rejecting rules map to error codes | 346 | ✅ pass |
| A23 | Calculate/preview endpoints never reject on business state; they return warnings[] (BR-015) | 4 | ✅ pass |
| A24 | Ledger/stock writes are coherent: entries have lines, stock movements update balances, system entries take an SJ number, bulk post = single post | 84 | ✅ pass |

**Negative tests** (a deliberately broken copy of the document must fail the named rule):

| Broken copy | Detected |
|---|---|
| Response without description (invalid OpenAPI) | ✅ |
| Request schema accepting unknown fields | ✅ |
| Error code enum out of sync with the catalogue | ✅ |
| 201 without Location | ✅ |
| Money as a JSON number | ✅ |
| Bulk post that only reads the ledger tables | ✅ |

**Not covered offline:** behaviour of the real Laravel implementation. That is checked later by contract tests that validate live responses against `openapi.yaml` (09, TC-CON-*).
<!-- VALIDATION:end -->

## 9. Endpoint reference


### Auth


<a id="postauthlogin"></a>
#### `POST /api/v1/auth/login`

Sign in with e-mail and password. Returns an access token, or a two-factor challenge when 2FA is on. Sets the refresh cookie `pl_rt` (HttpOnly, Secure, SameSite=Strict, Path=/api/v1/auth). Throttled per (e-mail, source IP): after 5 failures in 15 minutes that source gets 429 with Retry-After; wrong credentials and locked accounts both answer 401 `invalid_credentials` (no account enumeration, R-12).

| | |
|---|---|
| **Auth** | None (public, rate-limited) |
| **Permission** | public |
| **Path / query params** | — / — |
| **Request body** | [`LoginRequest`](#schema-loginrequest) |
| **Validation** | `email`* e-mail; `password`* string ≤ 200; `device_label` string ≤ 120 (nullable)<br>Unknown fields are rejected (422).<br>BR-061: Deactivating a user or changing their password revokes all their sessions immediately. |
| **Success** | 200 `data`: [`LoginResponse`](#schema-loginresponse) |
| **Status codes** | 200, 400, 401, 422, 429 |
| **Error codes** | **`invalid_credentials`**; standard: `malformed_json`, `rate_limited`, `server_error`, `validation_failed` |
| **Headers** | — |
| **Database** | insert: audit_logs, user_sessions; update: users; read: company_users |
| **Business rules** | BR-061 |
| **Requirements** | FR-001 |
| **Pages and actions** | S1<br>S1 · Sign in |
| **Transaction / concurrency** | Single transaction. |

<a id="postauthtwofactor"></a>
#### `POST /api/v1/auth/two-factor`

Complete sign-in with a TOTP or recovery code.

| | |
|---|---|
| **Auth** | None (public, rate-limited) |
| **Permission** | public |
| **Path / query params** | — / — |
| **Request body** | [`TwoFactorRequest`](#schema-twofactorrequest) |
| **Validation** | `challenge_token`* string; `code` string ≤ 6 (nullable); `recovery_code` string ≤ 20 (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`LoginResponse`](#schema-loginresponse) |
| **Status codes** | 200, 400, 401, 422, 429 |
| **Error codes** | **`two_factor_failed`**; standard: `malformed_json`, `rate_limited`, `server_error`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: users; insert: audit_logs; update: users; read: user_sessions |
| **Business rules** | — |
| **Requirements** | FR-001 |
| **Pages and actions** | S1<br>S1 · Use a recovery code |
| **Transaction / concurrency** | Single transaction. |

<a id="postauthrefresh"></a>
#### `POST /api/v1/auth/refresh`

Rotate the refresh cookie and issue a new access token.

| | |
|---|---|
| **Auth** | Refresh cookie `pl_rt` |
| **Permission** | public (refresh cookie) |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`TokenResponse`](#schema-tokenresponse) |
| **Status codes** | 200, 401, 429 |
| **Error codes** | **`refresh_reuse_detected`, `refresh_token_invalid`**; standard: `rate_limited`, `server_error` |
| **Headers** | — |
| **Database** | lock: user_sessions; insert: user_sessions; update: user_sessions |
| **Business rules** | — |
| **Requirements** | FR-001 |
| **Pages and actions** | system |
| **Transaction / concurrency** | Locks the session row; a reused (already rotated) token revokes the whole family. |

<a id="postauthlogout"></a>
#### `POST /api/v1/auth/logout`

Sign out this session (revokes refresh token, clears cookie). Authenticated by the refresh cookie alone, so it works after the access token has expired (R-36). Always 204; clears the cookie.

| | |
|---|---|
| **Auth** | Refresh cookie `pl_rt` |
| **Permission** | public (refresh cookie) |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 429 |
| **Error codes** | standard: `rate_limited`, `server_error` |
| **Headers** | — |
| **Database** | insert: audit_logs; update: user_sessions |
| **Business rules** | — |
| **Requirements** | FR-002 |
| **Pages and actions** | S0<br>S0 · Profile menu: profile, security, sign out |
| **Transaction / concurrency** | Single transaction. |

<a id="postauthpasswordforgot"></a>
#### `POST /api/v1/auth/password/forgot`

Send a password-reset link. Always 202 to avoid account enumeration.

| | |
|---|---|
| **Auth** | None (public, rate-limited) |
| **Permission** | public |
| **Path / query params** | — / — |
| **Request body** | [`ForgotPasswordRequest`](#schema-forgotpasswordrequest) |
| **Validation** | `email`* e-mail<br>Unknown fields are rejected (422). |
| **Success** | 202 — · 202 when above the approval limit |
| **Status codes** | 202, 400, 422, 429 |
| **Error codes** | standard: `malformed_json`, `rate_limited`, `server_error`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: users; update: users; read: password_reset_tokens |
| **Business rules** | — |
| **Requirements** | FR-003 |
| **Pages and actions** | S2<br>S2 · Send link |
| **Transaction / concurrency** | Single transaction. |

<a id="postauthpasswordreset"></a>
#### `POST /api/v1/auth/password/reset`

Set a new password with a reset token.

| | |
|---|---|
| **Auth** | None (public, rate-limited) |
| **Permission** | public |
| **Path / query params** | — / — |
| **Request body** | [`ResetPasswordRequest`](#schema-resetpasswordrequest) |
| **Validation** | `token`* string; `email`* e-mail; `password`* string ≤ 200; `password_confirmation`* string ≤ 200<br>Unknown fields are rejected (422).<br>BR-061: Deactivating a user or changing their password revokes all their sessions immediately. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 422, 429 |
| **Error codes** | standard: `malformed_json`, `rate_limited`, `server_error`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: users; update: users; read: password_reset_tokens, user_sessions |
| **Business rules** | BR-061 |
| **Requirements** | FR-003 |
| **Pages and actions** | S2<br>S2 · Set password |
| **Transaction / concurrency** | Single transaction. |

<a id="getinvitationstoken"></a>
#### `GET /api/v1/invitations/{token}`

Show an invitation (company, role, e-mail).

| | |
|---|---|
| **Auth** | None (public, rate-limited) |
| **Permission** | public |
| **Path / query params** | `token` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`InvitationPublic`](#schema-invitationpublic) |
| **Status codes** | 200, 404, 410, 429 |
| **Error codes** | **`gone`**; standard: `rate_limited`, `server_error` |
| **Headers** | — |
| **Database** | read: companies, roles, user_invitations |
| **Business rules** | — |
| **Requirements** | FR-006 |
| **Pages and actions** | S3 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postinvitationstokenaccept"></a>
#### `POST /api/v1/invitations/{token}/accept`

Accept an invitation (creates or links the user).

| | |
|---|---|
| **Auth** | None (public, rate-limited) |
| **Permission** | public |
| **Path / query params** | `token` / — |
| **Request body** | [`AcceptInvitationRequest`](#schema-acceptinvitationrequest) |
| **Validation** | `name` string ≤ 120 (nullable); `password` string ≤ 200 (nullable); `password_confirmation` string ≤ 200 (nullable)<br>Unknown fields are rejected (422).<br>BR-059: Active plus invited users cannot exceed the company's seat limit. |
| **Success** | 200 `data`: [`LoginResponse`](#schema-loginresponse) |
| **Status codes** | 200, 400, 404, 409, 410, 422, 429 |
| **Error codes** | **`gone`, `seat_limit_reached`**; standard: `malformed_json`, `not_found`, `rate_limited`, `server_error`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: user_invitations; insert: audit_logs, company_user_branches, company_users, users; update: user_invitations; read: user_invitation_branches |
| **Business rules** | BR-059 |
| **Requirements** | FR-006 |
| **Pages and actions** | S3<br>S3 · Join company |
| **Transaction / concurrency** | Locks the invitation; seat limit re-checked. |

### Me


<a id="getme"></a>
#### `GET /api/v1/me`

Current user, memberships and effective permissions in the active company. The X-Company-Id header is optional here; without it, permissions are omitted.

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Me`](#schema-me) |
| **Status codes** | 200, 401 |
| **Error codes** | standard: `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: company_users, permissions, role_permissions, roles, users |
| **Business rules** | — |
| **Requirements** | FR-005 |
| **Pages and actions** | S0<br>S0 · Switch company (reloads all data)<br>S0 · Create a company (NEW, company switcher) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putmepassword"></a>
#### `PUT /api/v1/me/password`

Change own password (revokes other sessions).

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | [`ChangePasswordRequest`](#schema-changepasswordrequest) |
| **Validation** | `current_password`* string; `password`* string ≤ 200; `password_confirmation`* string ≤ 200<br>Unknown fields are rejected (422).<br>BR-061: Deactivating a user or changing their password revokes all their sessions immediately. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 422 |
| **Error codes** | standard: `malformed_json`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | insert: audit_logs; update: users; read: user_sessions |
| **Business rules** | BR-061 |
| **Requirements** | FR-086 |
| **Pages and actions** | K14<br>K14 · Change password |
| **Transaction / concurrency** | Single transaction. |

<a id="postmetwofactor"></a>
#### `POST /api/v1/me/two-factor`

Start 2FA set-up (returns otpauth URI and QR). Replaces an unconfirmed set-up. Refused with 409 `invalid_transition` while 2-step is on (turn it off first).

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`TwoFactorSetup`](#schema-twofactorsetup) |
| **Status codes** | 200, 401, 409 |
| **Error codes** | **`invalid_transition`**; standard: `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: users; insert: audit_logs; update: users |
| **Business rules** | — |
| **Requirements** | FR-004 |
| **Pages and actions** | K14<br>K14 · Enable/disable 2-step |
| **Transaction / concurrency** | Single transaction. |

<a id="postmetwofactorconfirm"></a>
#### `POST /api/v1/me/two-factor/confirm`

Confirm 2FA with a code; returns recovery codes once.

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | [`TwoFactorConfirm`](#schema-twofactorconfirm) |
| **Validation** | `code`* string ≤ 6<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`RecoveryCodes`](#schema-recoverycodes) |
| **Status codes** | 201, 400, 401, 409, 422, 429 |
| **Error codes** | **`invalid_transition`, `two_factor_failed`**; standard: `malformed_json`, `rate_limited`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, users |
| **Business rules** | — |
| **Requirements** | FR-004 |
| **Pages and actions** | K14<br>K14 · Enable/disable 2-step |
| **Transaction / concurrency** | Single transaction. |

<a id="deletemetwofactor"></a>
#### `DELETE /api/v1/me/two-factor`

Turn off 2FA (requires password; blocked if the company requires 2FA).

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | [`PasswordConfirm`](#schema-passwordconfirm) |
| **Validation** | `password`* string<br>Unknown fields are rejected (422). |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 422 |
| **Error codes** | **`invalid_credentials`, `two_factor_required_by_company`**; standard: `malformed_json`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: users |
| **Business rules** | — |
| **Requirements** | FR-004 |
| **Pages and actions** | K14<br>K14 · Enable/disable 2-step |
| **Transaction / concurrency** | Single transaction. |

<a id="getmesessions"></a>
#### `GET /api/v1/me/sessions`

List own signed-in sessions.

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`Session`](#schema-session) + `meta` |
| **Status codes** | 200, 401 |
| **Error codes** | standard: `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: user_sessions |
| **Business rules** | — |
| **Requirements** | FR-009 |
| **Pages and actions** | K14 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="deletemesessionsid"></a>
#### `DELETE /api/v1/me/sessions/{id}`

Revoke one session.

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 401, 404 |
| **Error codes** | standard: `not_found`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: user_sessions |
| **Business rules** | — |
| **Requirements** | FR-009 |
| **Pages and actions** | K14<br>K14 · Sign out (session) |
| **Transaction / concurrency** | Single transaction. |

<a id="postmesessionsrevokeothers"></a>
#### `POST /api/v1/me/sessions/revoke-others`

Sign out everywhere else.

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 401 |
| **Error codes** | standard: `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: user_sessions; insert: audit_logs; update: user_sessions |
| **Business rules** | — |
| **Requirements** | FR-002 |
| **Pages and actions** | K14<br>K14 · Sign out everywhere else |
| **Transaction / concurrency** | Single transaction. |

<a id="getmepreferences"></a>
#### `GET /api/v1/me/preferences`

Get appearance preferences.

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`UserPreferences`](#schema-userpreferences) |
| **Status codes** | 200, 401 |
| **Error codes** | standard: `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: user_preferences |
| **Business rules** | — |
| **Requirements** | FR-086 |
| **Pages and actions** | K13 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putmepreferences"></a>
#### `PUT /api/v1/me/preferences`

Save appearance preferences.

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | [`UserPreferences`](#schema-userpreferences) |
| **Validation** | `theme` one of light/dark/system; `density` one of default/compact; `text_size` one of s/m/l; `reduce_motion` boolean; `sidebar_collapsed` boolean<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`UserPreferences`](#schema-userpreferences) |
| **Status codes** | 200, 400, 401, 422 |
| **Error codes** | standard: `malformed_json`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | update: user_preferences |
| **Business rules** | — |
| **Requirements** | FR-086 |
| **Pages and actions** | K13<br>K13 · Save changes |
| **Transaction / concurrency** | Single transaction. |

<a id="getmenotificationpreferences"></a>
#### `GET /api/v1/me/notification-preferences`

Get own notification switches for the active company.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`NotificationPreferences`](#schema-notificationpreferences) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: notification_preferences |
| **Business rules** | — |
| **Requirements** | FR-013 |
| **Pages and actions** | K12 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putmenotificationpreferences"></a>
#### `PUT /api/v1/me/notification-preferences`

Save own notification switches.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / — |
| **Request body** | [`NotificationPreferences`](#schema-notificationpreferences) |
| **Validation** | `events`* array of NotificationPreference; `daily_summary_time` string (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`NotificationPreferences`](#schema-notificationpreferences) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | update: notification_preferences |
| **Business rules** | — |
| **Requirements** | FR-013 |
| **Pages and actions** | K12<br>K12 · Save changes |
| **Transaction / concurrency** | Single transaction. |

### Company


<a id="postcompanies"></a>
#### `POST /api/v1/companies`

Create a company (the creator becomes Owner; chart of accounts seeded).

| | |
|---|---|
| **Auth** | Bearer JWT |
| **Permission** | any signed-in user |
| **Path / query params** | — / — |
| **Request body** | [`CompanyCreate`](#schema-companycreate) |
| **Validation** | `legal_name`* string ≤ 190; `trading_name` string ≤ 190 (nullable); `business_type`* one of private_limited/sole_proprietorship/partnership/public_limited; `fy_start_month` integer; `base_currency_code` ISO 4217<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Company`](#schema-company) |
| **Status codes** | 201, 400, 401, 409, 422 |
| **Error codes** | standard: `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, companies, idempotency_keys; read: account_groups, account_mappings, accounting_periods, branches, company_settings, company_users, fiscal_years, ledgers, number_sequences, payment_terms, role_permissions, roles, warehouses |
| **Business rules** | — |
| **Requirements** | FR-005 |
| **Pages and actions** | S0<br>S0 · Create a company (NEW, company switcher) |
| **Transaction / concurrency** | CompanyProvisioner runs in one transaction (04 section 11.2). |

<a id="getcompany"></a>
#### `GET /api/v1/company`

Active company profile and locations.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.view` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Company`](#schema-company) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: branches, companies |
| **Business rules** | — |
| **Requirements** | FR-080 |
| **Pages and actions** | K1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchcompany"></a>
#### `PATCH /api/v1/company`

Edit company identity and contact.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | [`CompanyUpdate`](#schema-companyupdate) |
| **Validation** | `legal_name` string ≤ 190; `trading_name` string ≤ 190 (nullable); `business_type` one of private_limited/sole_proprietorship/partnership/public_limited; `trade_licence_no` string ≤ 60 (nullable); `tax_registration_no` string ≤ 40 (nullable); `incorporated_on` YYYY-MM-DD (nullable); `registered_address` string ≤ 255 (nullable); `phone` string ≤ 30 (nullable); `email` e-mail (nullable); `website` string ≤ 190 (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Company`](#schema-company) |
| **Status codes** | 200, 400, 401, 403, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: companies |
| **Business rules** | — |
| **Requirements** | FR-080 |
| **Pages and actions** | K2<br>K2 · Save changes |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="putcompanylogo"></a>
#### `PUT /api/v1/company/logo`

Upload or replace the logo (PNG/SVG).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | [`FileUpload`](#schema-fileupload) |
| **Validation** | `file`* string<br>Unknown fields are rejected (422).<br>BR-051: Attachments must be JPG, PNG or PDF up to 10 MB, verified by content type sniffing. |
| **Success** | 200 `data`: [`Company`](#schema-company) |
| **Status codes** | 200, 400, 401, 403, 409, 413, 415, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `file_too_large`, `file_type_not_allowed`**; standard: `company_required`, `if_match_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: companies |
| **Business rules** | BR-051 |
| **Requirements** | FR-080 |
| **Pages and actions** | K2<br>K2 · Replace logo |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deletecompanylogo"></a>
#### `DELETE /api/v1/company/logo`

Remove the logo.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: companies |
| **Business rules** | — |
| **Requirements** | FR-080 |
| **Pages and actions** | K2<br>K2 · Remove logo |
| **Transaction / concurrency** | Single transaction. |

<a id="getcompanysettings"></a>
#### `GET /api/v1/company/settings`

Preferences, controls, currency display, tax display, invoice design, security and backup settings. Readable by every member (formatting depends on it); fields restricted by permission are omitted.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`CompanySettings`](#schema-companysettings) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: company_settings |
| **Business rules** | — |
| **Requirements** | FR-081, FR-083, FR-085 |
| **Pages and actions** | K3, K5, K6, K7, K14, K15, S0<br>S0 · Switch company (reloads all data) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchcompanysettings"></a>
#### `PATCH /api/v1/company/settings`

Update settings (partial). `require_two_factor` and backup fields additionally need Owner. Base currency cannot change (BR-035).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | [`CompanySettingsUpdate`](#schema-companysettingsupdate) |
| **Validation** | `valuation_method` one of weighted_average/fifo; `invoice_rounding` one of none/nearest_1/nearest_5; `allow_negative_stock` boolean; `require_approval` boolean; `backdate_warning_days` integer; `prices_include_tax` boolean; `show_tax_no_on_invoice` boolean; `default_warehouse_id` id of a record in this company (nullable); `default_payment_term_id` id of a record in this company (nullable); `symbol_position` one of before/after; `table_decimals` integer; `negative_format` one of parentheses/minus; `number_grouping` one of lakh/million; `date_format` string ≤ 20; `week_start` one of saturday/sunday/monday; `print_language` one of en/bn/both; `invoice` object; `require_two_factor` boolean; `idle_timeout_minutes` integer (nullable); `backup_enabled` boolean; `backup_retention_days` integer<br>Unknown fields are rejected (422).<br>BR-035: The base currency cannot change once any journal entry exists. |
| **Success** | 200 `data`: [`CompanySettings`](#schema-companysettings) |
| **Status codes** | 200, 400, 401, 403, 409, 422, 428, 503 |
| **Error codes** | **`base_currency_locked`, `company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: company_settings; read: journal_entries |
| **Business rules** | BR-035 |
| **Requirements** | FR-081, FR-083, FR-084, FR-085 |
| **Pages and actions** | K3, K5, K6, K7, K14, K15<br>K3 · Save changes<br>K5 · Save changes<br>K6 · Save<br>K7 · Save changes |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getbranches"></a>
#### `GET /api/v1/branches`

List locations (pickers and report filters).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Branch`](#schema-branch) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: branches |
| **Business rules** | — |
| **Requirements** | FR-080 |
| **Pages and actions** | K1, S0 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postbranches"></a>
#### `POST /api/v1/branches`

Add a location.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | [`BranchInput`](#schema-branchinput) |
| **Validation** | `code`* string ≤ 20; `name`* string ≤ 120; `kind` string ≤ 120 (nullable); `address` string ≤ 255 (nullable); `phone` string ≤ 30 (nullable); `is_head_office` boolean; `is_active` boolean<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Branch`](#schema-branch) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, branches, idempotency_keys |
| **Business rules** | — |
| **Requirements** | FR-080 |
| **Pages and actions** | K1<br>K1 · Manage locations (NEW, GAP-14) |
| **Transaction / concurrency** | Single transaction. |

<a id="patchbranchesid"></a>
#### `PATCH /api/v1/branches/{id}`

Edit a location.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | `id` / — |
| **Request body** | [`BranchInput`](#schema-branchinput) |
| **Validation** | `code`* string ≤ 20; `name`* string ≤ 120; `kind` string ≤ 120 (nullable); `address` string ≤ 255 (nullable); `phone` string ≤ 30 (nullable); `is_head_office` boolean; `is_active` boolean<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Branch`](#schema-branch) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: branches |
| **Business rules** | — |
| **Requirements** | FR-080 |
| **Pages and actions** | K1<br>K1 · Manage locations (NEW, GAP-14) |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getpaymentterms"></a>
#### `GET /api/v1/payment-terms`

List payment terms.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`PaymentTerm`](#schema-paymentterm) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: payment_terms |
| **Business rules** | — |
| **Requirements** | FR-081 |
| **Pages and actions** | B2, C2, E3, F3, K3 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getnumbersequences"></a>
#### `GET /api/v1/number-sequences`

Document numbering series for the current FY.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.view` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`NumberSequence`](#schema-numbersequence) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: number_sequences |
| **Business rules** | — |
| **Requirements** | FR-081 |
| **Pages and actions** | K3 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchnumbersequencesid"></a>
#### `PATCH /api/v1/number-sequences/{id}`

Change prefix, padding or next number (never below the last used).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | `id` / — |
| **Request body** | [`NumberSequenceUpdate`](#schema-numbersequenceupdate) |
| **Validation** | `prefix` string ≤ 20; `next_number` integer; `padding` integer<br>Unknown fields are rejected (422).<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 200 `data`: [`NumberSequence`](#schema-numbersequence) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: number_sequences |
| **Business rules** | BR-006 |
| **Requirements** | FR-081 |
| **Pages and actions** | K3<br>K3 · Save changes |
| **Transaction / concurrency** | Locks the sequence row. |

<a id="gettaxrules"></a>
#### `GET /api/v1/tax-rules`

List tax rules.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`TaxRule`](#schema-taxrule) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: ledgers, tax_rules |
| **Business rules** | — |
| **Requirements** | FR-084 |
| **Pages and actions** | K6, D2, E3, F3, I6 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="posttaxrules"></a>
#### `POST /api/v1/tax-rules`

Add a tax rule (user-entered rate).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | [`TaxRuleInput`](#schema-taxruleinput) |
| **Validation** | `name`* string ≤ 80; `rate`* decimal string 0–100, 4 dp; `applies_to`* one of sales/purchases/both; `ledger_id` id of a record in this company (nullable); `create_ledger` boolean; `is_recoverable` boolean; `is_active` boolean<br>Unknown fields are rejected (422).<br>BR-036: No tax rate is assumed: tax is calculated only from user-defined tax rules attached to a line, and posts to the rule's ledger. |
| **Success** | 201 `data`: [`TaxRule`](#schema-taxrule) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, tax_rules; read: ledgers |
| **Business rules** | BR-036 |
| **Requirements** | FR-084 |
| **Pages and actions** | K6<br>K6 · Add tax rule |
| **Transaction / concurrency** | Single transaction. |

<a id="patchtaxrulesid"></a>
#### `PATCH /api/v1/tax-rules/{id}`

Edit or deactivate a tax rule (rate changes apply to new documents only).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | `id` / — |
| **Request body** | [`TaxRuleInput`](#schema-taxruleinput) |
| **Validation** | `name`* string ≤ 80; `rate`* decimal string 0–100, 4 dp; `applies_to`* one of sales/purchases/both; `ledger_id` id of a record in this company (nullable); `create_ledger` boolean; `is_recoverable` boolean; `is_active` boolean<br>Unknown fields are rejected (422).<br>BR-036: No tax rate is assumed: tax is calculated only from user-defined tax rules attached to a line, and posts to the rule's ledger. |
| **Success** | 200 `data`: [`TaxRule`](#schema-taxrule) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: tax_rules |
| **Business rules** | BR-036 |
| **Requirements** | FR-084 |
| **Pages and actions** | K6<br>K6 · Edit / deactivate rule |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getaccountmappings"></a>
#### `GET /api/v1/account-mappings`

Posting-rule ledger mappings.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`AccountMappings`](#schema-accountmappings) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: account_groups, account_mappings, ledgers |
| **Business rules** | — |
| **Requirements** | FR-075 |
| **Pages and actions** | K16 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putaccountmappings"></a>
#### `PUT /api/v1/account-mappings`

Save mappings (nature-checked per key).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.edit` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | [`AccountMappings`](#schema-accountmappings) |
| **Validation** | `mappings`* array of AccountMapping<br>Unknown fields are rejected (422).<br>BR-064: Posting a sales invoice creates: Dr customer (total), Cr each line's income ledger (net), Cr tax ledgers (tax), Cr/Dr rounding; and for stocked lines Dr COGS, Cr warehouse inventory (cost). |
| **Success** | 200 `data`: [`AccountMappings`](#schema-accountmappings) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `mapping_missing`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | insert: audit_logs; update: account_mappings |
| **Business rules** | BR-064 |
| **Requirements** | FR-075 |
| **Pages and actions** | K16<br>K16 · Save changes |
| **Transaction / concurrency** | Single transaction. |

### Fiscal years


<a id="getfiscalyears"></a>
#### `GET /api/v1/fiscal-years`

Financial years with period status.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`FiscalYear`](#schema-fiscalyear) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: accounting_periods, fiscal_years |
| **Business rules** | — |
| **Requirements** | FR-082 |
| **Pages and actions** | K4, S0<br>S0 · Switch company (reloads all data) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postaccountingperiodsidlock"></a>
#### `POST /api/v1/accounting-periods/{id}/lock`

Lock a period (earlier periods must be locked).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.lock_period` — roles: Owner, Finance manager |
| **Path / query params** | `id` / — |
| **Request body** | [`PeriodLockRequest`](#schema-periodlockrequest) |
| **Validation** | `note` string ≤ 255 (nullable)<br>Unknown fields are rejected (422).<br>BR-033: Periods lock in date order by users with accounting.lock_period; unlocking needs the same permission, a reason, and is audited. |
| **Success** | 200 `data`: [`AccountingPeriod`](#schema-accountingperiod) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`, `lock_out_of_order`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: accounting_periods; insert: audit_logs; update: accounting_periods; read: company_backups |
| **Business rules** | BR-033 |
| **Requirements** | FR-082 |
| **Pages and actions** | K4<br>K4 · Lock period |
| **Transaction / concurrency** | Locks period rows of the FY; queues a pre_lock backup. |

<a id="postaccountingperiodsidunlock"></a>
#### `POST /api/v1/accounting-periods/{id}/unlock`

Unlock a period with a reason (later periods must be open).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.lock_period` — roles: Owner, Finance manager |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-033: Periods lock in date order by users with accounting.lock_period; unlocking needs the same permission, a reason, and is audited. |
| **Success** | 200 `data`: [`AccountingPeriod`](#schema-accountingperiod) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`, `lock_out_of_order`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: accounting_periods; insert: audit_logs; update: accounting_periods |
| **Business rules** | BR-033 |
| **Requirements** | FR-082 |
| **Pages and actions** | K4<br>K4 · Unlock period (reason) |
| **Transaction / concurrency** | Single transaction. |

<a id="postfiscalyearsidclose"></a>
#### `POST /api/v1/fiscal-years/{id}/close`

Close the financial year (posts year-end entry, opens next FY).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.close_year` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-034: A financial year closes only after its end date with every period locked; closing posts a year-end entry moving net profit to retained earnings.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`FiscalYear`](#schema-fiscalyear) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `fiscal_year_not_closable`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: fiscal_years, number_sequences; insert: audit_logs, idempotency_keys, journal_entries, journal_lines; update: fiscal_years, number_sequences; read: accounting_periods |
| **Business rules** | BR-034, BR-001 |
| **Requirements** | FR-082 |
| **Pages and actions** | K4<br>K4 · Close FY (after year end) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

### Users and roles


<a id="getusers"></a>
#### `GET /api/v1/users`

Members of the company with role and status.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.users` — roles: Owner |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Member`](#schema-member) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: company_users, roles, user_invitations, users |
| **Business rules** | — |
| **Requirements** | FR-006 |
| **Pages and actions** | K8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getusersid"></a>
#### `GET /api/v1/users/{id}`

Member details, recent activity and role permissions.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.users` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Member`](#schema-member) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: audit_logs, company_users, roles, users |
| **Business rules** | — |
| **Requirements** | FR-008 |
| **Pages and actions** | K10 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchusersid"></a>
#### `PATCH /api/v1/users/{id}`

Change a member's role or branch restriction.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.users` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | [`MemberUpdate`](#schema-memberupdate) |
| **Validation** | `role_id` id of a record in this company; `branch_ids` array of id<br>Unknown fields are rejected (422).<br>BR-060: The Owner role always has every permission and a company always keeps at least one active Owner.<br>BR-038: When a membership has branch restrictions, the user can only see and create records of those branches. |
| **Success** | 200 `data`: [`Member`](#schema-member) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`branch_restricted`, `company_maintenance`, `last_owner`, `role_in_use`, `system_role`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: company_users; read: company_user_branches |
| **Business rules** | BR-060, BR-038 |
| **Requirements** | FR-007 |
| **Pages and actions** | K10<br>K10 · Change role |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postusersiddeactivate"></a>
#### `POST /api/v1/users/{id}/deactivate`

Deactivate a member (revokes sessions; their pending approvals stay queued).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.users` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-061: Deactivating a user or changing their password revokes all their sessions immediately.<br>BR-060: The Owner role always has every permission and a company always keeps at least one active Owner. |
| **Success** | 200 `data`: [`Member`](#schema-member) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `last_owner`, `role_in_use`, `system_role`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: company_users; insert: audit_logs; update: company_users; read: user_sessions |
| **Business rules** | BR-061, BR-060 |
| **Requirements** | FR-008 |
| **Pages and actions** | K10<br>K10 · Deactivate (dialog) |
| **Transaction / concurrency** | Single transaction. |

<a id="postusersidreactivate"></a>
#### `POST /api/v1/users/{id}/reactivate`

Reactivate a member (seat check).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.users` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-059: Active plus invited users cannot exceed the company's seat limit. |
| **Success** | 200 `data`: [`Member`](#schema-member) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `seat_limit_reached`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: company_users; insert: audit_logs; update: company_users |
| **Business rules** | BR-059 |
| **Requirements** | FR-008 |
| **Pages and actions** | K10<br>K10 · Reactivate |
| **Transaction / concurrency** | Single transaction. |

<a id="postinvitations"></a>
#### `POST /api/v1/invitations`

Invite someone by e-mail with a role.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.users` — roles: Owner |
| **Path / query params** | — / — |
| **Request body** | [`InvitationCreate`](#schema-invitationcreate) |
| **Validation** | `email`* e-mail; `name` string ≤ 120 (nullable); `role_id`* id of a record in this company; `branch_ids` array of id<br>Unknown fields are rejected (422).<br>BR-059: Active plus invited users cannot exceed the company's seat limit. |
| **Success** | 201 `data`: [`Invitation`](#schema-invitation) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `seat_limit_reached`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, user_invitations; read: branches, companies, company_users, user_invitation_branches |
| **Business rules** | BR-059 |
| **Requirements** | FR-006 |
| **Pages and actions** | K9<br>K9 · Send invite |
| **Transaction / concurrency** | Single transaction. |

<a id="postinvitationsidresend"></a>
#### `POST /api/v1/invitations/{id}/resend`

Resend an invitation (new token, new 7-day expiry).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.users` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Invitation`](#schema-invitation) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: user_invitations; insert: audit_logs; update: user_invitations |
| **Business rules** | — |
| **Requirements** | FR-006 |
| **Pages and actions** | K10<br>K10 · Resend invite |
| **Transaction / concurrency** | Single transaction. |

<a id="deleteinvitationsid"></a>
#### `DELETE /api/v1/invitations/{id}`

Revoke an invitation.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.users` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: user_invitations |
| **Business rules** | — |
| **Requirements** | FR-006 |
| **Pages and actions** | K8, K10<br>K10 · Revoke invitation (NEW) |
| **Transaction / concurrency** | Single transaction. |

<a id="getroles"></a>
#### `GET /api/v1/roles`

Roles with member counts.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.roles` — roles: Owner |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`Role`](#schema-role) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: company_users, roles |
| **Business rules** | — |
| **Requirements** | FR-007 |
| **Pages and actions** | K11, K9 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getrolesid"></a>
#### `GET /api/v1/roles/{id}`

Role with permission matrix and approval limits.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.roles` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Role`](#schema-role) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: permissions, role_permissions, roles |
| **Business rules** | — |
| **Requirements** | FR-007 |
| **Pages and actions** | K11 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postroles"></a>
#### `POST /api/v1/roles`

Create a role (optionally copying another).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.roles` — roles: Owner |
| **Path / query params** | — / — |
| **Request body** | [`RoleInput`](#schema-roleinput) |
| **Validation** | `name`* string ≤ 60; `description` string ≤ 255 (nullable); `permissions`* array of str; `payment_approval_limit`* decimal string, 2 dp; `journal_approval_limit`* decimal string, 2 dp; `copy_from_role_id` id of a record in this company (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Role`](#schema-role) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, roles; read: role_permissions |
| **Business rules** | — |
| **Requirements** | FR-007 |
| **Pages and actions** | K11<br>K11 · New role<br>K11 · Duplicate role |
| **Transaction / concurrency** | Single transaction. |

<a id="putrolesid"></a>
#### `PUT /api/v1/roles/{id}`

Replace a role's permissions and limits (Owner role is locked).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.roles` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | [`RoleInput`](#schema-roleinput) |
| **Validation** | `name`* string ≤ 60; `description` string ≤ 255 (nullable); `permissions`* array of str; `payment_approval_limit`* decimal string, 2 dp; `journal_approval_limit`* decimal string, 2 dp; `copy_from_role_id` id of a record in this company (nullable)<br>Unknown fields are rejected (422).<br>BR-060: The Owner role always has every permission and a company always keeps at least one active Owner. |
| **Success** | 200 `data`: [`Role`](#schema-role) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `last_owner`, `role_in_use`, `system_role`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: roles; read: role_permissions |
| **Business rules** | BR-060 |
| **Requirements** | FR-007 |
| **Pages and actions** | K11<br>K11 · Save changes |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deleterolesid"></a>
#### `DELETE /api/v1/roles/{id}`

Delete a role with no members.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.roles` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: roles; read: role_permissions |
| **Business rules** | — |
| **Requirements** | FR-007 |
| **Pages and actions** | K11<br>K11 · Delete custom role (NEW, only without members) |
| **Transaction / concurrency** | Single transaction. |

<a id="getpermissions"></a>
#### `GET /api/v1/permissions`

Permission catalogue.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.roles` — roles: Owner |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`PermissionList`](#schema-permissionlist) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: permissions |
| **Business rules** | — |
| **Requirements** | FR-007 |
| **Pages and actions** | K11 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

### Platform


<a id="getsearch"></a>
#### `GET /api/v1/search`

Global search across parties, documents, vouchers, ledgers and products (permission-filtered). `q` >= 2 characters; numbers and amounts matched exactly, names by prefix. Types the user cannot view are omitted.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / `q`, `type`, `limit` |
| **Request body** | — |
| **Validation** | BR-037: Every read and write is scoped to the active company; any reference to another company's record is rejected as not found.<br>BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data`: [`SearchResults`](#schema-searchresults) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: journal_entries, ledgers, letters_of_credit, parties, products, purchase_bills, sales_invoices |
| **Business rules** | BR-037, BR-052 |
| **Requirements** | FR-012 |
| **Pages and actions** | L1, L2, S0 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getnotifications"></a>
#### `GET /api/v1/notifications`

Notification feed.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / `page`, `per_page`, `category`, `unread` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Notification`](#schema-notification) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: notifications |
| **Business rules** | — |
| **Requirements** | FR-013 |
| **Pages and actions** | L3, S0<br>S0 · Open notifications |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postnotificationsidread"></a>
#### `POST /api/v1/notifications/{id}/read`

Mark a notification read.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: notifications; update: notifications |
| **Business rules** | — |
| **Requirements** | FR-013 |
| **Pages and actions** | L3<br>L3 · Open notification (marks it read) |
| **Transaction / concurrency** | Single transaction. |

<a id="postnotificationsreadall"></a>
#### `POST /api/v1/notifications/read-all`

Mark all read.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: notifications; update: notifications |
| **Business rules** | — |
| **Requirements** | FR-013 |
| **Pages and actions** | L3<br>L3 · Mark all read |
| **Transaction / concurrency** | Single transaction. |

<a id="getapprovals"></a>
#### `GET /api/v1/approvals`

Approval requests (pending for me, or all).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / `status`, `page` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`ApprovalRequest`](#schema-approvalrequest) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: approval_requests, journal_entries |
| **Business rules** | — |
| **Requirements** | FR-014 |
| **Pages and actions** | L3, L4, H1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getapprovalsid"></a>
#### `GET /api/v1/approvals/{id}`

One approval request with the document facts shown in the L4 drawer. _Contract change: C-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — any → `the requester, or a holder of the request's required_permission` |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`ApprovalRequest`](#schema-approvalrequest) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: approval_requests, journal_entries, journal_lines, users |
| **Business rules** | — |
| **Requirements** | FR-014 |
| **Pages and actions** | L4, H13 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postapprovalsidapprove"></a>
#### `POST /api/v1/approvals/{id}/approve`

Approve and post the document. Permission is module-specific: `banking.approve` for cash vouchers, `purchases.approve` for supplier payments, `accounting.approve` for journals (the request's `required_permission`).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — any → `the request's required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)` |
| **Path / query params** | `id` / — |
| **Request body** | [`ApprovalDecision`](#schema-approvaldecision) |
| **Validation** | `comment` string ≤ 500 (nullable)<br>Unknown fields are rejected (422).<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-008: Nobody approves a voucher they submitted (segregation of duties).<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected. |
| **Success** | 200 `data`: [`ApprovalRequest`](#schema-approvalrequest) · 202 when above the approval limit |
| **Status codes** | 200, 202, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `not_draft`, `period_locked`, `self_approval_forbidden`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: approval_requests, journal_entries; insert: audit_logs, bill_allocations, idempotency_keys, notifications; update: approval_requests, journal_entries, journal_lines |
| **Business rules** | BR-007, BR-008, BR-001, BR-004 |
| **Requirements** | FR-014 |
| **Pages and actions** | L4, H13<br>H13 · Approve / Reject (when pending)<br>L4 · Approve |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postapprovalsidreject"></a>
#### `POST /api/v1/approvals/{id}/reject`

Reject with a required comment (document returns to draft as rejected).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — any → `the request's required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)` |
| **Path / query params** | `id` / — |
| **Request body** | [`ApprovalDecision`](#schema-approvaldecision) |
| **Validation** | `comment` string ≤ 500 (nullable)<br>Unknown fields are rejected (422).<br>BR-008: Nobody approves a voucher they submitted (segregation of duties). |
| **Success** | 200 `data`: [`ApprovalRequest`](#schema-approvalrequest) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`comment_required`, `company_maintenance`, `self_approval_forbidden`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: approval_requests; insert: audit_logs; update: approval_requests, journal_entries; read: notifications |
| **Business rules** | BR-008 |
| **Requirements** | FR-014 |
| **Pages and actions** | L4, H13<br>H13 · Approve / Reject (when pending)<br>L4 · Reject (comment required) |
| **Transaction / concurrency** | Single transaction. |

<a id="postattachments"></a>
#### `POST /api/v1/attachments`

Upload a file to a record (multipart). Caller needs edit permission on the target record; 10 MB limit; content-type sniffed.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` |
| **Path / query params** | — / — |
| **Request body** | [`AttachmentUpload`](#schema-attachmentupload) |
| **Validation** | `file`* string; `attachable_type`* one of sales_invoice/purchase_bill/voucher/lc_document/letter_of_credit/stock_adjustment/party; `attachable_id`* id of a record in this company<br>Unknown fields are rejected (422).<br>BR-051: Attachments must be JPG, PNG or PDF up to 10 MB, verified by content type sniffing. |
| **Success** | 201 `data`: [`Attachment`](#schema-attachment) |
| **Status codes** | 201, 400, 401, 403, 409, 413, 415, 422, 503 |
| **Error codes** | **`company_maintenance`, `file_too_large`, `file_type_not_allowed`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: attachments, audit_logs, idempotency_keys |
| **Business rules** | BR-051 |
| **Requirements** | FR-066 |
| **Pages and actions** | E5, F4, G6, G7, I3, I6, I2, I5, M4, H13<br>E5 · Attach file<br>I2 · Attach receipt<br>I5 · Attach receipt<br>M4 · Attach scan |
| **Transaction / concurrency** | Single transaction. |

<a id="getattachmentsidurl"></a>
#### `GET /api/v1/attachments/{id}/url`

Short-lived signed download URL (5 minutes).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — any → `<module>.view of the record named by type/id (e.g. sales.view for a sales_invoice)` |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`SignedUrl`](#schema-signedurl) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: attachments |
| **Business rules** | — |
| **Requirements** | FR-066 |
| **Pages and actions** | E5, F4, I5, M4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="deleteattachmentsid"></a>
#### `DELETE /api/v1/attachments/{id}`

Remove an attachment (not from posted vouchers in locked periods).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: attachments |
| **Business rules** | — |
| **Requirements** | FR-066 |
| **Pages and actions** | I6, M4<br>I6 · Remove attachment (NEW)<br>M4 · Remove attachment (NEW) |
| **Transaction / concurrency** | Single transaction. |

<a id="getauditlogs"></a>
#### `GET /api/v1/audit-logs`

Activity timeline of one record. Requires view permission on the audited record's module.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — any → `<module>.view of the record named by type/id (e.g. sales.view for a sales_invoice)` |
| **Path / query params** | — / `auditable_type`, `auditable_id`, `page` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`AuditLog`](#schema-auditlog) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: audit_logs, users |
| **Business rules** | — |
| **Requirements** | FR-074 |
| **Pages and actions** | E5, F4, B4, C4, H13, M4, K10, D3<br>B4 · Switch tab<br>C4 · Switch tab |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getdashboard"></a>
#### `GET /api/v1/dashboard`

Main dashboard data for a period. Cached per company and period for 60 seconds; invalidated by postings.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `dashboard.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `period` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`DashboardSummary`](#schema-dashboardsummary) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: journal_entries, journal_lines, ledgers, money_accounts, product_warehouse_settings, purchase_bills, sales_invoices, stock_balances |
| **Business rules** | — |
| **Requirements** | FR-010 |
| **Pages and actions** | A1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getdashboardlenseslens"></a>
#### `GET /api/v1/dashboard/lenses/{lens}`

Dashboard lens data (financial, sales, expenses, receivables, payables, cash, inventory). Each lens also requires the source module's view permission (financial: reports.view; sales: sales.view; expenses and cash: banking.view; receivables/payables: parties.view; inventory: inventory.view); otherwise 403 and the lens tab is hidden.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `dashboard.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `lens` / `period` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`LensData`](#schema-lensdata) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines, money_accounts, parties, product_warehouse_settings, purchase_bills, sales_invoices, stock_balances |
| **Business rules** | — |
| **Requirements** | FR-011 |
| **Pages and actions** | A2, A3, A4, A5, A6, A7, A8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getbackups"></a>
#### `GET /api/v1/backups`

Backup history and settings.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.backup` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`Backup`](#schema-backup) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: company_backups |
| **Business rules** | — |
| **Requirements** | FR-087 |
| **Pages and actions** | K15 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postbackups"></a>
#### `POST /api/v1/backups`

Take a manual backup now (queued).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.backup` — roles: Owner, Finance manager |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 202 `data`: [`Backup`](#schema-backup) · 202 when above the approval limit |
| **Status codes** | 202, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key` |
| **Database** | insert: audit_logs, company_backups, idempotency_keys |
| **Business rules** | — |
| **Requirements** | FR-087 |
| **Pages and actions** | K15<br>K15 · Back up now |
| **Transaction / concurrency** | Single transaction. |

<a id="getbackupsiddownload"></a>
#### `GET /api/v1/backups/{id}/download`

Signed URL of a backup file (Owner only: snapshots hold the whole company, R-11).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.restore` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`SignedUrl`](#schema-signedurl) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: audit_logs, company_backups |
| **Business rules** | — |
| **Requirements** | FR-087 |
| **Pages and actions** | K15<br>K15 · Download backup |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postbackupsidrestore"></a>
#### `POST /api/v1/backups/{id}/restore`

Restore the company from a backup (Owner; confirmation text RESTORE).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `settings.restore` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | [`RestoreRequest`](#schema-restorerequest) |
| **Validation** | `confirmation`* one of RESTORE<br>Unknown fields are rejected (422).<br>BR-062: Restore is Owner-only, requires typing RESTORE, and always takes a pre-restore snapshot first. |
| **Success** | 202 `data`: [`Backup`](#schema-backup) · 202 when above the approval limit |
| **Status codes** | 202, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `restore_confirmation_required`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | insert: audit_logs, company_backups, idempotency_keys |
| **Business rules** | BR-062 |
| **Requirements** | FR-087 |
| **Pages and actions** | K15<br>K15 · Restore… (dialog) |
| **Transaction / concurrency** | Company placed in maintenance; pre_restore snapshot first; queued job. |

<a id="postexportjobs"></a>
#### `POST /api/v1/export-jobs`

Prepare the month-end export pack or a large report export.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.export` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`ExportJobCreate`](#schema-exportjobcreate) |
| **Validation** | `kind`* one of export_pack/list; `format`* one of xlsx/pdf/both/csv; `period` string ≤ 20 (nullable); `reports` array of str; `source` string ≤ 60 (nullable); `filters` object (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 202 `data`: [`ExportJob`](#schema-exportjob) · 202 when above the approval limit |
| **Status codes** | 202, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | insert: audit_logs, export_jobs, idempotency_keys |
| **Business rules** | — |
| **Requirements** | FR-087, FR-076 |
| **Pages and actions** | K15, J1, B1, B5, B6, C1, C5, C6, D1, D9, D12, E2, E13, E14, E15, F2, F11, F12, F13, G11, G12, H2, H7<br>B1 · Export (bulk)<br>B5 · Export<br>B6 · Export<br>C1 · Export (bulk)<br>C5 · Export<br>C6 · Export<br>D1 · Export<br>D9 · Export<br>D12 · Export<br>E2 · Export<br>E13 · Export<br>E14 · Export<br>E15 · Export<br>E15 · Export<br>F2 · Export<br>F11 · Export<br>F12 · Export<br>F13 · Export<br>F13 · Export<br>G11 · Export<br>G12 · Export<br>H2 · Export<br>H7 · Export<br>K15 · Prepare export pack |
| **Transaction / concurrency** | Single transaction. |

<a id="getexportjobsid"></a>
#### `GET /api/v1/export-jobs/{id}`

Export job status and signed download URL when complete.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.export` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`ExportJob`](#schema-exportjob) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: export_jobs |
| **Business rules** | — |
| **Requirements** | FR-087 |
| **Pages and actions** | K15, J1, B1, B5, B6, C1, C5, C6, D1, D9, D12, E2, E13, E14, E15, F2, F11, F12, F13, G11, G12, H2, H7<br>B1 · Export (bulk)<br>B5 · Export<br>B6 · Export<br>C1 · Export (bulk)<br>C5 · Export<br>C6 · Export<br>D1 · Export<br>D9 · Export<br>D12 · Export<br>E2 · Export<br>E13 · Export<br>E14 · Export<br>E15 · Export<br>E15 · Export<br>F2 · Export<br>F11 · Export<br>F12 · Export<br>F13 · Export<br>F13 · Export<br>G11 · Export<br>G12 · Export<br>H2 · Export<br>H7 · Export<br>K15 · Prepare export pack |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

### Parties


<a id="getparties"></a>
#### `GET /api/v1/parties`

List customers or suppliers. `type` (customer|supplier) is required. Balance and overdue are computed from posted documents.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `type`, `status`, `balance`, `area`, `group_id` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Party`](#schema-party) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: parties, party_groups, payment_terms, purchase_bills, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-020 |
| **Pages and actions** | B1, C1, E3, F3, E7, F5, M3 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postparties"></a>
#### `POST /api/v1/parties`

Create a customer or supplier (and its ledger, and opening balance entry).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`PartyInput`](#schema-partyinput) |
| **Validation** | `party_type`* one of customer/supplier; `name`* string ≤ 190; `category` one of business/individual/walk_in/manufacturer/distributor/service_provider (nullable); `party_group_id` id of a record in this company (nullable); `contact_person` string ≤ 120 (nullable); `mobile` string ≤ 20 (nullable); `email` e-mail (nullable); `alt_phone` string ≤ 20 (nullable); `address` string ≤ 255 (nullable); `district` string ≤ 60 (nullable); `area` string ≤ 80 (nullable); `country_code` string ≤ 2; `default_currency_code` ISO 4217; `payment_term_id` id of a record in this company (nullable); `credit_limit` decimal string, 2 dp (nullable); `price_tier` one of standard/wholesale; `default_purchase_ledger_id` id of a record in this company (nullable); `tax_registration_no` string ≤ 40 (nullable); `port_of_loading` string ≤ 120 (nullable); `notes` text ≤ 65,535 (nullable); `opening_balance` decimal string, 2 dp; `opening_side` one of dr/cr; `bank_accounts` array of PartyBankAccount<br>Unknown fields are rejected (422).<br>BR-063: Local party mobile numbers have 11 digits starting 01 (01XXX-XXXXXX); e-mail addresses must be valid when given.<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 201 `data`: [`Party`](#schema-party) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `invalid_mobile`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, parties; read: account_mappings, journal_entries, journal_lines, ledgers, number_sequences, party_bank_accounts |
| **Business rules** | BR-063, BR-028, BR-006 |
| **Requirements** | FR-021 |
| **Pages and actions** | B2, C2, E3, L5<br>B2 · Save and add another<br>B2 · Save (Ctrl S)<br>C2 · Save and add another<br>C2 · Save (Ctrl S) |
| **Transaction / concurrency** | One transaction: code from sequence, ledger under mapped group, opening_balance entry if > 0. |

<a id="getpartiesid"></a>
#### `GET /api/v1/parties/{id}`

Party with contact, terms, bank details, KPIs and credit used.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Party`](#schema-party) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: journal_lines, ledgers, parties, party_bank_accounts |
| **Business rules** | — |
| **Requirements** | FR-022 |
| **Pages and actions** | B3, B4, C3, C4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchpartiesid"></a>
#### `PATCH /api/v1/parties/{id}`

Edit a party (opening balance locked after first document).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`PartyInput`](#schema-partyinput) |
| **Validation** | `party_type`* one of customer/supplier; `name`* string ≤ 190; `category` one of business/individual/walk_in/manufacturer/distributor/service_provider (nullable); `party_group_id` id of a record in this company (nullable); `contact_person` string ≤ 120 (nullable); `mobile` string ≤ 20 (nullable); `email` e-mail (nullable); `alt_phone` string ≤ 20 (nullable); `address` string ≤ 255 (nullable); `district` string ≤ 60 (nullable); `area` string ≤ 80 (nullable); `country_code` string ≤ 2; `default_currency_code` ISO 4217; `payment_term_id` id of a record in this company (nullable); `credit_limit` decimal string, 2 dp (nullable); `price_tier` one of standard/wholesale; `default_purchase_ledger_id` id of a record in this company (nullable); `tax_registration_no` string ≤ 40 (nullable); `port_of_loading` string ≤ 120 (nullable); `notes` text ≤ 65,535 (nullable); `opening_balance` decimal string, 2 dp; `opening_side` one of dr/cr; `bank_accounts` array of PartyBankAccount<br>Unknown fields are rejected (422).<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.<br>BR-063: Local party mobile numbers have 11 digits starting 01 (01XXX-XXXXXX); e-mail addresses must be valid when given.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. |
| **Success** | 200 `data`: [`Party`](#schema-party) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `invalid_mobile`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: parties; read: journal_entries, journal_lines, ledgers, party_bank_accounts |
| **Business rules** | BR-028, BR-063, BR-049 |
| **Requirements** | FR-021 |
| **Pages and actions** | B3, C3<br>B3 · Save changes (If-Match)<br>C3 · Save changes (If-Match) |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postpartiesiddeactivate"></a>
#### `POST /api/v1/parties/{id}/deactivate`

Make inactive (hidden from new documents).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. |
| **Success** | 200 `data`: [`Party`](#schema-party) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `has_history`, `inactive_reference`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: parties; insert: audit_logs; update: parties; read: ledgers |
| **Business rules** | BR-026 |
| **Requirements** | FR-025 |
| **Pages and actions** | B3, C3, B1, C1<br>B3 · Make inactive<br>C3 · Make inactive |
| **Transaction / concurrency** | Single transaction. |

<a id="postpartiesidreactivate"></a>
#### `POST /api/v1/parties/{id}/reactivate`

Make active again.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Party`](#schema-party) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: parties; insert: audit_logs; update: parties; read: ledgers |
| **Business rules** | — |
| **Requirements** | FR-025 |
| **Pages and actions** | B3, C3<br>B3 · Make inactive<br>C3 · Make inactive |
| **Transaction / concurrency** | Single transaction. |

<a id="postpartiesbulkdeactivate"></a>
#### `POST /api/v1/parties/bulk-deactivate`

Make several parties inactive (each with history is deactivated, never deleted). _Contract change: C-04._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`BulkIds`](#schema-bulkids) |
| **Validation** | `ids`* array of id<br>Unknown fields are rejected (422).<br>BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. |
| **Success** | 200 `data`: [`BulkResult`](#schema-bulkresult) |
| **Status codes** | 200, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `has_history`, `inactive_reference`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: parties; insert: audit_logs; update: parties; read: ledgers |
| **Business rules** | BR-026 |
| **Requirements** | FR-020 |
| **Pages and actions** | B1, C1<br>B1 · Mark inactive (bulk)<br>C1 · Mark inactive (bulk) |
| **Transaction / concurrency** | Single transaction. |

<a id="deletepartiesid"></a>
#### `DELETE /api/v1/parties/{id}`

Delete a party with no history (type the name to confirm in UI).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `has_history`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; update: parties; read: journal_lines, ledgers |
| **Business rules** | BR-026 |
| **Requirements** | FR-025 |
| **Pages and actions** | L7, B3, C3<br>B3 · Delete (L7 confirmation, only without history)<br>C3 · Delete (L7 confirmation, only without history) |
| **Transaction / concurrency** | Single transaction. |

<a id="getpartiesidopendocuments"></a>
#### `GET /api/v1/parties/{id}/open-documents`

Open invoices or bills with balance, oldest due first. Open invoices or bills, oldest due first, and the open credits that can be applied to them (unapplied receipts/payments, advances, posted notes with usage credit), each with the journal_line_id to send to POST /allocations (R-08).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`OpenDocuments`](#schema-opendocuments) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: bill_allocations, credit_notes, debit_notes, journal_lines, payment_details, purchase_bills, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-022, FR-043 |
| **Pages and actions** | B4, C4, E7, F5, E5, F4<br>B4 · Apply advance or credit (NEW, R-08)<br>C4 · Apply advance or credit (NEW, R-08)<br>E5 · Apply advance or credit (NEW, R-08)<br>F4 · Apply advance or credit (NEW, R-08) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getpartiesidtransactions"></a>
#### `GET /api/v1/parties/{id}/transactions`

Document history of a party.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `document_type` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`PartyTransaction`](#schema-partytransaction) + `meta` |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: credit_notes, debit_notes, journal_entries, purchase_bills, purchase_orders, purchase_returns, quotations, sales_invoices, sales_returns |
| **Business rules** | — |
| **Requirements** | FR-023 |
| **Pages and actions** | B5, C5, B4, C4<br>B4 · Switch tab<br>C4 · Switch tab |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getpartiesidledger"></a>
#### `GET /api/v1/parties/{id}/ledger`

Party ledger with opening, running and closing balance.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / `date_from`, `date_to`, `density`, `page` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`LedgerStatement`](#schema-ledgerstatement) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: journal_entries, journal_lines, ledgers |
| **Business rules** | — |
| **Requirements** | FR-023 |
| **Pages and actions** | B6, C6, B8, C8, B4, C4<br>B4 · Switch tab<br>C4 · Switch tab |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getpartiesoutstanding"></a>
#### `GET /api/v1/parties/outstanding`

Open documents across parties by age bucket.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `type`, `bucket`, `as_of` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`OutstandingReport`](#schema-outstandingreport) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: parties, purchase_bills, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-023 |
| **Pages and actions** | B7, C7<br>B7 · Filter by bucket<br>C7 · Filter by bucket |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getpartiesidstatement"></a>
#### `GET /api/v1/parties/{id}/statement`

Statement of account (JSON for the screen).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / `date_from`, `date_to`, `aging` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`PartyStatement`](#schema-partystatement) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: companies, journal_entries, journal_lines, parties, purchase_bills, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-024 |
| **Pages and actions** | B8, C8, J6, J7 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getpartiesidstatementpdf"></a>
#### `GET /api/v1/parties/{id}/statement/pdf`

Statement of account as PDF (same parameters as the JSON statement). _Contract change: C-01._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / `date_from`, `date_to`, `aging` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: companies, journal_entries, journal_lines, parties |
| **Business rules** | — |
| **Requirements** | FR-024 |
| **Pages and actions** | B8, C8<br>B8 · PDF<br>B8 · Print<br>C8 · PDF<br>C8 · Print |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postpartiesstatementssend"></a>
#### `POST /api/v1/parties/statements/send`

E-mail statements to one or more parties (queued).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / — |
| **Request body** | [`StatementSendRequest`](#schema-statementsendrequest) |
| **Validation** | `party_ids`* array of id; `date_from`* YYYY-MM-DD; `date_to`* YYYY-MM-DD; `include_aging` boolean<br>Unknown fields are rejected (422). |
| **Success** | 202 `data`: [`ExportJob`](#schema-exportjob) · 202 when above the approval limit |
| **Status codes** | 202, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: parties; insert: audit_logs, idempotency_keys; update: parties; read: export_jobs |
| **Business rules** | — |
| **Requirements** | FR-024 |
| **Pages and actions** | B1, B8, C8, B7, C1, C7<br>B1 · Send statements (bulk)<br>B7 · Send reminders/statements (bulk)<br>B8 · Email to customer<br>C1 · Send statements (bulk)<br>C7 · Send reminders/statements (bulk)<br>C8 · Email to supplier |
| **Transaction / concurrency** | Single transaction. |

<a id="getpartygroups"></a>
#### `GET /api/v1/party-groups`

Customer or supplier groups.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `type` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`PartyGroup`](#schema-partygroup) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: party_groups |
| **Business rules** | — |
| **Requirements** | FR-021 |
| **Pages and actions** | B2, C2, B1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postpartygroups"></a>
#### `POST /api/v1/party-groups`

Add a group.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `parties.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`PartyGroupInput`](#schema-partygroupinput) |
| **Validation** | `party_type`* one of customer/supplier; `name`* string ≤ 80<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`PartyGroup`](#schema-partygroup) |
| **Status codes** | 201, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, party_groups |
| **Business rules** | — |
| **Requirements** | FR-021 |
| **Pages and actions** | B2, C2<br>B2 · Add group inline (NEW)<br>C2 · Add group inline (NEW) |
| **Transaction / concurrency** | Single transaction. |

### Inventory


<a id="getproducts"></a>
#### `GET /api/v1/products`

List products and services with stock status.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `item_type`, `category_id`, `warehouse_id`, `stock_status`, `active` |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data[]` of [`Product`](#schema-product) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: product_categories, product_warehouse_settings, products, stock_balances, units |
| **Business rules** | BR-052 |
| **Requirements** | FR-030 |
| **Pages and actions** | D1, E3, F3, D10, D11, E10, F8<br>D10 · Add product<br>D11 · Add product<br>E3 · Find item<br>E3 · Paste from sheet<br>F3 · Find item |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postproducts"></a>
#### `POST /api/v1/products`

Create a product or service (optional opening stock per warehouse).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / — |
| **Request body** | [`ProductInput`](#schema-productinput) |
| **Validation** | `item_type`* one of product/service; `name`* string ≤ 190; `code` string ≤ 30 (nullable); `category_id` id of a record in this company (nullable); `unit_id`* id of a record in this company; `barcode` string ≤ 40 (nullable); `last_purchase_cost` decimal string, 4 dp (nullable); `selling_price`* decimal string, 2 dp; `wholesale_price` decimal string, 2 dp (nullable); `tax_rule_id` id of a record in this company (nullable); `income_ledger_id`* id of a record in this company; `expense_ledger_id` id of a record in this company (nullable); `stock_group_id` id of a record in this company (nullable); `preferred_supplier_id` id of a record in this company (nullable); `is_active` boolean; `warehouses` array of ProductWarehouseInput<br>Unknown fields are rejected (422).<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.<br>BR-017: On every stock-in to a warehouse, that warehouse's average cost = (warehouse value + incoming value) / (warehouse quantity + incoming quantity); when current quantity <= 0 the incoming unit cost becomes the average. |
| **Success** | 201 `data`: [`Product`](#schema-product) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, products; read: journal_entries, journal_lines, product_warehouse_settings, stock_balances, stock_movements |
| **Business rules** | BR-028, BR-017 |
| **Requirements** | FR-031 |
| **Pages and actions** | D2, L5<br>D2 · Save and add another<br>D2 · Save item |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postproductsimport"></a>
#### `POST /api/v1/products/import`

Import products from CSV/XLSX: dry_run=true validates and previews, false creates in one transaction. _Contract change: C-05._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / `dry_run` |
| **Request body** | [`ProductImport`](#schema-productimport) |
| **Validation** | `file`* string<br>Unknown fields are rejected (422).<br>BR-036: No tax rate is assumed: tax is calculated only from user-defined tax rules attached to a line, and posts to the rule's ledger.<br>BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. |
| **Success** | 200 `data`: [`ImportResult`](#schema-importresult) |
| **Status codes** | 200, 201, 400, 401, 403, 409, 413, 415, 422, 503 |
| **Error codes** | **`company_maintenance`, `file_too_large`, `file_type_not_allowed`, `has_history`, `inactive_reference`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: products; insert: audit_logs, idempotency_keys; update: products; read: ledgers, product_categories, product_warehouse_settings, tax_rules, units |
| **Business rules** | BR-036, BR-026 |
| **Requirements** | FR-030 |
| **Pages and actions** | D1<br>D1 · Import<br>D1 · Import |
| **Transaction / concurrency** | Single transaction. |

<a id="getproductsid"></a>
#### `GET /api/v1/products/{id}`

Product with stock by warehouse, costs (if permitted) and price history.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data`: [`Product`](#schema-product) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: audit_logs, product_warehouse_settings, products, stock_balances |
| **Business rules** | BR-052 |
| **Requirements** | FR-032 |
| **Pages and actions** | D3, D4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchproductsid"></a>
#### `PATCH /api/v1/products/{id}`

Edit a product (quantities never change here).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.edit` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | [`ProductInput`](#schema-productinput) |
| **Validation** | `item_type`* one of product/service; `name`* string ≤ 190; `code` string ≤ 30 (nullable); `category_id` id of a record in this company (nullable); `unit_id`* id of a record in this company; `barcode` string ≤ 40 (nullable); `last_purchase_cost` decimal string, 4 dp (nullable); `selling_price`* decimal string, 2 dp; `wholesale_price` decimal string, 2 dp (nullable); `tax_rule_id` id of a record in this company (nullable); `income_ledger_id`* id of a record in this company; `expense_ledger_id` id of a record in this company (nullable); `stock_group_id` id of a record in this company (nullable); `preferred_supplier_id` id of a record in this company (nullable); `is_active` boolean; `warehouses` array of ProductWarehouseInput<br>Unknown fields are rejected (422).<br>BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. |
| **Success** | 200 `data`: [`Product`](#schema-product) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `has_history`, `inactive_reference`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: products; read: product_warehouse_settings |
| **Business rules** | BR-026, BR-049 |
| **Requirements** | FR-031 |
| **Pages and actions** | D3<br>D3 · Save changes |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deleteproductsid"></a>
#### `DELETE /api/v1/products/{id}`

Delete an unused product; used products are deactivated instead.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `has_history`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; update: products; read: purchase_bill_lines, sales_invoice_lines, stock_movements |
| **Business rules** | BR-026 |
| **Requirements** | FR-031 |
| **Pages and actions** | D3<br>D3 · Delete product (NEW, only without history) |
| **Transaction / concurrency** | Single transaction. |

<a id="getstockmovements"></a>
#### `GET /api/v1/stock-movements`

All stock movements.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `movement_type`, `warehouse_id`, `product_id` |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data[]` of [`StockMovement`](#schema-stockmovement) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: products, stock_movements, warehouses |
| **Business rules** | BR-052 |
| **Requirements** | FR-034 |
| **Pages and actions** | D9 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getproductcategories"></a>
#### `GET /api/v1/product-categories`

List categorys.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Lookup`](#schema-lookup) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: product_categories |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D5, D1, D2 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postproductcategories"></a>
#### `POST /api/v1/product-categories`

Create a category.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / — |
| **Request body** | [`LookupInput`](#schema-lookupinput) |
| **Validation** | `name`* string ≤ 80; `parent_id` id of a record in this company (nullable); `code_prefix` string ≤ 10 (nullable); `default_unit_id` id of a record in this company (nullable); `default_stock_group_id` id of a record in this company (nullable); `sort_order` integer (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Lookup`](#schema-lookup) |
| **Status codes** | 201, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, product_categories |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D5<br>D5 · New category |
| **Transaction / concurrency** | Single transaction. |

<a id="patchproductcategoriesid"></a>
#### `PATCH /api/v1/product-categories/{id}`

Edit a category.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.edit` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | [`LookupInput`](#schema-lookupinput) |
| **Validation** | `name`* string ≤ 80; `parent_id` id of a record in this company (nullable); `code_prefix` string ≤ 10 (nullable); `default_unit_id` id of a record in this company (nullable); `default_stock_group_id` id of a record in this company (nullable); `sort_order` integer (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Lookup`](#schema-lookup) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | insert: audit_logs; update: product_categories |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D5<br>D5 · Edit<br>D5 · Reorder (drag) |
| **Transaction / concurrency** | Single transaction. |

<a id="deleteproductcategoriesid"></a>
#### `DELETE /api/v1/product-categories/{id}`

Delete an unused category.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `has_history`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: product_categories; read: products |
| **Business rules** | BR-026 |
| **Requirements** | FR-033 |
| **Pages and actions** | D5<br>D5 · Delete (only if unused) |
| **Transaction / concurrency** | Single transaction. |

<a id="getstockgroups"></a>
#### `GET /api/v1/stock-groups`

List stock groups.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Lookup`](#schema-lookup) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: stock_groups |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D6, D2 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="poststockgroups"></a>
#### `POST /api/v1/stock-groups`

Create a stock group.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / — |
| **Request body** | [`LookupInput`](#schema-lookupinput) |
| **Validation** | `name`* string ≤ 80; `parent_id` id of a record in this company (nullable); `code_prefix` string ≤ 10 (nullable); `default_unit_id` id of a record in this company (nullable); `default_stock_group_id` id of a record in this company (nullable); `sort_order` integer (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Lookup`](#schema-lookup) |
| **Status codes** | 201, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, stock_groups |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D6<br>D6 · New stock group |
| **Transaction / concurrency** | Single transaction. |

<a id="patchstockgroupsid"></a>
#### `PATCH /api/v1/stock-groups/{id}`

Edit a stock group.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.edit` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | [`LookupInput`](#schema-lookupinput) |
| **Validation** | `name`* string ≤ 80; `parent_id` id of a record in this company (nullable); `code_prefix` string ≤ 10 (nullable); `default_unit_id` id of a record in this company (nullable); `default_stock_group_id` id of a record in this company (nullable); `sort_order` integer (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Lookup`](#schema-lookup) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | insert: audit_logs; update: stock_groups |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D6<br>D6 · Edit |
| **Transaction / concurrency** | Single transaction. |

<a id="deletestockgroupsid"></a>
#### `DELETE /api/v1/stock-groups/{id}`

Delete an unused stock group.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `has_history`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: stock_groups; read: products |
| **Business rules** | BR-026 |
| **Requirements** | FR-033 |
| **Pages and actions** | D6<br>D6 · Delete stock group (NEW, only when unused) |
| **Transaction / concurrency** | Single transaction. |

<a id="getunits"></a>
#### `GET /api/v1/units`

List units.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Lookup`](#schema-lookup) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: units |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D2, D5 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postunits"></a>
#### `POST /api/v1/units`

Create a unit.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / — |
| **Request body** | [`UnitInput`](#schema-unitinput) |
| **Validation** | `name`* string ≤ 30; `decimals` integer<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Lookup`](#schema-lookup) |
| **Status codes** | 201, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, units |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D2<br>D2 · Add unit inline (NEW) |
| **Transaction / concurrency** | Single transaction. |

<a id="getwarehouses"></a>
#### `GET /api/v1/warehouses`

Warehouses with value, products, below-reorder count.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data[]` of [`Warehouse`](#schema-warehouse) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: product_warehouse_settings, stock_balances, warehouses |
| **Business rules** | BR-052 |
| **Requirements** | FR-033 |
| **Pages and actions** | D7, E3, F3, D10, D11, S0 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postwarehouses"></a>
#### `POST /api/v1/warehouses`

Create a warehouse (inventory ledger created under 1301 unless given).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / — |
| **Request body** | [`WarehouseInput`](#schema-warehouseinput) |
| **Validation** | `code`* string ≤ 20; `name`* string ≤ 120; `branch_id`* id of a record in this company; `address` string ≤ 255 (nullable); `manager_user_id` id of a record in this company (nullable); `phone` string ≤ 30 (nullable); `inventory_ledger_id` id of a record in this company (nullable); `is_default_for_sales` boolean; `status` one of active/planned/inactive; `opens_on` YYYY-MM-DD (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Warehouse`](#schema-warehouse) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, warehouses; read: account_mappings, ledgers |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D7<br>D7 · New warehouse (drawer: code, name, branch, address, manager, phone, inventory ledger, default for sales, status, opens on) |
| **Transaction / concurrency** | Single transaction. |

<a id="getwarehousesid"></a>
#### `GET /api/v1/warehouses/{id}`

Warehouse details and recent transfers.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Warehouse`](#schema-warehouse) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: stock_transfers, warehouses |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchwarehousesid"></a>
#### `PATCH /api/v1/warehouses/{id}`

Edit a warehouse.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.edit` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | [`WarehouseInput`](#schema-warehouseinput) |
| **Validation** | `code`* string ≤ 20; `name`* string ≤ 120; `branch_id`* id of a record in this company; `address` string ≤ 255 (nullable); `manager_user_id` id of a record in this company (nullable); `phone` string ≤ 30 (nullable); `inventory_ledger_id` id of a record in this company (nullable); `is_default_for_sales` boolean; `status` one of active/planned/inactive; `opens_on` YYYY-MM-DD (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Warehouse`](#schema-warehouse) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: warehouses |
| **Business rules** | — |
| **Requirements** | FR-033 |
| **Pages and actions** | D8<br>D8 · Edit |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getwarehousesidstock"></a>
#### `GET /api/v1/warehouses/{id}/stock`

Stock in one warehouse.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | `id` / `page`, `per_page`, `sort`, `q`, `below_reorder` |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data[]` of [`StockLevel`](#schema-stocklevel) + `meta` |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: product_warehouse_settings, products, stock_balances |
| **Business rules** | BR-052 |
| **Requirements** | FR-033 |
| **Pages and actions** | D8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getstockadjustments"></a>
#### `GET /api/v1/stock-adjustments`

List StockAdjustment documents.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `status`, `warehouse_id` |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data[]` of [`StockAdjustment`](#schema-stockadjustment) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: stock_adjustment_lines, stock_adjustments |
| **Business rules** | BR-052 |
| **Requirements** | FR-035 |
| **Pages and actions** | D10, D8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="poststockadjustments"></a>
#### `POST /api/v1/stock-adjustments`

Create a draft StockAdjustment.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / — |
| **Request body** | [`StockAdjustmentInput`](#schema-stockadjustmentinput) |
| **Validation** | `adjustment_date`* YYYY-MM-DD; `warehouse_id`* id of a record in this company; `reason`* one of count_difference/damaged/expired/theft_or_loss/free_samples; `notes` text ≤ 65,535 (nullable); `lines`* array of AdjustmentLineInput<br>Unknown fields are rejected (422).<br>BR-029: A stock adjustment posts the value of the difference: a shortage debits the stock-adjustment ledger and credits inventory; a gain does the opposite.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.<br>BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 201 `data`: [`StockAdjustment`](#schema-stockadjustment) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `period_locked`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, stock_adjustment_lines, stock_adjustments; read: number_sequences, stock_balances |
| **Business rules** | BR-029, BR-004, BR-006, BR-052 |
| **Requirements** | FR-035 |
| **Pages and actions** | D10<br>D10 · Save draft<br>D10 · Post adjustment |
| **Transaction / concurrency** | Single transaction. |

<a id="getstockadjustmentsid"></a>
#### `GET /api/v1/stock-adjustments/{id}`

Get a StockAdjustment.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data`: [`StockAdjustment`](#schema-stockadjustment) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: stock_adjustment_lines, stock_adjustments |
| **Business rules** | BR-052 |
| **Requirements** | FR-035 |
| **Pages and actions** | D10 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchstockadjustmentsid"></a>
#### `PATCH /api/v1/stock-adjustments/{id}`

Edit a draft StockAdjustment.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.edit` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | [`StockAdjustmentInput`](#schema-stockadjustmentinput) |
| **Validation** | `adjustment_date`* YYYY-MM-DD; `warehouse_id`* id of a record in this company; `reason`* one of count_difference/damaged/expired/theft_or_loss/free_samples; `notes` text ≤ 65,535 (nullable); `lines`* array of AdjustmentLineInput<br>Unknown fields are rejected (422).<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.<br>BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data`: [`StockAdjustment`](#schema-stockadjustment) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `not_draft`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: stock_adjustments; read: stock_adjustment_lines |
| **Business rules** | BR-025, BR-049, BR-052 |
| **Requirements** | FR-035 |
| **Pages and actions** | D10<br>D10 · Save draft<br>D10 · Post adjustment |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deletestockadjustmentsid"></a>
#### `DELETE /api/v1/stock-adjustments/{id}`

Delete a draft StockAdjustment (number voided).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: stock_adjustment_lines, stock_adjustments |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-035 |
| **Pages and actions** | D10<br>D10 · Delete draft (NEW) |
| **Transaction / concurrency** | Single transaction. |

<a id="getstocktransfers"></a>
#### `GET /api/v1/stock-transfers`

List StockTransfer documents.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `status`, `warehouse_id` |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data[]` of [`StockTransfer`](#schema-stocktransfer) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: stock_transfer_lines, stock_transfers |
| **Business rules** | BR-052 |
| **Requirements** | FR-036 |
| **Pages and actions** | D11, D8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="poststocktransfers"></a>
#### `POST /api/v1/stock-transfers`

Create a draft StockTransfer.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / — |
| **Request body** | [`StockTransferInput`](#schema-stocktransferinput) |
| **Validation** | `from_warehouse_id`* id of a record in this company; `to_warehouse_id`* id of a record in this company; `dispatch_date` YYYY-MM-DD (nullable); `vehicle` string ≤ 60 (nullable); `driver` string ≤ 120 (nullable); `notes` text ≤ 65,535 (nullable); `lines`* array of TransferLineInput<br>Unknown fields are rejected (422).<br>BR-030: A stock transfer requires different warehouses and quantity <= available at the source; total value is unchanged; a journal is posted only if the warehouses use different inventory ledgers.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.<br>BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 201 `data`: [`StockTransfer`](#schema-stocktransfer) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `insufficient_stock`, `same_warehouse`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, stock_transfer_lines, stock_transfers; read: number_sequences, stock_balances |
| **Business rules** | BR-030, BR-006, BR-052 |
| **Requirements** | FR-036 |
| **Pages and actions** | D11<br>D11 · Save draft |
| **Transaction / concurrency** | Single transaction. |

<a id="getstocktransfersid"></a>
#### `GET /api/v1/stock-transfers/{id}`

Get a StockTransfer.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data`: [`StockTransfer`](#schema-stocktransfer) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: stock_transfer_lines, stock_transfers |
| **Business rules** | BR-052 |
| **Requirements** | FR-036 |
| **Pages and actions** | D11 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchstocktransfersid"></a>
#### `PATCH /api/v1/stock-transfers/{id}`

Edit a draft StockTransfer.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.edit` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | [`StockTransferInput`](#schema-stocktransferinput) |
| **Validation** | `from_warehouse_id`* id of a record in this company; `to_warehouse_id`* id of a record in this company; `dispatch_date` YYYY-MM-DD (nullable); `vehicle` string ≤ 60 (nullable); `driver` string ≤ 120 (nullable); `notes` text ≤ 65,535 (nullable); `lines`* array of TransferLineInput<br>Unknown fields are rejected (422).<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.<br>BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data`: [`StockTransfer`](#schema-stocktransfer) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `not_draft`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: stock_transfers; read: stock_transfer_lines |
| **Business rules** | BR-025, BR-049, BR-052 |
| **Requirements** | FR-036 |
| **Pages and actions** | D11<br>D11 · Save draft |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deletestocktransfersid"></a>
#### `DELETE /api/v1/stock-transfers/{id}`

Delete a draft StockTransfer (number voided).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: stock_transfer_lines, stock_transfers |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-036 |
| **Pages and actions** | D11<br>D11 · Cancel draft |
| **Transaction / concurrency** | Single transaction. |

<a id="poststockadjustmentsidpost"></a>
#### `POST /api/v1/stock-adjustments/{id}/post`

Post an adjustment: stock movements + value journal.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-029: A stock adjustment posts the value of the difference: a shortage debits the stock-adjustment ledger and credits inventory; a gain does the opposite.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data`: [`StockAdjustment`](#schema-stockadjustment) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `company_maintenance`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: stock_adjustments, stock_balances; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, stock_movements; update: stock_adjustments, stock_balances; read: account_mappings, accounting_periods, stock_adjustment_lines |
| **Business rules** | BR-029, BR-001, BR-004, BR-009, BR-052 |
| **Requirements** | FR-035 |
| **Pages and actions** | D10<br>D10 · Post adjustment |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="poststockadjustmentsidreverse"></a>
#### `POST /api/v1/stock-adjustments/{id}/reverse`

Reverse a posted adjustment.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.approve` — roles: Owner, Finance manager |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock. |
| **Success** | 200 `data`: [`StockAdjustment`](#schema-stockadjustment) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_reversed`, `company_maintenance`, `insufficient_stock`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, stock_adjustments, stock_balances; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, stock_movements; update: journal_entries, number_sequences, stock_adjustments, stock_balances |
| **Business rules** | BR-003, BR-004, BR-014 |
| **Requirements** | FR-035 |
| **Pages and actions** | D10<br>D10 · Reverse posted adjustment (NEW) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="poststockadjustmentscountsheet"></a>
#### `POST /api/v1/stock-adjustments/count-sheet`

Parse an uploaded count sheet into adjustment lines (no save).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | — / — |
| **Request body** | [`FileUpload`](#schema-fileupload) |
| **Validation** | `file`* string<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`CountSheetPreview`](#schema-countsheetpreview) |
| **Status codes** | 200, 400, 401, 403, 413, 415, 422, 503 |
| **Error codes** | **`company_maintenance`, `file_too_large`, `file_type_not_allowed`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: products, stock_balances |
| **Business rules** | — |
| **Requirements** | FR-035 |
| **Pages and actions** | D10<br>D10 · Import count sheet |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="poststocktransfersiddispatch"></a>
#### `POST /api/v1/stock-transfers/{id}/dispatch`

Dispatch: stock leaves the source (in transit).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-030: A stock transfer requires different warehouses and quantity <= available at the source; total value is unchanged; a journal is posted only if the warehouses use different inventory ledgers.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. |
| **Success** | 200 `data`: [`StockTransfer`](#schema-stocktransfer) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `company_maintenance`, `insufficient_stock`, `period_locked`, `same_warehouse`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, stock_balances, stock_transfers; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, stock_movements; update: number_sequences, stock_balances, stock_transfer_lines, stock_transfers; read: warehouses |
| **Business rules** | BR-030, BR-014, BR-004, BR-009 |
| **Requirements** | FR-036 |
| **Pages and actions** | D11<br>D11 · Dispatch transfer |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="poststocktransfersidreceive"></a>
#### `POST /api/v1/stock-transfers/{id}/receive`

Receive at destination (optionally with received quantities).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.create` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | [`TransferReceive`](#schema-transferreceive) |
| **Validation** | `received_date`* YYYY-MM-DD; `lines` array of TransferLineInput<br>Unknown fields are rejected (422).<br>BR-030: A stock transfer requires different warehouses and quantity <= available at the source; total value is unchanged; a journal is posted only if the warehouses use different inventory ledgers.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. |
| **Success** | 200 `data`: [`StockTransfer`](#schema-stocktransfer) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `company_maintenance`, `insufficient_stock`, `period_locked`, `same_warehouse`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, stock_balances, stock_transfers; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, stock_movements; update: number_sequences, stock_balances, stock_transfer_lines, stock_transfers |
| **Business rules** | BR-030, BR-004, BR-009 |
| **Requirements** | FR-036 |
| **Pages and actions** | D11, D8<br>D11 · Confirm receipt (destination) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="poststocktransfersidcancel"></a>
#### `POST /api/v1/stock-transfers/{id}/cancel`

Cancel a draft or dispatched transfer (dispatched stock returns to source).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.edit` — roles: Owner, Store keeper |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited. |
| **Success** | 200 `data`: [`StockTransfer`](#schema-stocktransfer) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_reversed`, `company_maintenance`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, stock_balances, stock_transfers; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, stock_movements; update: journal_entries, number_sequences, stock_balances, stock_transfers |
| **Business rules** | BR-003 |
| **Requirements** | FR-036 |
| **Pages and actions** | D11<br>D11 · Cancel draft |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

### Reports


<a id="getreportsinventorysummary"></a>
#### `GET /api/v1/reports/inventory/summary`

Inventory summary (value by group, flow, movers, attention).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: sales_invoice_lines, stock_balances, stock_groups, stock_movements |
| **Business rules** | BR-052 |
| **Requirements** | FR-037 |
| **Pages and actions** | D14 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportsinventorylowstock"></a>
#### `GET /api/v1/reports/inventory/low-stock`

Items at or below reorder level with suggested quantities.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view` — roles: Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer |
| **Path / query params** | — / `warehouse_id`, `status`, `group_by_supplier` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: parties, product_warehouse_settings, products, stock_balances |
| **Business rules** | — |
| **Requirements** | FR-037 |
| **Pages and actions** | D12 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportsinventoryvaluation"></a>
#### `GET /api/v1/reports/inventory/valuation`

Stock valuation at weighted average cost as of a date.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `inventory.view_costs` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `as_of`, `warehouse_id`, `level`, `format` |
| **Request body** | — |
| **Validation** | BR-016: Cost of goods sold is quantity x the product's moving weighted average cost in the issuing warehouse at posting; the unit cost is stored on the invoice line. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: products, stock_groups, stock_movements |
| **Business rules** | BR-016 |
| **Requirements** | FR-037 |
| **Pages and actions** | D13, J1<br>D13 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportstrialbalance"></a>
#### `GET /api/v1/reports/trial-balance`

Trial balance (JSON; xlsx/pdf/csv via format). Reads posted lines only. `format=xlsx|pdf|csv` requires reports.export and returns the file; when the export exceeds 5,000 rows the answer is 202 with an ExportJob to poll.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `as_of`, `level`, `format` |
| **Request body** | — |
| **Validation** | BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `unbalanced_entry`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_entries, journal_lines, ledgers |
| **Business rules** | BR-005, BR-001 |
| **Requirements** | FR-076 |
| **Pages and actions** | H15, H1, J1<br>H15 · Export (xlsx, pdf, csv) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportsprofitandloss"></a>
#### `GET /api/v1/reports/profit-and-loss`

Profit and loss (JSON; xlsx/pdf/csv via format). Reads posted lines only. `format=xlsx|pdf|csv` requires reports.export and returns the file; when the export exceeds 5,000 rows the answer is 202 with an ExportJob to poll.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `compare`, `detail`, `branch_id`, `format` |
| **Request body** | — |
| **Validation** | BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `unbalanced_entry`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_entries, journal_lines, ledgers |
| **Business rules** | BR-005, BR-001 |
| **Requirements** | FR-076 |
| **Pages and actions** | H16, J1<br>H16 · Export (xlsx, pdf, csv) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportsbalancesheet"></a>
#### `GET /api/v1/reports/balance-sheet`

Balance sheet (JSON; xlsx/pdf/csv via format). Reads posted lines only. `format=xlsx|pdf|csv` requires reports.export and returns the file; when the export exceeds 5,000 rows the answer is 202 with an ExportJob to poll.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `as_of`, `compare`, `detail`, `format` |
| **Request body** | — |
| **Validation** | BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `unbalanced_entry`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_entries, journal_lines, ledgers |
| **Business rules** | BR-005, BR-001 |
| **Requirements** | FR-076 |
| **Pages and actions** | H17, J1<br>H17 · Export (xlsx, pdf, csv) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportscashflow"></a>
#### `GET /api/v1/reports/cash-flow`

Cash flow (indirect) (JSON; xlsx/pdf/csv via format). Reads posted lines only. `format=xlsx|pdf|csv` requires reports.export and returns the file; when the export exceeds 5,000 rows the answer is 202 with an ExportJob to poll.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `format` |
| **Request body** | — |
| **Validation** | BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `unbalanced_entry`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_entries, journal_lines, ledgers |
| **Business rules** | BR-005, BR-001 |
| **Requirements** | FR-076 |
| **Pages and actions** | H18, J1<br>H18 · Export (xlsx, pdf, csv) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportsgeneralledger"></a>
#### `GET /api/v1/reports/general-ledger`

General ledger (JSON; xlsx/pdf/csv via format). Reads posted lines only. `format=xlsx|pdf|csv` requires reports.export and returns the file; when the export exceeds 5,000 rows the answer is 202 with an ExportJob to poll.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `ledger_ids`, `group_by`, `format` |
| **Request body** | — |
| **Validation** | BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `unbalanced_entry`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_entries, journal_lines, ledgers |
| **Business rules** | BR-005, BR-001 |
| **Requirements** | FR-076 |
| **Pages and actions** | H19, J1<br>H19 · Export (xlsx, pdf, csv) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportsreceivablesaging"></a>
#### `GET /api/v1/reports/receivables-aging`

Receivables aging (JSON; xlsx/pdf/csv via format). Reads posted lines only. `format=xlsx|pdf|csv` requires reports.export and returns the file; when the export exceeds 5,000 rows the answer is 202 with an ExportJob to poll.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `as_of`, `q`, `overdue_only`, `format` |
| **Request body** | — |
| **Validation** | BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `unbalanced_entry`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_entries, journal_lines, ledgers, parties, purchase_bills, sales_invoices |
| **Business rules** | BR-005, BR-001 |
| **Requirements** | FR-076 |
| **Pages and actions** | J6<br>J6 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportspayablesaging"></a>
#### `GET /api/v1/reports/payables-aging`

Payables aging (JSON; xlsx/pdf/csv via format). Reads posted lines only. `format=xlsx|pdf|csv` requires reports.export and returns the file; when the export exceeds 5,000 rows the answer is 202 with an ExportJob to poll.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `as_of`, `q`, `overdue_only`, `format` |
| **Request body** | — |
| **Validation** | BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `unbalanced_entry`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_entries, journal_lines, ledgers, parties, purchase_bills, sales_invoices |
| **Business rules** | BR-005, BR-001 |
| **Requirements** | FR-076 |
| **Pages and actions** | J7<br>J7 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportssales"></a>
#### `GET /api/v1/reports/sales`

Sales report. `format=xlsx|pdf|csv` requires reports.export (202 + ExportJob above 5,000 rows).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `group_by`, `branch_id`, `party_id`, `reason`, `format` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines, parties, product_categories, purchase_bill_lines, purchase_bills, purchase_returns, sales_invoice_lines, sales_invoices, sales_returns |
| **Business rules** | — |
| **Requirements** | FR-047 |
| **Pages and actions** | E11, J1<br>E11 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportssalesreturns"></a>
#### `GET /api/v1/reports/sales-returns`

Sales return report. `format=xlsx|pdf|csv` requires reports.export (202 + ExportJob above 5,000 rows).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `group_by`, `branch_id`, `party_id`, `reason`, `format` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines, parties, product_categories, purchase_bill_lines, purchase_bills, purchase_returns, sales_invoice_lines, sales_invoices, sales_returns |
| **Business rules** | — |
| **Requirements** | FR-047 |
| **Pages and actions** | E12<br>E12 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportspurchases"></a>
#### `GET /api/v1/reports/purchases`

Purchase report. `format=xlsx|pdf|csv` requires reports.export (202 + ExportJob above 5,000 rows).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `group_by`, `branch_id`, `party_id`, `reason`, `format` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines, parties, product_categories, purchase_bill_lines, purchase_bills, purchase_returns, sales_invoice_lines, sales_invoices, sales_returns |
| **Business rules** | — |
| **Requirements** | FR-055 |
| **Pages and actions** | F9, J1<br>F9 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportspurchasereturns"></a>
#### `GET /api/v1/reports/purchase-returns`

Purchase return report. `format=xlsx|pdf|csv` requires reports.export (202 + ExportJob above 5,000 rows).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `group_by`, `branch_id`, `party_id`, `reason`, `format` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines, parties, product_categories, purchase_bill_lines, purchase_bills, purchase_returns, sales_invoice_lines, sales_invoices, sales_returns |
| **Business rules** | — |
| **Requirements** | FR-055 |
| **Pages and actions** | F10<br>F10 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportsincome"></a>
#### `GET /api/v1/reports/income`

Income report. `format=xlsx|pdf|csv` requires reports.export (202 + ExportJob above 5,000 rows).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `group_by`, `branch_id`, `party_id`, `reason`, `format` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines |
| **Business rules** | — |
| **Requirements** | FR-068 |
| **Pages and actions** | I8, J1<br>I8 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportsexpenses"></a>
#### `GET /api/v1/reports/expenses`

Expense report. `format=xlsx|pdf|csv` requires reports.export (202 + ExportJob above 5,000 rows).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `reports.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `date_from`, `date_to`, `group_by`, `branch_id`, `party_id`, `reason`, `format` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 202, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines |
| **Business rules** | — |
| **Requirements** | FR-068 |
| **Pages and actions** | I9, J1<br>I9 · Export |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getreportslcreport"></a>
#### `GET /api/v1/reports/lc/{report}`

LC reports: register, status, outstanding, expiry, docs, amendments, charges, cost.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `report` / `date_from`, `date_to`, `bank_account_id`, `segment`, `format` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) · `format=xlsx\|pdf\|csv` returns the file; 202 + ExportJob above 5,000 rows |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: landed_cost_allocations, lc_amendments, lc_charges, lc_documents, lc_payments, letters_of_credit |
| **Business rules** | — |
| **Requirements** | FR-099 |
| **Pages and actions** | M8<br>M8 · Export (xlsx, pdf) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

### Sales


<a id="getsalesoverview"></a>
#### `GET /api/v1/sales/overview`

Sales overview tiles, months, status breakdown, recent, top products.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `period` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: quotations, sales_invoice_lines, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-040 |
| **Pages and actions** | E1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postsalesinvoicescalculate"></a>
#### `POST /api/v1/sales-invoices/calculate`

Authoritative totals for an unsaved invoice (no write). Returns line amounts, totals, amount in words, party balance after, credit-limit and stock warnings. Debounced by the UI (300 ms). Also used by quotations.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`SalesInvoiceInput`](#schema-salesinvoiceinput) |
| **Validation** | `party_id`* id of a record in this company; `invoice_date`* YYYY-MM-DD; `due_date` YYYY-MM-DD (nullable); `payment_term_id` id of a record in this company (nullable); `warehouse_id`* id of a record in this company; `customer_po_ref` string ≤ 60 (nullable); `quotation_id` id of a record in this company (nullable); `branch_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `lines`* array of SalesLineInput<br>Unknown fields are rejected (422).<br>BR-010: Money uses decimal arithmetic only. Line amounts are rounded half-up to 2 decimals; document totals are the sum of rounded lines; the server's calculation is authoritative.<br>BR-011: Line gross = quantity x unit price; discount = gross x discount% ; tax = (gross - discount) x tax rate; line total = gross - discount + tax.<br>BR-012: Invoice rounding (company setting: none, nearest 1, nearest 5 taka) is posted to the mapped rounding ledger.<br>BR-015: Exceeding a customer's credit limit or invoicing a customer with overdue invoices shows a warning but does not block.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock. |
| **Success** | 200 `data`: [`DocumentCalculation`](#schema-documentcalculation) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `rounding_ledger_missing`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: company_settings, parties, products, stock_balances, tax_rules |
| **Business rules** | BR-010, BR-011, BR-012, BR-015, BR-014 |
| **Requirements** | FR-041 |
| **Pages and actions** | E3, E6, E10, E5<br>E3 · Add line (Ctrl Enter)<br>E3 · Remove line<br>E5 · Duplicate |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getsalesinvoices"></a>
#### `GET /api/v1/sales-invoices`

List invoices.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `status`, `payment_status`, `overdue`, `party_id` |
| **Request body** | — |
| **Validation** | BR-037: Every read and write is scoped to the active company; any reference to another company's record is rejected as not found.<br>BR-038: When a membership has branch restrictions, the user can only see and create records of those branches. |
| **Success** | 200 `data[]` of [`SalesInvoice`](#schema-salesinvoice) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`branch_restricted`, `company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: parties, sales_invoices |
| **Business rules** | BR-037, BR-038 |
| **Requirements** | FR-040 |
| **Pages and actions** | E2, E1, B5 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postsalesinvoices"></a>
#### `POST /api/v1/sales-invoices`

Create a draft invoice (number reserved).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`SalesInvoiceInput`](#schema-salesinvoiceinput) |
| **Validation** | `party_id`* id of a record in this company; `invoice_date`* YYYY-MM-DD; `due_date` YYYY-MM-DD (nullable); `payment_term_id` id of a record in this company (nullable); `warehouse_id`* id of a record in this company; `customer_po_ref` string ≤ 60 (nullable); `quotation_id` id of a record in this company (nullable); `branch_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `lines`* array of SalesLineInput<br>Unknown fields are rejected (422).<br>BR-013: A sales invoice needs an active customer, a warehouse, at least one line with quantity > 0 and price >= 0, and a due date on or after the invoice date.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.<br>BR-010: Money uses decimal arithmetic only. Line amounts are rounded half-up to 2 decimals; document totals are the sum of rounded lines; the server's calculation is authoritative.<br>BR-011: Line gross = quantity x unit price; discount = gross x discount% ; tax = (gross - discount) x tax rate; line total = gross - discount + tax.<br>BR-055: Due date defaults to document date + the party's payment-term days and may be edited. |
| **Success** | 201 `data`: [`SalesInvoice`](#schema-salesinvoice) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `inactive_reference`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, sales_invoice_lines, sales_invoices; read: number_sequences, parties, products |
| **Business rules** | BR-013, BR-006, BR-010, BR-011, BR-055 |
| **Requirements** | FR-041 |
| **Pages and actions** | E3, E10<br>E3 · Save draft<br>E3 · Preview<br>E3 · Print<br>E3 · Save invoice (Ctrl S) > Post dialog |
| **Transaction / concurrency** | Locks number_sequences row. |

<a id="getsalesinvoicesid"></a>
#### `GET /api/v1/sales-invoices/{id}`

Invoice with lines, payments (allocations), ledger entries and delivery.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`SalesInvoice`](#schema-salesinvoice) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: bill_allocations, journal_entries, journal_lines, sales_invoice_lines, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-042 |
| **Pages and actions** | E4, E5, E6 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putsalesinvoicesid"></a>
#### `PUT /api/v1/sales-invoices/{id}`

Replace a draft invoice (header and lines).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`SalesInvoiceInput`](#schema-salesinvoiceinput) |
| **Validation** | `party_id`* id of a record in this company; `invoice_date`* YYYY-MM-DD; `due_date` YYYY-MM-DD (nullable); `payment_term_id` id of a record in this company (nullable); `warehouse_id`* id of a record in this company; `customer_po_ref` string ≤ 60 (nullable); `quotation_id` id of a record in this company (nullable); `branch_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `lines`* array of SalesLineInput<br>Unknown fields are rejected (422).<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-013: A sales invoice needs an active customer, a warehouse, at least one line with quantity > 0 and price >= 0, and a due date on or after the invoice date.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. |
| **Success** | 200 `data`: [`SalesInvoice`](#schema-salesinvoice) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `inactive_reference`, `not_draft`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs, sales_invoice_lines; update: sales_invoices; delete: sales_invoice_lines |
| **Business rules** | BR-025, BR-013, BR-049 |
| **Requirements** | FR-041 |
| **Pages and actions** | E6<br>E6 · Save draft<br>E6 · Preview<br>E6 · Post invoice |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="patchsalesinvoicesiddelivery"></a>
#### `PATCH /api/v1/sales-invoices/{id}/delivery`

Record challan, vehicle and delivery time (allowed after posting).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`DeliveryUpdate`](#schema-deliveryupdate) |
| **Validation** | `challan_no` string ≤ 30 (nullable); `vehicle_no` string ≤ 40 (nullable); `delivered_at` ISO 8601 (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`SalesInvoice`](#schema-salesinvoice) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-042 |
| **Pages and actions** | E5<br>E5 · Edit delivery details (NEW) |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deletesalesinvoicesid"></a>
#### `DELETE /api/v1/sales-invoices/{id}`

Delete a draft (number voided, never reused).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: sales_invoice_lines, sales_invoices |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-046 |
| **Pages and actions** | Sal-DlgDelete, E6<br>Sal-DlgDelete · Confirm |
| **Transaction / concurrency** | Single transaction. |

<a id="postsalesinvoicesidpost"></a>
#### `POST /api/v1/sales-invoices/{id}/post`

Post: stock out at average cost, journal entry, customer balance. With `receipt` in the body (cash sale), the invoice and its RV are posted in the same transaction and the invoice is settled (R-28).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`PostRequest`](#schema-postrequest) |
| **Validation** | `confirm_backdated` boolean; `acknowledge_warnings` array of str; `receipt` CashReceiptInput (nullable)<br>Unknown fields are rejected (422).<br>BR-013: A sales invoice needs an active customer, a warehouse, at least one line with quantity > 0 and price >= 0, and a due date on or after the invoice date.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock.<br>BR-016: Cost of goods sold is quantity x the product's moving weighted average cost in the issuing warehouse at posting; the unit cost is stored on the invoice line.<br>BR-064: Posting a sales invoice creates: Dr customer (total), Cr each line's income ledger (net), Cr tax ledgers (tax), Cr/Dr rounding; and for stocked lines Dr COGS, Cr warehouse inventory (cost).<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-012: Invoice rounding (company setting: none, nearest 1, nearest 5 taka) is posted to the mapped rounding ledger.<br>BR-053: A voucher dated more than the configured number of days in the past requires explicit confirmation.<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger. |
| **Success** | 200 `data`: [`SalesInvoice`](#schema-salesinvoice) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `already_posted`, `backdated_confirmation_required`, `company_maintenance`, `inactive_reference`, `insufficient_stock`, `mapping_missing`, `period_locked`, `rounding_ledger_missing`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, sales_invoices, stock_balances; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines, payment_details, stock_movements; update: number_sequences, sales_invoice_lines, sales_invoices, stock_balances; read: account_mappings, accounting_periods, money_accounts |
| **Business rules** | BR-013, BR-014, BR-016, BR-064, BR-001, BR-004, BR-009, BR-012, BR-053, BR-065, BR-020 |
| **Requirements** | FR-041 |
| **Pages and actions** | Sal-DlgPost, E3, E4, E6<br>Sal-DlgPost · Confirm |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postsalesinvoicesbulkpost"></a>
#### `POST /api/v1/sales-invoices/bulk-post`

Post several drafts (each in its own transaction; per-item results).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`BulkIds`](#schema-bulkids) |
| **Validation** | `ids`* array of id<br>Unknown fields are rejected (422).<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`BulkResult`](#schema-bulkresult) |
| **Status codes** | 200, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`already_posted`, `company_maintenance`, `insufficient_stock`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, sales_invoices, stock_balances; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, stock_movements; update: number_sequences, sales_invoice_lines, sales_invoices, stock_balances; read: account_mappings, accounting_periods |
| **Business rules** | BR-009, BR-014, BR-001 |
| **Requirements** | FR-041 |
| **Pages and actions** | L6, E2<br>E2 · Post selected drafts (bulk, L6) |
| **Transaction / concurrency** | Each invoice posts in its own transaction; failures are reported, not rolled across. |

<a id="postsalesinvoicesidcancel"></a>
#### `POST /api/v1/sales-invoices/{id}/cancel`

Cancel a posted invoice (reversal + stock back; payments become advance).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`CancelRequest`](#schema-cancelrequest) |
| **Validation** | `reason`* one of customer_cancelled/entered_by_mistake/wrong_party/duplicate/other; `note` string ≤ 255 (nullable); `cancellation_date` YYYY-MM-DD (nullable)<br>Unknown fields are rejected (422).<br>BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances.<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected. |
| **Success** | 200 `data`: [`SalesInvoice`](#schema-salesinvoice) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_reversed`, `company_maintenance`, `invalid_transition`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, sales_invoices, stock_balances; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines, stock_movements; update: bill_allocations, journal_entries, number_sequences, sales_invoices, stock_balances |
| **Business rules** | BR-024, BR-003, BR-004 |
| **Requirements** | FR-046 |
| **Pages and actions** | Sal-DlgCancel, E5<br>Sal-DlgCancel · Confirm |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getsalesinvoicesidpdf"></a>
#### `GET /api/v1/sales-invoices/{id}/pdf`

Invoice PDF per invoice design (also challan with ?variant=challan).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / `variant` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: company_settings, money_accounts, sales_invoice_lines, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-042 |
| **Pages and actions** | E4, E5, E2, E3<br>E2 · Print (row)<br>E3 · Print<br>E4 · PDF<br>E4 · Print<br>E5 · Print<br>E5 · Download PDF |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postsalesinvoicesidsend"></a>
#### `POST /api/v1/sales-invoices/{id}/send`

E-mail the invoice PDF to the customer (queued).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`EmailRequest`](#schema-emailrequest) |
| **Validation** | `to`* array of email; `cc` array of email; `message` text ≤ 65,535 (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 202 — · 202 when above the approval limit |
| **Status codes** | 202, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: sales_invoices; insert: audit_logs, idempotency_keys; update: sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-042 |
| **Pages and actions** | E5<br>E5 · E-mail to customer (NEW) |
| **Transaction / concurrency** | Single transaction. |

<a id="postcustomerreceipts"></a>
#### `POST /api/v1/customer-receipts`

Record a customer payment with allocations (creates RV). Receipts never need approval (BR-007 covers payments and journals only, R-22).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`PartyPaymentInput`](#schema-partypaymentinput) |
| **Validation** | `party_id`* id of a record in this company; `date`* YYYY-MM-DD; `amount`* decimal string, 2 dp; `money_account_id`* id of a record in this company; `method`* one of cash/cheque/bank_transfer/beftn/rtgs/npsb/bkash/nagad/card; `cheque_number` string ≤ 20 (nullable); `cheque_date` YYYY-MM-DD (nullable); `drawn_on` string ≤ 120 (nullable); `transaction_reference` string ≤ 60 (nullable); `bank_charge` decimal string, 2 dp; `exchange_rate` decimal string, 6 dp (nullable); `narration` string ≤ 1000 (nullable); `allocations` array of AllocationInput; `auto_allocate` boolean; `confirm_backdated` boolean<br>Unknown fields are rejected (422).<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-021: Allocations, notes and returns may reference only documents of the same party and company.<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-053: A voucher dated more than the configured number of days in the past requires explicit confirmation.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. |
| **Success** | 201 `data`: [`Voucher`](#schema-voucher) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `already_posted`, `backdated_confirmation_required`, `company_maintenance`, `foreign_document`, `mapping_missing`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences, sales_invoices; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines, payment_details; update: number_sequences, sales_invoices; read: money_accounts |
| **Business rules** | BR-020, BR-021, BR-065, BR-001, BR-004, BR-053, BR-009 |
| **Requirements** | FR-043 |
| **Pages and actions** | E7, E5, B4<br>E7 · Save receipt (confirm dialog) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. Each allocated invoice row is locked; amount_settled/balance_due/payment_status updated. |

<a id="getcustomerreceipts"></a>
#### `GET /api/v1/customer-receipts`

List customer receipts (RV) with party, account, method and allocation status. _Contract change: R-07._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `party_id`, `money_account_id`, `unapplied` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Voucher`](#schema-voucher) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: bill_allocations, journal_entries, money_accounts, parties, payment_details |
| **Business rules** | — |
| **Requirements** | FR-043 |
| **Pages and actions** | E14 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getcustomerreceiptsid"></a>
#### `GET /api/v1/customer-receipts/{id}`

Get a customer receipt with its allocations. Lets sales users see receipts without accounting.view. _Contract change: R-07._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Voucher`](#schema-voucher) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: bill_allocations, journal_entries, journal_lines, payment_details, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-043 |
| **Pages and actions** | E14, E7<br>E14 · Open (drawer) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getcustomerreceiptsidpdf"></a>
#### `GET /api/v1/customer-receipts/{id}/pdf`

Printable receipt voucher. _Contract change: C-01._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: companies, journal_entries, journal_lines, payment_details |
| **Business rules** | — |
| **Requirements** | FR-043 |
| **Pages and actions** | E7, E14<br>E7 · Print voucher<br>E14 · Print voucher (row) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postallocations"></a>
#### `POST /api/v1/allocations`

Apply an advance, unapplied receipt/payment or open credit/debit note to an invoice or bill. _Contract change: R-08._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — party is a customer → `sales.create`; party is a supplier → `purchases.create` |
| **Path / query params** | — / — |
| **Request body** | [`AllocationCreate`](#schema-allocationcreate) |
| **Validation** | `journal_line_id`* id of a record in this company; `document_type`* one of sales_invoice/purchase_bill; `document_id`* id of a record in this company; `amount`* decimal string, 2 dp; `allocated_on` YYYY-MM-DD (nullable)<br>Unknown fields are rejected (422).<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-021: Allocations, notes and returns may reference only documents of the same party and company.<br>BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected. |
| **Success** | 201 `data`: [`Allocation`](#schema-allocation) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `company_maintenance`, `foreign_document`, `period_locked`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: journal_lines, payment_details, purchase_bills, sales_invoices; insert: audit_logs, bill_allocations, idempotency_keys; update: payment_details, purchase_bills, sales_invoices; read: credit_notes, debit_notes |
| **Business rules** | BR-020, BR-021, BR-023, BR-004 |
| **Requirements** | FR-043, FR-052 |
| **Pages and actions** | B4, C4, E5, F4<br>B4 · Apply advance or credit (NEW, R-08)<br>C4 · Apply advance or credit (NEW, R-08)<br>E5 · Apply advance or credit (NEW, R-08)<br>F4 · Apply advance or credit (NEW, R-08) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. The settling line, payment detail and document rows are locked. |

<a id="deleteallocationsid"></a>
#### `DELETE /api/v1/allocations/{id}`

Remove an allocation (sets reversed_at; balances restored). _Contract change: R-08._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — party is a customer → `sales.edit`; party is a supplier → `purchases.edit` |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `company_maintenance`, `period_locked`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: bill_allocations, purchase_bills, sales_invoices; insert: audit_logs; update: bill_allocations, payment_details, purchase_bills, sales_invoices |
| **Business rules** | BR-020, BR-004 |
| **Requirements** | FR-043, FR-052 |
| **Pages and actions** | B4, C4, E5, F4<br>B4 · Remove allocation (NEW, R-08)<br>C4 · Remove allocation (NEW, R-08)<br>E5 · Remove allocation (NEW, R-08)<br>F4 · Remove allocation (NEW, R-08) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getquotations"></a>
#### `GET /api/v1/quotations`

List quotations.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `status`, `party_id` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Quotation`](#schema-quotation) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: parties, quotations |
| **Business rules** | — |
| **Requirements** | FR-045 |
| **Pages and actions** | E10, E1, E13 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postquotations"></a>
#### `POST /api/v1/quotations`

Create a quotation.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`QuotationInput`](#schema-quotationinput) |
| **Validation** | `party_id`* id of a record in this company; `quotation_date`* YYYY-MM-DD; `valid_until`* YYYY-MM-DD; `warehouse_id` id of a record in this company (nullable); `payment_term_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `lines`* array of SalesLineInput<br>Unknown fields are rejected (422).<br>BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 201 `data`: [`Quotation`](#schema-quotation) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, quotation_lines, quotations; read: number_sequences |
| **Business rules** | BR-056, BR-006 |
| **Requirements** | FR-045 |
| **Pages and actions** | E10<br>E10 · Save draft |
| **Transaction / concurrency** | Single transaction. |

<a id="getquotationsid"></a>
#### `GET /api/v1/quotations/{id}`

Get a quotation.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Quotation`](#schema-quotation) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: quotation_lines, quotations |
| **Business rules** | — |
| **Requirements** | FR-045 |
| **Pages and actions** | E10 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putquotationsid"></a>
#### `PUT /api/v1/quotations/{id}`

Replace a draft or sent quotation.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`QuotationInput`](#schema-quotationinput) |
| **Validation** | `party_id`* id of a record in this company; `quotation_date`* YYYY-MM-DD; `valid_until`* YYYY-MM-DD; `warehouse_id` id of a record in this company (nullable); `payment_term_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `lines`* array of SalesLineInput<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Quotation`](#schema-quotation) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs, quotation_lines; update: quotations; delete: quotation_lines |
| **Business rules** | — |
| **Requirements** | FR-045 |
| **Pages and actions** | E10<br>E10 · Save draft |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postquotationsidsend"></a>
#### `POST /api/v1/quotations/{id}/send`

Mark sent and e-mail it.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`EmailRequest`](#schema-emailrequest) |
| **Validation** | `to`* array of email; `cc` array of email; `message` text ≤ 65,535 (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Quotation`](#schema-quotation) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: quotations; insert: audit_logs; update: quotations |
| **Business rules** | — |
| **Requirements** | FR-045 |
| **Pages and actions** | E10<br>E10 · Send quotation |
| **Transaction / concurrency** | Single transaction. |

<a id="postquotationsidconvert"></a>
#### `POST /api/v1/quotations/{id}/convert`

Convert an accepted quotation into a draft invoice.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities. |
| **Success** | 201 `data`: [`SalesInvoice`](#schema-salesinvoice) |
| **Status codes** | 201, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences, quotations; insert: audit_logs, idempotency_keys, sales_invoice_lines, sales_invoices; update: number_sequences, quotations |
| **Business rules** | BR-056 |
| **Requirements** | FR-045 |
| **Pages and actions** | E10, E13<br>E10 · Convert to invoice (accepted)<br>E13 · Convert to invoice (row, accepted) |
| **Transaction / concurrency** | Single transaction. |

<a id="postquotationsidstatus"></a>
#### `POST /api/v1/quotations/{id}/status`

Set accepted, declined or expired.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`StatusChange`](#schema-statuschange) |
| **Validation** | `status`* one of accepted/declined/expired/closed/cancelled; `reason` string ≤ 255 (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Quotation`](#schema-quotation) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: quotations; insert: audit_logs; update: quotations |
| **Business rules** | — |
| **Requirements** | FR-045 |
| **Pages and actions** | E10, E13<br>E13 · Mark accepted / declined / expired (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="deletequotationsid"></a>
#### `DELETE /api/v1/quotations/{id}`

Delete a draft quotation (number voided and audited). _Contract change: C-02._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: quotation_lines, quotations |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-045 |
| **Pages and actions** | E13<br>E13 · Delete draft (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="getquotationsidpdf"></a>
#### `GET /api/v1/quotations/{id}/pdf`

Printable quotation (also attached when sent). _Contract change: C-01._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: companies, company_settings, parties, quotation_lines, quotations |
| **Business rules** | — |
| **Requirements** | FR-045 |
| **Pages and actions** | E10, E13<br>E10 · Preview<br>E13 · Print (row) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getsalesreturns"></a>
#### `GET /api/v1/sales-returns`

List sales returns.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `party_id`, `reason` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Return`](#schema-return) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: credit_notes, sales_returns |
| **Business rules** | — |
| **Requirements** | FR-047 |
| **Pages and actions** | E8, E15 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postsalesreturns"></a>
#### `POST /api/v1/sales-returns`

Create a draft return against an invoice.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`ReturnInput`](#schema-returninput) |
| **Validation** | `source_document_id`* id of a record in this company; `return_date`* YYYY-MM-DD; `warehouse_id`* id of a record in this company; `stock_action`* one of restock/write_off/send_back/already_disposed; `usage` one of apply/credit/refund; `apply_to_document_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `lines`* array of ReturnLineInput<br>Unknown fields are rejected (422).<br>BR-022: Returned quantity per line <= original quantity - quantity already returned.<br>BR-021: Allocations, notes and returns may reference only documents of the same party and company.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 201 `data`: [`Return`](#schema-return) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `foreign_document`, `return_qty_exceeds`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, sales_return_lines, sales_returns; read: number_sequences, sales_invoice_lines |
| **Business rules** | BR-022, BR-021, BR-006 |
| **Requirements** | FR-044 |
| **Pages and actions** | E8<br>E8 · Save draft<br>E8 · Save return and create credit note |
| **Transaction / concurrency** | Single transaction. |

<a id="getsalesreturnsid"></a>
#### `GET /api/v1/sales-returns/{id}`

Get a return.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Return`](#schema-return) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: sales_return_lines, sales_returns |
| **Business rules** | — |
| **Requirements** | FR-044 |
| **Pages and actions** | E8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putsalesreturnsid"></a>
#### `PUT /api/v1/sales-returns/{id}`

Replace a draft return.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`ReturnInput`](#schema-returninput) |
| **Validation** | `source_document_id`* id of a record in this company; `return_date`* YYYY-MM-DD; `warehouse_id`* id of a record in this company; `stock_action`* one of restock/write_off/send_back/already_disposed; `usage` one of apply/credit/refund; `apply_to_document_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `lines`* array of ReturnLineInput<br>Unknown fields are rejected (422).<br>BR-022: Returned quantity per line <= original quantity - quantity already returned. |
| **Success** | 200 `data`: [`Return`](#schema-return) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `return_qty_exceeds`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs, sales_return_lines; update: sales_returns; delete: sales_return_lines |
| **Business rules** | BR-022 |
| **Requirements** | FR-044 |
| **Pages and actions** | E8<br>E8 · Save draft<br>E8 · Save return and create credit note |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postsalesreturnsidpost"></a>
#### `POST /api/v1/sales-returns/{id}/post`

Post the return (stock back if restock) and create a draft credit note.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-022: Returned quantity per line <= original quantity - quantity already returned.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-017: On every stock-in to a warehouse, that warehouse's average cost = (warehouse value + incoming value) / (warehouse quantity + incoming quantity); when current quantity <= 0 the incoming unit cost becomes the average. |
| **Success** | 200 `data`: [`Return`](#schema-return) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `company_maintenance`, `period_locked`, `return_qty_exceeds`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, sales_returns, stock_balances; insert: audit_logs, credit_notes, idempotency_keys, journal_entries, journal_lines, stock_movements; update: number_sequences, sales_invoice_lines, sales_return_lines, sales_returns, stock_balances |
| **Business rules** | BR-022, BR-001, BR-004, BR-009, BR-017 |
| **Requirements** | FR-044 |
| **Pages and actions** | E8<br>E8 · Save return and create credit note |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="deletesalesreturnsid"></a>
#### `DELETE /api/v1/sales-returns/{id}`

Delete a draft sales return. _Contract change: C-02._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: sales_return_lines, sales_returns |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-044 |
| **Pages and actions** | E15<br>E15 · Delete draft (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="postsalesreturnsidcancel"></a>
#### `POST /api/v1/sales-returns/{id}/cancel`

Cancel a posted return: mirror stock entry; its credit note is cancelled if unapplied. _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances. |
| **Success** | 200 `data`: [`Return`](#schema-return) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `already_reversed`, `company_maintenance`, `invalid_transition`, `note_applied`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, sales_returns, stock_balances; insert: audit_logs, credit_notes, idempotency_keys, journal_entries, journal_lines, stock_movements; update: journal_entries, number_sequences, sales_invoice_lines, sales_return_lines, sales_returns, stock_balances |
| **Business rules** | BR-003, BR-004, BR-009, BR-024 |
| **Requirements** | FR-044 |
| **Pages and actions** | E15<br>E15 · Cancel posted (row, dialog) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getcreditnotesid"></a>
#### `GET /api/v1/credit-notes/{id}`

Get a credit note.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Note`](#schema-note) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: credit_notes |
| **Business rules** | — |
| **Requirements** | FR-044 |
| **Pages and actions** | E9 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putcreditnotesid"></a>
#### `PUT /api/v1/credit-notes/{id}`

Edit a draft credit note (reason, amount, usage).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`NoteInput`](#schema-noteinput) |
| **Validation** | `party_id`* id of a record in this company; `source_document_id` id of a record in this company (nullable); `return_id` id of a record in this company (nullable); `note_date`* YYYY-MM-DD; `reason`* string ≤ 30; `amount`* decimal string, 2 dp; `usage`* one of apply/credit/refund; `apply_to_document_id` id of a record in this company (nullable); `refund` RefundInput (nullable)<br>Unknown fields are rejected (422).<br>BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note). |
| **Success** | 200 `data`: [`Note`](#schema-note) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: credit_notes |
| **Business rules** | BR-023 |
| **Requirements** | FR-044 |
| **Pages and actions** | E9<br>E9 · Post credit note |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postcreditnotes"></a>
#### `POST /api/v1/credit-notes`

Create a credit note without a return (price correction, goodwill).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | — / — |
| **Request body** | [`NoteInput`](#schema-noteinput) |
| **Validation** | `party_id`* id of a record in this company; `source_document_id` id of a record in this company (nullable); `return_id` id of a record in this company (nullable); `note_date`* YYYY-MM-DD; `reason`* string ≤ 30; `amount`* decimal string, 2 dp; `usage`* one of apply/credit/refund; `apply_to_document_id` id of a record in this company (nullable); `refund` RefundInput (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Note`](#schema-note) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, credit_notes, idempotency_keys; read: number_sequences |
| **Business rules** | — |
| **Requirements** | FR-044 |
| **Pages and actions** | E9, E5<br>E9 · Post credit note |
| **Transaction / concurrency** | Single transaction. |

<a id="postcreditnotesidpost"></a>
#### `POST /api/v1/credit-notes/{id}/post`

Post the credit note and apply it per usage.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.create` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger. |
| **Success** | 200 `data`: [`Note`](#schema-note) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `already_posted`, `company_maintenance`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: credit_notes, number_sequences, sales_invoices; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines; update: credit_notes, number_sequences, sales_invoices; read: account_mappings |
| **Business rules** | BR-023, BR-001, BR-004, BR-009, BR-020 |
| **Requirements** | FR-044 |
| **Pages and actions** | E9<br>E9 · Post credit note |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getcreditnotes"></a>
#### `GET /api/v1/credit-notes`

List credit notes. _Contract change: R-07._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `party_id`, `status`, `usage` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Note`](#schema-note) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: credit_notes, parties, sales_invoices |
| **Business rules** | — |
| **Requirements** | FR-044 |
| **Pages and actions** | E15 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="deletecreditnotesid"></a>
#### `DELETE /api/v1/credit-notes/{id}`

Delete a draft credit note. _Contract change: C-02._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: credit_notes |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-044 |
| **Pages and actions** | E15<br>E15 · Delete draft (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="postcreditnotesidcancel"></a>
#### `POST /api/v1/credit-notes/{id}/cancel`

Cancel a posted credit note (mirror entry; its allocations are reversed). _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.edit` — roles: Owner, Finance manager, Accountant, Sales staff |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger. |
| **Success** | 200 `data`: [`Note`](#schema-note) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `already_posted`, `already_reversed`, `company_maintenance`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: credit_notes, number_sequences, sales_invoices; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines; update: bill_allocations, credit_notes, journal_entries, number_sequences, sales_invoices |
| **Business rules** | BR-003, BR-004, BR-009, BR-020 |
| **Requirements** | FR-044 |
| **Pages and actions** | E15<br>E15 · Cancel posted (row, dialog) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getcreditnotesidpdf"></a>
#### `GET /api/v1/credit-notes/{id}/pdf`

Printable credit note. _Contract change: C-01._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `sales.view` — roles: Owner, Finance manager, Accountant, Sales staff, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: companies, credit_notes, parties |
| **Business rules** | — |
| **Requirements** | FR-044 |
| **Pages and actions** | E9, E15<br>E9 · Print<br>E15 · Print credit note (row) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

### Purchases


<a id="getpurchasesoverview"></a>
#### `GET /api/v1/purchases/overview`

Purchase overview.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | — / `period` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: purchase_bills, purchase_orders |
| **Business rules** | — |
| **Requirements** | FR-050 |
| **Pages and actions** | F1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postpurchasebillscalculate"></a>
#### `POST /api/v1/purchase-bills/calculate`

Authoritative totals for an unsaved bill (incl. foreign currency).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`PurchaseBillInput`](#schema-purchasebillinput) |
| **Validation** | `party_id`* id of a record in this company; `supplier_bill_no`* string ≤ 60; `bill_date`* YYYY-MM-DD; `due_date` YYYY-MM-DD (nullable); `payment_term_id` id of a record in this company (nullable); `warehouse_id`* id of a record in this company; `purchase_order_id` id of a record in this company (nullable); `letter_of_credit_id` id of a record in this company (nullable); `lc_shipment_id` id of a record in this company (nullable); `currency_code` ISO 4217; `exchange_rate` decimal string, 6 dp; `branch_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `receipt_condition_note` string ≤ 255 (nullable); `lines`* array of PurchaseLineInput<br>Unknown fields are rejected (422).<br>BR-010: Money uses decimal arithmetic only. Line amounts are rounded half-up to 2 decimals; document totals are the sum of rounded lines; the server's calculation is authoritative.<br>BR-011: Line gross = quantity x unit price; discount = gross x discount% ; tax = (gross - discount) x tax rate; line total = gross - discount + tax. |
| **Success** | 200 `data`: [`DocumentCalculation`](#schema-documentcalculation) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: company_settings, parties, products, tax_rules |
| **Business rules** | BR-010, BR-011 |
| **Requirements** | FR-051 |
| **Pages and actions** | F3, F8<br>F3 · Add line |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getpurchasebills"></a>
#### `GET /api/v1/purchase-bills`

List bills.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `status`, `payment_status`, `overdue`, `party_id`, `letter_of_credit_id` |
| **Request body** | — |
| **Validation** | BR-037: Every read and write is scoped to the active company; any reference to another company's record is rejected as not found.<br>BR-038: When a membership has branch restrictions, the user can only see and create records of those branches. |
| **Success** | 200 `data[]` of [`PurchaseBill`](#schema-purchasebill) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`branch_restricted`, `company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: parties, purchase_bills |
| **Business rules** | BR-037, BR-038 |
| **Requirements** | FR-050 |
| **Pages and actions** | F2, F1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postpurchasebills"></a>
#### `POST /api/v1/purchase-bills`

Create a draft bill (local or import mode).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`PurchaseBillInput`](#schema-purchasebillinput) |
| **Validation** | `party_id`* id of a record in this company; `supplier_bill_no`* string ≤ 60; `bill_date`* YYYY-MM-DD; `due_date` YYYY-MM-DD (nullable); `payment_term_id` id of a record in this company (nullable); `warehouse_id`* id of a record in this company; `purchase_order_id` id of a record in this company (nullable); `letter_of_credit_id` id of a record in this company (nullable); `lc_shipment_id` id of a record in this company (nullable); `currency_code` ISO 4217; `exchange_rate` decimal string, 6 dp; `branch_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `receipt_condition_note` string ≤ 255 (nullable); `lines`* array of PurchaseLineInput<br>Unknown fields are rejected (422).<br>BR-018: A purchase bill requires the supplier's bill number, unique per supplier within the company.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.<br>BR-010: Money uses decimal arithmetic only. Line amounts are rounded half-up to 2 decimals; document totals are the sum of rounded lines; the server's calculation is authoritative.<br>BR-011: Line gross = quantity x unit price; discount = gross x discount% ; tax = (gross - discount) x tax rate; line total = gross - discount + tax.<br>BR-055: Due date defaults to document date + the party's payment-term days and may be edited. |
| **Success** | 201 `data`: [`PurchaseBill`](#schema-purchasebill) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `duplicate_supplier_bill`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, purchase_bill_lines, purchase_bills; read: lc_shipments, letters_of_credit, number_sequences, parties, products |
| **Business rules** | BR-018, BR-006, BR-010, BR-011, BR-055 |
| **Requirements** | FR-051, FR-098 |
| **Pages and actions** | F3, M4<br>F3 · Save draft<br>F3 · Save bill > Post dialog |
| **Transaction / concurrency** | Single transaction. |

<a id="getpurchasebillsid"></a>
#### `GET /api/v1/purchase-bills/{id}`

Bill with lines, payments, ledger entries, receipt.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`PurchaseBill`](#schema-purchasebill) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: bill_allocations, journal_entries, journal_lines, purchase_bill_lines, purchase_bills |
| **Business rules** | — |
| **Requirements** | FR-051 |
| **Pages and actions** | F4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putpurchasebillsid"></a>
#### `PUT /api/v1/purchase-bills/{id}`

Replace a draft bill.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`PurchaseBillInput`](#schema-purchasebillinput) |
| **Validation** | `party_id`* id of a record in this company; `supplier_bill_no`* string ≤ 60; `bill_date`* YYYY-MM-DD; `due_date` YYYY-MM-DD (nullable); `payment_term_id` id of a record in this company (nullable); `warehouse_id`* id of a record in this company; `purchase_order_id` id of a record in this company (nullable); `letter_of_credit_id` id of a record in this company (nullable); `lc_shipment_id` id of a record in this company (nullable); `currency_code` ISO 4217; `exchange_rate` decimal string, 6 dp; `branch_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `receipt_condition_note` string ≤ 255 (nullable); `lines`* array of PurchaseLineInput<br>Unknown fields are rejected (422).<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-018: A purchase bill requires the supplier's bill number, unique per supplier within the company.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. |
| **Success** | 200 `data`: [`PurchaseBill`](#schema-purchasebill) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `duplicate_supplier_bill`, `not_draft`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs, purchase_bill_lines; update: purchase_bills; delete: purchase_bill_lines |
| **Business rules** | BR-025, BR-018, BR-049 |
| **Requirements** | FR-051 |
| **Pages and actions** | F3<br>F3 · Save draft<br>F3 · Save bill > Post dialog |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deletepurchasebillsid"></a>
#### `DELETE /api/v1/purchase-bills/{id}`

Delete a draft bill.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: purchase_bill_lines, purchase_bills |
| **Business rules** | BR-025 |
| **Requirements** | FR-051 |
| **Pages and actions** | F3<br>F3 · Delete draft (NEW) |
| **Transaction / concurrency** | Single transaction. |

<a id="postpurchasebillsidpost"></a>
#### `POST /api/v1/purchase-bills/{id}/post`

Post: stock in, average cost update, journal, supplier balance, PO received qty.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`PostRequest`](#schema-postrequest) |
| **Validation** | `confirm_backdated` boolean; `acknowledge_warnings` array of str; `receipt` CashReceiptInput (nullable)<br>Unknown fields are rejected (422).<br>BR-018: A purchase bill requires the supplier's bill number, unique per supplier within the company.<br>BR-019: Posting a bill increases stock in the receiving warehouse and debits that warehouse's inventory ledger for stocked lines, or the line's expense ledger for non-stock lines; the supplier is credited.<br>BR-017: On every stock-in to a warehouse, that warehouse's average cost = (warehouse value + incoming value) / (warehouse quantity + incoming quantity); when current quantity <= 0 the incoming unit cost becomes the average.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities.<br>BR-053: A voucher dated more than the configured number of days in the past requires explicit confirmation. |
| **Success** | 200 `data`: [`PurchaseBill`](#schema-purchasebill) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `backdated_confirmation_required`, `company_maintenance`, `duplicate_supplier_bill`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: purchase_bills, stock_balances; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, stock_movements; update: purchase_bill_lines, purchase_bills, purchase_order_lines, purchase_orders, stock_balances; read: account_mappings, accounting_periods, products |
| **Business rules** | BR-018, BR-019, BR-017, BR-001, BR-004, BR-009, BR-056, BR-053 |
| **Requirements** | FR-051 |
| **Pages and actions** | F3<br>F3 · Save bill > Post dialog |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postpurchasebillsidcancel"></a>
#### `POST /api/v1/purchase-bills/{id}/cancel`

Cancel a posted bill (reverse; stock out at bill cost; payments to advance).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`CancelRequest`](#schema-cancelrequest) |
| **Validation** | `reason`* one of customer_cancelled/entered_by_mistake/wrong_party/duplicate/other; `note` string ≤ 255 (nullable); `cancellation_date` YYYY-MM-DD (nullable)<br>Unknown fields are rejected (422).<br>BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock.<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited. |
| **Success** | 200 `data`: [`PurchaseBill`](#schema-purchasebill) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_reversed`, `company_maintenance`, `insufficient_stock`, `invalid_transition`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, purchase_bills, stock_balances; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines, stock_movements; update: bill_allocations, journal_entries, number_sequences, purchase_bills, stock_balances |
| **Business rules** | BR-024, BR-014, BR-003 |
| **Requirements** | FR-051 |
| **Pages and actions** | F4<br>F4 · Cancel bill (dialog) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postsupplierpayments"></a>
#### `POST /api/v1/supplier-payments`

Record a supplier payment with allocations (creates PV; may need approval). Returns 201 when posted, or 202 with status=submitted when above the role's payment limit.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`PartyPaymentInput`](#schema-partypaymentinput) |
| **Validation** | `party_id`* id of a record in this company; `date`* YYYY-MM-DD; `amount`* decimal string, 2 dp; `money_account_id`* id of a record in this company; `method`* one of cash/cheque/bank_transfer/beftn/rtgs/npsb/bkash/nagad/card; `cheque_number` string ≤ 20 (nullable); `cheque_date` YYYY-MM-DD (nullable); `drawn_on` string ≤ 120 (nullable); `transaction_reference` string ≤ 60 (nullable); `bank_charge` decimal string, 2 dp; `exchange_rate` decimal string, 6 dp (nullable); `narration` string ≤ 1000 (nullable); `allocations` array of AllocationInput; `auto_allocate` boolean; `confirm_backdated` boolean<br>Unknown fields are rejected (422).<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-021: Allocations, notes and returns may reference only documents of the same party and company.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-043: Differences between the rate at which a foreign bill was booked and the rate at which it is paid post to the exchange gain or loss ledger.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. |
| **Success** | 201 `data`: [`Voucher`](#schema-voucher) · 202 when above the approval limit |
| **Status codes** | 201, 202, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `already_posted`, `company_maintenance`, `foreign_document`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences, purchase_bills; insert: approval_requests, audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines, payment_details; update: number_sequences, purchase_bills; read: money_accounts, roles |
| **Business rules** | BR-020, BR-021, BR-031, BR-065, BR-007, BR-043, BR-001, BR-004, BR-009 |
| **Requirements** | FR-052 |
| **Pages and actions** | F5, Pur-DlgPay, F4, C4<br>Pur-DlgPay · Save payment |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getsupplierpayments"></a>
#### `GET /api/v1/supplier-payments`

List supplier payments (PV) with party, account, method and status. _Contract change: R-07._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `party_id`, `money_account_id`, `status` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Voucher`](#schema-voucher) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: approval_requests, bill_allocations, journal_entries, money_accounts, parties, payment_details |
| **Business rules** | — |
| **Requirements** | FR-052 |
| **Pages and actions** | F12 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getsupplierpaymentsid"></a>
#### `GET /api/v1/supplier-payments/{id}`

Get a supplier payment with its allocations. _Contract change: R-07._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Voucher`](#schema-voucher) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: approval_requests, bill_allocations, journal_entries, journal_lines, payment_details, purchase_bills |
| **Business rules** | — |
| **Requirements** | FR-052 |
| **Pages and actions** | F12, F5<br>F12 · Open (drawer) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getsupplierpaymentsidpdf"></a>
#### `GET /api/v1/supplier-payments/{id}/pdf`

Printable payment voucher. _Contract change: C-01._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: companies, journal_entries, journal_lines, payment_details |
| **Business rules** | — |
| **Requirements** | FR-052 |
| **Pages and actions** | F5, F12<br>F5 · Print voucher<br>F12 · Print voucher (row) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getpurchaseorders"></a>
#### `GET /api/v1/purchase-orders`

List purchase orders.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `status`, `party_id`, `currency` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`PurchaseOrder`](#schema-purchaseorder) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: parties, purchase_orders |
| **Business rules** | — |
| **Requirements** | FR-054 |
| **Pages and actions** | F8, F1, M3, F3, F11 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postpurchaseorders"></a>
#### `POST /api/v1/purchase-orders`

Create a purchase order.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`PurchaseOrderInput`](#schema-purchaseorderinput) |
| **Validation** | `party_id`* id of a record in this company; `order_date`* YYYY-MM-DD; `expected_date` YYYY-MM-DD (nullable); `warehouse_id`* id of a record in this company; `payment_term_id` id of a record in this company (nullable); `currency_code` ISO 4217; `exchange_rate` decimal string, 6 dp; `source_note` string ≤ 120 (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `lines`* array of PurchaseLineInput<br>Unknown fields are rejected (422).<br>BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 201 `data`: [`PurchaseOrder`](#schema-purchaseorder) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, purchase_order_lines, purchase_orders; read: number_sequences |
| **Business rules** | BR-056, BR-006 |
| **Requirements** | FR-054 |
| **Pages and actions** | F8<br>F8 · Save draft |
| **Transaction / concurrency** | Single transaction. |

<a id="postpurchaseordersfromlowstock"></a>
#### `POST /api/v1/purchase-orders/from-low-stock`

Create draft POs grouped by preferred supplier from selected low-stock rows.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`LowStockOrderRequest`](#schema-lowstockorderrequest) |
| **Validation** | `rows`* array of obj; `order_date`* YYYY-MM-DD<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`BulkResult`](#schema-bulkresult) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: purchase_orders; insert: audit_logs, idempotency_keys; update: purchase_orders; read: product_warehouse_settings, products, purchase_order_lines, stock_balances |
| **Business rules** | — |
| **Requirements** | FR-037, FR-054 |
| **Pages and actions** | D12<br>D12 · Create purchase orders (from selection) |
| **Transaction / concurrency** | Single transaction. |

<a id="getpurchaseordersid"></a>
#### `GET /api/v1/purchase-orders/{id}`

Get a purchase order.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`PurchaseOrder`](#schema-purchaseorder) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: purchase_order_lines, purchase_orders |
| **Business rules** | — |
| **Requirements** | FR-054 |
| **Pages and actions** | F8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putpurchaseordersid"></a>
#### `PUT /api/v1/purchase-orders/{id}`

Replace a draft or sent order.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`PurchaseOrderInput`](#schema-purchaseorderinput) |
| **Validation** | `party_id`* id of a record in this company; `order_date`* YYYY-MM-DD; `expected_date` YYYY-MM-DD (nullable); `warehouse_id`* id of a record in this company; `payment_term_id` id of a record in this company (nullable); `currency_code` ISO 4217; `exchange_rate` decimal string, 6 dp; `source_note` string ≤ 120 (nullable); `notes` text ≤ 65,535 (nullable); `internal_note` text ≤ 65,535 (nullable); `lines`* array of PurchaseLineInput<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`PurchaseOrder`](#schema-purchaseorder) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs, purchase_order_lines; update: purchase_orders; delete: purchase_order_lines |
| **Business rules** | — |
| **Requirements** | FR-054 |
| **Pages and actions** | F8<br>F8 · Save draft |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postpurchaseordersidsend"></a>
#### `POST /api/v1/purchase-orders/{id}/send`

Mark sent and e-mail it.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`EmailRequest`](#schema-emailrequest) |
| **Validation** | `to`* array of email; `cc` array of email; `message` text ≤ 65,535 (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`PurchaseOrder`](#schema-purchaseorder) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: purchase_orders; insert: audit_logs; update: purchase_orders |
| **Business rules** | — |
| **Requirements** | FR-054 |
| **Pages and actions** | F8<br>F8 · Send order |
| **Transaction / concurrency** | Single transaction. |

<a id="postpurchaseordersidconvert"></a>
#### `POST /api/v1/purchase-orders/{id}/convert`

Create a draft bill from remaining quantities.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities. |
| **Success** | 201 `data`: [`PurchaseBill`](#schema-purchasebill) |
| **Status codes** | 201, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences, purchase_orders; insert: audit_logs, idempotency_keys, purchase_bill_lines, purchase_bills; update: number_sequences, purchase_orders; read: purchase_order_lines |
| **Business rules** | BR-056 |
| **Requirements** | FR-054 |
| **Pages and actions** | F8, F11<br>F8 · Convert to bill<br>F11 · Convert to bill (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="postpurchaseordersidstatus"></a>
#### `POST /api/v1/purchase-orders/{id}/status`

Close or cancel an order.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`StatusChange`](#schema-statuschange) |
| **Validation** | `status`* one of accepted/declined/expired/closed/cancelled; `reason` string ≤ 255 (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`PurchaseOrder`](#schema-purchaseorder) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: purchase_orders; insert: audit_logs; update: purchase_orders |
| **Business rules** | — |
| **Requirements** | FR-054 |
| **Pages and actions** | F8, F11<br>F11 · Close or cancel (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="deletepurchaseordersid"></a>
#### `DELETE /api/v1/purchase-orders/{id}`

Delete a draft purchase order. _Contract change: C-02._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: purchase_order_lines, purchase_orders |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-054 |
| **Pages and actions** | F11<br>F11 · Delete draft (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="getpurchaseordersidpdf"></a>
#### `GET /api/v1/purchase-orders/{id}/pdf`

Printable purchase order (also attached when sent). _Contract change: C-01._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: companies, parties, purchase_order_lines, purchase_orders |
| **Business rules** | — |
| **Requirements** | FR-054 |
| **Pages and actions** | F8, F11<br>F11 · Print (row) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getpurchasereturns"></a>
#### `GET /api/v1/purchase-returns`

List purchase returns.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `party_id` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Return`](#schema-return) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: debit_notes, purchase_returns |
| **Business rules** | — |
| **Requirements** | FR-055 |
| **Pages and actions** | F6, F13 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postpurchasereturns"></a>
#### `POST /api/v1/purchase-returns`

Create a draft purchase return.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`ReturnInput`](#schema-returninput) |
| **Validation** | `source_document_id`* id of a record in this company; `return_date`* YYYY-MM-DD; `warehouse_id`* id of a record in this company; `stock_action`* one of restock/write_off/send_back/already_disposed; `usage` one of apply/credit/refund; `apply_to_document_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `lines`* array of ReturnLineInput<br>Unknown fields are rejected (422).<br>BR-022: Returned quantity per line <= original quantity - quantity already returned.<br>BR-021: Allocations, notes and returns may reference only documents of the same party and company. |
| **Success** | 201 `data`: [`Return`](#schema-return) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `foreign_document`, `return_qty_exceeds`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, purchase_return_lines, purchase_returns; read: number_sequences, purchase_bill_lines |
| **Business rules** | BR-022, BR-021 |
| **Requirements** | FR-053 |
| **Pages and actions** | F6<br>F6 · Save draft<br>F6 · Save return and create debit note |
| **Transaction / concurrency** | Single transaction. |

<a id="getpurchasereturnsid"></a>
#### `GET /api/v1/purchase-returns/{id}`

Get a purchase return.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Return`](#schema-return) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: purchase_return_lines, purchase_returns |
| **Business rules** | — |
| **Requirements** | FR-053 |
| **Pages and actions** | F6 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putpurchasereturnsid"></a>
#### `PUT /api/v1/purchase-returns/{id}`

Replace a draft purchase return.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`ReturnInput`](#schema-returninput) |
| **Validation** | `source_document_id`* id of a record in this company; `return_date`* YYYY-MM-DD; `warehouse_id`* id of a record in this company; `stock_action`* one of restock/write_off/send_back/already_disposed; `usage` one of apply/credit/refund; `apply_to_document_id` id of a record in this company (nullable); `notes` text ≤ 65,535 (nullable); `lines`* array of ReturnLineInput<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Return`](#schema-return) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs, purchase_return_lines; update: purchase_returns; delete: purchase_return_lines |
| **Business rules** | — |
| **Requirements** | FR-053 |
| **Pages and actions** | F6<br>F6 · Save draft<br>F6 · Save return and create debit note |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postpurchasereturnsidpost"></a>
#### `POST /api/v1/purchase-returns/{id}/post`

Post the return: stock out at bill cost and Dr supplier / Cr inventory; the debit note is created posted, sharing that journal entry, and applied per usage. The supplier is debited once: the debit note`s journal_entry_id equals the return`s (R-04).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-022: Returned quantity per line <= original quantity - quantity already returned.<br>BR-057: A purchase return credits inventory at the original bill cost of the returned lines; the supplier is debited through the debit note.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock.<br>BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. |
| **Success** | 200 `data`: [`Return`](#schema-return) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `already_posted`, `company_maintenance`, `insufficient_stock`, `period_locked`, `return_qty_exceeds`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, purchase_bills, purchase_returns, stock_balances; insert: audit_logs, bill_allocations, debit_notes, idempotency_keys, journal_entries, journal_lines, stock_movements; update: number_sequences, purchase_bill_lines, purchase_bills, purchase_return_lines, purchase_returns, stock_balances |
| **Business rules** | BR-022, BR-057, BR-014, BR-023, BR-001, BR-004, BR-009 |
| **Requirements** | FR-053 |
| **Pages and actions** | F6<br>F6 · Save return and create debit note |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="deletepurchasereturnsid"></a>
#### `DELETE /api/v1/purchase-returns/{id}`

Delete a draft purchase return. _Contract change: C-02._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: purchase_return_lines, purchase_returns |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-053 |
| **Pages and actions** | F13<br>F13 · Delete draft (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="postpurchasereturnsidcancel"></a>
#### `POST /api/v1/purchase-returns/{id}/cancel`

Cancel a posted purchase return: stock back at the same cost, supplier credited, its debit note cancelled. _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances. |
| **Success** | 200 `data`: [`Return`](#schema-return) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `already_reversed`, `company_maintenance`, `invalid_transition`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: number_sequences, purchase_returns, stock_balances; insert: audit_logs, bill_allocations, debit_notes, idempotency_keys, journal_entries, journal_lines, stock_movements; update: bill_allocations, journal_entries, number_sequences, purchase_bill_lines, purchase_return_lines, purchase_returns, stock_balances |
| **Business rules** | BR-003, BR-004, BR-009, BR-024 |
| **Requirements** | FR-053 |
| **Pages and actions** | F13<br>F13 · Cancel posted (row, dialog) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getdebitnotesid"></a>
#### `GET /api/v1/debit-notes/{id}`

Get a debit note.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Note`](#schema-note) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: debit_notes |
| **Business rules** | — |
| **Requirements** | FR-053 |
| **Pages and actions** | F7 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putdebitnotesid"></a>
#### `PUT /api/v1/debit-notes/{id}`

Edit a draft debit note.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`NoteInput`](#schema-noteinput) |
| **Validation** | `party_id`* id of a record in this company; `source_document_id` id of a record in this company (nullable); `return_id` id of a record in this company (nullable); `note_date`* YYYY-MM-DD; `reason`* string ≤ 30; `amount`* decimal string, 2 dp; `usage`* one of apply/credit/refund; `apply_to_document_id` id of a record in this company (nullable); `refund` RefundInput (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Note`](#schema-note) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: debit_notes |
| **Business rules** | — |
| **Requirements** | FR-053 |
| **Pages and actions** | F7<br>F7 · Post debit note |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postdebitnotes"></a>
#### `POST /api/v1/debit-notes`

Create a debit note without a return (price difference, quality claim).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`NoteInput`](#schema-noteinput) |
| **Validation** | `party_id`* id of a record in this company; `source_document_id` id of a record in this company (nullable); `return_id` id of a record in this company (nullable); `note_date`* YYYY-MM-DD; `reason`* string ≤ 30; `amount`* decimal string, 2 dp; `usage`* one of apply/credit/refund; `apply_to_document_id` id of a record in this company (nullable); `refund` RefundInput (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`Note`](#schema-note) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, debit_notes, idempotency_keys; read: number_sequences |
| **Business rules** | — |
| **Requirements** | FR-053 |
| **Pages and actions** | F7<br>F7 · Post debit note |
| **Transaction / concurrency** | Single transaction. |

<a id="postdebitnotesidpost"></a>
#### `POST /api/v1/debit-notes/{id}/post`

Post a debit note that was not created from a return (price difference, short supply, quality claim) and apply it per usage.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).<br>BR-057: A purchase return credits inventory at the original bill cost of the returned lines; the supplier is debited through the debit note.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. |
| **Success** | 200 `data`: [`Note`](#schema-note) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `already_posted`, `company_maintenance`, `created_from_return`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: debit_notes, number_sequences, purchase_bills; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines; update: debit_notes, number_sequences, purchase_bills |
| **Business rules** | BR-023, BR-057, BR-001, BR-004, BR-009 |
| **Requirements** | FR-053 |
| **Pages and actions** | F7<br>F7 · Post debit note |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getdebitnotes"></a>
#### `GET /api/v1/debit-notes`

List debit notes. _Contract change: R-07._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `party_id`, `status`, `usage` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Note`](#schema-note) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: debit_notes, parties, purchase_bills |
| **Business rules** | — |
| **Requirements** | FR-053 |
| **Pages and actions** | F13 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="deletedebitnotesid"></a>
#### `DELETE /api/v1/debit-notes/{id}`

Delete a draft debit note. _Contract change: C-02._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: debit_notes |
| **Business rules** | BR-025, BR-006 |
| **Requirements** | FR-053 |
| **Pages and actions** | F13<br>F13 · Delete draft (row) |
| **Transaction / concurrency** | Single transaction. |

<a id="postdebitnotesidcancel"></a>
#### `POST /api/v1/debit-notes/{id}/cancel`

Cancel a posted debit note not created from a return (mirror entry; allocations reversed). _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger. |
| **Success** | 200 `data`: [`Note`](#schema-note) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `already_posted`, `already_reversed`, `company_maintenance`, `created_from_return`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: debit_notes, number_sequences, purchase_bills; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines; update: bill_allocations, debit_notes, journal_entries, number_sequences, purchase_bills |
| **Business rules** | BR-003, BR-004, BR-009, BR-020 |
| **Requirements** | FR-053 |
| **Pages and actions** | F13<br>F13 · Cancel posted (row, dialog) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getdebitnotesidpdf"></a>
#### `GET /api/v1/debit-notes/{id}/pdf`

Printable debit note. _Contract change: C-01._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `purchases.view` — roles: Owner, Finance manager, Accountant, Store keeper, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: companies, debit_notes, parties |
| **Business rules** | — |
| **Requirements** | FR-053 |
| **Pages and actions** | F7, F13<br>F7 · Print<br>F13 · Print debit note (row) |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

### Banking


<a id="getbankingoverview"></a>
#### `GET /api/v1/banking/overview`

Accounts, combined balance series, quick stats, recent movements.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: bank_statement_lines, journal_lines, money_accounts |
| **Business rules** | — |
| **Requirements** | FR-060 |
| **Pages and actions** | G1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getmoneyaccounts"></a>
#### `GET /api/v1/money-accounts`

Cash, bank, wallet and card accounts with book balance and reconciliation status. Open to every member so receipt and payment forms can pick an account (e.g. Sales staff on E7). Without `banking.view` only id, name, type and use-for flags are returned (no balances, numbers or reconciliation data).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / `type`, `purpose` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`MoneyAccount`](#schema-moneyaccount) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: bank_statement_lines, journal_lines, ledgers, money_accounts |
| **Business rules** | — |
| **Requirements** | FR-060 |
| **Pages and actions** | G3, E7, F5, G6, G7, G8, G9, H10, H11, H12, I3, I6, M3, LC-DlgPay, E14, F12 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postmoneyaccounts"></a>
#### `POST /api/v1/money-accounts`

Add a bank, wallet or card account (ledger + opening balance).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`MoneyAccountInput`](#schema-moneyaccountinput) |
| **Validation** | `account_type`* one of cash/bank/wallet/card/cash_credit; `overdraft_limit` decimal string, 2 dp (nullable); `name` string ≤ 120 (nullable); `bank_name`* string ≤ 120; `branch_name` string ≤ 120 (nullable); `account_name`* string ≤ 150; `account_number`* string ≤ 40; `routing_number` string ≤ 9 (nullable); `opening_balance` decimal string, 2 dp; `opening_date` YYYY-MM-DD (nullable); `custodian_user_id` id of a record in this company (nullable); `use_for_receipts` boolean; `use_for_payments` boolean; `show_on_invoices` boolean<br>Unknown fields are rejected (422).<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document. |
| **Success** | 201 `data`: [`MoneyAccount`](#schema-moneyaccount) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, money_accounts; read: account_mappings, journal_entries, journal_lines, ledgers |
| **Business rules** | BR-028 |
| **Requirements** | FR-061 |
| **Pages and actions** | G4<br>G4 · Add account |
| **Transaction / concurrency** | One transaction. |

<a id="getmoneyaccountsid"></a>
#### `GET /api/v1/money-accounts/{id}`

Account details, statement imports, last cash count.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`MoneyAccount`](#schema-moneyaccount) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: bank_statement_imports, cash_count_denominations, cash_counts, money_accounts |
| **Business rules** | — |
| **Requirements** | FR-060 |
| **Pages and actions** | G2, G5 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchmoneyaccountsid"></a>
#### `PATCH /api/v1/money-accounts/{id}`

Edit account details and use-for switches.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`MoneyAccountInput`](#schema-moneyaccountinput) |
| **Validation** | `account_type`* one of cash/bank/wallet/card/cash_credit; `overdraft_limit` decimal string, 2 dp (nullable); `name` string ≤ 120 (nullable); `bank_name`* string ≤ 120; `branch_name` string ≤ 120 (nullable); `account_name`* string ≤ 150; `account_number`* string ≤ 40; `routing_number` string ≤ 9 (nullable); `opening_balance` decimal string, 2 dp; `opening_date` YYYY-MM-DD (nullable); `custodian_user_id` id of a record in this company (nullable); `use_for_receipts` boolean; `use_for_payments` boolean; `show_on_invoices` boolean<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`MoneyAccount`](#schema-moneyaccount) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: money_accounts |
| **Business rules** | — |
| **Requirements** | FR-061 |
| **Pages and actions** | G5<br>G5 · Edit account (NEW) |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getmoneyaccountsidbook"></a>
#### `GET /api/v1/money-accounts/{id}/book`

Cash book or bank book with running balance.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / `date_from`, `date_to`, `view`, `page` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`LedgerStatement`](#schema-ledgerstatement) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: journal_entries, journal_lines, payment_details |
| **Business rules** | — |
| **Requirements** | FR-060 |
| **Pages and actions** | G2, G5, G11, G12 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getmoneytransactions"></a>
#### `GET /api/v1/money-transactions`

Cash and bank transactions across accounts.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `direction`, `money_account_id` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`MoneyTransaction`](#schema-moneytransaction) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: journal_entries, journal_lines, money_accounts, payment_details |
| **Business rules** | — |
| **Requirements** | FR-060 |
| **Pages and actions** | G13, G1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postcashcounts"></a>
#### `POST /api/v1/cash-counts`

Record a cash count; a difference creates a draft adjustment awaiting approval.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`CashCountInput`](#schema-cashcountinput) |
| **Validation** | `money_account_id`* id of a record in this company; `counted_at`* ISO 8601; `denominations`* array of Denomination; `note` string ≤ 255 (nullable)<br>Unknown fields are rejected (422).<br>BR-058: A cash-count difference posts to the cash short/over ledger and requires approval. |
| **Success** | 201 `data`: [`CashCount`](#schema-cashcount) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences; insert: approval_requests, audit_logs, cash_count_denominations, cash_counts, idempotency_keys, journal_entries, journal_lines; update: number_sequences |
| **Business rules** | BR-058 |
| **Requirements** | FR-065 |
| **Pages and actions** | G2<br>G2 · Count cash (records cash count; difference needs approval) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. A difference is posted only after approval (BR-058). |

<a id="postcashcountsidreverse"></a>
#### `POST /api/v1/cash-counts/{id}/reverse`

Reverse a posted cash-count adjustment (mirror entry). _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.approve` — roles: Owner, Finance manager |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-058: A cash-count difference posts to the cash short/over ledger and requires approval. |
| **Success** | 200 `data`: [`CashCount`](#schema-cashcount) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_reversed`, `company_maintenance`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: cash_counts, number_sequences; insert: audit_logs, idempotency_keys, journal_entries, journal_lines; update: cash_counts, journal_entries, number_sequences |
| **Business rules** | BR-003, BR-004, BR-058 |
| **Requirements** | FR-065 |
| **Pages and actions** | G2<br>G2 · Reverse cash-count adjustment (NEW, R-03) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postbankstatementimports"></a>
#### `POST /api/v1/bank-statement-imports`

Import a CSV/XLSX statement (multipart; column mapping).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`StatementImport`](#schema-statementimport) |
| **Validation** | `file`* string; `money_account_id`* id of a record in this company; `mapping` object (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`StatementImportResult`](#schema-statementimportresult) |
| **Status codes** | 201, 400, 401, 403, 409, 413, 415, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate_statement`, `file_too_large`, `file_type_not_allowed`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, bank_statement_imports, idempotency_keys; read: bank_statement_lines |
| **Business rules** | — |
| **Requirements** | FR-064 |
| **Pages and actions** | G10, G5, G3<br>G3 · Import statement<br>G5 · Import statement<br>G10 · Import statement |
| **Transaction / concurrency** | Single transaction. |

<a id="postreconciliations"></a>
#### `POST /api/v1/reconciliations`

Start (or resume) a reconciliation for an account and statement date.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`ReconciliationStart`](#schema-reconciliationstart) |
| **Validation** | `money_account_id`* id of a record in this company; `statement_date`* YYYY-MM-DD; `statement_balance`* decimal string, 2 dp<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Reconciliation`](#schema-reconciliation) |
| **Status codes** | 200, 201, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, bank_reconciliations; read: bank_statement_lines, journal_lines |
| **Business rules** | — |
| **Requirements** | FR-064 |
| **Pages and actions** | G10, G1, G5<br>G1 · Reconcile<br>G5 · Reconcile<br>G10 · Import statement |
| **Transaction / concurrency** | Single transaction. |

<a id="getreconciliationsid"></a>
#### `GET /api/v1/reconciliations/{id}`

Reconciliation with statement lines, suggestions and difference.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / `filter` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`Reconciliation`](#schema-reconciliation) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: bank_reconciliations, bank_statement_lines, journal_entries, journal_lines |
| **Business rules** | — |
| **Requirements** | FR-064 |
| **Pages and actions** | G10 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postreconciliationsidlineslineidmatch"></a>
#### `POST /api/v1/reconciliations/{id}/lines/{lineId}/match`

Match a statement line to a book line.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id`, `lineId` / — |
| **Request body** | [`MatchRequest`](#schema-matchrequest) |
| **Validation** | `journal_line_id`* id of a record in this company<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Reconciliation`](#schema-reconciliation) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: bank_statement_lines; insert: audit_logs; update: bank_statement_lines; read: journal_lines |
| **Business rules** | — |
| **Requirements** | FR-064 |
| **Pages and actions** | G10<br>G10 · Match |
| **Transaction / concurrency** | Single transaction. |

<a id="postreconciliationsidlineslineidcreateentry"></a>
#### `POST /api/v1/reconciliations/{id}/lines/{lineId}/create-entry`

Create the missing receipt or bank-charge voucher and match it.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id`, `lineId` / — |
| **Request body** | [`CreateEntryFromLine`](#schema-createentryfromline) |
| **Validation** | `kind`* one of receipt/bank_charge/payment; `ledger_id`* id of a record in this company; `party_id` id of a record in this company (nullable); `narration` string ≤ 500 (nullable)<br>Unknown fields are rejected (422).<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`Reconciliation`](#schema-reconciliation) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `mapping_missing`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: bank_statement_lines, number_sequences; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, payment_details; update: bank_statement_lines, number_sequences |
| **Business rules** | BR-065, BR-004, BR-001 |
| **Requirements** | FR-064 |
| **Pages and actions** | G10<br>G10 · Create receipt<br>G10 · Record charge |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postreconciliationsidlinespair"></a>
#### `POST /api/v1/reconciliations/{id}/lines/pair`

Mark two offsetting statement lines as a pair.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`PairRequest`](#schema-pairrequest) |
| **Validation** | `line_ids`* array of id<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`Reconciliation`](#schema-reconciliation) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | lock: bank_statement_lines; insert: audit_logs; update: bank_statement_lines |
| **Business rules** | — |
| **Requirements** | FR-064 |
| **Pages and actions** | G10<br>G10 · Mark as pair |
| **Transaction / concurrency** | Single transaction. |

<a id="postreconciliationsidlineslineidundo"></a>
#### `POST /api/v1/reconciliations/{id}/lines/{lineId}/undo`

Undo a match, pair or ignore.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id`, `lineId` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Reconciliation`](#schema-reconciliation) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: bank_statement_lines; insert: audit_logs; update: bank_statement_lines |
| **Business rules** | — |
| **Requirements** | FR-064 |
| **Pages and actions** | G10<br>G10 · Undo |
| **Transaction / concurrency** | Single transaction. |

<a id="postreconciliationsidacceptsuggestions"></a>
#### `POST /api/v1/reconciliations/{id}/accept-suggestions`

Accept every same-amount, same-date suggestion.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Reconciliation`](#schema-reconciliation) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: bank_statement_lines; insert: audit_logs; update: bank_statement_lines; read: journal_lines |
| **Business rules** | — |
| **Requirements** | FR-064 |
| **Pages and actions** | G10<br>G10 · Accept all suggestions |
| **Transaction / concurrency** | Single transaction. |

<a id="postreconciliationsidfinish"></a>
#### `POST /api/v1/reconciliations/{id}/finish`

Finish (all lines handled and difference zero).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.reconcile` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-032: A reconciliation finishes only when every statement line is matched, created, paired or ignored and the difference is zero. |
| **Success** | 200 `data`: [`Reconciliation`](#schema-reconciliation) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`, `difference_not_zero`, `lines_unhandled`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: bank_reconciliations; insert: audit_logs; update: bank_reconciliations; read: bank_statement_imports, money_accounts |
| **Business rules** | BR-032 |
| **Requirements** | FR-064 |
| **Pages and actions** | G10<br>G10 · Finish reconciliation |
| **Transaction / concurrency** | Single transaction. |

### Income and expenses


<a id="getiecategories"></a>
#### `GET /api/v1/ie-categories`

Income or expense categories with ledger, budget and spent.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `kind`, `period` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`IeCategory`](#schema-iecategory) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines, ledgers |
| **Business rules** | — |
| **Requirements** | FR-067 |
| **Pages and actions** | I7, I3, I6, I2, I5<br>I7 · This month / FY to date |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postiecategories"></a>
#### `POST /api/v1/ie-categories`

Create a category (new or existing ledger).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`IeCategoryInput`](#schema-iecategoryinput) |
| **Validation** | `kind`* one of income/expense; `name`* string ≤ 80; `description` string ≤ 255 (nullable); `ledger_id` id of a record in this company (nullable); `create_ledger` boolean; `monthly_budget` decimal string, 2 dp (nullable); `warn_at_percent` decimal string 0–100, 4 dp<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`IeCategory`](#schema-iecategory) |
| **Status codes** | 201, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, ie_categories; read: ledgers |
| **Business rules** | — |
| **Requirements** | FR-067 |
| **Pages and actions** | I7<br>I7 · New category |
| **Transaction / concurrency** | Single transaction. |

<a id="patchiecategoriesid"></a>
#### `PATCH /api/v1/ie-categories/{id}`

Edit name, budget, warning.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`IeCategoryInput`](#schema-iecategoryinput) |
| **Validation** | `kind`* one of income/expense; `name`* string ≤ 80; `description` string ≤ 255 (nullable); `ledger_id` id of a record in this company (nullable); `create_ledger` boolean; `monthly_budget` decimal string, 2 dp (nullable); `warn_at_percent` decimal string 0–100, 4 dp<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`IeCategory`](#schema-iecategory) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | insert: audit_logs; update: ie_categories |
| **Business rules** | — |
| **Requirements** | FR-067 |
| **Pages and actions** | I7<br>I7 · Edit category |
| **Transaction / concurrency** | Single transaction. |

<a id="getieentries"></a>
#### `GET /api/v1/ie-entries`

Income or expense lines on category ledgers (any voucher).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `kind`, `category_id`, `missing_receipt` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`IeEntry`](#schema-ieentry) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: attachments, ie_categories, journal_entries, journal_lines, payment_details |
| **Business rules** | — |
| **Requirements** | FR-068 |
| **Pages and actions** | I2, I5, I1, I4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postieentries"></a>
#### `POST /api/v1/ie-entries`

Record an income or expense (creates INC/EXP voucher; optional recurrence).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`IeEntryInput`](#schema-ieentryinput) |
| **Validation** | `kind`* one of income/expense; `amount`* decimal string, 2 dp; `date`* YYYY-MM-DD; `category_id`* id of a record in this company; `money_account_id`* id of a record in this company; `method`* one of cash/cheque/bank_transfer/beftn/rtgs/npsb/bkash/nagad/card; `payee_text` string ≤ 150 (nullable); `reference` string ≤ 60 (nullable); `tax_rule_id` id of a record in this company (nullable); `notes` string ≤ 500 (nullable); `attachment_ids` array of id; `recurrence` RecurrenceInput (nullable); `confirm_backdated` boolean<br>Unknown fields are rejected (422).<br>BR-054: An expense or income entry needs amount > 0, a category and a money account; it posts the category ledger against the money account.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-048: Recurring templates create drafts only; nothing posts automatically.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts. |
| **Success** | 201 `data`: [`Voucher`](#schema-voucher) · 202 when above the approval limit |
| **Status codes** | 201, 202, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `insufficient_funds`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences; insert: approval_requests, audit_logs, idempotency_keys, journal_entries, journal_lines, payment_details; update: number_sequences; read: ie_categories, money_accounts, recurring_templates |
| **Business rules** | BR-054, BR-031, BR-048, BR-004, BR-001, BR-007 |
| **Requirements** | FR-066 |
| **Pages and actions** | I3, I6<br>I3 · Save and add another<br>I3 · Save income<br>I6 · Save and add another<br>I6 · Save expense |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getrecurringtemplates"></a>
#### `GET /api/v1/recurring-templates`

Recurring entries.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`RecurringTemplate`](#schema-recurringtemplate) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: recurring_templates |
| **Business rules** | — |
| **Requirements** | FR-066 |
| **Pages and actions** | I5, I2, I10 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchrecurringtemplatesid"></a>
#### `PATCH /api/v1/recurring-templates/{id}`

Pause, edit or end a recurring entry.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`RecurringTemplateInput`](#schema-recurringtemplateinput) |
| **Validation** | `frequency` one of weekly/monthly/quarterly/yearly; `next_run_on` YYYY-MM-DD; `ends_type` one of never/after_count/on_date; `ends_after_count` integer (nullable); `ends_on` YYYY-MM-DD (nullable); `is_active` boolean<br>Unknown fields are rejected (422).<br>BR-048: Recurring templates create drafts only; nothing posts automatically.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. |
| **Success** | 200 `data`: [`RecurringTemplate`](#schema-recurringtemplate) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: recurring_templates |
| **Business rules** | BR-048, BR-049 |
| **Requirements** | FR-066 |
| **Pages and actions** | I5, I10<br>I10 · Pause or resume (row)<br>I10 · Edit schedule (row)<br>I10 · End (row) |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getincomeexpensesoverview"></a>
#### `GET /api/v1/income-expenses/overview`

Income or expense overview for the FY.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `banking.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `kind`, `period` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: ie_categories, journal_lines |
| **Business rules** | — |
| **Requirements** | FR-068 |
| **Pages and actions** | I1, I4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

### Accounting


<a id="getaccountingoverview"></a>
#### `GET /api/v1/accounting/overview`

Totals and books-health checks.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: accounting_periods, bank_statement_lines, journal_entries, journal_lines, ledgers |
| **Business rules** | — |
| **Requirements** | FR-070 |
| **Pages and actions** | H1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getaccountgroups"></a>
#### `GET /api/v1/account-groups`

Chart of accounts tree with balances.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `as_of`, `with_ledgers`, `zero` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`AccountTree`](#schema-accounttree) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_lines, ledgers |
| **Business rules** | — |
| **Requirements** | FR-071 |
| **Pages and actions** | H2, H3, H4, K16 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postaccountgroups"></a>
#### `POST /api/v1/account-groups`

Create a group.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`AccountGroupInput`](#schema-accountgroupinput) |
| **Validation** | `code`* string ≤ 20; `name`* string ≤ 120; `parent_id`* id of a record in this company; `cash_flow_category` one of operating/investing/financing/cash (nullable); `sort_order` integer; `used_in_cash_vouchers` boolean<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`AccountGroup`](#schema-accountgroup) |
| **Status codes** | 201, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: account_groups, audit_logs |
| **Business rules** | — |
| **Requirements** | FR-071 |
| **Pages and actions** | H2<br>H2 · New group |
| **Transaction / concurrency** | Single transaction. |

<a id="getaccountgroupsid"></a>
#### `GET /api/v1/account-groups/{id}`

Group details and children.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`AccountGroup`](#schema-accountgroup) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: account_groups, journal_lines, ledgers |
| **Business rules** | — |
| **Requirements** | FR-071 |
| **Pages and actions** | H3 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchaccountgroupsid"></a>
#### `PATCH /api/v1/account-groups/{id}`

Edit a group (name, parent within nature, order).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`AccountGroupInput`](#schema-accountgroupinput) |
| **Validation** | `code`* string ≤ 20; `name`* string ≤ 120; `parent_id`* id of a record in this company; `cash_flow_category` one of operating/investing/financing/cash (nullable); `sort_order` integer; `used_in_cash_vouchers` boolean<br>Unknown fields are rejected (422).<br>BR-027: A ledger's code and opening balance are locked once it has entries; the group may change only within the same nature. |
| **Success** | 200 `data`: [`AccountGroup`](#schema-accountgroup) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `ledger_locked`, `nature_mismatch`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: account_groups |
| **Business rules** | BR-027 |
| **Requirements** | FR-071 |
| **Pages and actions** | H3<br>H3 · Edit group (name, parent within nature, sort) |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getledgers"></a>
#### `GET /api/v1/ledgers`

Ledger list (balances only with accounting.view). Picker for product, party, tax and category forms, and the ledger list. Without `accounting.view` the caller must send `purpose` (income_for_product, expense_for_product, purchase_for_supplier, tax, category) allowed by one of its create/edit permissions; only matching active posting ledgers are returned, without the balance fields (R-26). Sales staff therefore never browse the chart.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | any signed-in user who is an active member of the company |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `nature`, `kind`, `cash_vouchers`, `active`, `purpose`, `account_group_id` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Ledger`](#schema-ledger) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`, `purpose_required`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: account_groups, journal_lines, ledgers |
| **Business rules** | — |
| **Requirements** | FR-071 |
| **Pages and actions** | H6, H9, H10, H11, H12, K16, D2, C2, K6, I7, H3<br>H3 · Ledger in this group |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postledgers"></a>
#### `POST /api/v1/ledgers`

Create a ledger (optional opening balance).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`LedgerInput`](#schema-ledgerinput) |
| **Validation** | `name`* string ≤ 150; `account_group_id`* id of a record in this company; `code` string ≤ 20 (nullable); `description` string ≤ 255 (nullable); `track_bills` boolean; `show_in_cash_vouchers` boolean; `opening_balance` decimal string, 2 dp; `opening_side` one of dr/cr<br>Unknown fields are rejected (422).<br>BR-027: A ledger's code and opening balance are locked once it has entries; the group may change only within the same nature.<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document. |
| **Success** | 201 `data`: [`Ledger`](#schema-ledger) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `ledger_locked`, `nature_mismatch`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, ledgers; read: account_groups, journal_entries, journal_lines |
| **Business rules** | BR-027, BR-028 |
| **Requirements** | FR-071 |
| **Pages and actions** | H4<br>H4 · Save and add another<br>H4 · Create ledger |
| **Transaction / concurrency** | Single transaction. |

<a id="getledgersid"></a>
#### `GET /api/v1/ledgers/{id}`

Ledger details with FY summary.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Ledger`](#schema-ledger) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: journal_lines, ledgers |
| **Business rules** | — |
| **Requirements** | FR-072 |
| **Pages and actions** | H5, H7 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="patchledgersid"></a>
#### `PATCH /api/v1/ledgers/{id}`

Edit a ledger (code and opening locked once used).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LedgerInput`](#schema-ledgerinput) |
| **Validation** | `name`* string ≤ 150; `account_group_id`* id of a record in this company; `code` string ≤ 20 (nullable); `description` string ≤ 255 (nullable); `track_bills` boolean; `show_in_cash_vouchers` boolean; `opening_balance` decimal string, 2 dp; `opening_side` one of dr/cr<br>Unknown fields are rejected (422).<br>BR-027: A ledger's code and opening balance are locked once it has entries; the group may change only within the same nature.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. |
| **Success** | 200 `data`: [`Ledger`](#schema-ledger) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `ledger_locked`, `nature_mismatch`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: ledgers; read: journal_lines |
| **Business rules** | BR-027, BR-049 |
| **Requirements** | FR-071 |
| **Pages and actions** | H5<br>H5 · Save changes |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postledgersiddeactivate"></a>
#### `POST /api/v1/ledgers/{id}/deactivate`

Make a ledger inactive (system-mapped ledgers refused).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents. |
| **Success** | 200 `data`: [`Ledger`](#schema-ledger) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `has_history`, `inactive_reference`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: ledgers; insert: audit_logs; update: ledgers; read: account_mappings |
| **Business rules** | BR-026 |
| **Requirements** | FR-071 |
| **Pages and actions** | H5<br>H5 · Make inactive |
| **Transaction / concurrency** | Single transaction. |

<a id="getledgersidentries"></a>
#### `GET /api/v1/ledgers/{id}/entries`

Ledger entries with running balance and monthly closings.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / `date_from`, `date_to`, `page`, `narration` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data`: [`LedgerStatement`](#schema-ledgerstatement) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: journal_entries, journal_lines |
| **Business rules** | — |
| **Requirements** | FR-072 |
| **Pages and actions** | H7 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getvouchers"></a>
#### `GET /api/v1/vouchers`

Voucher list / day book.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `date_from`, `date_to`, `entry_type`, `status`, `ledger_id` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`Voucher`](#schema-voucher) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: journal_entries, journal_lines, ledgers |
| **Business rules** | — |
| **Requirements** | FR-074, FR-076 |
| **Pages and actions** | H8, H1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postvouchers"></a>
#### `POST /api/v1/vouchers`

Create a journal, payment, receipt or contra voucher (draft or post immediately). `action`: draft | submit | post. Post above the limit is converted to submit (202). Banking pages call this with `banking.create`; journals need `accounting.create`.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` |
| **Path / query params** | — / — |
| **Request body** | [`VoucherInput`](#schema-voucherinput) |
| **Validation** | `entry_type`* one of journal/payment/receipt/contra; `action`* one of draft/submit/post; `date`* YYYY-MM-DD; `reference` string ≤ 60 (nullable); `narration` string ≤ 1000 (nullable); `template_key` string ≤ 40 (nullable); `payment` PaymentDetailInput (nullable); `lines`* array of VoucherLineInput; `confirm_backdated` boolean<br>Unknown fields are rejected (422).<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-002: Each journal line carries a debit or a credit greater than zero, never both and never neither.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-053: A voucher dated more than the configured number of days in the past requires explicit confirmation.<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger. |
| **Success** | 201 `data`: [`Voucher`](#schema-voucher) · 202 when above the approval limit |
| **Status codes** | 201, 202, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`allocation_exceeds_balance`, `backdated_confirmation_required`, `company_maintenance`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences; insert: approval_requests, audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines, payment_details; update: number_sequences; read: money_accounts, roles |
| **Business rules** | BR-001, BR-002, BR-004, BR-006, BR-007, BR-031, BR-053, BR-065, BR-020 |
| **Requirements** | FR-073, FR-062, FR-063 |
| **Pages and actions** | H9, H10, H11, H12, G6, G7, G8, G9<br>G6 · Save and new<br>G6 · Save<br>G7 · Save and new<br>G7 · Save<br>G8 · Save transfer<br>G9 · Save transfer<br>H9 · Save draft<br>H9 · Submit for approval (above limit)<br>H9 · Post voucher<br>H10 · Preview (H14)<br>H10 · Post / Submit<br>H11 · Preview (H14)<br>H11 · Post / Submit<br>H12 · Preview (H14)<br>H12 · Post / Submit |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getvouchersid"></a>
#### `GET /api/v1/vouchers/{id}`

Voucher with lines, allocations, payment details, audit trail, linked documents.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`Voucher`](#schema-voucher) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: attachments, audit_logs, bill_allocations, journal_entries, journal_lines, payment_details |
| **Business rules** | — |
| **Requirements** | FR-074 |
| **Pages and actions** | H13, H14, H8<br>H8 · Duplicate (row)<br>H13 · Duplicate |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putvouchersid"></a>
#### `PUT /api/v1/vouchers/{id}`

Replace a draft or rejected voucher.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — entry_type = journal → `accounting.edit`; entry_type in (payment, receipt, contra) → `banking.edit or accounting.edit` |
| **Path / query params** | `id` / — |
| **Request body** | [`VoucherInput`](#schema-voucherinput) |
| **Validation** | `entry_type`* one of journal/payment/receipt/contra; `action`* one of draft/submit/post; `date`* YYYY-MM-DD; `reference` string ≤ 60 (nullable); `narration` string ≤ 1000 (nullable); `template_key` string ≤ 40 (nullable); `payment` PaymentDetailInput (nullable); `lines`* array of VoucherLineInput; `confirm_backdated` boolean<br>Unknown fields are rejected (422).<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. |
| **Success** | 200 `data`: [`Voucher`](#schema-voucher) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `not_draft`, `unbalanced_entry`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs, journal_lines; update: journal_entries, payment_details; delete: journal_lines |
| **Business rules** | BR-025, BR-001, BR-049 |
| **Requirements** | FR-073 |
| **Pages and actions** | H9, H10, H11, H12<br>H9 · Save draft<br>H10 · Preview (H14)<br>H11 · Preview (H14)<br>H12 · Preview (H14) |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deletevouchersid"></a>
#### `DELETE /api/v1/vouchers/{id}`

Delete a draft voucher (posted vouchers cannot be deleted).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`already_reversed`, `company_maintenance`, `not_draft`, `use_document_cancel`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: journal_entries; read: journal_lines |
| **Business rules** | BR-025, BR-003 |
| **Requirements** | FR-074 |
| **Pages and actions** | H13<br>H13 · Delete (drafts only, GAP-07) |
| **Transaction / concurrency** | Single transaction. |

<a id="postvouchersidsubmit"></a>
#### `POST /api/v1/vouchers/{id}/submit`

Submit a draft for approval.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts. |
| **Success** | 200 `data`: [`Voucher`](#schema-voucher) · 202 when above the approval limit |
| **Status codes** | 200, 202, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | lock: journal_entries; insert: approval_requests, audit_logs, notifications; update: journal_entries |
| **Business rules** | BR-007 |
| **Requirements** | FR-073 |
| **Pages and actions** | H9, H10<br>H9 · Submit for approval (above limit) |
| **Transaction / concurrency** | Single transaction. |

<a id="postvouchersidpost"></a>
#### `POST /api/v1/vouchers/{id}/post`

Post a draft (or approved) voucher.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` |
| **Path / query params** | `id` / — |
| **Request body** | [`PostRequest`](#schema-postrequest) |
| **Validation** | `confirm_backdated` boolean; `acknowledge_warnings` array of str; `receipt` CashReceiptInput (nullable)<br>Unknown fields are rejected (422).<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set). |
| **Success** | 200 `data`: [`Voucher`](#schema-voucher) · 202 when above the approval limit |
| **Status codes** | 200, 202, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `company_maintenance`, `insufficient_funds`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: journal_entries; insert: approval_requests, audit_logs, bill_allocations, idempotency_keys; update: journal_entries, journal_lines; read: accounting_periods |
| **Business rules** | BR-001, BR-004, BR-007, BR-009, BR-031 |
| **Requirements** | FR-073 |
| **Pages and actions** | H9, H10, H11, H12<br>H9 · Post voucher<br>H10 · Post / Submit<br>H11 · Post / Submit<br>H12 · Post / Submit |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postvouchersidreverse"></a>
#### `POST /api/v1/vouchers/{id}/reverse`

Create a linked reversing entry. System entries (source_type set) are reversed through their document's cancel endpoint, not here (409).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`ReverseRequest`](#schema-reverserequest) |
| **Validation** | `reversal_date`* YYYY-MM-DD; `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. |
| **Success** | 201 `data`: [`Voucher`](#schema-voucher) |
| **Status codes** | 201, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `already_reversed`, `company_maintenance`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: journal_entries, number_sequences; insert: audit_logs, idempotency_keys, journal_entries, journal_lines; update: bill_allocations, journal_entries, number_sequences; read: accounting_periods |
| **Business rules** | BR-003, BR-004, BR-009 |
| **Requirements** | FR-074 |
| **Pages and actions** | Acc-DlgReverse, H13<br>Acc-DlgReverse · Create reversing entry |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. The original row is locked; a second reversal hits UNIQUE(reverses_entry_id). |

<a id="postvoucherscalculate"></a>
#### `POST /api/v1/vouchers/calculate`

Authoritative balance and double-entry preview for an unsaved voucher (no write). Returns lines, totals, difference, balanced flag, whether approval will be needed and warnings (insufficient funds, back-dated). The client-side sum is only a typing indicator (DEC-09). _Contract change: R-27._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` |
| **Path / query params** | — / — |
| **Request body** | [`VoucherInput`](#schema-voucherinput) |
| **Validation** | `entry_type`* one of journal/payment/receipt/contra; `action`* one of draft/submit/post; `date`* YYYY-MM-DD; `reference` string ≤ 60 (nullable); `narration` string ≤ 1000 (nullable); `template_key` string ≤ 40 (nullable); `payment` PaymentDetailInput (nullable); `lines`* array of VoucherLineInput; `confirm_backdated` boolean<br>Unknown fields are rejected (422).<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-002: Each journal line carries a debit or a credit greater than zero, never both and never neither.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-053: A voucher dated more than the configured number of days in the past requires explicit confirmation. |
| **Success** | 200 `data`: [`VoucherPreview`](#schema-voucherpreview) |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: company_settings, journal_lines, ledgers, money_accounts, roles |
| **Business rules** | BR-001, BR-002, BR-031, BR-007, BR-053 |
| **Requirements** | FR-073 |
| **Pages and actions** | H9, H10, H11, H12, G6, G7, G8, G9<br>H9 · Add line<br>H9 · Remove line<br>H9 · Put difference on last line<br>H10 · Add line<br>H11 · Add line<br>H12 · Add line |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getvouchersidpdf"></a>
#### `GET /api/v1/vouchers/{id}/pdf`

Printable voucher (A4).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: companies, journal_entries, journal_lines |
| **Business rules** | — |
| **Requirements** | FR-074 |
| **Pages and actions** | H14, H8, H13<br>H8 · Print (row)<br>H13 · Print<br>H14 · PDF<br>H14 · Print |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getvouchertemplates"></a>
#### `GET /api/v1/voucher-templates`

Journal templates (depreciation, accruals, reclassify).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `accounting.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`TemplateList`](#schema-templatelist) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: ledgers |
| **Business rules** | — |
| **Requirements** | FR-073 |
| **Pages and actions** | H9 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

### LC


<a id="getlcoverview"></a>
#### `GET /api/v1/lc/overview`

LC KPIs, alerts, deadlines, by bank, pipeline, active, recent.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: audit_logs, lc_amendments, lc_charges, lc_documents, lc_payments, lc_shipments, letters_of_credit |
| **Business rules** | — |
| **Requirements** | FR-090 |
| **Pages and actions** | M1 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getlettersofcredit"></a>
#### `GET /api/v1/letters-of-credit`

List LCs.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `group`, `bank_account_id`, `party_id`, `attention` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`LetterOfCredit`](#schema-letterofcredit) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: lc_charges, lc_documents, lc_payments, letters_of_credit, money_accounts, parties |
| **Business rules** | — |
| **Requirements** | FR-091 |
| **Pages and actions** | M2, M5, M7, F3<br>M7 · Choose LC |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postlettersofcredit"></a>
#### `POST /api/v1/letters-of-credit`

Create a draft LC (documents copied from template).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`LetterOfCreditInput`](#schema-letterofcreditinput) |
| **Validation** | `party_id`* id of a record in this company; `purchase_order_id` id of a record in this company (nullable); `intended_import` string ≤ 255 (nullable); `goods_description`* string ≤ 500; `quantity` decimal string, 3 dp (nullable); `quantity_unit` string ≤ 20 (nullable); `import_authorization_ref` string ≤ 60 (nullable); `lc_type` one of irrevocable/irrevocable_transferable/standby; `tenor`* one of sight/usance; `usance_days` integer (nullable); `incoterm`* string ≤ 40; `currency_code`* ISO 4217; `amount`* decimal string, 2 dp; `tolerance_pct` decimal string 0–100, 4 dp; `planning_rate`* decimal string, 6 dp; `partial_shipments` boolean; `transhipment` boolean; `confirmation_required` boolean; `issuing_bank_account_id` id of a record in this company (nullable); `advising_bank` string ≤ 190 (nullable); `margin_pct` decimal string 0–100, 4 dp; `margin_account_id` id of a record in this company (nullable); `application_date` YYYY-MM-DD (nullable); `latest_shipment_date` YYYY-MM-DD (nullable); `expiry_date` YYYY-MM-DD (nullable); `presentation_days` integer; `document_template_id` id of a record in this company (nullable); `documents` array of LcDocumentInput; `record_shipments` boolean; `shipment` LcShipmentInput (nullable)<br>Unknown fields are rejected (422).<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited. |
| **Success** | 201 `data`: [`LetterOfCredit`](#schema-letterofcredit) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `duplicate`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, letters_of_credit; read: lc_document_template_items, lc_documents, lc_shipments, number_sequences |
| **Business rules** | BR-006 |
| **Requirements** | FR-092 |
| **Pages and actions** | M3, F8<br>M3 · Save draft<br>M3 · Submit to bank |
| **Transaction / concurrency** | Single transaction. |

<a id="getlettersofcreditid"></a>
#### `GET /api/v1/letters-of-credit/{id}`

LC with milestones, terms, documents, shipments, charges, payments, amendments, money position.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`LetterOfCredit`](#schema-letterofcredit) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: lc_amendment_changes, lc_amendments, lc_charges, lc_documents, lc_payments, lc_shipments, letters_of_credit |
| **Business rules** | — |
| **Requirements** | FR-093 |
| **Pages and actions** | M4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putlettersofcreditid"></a>
#### `PUT /api/v1/letters-of-credit/{id}`

Update a draft or awaiting-bank LC (opened LCs change only through amendments).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LetterOfCreditInput`](#schema-letterofcreditinput) |
| **Validation** | `party_id`* id of a record in this company; `purchase_order_id` id of a record in this company (nullable); `intended_import` string ≤ 255 (nullable); `goods_description`* string ≤ 500; `quantity` decimal string, 3 dp (nullable); `quantity_unit` string ≤ 20 (nullable); `import_authorization_ref` string ≤ 60 (nullable); `lc_type` one of irrevocable/irrevocable_transferable/standby; `tenor`* one of sight/usance; `usance_days` integer (nullable); `incoterm`* string ≤ 40; `currency_code`* ISO 4217; `amount`* decimal string, 2 dp; `tolerance_pct` decimal string 0–100, 4 dp; `planning_rate`* decimal string, 6 dp; `partial_shipments` boolean; `transhipment` boolean; `confirmation_required` boolean; `issuing_bank_account_id` id of a record in this company (nullable); `advising_bank` string ≤ 190 (nullable); `margin_pct` decimal string 0–100, 4 dp; `margin_account_id` id of a record in this company (nullable); `application_date` YYYY-MM-DD (nullable); `latest_shipment_date` YYYY-MM-DD (nullable); `expiry_date` YYYY-MM-DD (nullable); `presentation_days` integer; `document_template_id` id of a record in this company (nullable); `documents` array of LcDocumentInput; `record_shipments` boolean; `shipment` LcShipmentInput (nullable)<br>Unknown fields are rejected (422).<br>BR-040: LC status follows the state machine in 06 section 12.1; closed and cancelled LCs are read-only.<br>BR-041: LC amendment changes apply to the LC only when the amendment is accepted; each accepted change is recorded with old and new values.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved. |
| **Success** | 200 `data`: [`LetterOfCredit`](#schema-letterofcredit) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `invalid_transition`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: letters_of_credit; read: lc_documents, lc_shipments |
| **Business rules** | BR-040, BR-041, BR-049 |
| **Requirements** | FR-092 |
| **Pages and actions** | M3<br>M3 · Save draft<br>M3 · Submit to bank<br>M3 · Change template |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="deletelettersofcreditid"></a>
#### `DELETE /api/v1/letters-of-credit/{id}`

Delete a draft LC.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.delete` — roles: Owner |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed. |
| **Success** | 204 — |
| **Status codes** | 204, 400, 401, 403, 404, 409, 503 |
| **Error codes** | **`company_maintenance`, `not_draft`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | insert: audit_logs; delete: letters_of_credit; read: lc_documents, lc_shipments |
| **Business rules** | BR-025 |
| **Requirements** | FR-092 |
| **Pages and actions** | M3<br>M3 · Delete draft (NEW) |
| **Transaction / concurrency** | Single transaction. |

<a id="postlettersofcreditidtransition"></a>
#### `POST /api/v1/letters-of-credit/{id}/transition`

Change status: submit, mark_opened, mark_refused, documents_presented, cancel, expire, reopen.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcTransition`](#schema-lctransition) |
| **Validation** | `action`* one of submit/mark_opened/mark_refused/documents_presented/payment_due/cancel/expire/reopen; `bank_reference` string ≤ 60 (nullable); `date` YYYY-MM-DD (nullable); `reason` string ≤ 255 (nullable)<br>Unknown fields are rejected (422).<br>BR-039: Submitting an LC to the bank requires supplier, amount > 0, issuing bank and expiry date; marking it opened requires the bank reference and opening date.<br>BR-040: LC status follows the state machine in 06 section 12.1; closed and cancelled LCs are read-only. |
| **Success** | 200 `data`: [`LetterOfCredit`](#schema-letterofcredit) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`, `invalid_transition`, `lc_submit_incomplete`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | lock: letters_of_credit; insert: audit_logs; update: letters_of_credit |
| **Business rules** | BR-039, BR-040 |
| **Requirements** | FR-092, FR-093 |
| **Pages and actions** | M3, M4<br>M3 · Submit to bank |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="postlettersofcreditidclose"></a>
#### `POST /api/v1/letters-of-credit/{id}/close`

Close after checks (or with override reason); margin release posted.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcCloseRequest`](#schema-lccloserequest) |
| **Validation** | `override_reason` string ≤ 255 (nullable); `release_margin_to_account_id` id of a record in this company (nullable)<br>Unknown fields are rejected (422).<br>BR-046: An LC can be closed only when the supplier is fully paid (or the LC expired), no charges are unpaid and no documents are missing or discrepant, or with an explicit override reason.<br>BR-044: An LC margin deposit is a refundable asset, never part of landed cost; it is applied to the payment or released at closure. The margin applied to payments may not exceed the margin deposited for that LC.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0. |
| **Success** | 200 `data`: [`LetterOfCredit`](#schema-letterofcredit) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `lc_not_closable`, `margin_exceeds_deposit`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: letters_of_credit, number_sequences; insert: audit_logs, idempotency_keys, journal_entries, journal_lines; update: lc_charges, letters_of_credit, number_sequences; read: lc_documents, lc_payments |
| **Business rules** | BR-046, BR-044, BR-001 |
| **Requirements** | FR-099 |
| **Pages and actions** | LC-DlgClose, M4<br>LC-DlgClose · Close LC |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getlettersofcreditiddocuments"></a>
#### `GET /api/v1/letters-of-credit/{id}/documents`

Documents of an LC.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`LcDocument`](#schema-lcdocument) + `meta` |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: attachments, lc_documents |
| **Business rules** | — |
| **Requirements** | FR-095 |
| **Pages and actions** | M4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postlettersofcreditiddocuments"></a>
#### `POST /api/v1/letters-of-credit/{id}/documents`

Add a required document.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcDocumentInput`](#schema-lcdocumentinput) |
| **Validation** | `name`* string ≤ 120; `provided_by`* string ≤ 80; `copies` integer; `note` string ≤ 255 (nullable); `is_required` boolean; `status` one of not_yet_due/pending/received/missing/discrepant/not_required; `due_date` YYYY-MM-DD (nullable); `received_date` YYYY-MM-DD (nullable); `discrepancy_note` string ≤ 500 (nullable); `lc_shipment_id` id of a record in this company (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`LcDocument`](#schema-lcdocument) |
| **Status codes** | 201, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, lc_documents |
| **Business rules** | — |
| **Requirements** | FR-095 |
| **Pages and actions** | M4, M3<br>M3 · Add document<br>M4 · Add document |
| **Transaction / concurrency** | Single transaction. |

<a id="patchlcdocumentsid"></a>
#### `PATCH /api/v1/lc-documents/{id}`

Update document status, dates, discrepancy note.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcDocumentInput`](#schema-lcdocumentinput) |
| **Validation** | `name`* string ≤ 120; `provided_by`* string ≤ 80; `copies` integer; `note` string ≤ 255 (nullable); `is_required` boolean; `status` one of not_yet_due/pending/received/missing/discrepant/not_required; `due_date` YYYY-MM-DD (nullable); `received_date` YYYY-MM-DD (nullable); `discrepancy_note` string ≤ 500 (nullable); `lc_shipment_id` id of a record in this company (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`LcDocument`](#schema-lcdocument) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: lc_documents |
| **Business rules** | — |
| **Requirements** | FR-095 |
| **Pages and actions** | M4, M6<br>M4 · Update document status |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getlcdocumentsmatrix"></a>
#### `GET /api/v1/lc-documents/matrix`

Document status matrix across open LCs and follow-up list.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: lc_documents, letters_of_credit |
| **Business rules** | — |
| **Requirements** | FR-095 |
| **Pages and actions** | M6 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getlcdocumenttemplates"></a>
#### `GET /api/v1/lc-document-templates`

Checklist templates with items.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`LcDocumentTemplate`](#schema-lcdocumenttemplate) + `meta` |
| **Status codes** | 200, 400, 401, 403, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: lc_document_template_items, lc_document_templates |
| **Business rules** | — |
| **Requirements** | FR-095 |
| **Pages and actions** | M3, M6<br>M3 · Change template |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="putlcdocumenttemplatesid"></a>
#### `PUT /api/v1/lc-document-templates/{id}`

Replace a template's items.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcDocumentTemplateInput`](#schema-lcdocumenttemplateinput) |
| **Validation** | `name`* string ≤ 80; `is_default` boolean; `items`* array of obj<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`LcDocumentTemplate`](#schema-lcdocumenttemplate) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | insert: audit_logs, lc_document_template_items; update: lc_document_templates; delete: lc_document_template_items |
| **Business rules** | — |
| **Requirements** | FR-095 |
| **Pages and actions** | M6<br>M6 · Edit templates (NEW editor, GAP-14) |
| **Transaction / concurrency** | Single transaction. |

<a id="postlcdocumenttemplates"></a>
#### `POST /api/v1/lc-document-templates`

Create a template.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`LcDocumentTemplateInput`](#schema-lcdocumenttemplateinput) |
| **Validation** | `name`* string ≤ 80; `is_default` boolean; `items`* array of obj<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`LcDocumentTemplate`](#schema-lcdocumenttemplate) |
| **Status codes** | 201, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, lc_document_template_items, lc_document_templates |
| **Business rules** | — |
| **Requirements** | FR-095 |
| **Pages and actions** | M6<br>M6 · Edit templates (NEW editor, GAP-14) |
| **Transaction / concurrency** | Single transaction. |

<a id="getlcamendments"></a>
#### `GET /api/v1/lc-amendments`

All amendments (filter pending/accepted).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `status` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`LcAmendment`](#schema-lcamendment) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: lc_amendment_changes, lc_amendments, letters_of_credit |
| **Business rules** | — |
| **Requirements** | FR-094 |
| **Pages and actions** | M5, M8 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postlettersofcreditidamendments"></a>
#### `POST /api/v1/letters-of-credit/{id}/amendments`

Record an amendment with field changes.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcAmendmentInput`](#schema-lcamendmentinput) |
| **Validation** | `requested_on`* YYYY-MM-DD; `status`* one of requested/issued/accepted; `changes`* array of AmendmentChange; `other_change` string ≤ 255 (nullable); `reason` string ≤ 500 (nullable); `bank_fee` decimal string, 2 dp<br>Unknown fields are rejected (422).<br>BR-041: LC amendment changes apply to the LC only when the amendment is accepted; each accepted change is recorded with old and new values. |
| **Success** | 201 `data`: [`LcAmendment`](#schema-lcamendment) |
| **Status codes** | 201, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | insert: audit_logs, idempotency_keys, lc_amendment_changes, lc_amendments; read: lc_charges, letters_of_credit |
| **Business rules** | BR-041 |
| **Requirements** | FR-094 |
| **Pages and actions** | M5, M4<br>M4 · Record amendment<br>M5 · Record amendment |
| **Transaction / concurrency** | Single transaction. |

<a id="patchlcamendmentsid"></a>
#### `PATCH /api/v1/lc-amendments/{id}`

Advance amendment status; on accepted the LC is updated.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcAmendmentStatus`](#schema-lcamendmentstatus) |
| **Validation** | `status`* one of issued/accepted/refused; `date`* YYYY-MM-DD<br>Unknown fields are rejected (422).<br>BR-041: LC amendment changes apply to the LC only when the amendment is accepted; each accepted change is recorded with old and new values. |
| **Success** | 200 `data`: [`LcAmendment`](#schema-lcamendment) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: lc_amendments; read: lc_amendment_changes, letters_of_credit |
| **Business rules** | BR-041 |
| **Requirements** | FR-094 |
| **Pages and actions** | M5, M4<br>M5 · Record amendment |
| **Transaction / concurrency** | Locks the LC row when applying accepted changes. |

<a id="postlettersofcreditidshipments"></a>
#### `POST /api/v1/letters-of-credit/{id}/shipments`

Add a shipment lot.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcShipmentInput`](#schema-lcshipmentinput) |
| **Validation** | `lot_no` integer; `status` one of not_shipped/booking_confirmed/at_sea/arrived/arrived_cleared/not_shipped_expired; `booking_ref` string ≤ 60 (nullable); `bl_number` string ≤ 60 (nullable); `vessel` string ≤ 120 (nullable); `port_of_loading` string ≤ 120 (nullable); `port_of_discharge` string ≤ 120 (nullable); `mode` one of sea/air/road; `etd` YYYY-MM-DD (nullable); `eta` YYYY-MM-DD (nullable); `shipped_on` YYYY-MM-DD (nullable); `arrived_on` YYYY-MM-DD (nullable); `quantity` decimal string, 3 dp (nullable); `value_foreign` decimal string, 2 dp (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 201 `data`: [`LcShipment`](#schema-lcshipment) |
| **Status codes** | 201, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Location` on 201 |
| **Database** | insert: audit_logs, lc_shipments |
| **Business rules** | — |
| **Requirements** | FR-096 |
| **Pages and actions** | M4<br>M4 · Add/update shipment lot |
| **Transaction / concurrency** | Single transaction. |

<a id="patchlcshipmentsid"></a>
#### `PATCH /api/v1/lc-shipments/{id}`

Update a lot (status, B/L, vessel, ETD/ETA, arrived).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcShipmentInput`](#schema-lcshipmentinput) |
| **Validation** | `lot_no` integer; `status` one of not_shipped/booking_confirmed/at_sea/arrived/arrived_cleared/not_shipped_expired; `booking_ref` string ≤ 60 (nullable); `bl_number` string ≤ 60 (nullable); `vessel` string ≤ 120 (nullable); `port_of_loading` string ≤ 120 (nullable); `port_of_discharge` string ≤ 120 (nullable); `mode` one of sea/air/road; `etd` YYYY-MM-DD (nullable); `eta` YYYY-MM-DD (nullable); `shipped_on` YYYY-MM-DD (nullable); `arrived_on` YYYY-MM-DD (nullable); `quantity` decimal string, 3 dp (nullable); `value_foreign` decimal string, 2 dp (nullable)<br>Unknown fields are rejected (422). |
| **Success** | 200 `data`: [`LcShipment`](#schema-lcshipment) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 428, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `if_match_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed`, `version_conflict` |
| **Headers** | `If-Match` (required) |
| **Database** | insert: audit_logs; update: lc_shipments; read: letters_of_credit |
| **Business rules** | — |
| **Requirements** | FR-096 |
| **Pages and actions** | M4<br>M4 · Add/update shipment lot |
| **Transaction / concurrency** | Single-row write; optimistic lock via If-Match. |

<a id="getlccharges"></a>
#### `GET /api/v1/lc-charges`

Charges across LCs (filter by LC and paid status).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | — / `page`, `per_page`, `sort`, `q`, `letter_of_credit_id`, `status` |
| **Request body** | — |
| **Validation** | Query parameters only (unknown parameters: 422). |
| **Success** | 200 `data[]` of [`LcCharge`](#schema-lccharge) + `meta` |
| **Status codes** | 200, 400, 401, 403, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: lc_charges, letters_of_credit |
| **Business rules** | — |
| **Requirements** | FR-097 |
| **Pages and actions** | M7 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postlccharges"></a>
#### `POST /api/v1/lc-charges`

Add a charge or import expense (posts accrual or payment).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | — / — |
| **Request body** | [`LcChargeInput`](#schema-lcchargeinput) |
| **Validation** | `letter_of_credit_id`* id of a record in this company; `charge_type`* one of margin_deposit/opening_commission/amendment_fee/swift_postage/stamp_documentation/acceptance_commission/discrepancy_fee/marine_insurance/cf_agent_fee/port_handling/customs_duty_taxes/inland_transport/other; `charge_date`* YYYY-MM-DD; `amount`* decimal string, 2 dp; `paid_to` string ≤ 150 (nullable); `payee_party_id` id of a record in this company (nullable); `status`* one of unpaid/paid; `paid_from_account_id` id of a record in this company (nullable); `include_in_landed_cost` boolean; `reference` string ≤ 60 (nullable); `lc_shipment_id` id of a record in this company (nullable); `lc_amendment_id` id of a record in this company (nullable)<br>Unknown fields are rejected (422).<br>BR-044: An LC margin deposit is a refundable asset, never part of landed cost; it is applied to the payment or released at closure. The margin applied to payments may not exceed the margin deposited for that LC.<br>BR-045: LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold.<br>BR-047: Customs duties and taxes are entered as assessed; Pluto does not calculate them.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set). |
| **Success** | 201 `data`: [`LcCharge`](#schema-lccharge) |
| **Status codes** | 201, 400, 401, 403, 409, 422, 503 |
| **Error codes** | **`charge_allocated`, `company_maintenance`, `insufficient_funds`, `margin_exceeds_deposit`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, lc_charges, payment_details; update: number_sequences; read: account_mappings, money_accounts |
| **Business rules** | BR-044, BR-045, BR-047, BR-001, BR-004, BR-031 |
| **Requirements** | FR-097 |
| **Pages and actions** | LC-DrawerCharge, M4, M7<br>LC-DrawerCharge · Add charge |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postlcchargesidreverse"></a>
#### `POST /api/v1/lc-charges/{id}/reverse`

Reverse an LC charge (refused once included in a posted landed-cost allocation). _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-045: LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold. |
| **Success** | 200 `data`: [`LcCharge`](#schema-lccharge) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `already_reversed`, `charge_allocated`, `company_maintenance`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: lc_charges, number_sequences; insert: audit_logs, idempotency_keys, journal_entries, journal_lines; update: journal_entries, lc_charges, number_sequences |
| **Business rules** | BR-003, BR-004, BR-009, BR-045 |
| **Requirements** | FR-097 |
| **Pages and actions** | M4, M7<br>M4 · Reverse payment, charge or landed-cost allocation (NEW, R-03) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postlcchargesidpay"></a>
#### `POST /api/v1/lc-charges/{id}/pay`

Pay an unpaid charge.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`ChargePayment`](#schema-chargepayment) |
| **Validation** | `paid_from_account_id`* id of a record in this company; `date`* YYYY-MM-DD; `reference` string ≤ 60 (nullable)<br>Unknown fields are rejected (422).<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected. |
| **Success** | 200 `data`: [`LcCharge`](#schema-lccharge) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `insufficient_funds`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: lc_charges, number_sequences; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, payment_details; update: lc_charges, number_sequences |
| **Business rules** | BR-031, BR-001, BR-004 |
| **Requirements** | FR-097 |
| **Pages and actions** | M4, M7<br>M4 · Pay a charge (NEW, row)<br>M7 · Pay a charge (NEW, row) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postlettersofcreditidpayments"></a>
#### `POST /api/v1/letters-of-credit/{id}/payments`

Record a payment to the beneficiary at the bank's rate (applies margin, FX).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LcPaymentInput`](#schema-lcpaymentinput) |
| **Validation** | `payment_kind` one of sight_payment/usance_acceptance/usance_settlement; `payment_date`* YYYY-MM-DD; `amount_foreign`* decimal string, 2 dp; `bank_rate`* decimal string, 6 dp; `money_account_id`* id of a record in this company; `margin_applied` decimal string, 2 dp; `lc_shipment_id` id of a record in this company (nullable); `purchase_bill_id` id of a record in this company (nullable); `bank_reference` string ≤ 60 (nullable); `description` string ≤ 150 (nullable)<br>Unknown fields are rejected (422).<br>BR-042: LC payment taka amount = foreign amount x bank rate (rounded to 2 dp); total paid may not exceed LC amount x (1 + tolerance).<br>BR-043: Differences between the rate at which a foreign bill was booked and the rate at which it is paid post to the exchange gain or loss ledger.<br>BR-044: An LC margin deposit is a refundable asset, never part of landed cost; it is applied to the payment or released at closure. The margin applied to payments may not exceed the margin deposited for that LC.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set). |
| **Success** | 201 `data`: [`LcPayment`](#schema-lcpayment) |
| **Status codes** | 201, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`company_maintenance`, `exceeds_tolerance`, `insufficient_funds`, `margin_exceeds_deposit`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: letters_of_credit, number_sequences, purchase_bills; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines, lc_payments; update: letters_of_credit, number_sequences, purchase_bills; read: account_mappings, money_accounts |
| **Business rules** | BR-042, BR-043, BR-044, BR-001, BR-004, BR-031 |
| **Requirements** | FR-097 |
| **Pages and actions** | LC-DlgPay, M4<br>LC-DlgPay · Record payment |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getlettersofcreditidpayments"></a>
#### `GET /api/v1/letters-of-credit/{id}/payments`

Payments of an LC with bank rate, margin applied and FX difference. _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data[]` of [`LcPayment`](#schema-lcpayment) + `meta` |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: journal_entries, lc_payments |
| **Business rules** | — |
| **Requirements** | FR-097 |
| **Pages and actions** | M4 |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postlcpaymentsidreverse"></a>
#### `POST /api/v1/lc-payments/{id}/reverse`

Reverse an LC payment (restores margin and supplier balance; mirror entry). _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-040: LC status follows the state machine in 06 section 12.1; closed and cancelled LCs are read-only. |
| **Success** | 200 `data`: [`LcPayment`](#schema-lcpayment) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `already_reversed`, `company_maintenance`, `invalid_transition`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: lc_payments, letters_of_credit, number_sequences, purchase_bills; insert: audit_logs, bill_allocations, idempotency_keys, journal_entries, journal_lines; update: bill_allocations, journal_entries, lc_payments, letters_of_credit, number_sequences, purchase_bills |
| **Business rules** | BR-003, BR-004, BR-009, BR-040 |
| **Requirements** | FR-097 |
| **Pages and actions** | M4<br>M4 · Reverse payment, charge or landed-cost allocation (NEW, R-03) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postlettersofcreditidlandedcostpreview"></a>
#### `POST /api/v1/letters-of-credit/{id}/landed-cost/preview`

Preview allocation of capitalised charges to an import bill's lines.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | [`LandedCostRequest`](#schema-landedcostrequest) |
| **Validation** | `purchase_bill_id`* id of a record in this company; `allocation_date`* YYYY-MM-DD; `method` one of value/quantity; `charge_ids` array of id<br>Unknown fields are rejected (422).<br>BR-045: LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold. |
| **Success** | 200 `data`: [`LandedCostAllocation`](#schema-landedcostallocation) |
| **Status codes** | 200, 400, 401, 403, 404, 422, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | — |
| **Database** | read: lc_charges, purchase_bill_lines, stock_balances |
| **Business rules** | BR-045 |
| **Requirements** | FR-098 |
| **Pages and actions** | M4, M7<br>M4 · Allocate landed cost<br>M7 · Allocate landed cost |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="postlettersofcreditidlandedcost"></a>
#### `POST /api/v1/letters-of-credit/{id}/landed-cost`

Post a landed-cost allocation (inventory and COGS split).

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.create` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`LandedCostRequest`](#schema-landedcostrequest) |
| **Validation** | `purchase_bill_id`* id of a record in this company; `allocation_date`* YYYY-MM-DD; `method` one of value/quantity; `charge_ids` array of id<br>Unknown fields are rejected (422).<br>BR-045: LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold.<br>BR-017: On every stock-in to a warehouse, that warehouse's average cost = (warehouse value + incoming value) / (warehouse quantity + incoming quantity); when current quantity <= 0 the incoming unit cost becomes the average.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result. |
| **Success** | 201 `data`: [`LandedCostAllocation`](#schema-landedcostallocation) |
| **Status codes** | 201, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `charge_allocated`, `company_maintenance`, `period_locked`, `unbalanced_entry`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key`, `Location` on 201 |
| **Database** | lock: number_sequences, stock_balances; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, landed_cost_allocation_lines, landed_cost_allocations, stock_movements; update: lc_charges, number_sequences, purchase_bill_lines, stock_balances |
| **Business rules** | BR-045, BR-017, BR-001, BR-004, BR-009 |
| **Requirements** | FR-098 |
| **Pages and actions** | M4, M7<br>M4 · Allocate landed cost<br>M7 · Allocate landed cost |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="postlandedcostallocationsidreverse"></a>
#### `POST /api/v1/landed-cost-allocations/{id}/reverse`

Reverse a landed-cost allocation (inventory/COGS split and landed_cost movements). _Contract change: R-03._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.edit` — roles: Owner, Finance manager, Accountant |
| **Path / query params** | `id` / — |
| **Request body** | [`ReasonRequest`](#schema-reasonrequest) |
| **Validation** | `reason`* string ≤ 255<br>Unknown fields are rejected (422).<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.<br>BR-045: LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold.<br>BR-017: On every stock-in to a warehouse, that warehouse's average cost = (warehouse value + incoming value) / (warehouse quantity + incoming quantity); when current quantity <= 0 the incoming unit cost becomes the average. |
| **Success** | 200 `data`: [`LandedCostAllocation`](#schema-landedcostallocation) |
| **Status codes** | 200, 400, 401, 403, 404, 409, 422, 503 |
| **Error codes** | **`already_posted`, `already_reversed`, `charge_allocated`, `company_maintenance`, `period_locked`, `use_document_cancel`**; standard: `company_required`, `idempotency_in_progress`, `idempotency_key_reused`, `malformed_json`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated`, `validation_failed` |
| **Headers** | `Idempotency-Key` |
| **Database** | lock: landed_cost_allocations, number_sequences, stock_balances; insert: audit_logs, idempotency_keys, journal_entries, journal_lines, landed_cost_allocation_lines, stock_movements; update: journal_entries, landed_cost_allocations, lc_charges, number_sequences, stock_balances |
| **Business rules** | BR-003, BR-004, BR-009, BR-045, BR-017 |
| **Requirements** | FR-098 |
| **Pages and actions** | M4, M7<br>M4 · Reverse payment, charge or landed-cost allocation (NEW, R-03) |
| **Transaction / concurrency** | One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks. |

<a id="getlettersofcreditidpdf"></a>
#### `GET /api/v1/letters-of-credit/{id}/pdf`

Printable LC summary. _Contract change: C-01._

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 file (`application/pdf`) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: companies, lc_charges, lc_documents, lc_payments, lc_shipments, letters_of_credit, parties |
| **Business rules** | — |
| **Requirements** | FR-093 |
| **Pages and actions** | M4<br>M4 · Print LC summary |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

<a id="getlettersofcreditidcostsummary"></a>
#### `GET /api/v1/letters-of-credit/{id}/cost-summary`

Landed cost breakdown and per-unit cost.

| | |
|---|---|
| **Auth** | Bearer JWT + `X-Company-Id` |
| **Permission** | `lc.view` — roles: Owner, Finance manager, Accountant, Viewer |
| **Path / query params** | `id` / — |
| **Request body** | — |
| **Validation** | — |
| **Success** | 200 `data`: [`ReportData`](#schema-reportdata) |
| **Status codes** | 200, 400, 401, 403, 404, 503 |
| **Error codes** | **`company_maintenance`**; standard: `company_required`, `not_a_member`, `not_found`, `permission_denied`, `server_error`, `token_expired`, `token_invalid`, `unauthenticated` |
| **Headers** | — |
| **Database** | read: landed_cost_allocations, lc_charges, lc_payments, purchase_bills |
| **Business rules** | — |
| **Requirements** | FR-098 |
| **Pages and actions** | M7, M4<br>M4 · Open cost summary<br>M7 · Choose LC |
| **Transaction / concurrency** | Read-only; consistent snapshot. |

## 10. Schemas

Shorthand types: `money`/`rate`/`qty`/`cost`/`pct` are decimal strings; `id` is int64; `ccy` is an ISO 4217 code; `[x]` is an array; `| null` means nullable. Exact JSON Schema is in `openapi.yaml`.


<a id="schema-error"></a>
#### `Error`

RFC 9457 problem details (application/problem+json), extended with a stable application error code (see the error catalogue), the request id and, for 422, field errors keyed by JSON path (e.g. lines.2.quantity).

| Property | Type | Required |
|---|---|---|
| `type` | `str` | yes |
| `title` | `str` | yes |
| `status` | `int` | yes |
| `code` | `errcode` | yes |
| `detail` | `str \| null` | no |
| `instance` | `str \| null` | no |
| `request_id` | `uuid` | yes |
| `errors` | `errmap \| null` | no |
| `warnings` | `[ref:Warning]` | no |

<a id="schema-userref"></a>
#### `UserRef`

Minimal user reference.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |

<a id="schema-partyref"></a>
#### `PartyRef`

Minimal party reference.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `code` | `str` | yes |
| `name` | `str` | yes |

<a id="schema-pagemeta"></a>
#### `PageMeta`

Pagination metadata.

| Property | Type | Required |
|---|---|---|
| `current_page` | `int` | yes |
| `per_page` | `int` | yes |
| `total` | `int` | yes |
| `last_page` | `int` | yes |

<a id="schema-binary"></a>
#### `Binary`

File response (application/pdf, xlsx, csv).


<a id="schema-lookup"></a>
#### `Lookup`

Simple named record (category, stock group, unit).

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `parent_id` | `id \| null` | no |
| `code_prefix` | `str \| null` | no |
| `items_count` | `int \| null` | no |
| `stock_value` | `money \| null` | no |

<a id="schema-lookupinput"></a>
#### `LookupInput`

Create or edit a product category or stock group. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `name` | `str:80` | yes |
| `parent_id` | `id \| null` | no |
| `code_prefix` | `str:10 \| null` | no |
| `default_unit_id` | `id \| null` | no |
| `default_stock_group_id` | `id \| null` | no |
| `sort_order` | `int \| null` | no |

<a id="schema-unitinput"></a>
#### `UnitInput`

Add a unit of measure (D2 inline). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `name` | `str:30` | yes |
| `decimals` | `int` | no |

<a id="schema-reasonrequest"></a>
#### `ReasonRequest`

A required reason. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `reason` | `str:255` | yes |

<a id="schema-statuschange"></a>
#### `StatusChange`

Set a document status. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `status` | `enum:accepted\|declined\|expired\|closed\|cancelled` | yes |
| `reason` | `str:255 \| null` | no |

<a id="schema-bulkids"></a>
#### `BulkIds`

Ids for a bulk action. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `ids` | `[id]` | yes |

<a id="schema-bulkresult"></a>
#### `BulkResult`

Per-item outcome of a bulk action.

| Property | Type | Required |
|---|---|---|
| `succeeded` | `[id]` | yes |
| `failed` | `[ref:BulkFailure]` | yes |

<a id="schema-bulkfailure"></a>
#### `BulkFailure`

One failed item.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `code` | `str` | yes |
| `detail` | `str` | yes |

<a id="schema-postrequest"></a>
#### `PostRequest`

Post a document. confirm_backdated is required when the date is older than the warning window (BR-053). `receipt` (sales invoices only) records the cash received in the same transaction (R-28). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `confirm_backdated` | `bool` | no |
| `acknowledge_warnings` | `[str]` | no |
| `receipt` | `ref:CashReceiptInput \| null` | no |

<a id="schema-cashreceiptinput"></a>
#### `CashReceiptInput`

Money received with a cash sale; amount must equal the invoice total. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `money_account_id` | `id` | yes |
| `method` | `enum:cash\|cheque\|bank_transfer\|beftn\|rtgs\|npsb\|bkash\|nagad\|card` | yes |
| `amount` | `money` | yes |
| `transaction_reference` | `str:60 \| null` | no |

<a id="schema-cancelrequest"></a>
#### `CancelRequest`

Cancel a posted document. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `reason` | `enum:customer_cancelled\|entered_by_mistake\|wrong_party\|duplicate\|other` | yes |
| `note` | `str:255 \| null` | no |
| `cancellation_date` | `date \| null` | no |

<a id="schema-reverserequest"></a>
#### `ReverseRequest`

Reverse a posted voucher. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `reversal_date` | `date` | yes |
| `reason` | `str:255` | yes |

<a id="schema-emailrequest"></a>
#### `EmailRequest`

Send a document by e-mail. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `to` | `[email]` | yes |
| `cc` | `[email]` | no |
| `message` | `text \| null` | no |

<a id="schema-fileupload"></a>
#### `FileUpload`

multipart/form-data with a `file` part. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `file` | `str` | yes |

<a id="schema-signedurl"></a>
#### `SignedUrl`

Short-lived download link.

| Property | Type | Required |
|---|---|---|
| `url` | `str` | yes |
| `expires_at` | `datetime` | yes |

<a id="schema-reportdata"></a>
#### `ReportData`

Generic report payload: header, columns, rows, totals and checks. Every amount is a decimal string.

| Property | Type | Required |
|---|---|---|
| `title` | `str` | yes |
| `period` | `obj` | yes |
| `basis` | `str \| null` | no |
| `columns` | `[ref:ReportColumn]` | yes |
| `rows` | `[obj]` | yes |
| `totals` | `obj \| null` | no |
| `checks` | `[ref:ReportCheck]` | no |
| `meta` | `obj \| null` | no |

<a id="schema-reportcolumn"></a>
#### `ReportColumn`

Report column definition.

| Property | Type | Required |
|---|---|---|
| `key` | `str` | yes |
| `label` | `str` | yes |
| `type` | `enum:text\|amount\|qty\|pct\|date\|code` | yes |

<a id="schema-reportcheck"></a>
#### `ReportCheck`

Agreement or health check on a report.

| Property | Type | Required |
|---|---|---|
| `key` | `str` | yes |
| `ok` | `bool` | yes |
| `message` | `str` | yes |

<a id="schema-loginrequest"></a>
#### `LoginRequest`

E-mail and password. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `email` | `email` | yes |
| `password` | `str:200` | yes |
| `device_label` | `str:120 \| null` | no |

<a id="schema-loginresponse"></a>
#### `LoginResponse`

Either tokens, or a two-factor challenge.

| Property | Type | Required |
|---|---|---|
| `status` | `enum:authenticated\|two_factor_required` | yes |
| `access_token` | `str \| null` | no |
| `token_type` | `str \| null` | no |
| `expires_in` | `int \| null` | no |
| `challenge_token` | `str \| null` | no |
| `user` | `ref:Me \| null` | no |

<a id="schema-twofactorrequest"></a>
#### `TwoFactorRequest`

Second step. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `challenge_token` | `str` | yes |
| `code` | `str:6 \| null` | no |
| `recovery_code` | `str:20 \| null` | no |

<a id="schema-tokenresponse"></a>
#### `TokenResponse`

New access token (refresh cookie rotated).

| Property | Type | Required |
|---|---|---|
| `access_token` | `str` | yes |
| `token_type` | `str` | yes |
| `expires_in` | `int` | yes |

<a id="schema-forgotpasswordrequest"></a>
#### `ForgotPasswordRequest`

Request a reset link. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `email` | `email` | yes |

<a id="schema-resetpasswordrequest"></a>
#### `ResetPasswordRequest`

Set a new password. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `token` | `str` | yes |
| `email` | `email` | yes |
| `password` | `str:200` | yes |
| `password_confirmation` | `str:200` | yes |

<a id="schema-changepasswordrequest"></a>
#### `ChangePasswordRequest`

Change own password. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `current_password` | `str` | yes |
| `password` | `str:200` | yes |
| `password_confirmation` | `str:200` | yes |

<a id="schema-passwordconfirm"></a>
#### `PasswordConfirm`

Re-enter password. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `password` | `str` | yes |

<a id="schema-twofactorsetup"></a>
#### `TwoFactorSetup`

TOTP enrolment.

| Property | Type | Required |
|---|---|---|
| `otpauth_uri` | `str` | yes |
| `qr_svg` | `text` | yes |

<a id="schema-twofactorconfirm"></a>
#### `TwoFactorConfirm`

Confirm TOTP. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `code` | `str:6` | yes |

<a id="schema-recoverycodes"></a>
#### `RecoveryCodes`

Shown once.

| Property | Type | Required |
|---|---|---|
| `recovery_codes` | `[str]` | yes |

<a id="schema-invitationpublic"></a>
#### `InvitationPublic`

Invitation seen before accepting.

| Property | Type | Required |
|---|---|---|
| `company_name` | `str` | yes |
| `role_name` | `str` | yes |
| `email` | `email` | yes |
| `expires_at` | `datetime` | yes |
| `existing_user` | `bool` | yes |

<a id="schema-acceptinvitationrequest"></a>
#### `AcceptInvitationRequest`

Accept an invitation. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `name` | `str:120 \| null` | no |
| `password` | `str:200 \| null` | no |
| `password_confirmation` | `str:200 \| null` | no |

<a id="schema-me"></a>
#### `Me`

Current user.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `email` | `email` | yes |
| `phone` | `str \| null` | no |
| `two_factor_enabled` | `bool` | yes |
| `companies` | `[ref:Membership]` | yes |
| `active_company_id` | `id \| null` | no |
| `permissions` | `[str]` | no |
| `approval_limits` | `obj \| null` | no |
| `branch_ids` | `[id]` | no |

<a id="schema-membership"></a>
#### `Membership`

Company membership.

| Property | Type | Required |
|---|---|---|
| `company_id` | `id` | yes |
| `company_name` | `str` | yes |
| `role` | `str` | yes |
| `status` | `enum:active\|invited\|deactivated` | yes |

<a id="schema-session"></a>
#### `Session`

Signed-in device.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `device_label` | `str \| null` | no |
| `user_agent` | `str \| null` | no |
| `ip_address` | `str \| null` | no |
| `last_seen_at` | `datetime \| null` | no |
| `current` | `bool` | yes |

<a id="schema-userpreferences"></a>
#### `UserPreferences`

Appearance.

| Property | Type | Required |
|---|---|---|
| `theme` | `enum:light\|dark\|system` | no |
| `density` | `enum:default\|compact` | no |
| `text_size` | `enum:s\|m\|l` | no |
| `reduce_motion` | `bool` | no |
| `sidebar_collapsed` | `bool` | no |

<a id="schema-notificationpreferences"></a>
#### `NotificationPreferences`

Per-event channels.

| Property | Type | Required |
|---|---|---|
| `events` | `[ref:NotificationPreference]` | yes |
| `daily_summary_time` | `str \| null` | no |

<a id="schema-notificationpreference"></a>
#### `NotificationPreference`

Channels for one event.

| Property | Type | Required |
|---|---|---|
| `event_key` | `str` | yes |
| `in_app` | `bool` | yes |
| `email` | `bool` | yes |
| `sms` | `bool` | yes |

<a id="schema-companycreate"></a>
#### `CompanyCreate`

New company. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `legal_name` | `str:190` | yes |
| `trading_name` | `str:190 \| null` | no |
| `business_type` | `enum:private_limited\|sole_proprietorship\|partnership\|public_limited` | yes |
| `fy_start_month` | `int` | no |
| `base_currency_code` | `ccy` | no |

<a id="schema-company"></a>
#### `Company`

Company profile.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `legal_name` | `str` | yes |
| `trading_name` | `str \| null` | no |
| `business_type` | `str` | yes |
| `trade_licence_no` | `str \| null` | no |
| `tax_registration_no` | `str \| null` | no |
| `incorporated_on` | `date \| null` | no |
| `registered_address` | `str \| null` | no |
| `phone` | `str \| null` | no |
| `email` | `email \| null` | no |
| `website` | `str \| null` | no |
| `logo_url` | `str \| null` | no |
| `base_currency_code` | `ccy` | yes |
| `fy_start_month` | `int` | yes |
| `timezone` | `str` | yes |
| `branches` | `[ref:Branch]` | no |
| `seats_used` | `int` | no |
| `seat_limit` | `int` | no |
| `version` | `int` | yes |

<a id="schema-companyupdate"></a>
#### `CompanyUpdate`

Editable company fields. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `legal_name` | `str:190` | no |
| `trading_name` | `str:190 \| null` | no |
| `business_type` | `enum:private_limited\|sole_proprietorship\|partnership\|public_limited` | no |
| `trade_licence_no` | `str:60 \| null` | no |
| `tax_registration_no` | `str:40 \| null` | no |
| `incorporated_on` | `date \| null` | no |
| `registered_address` | `str:255 \| null` | no |
| `phone` | `str:30 \| null` | no |
| `email` | `email \| null` | no |
| `website` | `str:190 \| null` | no |

<a id="schema-companysettings"></a>
#### `CompanySettings`

All company settings (see company_settings).

| Property | Type | Required |
|---|---|---|
| `valuation_method` | `enum:weighted_average\|fifo` | yes |
| `invoice_rounding` | `enum:none\|nearest_1\|nearest_5` | yes |
| `allow_negative_stock` | `bool` | yes |
| `require_approval` | `bool` | yes |
| `backdate_warning_days` | `int` | yes |
| `prices_include_tax` | `bool` | yes |
| `show_tax_no_on_invoice` | `bool` | yes |
| `default_warehouse_id` | `id \| null` | no |
| `default_payment_term_id` | `id \| null` | no |
| `symbol_position` | `enum:before\|after` | yes |
| `table_decimals` | `int` | yes |
| `negative_format` | `enum:parentheses\|minus` | yes |
| `number_grouping` | `enum:lakh\|million` | yes |
| `date_format` | `str` | yes |
| `week_start` | `enum:saturday\|sunday\|monday` | yes |
| `print_language` | `enum:en\|bn\|both` | yes |
| `invoice` | `obj` | yes |
| `require_two_factor` | `bool` | yes |
| `idle_timeout_minutes` | `int \| null` | no |
| `backup_enabled` | `bool` | no |
| `backup_retention_days` | `int` | no |
| `version` | `int` | yes |

<a id="schema-companysettingsupdate"></a>
#### `CompanySettingsUpdate`

Partial update; same fields as CompanySettings except version. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `valuation_method` | `enum:weighted_average\|fifo` | no |
| `invoice_rounding` | `enum:none\|nearest_1\|nearest_5` | no |
| `allow_negative_stock` | `bool` | no |
| `require_approval` | `bool` | no |
| `backdate_warning_days` | `int` | no |
| `prices_include_tax` | `bool` | no |
| `show_tax_no_on_invoice` | `bool` | no |
| `default_warehouse_id` | `id \| null` | no |
| `default_payment_term_id` | `id \| null` | no |
| `symbol_position` | `enum:before\|after` | no |
| `table_decimals` | `int` | no |
| `negative_format` | `enum:parentheses\|minus` | no |
| `number_grouping` | `enum:lakh\|million` | no |
| `date_format` | `str:20` | no |
| `week_start` | `enum:saturday\|sunday\|monday` | no |
| `print_language` | `enum:en\|bn\|both` | no |
| `invoice` | `obj` | no |
| `require_two_factor` | `bool` | no |
| `idle_timeout_minutes` | `int \| null` | no |
| `backup_enabled` | `bool` | no |
| `backup_retention_days` | `int` | no |

<a id="schema-branch"></a>
#### `Branch`

Location.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `code` | `str` | yes |
| `name` | `str` | yes |
| `kind` | `str \| null` | no |
| `address` | `str \| null` | no |
| `phone` | `str \| null` | no |
| `is_head_office` | `bool` | yes |
| `is_active` | `bool` | yes |
| `version` | `int` | yes |

<a id="schema-branchinput"></a>
#### `BranchInput`

Create or edit a location. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `code` | `str:20` | yes |
| `name` | `str:120` | yes |
| `kind` | `str:120 \| null` | no |
| `address` | `str:255 \| null` | no |
| `phone` | `str:30 \| null` | no |
| `is_head_office` | `bool` | no |
| `is_active` | `bool` | no |

<a id="schema-paymentterm"></a>
#### `PaymentTerm`

Payment terms.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `days` | `int` | yes |
| `is_default` | `bool` | yes |

<a id="schema-numbersequence"></a>
#### `NumberSequence`

Numbering series.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `document_type` | `str` | yes |
| `prefix` | `str` | yes |
| `next_number` | `int` | yes |
| `padding` | `int` | yes |
| `reset_policy` | `enum:fiscal_year\|never` | yes |
| `preview` | `str` | yes |
| `version` | `int` | yes |

<a id="schema-numbersequenceupdate"></a>
#### `NumberSequenceUpdate`

Change a series. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `prefix` | `str:20` | no |
| `next_number` | `int` | no |
| `padding` | `int` | no |

<a id="schema-taxrule"></a>
#### `TaxRule`

Tax rule.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `rate` | `pct` | yes |
| `applies_to` | `enum:sales\|purchases\|both` | yes |
| `ledger_id` | `id` | yes |
| `ledger_name` | `str` | yes |
| `is_recoverable` | `bool` | yes |
| `is_active` | `bool` | yes |
| `version` | `int` | yes |

<a id="schema-taxruleinput"></a>
#### `TaxRuleInput`

Create or edit a tax rule. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `name` | `str:80` | yes |
| `rate` | `pct` | yes |
| `applies_to` | `enum:sales\|purchases\|both` | yes |
| `ledger_id` | `id \| null` | no |
| `create_ledger` | `bool` | no |
| `is_recoverable` | `bool` | no |
| `is_active` | `bool` | no |

<a id="schema-accountmappings"></a>
#### `AccountMappings`

Mapping key -> ledger or group.

| Property | Type | Required |
|---|---|---|
| `mappings` | `[ref:AccountMapping]` | yes |

<a id="schema-accountmapping"></a>
#### `AccountMapping`

One posting mapping.

| Property | Type | Required |
|---|---|---|
| `mapping_key` | `str` | yes |
| `ledger_id` | `id \| null` | no |
| `account_group_id` | `id \| null` | no |
| `required_nature` | `str` | yes |
| `description` | `str \| null` | no |

<a id="schema-fiscalyear"></a>
#### `FiscalYear`

Financial year with periods.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `start_date` | `date` | yes |
| `end_date` | `date` | yes |
| `status` | `enum:open\|closing\|closed` | yes |
| `periods` | `[ref:AccountingPeriod]` | yes |

<a id="schema-accountingperiod"></a>
#### `AccountingPeriod`

Monthly period.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `start_date` | `date` | yes |
| `end_date` | `date` | yes |
| `status` | `enum:future\|open\|locked` | yes |
| `locked_at` | `datetime \| null` | no |
| `locked_by` | `ref:UserRef \| null` | no |
| `lock_note` | `str \| null` | no |

<a id="schema-periodlockrequest"></a>
#### `PeriodLockRequest`

Lock a period. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `note` | `str:255 \| null` | no |

<a id="schema-member"></a>
#### `Member`

Company member.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `user_id` | `id` | yes |
| `name` | `str` | yes |
| `email` | `email` | yes |
| `role_id` | `id` | yes |
| `role_name` | `str` | yes |
| `status` | `enum:active\|invited\|deactivated` | yes |
| `last_active_at` | `datetime \| null` | no |
| `two_factor_enabled` | `bool` | yes |
| `branch_ids` | `[id]` | no |
| `permissions` | `[str]` | no |
| `recent_activity` | `[ref:AuditLog]` | no |
| `version` | `int` | yes |

<a id="schema-memberupdate"></a>
#### `MemberUpdate`

Change role or branches. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `role_id` | `id` | no |
| `branch_ids` | `[id]` | no |

<a id="schema-invitation"></a>
#### `Invitation`

Pending invitation.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `email` | `email` | yes |
| `name` | `str \| null` | no |
| `role_id` | `id` | yes |
| `expires_at` | `datetime` | yes |
| `status` | `enum:pending\|accepted\|revoked\|expired` | yes |

<a id="schema-invitationcreate"></a>
#### `InvitationCreate`

Invite a user. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `email` | `email` | yes |
| `name` | `str:120 \| null` | no |
| `role_id` | `id` | yes |
| `branch_ids` | `[id]` | no |

<a id="schema-role"></a>
#### `Role`

Role with permissions.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `slug` | `str` | yes |
| `description` | `str \| null` | no |
| `is_system` | `bool` | yes |
| `members_count` | `int` | yes |
| `permissions` | `[str]` | yes |
| `payment_approval_limit` | `money \| null` | no |
| `journal_approval_limit` | `money \| null` | no |
| `version` | `int` | yes |

<a id="schema-roleinput"></a>
#### `RoleInput`

Create or replace a custom role. Both approval limits are required (0.00 = everything needs approval); only the system Owner role has no limit (R-10). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `name` | `str:60` | yes |
| `description` | `str:255 \| null` | no |
| `permissions` | `[str]` | yes |
| `payment_approval_limit` | `money` | yes |
| `journal_approval_limit` | `money` | yes |
| `copy_from_role_id` | `id \| null` | no |

<a id="schema-permissionlist"></a>
#### `PermissionList`

Permission catalogue grouped by module.

| Property | Type | Required |
|---|---|---|
| `modules` | `[obj]` | yes |

<a id="schema-searchresults"></a>
#### `SearchResults`

Grouped search hits.

| Property | Type | Required |
|---|---|---|
| `query` | `str` | yes |
| `groups` | `[ref:SearchGroup]` | yes |

<a id="schema-searchgroup"></a>
#### `SearchGroup`

Hits of one type.

| Property | Type | Required |
|---|---|---|
| `type` | `enum:party\|sales_invoice\|purchase_bill\|voucher\|ledger\|product\|letter_of_credit` | yes |
| `count` | `int` | yes |
| `items` | `[ref:SearchHit]` | yes |

<a id="schema-searchhit"></a>
#### `SearchHit`

One hit.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `title` | `str` | yes |
| `subtitle` | `str \| null` | no |
| `badge` | `str \| null` | no |
| `url` | `str` | yes |

<a id="schema-notification"></a>
#### `Notification`

In-app notification.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `event_key` | `str` | yes |
| `title` | `str` | yes |
| `body` | `str \| null` | no |
| `category` | `enum:approvals\|alerts\|info` | yes |
| `link_type` | `str \| null` | no |
| `link_id` | `id \| null` | no |
| `read_at` | `datetime \| null` | no |
| `created_at` | `datetime` | yes |

<a id="schema-approvalrequest"></a>
#### `ApprovalRequest`

Approval request.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `document_type` | `str` | yes |
| `document_id` | `id` | yes |
| `document_number` | `str` | yes |
| `amount` | `money` | yes |
| `limit` | `money \| null` | no |
| `required_permission` | `str` | yes |
| `status` | `enum:pending\|approved\|rejected\|cancelled` | yes |
| `requested_by` | `ref:UserRef` | yes |
| `requested_at` | `datetime` | yes |
| `decided_by` | `ref:UserRef \| null` | no |
| `decided_at` | `datetime \| null` | no |
| `comment` | `str \| null` | no |
| `facts` | `obj \| null` | no |

<a id="schema-approvaldecision"></a>
#### `ApprovalDecision`

Approve or reject. Comment required to reject. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `comment` | `str:500 \| null` | no |

<a id="schema-attachment"></a>
#### `Attachment`

Uploaded file.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `original_name` | `str` | yes |
| `mime_type` | `str` | yes |
| `size_bytes` | `int` | yes |
| `uploaded_by` | `ref:UserRef` | yes |
| `created_at` | `datetime` | yes |

<a id="schema-attachmentupload"></a>
#### `AttachmentUpload`

multipart: file, attachable_type, attachable_id. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `file` | `str` | yes |
| `attachable_type` | `enum:sales_invoice\|purchase_bill\|voucher\|lc_document\|letter_of_credit\|stock_adjustment\|party` | yes |
| `attachable_id` | `id` | yes |

<a id="schema-auditlog"></a>
#### `AuditLog`

Audit entry.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `action` | `str` | yes |
| `user` | `ref:UserRef \| null` | no |
| `old_values` | `obj \| null` | no |
| `new_values` | `obj \| null` | no |
| `ip_address` | `str \| null` | no |
| `created_at` | `datetime` | yes |

<a id="schema-dashboardsummary"></a>
#### `DashboardSummary`

A1 payload.

| Property | Type | Required |
|---|---|---|
| `period` | `obj` | yes |
| `kpis` | `[ref:Kpi]` | yes |
| `revenue_vs_expenses` | `[obj]` | yes |
| `cash_position` | `[obj]` | yes |
| `money_owed` | `obj` | yes |
| `upcoming_payments` | `[obj]` | yes |
| `recent_transactions` | `[obj]` | yes |
| `inventory_alerts` | `[obj]` | yes |
| `banners` | `[obj]` | no |

<a id="schema-kpi"></a>
#### `Kpi`

Stat tile.

| Property | Type | Required |
|---|---|---|
| `key` | `str` | yes |
| `label` | `str` | yes |
| `value` | `money` | yes |
| `delta_pct` | `pct \| null` | no |
| `trend` | `[money]` | no |
| `foot` | `str \| null` | no |

<a id="schema-lensdata"></a>
#### `LensData`

A2-A8 payload.

| Property | Type | Required |
|---|---|---|
| `lens` | `enum:financial\|sales\|expenses\|receivables\|payables\|cash\|inventory` | yes |
| `kpis` | `[ref:Kpi]` | yes |
| `series` | `[obj]` | no |
| `table` | `ref:ReportData` | no |

<a id="schema-backup"></a>
#### `Backup`

Company backup.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `kind` | `enum:automatic\|manual\|pre_restore\|pre_lock` | yes |
| `status` | `enum:running\|complete\|failed\|expired` | yes |
| `size_bytes` | `int \| null` | no |
| `started_at` | `datetime` | yes |
| `finished_at` | `datetime \| null` | no |
| `created_by` | `ref:UserRef \| null` | no |

<a id="schema-restorerequest"></a>
#### `RestoreRequest`

Restore confirmation. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `confirmation` | `enum:RESTORE` | yes |

<a id="schema-exportjob"></a>
#### `ExportJob`

Async export.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `kind` | `enum:export_pack\|report\|list` | yes |
| `status` | `enum:queued\|running\|complete\|failed` | yes |
| `download_url` | `str \| null` | no |
| `expires_at` | `datetime \| null` | no |
| `finished_at` | `datetime \| null` | no |
| `error` | `str \| null` | no |

<a id="schema-exportjobcreate"></a>
#### `ExportJobCreate`

Start an export. export_pack: month-end pack (`period`, `reports`). list: any list screen, `source` = the list operationId (e.g. getParties) and `filters` = its query parameters; the caller needs that list's view permission as well. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `kind` | `enum:export_pack\|list` | yes |
| `format` | `enum:xlsx\|pdf\|both\|csv` | yes |
| `period` | `str:20 \| null` | no |
| `reports` | `[str]` | no |
| `source` | `str:60 \| null` | no |
| `filters` | `obj \| null` | no |

<a id="schema-partyinput"></a>
#### `PartyInput`

Create or edit a customer or supplier. `mobile` is required when country_code = BD (BR-063, R-33). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_type` | `enum:customer\|supplier` | yes |
| `name` | `str:190` | yes |
| `category` | `enum:business\|individual\|walk_in\|manufacturer\|distributor\|service_provider \| null` | no |
| `party_group_id` | `id \| null` | no |
| `contact_person` | `str:120 \| null` | no |
| `mobile` | `str:20 \| null` | no |
| `email` | `email \| null` | no |
| `alt_phone` | `str:20 \| null` | no |
| `address` | `str:255 \| null` | no |
| `district` | `str:60 \| null` | no |
| `area` | `str:80 \| null` | no |
| `country_code` | `str:2` | no |
| `default_currency_code` | `ccy` | no |
| `payment_term_id` | `id \| null` | no |
| `credit_limit` | `money \| null` | no |
| `price_tier` | `enum:standard\|wholesale` | no |
| `default_purchase_ledger_id` | `id \| null` | no |
| `tax_registration_no` | `str:40 \| null` | no |
| `port_of_loading` | `str:120 \| null` | no |
| `notes` | `text \| null` | no |
| `opening_balance` | `money` | no |
| `opening_side` | `enum:dr\|cr` | no |
| `bank_accounts` | `[ref:PartyBankAccount]` | no |

<a id="schema-partybankaccount"></a>
#### `PartyBankAccount`

Supplier bank details.

| Property | Type | Required |
|---|---|---|
| `id` | `id \| null` | no |
| `bank_name` | `str:120` | yes |
| `branch` | `str:120 \| null` | no |
| `account_name` | `str:150 \| null` | no |
| `account_number` | `str:40` | yes |
| `routing_number` | `str:20 \| null` | no |
| `swift_code` | `str:11 \| null` | no |
| `is_default` | `bool` | no |

<a id="schema-party"></a>
#### `Party`

Customer or supplier.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `party_type` | `enum:customer\|supplier` | yes |
| `code` | `str` | yes |
| `name` | `str` | yes |
| `category` | `str \| null` | no |
| `group` | `ref:PartyGroup \| null` | no |
| `contact_person` | `str \| null` | no |
| `mobile` | `str \| null` | no |
| `email` | `email \| null` | no |
| `alt_phone` | `str \| null` | no |
| `address` | `str \| null` | no |
| `district` | `str \| null` | no |
| `area` | `str \| null` | no |
| `country_code` | `str` | yes |
| `default_currency_code` | `ccy` | yes |
| `payment_term` | `ref:PaymentTerm \| null` | no |
| `credit_limit` | `money \| null` | no |
| `price_tier` | `str` | yes |
| `ledger_id` | `id` | yes |
| `ledger_code` | `str` | yes |
| `tax_registration_no` | `str \| null` | no |
| `port_of_loading` | `str \| null` | no |
| `default_purchase_ledger_id` | `id \| null` | no |
| `status` | `enum:active\|inactive\|prospect` | yes |
| `balance` | `money` | yes |
| `balance_side` | `enum:dr\|cr` | yes |
| `overdue` | `money` | yes |
| `opening_balance` | `money` | yes |
| `opening_locked` | `bool` | yes |
| `bank_accounts` | `[ref:PartyBankAccount]` | no |
| `kpis` | `[ref:Kpi]` | no |
| `notes` | `text \| null` | no |
| `version` | `int` | yes |

<a id="schema-partygroup"></a>
#### `PartyGroup`

Party group.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `party_type` | `str` | yes |
| `name` | `str` | yes |

<a id="schema-partygroupinput"></a>
#### `PartyGroupInput`

Create a group. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_type` | `enum:customer\|supplier` | yes |
| `name` | `str:80` | yes |

<a id="schema-opendocuments"></a>
#### `OpenDocuments`

Open invoices or bills of a party, oldest due first, and the open credits that can be applied to them.

| Property | Type | Required |
|---|---|---|
| `party` | `ref:PartyRef` | yes |
| `documents` | `[ref:OpenDocument]` | yes |
| `credits` | `[ref:OpenCredit]` | yes |
| `total_open` | `money` | yes |
| `unapplied_credit` | `money` | yes |

<a id="schema-opencredit"></a>
#### `OpenCredit`

An unapplied receipt/payment, advance or posted note that can be allocated (R-08).

| Property | Type | Required |
|---|---|---|
| `journal_line_id` | `id` | yes |
| `kind` | `enum:receipt\|payment\|advance\|credit_note\|debit_note` | yes |
| `number` | `str` | yes |
| `date` | `date` | yes |
| `amount` | `money` | yes |
| `unapplied` | `money` | yes |

<a id="schema-opendocument"></a>
#### `OpenDocument`

One open document.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `type` | `enum:sales_invoice\|purchase_bill` | yes |
| `number` | `str` | yes |
| `date` | `date` | yes |
| `due_date` | `date` | yes |
| `total` | `money` | yes |
| `settled` | `money` | yes |
| `balance` | `money` | yes |
| `days_overdue` | `int` | yes |

<a id="schema-partytransaction"></a>
#### `PartyTransaction`

History row.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `type` | `str` | yes |
| `number` | `str` | yes |
| `date` | `date` | yes |
| `reference` | `str \| null` | no |
| `due_date` | `date \| null` | no |
| `amount` | `money` | yes |
| `status` | `str` | yes |
| `url` | `str` | yes |

<a id="schema-ledgerstatement"></a>
#### `LedgerStatement`

Ledger/book lines with running balance.

| Property | Type | Required |
|---|---|---|
| `ledger` | `obj` | yes |
| `period` | `obj` | yes |
| `opening` | `money` | yes |
| `opening_side` | `enum:dr\|cr` | yes |
| `lines` | `[ref:LedgerLine]` | yes |
| `total_debit` | `money` | yes |
| `total_credit` | `money` | yes |
| `closing` | `money` | yes |
| `closing_side` | `enum:dr\|cr` | yes |
| `monthly` | `[obj]` | no |
| `meta` | `ref:PageMeta \| null` | no |

<a id="schema-ledgerline"></a>
#### `LedgerLine`

One ledger line.

| Property | Type | Required |
|---|---|---|
| `date` | `date` | yes |
| `voucher_id` | `id` | yes |
| `voucher_number` | `str` | yes |
| `entry_type` | `str` | yes |
| `particulars` | `str` | yes |
| `narration` | `str \| null` | no |
| `debit` | `money` | yes |
| `credit` | `money` | yes |
| `balance` | `money` | yes |
| `balance_side` | `enum:dr\|cr` | yes |

<a id="schema-outstandingreport"></a>
#### `OutstandingReport`

Aging across parties.

| Property | Type | Required |
|---|---|---|
| `as_of` | `date` | yes |
| `buckets` | `[ref:AgingBucket]` | yes |
| `rows` | `[obj]` | yes |
| `totals` | `obj` | yes |

<a id="schema-agingbucket"></a>
#### `AgingBucket`

Aging bucket.

| Property | Type | Required |
|---|---|---|
| `key` | `enum:not_due\|d1_30\|d31_60\|d61_90\|d90_plus\|total` | yes |
| `amount` | `money` | yes |
| `count` | `int` | yes |

<a id="schema-partystatement"></a>
#### `PartyStatement`

Statement of account.

| Property | Type | Required |
|---|---|---|
| `statement_no` | `str` | yes |
| `party` | `ref:PartyRef` | yes |
| `period` | `obj` | yes |
| `summary` | `obj` | yes |
| `lines` | `[ref:LedgerLine]` | yes |
| `aging` | `[ref:AgingBucket] \| null` | no |
| `footer` | `str \| null` | no |

<a id="schema-statementsendrequest"></a>
#### `StatementSendRequest`

Send statements. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_ids` | `[id]` | yes |
| `date_from` | `date` | yes |
| `date_to` | `date` | yes |
| `include_aging` | `bool` | no |

<a id="schema-productinput"></a>
#### `ProductInput`

Create or edit a product or service. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `item_type` | `enum:product\|service` | yes |
| `name` | `str:190` | yes |
| `code` | `str:30 \| null` | no |
| `category_id` | `id \| null` | no |
| `unit_id` | `id` | yes |
| `barcode` | `str:40 \| null` | no |
| `last_purchase_cost` | `cost \| null` | no |
| `selling_price` | `money` | yes |
| `wholesale_price` | `money \| null` | no |
| `tax_rule_id` | `id \| null` | no |
| `income_ledger_id` | `id` | yes |
| `expense_ledger_id` | `id \| null` | no |
| `stock_group_id` | `id \| null` | no |
| `preferred_supplier_id` | `id \| null` | no |
| `is_active` | `bool` | no |
| `warehouses` | `[ref:ProductWarehouseInput]` | no |

<a id="schema-productwarehouseinput"></a>
#### `ProductWarehouseInput`

Per-warehouse settings; opening_qty only on create. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `warehouse_id` | `id` | yes |
| `reorder_level` | `qty` | no |
| `reorder_qty` | `qty` | no |
| `opening_qty` | `qty` | no |
| `opening_unit_cost` | `cost` | no |

<a id="schema-product"></a>
#### `Product`

Product or service. Cost fields omitted without inventory.view_costs (BR-052).

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `item_type` | `enum:product\|service` | yes |
| `code` | `str` | yes |
| `name` | `str` | yes |
| `category` | `ref:Lookup \| null` | no |
| `unit` | `str` | yes |
| `barcode` | `str \| null` | no |
| `selling_price` | `money` | yes |
| `wholesale_price` | `money \| null` | no |
| `tax_rule_id` | `id \| null` | no |
| `income_ledger_id` | `id` | yes |
| `track_stock` | `bool` | yes |
| `stock_group` | `ref:Lookup \| null` | no |
| `preferred_supplier` | `ref:PartyRef \| null` | no |
| `on_hand` | `qty \| null` | no |
| `stock_status` | `enum:in_stock\|low_stock\|out_of_stock\|not_tracked` | yes |
| `avg_cost` | `cost \| null` | no |
| `stock_value` | `money \| null` | no |
| `last_purchase_cost` | `cost \| null` | no |
| `warehouses` | `[ref:StockLevel]` | no |
| `is_active` | `bool` | yes |
| `version` | `int` | yes |

<a id="schema-stocklevel"></a>
#### `StockLevel`

Stock of a product in a warehouse.

| Property | Type | Required |
|---|---|---|
| `product_id` | `id` | yes |
| `product_code` | `str` | yes |
| `product_name` | `str` | yes |
| `warehouse_id` | `id` | yes |
| `warehouse_name` | `str` | yes |
| `qty_on_hand` | `qty` | yes |
| `reorder_level` | `qty` | yes |
| `reorder_qty` | `qty` | yes |
| `value` | `money \| null` | no |
| `status` | `enum:in_stock\|low_stock\|out_of_stock\|not_stocked` | yes |

<a id="schema-stockmovement"></a>
#### `StockMovement`

Stock ledger row. unit_cost and value are omitted without inventory.view_costs (BR-052).

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `moved_on` | `date` | yes |
| `movement_type` | `str` | yes |
| `product` | `ref:Lookup` | yes |
| `warehouse` | `ref:Lookup` | yes |
| `quantity_in` | `qty` | yes |
| `quantity_out` | `qty` | yes |
| `qty_balance_after` | `qty` | yes |
| `unit_cost` | `cost \| null` | no |
| `value` | `money \| null` | no |
| `source_type` | `str` | yes |
| `source_id` | `id` | yes |
| `source_number` | `str` | yes |

<a id="schema-warehouse"></a>
#### `Warehouse`

Warehouse.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `code` | `str` | yes |
| `name` | `str` | yes |
| `branch_id` | `id` | yes |
| `address` | `str \| null` | no |
| `manager` | `ref:UserRef \| null` | no |
| `phone` | `str \| null` | no |
| `inventory_ledger_id` | `id` | yes |
| `is_default_for_sales` | `bool` | yes |
| `status` | `enum:active\|planned\|inactive` | yes |
| `opens_on` | `date \| null` | no |
| `stock_value` | `money \| null` | no |
| `products_count` | `int` | yes |
| `below_reorder_count` | `int` | yes |
| `capacity_used_pct` | `pct \| null` | no |
| `version` | `int` | yes |

<a id="schema-warehouseinput"></a>
#### `WarehouseInput`

Create or edit a warehouse. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `code` | `str:20` | yes |
| `name` | `str:120` | yes |
| `branch_id` | `id` | yes |
| `address` | `str:255 \| null` | no |
| `manager_user_id` | `id \| null` | no |
| `phone` | `str:30 \| null` | no |
| `inventory_ledger_id` | `id \| null` | no |
| `is_default_for_sales` | `bool` | no |
| `status` | `enum:active\|planned\|inactive` | no |
| `opens_on` | `date \| null` | no |

<a id="schema-stockadjustmentinput"></a>
#### `StockAdjustmentInput`

Draft adjustment. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `adjustment_date` | `date` | yes |
| `warehouse_id` | `id` | yes |
| `reason` | `enum:count_difference\|damaged\|expired\|theft_or_loss\|free_samples` | yes |
| `notes` | `text \| null` | no |
| `lines` | `[ref:AdjustmentLineInput]` | yes |

<a id="schema-adjustmentlineinput"></a>
#### `AdjustmentLineInput`

Counted quantity. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `product_id` | `id` | yes |
| `counted_qty` | `qty` | yes |

<a id="schema-stockadjustment"></a>
#### `StockAdjustment`

Stock adjustment. net_value and posting_preview are omitted without inventory.view_costs (BR-052).

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `number` | `str` | yes |
| `adjustment_date` | `date` | yes |
| `warehouse_id` | `id` | yes |
| `reason` | `str` | yes |
| `status` | `enum:draft\|posted\|reversed` | yes |
| `lines` | `[obj]` | yes |
| `net_value` | `money \| null` | no |
| `journal_entry_id` | `id \| null` | no |
| `posting_preview` | `[ref:PostingLine]` | no |
| `version` | `int` | yes |

<a id="schema-countsheetpreview"></a>
#### `CountSheetPreview`

Parsed count sheet.

| Property | Type | Required |
|---|---|---|
| `lines` | `[obj]` | yes |
| `unknown_codes` | `[str]` | no |

<a id="schema-productimport"></a>
#### `ProductImport`

multipart/form-data: `file` (CSV or XLSX, max 10 MB, columns per the import template) and `dry_run` query flag. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `file` | `str` | yes |

<a id="schema-importresult"></a>
#### `ImportResult`

Outcome of an import: per-row errors on dry run, created ids on commit.

| Property | Type | Required |
|---|---|---|
| `dry_run` | `bool` | yes |
| `rows` | `int` | yes |
| `valid_rows` | `int` | yes |
| `created_ids` | `[id]` | no |
| `errors` | `[ref:ImportRowError]` | yes |

<a id="schema-importrowerror"></a>
#### `ImportRowError`

One rejected import row.

| Property | Type | Required |
|---|---|---|
| `row` | `int` | yes |
| `column` | `str \| null` | no |
| `code` | `errcode` | yes |
| `message` | `str` | yes |

<a id="schema-stocktransferinput"></a>
#### `StockTransferInput`

Draft transfer. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `from_warehouse_id` | `id` | yes |
| `to_warehouse_id` | `id` | yes |
| `dispatch_date` | `date \| null` | no |
| `vehicle` | `str:60 \| null` | no |
| `driver` | `str:120 \| null` | no |
| `notes` | `text \| null` | no |
| `lines` | `[ref:TransferLineInput]` | yes |

<a id="schema-transferlineinput"></a>
#### `TransferLineInput`

Quantity to move. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `product_id` | `id` | yes |
| `quantity` | `qty` | yes |

<a id="schema-transferreceive"></a>
#### `TransferReceive`

Received quantities (default = dispatched). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `received_date` | `date` | yes |
| `lines` | `[ref:TransferLineInput]` | no |

<a id="schema-stocktransfer"></a>
#### `StockTransfer`

Stock transfer. value_at_cost is omitted without inventory.view_costs (BR-052).

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `number` | `str` | yes |
| `from_warehouse_id` | `id` | yes |
| `to_warehouse_id` | `id` | yes |
| `status` | `enum:draft\|dispatched\|received\|cancelled` | yes |
| `dispatch_date` | `date \| null` | no |
| `received_date` | `date \| null` | no |
| `lines` | `[obj]` | yes |
| `total_units` | `qty` | yes |
| `value_at_cost` | `money \| null` | no |
| `version` | `int` | yes |

<a id="schema-salesinvoiceinput"></a>
#### `SalesInvoiceInput`

Create or replace a draft invoice. Prices are optional; defaults come from the price tier. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_id` | `id` | yes |
| `invoice_date` | `date` | yes |
| `due_date` | `date \| null` | no |
| `payment_term_id` | `id \| null` | no |
| `warehouse_id` | `id` | yes |
| `customer_po_ref` | `str:60 \| null` | no |
| `quotation_id` | `id \| null` | no |
| `branch_id` | `id \| null` | no |
| `notes` | `text \| null` | no |
| `internal_note` | `text \| null` | no |
| `lines` | `[ref:SalesLineInput]` | yes |

<a id="schema-saleslineinput"></a>
#### `SalesLineInput`

Invoice line. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `unit_price` | `money \| null` | no |
| `product_id` | `id` | yes |
| `description` | `str:255 \| null` | no |
| `quantity` | `qty` | yes |
| `discount_pct` | `pct` | no |
| `tax_rule_id` | `id \| null` | no |

<a id="schema-salesinvoice"></a>
#### `SalesInvoice`

Sales invoice.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `number` | `str` | yes |
| `status` | `enum:draft\|posted\|cancelled` | yes |
| `payment_status` | `enum:unpaid\|part_paid\|paid` | yes |
| `overdue` | `bool` | yes |
| `party` | `ref:PartyRef` | yes |
| `invoice_date` | `date` | yes |
| `due_date` | `date` | yes |
| `warehouse_id` | `id` | yes |
| `customer_po_ref` | `str \| null` | no |
| `lines` | `[ref:SalesLine]` | yes |
| `amount_settled` | `money` | yes |
| `balance_due` | `money` | yes |
| `allocations` | `[ref:Allocation]` | no |
| `journal` | `[ref:PostingLine]` | no |
| `delivery` | `obj \| null` | no |
| `posted_at` | `datetime \| null` | no |
| `cancel_reason` | `str \| null` | no |
| `subtotal` | `money` | yes |
| `discount_total` | `money` | yes |
| `tax_total` | `money` | yes |
| `rounding_adjustment` | `money` | yes |
| `total` | `money` | yes |
| `amount_in_words` | `str` | yes |
| `created_at` | `datetime` | yes |
| `created_by` | `ref:UserRef \| null` | no |
| `updated_at` | `datetime` | yes |
| `version` | `int` | yes |

<a id="schema-salesline"></a>
#### `SalesLine`

Invoice line out. Cost fields need inventory.view_costs.

| Property | Type | Required |
|---|---|---|
| `unit_price` | `money` | yes |
| `unit_cost` | `cost \| null` | no |
| `cost_amount` | `money \| null` | no |
| `returned_quantity` | `qty` | yes |
| `id` | `id` | yes |
| `line_no` | `int` | yes |
| `product_id` | `id` | yes |
| `product_code` | `str` | yes |
| `product_name` | `str` | yes |
| `unit` | `str` | yes |
| `description` | `str \| null` | no |
| `quantity` | `qty` | yes |
| `discount_pct` | `pct` | yes |
| `discount_amount` | `money` | yes |
| `tax_rule_id` | `id \| null` | no |
| `tax_rate` | `pct` | yes |
| `tax_amount` | `money` | yes |
| `net_amount` | `money` | yes |
| `line_total` | `money` | yes |

<a id="schema-deliveryupdate"></a>
#### `DeliveryUpdate`

Delivery details. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `challan_no` | `str:30 \| null` | no |
| `vehicle_no` | `str:40 \| null` | no |
| `delivered_at` | `datetime \| null` | no |

<a id="schema-documentcalculation"></a>
#### `DocumentCalculation`

Server-side totals for an unsaved document.

| Property | Type | Required |
|---|---|---|
| `lines` | `[ref:CalculatedLine]` | yes |
| `warnings` | `[ref:Warning]` | yes |
| `party_balance_before` | `money \| null` | no |
| `party_balance_after` | `money \| null` | no |
| `credit_limit` | `money \| null` | no |
| `effects` | `[str]` | no |
| `subtotal` | `money` | yes |
| `discount_total` | `money` | yes |
| `tax_total` | `money` | yes |
| `rounding_adjustment` | `money` | yes |
| `total` | `money` | yes |
| `amount_in_words` | `str` | yes |

<a id="schema-calculatedline"></a>
#### `CalculatedLine`

Calculated line.

| Property | Type | Required |
|---|---|---|
| `line_no` | `int` | yes |
| `gross` | `money` | yes |
| `discount_amount` | `money` | yes |
| `tax_amount` | `money` | yes |
| `line_total` | `money` | yes |
| `available_qty` | `qty \| null` | no |

<a id="schema-warning"></a>
#### `Warning`

Non-blocking warning.

| Property | Type | Required |
|---|---|---|
| `code` | `enum:CREDIT_LIMIT_EXCEEDED\|PARTY_OVERDUE\|LOW_STOCK\|BACKDATED\|BUDGET_NEAR_LIMIT\|DUPLICATE_PARTY\|INSUFFICIENT_FUNDS\|APPROVAL_REQUIRED` | yes |
| `message` | `str` | yes |
| `line_no` | `int \| null` | no |

<a id="schema-quotationinput"></a>
#### `QuotationInput`

Create or replace a quotation. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_id` | `id` | yes |
| `quotation_date` | `date` | yes |
| `valid_until` | `date` | yes |
| `warehouse_id` | `id \| null` | no |
| `payment_term_id` | `id \| null` | no |
| `notes` | `text \| null` | no |
| `internal_note` | `text \| null` | no |
| `lines` | `[ref:SalesLineInput]` | yes |

<a id="schema-quotation"></a>
#### `Quotation`

Quotation.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `number` | `str` | yes |
| `status` | `enum:draft\|sent\|accepted\|declined\|expired\|converted` | yes |
| `party` | `ref:PartyRef` | yes |
| `quotation_date` | `date` | yes |
| `valid_until` | `date` | yes |
| `lines` | `[ref:SalesLine]` | yes |
| `converted_invoice_id` | `id \| null` | no |
| `subtotal` | `money` | yes |
| `discount_total` | `money` | yes |
| `tax_total` | `money` | yes |
| `rounding_adjustment` | `money` | yes |
| `total` | `money` | yes |
| `amount_in_words` | `str` | yes |
| `created_at` | `datetime` | yes |
| `created_by` | `ref:UserRef \| null` | no |
| `updated_at` | `datetime` | yes |
| `version` | `int` | yes |

<a id="schema-purchasebillinput"></a>
#### `PurchaseBillInput`

Create or replace a draft bill. Foreign currency requires exchange_rate. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_id` | `id` | yes |
| `supplier_bill_no` | `str:60` | yes |
| `bill_date` | `date` | yes |
| `due_date` | `date \| null` | no |
| `payment_term_id` | `id \| null` | no |
| `warehouse_id` | `id` | yes |
| `purchase_order_id` | `id \| null` | no |
| `letter_of_credit_id` | `id \| null` | no |
| `lc_shipment_id` | `id \| null` | no |
| `currency_code` | `ccy` | no |
| `exchange_rate` | `rate` | no |
| `branch_id` | `id \| null` | no |
| `notes` | `text \| null` | no |
| `internal_note` | `text \| null` | no |
| `receipt_condition_note` | `str:255 \| null` | no |
| `lines` | `[ref:PurchaseLineInput]` | yes |

<a id="schema-purchaselineinput"></a>
#### `PurchaseLineInput`

Bill line. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `unit_cost` | `cost` | yes |
| `purchase_order_line_id` | `id \| null` | no |
| `product_id` | `id` | yes |
| `description` | `str:255 \| null` | no |
| `quantity` | `qty` | yes |
| `discount_pct` | `pct` | no |
| `tax_rule_id` | `id \| null` | no |

<a id="schema-purchasebill"></a>
#### `PurchaseBill`

Purchase bill.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `number` | `str` | yes |
| `supplier_bill_no` | `str` | yes |
| `status` | `enum:draft\|posted\|cancelled` | yes |
| `payment_status` | `enum:unpaid\|part_paid\|paid` | yes |
| `overdue` | `bool` | yes |
| `party` | `ref:PartyRef` | yes |
| `bill_date` | `date` | yes |
| `due_date` | `date` | yes |
| `warehouse_id` | `id` | yes |
| `currency_code` | `ccy` | yes |
| `exchange_rate` | `rate` | yes |
| `total_foreign` | `money \| null` | no |
| `purchase_order_id` | `id \| null` | no |
| `letter_of_credit_id` | `id \| null` | no |
| `lines` | `[obj]` | yes |
| `amount_settled` | `money` | yes |
| `balance_due` | `money` | yes |
| `allocations` | `[ref:Allocation]` | no |
| `journal` | `[ref:PostingLine]` | no |
| `posted_at` | `datetime \| null` | no |
| `subtotal` | `money` | yes |
| `discount_total` | `money` | yes |
| `tax_total` | `money` | yes |
| `rounding_adjustment` | `money` | yes |
| `total` | `money` | yes |
| `amount_in_words` | `str` | yes |
| `created_at` | `datetime` | yes |
| `created_by` | `ref:UserRef \| null` | no |
| `updated_at` | `datetime` | yes |
| `version` | `int` | yes |

<a id="schema-purchaseorderinput"></a>
#### `PurchaseOrderInput`

Create or replace a PO. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_id` | `id` | yes |
| `order_date` | `date` | yes |
| `expected_date` | `date \| null` | no |
| `warehouse_id` | `id` | yes |
| `payment_term_id` | `id \| null` | no |
| `currency_code` | `ccy` | no |
| `exchange_rate` | `rate` | no |
| `source_note` | `str:120 \| null` | no |
| `notes` | `text \| null` | no |
| `internal_note` | `text \| null` | no |
| `lines` | `[ref:PurchaseLineInput]` | yes |

<a id="schema-purchaseorder"></a>
#### `PurchaseOrder`

Purchase order.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `number` | `str` | yes |
| `status` | `enum:draft\|sent\|partly_received\|received\|closed\|cancelled` | yes |
| `party` | `ref:PartyRef` | yes |
| `order_date` | `date` | yes |
| `expected_date` | `date \| null` | no |
| `currency_code` | `ccy` | yes |
| `lines` | `[obj]` | yes |
| `total_foreign` | `money \| null` | no |
| `subtotal` | `money` | yes |
| `discount_total` | `money` | yes |
| `tax_total` | `money` | yes |
| `rounding_adjustment` | `money` | yes |
| `total` | `money` | yes |
| `amount_in_words` | `str` | yes |
| `created_at` | `datetime` | yes |
| `created_by` | `ref:UserRef \| null` | no |
| `updated_at` | `datetime` | yes |
| `version` | `int` | yes |

<a id="schema-lowstockorderrequest"></a>
#### `LowStockOrderRequest`

Rows to order. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `rows` | `[obj]` | yes |
| `order_date` | `date` | yes |

<a id="schema-returninput"></a>
#### `ReturnInput`

Sales or purchase return. stock_action: restock|write_off (sales), send_back|already_disposed (purchase). `usage` and `apply_to_document_id` decide how the note created on posting is used (R-04). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `source_document_id` | `id` | yes |
| `return_date` | `date` | yes |
| `warehouse_id` | `id` | yes |
| `stock_action` | `enum:restock\|write_off\|send_back\|already_disposed` | yes |
| `usage` | `enum:apply\|credit\|refund` | no |
| `apply_to_document_id` | `id \| null` | no |
| `notes` | `text \| null` | no |
| `lines` | `[ref:ReturnLineInput]` | yes |

<a id="schema-returnlineinput"></a>
#### `ReturnLineInput`

Returned quantity per original line. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `source_line_id` | `id` | yes |
| `quantity` | `qty` | yes |
| `reason` | `str:30` | yes |

<a id="schema-return"></a>
#### `Return`

Sales or purchase return.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `number` | `str` | yes |
| `status` | `enum:draft\|posted\|cancelled` | yes |
| `party` | `ref:PartyRef` | yes |
| `source_document_id` | `id` | yes |
| `source_number` | `str` | yes |
| `return_date` | `date` | yes |
| `stock_action` | `str` | yes |
| `lines` | `[obj]` | yes |
| `total` | `money` | yes |
| `note_id` | `id \| null` | no |
| `note_number` | `str \| null` | no |
| `version` | `int` | yes |

<a id="schema-noteinput"></a>
#### `NoteInput`

Credit or debit note. `apply_to_document_id` (required when usage = apply) is the invoice or bill of the same party that the note is allocated to on posting; it is stored only as the resulting bill_allocations row (R-20). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_id` | `id` | yes |
| `source_document_id` | `id \| null` | no |
| `return_id` | `id \| null` | no |
| `note_date` | `date` | yes |
| `reason` | `str:30` | yes |
| `amount` | `money` | yes |
| `usage` | `enum:apply\|credit\|refund` | yes |
| `apply_to_document_id` | `id \| null` | no |
| `refund` | `ref:RefundInput \| null` | no |

<a id="schema-refundinput"></a>
#### `RefundInput`

Refund details for usage=refund. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `money_account_id` | `id` | yes |
| `method` | `enum:cash\|cheque\|bank_transfer\|beftn\|rtgs\|npsb\|bkash\|nagad\|card` | yes |
| `reference` | `str:60 \| null` | no |

<a id="schema-note"></a>
#### `Note`

Credit or debit note.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `number` | `str` | yes |
| `kind` | `enum:credit_note\|debit_note` | yes |
| `status` | `enum:draft\|posted\|cancelled` | yes |
| `party` | `ref:PartyRef` | yes |
| `note_date` | `date` | yes |
| `reason` | `str` | yes |
| `amount` | `money` | yes |
| `usage` | `str` | yes |
| `effects` | `[obj]` | no |
| `journal` | `[ref:PostingLine]` | no |
| `version` | `int` | yes |

<a id="schema-allocationcreate"></a>
#### `AllocationCreate`

Apply an open settling line to a document of the same party (R-08). journal_line_id is the party-ledger line of a receipt, payment, advance or posted credit/debit note. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `journal_line_id` | `id` | yes |
| `document_type` | `enum:sales_invoice\|purchase_bill` | yes |
| `document_id` | `id` | yes |
| `amount` | `money` | yes |
| `allocated_on` | `date \| null` | no |

<a id="schema-allocation"></a>
#### `Allocation`

Settlement applied to a document.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `voucher_id` | `id` | yes |
| `voucher_number` | `str` | yes |
| `date` | `date` | yes |
| `amount` | `money` | yes |
| `kind` | `enum:receipt\|payment\|credit_note\|debit_note\|advance` | yes |
| `reversed` | `bool` | yes |

<a id="schema-postingline"></a>
#### `PostingLine`

Journal line preview or actual.

| Property | Type | Required |
|---|---|---|
| `ledger_id` | `id` | yes |
| `ledger_code` | `str` | yes |
| `ledger_name` | `str` | yes |
| `debit` | `money` | yes |
| `credit` | `money` | yes |

<a id="schema-partypaymentinput"></a>
#### `PartyPaymentInput`

Customer receipt (E7) or supplier payment (F5). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_id` | `id` | yes |
| `date` | `date` | yes |
| `amount` | `money` | yes |
| `money_account_id` | `id` | yes |
| `method` | `enum:cash\|cheque\|bank_transfer\|beftn\|rtgs\|npsb\|bkash\|nagad\|card` | yes |
| `cheque_number` | `str:20 \| null` | no |
| `cheque_date` | `date \| null` | no |
| `drawn_on` | `str:120 \| null` | no |
| `transaction_reference` | `str:60 \| null` | no |
| `bank_charge` | `money` | no |
| `exchange_rate` | `rate \| null` | no |
| `narration` | `str:1000 \| null` | no |
| `allocations` | `[ref:AllocationInput]` | no |
| `auto_allocate` | `bool` | no |
| `confirm_backdated` | `bool` | no |

<a id="schema-allocationinput"></a>
#### `AllocationInput`

Amount to apply to one document. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `document_id` | `id` | yes |
| `amount` | `money` | yes |

<a id="schema-voucherinput"></a>
#### `VoucherInput`

Journal, payment, receipt or contra voucher. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `entry_type` | `enum:journal\|payment\|receipt\|contra` | yes |
| `action` | `enum:draft\|submit\|post` | yes |
| `date` | `date` | yes |
| `reference` | `str:60 \| null` | no |
| `narration` | `str:1000 \| null` | no |
| `template_key` | `str:40 \| null` | no |
| `payment` | `ref:PaymentDetailInput \| null` | no |
| `lines` | `[ref:VoucherLineInput]` | yes |
| `confirm_backdated` | `bool` | no |

<a id="schema-paymentdetailinput"></a>
#### `PaymentDetailInput`

Cash/bank side of payment, receipt or contra. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `money_account_id` | `id` | yes |
| `counter_money_account_id` | `id \| null` | no |
| `method` | `enum:cash\|cheque\|bank_transfer\|beftn\|rtgs\|npsb\|bkash\|nagad\|card` | yes |
| `party_id` | `id \| null` | no |
| `payee_text` | `str:150 \| null` | no |
| `cheque_number` | `str:20 \| null` | no |
| `cheque_date` | `date \| null` | no |
| `drawn_on` | `str:120 \| null` | no |
| `transaction_reference` | `str:60 \| null` | no |
| `bank_charge` | `money` | no |
| `handed_to` | `str:120 \| null` | no |

<a id="schema-voucherlineinput"></a>
#### `VoucherLineInput`

Voucher line. Journal: debit or credit. Payment/receipt: amount. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `ledger_id` | `id` | yes |
| `debit` | `money` | no |
| `credit` | `money` | no |
| `amount` | `money` | no |
| `line_narration` | `str:500 \| null` | no |
| `against_reference` | `str:60 \| null` | no |
| `allocations` | `[ref:AllocationInput]` | no |

<a id="schema-voucher"></a>
#### `Voucher`

Journal entry / voucher.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `voucher_number` | `str` | yes |
| `entry_type` | `str` | yes |
| `status` | `enum:draft\|submitted\|approved\|rejected\|posted\|reversed` | yes |
| `entry_date` | `date` | yes |
| `reference` | `str \| null` | no |
| `narration` | `str \| null` | no |
| `total_debit` | `money` | yes |
| `total_credit` | `money` | yes |
| `lines` | `[ref:VoucherLine]` | yes |
| `payment` | `obj \| null` | no |
| `source` | `obj \| null` | no |
| `reverses_entry_id` | `id \| null` | no |
| `reversed_by_entry_id` | `id \| null` | no |
| `approval` | `ref:ApprovalRequest \| null` | no |
| `audit_trail` | `[ref:AuditLog]` | no |
| `amount_in_words` | `str` | yes |
| `created_at` | `datetime` | yes |
| `created_by` | `ref:UserRef \| null` | no |
| `updated_at` | `datetime` | yes |
| `version` | `int` | yes |

<a id="schema-voucherline"></a>
#### `VoucherLine`

Voucher line.

| Property | Type | Required |
|---|---|---|
| `line_no` | `int` | yes |
| `ledger_id` | `id` | yes |
| `ledger_code` | `str` | yes |
| `ledger_name` | `str` | yes |
| `debit` | `money` | yes |
| `credit` | `money` | yes |
| `line_narration` | `str \| null` | no |
| `against_reference` | `str \| null` | no |
| `foreign_amount` | `money \| null` | no |
| `exchange_rate` | `rate \| null` | no |

<a id="schema-moneyaccount"></a>
#### `MoneyAccount`

Cash, bank, wallet, card or cash-credit account.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `account_type` | `enum:cash\|bank\|wallet\|card\|cash_credit` | yes |
| `overdraft_limit` | `money \| null` | no |
| `name` | `str` | yes |
| `bank_name` | `str \| null` | no |
| `branch_name` | `str \| null` | no |
| `account_name` | `str \| null` | no |
| `account_number_masked` | `str \| null` | no |
| `routing_number` | `str \| null` | no |
| `currency_code` | `ccy` | yes |
| `ledger_id` | `id` | yes |
| `ledger_code` | `str` | yes |
| `book_balance` | `money` | yes |
| `custodian` | `ref:UserRef \| null` | no |
| `use_for_receipts` | `bool` | yes |
| `use_for_payments` | `bool` | yes |
| `show_on_invoices` | `bool` | yes |
| `last_reconciled_on` | `date \| null` | no |
| `unmatched_lines` | `int` | yes |
| `is_active` | `bool` | yes |
| `version` | `int` | yes |

<a id="schema-moneyaccountinput"></a>
#### `MoneyAccountInput`

Add or edit an account. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `account_type` | `enum:cash\|bank\|wallet\|card\|cash_credit` | yes |
| `overdraft_limit` | `money \| null` | no |
| `name` | `str:120 \| null` | no |
| `bank_name` | `str:120` | yes |
| `branch_name` | `str:120 \| null` | no |
| `account_name` | `str:150` | yes |
| `account_number` | `str:40` | yes |
| `routing_number` | `str:9 \| null` | no |
| `opening_balance` | `money` | no |
| `opening_date` | `date \| null` | no |
| `custodian_user_id` | `id \| null` | no |
| `use_for_receipts` | `bool` | no |
| `use_for_payments` | `bool` | no |
| `show_on_invoices` | `bool` | no |

<a id="schema-moneytransaction"></a>
#### `MoneyTransaction`

Cash/bank movement row.

| Property | Type | Required |
|---|---|---|
| `date` | `date` | yes |
| `voucher_id` | `id` | yes |
| `voucher_number` | `str` | yes |
| `account` | `str` | yes |
| `particulars` | `str` | yes |
| `direction` | `enum:in\|out\|transfer` | yes |
| `amount_in` | `money` | yes |
| `amount_out` | `money` | yes |

<a id="schema-cashcountinput"></a>
#### `CashCountInput`

Cash count. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `money_account_id` | `id` | yes |
| `counted_at` | `datetime` | yes |
| `denominations` | `[ref:Denomination]` | yes |
| `note` | `str:255 \| null` | no |

<a id="schema-denomination"></a>
#### `Denomination`

Note or coin count. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `denomination` | `money` | yes |
| `count` | `int` | yes |

<a id="schema-cashcount"></a>
#### `CashCount`

Recorded cash count.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `money_account_id` | `id` | yes |
| `counted_at` | `datetime` | yes |
| `counted_by` | `ref:UserRef` | yes |
| `book_balance` | `money` | yes |
| `counted_total` | `money` | yes |
| `difference` | `money` | yes |
| `note` | `str \| null` | no |
| `status` | `enum:recorded\|adjusted\|reversed` | yes |
| `adjustment_entry_id` | `id \| null` | no |

<a id="schema-statementimport"></a>
#### `StatementImport`

multipart: file, money_account_id, column mapping. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `file` | `str` | yes |
| `money_account_id` | `id` | yes |
| `mapping` | `obj \| null` | no |

<a id="schema-statementimportresult"></a>
#### `StatementImportResult`

Imported statement.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `line_count` | `int` | yes |
| `period_from` | `date` | yes |
| `period_to` | `date` | yes |
| `duplicates_skipped` | `int` | yes |

<a id="schema-reconciliationstart"></a>
#### `ReconciliationStart`

Start a reconciliation. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `money_account_id` | `id` | yes |
| `statement_date` | `date` | yes |
| `statement_balance` | `money` | yes |

<a id="schema-reconciliation"></a>
#### `Reconciliation`

Reconciliation workspace.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `money_account_id` | `id` | yes |
| `statement_date` | `date` | yes |
| `statement_balance` | `money` | yes |
| `book_balance` | `money` | yes |
| `difference` | `money` | yes |
| `matched_count` | `int` | yes |
| `line_count` | `int` | yes |
| `status` | `enum:in_progress\|finished` | yes |
| `lines` | `[ref:StatementLine]` | yes |
| `version` | `int` | yes |

<a id="schema-statementline"></a>
#### `StatementLine`

Statement line with match state.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `txn_date` | `date` | yes |
| `description` | `str` | yes |
| `bank_reference` | `str \| null` | no |
| `amount` | `money` | yes |
| `status` | `enum:unmatched\|matched\|created\|paired\|ignored` | yes |
| `suggestion` | `obj \| null` | no |
| `matched` | `obj \| null` | no |

<a id="schema-matchrequest"></a>
#### `MatchRequest`

Match to a book line. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `journal_line_id` | `id` | yes |

<a id="schema-createentryfromline"></a>
#### `CreateEntryFromLine`

Create the missing voucher. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `kind` | `enum:receipt\|bank_charge\|payment` | yes |
| `ledger_id` | `id` | yes |
| `party_id` | `id \| null` | no |
| `narration` | `str:500 \| null` | no |

<a id="schema-pairrequest"></a>
#### `PairRequest`

Offsetting statement lines. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `line_ids` | `[id]` | yes |

<a id="schema-iecategory"></a>
#### `IeCategory`

Income or expense category.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `kind` | `enum:income\|expense` | yes |
| `name` | `str` | yes |
| `description` | `str \| null` | no |
| `ledger_id` | `id` | yes |
| `ledger_code` | `str` | yes |
| `monthly_budget` | `money \| null` | no |
| `warn_at_percent` | `pct` | yes |
| `spent` | `money` | yes |
| `used_pct` | `pct \| null` | no |
| `status` | `enum:on_track\|near_limit\|over_budget\|not_used\|no_budget` | yes |

<a id="schema-iecategoryinput"></a>
#### `IeCategoryInput`

Create or edit a category. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `kind` | `enum:income\|expense` | yes |
| `name` | `str:80` | yes |
| `description` | `str:255 \| null` | no |
| `ledger_id` | `id \| null` | no |
| `create_ledger` | `bool` | no |
| `monthly_budget` | `money \| null` | no |
| `warn_at_percent` | `pct` | no |

<a id="schema-ieentryinput"></a>
#### `IeEntryInput`

Quick income or expense. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `kind` | `enum:income\|expense` | yes |
| `amount` | `money` | yes |
| `date` | `date` | yes |
| `category_id` | `id` | yes |
| `money_account_id` | `id` | yes |
| `method` | `enum:cash\|cheque\|bank_transfer\|beftn\|rtgs\|npsb\|bkash\|nagad\|card` | yes |
| `payee_text` | `str:150 \| null` | no |
| `reference` | `str:60 \| null` | no |
| `tax_rule_id` | `id \| null` | no |
| `notes` | `str:500 \| null` | no |
| `attachment_ids` | `[id]` | no |
| `recurrence` | `ref:RecurrenceInput \| null` | no |
| `confirm_backdated` | `bool` | no |

<a id="schema-recurrenceinput"></a>
#### `RecurrenceInput`

Repeat settings. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `frequency` | `enum:weekly\|monthly\|quarterly\|yearly` | yes |
| `next_run_on` | `date` | yes |
| `ends_type` | `enum:never\|after_count\|on_date` | yes |
| `ends_after_count` | `int \| null` | no |
| `ends_on` | `date \| null` | no |

<a id="schema-ieentry"></a>
#### `IeEntry`

Line on a category ledger.

| Property | Type | Required |
|---|---|---|
| `journal_line_id` | `id` | yes |
| `voucher_id` | `id` | yes |
| `voucher_number` | `str` | yes |
| `date` | `date` | yes |
| `description` | `str` | yes |
| `category` | `str` | yes |
| `account` | `str` | yes |
| `method` | `str \| null` | no |
| `amount` | `money` | yes |
| `has_receipt` | `bool` | yes |

<a id="schema-recurringtemplate"></a>
#### `RecurringTemplate`

Recurring entry.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `kind` | `str` | yes |
| `frequency` | `str` | yes |
| `next_run_on` | `date` | yes |
| `ends_type` | `str` | yes |
| `occurrences_done` | `int` | yes |
| `is_active` | `bool` | yes |
| `payload` | `obj` | yes |
| `version` | `int` | yes |

<a id="schema-recurringtemplateinput"></a>
#### `RecurringTemplateInput`

Edit a recurring entry. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `frequency` | `enum:weekly\|monthly\|quarterly\|yearly` | no |
| `next_run_on` | `date` | no |
| `ends_type` | `enum:never\|after_count\|on_date` | no |
| `ends_after_count` | `int \| null` | no |
| `ends_on` | `date \| null` | no |
| `is_active` | `bool` | no |

<a id="schema-accounttree"></a>
#### `AccountTree`

Chart of accounts tree.

| Property | Type | Required |
|---|---|---|
| `as_of` | `date` | yes |
| `nodes` | `[ref:AccountNode]` | yes |
| `total_debit` | `money` | yes |
| `total_credit` | `money` | yes |
| `balanced` | `bool` | yes |

<a id="schema-accountnode"></a>
#### `AccountNode`

Group or ledger node.

| Property | Type | Required |
|---|---|---|
| `kind` | `enum:primary\|group\|ledger` | yes |
| `id` | `id` | yes |
| `code` | `str` | yes |
| `name` | `str` | yes |
| `nature` | `str` | yes |
| `balance` | `money` | yes |
| `balance_side` | `enum:dr\|cr` | yes |
| `note` | `str \| null` | no |
| `children` | `[ref:AccountNode]` | no |

<a id="schema-accountgroup"></a>
#### `AccountGroup`

Account group.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `code` | `str` | yes |
| `name` | `str` | yes |
| `parent_id` | `id \| null` | no |
| `nature` | `str` | yes |
| `normal_balance` | `str` | yes |
| `report_section` | `str` | yes |
| `cash_flow_category` | `str \| null` | no |
| `is_system` | `bool` | yes |
| `balance` | `money` | yes |
| `children` | `[obj]` | no |
| `version` | `int` | yes |

<a id="schema-accountgroupinput"></a>
#### `AccountGroupInput`

Create or edit a group. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `code` | `str:20` | yes |
| `name` | `str:120` | yes |
| `parent_id` | `id` | yes |
| `cash_flow_category` | `enum:operating\|investing\|financing\|cash \| null` | no |
| `sort_order` | `int` | no |
| `used_in_cash_vouchers` | `bool` | no |

<a id="schema-ledger"></a>
#### `Ledger`

Ledger. opening_balance, opening_side, balance and balance_side are present only for callers with accounting.view (pickers never see balances, R-26).

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `code` | `str` | yes |
| `name` | `str` | yes |
| `account_group_id` | `id` | yes |
| `group_path` | `[str]` | yes |
| `nature` | `str` | yes |
| `normal_balance` | `str` | yes |
| `ledger_kind` | `str` | yes |
| `description` | `str \| null` | no |
| `track_bills` | `bool` | yes |
| `show_in_cash_vouchers` | `bool` | yes |
| `is_system` | `bool` | yes |
| `is_active` | `bool` | yes |
| `has_entries` | `bool` | yes |
| `opening_balance` | `money` | no |
| `opening_side` | `enum:dr\|cr` | no |
| `balance` | `money` | no |
| `balance_side` | `enum:dr\|cr` | no |
| `fy_summary` | `obj \| null` | no |
| `version` | `int` | yes |

<a id="schema-ledgerinput"></a>
#### `LedgerInput`

Create or edit a ledger. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `name` | `str:150` | yes |
| `account_group_id` | `id` | yes |
| `code` | `str:20 \| null` | no |
| `description` | `str:255 \| null` | no |
| `track_bills` | `bool` | no |
| `show_in_cash_vouchers` | `bool` | no |
| `opening_balance` | `money` | no |
| `opening_side` | `enum:dr\|cr` | no |

<a id="schema-templatelist"></a>
#### `TemplateList`

Journal templates.

| Property | Type | Required |
|---|---|---|
| `templates` | `[obj]` | yes |

<a id="schema-voucherpreview"></a>
#### `VoucherPreview`

Server-calculated voucher (R-27): the lines as they would post, totals, balance status, approval need and warnings.

| Property | Type | Required |
|---|---|---|
| `lines` | `[ref:PostingLine]` | yes |
| `total_debit` | `money` | yes |
| `total_credit` | `money` | yes |
| `difference` | `money` | yes |
| `balanced` | `bool` | yes |
| `needs_approval` | `bool` | yes |
| `approval_limit` | `money \| null` | no |
| `warnings` | `[ref:Warning]` | yes |
| `amount_in_words` | `str` | yes |

<a id="schema-letterofcreditinput"></a>
#### `LetterOfCreditInput`

Create or update a draft/awaiting LC. Only supplier, amount, issuing bank and expiry are needed to submit. `document_template_id` copies that checklist into the LC's documents on create (or while draft) and is not stored (R-40). Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `party_id` | `id` | yes |
| `purchase_order_id` | `id \| null` | no |
| `intended_import` | `str:255 \| null` | no |
| `goods_description` | `str:500` | yes |
| `quantity` | `qty \| null` | no |
| `quantity_unit` | `str:20 \| null` | no |
| `import_authorization_ref` | `str:60 \| null` | no |
| `lc_type` | `enum:irrevocable\|irrevocable_transferable\|standby` | no |
| `tenor` | `enum:sight\|usance` | yes |
| `usance_days` | `int \| null` | no |
| `incoterm` | `str:40` | yes |
| `currency_code` | `ccy` | yes |
| `amount` | `money` | yes |
| `tolerance_pct` | `pct` | no |
| `planning_rate` | `rate` | yes |
| `partial_shipments` | `bool` | no |
| `transhipment` | `bool` | no |
| `confirmation_required` | `bool` | no |
| `issuing_bank_account_id` | `id \| null` | no |
| `advising_bank` | `str:190 \| null` | no |
| `margin_pct` | `pct` | no |
| `margin_account_id` | `id \| null` | no |
| `application_date` | `date \| null` | no |
| `latest_shipment_date` | `date \| null` | no |
| `expiry_date` | `date \| null` | no |
| `presentation_days` | `int` | no |
| `document_template_id` | `id \| null` | no |
| `documents` | `[ref:LcDocumentInput]` | no |
| `record_shipments` | `bool` | no |
| `shipment` | `ref:LcShipmentInput \| null` | no |

<a id="schema-letterofcredit"></a>
#### `LetterOfCredit`

Import LC.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `lc_number` | `str` | yes |
| `status` | `enum:draft\|awaiting_bank\|opened\|partly_shipped\|documents_presented\|payment_due\|closed\|cancelled\|expired` | yes |
| `party` | `ref:PartyRef` | yes |
| `goods_description` | `str` | yes |
| `currency_code` | `ccy` | yes |
| `amount` | `money` | yes |
| `tolerance_pct` | `pct` | yes |
| `planning_rate` | `rate` | yes |
| `amount_bdt_planned` | `money` | yes |
| `paid_foreign` | `money` | yes |
| `outstanding_foreign` | `money` | yes |
| `tenor` | `str` | yes |
| `usance_days` | `int \| null` | no |
| `incoterm` | `str` | yes |
| `issuing_bank` | `ref:MoneyAccount \| null` | no |
| `bank_reference` | `str \| null` | no |
| `opening_status` | `str` | yes |
| `opened_on` | `date \| null` | no |
| `latest_shipment_date` | `date \| null` | no |
| `expiry_date` | `date \| null` | no |
| `days_to_expiry` | `int \| null` | no |
| `maturity_date` | `date \| null` | no |
| `margin_pct` | `pct` | yes |
| `margin_amount` | `money` | yes |
| `flags` | `[ref:Warning]` | no |
| `milestones` | `[obj]` | yes |
| `documents` | `[ref:LcDocument]` | no |
| `shipments` | `[ref:LcShipment]` | no |
| `charges` | `[ref:LcCharge]` | no |
| `payments` | `[ref:LcPayment]` | no |
| `amendments` | `[ref:LcAmendment]` | no |
| `money_position` | `obj \| null` | no |
| `created_at` | `datetime` | yes |
| `created_by` | `ref:UserRef \| null` | no |
| `updated_at` | `datetime` | yes |
| `version` | `int` | yes |

<a id="schema-lctransition"></a>
#### `LcTransition`

Status change. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `action` | `enum:submit\|mark_opened\|mark_refused\|documents_presented\|payment_due\|cancel\|expire\|reopen` | yes |
| `bank_reference` | `str:60 \| null` | no |
| `date` | `date \| null` | no |
| `reason` | `str:255 \| null` | no |

<a id="schema-lccloserequest"></a>
#### `LcCloseRequest`

Close an LC. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `override_reason` | `str:255 \| null` | no |
| `release_margin_to_account_id` | `id \| null` | no |

<a id="schema-lcdocumentinput"></a>
#### `LcDocumentInput`

Required document. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `name` | `str:120` | yes |
| `provided_by` | `str:80` | yes |
| `copies` | `int` | no |
| `note` | `str:255 \| null` | no |
| `is_required` | `bool` | no |
| `status` | `enum:not_yet_due\|pending\|received\|missing\|discrepant\|not_required` | no |
| `due_date` | `date \| null` | no |
| `received_date` | `date \| null` | no |
| `discrepancy_note` | `str:500 \| null` | no |
| `lc_shipment_id` | `id \| null` | no |

<a id="schema-lcdocument"></a>
#### `LcDocument`

LC document.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `provided_by` | `str` | yes |
| `copies` | `int` | yes |
| `note` | `str \| null` | no |
| `is_required` | `bool` | yes |
| `status` | `str` | yes |
| `due_date` | `date \| null` | no |
| `received_date` | `date \| null` | no |
| `attachments` | `[ref:Attachment]` | no |
| `version` | `int` | yes |

<a id="schema-lcdocumenttemplate"></a>
#### `LcDocumentTemplate`

Checklist template.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `name` | `str` | yes |
| `is_default` | `bool` | yes |
| `items` | `[obj]` | yes |

<a id="schema-lcdocumenttemplateinput"></a>
#### `LcDocumentTemplateInput`

Template items. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `name` | `str:80` | yes |
| `is_default` | `bool` | no |
| `items` | `[obj]` | yes |

<a id="schema-lcamendmentinput"></a>
#### `LcAmendmentInput`

Record an amendment. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `requested_on` | `date` | yes |
| `status` | `enum:requested\|issued\|accepted` | yes |
| `changes` | `[ref:AmendmentChange]` | yes |
| `other_change` | `str:255 \| null` | no |
| `reason` | `str:500 \| null` | no |
| `bank_fee` | `money` | no |

<a id="schema-amendmentchange"></a>
#### `AmendmentChange`

Changed field. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `field_key` | `enum:expiry_date\|latest_shipment_date\|amount\|tolerance_pct\|other` | yes |
| `new_value` | `str:120` | yes |

<a id="schema-lcamendmentstatus"></a>
#### `LcAmendmentStatus`

Advance status. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `status` | `enum:issued\|accepted\|refused` | yes |
| `date` | `date` | yes |

<a id="schema-lcamendment"></a>
#### `LcAmendment`

Amendment.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `letter_of_credit_id` | `id` | yes |
| `amendment_no` | `int` | yes |
| `status` | `str` | yes |
| `requested_on` | `date` | yes |
| `issued_on` | `date \| null` | no |
| `accepted_on` | `date \| null` | no |
| `changes` | `[obj]` | yes |
| `bank_fee` | `money` | yes |
| `reason` | `str \| null` | no |
| `version` | `int` | yes |

<a id="schema-lcshipmentinput"></a>
#### `LcShipmentInput`

Shipment lot. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `lot_no` | `int` | no |
| `status` | `enum:not_shipped\|booking_confirmed\|at_sea\|arrived\|arrived_cleared\|not_shipped_expired` | no |
| `booking_ref` | `str:60 \| null` | no |
| `bl_number` | `str:60 \| null` | no |
| `vessel` | `str:120 \| null` | no |
| `port_of_loading` | `str:120 \| null` | no |
| `port_of_discharge` | `str:120 \| null` | no |
| `mode` | `enum:sea\|air\|road` | no |
| `etd` | `date \| null` | no |
| `eta` | `date \| null` | no |
| `shipped_on` | `date \| null` | no |
| `arrived_on` | `date \| null` | no |
| `quantity` | `qty \| null` | no |
| `value_foreign` | `money \| null` | no |

<a id="schema-lcshipment"></a>
#### `LcShipment`

Shipment lot.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `lot_no` | `int` | yes |
| `status` | `str` | yes |
| `bl_number` | `str \| null` | no |
| `vessel` | `str \| null` | no |
| `etd` | `date \| null` | no |
| `eta` | `date \| null` | no |
| `arrived_on` | `date \| null` | no |
| `quantity` | `qty \| null` | no |
| `value_foreign` | `money \| null` | no |
| `purchase_bill_id` | `id \| null` | no |
| `version` | `int` | yes |

<a id="schema-lcchargeinput"></a>
#### `LcChargeInput`

Charge or import expense. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `letter_of_credit_id` | `id` | yes |
| `charge_type` | `enum:margin_deposit\|opening_commission\|amendment_fee\|swift_postage\|stamp_documentation\|acceptance_commission\|discrepancy_fee\|marine_insurance\|cf_agent_fee\|port_handling\|customs_duty_taxes\|inland_transport\|other` | yes |
| `charge_date` | `date` | yes |
| `amount` | `money` | yes |
| `paid_to` | `str:150 \| null` | no |
| `payee_party_id` | `id \| null` | no |
| `status` | `enum:unpaid\|paid` | yes |
| `paid_from_account_id` | `id \| null` | no |
| `include_in_landed_cost` | `bool` | no |
| `reference` | `str:60 \| null` | no |
| `lc_shipment_id` | `id \| null` | no |
| `lc_amendment_id` | `id \| null` | no |

<a id="schema-chargepayment"></a>
#### `ChargePayment`

Pay a charge. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `paid_from_account_id` | `id` | yes |
| `date` | `date` | yes |
| `reference` | `str:60 \| null` | no |

<a id="schema-lccharge"></a>
#### `LcCharge`

LC charge.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `letter_of_credit_id` | `id` | yes |
| `lc_number` | `str` | yes |
| `charge_type` | `str` | yes |
| `charge_date` | `date` | yes |
| `amount` | `money` | yes |
| `status` | `enum:unpaid\|paid` | yes |
| `paid_to` | `str \| null` | no |
| `include_in_landed_cost` | `bool` | yes |
| `is_refundable` | `bool` | yes |
| `allocated` | `bool` | yes |
| `journal_entry_id` | `id \| null` | no |
| `reference` | `str \| null` | no |
| `version` | `int` | yes |

<a id="schema-lcpaymentinput"></a>
#### `LcPaymentInput`

Payment at the bank's rate. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `payment_kind` | `enum:sight_payment\|usance_acceptance\|usance_settlement` | no |
| `payment_date` | `date` | yes |
| `amount_foreign` | `money` | yes |
| `bank_rate` | `rate` | yes |
| `money_account_id` | `id` | yes |
| `margin_applied` | `money` | no |
| `lc_shipment_id` | `id \| null` | no |
| `purchase_bill_id` | `id \| null` | no |
| `bank_reference` | `str:60 \| null` | no |
| `description` | `str:150 \| null` | no |

<a id="schema-lcpayment"></a>
#### `LcPayment`

LC payment.

| Property | Type | Required |
|---|---|---|
| `id` | `id` | yes |
| `payment_kind` | `enum:sight_payment\|usance_acceptance\|usance_settlement` | yes |
| `payment_date` | `date` | yes |
| `amount_foreign` | `money` | yes |
| `bank_rate` | `rate` | yes |
| `amount_bdt` | `money` | yes |
| `margin_applied` | `money` | yes |
| `fx_difference` | `money` | yes |
| `journal_entry_id` | `id \| null` | no |
| `status` | `str` | yes |

<a id="schema-landedcostrequest"></a>
#### `LandedCostRequest`

Allocate capitalised charges to a bill. Unknown properties are rejected.

| Property | Type | Required |
|---|---|---|
| `purchase_bill_id` | `id` | yes |
| `allocation_date` | `date` | yes |
| `method` | `enum:value\|quantity` | no |
| `charge_ids` | `[id]` | no |

<a id="schema-landedcostallocation"></a>
#### `LandedCostAllocation`

Allocation preview or result.

| Property | Type | Required |
|---|---|---|
| `id` | `id \| null` | no |
| `status` | `enum:preview\|draft\|posted\|reversed` | yes |
| `total_amount` | `money` | yes |
| `lines` | `[obj]` | yes |
| `journal` | `[ref:PostingLine]` | no |
| `warnings` | `[ref:Warning]` | no |

## 11. Page action map

For every page: what it **loads** when opened, and for every action in its design what it **calls**, with the permission and success status. `→` is a sequence and `|` alternatives chosen by state (create vs update). `dlg:` opens a dialog page (its own actions are listed under it), `nav:` navigates, and `ui:` is client-only. `export:` re-calls a `format`-capable report with `?format=xlsx|pdf|csv`, or starts `POST /export-jobs` for a list. Validation, errors and database operations of each call are in its §9 entry.


### Shell


<a id="actions-s0"></a>
#### S0 · App shell

View permission `dashboard.view`. **Loads:** [`GET /me`](#getme), [`GET /company/settings`](#getcompanysettings), [`GET /branches`](#getbranches), [`GET /fiscal-years`](#getfiscalyears), [`GET /search`](#getsearch), [`GET /notifications`](#getnotifications), [`GET /warehouses`](#getwarehouses).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Switch company (reloads all data) | [`GET /me`](#getme) → [`GET /company/settings`](#getcompanysettings) → [`GET /fiscal-years`](#getfiscalyears) (with the new X-Company-Id) | any signed-in user, any signed-in user who is an active member of the company | — | 200 | — |
| 2 | Open command palette (Ctrl K) | dlg:L2 | — | — | — | — |
| 3 | Quick create menu: Sales invoice Alt S, Purchase bill Alt P, Receipt Alt R, Payment Alt Y, Contra Alt C, Journal Alt J, Customer, Supplier, Product | dlg:L5 | — | — | — | — |
| 4 | Open notifications | [`GET /notifications`](#getnotifications) | any signed-in user who is an active member of the company | — | 200 | — |
| 5 | Profile menu: profile, security, sign out | nav:K14 \| [`POST /auth/logout`](#postauthlogout) | public (refresh cookie) | — → — | 204 | — |
| 6 | Create a company (NEW, company switcher) | [`POST /companies`](#postcompanies) → [`GET /me`](#getme) | any signed-in user | [`CompanyCreate`](#schema-companycreate) → [`Company`](#schema-company) | 200, 201 | — |

<a id="actions-s1"></a>
#### S1 · Sign in (NEW: not in design)

View permission `public`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Sign in | [`POST /auth/login`](#postauthlogin) | public | [`LoginRequest`](#schema-loginrequest) → [`LoginResponse`](#schema-loginresponse) | 200 | `invalid_credentials` |
| 2 | Use a recovery code | [`POST /auth/two-factor`](#postauthtwofactor) | public | [`TwoFactorRequest`](#schema-twofactorrequest) → [`LoginResponse`](#schema-loginresponse) | 200 | `two_factor_failed` |
| 3 | Forgot password | nav:S2 | — | — | — | — |

<a id="actions-s2"></a>
#### S2 · Forgot / reset password (NEW)

View permission `public`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Send link | [`POST /auth/password/forgot`](#postauthpasswordforgot) | public | [`ForgotPasswordRequest`](#schema-forgotpasswordrequest) → — | 202 | — |
| 2 | Set password | [`POST /auth/password/reset`](#postauthpasswordreset) | public | [`ResetPasswordRequest`](#schema-resetpasswordrequest) → — | 204 | — |

<a id="actions-s3"></a>
#### S3 · Accept invitation (NEW)

View permission `public`. **Loads:** [`GET /invitations/{token}`](#getinvitationstoken).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Join company | [`POST /invitations/{token}/accept`](#postinvitationstokenaccept) | public | [`AcceptInvitationRequest`](#schema-acceptinvitationrequest) → [`LoginResponse`](#schema-loginresponse) | 200 | `gone`, `seat_limit_reached` |

### Dashboard


<a id="actions-a1"></a>
#### A1 · Main dashboard

View permission `dashboard.view`. **Loads:** [`GET /dashboard`](#getdashboard).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New invoice (Alt S) | nav:E3 | — | — | — | — |
| 2 | Record receipt | nav:E7 | — | — | — | — |
| 3 | Reconcile (from banner) | nav:G10 | — | — | — | — |
| 4 | Dismiss banner | ui:hide the banner for this session (sessionStorage) | — | — | — | — |
| 5 | Open any tile or row (deep link) | nav:* | — | — | — | — |

<a id="actions-a2"></a>
#### A2 · Financial overview

View permission `dashboard.view`. **Loads:** [`GET /dashboard/lenses/{lens}`](#getdashboardlenseslens).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open row (deep link) | nav:* | — | — | — | — |
| 2 | View all | nav:H16 | — | — | — | — |

<a id="actions-a3"></a>
#### A3 · Sales overview lens

View permission `dashboard.view`. **Loads:** [`GET /dashboard/lenses/{lens}`](#getdashboardlenseslens).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open row (deep link) | nav:* | — | — | — | — |
| 2 | View all | nav:E11 | — | — | — | — |

<a id="actions-a4"></a>
#### A4 · Expense overview lens

View permission `dashboard.view`. **Loads:** [`GET /dashboard/lenses/{lens}`](#getdashboardlenseslens).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open row (deep link) | nav:* | — | — | — | — |
| 2 | View all | nav:I9 | — | — | — | — |

<a id="actions-a5"></a>
#### A5 · Outstanding receivables

View permission `dashboard.view`. **Loads:** [`GET /dashboard/lenses/{lens}`](#getdashboardlenseslens).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open row (deep link) | nav:* | — | — | — | — |
| 2 | View all | nav:B7 | — | — | — | — |

<a id="actions-a6"></a>
#### A6 · Outstanding payables

View permission `dashboard.view`. **Loads:** [`GET /dashboard/lenses/{lens}`](#getdashboardlenseslens).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open row (deep link) | nav:* | — | — | — | — |
| 2 | View all | nav:C7 | — | — | — | — |

<a id="actions-a7"></a>
#### A7 · Cash and bank summary

View permission `dashboard.view`. **Loads:** [`GET /dashboard/lenses/{lens}`](#getdashboardlenseslens).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open row (deep link) | nav:* | — | — | — | — |
| 2 | View all | nav:G1 | — | — | — | — |

<a id="actions-a8"></a>
#### A8 · Inventory alerts

View permission `dashboard.view`. **Loads:** [`GET /dashboard/lenses/{lens}`](#getdashboardlenseslens).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open row (deep link) | nav:* | — | — | — | — |
| 2 | View all | nav:D12 | — | — | — | — |

### Parties


<a id="actions-b1"></a>
#### B1 · Customer list

View permission `parties.view`. **Loads:** [`GET /parties`](#getparties), [`GET /party-groups`](#getpartygroups).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New customer | nav:B2 | — | — | — | — |
| 2 | Open row | nav:B4 | — | — | — | — |
| 3 | Edit (row) | nav:B3 | — | — | — | — |
| 4 | Send statements (bulk) | [`POST /parties/statements/send`](#postpartiesstatementssend) | `parties.view` | [`StatementSendRequest`](#schema-statementsendrequest) → [`ExportJob`](#schema-exportjob) | 202 | — |
| 5 | Export (bulk) | export:[`GET /parties`](#getparties) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 6 | Mark inactive (bulk) | dlg:L6 → [`POST /parties/bulk-deactivate`](#postpartiesbulkdeactivate) | `parties.edit` | [`BulkIds`](#schema-bulkids) → [`BulkResult`](#schema-bulkresult) | 200 | `has_history`, `inactive_reference` |

<a id="actions-b2"></a>
#### B2 · Add customer

View permission `parties.create`. **Loads:** [`GET /payment-terms`](#getpaymentterms), [`GET /party-groups`](#getpartygroups).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel (unsaved-changes dialog if dirty) | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save and add another | [`POST /parties`](#postparties) → ui:reset the form | `parties.create` | [`PartyInput`](#schema-partyinput) → [`Party`](#schema-party) | 201 | `duplicate`, `invalid_mobile` |
| 3 | Save (Ctrl S) | [`POST /parties`](#postparties) → nav:B4 | `parties.create` | [`PartyInput`](#schema-partyinput) → [`Party`](#schema-party) | 201 | `duplicate`, `invalid_mobile` |
| 4 | Add group inline (NEW) | [`POST /party-groups`](#postpartygroups) | `parties.create` | [`PartyGroupInput`](#schema-partygroupinput) → [`PartyGroup`](#schema-partygroup) | 201 | — |

<a id="actions-b3"></a>
#### B3 · Edit customer

View permission `parties.edit`. **Loads:** [`GET /parties/{id}`](#getpartiesid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save changes (If-Match) | [`PATCH /parties/{id}`](#patchpartiesid) | `parties.edit` | [`PartyInput`](#schema-partyinput) → [`Party`](#schema-party) | 200 | `invalid_mobile` |
| 3 | Make inactive | dlg:L6 → [`POST /parties/{id}/deactivate`](#postpartiesiddeactivate) \| [`POST /parties/{id}/reactivate`](#postpartiesidreactivate) | `parties.edit` | — → [`Party`](#schema-party) | 200 | `has_history`, `inactive_reference` |
| 4 | Delete (L7 confirmation, only without history) | dlg:L7 → [`DELETE /parties/{id}`](#deletepartiesid) | `parties.delete` | — → — | 204 | `has_history` |

<a id="actions-b4"></a>
#### B4 · Customer details

View permission `parties.view`. **Loads:** [`GET /audit-logs`](#getauditlogs), [`GET /parties/{id}`](#getpartiesid), [`GET /parties/{id}/open-documents`](#getpartiesidopendocuments).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Edit | nav:B3 | — | — | — | — |
| 2 | Statement | nav:B8 | — | — | — | — |
| 3 | New invoice (Alt S) | nav:E3 | — | — | — | — |
| 4 | Record payment | nav:E7 | — | — | — | — |
| 5 | Switch tab | [`GET /parties/{id}/transactions`](#getpartiesidtransactions) \| [`GET /parties/{id}/ledger`](#getpartiesidledger) \| [`GET /audit-logs`](#getauditlogs) | `parties.view`, contextual — any → `<module>.view of the record named by type/id (e.g. sales.view for a sales_invoice)` | — | 200 | — |
| 6 | Apply advance or credit (NEW, R-08) | [`GET /parties/{id}/open-documents`](#getpartiesidopendocuments) → [`POST /allocations`](#postallocations) | `parties.view`, contextual — party is a customer → `sales.create`; party is a supplier → `purchases.create` | [`AllocationCreate`](#schema-allocationcreate) → [`Allocation`](#schema-allocation) | 200, 201 | `allocation_exceeds_balance`, `foreign_document`, `period_locked` |
| 7 | Remove allocation (NEW, R-08) | dlg:L6 → [`DELETE /allocations/{id}`](#deleteallocationsid) | contextual — party is a customer → `sales.edit`; party is a supplier → `purchases.edit` | — → — | 204 | `allocation_exceeds_balance`, `period_locked` |

<a id="actions-b5"></a>
#### B5 · Customer transaction history

View permission `parties.view`. **Loads:** [`GET /parties/{id}/transactions`](#getpartiesidtransactions), [`GET /sales-invoices`](#getsalesinvoices).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open document | nav:* | — | — | — | — |
| 2 | Export | export:[`GET /parties/{id}/transactions`](#getpartiesidtransactions) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-b6"></a>
#### B6 · Customer ledger

View permission `parties.view`. **Loads:** [`GET /parties/{id}/ledger`](#getpartiesidledger).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /parties/{id}/ledger`](#getpartiesidledger) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 3 | Open voucher | nav:* | — | — | — | — |

<a id="actions-b7"></a>
#### B7 · Outstanding balances

View permission `parties.view`. **Loads:** [`GET /parties/outstanding`](#getpartiesoutstanding).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Filter by bucket | [`GET /parties/outstanding`](#getpartiesoutstanding) | `parties.view` | — | 200 | — |
| 2 | Send reminders/statements (bulk) | [`POST /parties/statements/send`](#postpartiesstatementssend) | `parties.view` | [`StatementSendRequest`](#schema-statementsendrequest) → [`ExportJob`](#schema-exportjob) | 202 | — |
| 3 | Record payment (row) | nav:E7 | — | — | — | — |

<a id="actions-b8"></a>
#### B8 · Customer statement

View permission `parties.view`. **Loads:** [`GET /parties/{id}/ledger`](#getpartiesidledger), [`GET /parties/{id}/statement`](#getpartiesidstatement), [`GET /parties/{id}/statement/pdf`](#getpartiesidstatementpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | PDF | [`GET /parties/{id}/statement/pdf`](#getpartiesidstatementpdf) | `parties.view` | — | 200 | — |
| 2 | Print | [`GET /parties/{id}/statement/pdf`](#getpartiesidstatementpdf) → ui:print | `parties.view` | — | 200 | — |
| 3 | Email to customer | [`POST /parties/statements/send`](#postpartiesstatementssend) | `parties.view` | [`StatementSendRequest`](#schema-statementsendrequest) → [`ExportJob`](#schema-exportjob) | 202 | — |

<a id="actions-c1"></a>
#### C1 · Supplier list

View permission `parties.view`. **Loads:** [`GET /parties`](#getparties).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New supplier | nav:C2 | — | — | — | — |
| 2 | Open row | nav:C4 | — | — | — | — |
| 3 | Edit (row) | nav:C3 | — | — | — | — |
| 4 | Send statements (bulk) | [`POST /parties/statements/send`](#postpartiesstatementssend) | `parties.view` | [`StatementSendRequest`](#schema-statementsendrequest) → [`ExportJob`](#schema-exportjob) | 202 | — |
| 5 | Export (bulk) | export:[`GET /parties`](#getparties) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 6 | Mark inactive (bulk) | dlg:L6 → [`POST /parties/bulk-deactivate`](#postpartiesbulkdeactivate) | `parties.edit` | [`BulkIds`](#schema-bulkids) → [`BulkResult`](#schema-bulkresult) | 200 | `has_history`, `inactive_reference` |

<a id="actions-c2"></a>
#### C2 · Add supplier

View permission `parties.create`. **Loads:** [`GET /payment-terms`](#getpaymentterms), [`GET /party-groups`](#getpartygroups), [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel (unsaved-changes dialog if dirty) | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save and add another | [`POST /parties`](#postparties) → ui:reset the form | `parties.create` | [`PartyInput`](#schema-partyinput) → [`Party`](#schema-party) | 201 | `duplicate`, `invalid_mobile` |
| 3 | Save (Ctrl S) | [`POST /parties`](#postparties) → nav:C4 | `parties.create` | [`PartyInput`](#schema-partyinput) → [`Party`](#schema-party) | 201 | `duplicate`, `invalid_mobile` |
| 4 | Add group inline (NEW) | [`POST /party-groups`](#postpartygroups) | `parties.create` | [`PartyGroupInput`](#schema-partygroupinput) → [`PartyGroup`](#schema-partygroup) | 201 | — |

<a id="actions-c3"></a>
#### C3 · Edit supplier

View permission `parties.edit`. **Loads:** [`GET /parties/{id}`](#getpartiesid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save changes (If-Match) | [`PATCH /parties/{id}`](#patchpartiesid) | `parties.edit` | [`PartyInput`](#schema-partyinput) → [`Party`](#schema-party) | 200 | `invalid_mobile` |
| 3 | Make inactive | dlg:L6 → [`POST /parties/{id}/deactivate`](#postpartiesiddeactivate) \| [`POST /parties/{id}/reactivate`](#postpartiesidreactivate) | `parties.edit` | — → [`Party`](#schema-party) | 200 | `has_history`, `inactive_reference` |
| 4 | Delete (L7 confirmation, only without history) | dlg:L7 → [`DELETE /parties/{id}`](#deletepartiesid) | `parties.delete` | — → — | 204 | `has_history` |

<a id="actions-c4"></a>
#### C4 · Supplier details

View permission `parties.view`. **Loads:** [`GET /audit-logs`](#getauditlogs), [`GET /parties/{id}`](#getpartiesid), [`GET /parties/{id}/open-documents`](#getpartiesidopendocuments).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Edit | nav:C3 | — | — | — | — |
| 2 | Statement | nav:C8 | — | — | — | — |
| 3 | New bill (Alt P) | nav:F3 | — | — | — | — |
| 4 | Record payment | nav:F5 | — | — | — | — |
| 5 | Switch tab | [`GET /parties/{id}/transactions`](#getpartiesidtransactions) \| [`GET /parties/{id}/ledger`](#getpartiesidledger) \| [`GET /audit-logs`](#getauditlogs) | `parties.view`, contextual — any → `<module>.view of the record named by type/id (e.g. sales.view for a sales_invoice)` | — | 200 | — |
| 6 | Apply advance or credit (NEW, R-08) | [`GET /parties/{id}/open-documents`](#getpartiesidopendocuments) → [`POST /allocations`](#postallocations) | `parties.view`, contextual — party is a customer → `sales.create`; party is a supplier → `purchases.create` | [`AllocationCreate`](#schema-allocationcreate) → [`Allocation`](#schema-allocation) | 200, 201 | `allocation_exceeds_balance`, `foreign_document`, `period_locked` |
| 7 | Remove allocation (NEW, R-08) | dlg:L6 → [`DELETE /allocations/{id}`](#deleteallocationsid) | contextual — party is a customer → `sales.edit`; party is a supplier → `purchases.edit` | — → — | 204 | `allocation_exceeds_balance`, `period_locked` |

<a id="actions-c5"></a>
#### C5 · Supplier transaction history

View permission `parties.view`. **Loads:** [`GET /parties/{id}/transactions`](#getpartiesidtransactions).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open document | nav:* | — | — | — | — |
| 2 | Export | export:[`GET /parties/{id}/transactions`](#getpartiesidtransactions) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-c6"></a>
#### C6 · Supplier ledger

View permission `parties.view`. **Loads:** [`GET /parties/{id}/ledger`](#getpartiesidledger).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /parties/{id}/ledger`](#getpartiesidledger) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 3 | Open voucher | nav:* | — | — | — | — |

<a id="actions-c7"></a>
#### C7 · Outstanding bills

View permission `parties.view`. **Loads:** [`GET /parties/outstanding`](#getpartiesoutstanding).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Filter by bucket | [`GET /parties/outstanding`](#getpartiesoutstanding) | `parties.view` | — | 200 | — |
| 2 | Send reminders/statements (bulk) | [`POST /parties/statements/send`](#postpartiesstatementssend) | `parties.view` | [`StatementSendRequest`](#schema-statementsendrequest) → [`ExportJob`](#schema-exportjob) | 202 | — |
| 3 | Record payment (row) | nav:F5 | — | — | — | — |

<a id="actions-c8"></a>
#### C8 · Supplier statement

View permission `parties.view`. **Loads:** [`GET /parties/{id}/ledger`](#getpartiesidledger), [`GET /parties/{id}/statement`](#getpartiesidstatement), [`GET /parties/{id}/statement/pdf`](#getpartiesidstatementpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | PDF | [`GET /parties/{id}/statement/pdf`](#getpartiesidstatementpdf) | `parties.view` | — | 200 | — |
| 2 | Print | [`GET /parties/{id}/statement/pdf`](#getpartiesidstatementpdf) → ui:print | `parties.view` | — | 200 | — |
| 3 | Email to supplier | [`POST /parties/statements/send`](#postpartiesstatementssend) | `parties.view` | [`StatementSendRequest`](#schema-statementsendrequest) → [`ExportJob`](#schema-exportjob) | 202 | — |

### Inventory


<a id="actions-d1"></a>
#### D1 · Product list

View permission `inventory.view`. **Loads:** [`GET /products`](#getproducts), [`GET /product-categories`](#getproductcategories).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New item | nav:D2 | — | — | — | — |
| 2 | Import | [`POST /products/import`](#postproductsimport) (dry_run=true) → [`POST /products/import`](#postproductsimport) (dry_run=false) | `inventory.create` | [`ProductImport`](#schema-productimport) → [`ImportResult`](#schema-importresult) | 200 | `file_too_large`, `file_type_not_allowed`, `has_history`, `inactive_reference` |
| 3 | Export | export:[`GET /products`](#getproducts) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 4 | Open | nav:D4 | — | — | — | — |
| 5 | Edit (row) | nav:D3 | — | — | — | — |

<a id="actions-d2"></a>
#### D2 · Add product or service

View permission `inventory.create`. **Loads:** [`GET /tax-rules`](#gettaxrules), [`GET /product-categories`](#getproductcategories), [`GET /stock-groups`](#getstockgroups), [`GET /units`](#getunits), [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save and add another | [`POST /products`](#postproducts) → ui:reset the form | `inventory.create` | [`ProductInput`](#schema-productinput) → [`Product`](#schema-product) | 201 | — |
| 3 | Save item | [`POST /products`](#postproducts) → nav:D4 | `inventory.create` | [`ProductInput`](#schema-productinput) → [`Product`](#schema-product) | 201 | — |
| 4 | Add unit inline (NEW) | [`POST /units`](#postunits) | `inventory.create` | [`UnitInput`](#schema-unitinput) → [`Lookup`](#schema-lookup) | 201 | — |

<a id="actions-d3"></a>
#### D3 · Edit product

View permission `inventory.edit`. **Loads:** [`GET /audit-logs`](#getauditlogs), [`GET /products/{id}`](#getproductsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save changes | [`PATCH /products/{id}`](#patchproductsid) | `inventory.edit` | [`ProductInput`](#schema-productinput) → [`Product`](#schema-product) | 200 | `has_history`, `inactive_reference` |
| 3 | Go to stock adjustment | nav:D10 | — | — | — | — |
| 4 | Delete product (NEW, only without history) | dlg:L7 → [`DELETE /products/{id}`](#deleteproductsid) | `inventory.delete` | — → — | 204 | `has_history` |

<a id="actions-d4"></a>
#### D4 · Product details

View permission `inventory.view`. **Loads:** [`GET /products/{id}`](#getproductsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Edit | nav:D3 | — | — | — | — |
| 2 | Adjust | nav:D10 | — | — | — | — |
| 3 | Transfer | nav:D11 | — | — | — | — |
| 4 | All movements | nav:D9 | — | — | — | — |

<a id="actions-d5"></a>
#### D5 · Product categories

View permission `inventory.view`. **Loads:** [`GET /product-categories`](#getproductcategories), [`GET /units`](#getunits).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New category | [`POST /product-categories`](#postproductcategories) | `inventory.create` | [`LookupInput`](#schema-lookupinput) → [`Lookup`](#schema-lookup) | 201 | — |
| 2 | Edit | [`PATCH /product-categories/{id}`](#patchproductcategoriesid) | `inventory.edit` | [`LookupInput`](#schema-lookupinput) → [`Lookup`](#schema-lookup) | 200 | — |
| 3 | Reorder (drag) | [`PATCH /product-categories/{id}`](#patchproductcategoriesid) (sort_order) | `inventory.edit` | [`LookupInput`](#schema-lookupinput) → [`Lookup`](#schema-lookup) | 200 | — |
| 4 | Delete (only if unused) | dlg:L7 → [`DELETE /product-categories/{id}`](#deleteproductcategoriesid) | `inventory.delete` | — → — | 204 | `has_history` |

<a id="actions-d6"></a>
#### D6 · Stock groups

View permission `inventory.view`. **Loads:** [`GET /stock-groups`](#getstockgroups).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New stock group | [`POST /stock-groups`](#poststockgroups) | `inventory.create` | [`LookupInput`](#schema-lookupinput) → [`Lookup`](#schema-lookup) | 201 | — |
| 2 | Edit | [`PATCH /stock-groups/{id}`](#patchstockgroupsid) | `inventory.edit` | [`LookupInput`](#schema-lookupinput) → [`Lookup`](#schema-lookup) | 200 | — |
| 3 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 4 | Delete stock group (NEW, only when unused) | dlg:L7 → [`DELETE /stock-groups/{id}`](#deletestockgroupsid) | `inventory.delete` | — → — | 204 | `has_history` |

<a id="actions-d7"></a>
#### D7 · Warehouse list

View permission `inventory.view`. **Loads:** [`GET /warehouses`](#getwarehouses).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open | nav:D8 | — | — | — | — |
| 2 | Transfer | nav:D11 | — | — | — | — |
| 3 | New warehouse (drawer: code, name, branch, address, manager, phone, inventory ledger, default for sales, status, opens on) | [`POST /warehouses`](#postwarehouses) | `inventory.create` | [`WarehouseInput`](#schema-warehouseinput) → [`Warehouse`](#schema-warehouse) | 201 | — |

<a id="actions-d8"></a>
#### D8 · Warehouse details

View permission `inventory.view`. **Loads:** [`GET /warehouses/{id}`](#getwarehousesid), [`GET /warehouses/{id}/stock`](#getwarehousesidstock), [`GET /stock-adjustments`](#getstockadjustments), [`GET /stock-transfers`](#getstocktransfers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Edit | [`PATCH /warehouses/{id}`](#patchwarehousesid) | `inventory.edit` | [`WarehouseInput`](#schema-warehouseinput) → [`Warehouse`](#schema-warehouse) | 200 | — |
| 2 | Count stock | nav:D10 | — | — | — | — |
| 3 | Transfer | nav:D11 | — | — | — | — |

<a id="actions-d9"></a>
#### D9 · Stock movement history

View permission `inventory.view`. **Loads:** [`GET /stock-movements`](#getstockmovements).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open source document | nav:* | — | — | — | — |
| 2 | Export | export:[`GET /stock-movements`](#getstockmovements) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-d10"></a>
#### D10 · Stock adjustment

View permission `inventory.create`. **Loads:** [`GET /products`](#getproducts), [`GET /warehouses`](#getwarehouses), [`GET /stock-adjustments`](#getstockadjustments), [`GET /stock-adjustments/{id}`](#getstockadjustmentsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Import count sheet | [`POST /stock-adjustments/count-sheet`](#poststockadjustmentscountsheet) | `inventory.create` | [`FileUpload`](#schema-fileupload) → [`CountSheetPreview`](#schema-countsheetpreview) | 200 | `file_too_large`, `file_type_not_allowed` |
| 2 | Add product | [`GET /products`](#getproducts) | `inventory.view` | — | 200 | — |
| 3 | Remove line | ui:remove the line | — | — | — | — |
| 4 | Save draft | [`POST /stock-adjustments`](#poststockadjustments) \| [`PATCH /stock-adjustments/{id}`](#patchstockadjustmentsid) | `inventory.create`, `inventory.edit` | [`StockAdjustmentInput`](#schema-stockadjustmentinput) → [`StockAdjustment`](#schema-stockadjustment) | 200, 201 | `duplicate`, `not_draft`, `period_locked` |
| 5 | Post adjustment | [`POST /stock-adjustments`](#poststockadjustments) \| [`PATCH /stock-adjustments/{id}`](#patchstockadjustmentsid) → dlg:L6 → [`POST /stock-adjustments/{id}/post`](#poststockadjustmentsidpost) | `inventory.create`, `inventory.edit` | [`StockAdjustmentInput`](#schema-stockadjustmentinput) → [`StockAdjustment`](#schema-stockadjustment)<br>— → [`StockAdjustment`](#schema-stockadjustment) | 200, 201 | `already_posted`, `duplicate`, `not_draft`, `period_locked`, `unbalanced_entry` |
| 6 | Delete draft (NEW) | dlg:L7 → [`DELETE /stock-adjustments/{id}`](#deletestockadjustmentsid) | `inventory.delete` | — → — | 204 | `duplicate`, `not_draft` |
| 7 | Reverse posted adjustment (NEW) | dlg:L6 → [`POST /stock-adjustments/{id}/reverse`](#poststockadjustmentsidreverse) | `inventory.approve` | [`ReasonRequest`](#schema-reasonrequest) → [`StockAdjustment`](#schema-stockadjustment) | 200 | `already_reversed`, `insufficient_stock`, `period_locked`, `use_document_cancel` |

<a id="actions-d11"></a>
#### D11 · Stock transfer

View permission `inventory.create`. **Loads:** [`GET /products`](#getproducts), [`GET /warehouses`](#getwarehouses), [`GET /stock-transfers`](#getstocktransfers), [`GET /stock-transfers/{id}`](#getstocktransfersid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add product | [`GET /products`](#getproducts) | `inventory.view` | — | 200 | — |
| 2 | Save draft | [`POST /stock-transfers`](#poststocktransfers) \| [`PATCH /stock-transfers/{id}`](#patchstocktransfersid) | `inventory.create`, `inventory.edit` | [`StockTransferInput`](#schema-stocktransferinput) → [`StockTransfer`](#schema-stocktransfer) | 200, 201 | `duplicate`, `insufficient_stock`, `not_draft`, `same_warehouse` |
| 3 | Dispatch transfer | dlg:L6 → [`POST /stock-transfers/{id}/dispatch`](#poststocktransfersiddispatch) | `inventory.create` | — → [`StockTransfer`](#schema-stocktransfer) | 200 | `already_posted`, `insufficient_stock`, `period_locked`, `same_warehouse` |
| 4 | Confirm receipt (destination) | [`POST /stock-transfers/{id}/receive`](#poststocktransfersidreceive) | `inventory.create` | [`TransferReceive`](#schema-transferreceive) → [`StockTransfer`](#schema-stocktransfer) | 200 | `already_posted`, `insufficient_stock`, `period_locked`, `same_warehouse` |
| 5 | Cancel draft | dlg:L7 → [`DELETE /stock-transfers/{id}`](#deletestocktransfersid) \| dlg:L6 → [`POST /stock-transfers/{id}/cancel`](#poststocktransfersidcancel) | `inventory.delete`, `inventory.edit` | — → —<br>[`ReasonRequest`](#schema-reasonrequest) → [`StockTransfer`](#schema-stocktransfer) | 200, 204 | `already_reversed`, `duplicate`, `not_draft`, `use_document_cancel` |

<a id="actions-d12"></a>
#### D12 · Low-stock report

View permission `inventory.view`. **Loads:** [`GET /reports/inventory/low-stock`](#getreportsinventorylowstock).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Create purchase orders (from selection) | [`POST /purchase-orders/from-low-stock`](#postpurchaseordersfromlowstock) | `purchases.create` | [`LowStockOrderRequest`](#schema-lowstockorderrequest) → [`BulkResult`](#schema-bulkresult) | 201 | — |
| 2 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 3 | Export | export:[`GET /reports/inventory/low-stock`](#getreportsinventorylowstock) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-d13"></a>
#### D13 · Stock valuation report

View permission `inventory.view_costs`. **Loads:** [`GET /reports/inventory/valuation`](#getreportsinventoryvaluation).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /reports/inventory/valuation`](#getreportsinventoryvaluation) | `inventory.view_costs` | — | 200 | — |

<a id="actions-d14"></a>
#### D14 · Inventory summary

View permission `inventory.view`. **Loads:** [`GET /reports/inventory/summary`](#getreportsinventorysummary).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Transfer | nav:D11 | — | — | — | — |
| 2 | Adjust stock | nav:D10 | — | — | — | — |
| 3 | Valuation | nav:D13 | — | — | — | — |
| 4 | Low-stock report | nav:D12 | — | — | — | — |

### Sales


<a id="actions-e1"></a>
#### E1 · Sales overview

View permission `sales.view`. **Loads:** [`GET /sales/overview`](#getsalesoverview), [`GET /sales-invoices`](#getsalesinvoices), [`GET /quotations`](#getquotations).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New quotation | nav:E10 | — | — | — | — |
| 2 | New invoice | nav:E3 | — | — | — | — |
| 3 | Drill to status tab | nav:E2 | — | — | — | — |
| 4 | View all | nav:E2 | — | — | — | — |

<a id="actions-e2"></a>
#### E2 · Sales invoice list

View permission `sales.view`. **Loads:** [`GET /sales-invoices`](#getsalesinvoices).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New invoice | nav:E3 | — | — | — | — |
| 2 | Export | export:[`GET /sales-invoices`](#getsalesinvoices) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 3 | Open | nav:E5 | — | — | — | — |
| 4 | Print (row) | [`GET /sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf) → ui:print | `sales.view` | — | 200 | — |
| 5 | Post selected drafts (bulk, L6) | dlg:L6 → [`POST /sales-invoices/bulk-post`](#postsalesinvoicesbulkpost) | `sales.create` | [`BulkIds`](#schema-bulkids) → [`BulkResult`](#schema-bulkresult) | 200 | `already_posted`, `insufficient_stock`, `unbalanced_entry` |

<a id="actions-e3"></a>
#### E3 · Create sales invoice

View permission `sales.create`. **Loads:** [`GET /payment-terms`](#getpaymentterms), [`GET /tax-rules`](#gettaxrules), [`GET /parties`](#getparties), [`GET /products`](#getproducts), [`GET /warehouses`](#getwarehouses).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Find item | [`GET /products`](#getproducts) | `inventory.view` | — | 200 | — |
| 2 | Paste from sheet | ui:parse pasted rows → [`GET /products`](#getproducts) | `inventory.view` | — | 200 | — |
| 3 | Add line (Ctrl Enter) | ui:add a line → [`POST /sales-invoices/calculate`](#postsalesinvoicescalculate) | `sales.create` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`DocumentCalculation`](#schema-documentcalculation) | 200 | `rounding_ledger_missing` |
| 4 | Remove line | ui:remove the line → [`POST /sales-invoices/calculate`](#postsalesinvoicescalculate) | `sales.create` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`DocumentCalculation`](#schema-documentcalculation) | 200 | `rounding_ledger_missing` |
| 5 | Cancel (unsaved dialog) | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 6 | Save draft | [`POST /sales-invoices`](#postsalesinvoices) | `sales.create` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`SalesInvoice`](#schema-salesinvoice) | 201 | `duplicate`, `inactive_reference` |
| 7 | Preview | [`POST /sales-invoices`](#postsalesinvoices) → nav:E4 | `sales.create` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`SalesInvoice`](#schema-salesinvoice) | 201 | `duplicate`, `inactive_reference` |
| 8 | Print | [`POST /sales-invoices`](#postsalesinvoices) → [`GET /sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf) → ui:print | `sales.create`, `sales.view` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`SalesInvoice`](#schema-salesinvoice) | 200, 201 | `duplicate`, `inactive_reference` |
| 9 | Save invoice (Ctrl S) > Post dialog | [`POST /sales-invoices`](#postsalesinvoices) → dlg:Sal-DlgPost | `sales.create` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`SalesInvoice`](#schema-salesinvoice) | 201 | `duplicate`, `inactive_reference` |

<a id="actions-e4"></a>
#### E4 · Invoice preview

View permission `sales.view`. **Loads:** [`GET /sales-invoices/{id}`](#getsalesinvoicesid), [`GET /sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Back to edit | nav:E6 | — | — | — | — |
| 2 | PDF | [`GET /sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf) | `sales.view` | — | 200 | — |
| 3 | Print | [`GET /sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf) → ui:print | `sales.view` | — | 200 | — |
| 4 | Save and post | dlg:Sal-DlgPost | — | — | — | — |

<a id="actions-e5"></a>
#### E5 · Invoice details

View permission `sales.view`. **Loads:** [`GET /attachments/{id}/url`](#getattachmentsidurl), [`GET /audit-logs`](#getauditlogs), [`GET /sales-invoices/{id}`](#getsalesinvoicesid), [`GET /sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Record payment (Alt R) | nav:E7 | — | — | — | — |
| 2 | Print | [`GET /sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf) → ui:print | `sales.view` | — | 200 | — |
| 3 | Duplicate | nav:E3 (prefilled from this invoice; prices recalculated by [`POST /sales-invoices/calculate`](#postsalesinvoicescalculate)) | `sales.create` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`DocumentCalculation`](#schema-documentcalculation) | 200 | `rounding_ledger_missing` |
| 4 | Create credit note | nav:E9 | — | — | — | — |
| 5 | Download PDF | [`GET /sales-invoices/{id}/pdf`](#getsalesinvoicesidpdf) | `sales.view` | — | 200 | — |
| 6 | Cancel invoice (dialog) | dlg:Sal-DlgCancel | — | — | — | — |
| 7 | Attach file | [`POST /attachments`](#postattachments) | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` | [`AttachmentUpload`](#schema-attachmentupload) → [`Attachment`](#schema-attachment) | 201 | `file_too_large`, `file_type_not_allowed` |
| 8 | Apply advance or credit (NEW, R-08) | [`GET /parties/{id}/open-documents`](#getpartiesidopendocuments) → [`POST /allocations`](#postallocations) | `parties.view`, contextual — party is a customer → `sales.create`; party is a supplier → `purchases.create` | [`AllocationCreate`](#schema-allocationcreate) → [`Allocation`](#schema-allocation) | 200, 201 | `allocation_exceeds_balance`, `foreign_document`, `period_locked` |
| 9 | Remove allocation (NEW, R-08) | dlg:L6 → [`DELETE /allocations/{id}`](#deleteallocationsid) | contextual — party is a customer → `sales.edit`; party is a supplier → `purchases.edit` | — → — | 204 | `allocation_exceeds_balance`, `period_locked` |
| 10 | Edit delivery details (NEW) | [`PATCH /sales-invoices/{id}/delivery`](#patchsalesinvoicesiddelivery) | `sales.edit` | [`DeliveryUpdate`](#schema-deliveryupdate) → [`SalesInvoice`](#schema-salesinvoice) | 200 | — |
| 11 | E-mail to customer (NEW) | [`POST /sales-invoices/{id}/send`](#postsalesinvoicesidsend) | `sales.edit` | [`EmailRequest`](#schema-emailrequest) → — | 202 | — |

<a id="actions-e6"></a>
#### E6 · Edit draft invoice

View permission `sales.edit`. **Loads:** [`GET /sales-invoices/{id}`](#getsalesinvoicesid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Delete draft (dialog) | dlg:Sal-DlgDelete | — | — | — | — |
| 2 | Save draft | [`PUT /sales-invoices/{id}`](#putsalesinvoicesid) | `sales.edit` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`SalesInvoice`](#schema-salesinvoice) | 200 | `inactive_reference`, `not_draft` |
| 3 | Preview | [`PUT /sales-invoices/{id}`](#putsalesinvoicesid) → nav:E4 | `sales.edit` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`SalesInvoice`](#schema-salesinvoice) | 200 | `inactive_reference`, `not_draft` |
| 4 | Post invoice | [`PUT /sales-invoices/{id}`](#putsalesinvoicesid) → dlg:Sal-DlgPost | `sales.edit` | [`SalesInvoiceInput`](#schema-salesinvoiceinput) → [`SalesInvoice`](#schema-salesinvoice) | 200 | `inactive_reference`, `not_draft` |

<a id="actions-e7"></a>
#### E7 · Customer payment

View permission `sales.create`. **Loads:** [`GET /parties`](#getparties), [`GET /parties/{id}/open-documents`](#getpartiesidopendocuments), [`GET /customer-receipts/{id}`](#getcustomerreceiptsid), [`GET /customer-receipts/{id}/pdf`](#getcustomerreceiptsidpdf), [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Re-apply oldest first | ui:re-allocate oldest due first on the client (the server re-validates, BR-020) | — | — | — | — |
| 2 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 3 | Print voucher | [`GET /customer-receipts/{id}/pdf`](#getcustomerreceiptsidpdf) | `sales.view` | — | 200 | — |
| 4 | Save receipt (confirm dialog) | dlg:L6 → [`POST /customer-receipts`](#postcustomerreceipts) | `sales.create` | [`PartyPaymentInput`](#schema-partypaymentinput) → [`Voucher`](#schema-voucher) | 201 | `allocation_exceeds_balance`, `already_posted`, `backdated_confirmation_required`, `foreign_document`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-e8"></a>
#### E8 · Sales return

View permission `sales.create`. **Loads:** [`GET /sales-returns`](#getsalesreturns), [`GET /sales-returns/{id}`](#getsalesreturnsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save draft | [`POST /sales-returns`](#postsalesreturns) \| [`PUT /sales-returns/{id}`](#putsalesreturnsid) | `sales.create`, `sales.edit` | [`ReturnInput`](#schema-returninput) → [`Return`](#schema-return) | 200, 201 | `duplicate`, `foreign_document`, `return_qty_exceeds` |
| 3 | Save return and create credit note | [`POST /sales-returns`](#postsalesreturns) \| [`PUT /sales-returns/{id}`](#putsalesreturnsid) → [`POST /sales-returns/{id}/post`](#postsalesreturnsidpost) → nav:E9 | `sales.create`, `sales.edit` | [`ReturnInput`](#schema-returninput) → [`Return`](#schema-return)<br>— → [`Return`](#schema-return) | 200, 201 | `already_posted`, `duplicate`, `foreign_document`, `period_locked`, `return_qty_exceeds`, `unbalanced_entry` |

<a id="actions-e9"></a>
#### E9 · Credit note

View permission `sales.create`. **Loads:** [`GET /credit-notes/{id}`](#getcreditnotesid), [`GET /credit-notes/{id}/pdf`](#getcreditnotesidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Back | nav:* | — | — | — | — |
| 2 | Print | [`GET /credit-notes/{id}/pdf`](#getcreditnotesidpdf) | `sales.view` | — | 200 | — |
| 3 | Post credit note | [`POST /credit-notes`](#postcreditnotes) \| [`PUT /credit-notes/{id}`](#putcreditnotesid) → dlg:L6 → [`POST /credit-notes/{id}/post`](#postcreditnotesidpost) | `sales.create`, `sales.edit` | [`NoteInput`](#schema-noteinput) → [`Note`](#schema-note)<br>— → [`Note`](#schema-note) | 200, 201 | `allocation_exceeds_balance`, `already_posted`, `period_locked`, `unbalanced_entry` |

<a id="actions-e10"></a>
#### E10 · Sales quotation

View permission `sales.create`. **Loads:** [`GET /products`](#getproducts), [`GET /quotations`](#getquotations), [`GET /quotations/{id}`](#getquotationsid), [`GET /quotations/{id}/pdf`](#getquotationsidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Save draft | [`POST /quotations`](#postquotations) \| [`PUT /quotations/{id}`](#putquotationsid) | `sales.create`, `sales.edit` | [`QuotationInput`](#schema-quotationinput) → [`Quotation`](#schema-quotation) | 200, 201 | `duplicate` |
| 2 | Preview | [`GET /quotations/{id}/pdf`](#getquotationsidpdf) | `sales.view` | — | 200 | — |
| 3 | Send quotation | [`POST /quotations/{id}/send`](#postquotationsidsend) | `sales.edit` | [`EmailRequest`](#schema-emailrequest) → [`Quotation`](#schema-quotation) | 200 | — |
| 4 | Convert to invoice (accepted) | [`POST /quotations/{id}/convert`](#postquotationsidconvert) → nav:E6 | `sales.create` | — → [`SalesInvoice`](#schema-salesinvoice) | 201 | — |

<a id="actions-e11"></a>
#### E11 · Sales report

View permission `reports.view`. **Loads:** [`GET /reports/sales`](#getreportssales).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /reports/sales`](#getreportssales) | `reports.view` | — | 200 | — |

<a id="actions-e12"></a>
#### E12 · Sales return report

View permission `reports.view`. **Loads:** [`GET /reports/sales-returns`](#getreportssalesreturns).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /reports/sales-returns`](#getreportssalesreturns) | `reports.view` | — | 200 | — |

<a id="actions-e13"></a>
#### E13 · Quotation list (NEW)

View permission `sales.view`. **Loads:** [`GET /quotations`](#getquotations), [`GET /quotations/{id}/pdf`](#getquotationsidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New quotation | nav:E10 | — | — | — | — |
| 2 | Open | nav:E10 | — | — | — | — |
| 3 | Mark accepted / declined / expired (row) | [`POST /quotations/{id}/status`](#postquotationsidstatus) | `sales.edit` | [`StatusChange`](#schema-statuschange) → [`Quotation`](#schema-quotation) | 200 | — |
| 4 | Convert to invoice (row, accepted) | [`POST /quotations/{id}/convert`](#postquotationsidconvert) → nav:E6 | `sales.create` | — → [`SalesInvoice`](#schema-salesinvoice) | 201 | — |
| 5 | Print (row) | [`GET /quotations/{id}/pdf`](#getquotationsidpdf) → ui:print | `sales.view` | — | 200 | — |
| 6 | Delete draft (row) | dlg:L7 → [`DELETE /quotations/{id}`](#deletequotationsid) | `sales.delete` | — → — | 204 | `duplicate`, `not_draft` |
| 7 | Export | export:[`GET /quotations`](#getquotations) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-e14"></a>
#### E14 · Payments received (NEW)

View permission `sales.view`. **Loads:** [`GET /customer-receipts`](#getcustomerreceipts), [`GET /customer-receipts/{id}`](#getcustomerreceiptsid), [`GET /customer-receipts/{id}/pdf`](#getcustomerreceiptsidpdf), [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New receipt | nav:E7 | — | — | — | — |
| 2 | Open (drawer) | [`GET /customer-receipts/{id}`](#getcustomerreceiptsid) | `sales.view` | — | 200 | — |
| 3 | Print voucher (row) | [`GET /customer-receipts/{id}/pdf`](#getcustomerreceiptsidpdf) → ui:print | `sales.view` | — | 200 | — |
| 4 | Export | export:[`GET /customer-receipts`](#getcustomerreceipts) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-e15"></a>
#### E15 · Returns and credit notes (NEW)

View permission `sales.view`. **Loads:** [`GET /sales-returns`](#getsalesreturns), [`GET /credit-notes`](#getcreditnotes), [`GET /credit-notes/{id}/pdf`](#getcreditnotesidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New return | nav:E8 | — | — | — | — |
| 2 | New credit note | nav:E9 | — | — | — | — |
| 3 | Open | nav:E8 \| nav:E9 | — | — | — | — |
| 4 | Print credit note (row) | [`GET /credit-notes/{id}/pdf`](#getcreditnotesidpdf) → ui:print | `sales.view` | — | 200 | — |
| 5 | Delete draft (row) | dlg:L7 → [`DELETE /sales-returns/{id}`](#deletesalesreturnsid) \| dlg:L7 → [`DELETE /credit-notes/{id}`](#deletecreditnotesid) | `sales.delete` | — → — | 204 | `duplicate`, `not_draft` |
| 6 | Cancel posted (row, dialog) | dlg:L6 → [`POST /sales-returns/{id}/cancel`](#postsalesreturnsidcancel) \| dlg:L6 → [`POST /credit-notes/{id}/cancel`](#postcreditnotesidcancel) | `sales.edit` | [`ReasonRequest`](#schema-reasonrequest) → [`Return`](#schema-return)<br>[`ReasonRequest`](#schema-reasonrequest) → [`Note`](#schema-note) | 200 | `allocation_exceeds_balance`, `already_posted`, `already_reversed`, `invalid_transition`, `note_applied`, `period_locked`, `use_document_cancel` |
| 7 | Export | export:[`GET /sales-returns`](#getsalesreturns) \| export:[`GET /credit-notes`](#getcreditnotes) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-sal-dlgpost"></a>
#### Sal-DlgPost · Dialog · Post invoice

View permission `sales.edit`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Keep | ui:close the dialog | — | — | — | — |
| 2 | Confirm | [`POST /sales-invoices/{id}/post`](#postsalesinvoicesidpost) | `sales.create` | [`PostRequest`](#schema-postrequest) → [`SalesInvoice`](#schema-salesinvoice) | 200 | `allocation_exceeds_balance`, `already_posted`, `backdated_confirmation_required`, `inactive_reference`, `insufficient_stock`, `mapping_missing`, `period_locked`, `rounding_ledger_missing`, `unbalanced_entry` |

<a id="actions-sal-dlgcancel"></a>
#### Sal-DlgCancel · Dialog · Cancel invoice

View permission `sales.edit`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Keep | ui:close the dialog | — | — | — | — |
| 2 | Confirm | [`POST /sales-invoices/{id}/cancel`](#postsalesinvoicesidcancel) | `sales.edit` | [`CancelRequest`](#schema-cancelrequest) → [`SalesInvoice`](#schema-salesinvoice) | 200 | `already_reversed`, `invalid_transition`, `period_locked`, `use_document_cancel` |

<a id="actions-sal-dlgdelete"></a>
#### Sal-DlgDelete · Dialog · Delete draft

View permission `sales.delete`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Keep | ui:close the dialog | — | — | — | — |
| 2 | Confirm | [`DELETE /sales-invoices/{id}`](#deletesalesinvoicesid) | `sales.delete` | — → — | 204 | `duplicate`, `not_draft` |

### Purchases


<a id="actions-f1"></a>
#### F1 · Purchase overview

View permission `purchases.view`. **Loads:** [`GET /purchases/overview`](#getpurchasesoverview), [`GET /purchase-bills`](#getpurchasebills), [`GET /purchase-orders`](#getpurchaseorders).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New purchase order | nav:F8 | — | — | — | — |
| 2 | New bill | nav:F3 | — | — | — | — |

<a id="actions-f2"></a>
#### F2 · Purchase bill list

View permission `purchases.view`. **Loads:** [`GET /purchase-bills`](#getpurchasebills).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New bill | nav:F3 | — | — | — | — |
| 2 | Export | export:[`GET /purchase-bills`](#getpurchasebills) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 3 | Open | nav:F4 | — | — | — | — |

<a id="actions-f3"></a>
#### F3 · Create purchase bill

View permission `purchases.create`. **Loads:** [`GET /payment-terms`](#getpaymentterms), [`GET /tax-rules`](#gettaxrules), [`GET /parties`](#getparties), [`GET /products`](#getproducts), [`GET /warehouses`](#getwarehouses), [`GET /purchase-orders`](#getpurchaseorders), [`GET /letters-of-credit`](#getlettersofcredit).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Find item | [`GET /products`](#getproducts) | `inventory.view` | — | 200 | — |
| 2 | Add line | ui:add a line → [`POST /purchase-bills/calculate`](#postpurchasebillscalculate) | `purchases.create` | [`PurchaseBillInput`](#schema-purchasebillinput) → [`DocumentCalculation`](#schema-documentcalculation) | 200 | — |
| 3 | Save draft | [`POST /purchase-bills`](#postpurchasebills) \| [`PUT /purchase-bills/{id}`](#putpurchasebillsid) | `purchases.create`, `purchases.edit` | [`PurchaseBillInput`](#schema-purchasebillinput) → [`PurchaseBill`](#schema-purchasebill) | 200, 201 | `duplicate`, `duplicate_supplier_bill`, `not_draft` |
| 4 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 5 | Save bill > Post dialog | [`POST /purchase-bills`](#postpurchasebills) \| [`PUT /purchase-bills/{id}`](#putpurchasebillsid) → dlg:L6 → [`POST /purchase-bills/{id}/post`](#postpurchasebillsidpost) | `purchases.create`, `purchases.edit` | [`PurchaseBillInput`](#schema-purchasebillinput) → [`PurchaseBill`](#schema-purchasebill)<br>[`PostRequest`](#schema-postrequest) → [`PurchaseBill`](#schema-purchasebill) | 200, 201 | `already_posted`, `backdated_confirmation_required`, `duplicate`, `duplicate_supplier_bill`, `not_draft`, `period_locked`, `unbalanced_entry` |
| 6 | Delete draft (NEW) | dlg:L7 → [`DELETE /purchase-bills/{id}`](#deletepurchasebillsid) | `purchases.delete` | — → — | 204 | `not_draft` |

<a id="actions-f4"></a>
#### F4 · Purchase bill details

View permission `purchases.view`. **Loads:** [`GET /attachments/{id}/url`](#getattachmentsidurl), [`GET /audit-logs`](#getauditlogs), [`GET /purchase-bills/{id}`](#getpurchasebillsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Record payment (Alt Y) | nav:F5 | — | — | — | — |
| 2 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 3 | Create debit note | nav:F7 | — | — | — | — |
| 4 | Cancel bill (dialog) | dlg:L6 → [`POST /purchase-bills/{id}/cancel`](#postpurchasebillsidcancel) | `purchases.edit` | [`CancelRequest`](#schema-cancelrequest) → [`PurchaseBill`](#schema-purchasebill) | 200 | `already_reversed`, `insufficient_stock`, `invalid_transition`, `use_document_cancel` |
| 5 | Apply advance or credit (NEW, R-08) | [`GET /parties/{id}/open-documents`](#getpartiesidopendocuments) → [`POST /allocations`](#postallocations) | `parties.view`, contextual — party is a customer → `sales.create`; party is a supplier → `purchases.create` | [`AllocationCreate`](#schema-allocationcreate) → [`Allocation`](#schema-allocation) | 200, 201 | `allocation_exceeds_balance`, `foreign_document`, `period_locked` |
| 6 | Remove allocation (NEW, R-08) | dlg:L6 → [`DELETE /allocations/{id}`](#deleteallocationsid) | contextual — party is a customer → `sales.edit`; party is a supplier → `purchases.edit` | — → — | 204 | `allocation_exceeds_balance`, `period_locked` |

<a id="actions-f5"></a>
#### F5 · Supplier payment

View permission `purchases.create`. **Loads:** [`GET /parties`](#getparties), [`GET /parties/{id}/open-documents`](#getpartiesidopendocuments), [`GET /supplier-payments/{id}`](#getsupplierpaymentsid), [`GET /supplier-payments/{id}/pdf`](#getsupplierpaymentsidpdf), [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Re-apply oldest first | ui:re-allocate oldest due first on the client (the server re-validates, BR-020) | — | — | — | — |
| 2 | Print voucher | [`GET /supplier-payments/{id}/pdf`](#getsupplierpaymentsidpdf) | `purchases.view` | — | 200 | — |
| 3 | Save payment (confirm) | dlg:Pur-DlgPay | — | — | — | — |

<a id="actions-f6"></a>
#### F6 · Purchase return

View permission `purchases.create`. **Loads:** [`GET /purchase-returns`](#getpurchasereturns), [`GET /purchase-returns/{id}`](#getpurchasereturnsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Save draft | [`POST /purchase-returns`](#postpurchasereturns) \| [`PUT /purchase-returns/{id}`](#putpurchasereturnsid) | `purchases.create`, `purchases.edit` | [`ReturnInput`](#schema-returninput) → [`Return`](#schema-return) | 200, 201 | `foreign_document`, `return_qty_exceeds` |
| 2 | Save return and create debit note | [`POST /purchase-returns`](#postpurchasereturns) \| [`PUT /purchase-returns/{id}`](#putpurchasereturnsid) → [`POST /purchase-returns/{id}/post`](#postpurchasereturnsidpost) → nav:F7 | `purchases.create`, `purchases.edit` | [`ReturnInput`](#schema-returninput) → [`Return`](#schema-return)<br>— → [`Return`](#schema-return) | 200, 201 | `allocation_exceeds_balance`, `already_posted`, `foreign_document`, `insufficient_stock`, `period_locked`, `return_qty_exceeds`, `unbalanced_entry` |

<a id="actions-f7"></a>
#### F7 · Debit note

View permission `purchases.create`. **Loads:** [`GET /debit-notes/{id}`](#getdebitnotesid), [`GET /debit-notes/{id}/pdf`](#getdebitnotesidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Back | nav:* | — | — | — | — |
| 2 | Print | [`GET /debit-notes/{id}/pdf`](#getdebitnotesidpdf) | `purchases.view` | — | 200 | — |
| 3 | Post debit note | [`POST /debit-notes`](#postdebitnotes) \| [`PUT /debit-notes/{id}`](#putdebitnotesid) → dlg:L6 → [`POST /debit-notes/{id}/post`](#postdebitnotesidpost) | `purchases.create`, `purchases.edit` | [`NoteInput`](#schema-noteinput) → [`Note`](#schema-note)<br>— → [`Note`](#schema-note) | 200, 201 | `allocation_exceeds_balance`, `already_posted`, `created_from_return`, `period_locked`, `unbalanced_entry` |

<a id="actions-f8"></a>
#### F8 · Purchase order

View permission `purchases.create`. **Loads:** [`GET /products`](#getproducts), [`GET /purchase-orders`](#getpurchaseorders), [`GET /purchase-orders/{id}`](#getpurchaseordersid), [`GET /purchase-orders/{id}/pdf`](#getpurchaseordersidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Save draft | [`POST /purchase-orders`](#postpurchaseorders) \| [`PUT /purchase-orders/{id}`](#putpurchaseordersid) | `purchases.create`, `purchases.edit` | [`PurchaseOrderInput`](#schema-purchaseorderinput) → [`PurchaseOrder`](#schema-purchaseorder) | 200, 201 | `duplicate` |
| 2 | Send order | [`POST /purchase-orders/{id}/send`](#postpurchaseordersidsend) | `purchases.edit` | [`EmailRequest`](#schema-emailrequest) → [`PurchaseOrder`](#schema-purchaseorder) | 200 | — |
| 3 | Convert to bill | [`POST /purchase-orders/{id}/convert`](#postpurchaseordersidconvert) → nav:F3 | `purchases.create` | — → [`PurchaseBill`](#schema-purchasebill) | 201 | — |
| 4 | Create LC | nav:M3 | — | — | — | — |

<a id="actions-f9"></a>
#### F9 · Purchase report

View permission `reports.view`. **Loads:** [`GET /reports/purchases`](#getreportspurchases).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /reports/purchases`](#getreportspurchases) | `reports.view` | — | 200 | — |

<a id="actions-f10"></a>
#### F10 · Purchase return report

View permission `reports.view`. **Loads:** [`GET /reports/purchase-returns`](#getreportspurchasereturns).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /reports/purchase-returns`](#getreportspurchasereturns) | `reports.view` | — | 200 | — |

<a id="actions-f11"></a>
#### F11 · Purchase orders (NEW)

View permission `purchases.view`. **Loads:** [`GET /purchase-orders`](#getpurchaseorders), [`GET /purchase-orders/{id}/pdf`](#getpurchaseordersidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New purchase order | nav:F8 | — | — | — | — |
| 2 | Open | nav:F8 | — | — | — | — |
| 3 | Convert to bill (row) | [`POST /purchase-orders/{id}/convert`](#postpurchaseordersidconvert) → nav:F3 | `purchases.create` | — → [`PurchaseBill`](#schema-purchasebill) | 201 | — |
| 4 | Close or cancel (row) | dlg:L6 → [`POST /purchase-orders/{id}/status`](#postpurchaseordersidstatus) | `purchases.edit` | [`StatusChange`](#schema-statuschange) → [`PurchaseOrder`](#schema-purchaseorder) | 200 | — |
| 5 | Print (row) | [`GET /purchase-orders/{id}/pdf`](#getpurchaseordersidpdf) → ui:print | `purchases.view` | — | 200 | — |
| 6 | Delete draft (row) | dlg:L7 → [`DELETE /purchase-orders/{id}`](#deletepurchaseordersid) | `purchases.delete` | — → — | 204 | `duplicate`, `not_draft` |
| 7 | Export | export:[`GET /purchase-orders`](#getpurchaseorders) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-f12"></a>
#### F12 · Payments made (NEW)

View permission `purchases.view`. **Loads:** [`GET /supplier-payments`](#getsupplierpayments), [`GET /supplier-payments/{id}`](#getsupplierpaymentsid), [`GET /supplier-payments/{id}/pdf`](#getsupplierpaymentsidpdf), [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New payment | nav:F5 | — | — | — | — |
| 2 | Open (drawer) | [`GET /supplier-payments/{id}`](#getsupplierpaymentsid) | `purchases.view` | — | 200 | — |
| 3 | Print voucher (row) | [`GET /supplier-payments/{id}/pdf`](#getsupplierpaymentsidpdf) → ui:print | `purchases.view` | — | 200 | — |
| 4 | Export | export:[`GET /supplier-payments`](#getsupplierpayments) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-f13"></a>
#### F13 · Returns and debit notes (NEW)

View permission `purchases.view`. **Loads:** [`GET /purchase-returns`](#getpurchasereturns), [`GET /debit-notes`](#getdebitnotes), [`GET /debit-notes/{id}/pdf`](#getdebitnotesidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New return | nav:F6 | — | — | — | — |
| 2 | New debit note | nav:F7 | — | — | — | — |
| 3 | Open | nav:F6 \| nav:F7 | — | — | — | — |
| 4 | Print debit note (row) | [`GET /debit-notes/{id}/pdf`](#getdebitnotesidpdf) → ui:print | `purchases.view` | — | 200 | — |
| 5 | Delete draft (row) | dlg:L7 → [`DELETE /purchase-returns/{id}`](#deletepurchasereturnsid) \| dlg:L7 → [`DELETE /debit-notes/{id}`](#deletedebitnotesid) | `purchases.delete` | — → — | 204 | `duplicate`, `not_draft` |
| 6 | Cancel posted (row, dialog) | dlg:L6 → [`POST /purchase-returns/{id}/cancel`](#postpurchasereturnsidcancel) \| dlg:L6 → [`POST /debit-notes/{id}/cancel`](#postdebitnotesidcancel) | `purchases.edit` | [`ReasonRequest`](#schema-reasonrequest) → [`Return`](#schema-return)<br>[`ReasonRequest`](#schema-reasonrequest) → [`Note`](#schema-note) | 200 | `allocation_exceeds_balance`, `already_posted`, `already_reversed`, `created_from_return`, `invalid_transition`, `period_locked`, `use_document_cancel` |
| 7 | Export | export:[`GET /purchase-returns`](#getpurchasereturns) \| export:[`GET /debit-notes`](#getdebitnotes) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-pur-dlgpay"></a>
#### Pur-DlgPay · Dialog · Confirm supplier payment

View permission `purchases.create`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:close the dialog | — | — | — | — |
| 2 | Save payment | [`POST /supplier-payments`](#postsupplierpayments) | `purchases.create` | [`PartyPaymentInput`](#schema-partypaymentinput) → [`Voucher`](#schema-voucher) | 201 | `allocation_exceeds_balance`, `already_posted`, `foreign_document`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

### Banking


<a id="actions-g1"></a>
#### G1 · Cash and bank overview

View permission `banking.view`. **Loads:** [`GET /banking/overview`](#getbankingoverview), [`GET /money-transactions`](#getmoneytransactions).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Transfer | nav:G8 \| nav:G9 | — | — | — | — |
| 2 | Money paid | nav:G7 | — | — | — | — |
| 3 | Money received | nav:G6 | — | — | — | — |
| 4 | Reconcile | [`POST /reconciliations`](#postreconciliations) → nav:G10 | `banking.reconcile` | [`ReconciliationStart`](#schema-reconciliationstart) → [`Reconciliation`](#schema-reconciliation) | 200 | — |
| 5 | Open account | nav:G2 \| nav:G5 | — | — | — | — |

<a id="actions-g2"></a>
#### G2 · Cash account details

View permission `banking.view`. **Loads:** [`GET /money-accounts/{id}`](#getmoneyaccountsid), [`GET /money-accounts/{id}/book`](#getmoneyaccountsidbook).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cash transfer | nav:G8 | — | — | — | — |
| 2 | Count cash (records cash count; difference needs approval) | [`POST /cash-counts`](#postcashcounts) | `banking.create` | [`CashCountInput`](#schema-cashcountinput) → [`CashCount`](#schema-cashcount) | 201 | — |
| 3 | Reverse cash-count adjustment (NEW, R-03) | dlg:L6 → [`POST /cash-counts/{id}/reverse`](#postcashcountsidreverse) | `banking.approve` | [`ReasonRequest`](#schema-reasonrequest) → [`CashCount`](#schema-cashcount) | 200 | `already_reversed`, `period_locked`, `use_document_cancel` |

<a id="actions-g3"></a>
#### G3 · Bank account list

View permission `banking.view`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add account | nav:G4 | — | — | — | — |
| 2 | Open | nav:G5 | — | — | — | — |
| 3 | Import statement | [`POST /bank-statement-imports`](#postbankstatementimports) | `banking.reconcile` | [`StatementImport`](#schema-statementimport) → [`StatementImportResult`](#schema-statementimportresult) | 201 | `duplicate_statement`, `file_too_large`, `file_type_not_allowed` |

<a id="actions-g4"></a>
#### G4 · Add bank account

View permission `banking.create`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Add account | [`POST /money-accounts`](#postmoneyaccounts) | `banking.create` | [`MoneyAccountInput`](#schema-moneyaccountinput) → [`MoneyAccount`](#schema-moneyaccount) | 201 | — |

<a id="actions-g5"></a>
#### G5 · Bank account details

View permission `banking.view`. **Loads:** [`GET /money-accounts/{id}`](#getmoneyaccountsid), [`GET /money-accounts/{id}/book`](#getmoneyaccountsidbook).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Reconcile | [`POST /reconciliations`](#postreconciliations) → nav:G10 | `banking.reconcile` | [`ReconciliationStart`](#schema-reconciliationstart) → [`Reconciliation`](#schema-reconciliation) | 200 | — |
| 2 | Import statement | [`POST /bank-statement-imports`](#postbankstatementimports) | `banking.reconcile` | [`StatementImport`](#schema-statementimport) → [`StatementImportResult`](#schema-statementimportresult) | 201 | `duplicate_statement`, `file_too_large`, `file_type_not_allowed` |
| 3 | Bank book | nav:G12 | — | — | — | — |
| 4 | Edit account (NEW) | [`PATCH /money-accounts/{id}`](#patchmoneyaccountsid) | `banking.edit` | [`MoneyAccountInput`](#schema-moneyaccountinput) → [`MoneyAccount`](#schema-moneyaccount) | 200 | — |

<a id="actions-g6"></a>
#### G6 · Money received

View permission `banking.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save and new | [`POST /vouchers`](#postvouchers) → ui:reset the form | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |
| 3 | Save | [`POST /vouchers`](#postvouchers) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-g7"></a>
#### G7 · Money paid

View permission `banking.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save and new | [`POST /vouchers`](#postvouchers) → ui:reset the form | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |
| 3 | Save | [`POST /vouchers`](#postvouchers) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-g8"></a>
#### G8 · Cash transfer

View permission `banking.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save transfer | [`POST /vouchers`](#postvouchers) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-g9"></a>
#### G9 · Bank transfer

View permission `banking.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save transfer | [`POST /vouchers`](#postvouchers) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-g10"></a>
#### G10 · Bank reconciliation

View permission `banking.reconcile`. **Loads:** [`GET /reconciliations/{id}`](#getreconciliationsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Import statement | [`POST /bank-statement-imports`](#postbankstatementimports) → [`POST /reconciliations`](#postreconciliations) | `banking.reconcile` | [`StatementImport`](#schema-statementimport) → [`StatementImportResult`](#schema-statementimportresult)<br>[`ReconciliationStart`](#schema-reconciliationstart) → [`Reconciliation`](#schema-reconciliation) | 200, 201 | `duplicate_statement`, `file_too_large`, `file_type_not_allowed` |
| 2 | Accept all suggestions | [`POST /reconciliations/{id}/accept-suggestions`](#postreconciliationsidacceptsuggestions) | `banking.reconcile` | — → [`Reconciliation`](#schema-reconciliation) | 200 | — |
| 3 | Match | [`POST /reconciliations/{id}/lines/{lineId}/match`](#postreconciliationsidlineslineidmatch) | `banking.reconcile` | [`MatchRequest`](#schema-matchrequest) → [`Reconciliation`](#schema-reconciliation) | 200 | — |
| 4 | Create receipt | [`POST /reconciliations/{id}/lines/{lineId}/create-entry`](#postreconciliationsidlineslineidcreateentry) | `banking.reconcile` | [`CreateEntryFromLine`](#schema-createentryfromline) → [`Reconciliation`](#schema-reconciliation) | 200 | `mapping_missing`, `period_locked`, `unbalanced_entry` |
| 5 | Record charge | [`POST /reconciliations/{id}/lines/{lineId}/create-entry`](#postreconciliationsidlineslineidcreateentry) (kind=bank_charge) | `banking.reconcile` | [`CreateEntryFromLine`](#schema-createentryfromline) → [`Reconciliation`](#schema-reconciliation) | 200 | `mapping_missing`, `period_locked`, `unbalanced_entry` |
| 6 | Mark as pair | [`POST /reconciliations/{id}/lines/pair`](#postreconciliationsidlinespair) | `banking.reconcile` | [`PairRequest`](#schema-pairrequest) → [`Reconciliation`](#schema-reconciliation) | 200 | — |
| 7 | Undo | [`POST /reconciliations/{id}/lines/{lineId}/undo`](#postreconciliationsidlineslineidundo) | `banking.reconcile` | — → [`Reconciliation`](#schema-reconciliation) | 200 | — |
| 8 | Save progress | ui:nothing to send; every match is saved when made | — | — | — | — |
| 9 | Finish reconciliation | dlg:L6 → [`POST /reconciliations/{id}/finish`](#postreconciliationsidfinish) | `banking.reconcile` | — → [`Reconciliation`](#schema-reconciliation) | 200 | `difference_not_zero`, `lines_unhandled` |

<a id="actions-g11"></a>
#### G11 · Cash book

View permission `banking.view`. **Loads:** [`GET /money-accounts/{id}/book`](#getmoneyaccountsidbook).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open voucher | nav:* | — | — | — | — |
| 2 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 3 | Export | export:[`GET /money-accounts/{id}/book`](#getmoneyaccountsidbook) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-g12"></a>
#### G12 · Bank book

View permission `banking.view`. **Loads:** [`GET /money-accounts/{id}/book`](#getmoneyaccountsidbook).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open voucher | nav:* | — | — | — | — |
| 2 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 3 | Export | export:[`GET /money-accounts/{id}/book`](#getmoneyaccountsidbook) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-g13"></a>
#### G13 · Cash and bank transactions

View permission `banking.view`. **Loads:** [`GET /money-transactions`](#getmoneytransactions).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open voucher | nav:* | — | — | — | — |

### IncExp


<a id="actions-i1"></a>
#### I1 · Income overview

View permission `banking.view`. **Loads:** [`GET /ie-entries`](#getieentries), [`GET /income-expenses/overview`](#getincomeexpensesoverview).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add income | nav:I3 | — | — | — | — |
| 2 | Income report | nav:I8 | — | — | — | — |

<a id="actions-i4"></a>
#### I4 · Expense overview

View permission `banking.view`. **Loads:** [`GET /ie-entries`](#getieentries), [`GET /income-expenses/overview`](#getincomeexpensesoverview).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add expense | nav:I6 | — | — | — | — |
| 2 | Expense report | nav:I9 | — | — | — | — |

<a id="actions-i2"></a>
#### I2 · Income transactions

View permission `banking.view`. **Loads:** [`GET /ie-categories`](#getiecategories), [`GET /ie-entries`](#getieentries), [`GET /recurring-templates`](#getrecurringtemplates).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Attach receipt | [`POST /attachments`](#postattachments) | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` | [`AttachmentUpload`](#schema-attachmentupload) → [`Attachment`](#schema-attachment) | 201 | `file_too_large`, `file_type_not_allowed` |
| 2 | Open voucher | nav:* | — | — | — | — |
| 3 | Add income | nav:I3 | — | — | — | — |

<a id="actions-i5"></a>
#### I5 · Expense transactions

View permission `banking.view`. **Loads:** [`GET /attachments/{id}/url`](#getattachmentsidurl), [`GET /ie-categories`](#getiecategories), [`GET /ie-entries`](#getieentries), [`GET /recurring-templates`](#getrecurringtemplates).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Attach receipt | [`POST /attachments`](#postattachments) | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` | [`AttachmentUpload`](#schema-attachmentupload) → [`Attachment`](#schema-attachment) | 201 | `file_too_large`, `file_type_not_allowed` |
| 2 | Open voucher | nav:* | — | — | — | — |
| 3 | Add expense | nav:I6 | — | — | — | — |

<a id="actions-i3"></a>
#### I3 · Add income

View permission `banking.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts), [`GET /ie-categories`](#getiecategories).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save and add another | [`POST /ie-entries`](#postieentries) → ui:reset the form | `banking.create` | [`IeEntryInput`](#schema-ieentryinput) → [`Voucher`](#schema-voucher) | 201 | `insufficient_funds`, `period_locked`, `unbalanced_entry` |
| 3 | Save income | [`POST /ie-entries`](#postieentries) | `banking.create` | [`IeEntryInput`](#schema-ieentryinput) → [`Voucher`](#schema-voucher) | 201 | `insufficient_funds`, `period_locked`, `unbalanced_entry` |

<a id="actions-i6"></a>
#### I6 · Add expense

View permission `banking.create`. **Loads:** [`GET /tax-rules`](#gettaxrules), [`GET /money-accounts`](#getmoneyaccounts), [`GET /ie-categories`](#getiecategories).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save and add another | [`POST /ie-entries`](#postieentries) → ui:reset the form | `banking.create` | [`IeEntryInput`](#schema-ieentryinput) → [`Voucher`](#schema-voucher) | 201 | `insufficient_funds`, `period_locked`, `unbalanced_entry` |
| 3 | Save expense | [`POST /ie-entries`](#postieentries) | `banking.create` | [`IeEntryInput`](#schema-ieentryinput) → [`Voucher`](#schema-voucher) | 201 | `insufficient_funds`, `period_locked`, `unbalanced_entry` |
| 4 | Remove attachment (NEW) | [`DELETE /attachments/{id}`](#deleteattachmentsid) | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` | — → — | 204 | — |

<a id="actions-i7"></a>
#### I7 · Expense categories and budgets

View permission `banking.view`. **Loads:** [`GET /ie-categories`](#getiecategories), [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New category | [`POST /ie-categories`](#postiecategories) | `banking.create` | [`IeCategoryInput`](#schema-iecategoryinput) → [`IeCategory`](#schema-iecategory) | 201 | — |
| 2 | Edit category | [`PATCH /ie-categories/{id}`](#patchiecategoriesid) | `banking.edit` | [`IeCategoryInput`](#schema-iecategoryinput) → [`IeCategory`](#schema-iecategory) | 200 | — |
| 3 | This month / FY to date | [`GET /ie-categories`](#getiecategories) (period) | `banking.view` | — | 200 | — |

<a id="actions-i10"></a>
#### I10 · Recurring entries (NEW)

View permission `banking.view`. **Loads:** [`GET /recurring-templates`](#getrecurringtemplates).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Pause or resume (row) | [`PATCH /recurring-templates/{id}`](#patchrecurringtemplatesid) (is_active) | `banking.edit` | [`RecurringTemplateInput`](#schema-recurringtemplateinput) → [`RecurringTemplate`](#schema-recurringtemplate) | 200 | — |
| 2 | Edit schedule (row) | [`PATCH /recurring-templates/{id}`](#patchrecurringtemplatesid) | `banking.edit` | [`RecurringTemplateInput`](#schema-recurringtemplateinput) → [`RecurringTemplate`](#schema-recurringtemplate) | 200 | — |
| 3 | End (row) | dlg:L6 → [`PATCH /recurring-templates/{id}`](#patchrecurringtemplatesid) (ends_type=on_date) | `banking.edit` | [`RecurringTemplateInput`](#schema-recurringtemplateinput) → [`RecurringTemplate`](#schema-recurringtemplate) | 200 | — |

<a id="actions-i8"></a>
#### I8 · Income report

View permission `reports.view`. **Loads:** [`GET /reports/income`](#getreportsincome).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /reports/income`](#getreportsincome) | `reports.view` | — | 200 | — |

<a id="actions-i9"></a>
#### I9 · Expense report

View permission `reports.view`. **Loads:** [`GET /reports/expenses`](#getreportsexpenses).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export | export:[`GET /reports/expenses`](#getreportsexpenses) | `reports.view` | — | 200 | — |

### Accounting


<a id="actions-h1"></a>
#### H1 · Accounting overview

View permission `accounting.view`. **Loads:** [`GET /approvals`](#getapprovals), [`GET /accounting/overview`](#getaccountingoverview), [`GET /vouchers`](#getvouchers), [`GET /reports/trial-balance`](#getreportstrialbalance).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Trial balance | nav:H15 | — | — | — | — |
| 2 | Chart of accounts | nav:H2 | — | — | — | — |
| 3 | Journal voucher (Alt J) | nav:H9 | — | — | — | — |
| 4 | Payment (Alt Y) | nav:H10 | — | — | — | — |
| 5 | Receipt (Alt R) | nav:H11 | — | — | — | — |
| 6 | Contra (Alt C) | nav:H12 | — | — | — | — |

<a id="actions-h2"></a>
#### H2 · Chart of accounts

View permission `accounting.view`. **Loads:** [`GET /account-groups`](#getaccountgroups).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New group | [`POST /account-groups`](#postaccountgroups) | `accounting.create` | [`AccountGroupInput`](#schema-accountgroupinput) → [`AccountGroup`](#schema-accountgroup) | 201 | — |
| 2 | New ledger | nav:H4 | — | — | — | — |
| 3 | Export | export:[`GET /account-groups`](#getaccountgroups) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 4 | Open group | nav:H3 | — | — | — | — |
| 5 | Open ledger | nav:H7 | — | — | — | — |

<a id="actions-h3"></a>
#### H3 · Account group details

View permission `accounting.view`. **Loads:** [`GET /account-groups`](#getaccountgroups), [`GET /account-groups/{id}`](#getaccountgroupsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Edit group (name, parent within nature, sort) | [`PATCH /account-groups/{id}`](#patchaccountgroupsid) | `accounting.edit` | [`AccountGroupInput`](#schema-accountgroupinput) → [`AccountGroup`](#schema-accountgroup) | 200 | `ledger_locked`, `nature_mismatch` |
| 2 | Ledger in this group | nav:H6 ([`GET /ledgers`](#getledgers)?account_group_id=) | any signed-in user who is an active member of the company | — | 200 | `purpose_required` |

<a id="actions-h4"></a>
#### H4 · Add ledger account

View permission `accounting.create`. **Loads:** [`GET /account-groups`](#getaccountgroups).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save and add another | [`POST /ledgers`](#postledgers) → ui:reset the form | `accounting.create` | [`LedgerInput`](#schema-ledgerinput) → [`Ledger`](#schema-ledger) | 201 | `ledger_locked`, `nature_mismatch` |
| 3 | Create ledger | [`POST /ledgers`](#postledgers) → nav:H7 | `accounting.create` | [`LedgerInput`](#schema-ledgerinput) → [`Ledger`](#schema-ledger) | 201 | `ledger_locked`, `nature_mismatch` |

<a id="actions-h5"></a>
#### H5 · Edit ledger account

View permission `accounting.edit`. **Loads:** [`GET /ledgers/{id}`](#getledgersid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save changes | [`PATCH /ledgers/{id}`](#patchledgersid) | `accounting.edit` | [`LedgerInput`](#schema-ledgerinput) → [`Ledger`](#schema-ledger) | 200 | `ledger_locked`, `nature_mismatch` |
| 3 | Make inactive | dlg:L6 → [`POST /ledgers/{id}/deactivate`](#postledgersiddeactivate) | `accounting.edit` | — → [`Ledger`](#schema-ledger) | 200 | `has_history`, `inactive_reference` |

<a id="actions-h6"></a>
#### H6 · Ledger list

View permission `accounting.view`. **Loads:** [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open | nav:H7 | — | — | — | — |
| 2 | Edit | nav:H5 | — | — | — | — |

<a id="actions-h7"></a>
#### H7 · Ledger details

View permission `accounting.view`. **Loads:** [`GET /ledgers/{id}`](#getledgersid), [`GET /ledgers/{id}/entries`](#getledgersidentries).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open voucher | nav:H13 | — | — | — | — |
| 2 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 3 | Export | export:[`GET /ledgers/{id}/entries`](#getledgersidentries) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |

<a id="actions-h8"></a>
#### H8 · Voucher list

View permission `accounting.view`. **Loads:** [`GET /vouchers`](#getvouchers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open | nav:H13 | — | — | — | — |
| 2 | Print (row) | [`GET /vouchers/{id}/pdf`](#getvouchersidpdf) → ui:print | `accounting.view` | — | 200 | — |
| 3 | Duplicate (row) | nav:H9 (prefilled from [`GET /vouchers/{id}`](#getvouchersid)) | `accounting.view` | — | 200 | — |

<a id="actions-h9"></a>
#### H9 · Journal voucher

View permission `accounting.create`. **Loads:** [`GET /ledgers`](#getledgers), [`GET /voucher-templates`](#getvouchertemplates).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add line | ui:add a line → [`POST /vouchers/calculate`](#postvoucherscalculate) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`VoucherPreview`](#schema-voucherpreview) | 200 | — |
| 2 | Remove line | ui:remove the line → [`POST /vouchers/calculate`](#postvoucherscalculate) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`VoucherPreview`](#schema-voucherpreview) | 200 | — |
| 3 | Put difference on last line | ui:put the difference on the last line → [`POST /vouchers/calculate`](#postvoucherscalculate) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`VoucherPreview`](#schema-voucherpreview) | 200 | — |
| 4 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 5 | Save draft | [`POST /vouchers`](#postvouchers) (action=draft) \| [`PUT /vouchers/{id}`](#putvouchersid) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create`, contextual — entry_type = journal → `accounting.edit`; entry_type in (payment, receipt, contra) → `banking.edit or accounting.edit` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `not_draft`, `period_locked`, `unbalanced_entry` |
| 6 | Submit for approval (above limit) | [`POST /vouchers`](#postvouchers) (action=submit) \| [`POST /vouchers/{id}/submit`](#postvouchersidsubmit) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher)<br>— → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |
| 7 | Post voucher | [`POST /vouchers`](#postvouchers) (action=post) \| [`POST /vouchers/{id}/post`](#postvouchersidpost) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher)<br>[`PostRequest`](#schema-postrequest) → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `already_posted`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-h10"></a>
#### H10 · Payment voucher

View permission `accounting.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts), [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add line | ui:add a line → [`POST /vouchers/calculate`](#postvoucherscalculate) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`VoucherPreview`](#schema-voucherpreview) | 200 | — |
| 2 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 3 | Preview (H14) | [`POST /vouchers`](#postvouchers) (action=draft) \| [`PUT /vouchers/{id}`](#putvouchersid) → nav:H14 | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create`, contextual — entry_type = journal → `accounting.edit`; entry_type in (payment, receipt, contra) → `banking.edit or accounting.edit` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `not_draft`, `period_locked`, `unbalanced_entry` |
| 4 | Post / Submit | [`POST /vouchers`](#postvouchers) (action=post; 202 when approval is needed) \| [`POST /vouchers/{id}/post`](#postvouchersidpost) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher)<br>[`PostRequest`](#schema-postrequest) → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `already_posted`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-h11"></a>
#### H11 · Receipt voucher

View permission `accounting.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts), [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add line | ui:add a line → [`POST /vouchers/calculate`](#postvoucherscalculate) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`VoucherPreview`](#schema-voucherpreview) | 200 | — |
| 2 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 3 | Preview (H14) | [`POST /vouchers`](#postvouchers) (action=draft) \| [`PUT /vouchers/{id}`](#putvouchersid) → nav:H14 | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create`, contextual — entry_type = journal → `accounting.edit`; entry_type in (payment, receipt, contra) → `banking.edit or accounting.edit` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `not_draft`, `period_locked`, `unbalanced_entry` |
| 4 | Post / Submit | [`POST /vouchers`](#postvouchers) (action=post) \| [`POST /vouchers/{id}/post`](#postvouchersidpost) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher)<br>[`PostRequest`](#schema-postrequest) → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `already_posted`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-h12"></a>
#### H12 · Contra voucher

View permission `accounting.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts), [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add line | ui:add a line → [`POST /vouchers/calculate`](#postvoucherscalculate) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`VoucherPreview`](#schema-voucherpreview) | 200 | — |
| 2 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 3 | Preview (H14) | [`POST /vouchers`](#postvouchers) (action=draft) \| [`PUT /vouchers/{id}`](#putvouchersid) → nav:H14 | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create`, contextual — entry_type = journal → `accounting.edit`; entry_type in (payment, receipt, contra) → `banking.edit or accounting.edit` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `not_draft`, `period_locked`, `unbalanced_entry` |
| 4 | Post / Submit | [`POST /vouchers`](#postvouchers) (action=post; 202 when approval is needed) \| [`POST /vouchers/{id}/post`](#postvouchersidpost) | contextual — entry_type = journal → `accounting.create`; entry_type in (payment, receipt, contra) → `banking.create or accounting.create` | [`VoucherInput`](#schema-voucherinput) → [`Voucher`](#schema-voucher)<br>[`PostRequest`](#schema-postrequest) → [`Voucher`](#schema-voucher) | 200, 201 | `allocation_exceeds_balance`, `already_posted`, `backdated_confirmation_required`, `duplicate`, `insufficient_funds`, `mapping_missing`, `period_locked`, `unbalanced_entry` |

<a id="actions-h13"></a>
#### H13 · Voucher details

View permission `accounting.view`. **Loads:** [`GET /approvals/{id}`](#getapprovalsid), [`GET /audit-logs`](#getauditlogs), [`GET /vouchers/{id}`](#getvouchersid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Reverse (dialog) | dlg:Acc-DlgReverse | — | — | — | — |
| 2 | Duplicate | nav:H9 (prefilled from [`GET /vouchers/{id}`](#getvouchersid)) | `accounting.view` | — | 200 | — |
| 3 | Print | [`GET /vouchers/{id}/pdf`](#getvouchersidpdf) → ui:print | `accounting.view` | — | 200 | — |
| 4 | Approve / Reject (when pending) | [`POST /approvals/{id}/approve`](#postapprovalsidapprove) \| [`POST /approvals/{id}/reject`](#postapprovalsidreject) | contextual — any → `the request's required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)` | [`ApprovalDecision`](#schema-approvaldecision) → [`ApprovalRequest`](#schema-approvalrequest) | 200 | `comment_required`, `not_draft`, `period_locked`, `self_approval_forbidden`, `unbalanced_entry` |
| 5 | Delete (drafts only, GAP-07) | dlg:L7 → [`DELETE /vouchers/{id}`](#deletevouchersid) | `accounting.delete` | — → — | 204 | `already_reversed`, `not_draft`, `use_document_cancel` |

<a id="actions-acc-dlgreverse"></a>
#### Acc-DlgReverse · Dialog · Reverse voucher

View permission `accounting.create`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:close the dialog | — | — | — | — |
| 2 | Create reversing entry | [`POST /vouchers/{id}/reverse`](#postvouchersidreverse) | `accounting.create` | [`ReverseRequest`](#schema-reverserequest) → [`Voucher`](#schema-voucher) | 201 | `already_posted`, `already_reversed`, `period_locked`, `use_document_cancel` |

<a id="actions-h14"></a>
#### H14 · Printable voucher

View permission `accounting.view`. **Loads:** [`GET /vouchers/{id}`](#getvouchersid), [`GET /vouchers/{id}/pdf`](#getvouchersidpdf).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Back | nav:* | — | — | — | — |
| 2 | PDF | [`GET /vouchers/{id}/pdf`](#getvouchersidpdf) | `accounting.view` | — | 200 | — |
| 3 | Print | [`GET /vouchers/{id}/pdf`](#getvouchersidpdf) → ui:print | `accounting.view` | — | 200 | — |

<a id="actions-k16"></a>
#### K16 · Accounting defaults (NEW: GAP-01)

View permission `settings.edit`. **Loads:** [`GET /account-mappings`](#getaccountmappings), [`GET /account-groups`](#getaccountgroups), [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Save changes | [`PUT /account-mappings`](#putaccountmappings) | `settings.edit` | [`AccountMappings`](#schema-accountmappings) → [`AccountMappings`](#schema-accountmappings) | 200 | `mapping_missing` |

### Reports


<a id="actions-h15"></a>
#### H15 · Trial balance

View permission `reports.view`. **Loads:** [`GET /reports/trial-balance`](#getreportstrialbalance).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export (xlsx, pdf, csv) | export:[`GET /reports/trial-balance`](#getreportstrialbalance) | `reports.view` | — | 200 | `unbalanced_entry` |
| 3 | Drill into ledger | nav:H7 | — | — | — | — |

<a id="actions-h16"></a>
#### H16 · Profit and loss

View permission `reports.view`. **Loads:** [`GET /reports/profit-and-loss`](#getreportsprofitandloss).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export (xlsx, pdf, csv) | export:[`GET /reports/profit-and-loss`](#getreportsprofitandloss) | `reports.view` | — | 200 | `unbalanced_entry` |
| 3 | Drill into ledger | nav:H7 | — | — | — | — |

<a id="actions-h17"></a>
#### H17 · Balance sheet

View permission `reports.view`. **Loads:** [`GET /reports/balance-sheet`](#getreportsbalancesheet).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export (xlsx, pdf, csv) | export:[`GET /reports/balance-sheet`](#getreportsbalancesheet) | `reports.view` | — | 200 | `unbalanced_entry` |
| 3 | Drill into ledger | nav:H7 | — | — | — | — |

<a id="actions-h18"></a>
#### H18 · Cash flow statement

View permission `reports.view`. **Loads:** [`GET /reports/cash-flow`](#getreportscashflow).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export (xlsx, pdf, csv) | export:[`GET /reports/cash-flow`](#getreportscashflow) | `reports.view` | — | 200 | `unbalanced_entry` |
| 3 | Drill into ledger | nav:H7 | — | — | — | — |

<a id="actions-h19"></a>
#### H19 · General ledger

View permission `reports.view`. **Loads:** [`GET /reports/general-ledger`](#getreportsgeneralledger).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 2 | Export (xlsx, pdf, csv) | export:[`GET /reports/general-ledger`](#getreportsgeneralledger) | `reports.view` | — | 200 | `unbalanced_entry` |
| 3 | Drill into ledger | nav:H7 | — | — | — | — |

<a id="actions-j1"></a>
#### J1 · Reports centre

View permission `reports.view`. **Loads:** [`GET /export-jobs/{id}`](#getexportjobsid), [`GET /reports/inventory/valuation`](#getreportsinventoryvaluation), [`GET /reports/trial-balance`](#getreportstrialbalance), [`GET /reports/profit-and-loss`](#getreportsprofitandloss), [`GET /reports/balance-sheet`](#getreportsbalancesheet), [`GET /reports/cash-flow`](#getreportscashflow), [`GET /reports/general-ledger`](#getreportsgeneralledger), [`GET /reports/sales`](#getreportssales), [`GET /reports/purchases`](#getreportspurchases), [`GET /reports/income`](#getreportsincome), [`GET /reports/expenses`](#getreportsexpenses).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open report | nav:* | — | — | — | — |

<a id="actions-j6"></a>
#### J6 · Receivables aging

View permission `reports.view`. **Loads:** [`GET /parties/{id}/statement`](#getpartiesidstatement), [`GET /reports/receivables-aging`](#getreportsreceivablesaging).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Statement (row) | nav:B8 | — | — | — | — |
| 2 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 3 | Export | export:[`GET /reports/receivables-aging`](#getreportsreceivablesaging) | `reports.view` | — | 200 | `unbalanced_entry` |

<a id="actions-j7"></a>
#### J7 · Payables aging

View permission `reports.view`. **Loads:** [`GET /parties/{id}/statement`](#getpartiesidstatement), [`GET /reports/payables-aging`](#getreportspayablesaging).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Statement (row) | nav:C8 | — | — | — | — |
| 2 | Print | ui:browser print of the loaded view (print stylesheet) | — | — | — | — |
| 3 | Export | export:[`GET /reports/payables-aging`](#getreportspayablesaging) | `reports.view` | — | 200 | `unbalanced_entry` |

### Settings


<a id="actions-k1"></a>
#### K1 · Company profile

View permission `settings.view`. **Loads:** [`GET /company`](#getcompany), [`GET /branches`](#getbranches).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Edit company | nav:K2 | — | — | — | — |
| 2 | Manage locations (NEW, GAP-14) | [`POST /branches`](#postbranches) \| [`PATCH /branches/{id}`](#patchbranchesid) | `settings.edit` | [`BranchInput`](#schema-branchinput) → [`Branch`](#schema-branch) | 200, 201 | — |

<a id="actions-k2"></a>
#### K2 · Edit company

View permission `settings.edit`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Replace logo | [`PUT /company/logo`](#putcompanylogo) | `settings.edit` | [`FileUpload`](#schema-fileupload) → [`Company`](#schema-company) | 200 | `file_too_large`, `file_type_not_allowed` |
| 2 | Remove logo | dlg:L7 → [`DELETE /company/logo`](#deletecompanylogo) | `settings.edit` | — → — | 204 | — |
| 3 | Discard | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 4 | Save changes | [`PATCH /company`](#patchcompany) | `settings.edit` | [`CompanyUpdate`](#schema-companyupdate) → [`Company`](#schema-company) | 200 | — |

<a id="actions-k3"></a>
#### K3 · Business preferences

View permission `settings.edit`. **Loads:** [`GET /company/settings`](#getcompanysettings), [`GET /payment-terms`](#getpaymentterms), [`GET /number-sequences`](#getnumbersequences).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Discard | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save changes | [`PATCH /company/settings`](#patchcompanysettings) → [`PATCH /number-sequences/{id}`](#patchnumbersequencesid) (each changed series) | `settings.edit` | [`CompanySettingsUpdate`](#schema-companysettingsupdate) → [`CompanySettings`](#schema-companysettings)<br>[`NumberSequenceUpdate`](#schema-numbersequenceupdate) → [`NumberSequence`](#schema-numbersequence) | 200 | `base_currency_locked`, `duplicate` |

<a id="actions-k4"></a>
#### K4 · Financial-year settings

View permission `settings.view`. **Loads:** [`GET /fiscal-years`](#getfiscalyears).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Lock period | dlg:L6 → [`POST /accounting-periods/{id}/lock`](#postaccountingperiodsidlock) | `accounting.lock_period` | [`PeriodLockRequest`](#schema-periodlockrequest) → [`AccountingPeriod`](#schema-accountingperiod) | 200 | `lock_out_of_order` |
| 2 | Unlock period (reason) | dlg:L6 → [`POST /accounting-periods/{id}/unlock`](#postaccountingperiodsidunlock) | `accounting.lock_period` | [`ReasonRequest`](#schema-reasonrequest) → [`AccountingPeriod`](#schema-accountingperiod) | 200 | `lock_out_of_order` |
| 3 | Close FY (after year end) | dlg:L6 → [`POST /fiscal-years/{id}/close`](#postfiscalyearsidclose) | `accounting.close_year` | — → [`FiscalYear`](#schema-fiscalyear) | 200 | `fiscal_year_not_closable`, `unbalanced_entry` |

<a id="actions-k5"></a>
#### K5 · Currency and region

View permission `settings.edit`. **Loads:** [`GET /company/settings`](#getcompanysettings).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Discard | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save changes | [`PATCH /company/settings`](#patchcompanysettings) | `settings.edit` | [`CompanySettingsUpdate`](#schema-companysettingsupdate) → [`CompanySettings`](#schema-companysettings) | 200 | `base_currency_locked` |

<a id="actions-k6"></a>
#### K6 · Tax configuration

View permission `settings.view`. **Loads:** [`GET /company/settings`](#getcompanysettings), [`GET /tax-rules`](#gettaxrules), [`GET /ledgers`](#getledgers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add tax rule | [`POST /tax-rules`](#posttaxrules) | `settings.edit` | [`TaxRuleInput`](#schema-taxruleinput) → [`TaxRule`](#schema-taxrule) | 201 | — |
| 2 | Edit / deactivate rule | [`PATCH /tax-rules/{id}`](#patchtaxrulesid) | `settings.edit` | [`TaxRuleInput`](#schema-taxruleinput) → [`TaxRule`](#schema-taxrule) | 200 | — |
| 3 | Save | [`PATCH /company/settings`](#patchcompanysettings) | `settings.edit` | [`CompanySettingsUpdate`](#schema-companysettingsupdate) → [`CompanySettings`](#schema-companysettings) | 200 | `base_currency_locked` |

<a id="actions-k7"></a>
#### K7 · Invoice customization

View permission `settings.edit`. **Loads:** [`GET /company/settings`](#getcompanysettings).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Discard | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save changes | [`PATCH /company/settings`](#patchcompanysettings) | `settings.edit` | [`CompanySettingsUpdate`](#schema-companysettingsupdate) → [`CompanySettings`](#schema-companysettings) | 200 | `base_currency_locked` |

<a id="actions-k8"></a>
#### K8 · User list

View permission `settings.users`. **Loads:** [`GET /users`](#getusers).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Invite user | dlg:K9 | — | — | — | — |
| 2 | Open user | nav:K10 | — | — | — | — |

<a id="actions-k9"></a>
#### K9 · Invite user

View permission `settings.users`. **Loads:** [`GET /roles`](#getroles).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:close the dialog | — | — | — | — |
| 2 | Send invite | [`POST /invitations`](#postinvitations) | `settings.users` | [`InvitationCreate`](#schema-invitationcreate) → [`Invitation`](#schema-invitation) | 201 | `seat_limit_reached` |

<a id="actions-k10"></a>
#### K10 · User details

View permission `settings.users`. **Loads:** [`GET /users/{id}`](#getusersid), [`GET /audit-logs`](#getauditlogs).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Edit role | nav:K11 | — | — | — | — |
| 2 | Change role | [`PATCH /users/{id}`](#patchusersid) | `settings.users` | [`MemberUpdate`](#schema-memberupdate) → [`Member`](#schema-member) | 200 | `branch_restricted`, `last_owner`, `role_in_use`, `system_role` |
| 3 | Deactivate (dialog) | dlg:L6 → [`POST /users/{id}/deactivate`](#postusersiddeactivate) | `settings.users` | — → [`Member`](#schema-member) | 200 | `last_owner`, `role_in_use`, `system_role` |
| 4 | Reactivate | [`POST /users/{id}/reactivate`](#postusersidreactivate) | `settings.users` | — → [`Member`](#schema-member) | 200 | `seat_limit_reached` |
| 5 | Resend invite | [`POST /invitations/{id}/resend`](#postinvitationsidresend) | `settings.users` | — → [`Invitation`](#schema-invitation) | 200 | — |
| 6 | Revoke invitation (NEW) | dlg:L6 → [`DELETE /invitations/{id}`](#deleteinvitationsid) | `settings.users` | — → — | 204 | — |

<a id="actions-k11"></a>
#### K11 · Roles and permissions

View permission `settings.roles`. **Loads:** [`GET /roles`](#getroles), [`GET /roles/{id}`](#getrolesid), [`GET /permissions`](#getpermissions).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New role | [`POST /roles`](#postroles) | `settings.roles` | [`RoleInput`](#schema-roleinput) → [`Role`](#schema-role) | 201 | — |
| 2 | Duplicate role | [`POST /roles`](#postroles) (copy_from_role_id) | `settings.roles` | [`RoleInput`](#schema-roleinput) → [`Role`](#schema-role) | 201 | — |
| 3 | Discard | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 4 | Save changes | [`PUT /roles/{id}`](#putrolesid) (each changed role) | `settings.roles` | [`RoleInput`](#schema-roleinput) → [`Role`](#schema-role) | 200 | `last_owner`, `role_in_use`, `system_role` |
| 5 | Delete custom role (NEW, only without members) | dlg:L7 → [`DELETE /roles/{id}`](#deleterolesid) | `settings.roles` | — → — | 204 | — |

<a id="actions-k12"></a>
#### K12 · Notification preferences

View permission `auth.self`. **Loads:** [`GET /me/notification-preferences`](#getmenotificationpreferences).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Save changes | [`PUT /me/notification-preferences`](#putmenotificationpreferences) | any signed-in user who is an active member of the company | [`NotificationPreferences`](#schema-notificationpreferences) → [`NotificationPreferences`](#schema-notificationpreferences) | 200 | — |

<a id="actions-k13"></a>
#### K13 · Appearance

View permission `auth.self`. **Loads:** [`GET /me/preferences`](#getmepreferences).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Save changes | [`PUT /me/preferences`](#putmepreferences) | any signed-in user | [`UserPreferences`](#schema-userpreferences) → [`UserPreferences`](#schema-userpreferences) | 200 | — |

<a id="actions-k14"></a>
#### K14 · Security

View permission `auth.self`. **Loads:** [`GET /me/sessions`](#getmesessions), [`GET /company/settings`](#getcompanysettings).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Change password | [`PUT /me/password`](#putmepassword) | any signed-in user | [`ChangePasswordRequest`](#schema-changepasswordrequest) → — | 204 | — |
| 2 | Enable/disable 2-step | [`POST /me/two-factor`](#postmetwofactor) → [`POST /me/two-factor/confirm`](#postmetwofactorconfirm) \| [`DELETE /me/two-factor`](#deletemetwofactor) | any signed-in user | — → [`TwoFactorSetup`](#schema-twofactorsetup)<br>[`TwoFactorConfirm`](#schema-twofactorconfirm) → [`RecoveryCodes`](#schema-recoverycodes)<br>[`PasswordConfirm`](#schema-passwordconfirm) → — | 200, 201, 204 | `invalid_credentials`, `invalid_transition`, `two_factor_failed`, `two_factor_required_by_company` |
| 3 | Sign out (session) | [`DELETE /me/sessions/{id}`](#deletemesessionsid) | any signed-in user | — → — | 204 | — |
| 4 | Sign out everywhere else | [`POST /me/sessions/revoke-others`](#postmesessionsrevokeothers) | any signed-in user | — → — | 204 | — |

<a id="actions-k15"></a>
#### K15 · Backup and export

View permission `settings.backup`. **Loads:** [`GET /company/settings`](#getcompanysettings), [`GET /backups`](#getbackups), [`GET /backups/{id}/download`](#getbackupsiddownload), [`GET /export-jobs/{id}`](#getexportjobsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Prepare export pack | [`POST /export-jobs`](#postexportjobs) → [`GET /export-jobs/{id}`](#getexportjobsid) | `reports.export` | [`ExportJobCreate`](#schema-exportjobcreate) → [`ExportJob`](#schema-exportjob) | 200, 202 | — |
| 2 | Download backup | [`GET /backups/{id}/download`](#getbackupsiddownload) | `settings.restore` | — | 200 | — |
| 3 | Back up now | [`POST /backups`](#postbackups) | `settings.backup` | — → [`Backup`](#schema-backup) | 202 | — |
| 4 | Restore… (dialog) | dlg:L6 → [`POST /backups/{id}/restore`](#postbackupsidrestore) | `settings.restore` | [`RestoreRequest`](#schema-restorerequest) → [`Backup`](#schema-backup) | 202 | `restore_confirmation_required` |

### States


<a id="actions-l1"></a>
#### L1 · Global search results

View permission `auth.self`. **Loads:** [`GET /search`](#getsearch).

_No actions of its own._

<a id="actions-l2"></a>
#### L2 · Command palette

View permission `auth.self`. **Loads:** [`GET /search`](#getsearch).

_No actions of its own._

<a id="actions-l3"></a>
#### L3 · Notifications center

View permission `auth.self`. **Loads:** [`GET /notifications`](#getnotifications), [`GET /approvals`](#getapprovals).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open notification (marks it read) | [`POST /notifications/{id}/read`](#postnotificationsidread) → nav:* | any signed-in user who is an active member of the company | — → — | 204 | — |
| 2 | Mark all read | [`POST /notifications/read-all`](#postnotificationsreadall) | any signed-in user who is an active member of the company | — → — | 204 | — |

<a id="actions-l4"></a>
#### L4 · Notification detail drawer

View permission `accounting.approve`. **Loads:** [`GET /approvals`](#getapprovals), [`GET /approvals/{id}`](#getapprovalsid).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Approve | [`POST /approvals/{id}/approve`](#postapprovalsidapprove) | contextual — any → `the request's required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)` | [`ApprovalDecision`](#schema-approvaldecision) → [`ApprovalRequest`](#schema-approvalrequest) | 200 | `not_draft`, `period_locked`, `self_approval_forbidden`, `unbalanced_entry` |
| 2 | Reject (comment required) | [`POST /approvals/{id}/reject`](#postapprovalsidreject) | contextual — any → `the request's required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)` | [`ApprovalDecision`](#schema-approvaldecision) → [`ApprovalRequest`](#schema-approvalrequest) | 200 | `comment_required`, `self_approval_forbidden` |

<a id="actions-l5"></a>
#### L5 · Quick-create menu

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l6"></a>
#### L6 · Confirmation dialog

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l7"></a>
#### L7 · Delete confirmation

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l8"></a>
#### L8 · Validation errors

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l9"></a>
#### L9 · Empty states

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l10"></a>
#### L10 · Loading states

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l11"></a>
#### L11 · Error states

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l12"></a>
#### L12 · Success and other toasts

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l13"></a>
#### L13 · Unsaved changes

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l14"></a>
#### L14 · Access denied

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

<a id="actions-l15"></a>
#### L15 · Not found

View permission `auth.self`. **Loads:** nothing of its own (host page data).

_No actions of its own._

### LC


<a id="actions-m1"></a>
#### M1 · LC dashboard

View permission `lc.view`. **Loads:** [`GET /lc/overview`](#getlcoverview).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New LC | nav:M3 | — | — | — | — |
| 2 | Open alert | nav:M4 | — | — | — | — |
| 3 | Open LC | nav:M4 | — | — | — | — |
| 4 | All LCs | nav:M2 | — | — | — | — |

<a id="actions-m2"></a>
#### M2 · LC list

View permission `lc.view`. **Loads:** [`GET /letters-of-credit`](#getlettersofcredit).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | New LC | nav:M3 | — | — | — | — |
| 2 | Open LC | nav:M4 | — | — | — | — |

<a id="actions-m3"></a>
#### M3 · Create LC

View permission `lc.create`. **Loads:** [`GET /parties`](#getparties), [`GET /purchase-orders`](#getpurchaseorders), [`GET /money-accounts`](#getmoneyaccounts), [`GET /lc-document-templates`](#getlcdocumenttemplates).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:discard changes (L13 unsaved-changes dialog when dirty) | — | — | — | — |
| 2 | Save draft | [`POST /letters-of-credit`](#postlettersofcredit) \| [`PUT /letters-of-credit/{id}`](#putlettersofcreditid) | `lc.create`, `lc.edit` | [`LetterOfCreditInput`](#schema-letterofcreditinput) → [`LetterOfCredit`](#schema-letterofcredit) | 200, 201 | `duplicate`, `invalid_transition` |
| 3 | Next section | ui:validate this section on the client and scroll to the next | — | — | — | — |
| 4 | Submit to bank | [`POST /letters-of-credit`](#postlettersofcredit) \| [`PUT /letters-of-credit/{id}`](#putlettersofcreditid) → [`POST /letters-of-credit/{id}/transition`](#postlettersofcreditidtransition) (action=submit) | `lc.create`, `lc.edit` | [`LetterOfCreditInput`](#schema-letterofcreditinput) → [`LetterOfCredit`](#schema-letterofcredit)<br>[`LcTransition`](#schema-lctransition) → [`LetterOfCredit`](#schema-letterofcredit) | 200, 201 | `duplicate`, `invalid_transition`, `lc_submit_incomplete` |
| 5 | Add document | [`POST /letters-of-credit/{id}/documents`](#postlettersofcreditiddocuments) | `lc.edit` | [`LcDocumentInput`](#schema-lcdocumentinput) → [`LcDocument`](#schema-lcdocument) | 201 | — |
| 6 | Change template | [`GET /lc-document-templates`](#getlcdocumenttemplates) → [`PUT /letters-of-credit/{id}`](#putlettersofcreditid) (document_template_id) | `lc.edit`, `lc.view` | [`LetterOfCreditInput`](#schema-letterofcreditinput) → [`LetterOfCredit`](#schema-letterofcredit) | 200 | `invalid_transition` |
| 7 | Delete draft (NEW) | dlg:L7 → [`DELETE /letters-of-credit/{id}`](#deletelettersofcreditid) | `lc.delete` | — → — | 204 | `not_draft` |

<a id="actions-m4"></a>
#### M4 · LC details

View permission `lc.view`. **Loads:** [`GET /attachments/{id}/url`](#getattachmentsidurl), [`GET /audit-logs`](#getauditlogs), [`GET /letters-of-credit/{id}`](#getlettersofcreditid), [`GET /letters-of-credit/{id}/documents`](#getlettersofcreditiddocuments), [`GET /letters-of-credit/{id}/payments`](#getlettersofcreditidpayments), [`GET /letters-of-credit/{id}/pdf`](#getlettersofcreditidpdf), [`GET /letters-of-credit/{id}/cost-summary`](#getlettersofcreditidcostsummary).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Record payment (dialog) | dlg:LC-DlgPay | — | — | — | — |
| 2 | Add charge (drawer) | dlg:LC-DrawerCharge | — | — | — | — |
| 3 | Record amendment | [`POST /letters-of-credit/{id}/amendments`](#postlettersofcreditidamendments) | `lc.edit` | [`LcAmendmentInput`](#schema-lcamendmentinput) → [`LcAmendment`](#schema-lcamendment) | 201 | — |
| 4 | Attach scan | [`POST /attachments`](#postattachments) | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` | [`AttachmentUpload`](#schema-attachmentupload) → [`Attachment`](#schema-attachment) | 201 | `file_too_large`, `file_type_not_allowed` |
| 5 | Add document | [`POST /letters-of-credit/{id}/documents`](#postlettersofcreditiddocuments) | `lc.edit` | [`LcDocumentInput`](#schema-lcdocumentinput) → [`LcDocument`](#schema-lcdocument) | 201 | — |
| 6 | Update document status | [`PATCH /lc-documents/{id}`](#patchlcdocumentsid) | `lc.edit` | [`LcDocumentInput`](#schema-lcdocumentinput) → [`LcDocument`](#schema-lcdocument) | 200 | — |
| 7 | Add/update shipment lot | [`POST /letters-of-credit/{id}/shipments`](#postlettersofcreditidshipments) \| [`PATCH /lc-shipments/{id}`](#patchlcshipmentsid) | `lc.edit` | [`LcShipmentInput`](#schema-lcshipmentinput) → [`LcShipment`](#schema-lcshipment) | 200, 201 | — |
| 8 | Receive goods (opens F3 in import mode) | nav:F3 (import mode, LC and lot prefilled) | — | — | — | — |
| 9 | Allocate landed cost | [`POST /letters-of-credit/{id}/landed-cost/preview`](#postlettersofcreditidlandedcostpreview) → [`POST /letters-of-credit/{id}/landed-cost`](#postlettersofcreditidlandedcost) | `lc.create`, `lc.view` | [`LandedCostRequest`](#schema-landedcostrequest) → [`LandedCostAllocation`](#schema-landedcostallocation) | 200, 201 | `already_posted`, `charge_allocated`, `period_locked`, `unbalanced_entry` |
| 10 | Open cost summary | [`GET /letters-of-credit/{id}/cost-summary`](#getlettersofcreditidcostsummary) | `lc.view` | — | 200 | — |
| 11 | Close LC (dialog) | dlg:LC-DlgClose | — | — | — | — |
| 12 | Print LC summary | [`GET /letters-of-credit/{id}/pdf`](#getlettersofcreditidpdf) → ui:print | `lc.view` | — | 200 | — |
| 13 | Reverse payment, charge or landed-cost allocation (NEW, R-03) | dlg:L6 → [`POST /lc-payments/{id}/reverse`](#postlcpaymentsidreverse) \| dlg:L6 → [`POST /lc-charges/{id}/reverse`](#postlcchargesidreverse) \| dlg:L6 → [`POST /landed-cost-allocations/{id}/reverse`](#postlandedcostallocationsidreverse) | `lc.edit` | [`ReasonRequest`](#schema-reasonrequest) → [`LcPayment`](#schema-lcpayment)<br>[`ReasonRequest`](#schema-reasonrequest) → [`LcCharge`](#schema-lccharge)<br>[`ReasonRequest`](#schema-reasonrequest) → [`LandedCostAllocation`](#schema-landedcostallocation) | 200 | `already_posted`, `already_reversed`, `charge_allocated`, `invalid_transition`, `period_locked`, `use_document_cancel` |
| 14 | Pay a charge (NEW, row) | [`POST /lc-charges/{id}/pay`](#postlcchargesidpay) | `lc.create` | [`ChargePayment`](#schema-chargepayment) → [`LcCharge`](#schema-lccharge) | 200 | `insufficient_funds`, `period_locked`, `unbalanced_entry` |
| 15 | Remove attachment (NEW) | [`DELETE /attachments/{id}`](#deleteattachmentsid) | contextual — any → `<module>.edit of the record the file belongs to (create for a draft)` | — → — | 204 | — |

<a id="actions-m5"></a>
#### M5 · LC amendments

View permission `lc.view`. **Loads:** [`GET /letters-of-credit`](#getlettersofcredit), [`GET /lc-amendments`](#getlcamendments).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Clear | ui:clear the filters | — | — | — | — |
| 2 | Record amendment | [`POST /letters-of-credit/{id}/amendments`](#postlettersofcreditidamendments) \| [`PATCH /lc-amendments/{id}`](#patchlcamendmentsid) | `lc.edit` | [`LcAmendmentInput`](#schema-lcamendmentinput) → [`LcAmendment`](#schema-lcamendment)<br>[`LcAmendmentStatus`](#schema-lcamendmentstatus) → [`LcAmendment`](#schema-lcamendment) | 200, 201 | — |
| 3 | Open LC | nav:M4 | — | — | — | — |

<a id="actions-m6"></a>
#### M6 · LC documents

View permission `lc.view`. **Loads:** [`GET /lc-documents/matrix`](#getlcdocumentsmatrix), [`GET /lc-document-templates`](#getlcdocumenttemplates).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Open LC documents | nav:M4 | — | — | — | — |
| 2 | Edit templates (NEW editor, GAP-14) | [`POST /lc-document-templates`](#postlcdocumenttemplates) \| [`PUT /lc-document-templates/{id}`](#putlcdocumenttemplatesid) | `lc.create`, `lc.edit` | [`LcDocumentTemplateInput`](#schema-lcdocumenttemplateinput) → [`LcDocumentTemplate`](#schema-lcdocumenttemplate) | 200, 201 | — |

<a id="actions-m7"></a>
#### M7 · Charges and cost summary

View permission `lc.view`. **Loads:** [`GET /letters-of-credit`](#getlettersofcredit), [`GET /lc-charges`](#getlccharges), [`GET /letters-of-credit/{id}/cost-summary`](#getlettersofcreditidcostsummary).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Add charge | dlg:LC-DrawerCharge | — | — | — | — |
| 2 | Choose LC | [`GET /letters-of-credit`](#getlettersofcredit) → [`GET /letters-of-credit/{id}/cost-summary`](#getlettersofcreditidcostsummary) | `lc.view` | — | 200 | — |
| 3 | Allocate landed cost | [`POST /letters-of-credit/{id}/landed-cost/preview`](#postlettersofcreditidlandedcostpreview) → [`POST /letters-of-credit/{id}/landed-cost`](#postlettersofcreditidlandedcost) | `lc.create`, `lc.view` | [`LandedCostRequest`](#schema-landedcostrequest) → [`LandedCostAllocation`](#schema-landedcostallocation) | 200, 201 | `already_posted`, `charge_allocated`, `period_locked`, `unbalanced_entry` |
| 4 | Pay a charge (NEW, row) | [`POST /lc-charges/{id}/pay`](#postlcchargesidpay) | `lc.create` | [`ChargePayment`](#schema-chargepayment) → [`LcCharge`](#schema-lccharge) | 200 | `insufficient_funds`, `period_locked`, `unbalanced_entry` |

<a id="actions-m8"></a>
#### M8 · LC reports

View permission `lc.view`. **Loads:** [`GET /lc-amendments`](#getlcamendments), [`GET /reports/lc/{report}`](#getreportslcreport).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Export (xlsx, pdf) | export:[`GET /reports/lc/{report}`](#getreportslcreport) | `lc.view` | — | 200 | — |
| 2 | Open LC | nav:M4 | — | — | — | — |

<a id="actions-lc-dlgpay"></a>
#### LC-DlgPay · Dialog · Record LC payment

View permission `lc.create`. **Loads:** [`GET /money-accounts`](#getmoneyaccounts).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:close the dialog | — | — | — | — |
| 2 | Record payment | [`POST /letters-of-credit/{id}/payments`](#postlettersofcreditidpayments) | `lc.create` | [`LcPaymentInput`](#schema-lcpaymentinput) → [`LcPayment`](#schema-lcpayment) | 201 | `exceeds_tolerance`, `insufficient_funds`, `margin_exceeds_deposit`, `period_locked`, `unbalanced_entry` |

<a id="actions-lc-dlgclose"></a>
#### LC-DlgClose · Dialog · Close LC

View permission `lc.edit`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:close the dialog | — | — | — | — |
| 2 | Close LC | [`POST /letters-of-credit/{id}/close`](#postlettersofcreditidclose) | `lc.edit` | [`LcCloseRequest`](#schema-lccloserequest) → [`LetterOfCredit`](#schema-letterofcredit) | 200 | `lc_not_closable`, `margin_exceeds_deposit`, `unbalanced_entry` |

<a id="actions-lc-drawercharge"></a>
#### LC-DrawerCharge · Drawer · Add charge or import expense

View permission `lc.create`. **Loads:** nothing of its own (host page data).

| # | Action | Calls | Permission | Body → data | Success | Business errors to handle |
|---|---|---|---|---|---|---|
| 1 | Cancel | ui:close the drawer | — | — | — | — |
| 2 | Add charge | [`POST /lc-charges`](#postlccharges) | `lc.create` | [`LcChargeInput`](#schema-lcchargeinput) → [`LcCharge`](#schema-lccharge) | 201 | `charge_allocated`, `insufficient_funds`, `margin_exceeds_deposit`, `period_locked`, `unbalanced_entry` |

- J2 · Sales report: same as [E11](#actions-e11) (reused screen, Reports breadcrumb)

- J3 · Purchase report: same as [F9](#actions-f9) (reused screen, Reports breadcrumb)

- J4 · Income report: same as [I8](#actions-i8) (reused screen, Reports breadcrumb)

- J5 · Expense report: same as [I9](#actions-i9) (reused screen, Reports breadcrumb)

- J8 · Cash flow report: same as [H18](#actions-h18) (reused screen, Reports breadcrumb)

- J9 · Cash book: same as [G11](#actions-g11) (reused screen, Reports breadcrumb)

- J10 · Bank book: same as [G12](#actions-g12) (reused screen, Reports breadcrumb)

- J11 · Inventory report: same as [D14](#actions-d14) (reused screen, Reports breadcrumb)

- J12 · Stock valuation: same as [D13](#actions-d13) (reused screen, Reports breadcrumb)

- J13 · Trial balance: same as [H15](#actions-h15) (reused screen, Reports breadcrumb)

- J14 · Profit and loss: same as [H16](#actions-h16) (reused screen, Reports breadcrumb)

- J15 · Balance sheet: same as [H17](#actions-h17) (reused screen, Reports breadcrumb)

- J16 · General ledger: same as [H19](#actions-h19) (reused screen, Reports breadcrumb)

- J17 · Customer statement: same as [B8](#actions-b8) (reused screen, Reports breadcrumb)

- J18 · Supplier statement: same as [C8](#actions-c8) (reused screen, Reports breadcrumb)

## 12. Contract review (18 Oct 2026): missing, redundant and changed endpoints

### 12.1 Size

| | Before review | Contract |
|---|---|---|
| Endpoints | 280 | 309 |
| Schemas | 191 | 197 |
| Pages | 155 | 162 |
| Page actions mapped to API or client behaviour | 0 of 448 | 522 of 522 |

### 12.2 Missing endpoints, now added (35)

| Endpoint | Ref | Why it was missing |
|---|---|---|
| [`GET /approvals/{id}`](#getapprovalsid) | C-03 | The approval drawer (L4) shows one request with its facts; only a list existed |
| [`POST /parties/bulk-deactivate`](#postpartiesbulkdeactivate) | C-04 | B1/C1 'Mark inactive (bulk)' had no endpoint |
| [`GET /parties/{id}/statement/pdf`](#getpartiesidstatementpdf) | C-01 | Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary) |
| [`POST /products/import`](#postproductsimport) | C-05 | D1 'Import' had no endpoint |
| [`GET /customer-receipts`](#getcustomerreceipts) | R-07 | Sidebar list screens had no list endpoint; sales users could not see receipts without accounting.view (13 R-07) |
| [`GET /customer-receipts/{id}`](#getcustomerreceiptsid) | R-07 | Sidebar list screens had no list endpoint; sales users could not see receipts without accounting.view (13 R-07) |
| [`GET /customer-receipts/{id}/pdf`](#getcustomerreceiptsidpdf) | C-01 | Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary) |
| [`POST /allocations`](#postallocations) | R-08 | Advances and open credits could never be applied later, and a wrong allocation could not be undone (13 R-08) |
| [`DELETE /allocations/{id}`](#deleteallocationsid) | R-08 | Advances and open credits could never be applied later, and a wrong allocation could not be undone (13 R-08) |
| [`DELETE /quotations/{id}`](#deletequotationsid) | C-02 | BR-025 lets drafts be deleted, but only invoices, bills, vouchers and stock documents had a delete endpoint |
| [`GET /quotations/{id}/pdf`](#getquotationsidpdf) | C-01 | Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary) |
| [`DELETE /sales-returns/{id}`](#deletesalesreturnsid) | C-02 | BR-025 lets drafts be deleted, but only invoices, bills, vouchers and stock documents had a delete endpoint |
| [`POST /sales-returns/{id}/cancel`](#postsalesreturnsidcancel) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`GET /credit-notes`](#getcreditnotes) | R-07 | Sidebar list screens had no list endpoint; sales users could not see receipts without accounting.view (13 R-07) |
| [`DELETE /credit-notes/{id}`](#deletecreditnotesid) | C-02 | BR-025 lets drafts be deleted, but only invoices, bills, vouchers and stock documents had a delete endpoint |
| [`POST /credit-notes/{id}/cancel`](#postcreditnotesidcancel) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`GET /credit-notes/{id}/pdf`](#getcreditnotesidpdf) | C-01 | Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary) |
| [`GET /supplier-payments`](#getsupplierpayments) | R-07 | Sidebar list screens had no list endpoint; sales users could not see receipts without accounting.view (13 R-07) |
| [`GET /supplier-payments/{id}`](#getsupplierpaymentsid) | R-07 | Sidebar list screens had no list endpoint; sales users could not see receipts without accounting.view (13 R-07) |
| [`GET /supplier-payments/{id}/pdf`](#getsupplierpaymentsidpdf) | C-01 | Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary) |
| [`DELETE /purchase-orders/{id}`](#deletepurchaseordersid) | C-02 | BR-025 lets drafts be deleted, but only invoices, bills, vouchers and stock documents had a delete endpoint |
| [`GET /purchase-orders/{id}/pdf`](#getpurchaseordersidpdf) | C-01 | Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary) |
| [`DELETE /purchase-returns/{id}`](#deletepurchasereturnsid) | C-02 | BR-025 lets drafts be deleted, but only invoices, bills, vouchers and stock documents had a delete endpoint |
| [`POST /purchase-returns/{id}/cancel`](#postpurchasereturnsidcancel) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`GET /debit-notes`](#getdebitnotes) | R-07 | Sidebar list screens had no list endpoint; sales users could not see receipts without accounting.view (13 R-07) |
| [`DELETE /debit-notes/{id}`](#deletedebitnotesid) | C-02 | BR-025 lets drafts be deleted, but only invoices, bills, vouchers and stock documents had a delete endpoint |
| [`POST /debit-notes/{id}/cancel`](#postdebitnotesidcancel) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`GET /debit-notes/{id}/pdf`](#getdebitnotesidpdf) | C-01 | Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary) |
| [`POST /cash-counts/{id}/reverse`](#postcashcountsidreverse) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`POST /vouchers/calculate`](#postvoucherscalculate) | R-27 | The voucher forms show a live double-entry and balance; DEC-09 makes the server authoritative (13 R-27) |
| [`POST /lc-charges/{id}/reverse`](#postlcchargesidreverse) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`GET /letters-of-credit/{id}/payments`](#getlettersofcreditidpayments) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`POST /lc-payments/{id}/reverse`](#postlcpaymentsidreverse) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`POST /landed-cost-allocations/{id}/reverse`](#postlandedcostallocationsidreverse) | R-03 | No correction path for a posted return, note, LC payment, LC charge, landed-cost allocation or cash-count adjustment (13 R-03) |
| [`GET /letters-of-credit/{id}/pdf`](#getlettersofcreditidpdf) | C-01 | Documents that are printed or e-mailed need a server PDF (quotation, PO, notes, receipts, payments, statement, LC summary) |

### 12.3 Redundant or unused endpoints, removed (6)

| Endpoint | Reason |
|---|---|
| `GET /products/{id}/movements` | Redundant: identical to `GET /stock-movements?product_id=` (same table, same schema, same permission). |
| `POST /sales-invoices/{id}/duplicate` | Redundant and inconsistent: vouchers are duplicated on the client; duplicating now opens E3 prefilled, so no draft or number is created until the user saves. |
| `PATCH /me` | No designed screen edits the user's own name or phone (K14 has none of these fields). Re-add with the screen. |
| `POST /payment-terms` | No designed screen adds payment terms; the six terms are seeded and chosen in K3, B2 and C2. |
| `PATCH /units/{id}` | Units are seeded and added inline in D2; no screen edits a unit. |
| `DELETE /units/{id}` | As above; no screen deletes a unit. |

**Renamed:**

| Before | After | Reason |
|---|---|---|
| `GET /incexp/overview` | `GET /income-expenses/overview` | Consistent resource naming (no abbreviations) |
| `GET /lc/dashboard` | `GET /lc/overview` | Same `…/overview` pattern as the other modules |

### 12.4 Changed behaviour

| Endpoint(s) | Change |
|---|---|
| POST /auth/refresh, POST /auth/logout | Security is the refresh cookie (`refreshCookie`); logout no longer needs a valid access token (R-36). |
| POST /auth/login | A locked account answers 401 `invalid_credentials` and throttling 429 with Retry-After, instead of 423 (R-12, API part). |
| POST/PUT /vouchers, /vouchers/{id}/post, /submit, /calculate | Contextual permission: journals need accounting.*, cash vouchers banking.* or accounting.* (R-09). |
| POST /approvals/{id}/approve, /reject | Contextual: the request's required_permission, never the submitter (R-09, BR-008). |
| GET /audit-logs, attachments | Contextual: the module permission of the record they belong to (R-09). |
| GET /ledgers | `purpose` parameter; without accounting.view only ledgers for that purpose are returned (R-26). |
| POST /sales-invoices/{id}/post | Optional `receipt` posts a cash sale's receipt in the same transaction (R-28). |
| POST /customer-receipts | No approval step (receipts are outside BR-007); approval_requests removed (R-22). |
| POST /purchase-returns/{id}/post, POST /debit-notes/{id}/post | The return's entry is the debit note's entry; posting a return-based note separately is refused with `created_from_return` (R-04). |
| Stock adjustment, transfer and movement endpoints | BR-052 cost masking declared; value fields omitted without inventory.view_costs (R-24). |
| POST /invitations, accept | Branch restriction stored in `user_invitation_branches` (R-18). |
| Reports with `format` | 200 returns the xlsx/pdf/csv file directly; 202 + ExportJob above 5,000 rows. |
| All PUT/PATCH with If-Match | Missing If-Match is 428 `if_match_required` (RFC 6585) instead of 412; a stale version stays 409 `version_conflict` (BR-049). |
| All request bodies | Strict: unknown properties are rejected (`additionalProperties: false`). |
| Party schema | Adds `port_of_loading` and `default_purchase_ledger_id`, which PartyInput accepts but the response did not return (found by the slice-1 contract test, 19 Oct 2026). Additive. |
| POST /me/two-factor, POST /me/two-factor/confirm | Declare 409 `invalid_transition` (set-up while 2-step is already on, or confirm without a set-up) and 429 `rate_limited` on confirm; found while implementing 2-step in slice 1 (19 Oct 2026). Additive. |
| All company-scoped endpoints | May return 503 `company_maintenance` with Retry-After while that company is restored from a backup (11 §8); previously promised in operations but missing from the contract. |
| GET /backups/{id}/download | Permission `settings.restore` (Owner) instead of `settings.backup`; an Owner-only action on K15, no longer a page load (R-11). |
| Database operations (second pass) | Corrected derived writes: bulk post now posts like the single post; 13 system-entry postings lock number_sequences for the SJ number (R-06); voucher post updates rather than inserts its lines; landed cost updates stock_balances (R-02); allocations create no journal lines; quote/PO convert create the draft document. Rule A23 now enforces these invariants. |
| Dashboard lenses A2–A8 | Load only `GET /dashboard/lenses/{lens}`; the overlapping report and list endpoints were removed from their page lists. |

### 12.5 Overlaps kept on purpose

Each pair returns similar data but serves a different permission audience. Removing either would force a broader permission on a role (07 §6). One query service backs both.

| Endpoint | Overlaps with | Why both exist |
|---|---|---|
| `GET /parties/{id}/ledger` | `GET /ledgers/{id}/entries` | Same lines, different permission (parties.view vs accounting.view); sales users must see a customer ledger without the chart of accounts. |
| `GET /money-accounts/{id}/book` | `GET /ledgers/{id}/entries` | Same reason with banking.view. |
| `GET /parties/outstanding` | `GET /reports/receivables-aging, payables-aging` | Outstanding screens (parties.view, with actions) vs aging reports (reports.view, exportable). One query service serves both. |
| `GET /ie-entries, GET /money-transactions` | `GET /vouchers` | Filtered, permission-scoped views (banking.view) of journal lines; vouchers need accounting.view. |
| `GET /customer-receipts, GET /supplier-payments` | `GET /vouchers?entry_type=` | Same reason with sales.view and purchases.view. |

### 12.6 UI additions that need design sign-off

**New screens** (List template, no new components): E13 Quotation list (NEW), E14 Payments received (NEW), E15 Returns and credit notes (NEW), F11 Purchase orders (NEW), F12 Payments made (NEW), F13 Returns and debit notes (NEW), I10 Recurring entries (NEW).

**Actions added to designed screens** (each one needed by an endpoint that no designed control triggered):

| Page | Action | Kind |
|---|---|---|
| L3 | Open notification (marks it read) | shown in the component's design description, missing from the inventory |
| L3 | Mark all read | shown in the component's design description, missing from the inventory |
| L4 | Approve | shown in the component's design description, missing from the inventory |
| L4 | Reject (comment required) | shown in the component's design description, missing from the inventory |
| B3 | Delete (L7 confirmation, only without history) | shown in the component's design description, missing from the inventory |
| C3 | Delete (L7 confirmation, only without history) | shown in the component's design description, missing from the inventory |
| B4 | Apply advance or credit (NEW, R-08) | NEW |
| B4 | Remove allocation (NEW, R-08) | NEW |
| C4 | Apply advance or credit (NEW, R-08) | NEW |
| C4 | Remove allocation (NEW, R-08) | NEW |
| F4 | Apply advance or credit (NEW, R-08) | NEW |
| F4 | Remove allocation (NEW, R-08) | NEW |
| G2 | Reverse cash-count adjustment (NEW, R-03) | NEW |
| M4 | Reverse payment, charge or landed-cost allocation (NEW, R-03) | NEW |
| M4 | Pay a charge (NEW, row) | NEW |
| M4 | Remove attachment (NEW) | NEW |
| M7 | Pay a charge (NEW, row) | NEW |
| S0 | Create a company (NEW, company switcher) | NEW |
| K10 | Revoke invitation (NEW) | NEW |
| K11 | Delete custom role (NEW, only without members) | NEW |
| I6 | Remove attachment (NEW) | NEW |
| B2 | Add group inline (NEW) | NEW |
| C2 | Add group inline (NEW) | NEW |
| D2 | Add unit inline (NEW) | NEW |
| D3 | Delete product (NEW, only without history) | NEW |
| D6 | Delete stock group (NEW, only when unused) | NEW |
| D10 | Delete draft (NEW) | NEW |
| D10 | Reverse posted adjustment (NEW) | NEW |
| E5 | Apply advance or credit (NEW, R-08) | NEW |
| E5 | Remove allocation (NEW, R-08) | NEW |
| E5 | Edit delivery details (NEW) | NEW |
| E5 | E-mail to customer (NEW) | NEW |
| F3 | Delete draft (NEW) | NEW |
| G5 | Edit account (NEW) | NEW |
| M3 | Delete draft (NEW) | NEW |

### 12.7 Open decisions that affect the contract

| Decision | Effect on the API |
|---|---|
| DEC-02 | Access token in memory + refresh cookie (assumed throughout the contract). If tokens move to a BFF/session cookie, `bearerAuth` changes and CSRF headers are added. |
| DEC-13 / R-25 | Sales staff and Viewer can read the dashboard's cash and P&L widgets and the financial statements. If restricted, add `reports.financial` and mask those widgets; no path changes. |
| R-02 / R-13 | Per-warehouse average cost and cash-credit accounts are reflected in the schema the API reads; reverting either changes response fields, not endpoints. |
| R-24 | Store keeper: confirm that goods are received by purchasing (no purchases.create for the role). |
| Design sign-off | Seven NEW list/recurring screens and the NEW actions listed in 12.6 need design approval before the frontend builds them. |
