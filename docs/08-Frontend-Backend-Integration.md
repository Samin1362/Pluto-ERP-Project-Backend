# 08 · Frontend–backend integration

> React 18 + TypeScript (strict) + Vite, talking to the Laravel API `/api/v1` ([05](05-API-Specification.md)). The backend is authoritative for permissions, accounting, stock, prices and every calculation. React displays and collects; it never decides.

## 1. Frontend folder structure

```
frontend/
  index.html
  vite.config.ts
  src/
    main.tsx                     app bootstrap (QueryClient, Router, ThemeProvider)
    app/
      router.tsx                 route tree (lazy chunks per module)
      providers/                 Auth, Company, Theme, Toast providers
      shell/                     AppShell, Sidebar, Topbar, CommandPalette, QuickCreate (S0, L2, L5)
    design-system/               Pluto components (1:1 with the design)
      tokens/tokens.json         copied from the design (single source)
      tokens/tokens.css          generated CSS variables (light + dark)
      components/                Button, IconButton, Field, Input, Select, DateInput, Segmented, Switch, Checkbox,
                                 DataTable, Pagination, Tabs, Card, StatTile, Badge, Banner, Toast, Dialog, Drawer,
                                 Dropdown, Menu, EmptyState, Skeleton, Amount, BalanceStatus, Avatar, Tooltip, Icon,
                                 PageHeader, Logo, Sidebar, NavSection, NavItem, NavSubItem, Topbar, CommandPalette
      layouts/                   OverviewLayout, ListLayout, FormLayout, DetailLayout, ReportLayout, VoucherLayout, SettingsLayout
    api/
      client.ts                  fetch wrapper (auth, company header, idempotency, ETag, problem+json)
      generated/schema.d.ts      openapi-typescript output from docs/openapi.yaml (CI-regenerated)
      keys.ts                    query-key factory
    features/
      auth/        pages: SignIn, TwoFactor, ForgotPassword, ResetPassword, AcceptInvitation
      dashboard/   pages: Dashboard (A1), Lens (A2–A8)
      parties/     pages: PartyList, PartyForm, PartyDetail, PartyHistory, PartyLedger, Outstanding, Statement (B/C)
      inventory/   pages: ProductList, ProductForm, ProductDetail, Categories, StockGroups, Warehouses, WarehouseDetail,
                   Movements, StockAdjustment, StockTransfer, LowStock, Valuation, InventorySummary (D1–D14)
      sales/       pages: SalesOverview, InvoiceList, InvoiceEditor, InvoicePreview, InvoiceDetail, CustomerReceipt,
                   SalesReturn, CreditNote, Quotation, SalesReport, SalesReturnReport (E1–E12) + dialogs
      purchases/   (F1–F10) – shares documents/ editor with sales
      documents/   shared trade-document editor, line grid, totals panel, posting dialog
      banking/     (G1–G13)
      incexp/      (I1–I9)
      accounting/  (H1–H14, K16)
      reports/     (J1, H15–H19, J6, J7)
      settings/    (K1–K15)
      lc/          (M1–M8, dialogs, drawer)
      search/      (L1, palette data), notifications/ (L3, L4)
    lib/
      money.ts                   Money branded type; format via Intl + lakh grouping; never arithmetic
      permissions.ts             useCan(perm), <Can perm="...">
      errors.ts                  ProblemDetails → form errors / toasts
      shortcuts.ts               Ctrl K, Alt S/P/R/Y/J/C, Ctrl S, Ctrl Enter
    test/                        MSW handlers (component tests only), test utils
  .env.example                   VITE_API_BASE_URL, VITE_APP_ENV, VITE_SENTRY_DSN (public only)
```

