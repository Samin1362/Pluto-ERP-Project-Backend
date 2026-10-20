# 05 · API specification

> Status: **Release candidate 1.0.0-rc.1 for approval** (18 Oct 2026), Laravel + Eloquent. **No endpoint is to be implemented until this contract is approved.** ⚑ Auth details (DEC-02) and the items in §12.7 are still open.
>
> **Generated file.** The endpoint reference (§9), schemas (§10), page action map (§11) and contract review (§12) come from `docs/source/api.py`, `schemas.py`, `actions.py`, `errors.py` and `contract_review.py`. The machine-readable contract is [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1), validated against the official OpenAPI 3.1 schema (§8). Size: <!-- GEN:count -->.

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
`POST /auth/login` returns a short-lived **access JWT** (15 minutes) in the body and sets a rotating **refresh token** in the `lf_rt` cookie (HttpOnly, Secure, SameSite=Strict, Path=/api/v1/auth). The SPA sends `Authorization: Bearer <access>` on every call. On a 401 `token_expired` it calls `POST /auth/refresh` once and retries. Full design: [07-Authentication-and-Authorization](07-Authentication-and-Authorization.md).

## 3. Error response format (RFC 9457 Problem Details)

```json
{
  "type": "https://docs.ledgerflow.example/errors/validation_failed",
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

<!-- GEN:errors -->

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
Set-Cookie: lf_rt=8Qm…; Path=/api/v1/auth; HttpOnly; Secure; SameSite=Strict; Max-Age=1209600

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

{"type": "https://docs.ledgerflow.example/errors/insufficient_stock", "title": "Not enough stock", "status": 422, "code": "insufficient_stock",
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
{"type": "https://docs.ledgerflow.example/errors/version_conflict", "title": "Someone else changed this invoice", "status": 409, "code": "version_conflict",
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

<!-- GEN:index -->

## 8. Contract validation

`python3 docs/source/validate_api.py` checks the generated `openapi.yaml` and the definitions behind it. The results below come from the last run and are written by the script; it exits non-zero on any failure.

<!-- GEN:validation -->

## 9. Endpoint reference

<!-- GEN:endpoints -->

## 10. Schemas

Shorthand types: `money`/`rate`/`qty`/`cost`/`pct` are decimal strings; `id` is int64; `ccy` is an ISO 4217 code; `[x]` is an array; `| null` means nullable. Exact JSON Schema is in `openapi.yaml`.

<!-- GEN:schemas -->

## 11. Page action map

For every page: what it **loads** when opened, and for every action in its design what it **calls**, with the permission and success status. `→` is a sequence and `|` alternatives chosen by state (create vs update). `dlg:` opens a dialog page (its own actions are listed under it), `nav:` navigates, and `ui:` is client-only. `export:` re-calls a `format`-capable report with `?format=xlsx|pdf|csv`, or starts `POST /export-jobs` for a list. Validation, errors and database operations of each call are in its §9 entry.

<!-- GEN:pagemap -->

## 12. Contract review (18 Oct 2026): missing, redundant and changed endpoints

<!-- GEN:review -->