## 2. Reusable components and the design system
- Each design component becomes a typed React component with the **same props as the design** (e.g. `<Field label hint error required term>`, `<DataTable columns rows totals selectable rowActions emptyState density loading>`, `<Amount value currency decimals size>`, `<BalanceStatus debit credit>`). The prototype already uses these names (`Pluto.Field` …), so the mapping is mechanical.
- Tokens: `tokens.json` → a build script → `tokens.css` with `:root,[data-theme=light]{…}` and `[data-theme=dark]{…}` blocks identical to `lf-theme.css`. Components use only CSS variables; no hard-coded colours (enforced by a stylelint rule).
- Fonts are self-hosted from the design's `ds/pluto/fonts/*.woff2`; Noto Sans/Serif Bengali come from Google Fonts for Bangla.
- Storybook holds one story per component and state, with visual regression (Chromatic or Playwright snapshots) in both themes.

## 3. Layout and routing
| Route | Page | Permission guard |
|---|---|---|
| `/sign-in`, `/two-factor`, `/forgot-password`, `/reset-password`, `/invite/:token` | S1–S3 | public |
| `/` → first permitted of `/dashboard`, `/sales`, `/purchases`, `/inventory`, `/banking`, `/accounting`, `/reports`, `/lc` (R-35; a Store keeper lands on `/inventory`) | — | signed in |
| `/dashboard`, `/dashboard/:lens` | A1; A2–A8 | `dashboard.view` |
| `/customers`, `/customers/new`, `/customers/:id`, `/customers/:id/edit`, `/customers/:id/history`, `/customers/:id/ledger`, `/customers/:id/statement`, `/customers/outstanding` | B1–B8 | `parties.*` |
| `/suppliers/...` | C1–C8 | `parties.*` |
| `/inventory/products[...]`, `/inventory/categories`, `/inventory/stock-groups`, `/inventory/warehouses[/:id]`, `/inventory/movements`, `/inventory/adjustments/new`, `/inventory/transfers/new`, `/inventory/low-stock`, `/inventory/valuation`, `/inventory` | D1–D14 | `inventory.*` |
| `/sales`, `/sales/invoices[/new|/:id|/:id/edit|/:id/preview]`, `/sales/payments[/new|/:id]`, `/sales/returns[/new]`, `/sales/credit-notes/:id`, `/sales/quotations[/new|/:id]`, `/reports/sales`, `/reports/sales-returns` | E1–E15 (lists E13 quotations, E14 payments received, E15 returns and credit notes are NEW, R-07) | `sales.*`, `reports.view` |
| `/purchases/...` incl. NEW lists `/purchases/orders`, `/purchases/payments`, `/purchases/returns` (R-07) | F1–F13 | `purchases.*` |
| `/banking`, `/banking/cash/:id`, `/banking/accounts[/new|/:id]`, `/banking/received/new`, `/banking/paid/new`, `/banking/transfer/cash`, `/banking/transfer/bank`, `/banking/reconcile/:accountId`, `/banking/books/:accountId`, `/banking/transactions` | G1–G13 | `banking.*` |
| `/income`, `/expenses` (+ `/list`, `/new`, `/categories`, `/report`), `/expenses/recurring` (NEW I10) | I1–I10 | `banking.*`, `reports.view` |
| `/accounting`, `/accounting/chart`, `/accounting/groups/:id`, `/accounting/ledgers[/new|/:id|/:id/edit]`, `/accounting/vouchers[/new/:type|/:id|/:id/print]` | H1–H14 | `accounting.*` |
| `/reports`, `/reports/trial-balance`, `/profit-and-loss`, `/balance-sheet`, `/cash-flow`, `/general-ledger`, `/receivables-aging`, `/payables-aging` | J1, H15–H19, J6, J7 | `reports.view` |
| `/settings/:section` | K1–K16 | per section |
| `/lc`, `/lc/list`, `/lc/new`, `/lc/:id/:tab?`, `/lc/amendments`, `/lc/documents`, `/lc/charges`, `/lc/reports/:report` | M1–M8 | `lc.*` |
| `/search?q=`, `/notifications` | L1, L3 | signed in |
| `*` | L15 Not found | — |

Breadcrumbs come from route `handle.crumb` and follow the design's three-level rule. Each module is a lazy chunk (NFR-004).

## 4. API client configuration
`src/api/client.ts` (fetch-based, about 150 lines) does the following:
1. Sets `baseUrl = import.meta.env.VITE_API_BASE_URL` (e.g. `https://api.pluto.example/api/v1`).
2. Adds `Authorization: Bearer <accessToken>` from the in-memory auth store; adds `X-Company-Id` from the company store; `Accept: application/json`; `X-Requested-With: XMLHttpRequest`.
3. For mutations, attaches an `Idempotency-Key` generated **once per user action** (stored on the form instance), so a retry or double-click reuses it.
4. For updates, sends `If-Match` from the ETag kept with the cached resource.
5. On 401 `token_expired`, makes a single in-flight `POST /auth/refresh` (`credentials: 'include'`, a mutex shared by concurrent requests), then retries once. A refresh failure clears auth and redirects to `/sign-in?next=…`.
6. Parses `application/problem+json` into `ApiError {status, code, detail, errors, requestId}`.
7. Never retries non-idempotent requests automatically, except safely with the same Idempotency-Key after a network error.
8. Uses `credentials: 'include'` only for `/auth/*`; the default is `same-origin`.

TanStack Query defaults: `staleTime` 30 s for lists, 5 min for lookups (units, tax rules, payment terms, mappings); `retry` 2 for GET on network or 5xx errors only; `refetchOnWindowFocus` on for lists.

## 5. Authentication state and protected routes
- `AuthProvider` holds `{user, accessToken, expiresAt}` **in memory**. On app load it calls `POST /auth/refresh` silently: success restores the session, failure shows sign-in.
- `CompanyProvider` holds the active company id, persisted in `localStorage` as a *preference only* (it is not a secret). When it changes it calls `queryClient.clear()` and refetches `GET /me` for the permissions in that company.
- `<ProtectedRoute perm="sales.view">` renders L14 Access denied when the permission is missing, or redirects to sign-in when signed out. `useCan(perm)` hides buttons. **This is UX only; the API enforces everything (07 §6).**
- Idle timeout: the SPA tracks activity and the API enforces the refresh-token idle limit; the UI shows "Signed out after inactivity".

## 6. TypeScript API types
- `openapi-typescript docs/openapi.yaml -o src/api/generated/schema.d.ts` runs in CI. A diff fails the build if the generated file wasn't committed, which keeps frontend and contract aligned (NFR-062).
- Types keep **snake_case** keys, exactly as JSON (no runtime case conversion, no mismatches). Money-like fields are `string`, wrapped in branded types:

```ts
// src/lib/money.ts
export type Money = string & { readonly __brand: 'Money' };   // "118450.00"
export type Qty = string & { readonly __brand: 'Qty' };
export function formatMoney(m: Money, opts: { grouping: 'lakh' | 'million'; decimals: 0 | 2; negative: 'parentheses' | 'minus'; symbol?: boolean }): string;
// No add/subtract/multiply helpers exist on purpose: arithmetic on money is a backend concern.
```

- Typed hooks per endpoint, e.g. `useSalesInvoice(id)`, `useCreateSalesInvoice()`, `usePostSalesInvoice()`, built on `paths['/api/v1/sales-invoices/{id}/post']['post']`.

## 7. Form validation and submission
| Step | Behaviour |
|---|---|
| Shape validation | zod schema derived from the OpenAPI request type: required fields, formats (e-mail, `01XXXXXXXXX`), max lengths. Gives instant inline feedback; it is **not** the source of truth |
| Calculation (trade documents, vouchers, LC) | Debounced (300 ms) `POST …/calculate` (sales and purchase), or the voucher's own preview, after each line change. The totals panel, amount in words, party balance after, warnings and "When you save" effects render **only** from the response; while pending, figures show a subtle skeleton (DEC-09, TC-FE-002). The journal voucher's live BalanceStatus is computed server-side too, with a local display-only sum while typing (marked "checking…") |
| Submit | Disable primary button, show busy state, send with Idempotency-Key |
| 422 | `errors` keys in dot notation (`lines.1.quantity`) map onto fields with `setError`; the banner "Fix N fields/lines to save" lists them (role=alert); focus moves to the first error (L8) |
| Business-rule 422 (`period_locked`, `insufficient_stock`…) | Shown as a negative Banner with the server's `detail`; line-level errors attach to their row |
| Warnings | Shown as warning Banners or badges; posting may require `acknowledge_warnings` |
| 409 `version_conflict` | Banner "Someone else changed this…" with **Reload**; the user's edits are kept in local draft state (L11) |
| 202 submitted | Toast "Sent for approval to …"; the document shows status *Submitted* |
| Success | Toast that says what happened (L12 rules); invalidate queries (§8); navigate per design (e.g. post → list, payment → details) |
| Leaving with changes | `useBlocker` opens the Unsaved-changes dialog (L13) |

Per form, the design's sticky footer pattern is kept: Cancel · Save draft · (Preview) · primary action with its keyboard hint.

## 8. Refreshing data after operations
Query keys are hierarchical (`['company', cid, 'sales-invoices', {filters}]`). After a mutation, the hook invalidates every view the change affects:

| Mutation | Invalidate |
|---|---|
| Post or cancel invoice | `sales-invoices`, `sales-invoice/{id}`, `party/{id}` (balance, ledger, open documents), `products` (stock), `stock-movements`, `dashboard`, `sales/overview`, `reports/*` |
| Customer receipt / supplier payment | `party/{id}`, documents allocated, `money-accounts`, `money-transactions`, `dashboard`, `approvals` |
| Post bill | `purchase-bills`, `party/{id}`, `products`, `warehouses`, `purchase-orders/{po}` |
| Voucher post or reverse | `vouchers`, `ledgers/{ids}`, `account-groups`, `reports/*`, `dashboard` |
| Stock adjustment or transfer | `products`, `warehouses`, `stock-movements`, `reports/inventory/*` |
| LC actions | `letters-of-credit/{id}`, `lc/overview`, `lc-charges`, `reports/lc/*`, `money-accounts` |
| Apply or remove an allocation | `party/{id}`, both documents, `customer-receipts` / `supplier-payments`, `dashboard` |
| Settings | `company/settings` (and re-render formatting) |

Optimistic updates are **not** used for financial mutations; the UI waits for the server's answer. They are allowed only for harmless UI state, such as marking a notification read.

## 9. Data tables and pagination
`<DataTable>` is server-driven: `page`, `per_page` (25 default, 100 max), `sort`, filters and `q` live in the URL query string (shareable, back-button friendly). The totals row uses server-provided totals for the **whole filtered set** where the design shows totals, never a client sum of the visible page. Selection persists across pages via ids; bulk actions post ids. Below 640 px, rows become stacked cards (NFR-052). Loading uses `loading` skeleton rows; empty uses `emptyState`.

## 10. Error and loading states
Implemented once and reused: `<PageSkeleton template="list|form|detail|report">`, `<WidgetError requestId onRetry>`, `<OfflineBanner>` (navigator.onLine plus failed-fetch detection; saving paused), `<AccessDenied>` (L14), `<NotFound>` (L15), and a toast queue (max 3, 5 s, errors persist, polite live region). After 8 s of loading, a "Still loading…" line appears (L10).

## 11. State management strategy
| State | Where |
|---|---|
| Server data | TanStack Query cache only |
| Auth tokens | In-memory module (never Web Storage) |
| Active company, theme, density, sidebar collapsed | Zustand, persisted to localStorage as preferences; theme and density also saved via `PUT /me/preferences` |
| Form state | React Hook Form per page |
| URL state (filters, tabs, pagination) | Router search params |

## 12. Environment variables
Frontend (public, baked at build): `VITE_API_BASE_URL`, `VITE_APP_ENV` (`local|staging|production`), `VITE_SENTRY_DSN` (public DSN), `VITE_RELEASE`. **No secrets** (NFR-014). Backend env: see [11 §2](11-Deployment-and-Operations.md#2-environment-configuration).

## 13. CORS configuration (Laravel `config/cors.php`)
```php
'paths' => ['api/*'],
'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS')),   // e.g. https://app.pluto.example
'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Company-Id', 'Idempotency-Key', 'If-Match', 'X-Requested-With', 'X-Request-Id'],
'exposed_headers' => ['ETag', 'X-Request-Id', 'Retry-After', 'Location'],
'max_age' => 600,
'supports_credentials' => true,   // needed for the refresh cookie on /api/v1/auth/*
```
No wildcard origin is allowed with credentials. Locally, the Vite dev server proxies `/api` to Laravel, so the app runs same-origin.

## 14. API response conventions used by the UI
`{data}` / `{data, meta}` envelopes; Problem Details errors; `warnings[]`; decimal strings; ISO dates; `ETag` on editable resources; `Location` on 201; `202` plus a job resource for async work. These match [05 §1](05-API-Specification.md#1-conventions).

## 15. Per-form submission map (summary)
For every form page in [02](02-UI-Inventory.md), the endpoint list under point 9 is the submission target. The table below covers the high-traffic forms.

| Form | Submit | On success | Refresh |
|---|---|---|---|
| E3 / E6 Invoice | `POST /sales-invoices` or `PUT /sales-invoices/{id}`, then `POST …/{id}/post` from the Post dialog | Toast "INV-… posted", go to E2 | §8 invoice row |
| E7 Customer payment | `POST /customer-receipts` (after confirm dialog) | Toast, go to invoice details | §8 receipt row |
| F3 Bill | `POST /purchase-bills`, then `/{id}/post` | Toast, go to F2 | §8 bill row |
| F5 Supplier payment | `POST /supplier-payments` | 201 toast, or 202 "sent for approval" | §8 |
| B2/C2 Party | `POST /parties` (Save and add another resets the form, keeping the type) | Go to B4/C4 | `parties` |
| D2/D3 Product | `POST /products` / `PATCH /products/{id}` | Go to D4 | `products` |
| D10 Adjustment | `POST /stock-adjustments`, then `/{id}/post` | Go to D9 | §8 |
| D11 Transfer | `POST /stock-transfers`, then `/{id}/dispatch` | Go to D8 | §8 |
| G6/G7/G8/G9 | `POST /vouchers` (`entry_type` receipt/payment/contra, `action: post`) | Toast; G1 | `money-accounts` |
| I3/I6 | `POST /ie-entries` (+ `POST /attachments` first; ids included) | Go to list | `ie-entries`, `ie-categories` |
| H9–H12 | `POST /vouchers` with `action` draft/submit/post | Go to H8 | §8 voucher row |
| G10 Reconcile actions | `/reconciliations/{id}/…` per row; Finish | Toast; G5 | `reconciliations/{id}`, `money-accounts` |
| K-settings | `PATCH /company/settings` (If-Match) per section | Toast; clear dirty bar | `company/settings` |
| K11 Roles | `PUT /roles/{id}` | Toast | `roles`, `me` |
| M3 LC | `POST /letters-of-credit` / `PUT …/{id}`; Submit → `POST …/{id}/transition {action: submit}` | Go to M4 | `letters-of-credit` |
| M4 dialogs | `POST /letters-of-credit/{id}/payments`, `POST /lc-charges`, `POST …/{id}/close` | Toast | LC row in §8 |

## 16. Business logic that must NOT live in React
Totals, taxes, discounts, rounding and amount in words; stock availability; average cost and COGS; credit-limit and overdue checks; allocation (the "Re-apply oldest first" button calls the API's planner through `auto_allocate`, or the open-documents endpoint returns a suggested plan); posting effects; period checks; permission decisions; LC tolerance and FX; landed-cost splits; aging buckets. A lint rule forbids `parseFloat`/`Number()` on fields typed `Money` in `src/features/**`.
