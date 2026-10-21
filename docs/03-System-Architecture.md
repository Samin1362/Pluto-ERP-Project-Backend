# 03 · System architecture

> Status: **Option A, approved: React + TypeScript SPA · Laravel 11/12 on PHP 8.3 · Eloquent · MySQL 8.0** (ORM decision DEC-01, 18 Oct 2026; [00 §6](00-Discovery-and-Decisions.md#6-orm-compatibility-evaluation--decided-eloquent)).

## 1. Overall architecture
A **modular monolith**: one Laravel application, one MySQL database, one React single-page application. Modules are separated *inside* the codebase (domain folders with explicit service interfaces), not as separate deployables. For an accounting system this is the right trade-off: a sale must move stock, post the journal and take a number in **one ACID transaction**, which microservices would turn into distributed sagas.

### Diagram 1 · System context

```mermaid
flowchart LR
    subgraph People
        O[Owner / director]
        FM[Finance manager / accountant]
        SS[Sales staff]
        SK[Store keeper]
        V[Viewer / auditor]
    end
    LF((Pluto ERP))
    O --> LF
    FM --> LF
    SS --> LF
    SK --> LF
    V --> LF
    LF -- "invoices, statements, invitations" --> MAIL[E-mail service]
    LF -- "alerts (optional)" --> SMS[SMS gateway]
    BANK[Banks: Sonali, DBBL, BRAC, bKash] -. "statement files CSV/XLSX, uploaded by users" .-> LF
    LF -. "LC applications, prepared by users outside the system" .-> BANK
    LF -- "files, backups" --> OBJ[(Object storage)]
    LF -- "errors, metrics" --> MON[Monitoring / error tracking]
```

People use Pluto through the browser. Pluto sends e-mails (and optional SMS) and stores files and backups in object storage. Bank data enters only as statement files uploaded by users; there is **no direct bank integration in v1** (dotted lines are manual steps). Errors and metrics go to monitoring.

### Diagram 2 · High-level architecture

```mermaid
flowchart TB
    subgraph Browser
        SPA["React 18 + TypeScript SPA<br/>Vite build · TanStack Query · React Router<br/>Pluto design-system components"]
    end
    subgraph Edge
        CDN["CDN / static hosting<br/>(SPA assets)"]
        LB["Reverse proxy / load balancer<br/>TLS, HSTS, rate limit"]
    end
    subgraph App["Application tier (stateless, N nodes)"]
        API["Laravel 11/12 API (PHP-FPM / Octane)<br/>HTTP: routes, middleware, controllers, form requests, resources<br/>Domain: services, policies, posting engine<br/>Persistence: Eloquent models, query objects"]
        WRK["Queue workers (Laravel Horizon)<br/>exports, e-mails, imports, backups, recurring drafts"]
        SCH["Scheduler (cron, one node)<br/>expiry alerts, period-lock reminders,<br/>nightly integrity checks"]
    end
    subgraph Data
        DB[("MySQL 8.0 InnoDB<br/>primary + (later) read replica")]
        RD[("Redis<br/>cache, queues, rate limits,<br/>session-revocation cache")]
        S3[("S3-compatible object storage<br/>attachments, exports, backups (private)")]
    end
    SPA -- "HTTPS JSON /api/v1" --> LB
    CDN -- "index.html, JS, CSS" --> SPA
    LB --> API
    API --> DB
    API --> RD
    API --> S3
    WRK --> DB
    WRK --> RD
    WRK --> S3
    SCH --> RD
```

The SPA is static files served from a CDN and talks only to the API over HTTPS. The API nodes keep no state, so any node can serve any request. MySQL holds all business data; Redis holds caches, queues and rate-limit counters; object storage holds files. Workers run slow jobs off the request path; one scheduler node runs timed jobs.

## 2. Frontend architecture (summary; details in [08](08-Frontend-Backend-Integration.md))
- React 18 + TypeScript (strict), Vite, React Router 6 (data routers, lazy route chunks), and TanStack Query for server state (cache, retries, invalidation). There is no global client store for server data; Zustand holds UI-only state (sidebar, theme, active company).
- The **Pluto design system** is implemented as a typed React component library (`src/design-system/`), matching the 36 components and the tokens in [02 §1](02-UI-Inventory.md#1-design-system-to-preserve). Tokens become CSS variables from `tokens.json`.
- Forms use React Hook Form with zod for *shape* validation only. Business validation always comes from the API (422).
- Feature folders mirror the backend modules (`features/sales`, `features/lc`, …).

## 3. Backend architecture

```
app/
  Http/
    Controllers/Api/V1/{Module}/...Controller.php   thin: authorize → validate → call service → resource
    Requests/{Module}/...Request.php                 Form Requests: rules + company-scoped exists()
    Resources/{Module}/...Resource.php               JSON shape, field masking (view_costs)
    Middleware/ResolveCompany.php, RequireTwoFactor.php, Idempotency.php, ETag.php
  Domain/
    Shared/ Money.php, Quantity.php, Rate.php, DocumentNumberService.php, AuditLogger.php
    Accounting/ JournalBuilder.php, PostingService.php, ReversalService.php, PeriodService.php, AccountMapper.php
    Inventory/ StockService.php, AverageCostCalculator.php, StockAdjustmentPostingService.php, StockTransferService.php
    Sales/ DocumentCalculator.php, SalesInvoiceService.php, SalesInvoicePostingService.php, SalesInvoiceCancellationService.php, ...
    Purchases/ PurchaseBillPostingService.php, ...
    Money/ PartyPaymentService.php, AllocationPlanner.php, VoucherService.php, ReconciliationService.php
    LC/ LetterOfCreditService.php, LcStateMachine.php, LcChargeService.php, LcPaymentService.php, LandedCostService.php
    Access/ AuthService.php, PermissionResolver.php, InvitationService.php
  Models/ (Eloquent, one per table, BelongsToCompany trait, casts to Money/enums)
  Policies/ (one per aggregate)
  Queries/ (report read models: TrialBalanceQuery, AgingQuery, LedgerQuery ...)
  Jobs/, Events/, Listeners/
database/migrations, seeders, factories
routes/api.php (versioned group /api/v1)
```

Rules: controllers contain no business logic (NFR-060). Domain services are the only code that changes financial or stock state. Posting services are the only code that creates journal entries. Read-heavy reports use `Queries/` with the query builder or raw SQL (always with `company_id` first), not N+1 Eloquent loops.

Module boundaries and allowed dependencies (enforced with `deptrac`):

| Module | May depend on |
|---|---|
| Shared | — |
| Access | Shared |
| Accounting | Shared |
| Parties, Inventory | Shared, Accounting (ledger creation, posting) |
| Sales, Purchases | Shared, Accounting, Inventory, Parties |
| Money | Shared, Accounting, Parties, Sales/Purchases (allocation targets via interfaces) |
| LC | Shared, Accounting, Purchases, Money |
| Reports (Queries) | read-only access to all |

## 4. Database architecture
MySQL 8.0 InnoDB, `utf8mb4`, strict mode, UTC. Single schema, row-level tenancy (`company_id`). 83 tables in 9 modules ([04](04-Database-Design.md)). Isolation level is `REPEATABLE READ` (the default), with explicit `SELECT … FOR UPDATE` on the rows a posting changes. Connections are pooled through PHP-FPM persistent connections or Octane. A read replica for heavy reports becomes an option later (reports tolerate seconds of lag; posting never reads from the replica).

## 5. Authentication and authorization
JWT access token in memory + rotating refresh cookie; per-request membership and permission resolution; global tenant scopes; policies; field masking. Full detail: [07](07-Authentication-and-Authorization.md).

### Diagram 4 · Authentication flow (condensed)

```mermaid
flowchart LR
    A[Sign-in form] -->|email + password| B{Credentials valid?}
    B -- no --> X[401 generic / 429 throttled]
    B -- yes --> C{2FA on?}
    C -- yes --> D[TOTP step] --> E{Code valid?}
    E -- no --> X
    E -- yes --> F
    C -- no --> F[Create session<br/>issue JWT 15 min<br/>set HttpOnly refresh cookie]
    F --> G[API calls with Bearer + X-Company-Id]
    G --> H{JWT valid and session live?}
    H -- expired --> I[POST /auth/refresh rotates cookie] --> G
    H -- yes --> J{Active member with permission?}
    J -- no --> K[403 / 404]
    J -- yes --> L[200]
```

## 6. API communication

### Diagram 3 · Frontend-to-backend request flow

```mermaid
sequenceDiagram
    autonumber
    participant UI as React page (e.g. E3)
    participant Q as TanStack Query / apiClient
    participant API as Laravel route /api/v1
    participant MW as Middleware
    participant FR as Form Request
    participant CT as Controller
    participant SV as Domain service
    participant DB as MySQL
    UI->>Q: mutate(createInvoice, payload)
    Q->>API: POST /sales-invoices (Bearer, X-Company-Id, Idempotency-Key)
    API->>MW: RequestId, throttle, auth:api, ResolveCompany, Idempotency
    MW->>FR: authorize() via policy, rules() incl. company-scoped exists
    FR-->>UI: 422 Problem Details (field errors) on failure
    FR->>CT: validated DTO
    CT->>SV: SalesInvoiceService::createDraft(dto)
    SV->>DB: transaction: sequence lock, insert header + lines, audit
    SV-->>CT: SalesInvoice model
    CT-->>Q: 201 {data: SalesInvoiceResource} + ETag
    Q->>Q: invalidate ['sales-invoices'], ['party', id]
    Q-->>UI: success toast, navigate to E5
```

A request passes through the same chain every time. Middleware identifies the caller, company and idempotency key; the Form Request authorizes and validates; the thin controller calls one domain service, which does the work in a transaction; the API Resource shapes the JSON. On success the client invalidates the affected cached queries so every screen refreshes.

## 7. Validation and error handling
- **Three layers:** (1) client-side shape checks for instant feedback (required, format); (2) Form Requests (types, lengths, existence within company, cross-field rules); (3) domain services (business rules: stock, periods, allocations, balances), throwing typed `DomainException`s that map to Problem Details codes.
- A central `Handler` maps exceptions: `ValidationException` → 422 `validation_failed`; `DomainRuleViolation` → 422 with its `code`; `ConflictException` → 409; `AuthorizationException` → 403; `ModelNotFoundException` → 404; anything else → 500 with only the `request_id` shown.
- Warnings (non-blocking) travel in `warnings[]` of 2xx responses.

## 8. Logging and monitoring
- Structured JSON logs (Monolog) with `request_id`, `user_id`, `company_id`, route and duration; no PII beyond ids; no amounts in info logs; secrets redacted.
- Error tracking: Sentry (or an equivalent) for API and SPA, with release tags and source maps uploaded privately.
- Metrics: request rate, latency p50/p95/p99, error rate per route, queue depth and age, failed jobs, DB slow-query log (> 500 ms), integrity-job results. Alerts on: error rate > 2% for 5 min, p95 > 1 s for 10 min, queue age > 5 min, any integrity mismatch, backup failure, disk > 80%.
- Business audit is separate from logs (`audit_logs`, [07 §11](07-Authentication-and-Authorization.md#11-audit-logging)).

## 9. File storage
Private S3-compatible bucket (`attachments/`, `exports/`, `backups/`), with object keys `company/{id}/{yyyy}/{mm}/{uuid}`. Uploads are streamed through the API with MIME sniffing, a size limit and an optional ClamAV scan job. Downloads use signed URLs valid 5 minutes. Server-side encryption at rest is on, and lifecycle rules apply backup retention.

## 10. Deployment architecture
Provider-neutral (AS-10): containerised API (PHP-FPM + Nginx, or Octane/FrankenPHP), workers and scheduler; managed or self-hosted MySQL 8.0 and Redis; SPA on any static host or CDN. Environments: development (docker-compose), staging, production. Detail: [11](11-Deployment-and-Operations.md).

## 11. Security boundaries

```mermaid
flowchart LR
    subgraph Z0["Untrusted: Internet"]
        BR[Browser / SPA]
    end
    subgraph Z1["DMZ"]
        LB[TLS termination<br/>WAF / rate limit]
    end
    subgraph Z2["Application network"]
        API[API nodes]
        WRK[Workers]
    end
    subgraph Z3["Data network (no public access)"]
        DB[(MySQL)]
        RD[(Redis)]
    end
    S3[(Object storage<br/>private, signed URLs)]
    BR -- "HTTPS only" --> LB --> API
    API --> DB
    API --> RD
    WRK --> DB
    API -- "IAM-scoped keys" --> S3
    BR -. "signed URL, 5 min" .-> S3
```

Trust boundaries: (1) everything from the browser is untrusted, so the API re-validates and re-authorizes every request; (2) only the load balancer is public; (3) database and Redis accept connections only from the application network, with separate DB users for runtime (DML only) and migrations (DDL); (4) object storage is private, and the browser reaches a file only through a short-lived signed URL; (5) the tenant boundary (`company_id`) is enforced inside the application on every query (07 §7).

## 12. Business workflow diagrams

### Diagram 5 · Purchase → inventory → accounting

```mermaid
flowchart LR
    A[D12 Low stock / F8 Purchase order] --> B[F3 Purchase bill draft]
    B -->|post| C{Valid?<br/>supplier bill no. unique<br/>period open}
    C -- no --> R[422 with reason]
    C -- yes --> D[Stock in: stock_movements purchase_in<br/>stock_balances +qty]
    D --> E[Warehouse average cost recalculated<br/>stock_balances]
    E --> F[Journal: Dr warehouse inventory<br/>Cr supplier ledger]
    F --> G[PO received qty updated]
    G --> H[F5 Supplier payment<br/>allocate to bill]
    H --> I{Above role limit?}
    I -- yes --> J[L4 approval] --> K
    I -- no --> K[Journal: Dr supplier<br/>Cr bank/cash]
    K --> L[Bill paid · bank book · payables aging]
```

A purchase order (optional) becomes a bill. Posting the bill brings stock in, updates the average cost and records what is owed to the supplier, all in one transaction. Paying the supplier is a separate voucher, which needs approval above the role's limit, and is allocated to the bill.

### Diagram 6 · Sales → payment → accounting

```mermaid
flowchart LR
    Q[E10 Quotation] -->|convert| A[E3 Invoice draft]
    A --> B[POST /calculate<br/>server totals + warnings]
    B --> C[E4 Preview]
    C -->|post| D{Stock available?<br/>period open?}
    D -- no --> R[422 line errors]
    D -- yes --> E[Stock out at average cost<br/>sale_out movements]
    E --> F[Journal: Dr customer<br/>Cr sales/income, Cr tax<br/>Dr COGS, Cr inventory]
    F --> G[E7 Customer receipt]
    G --> H[Allocate oldest first<br/>excess = advance]
    H --> I[Journal: Dr bank/cash<br/>Cr customer]
    I --> J[Invoice paid / part paid<br/>B6 ledger · J6 aging]
    F -. correction .-> K[E8 Return → E9 Credit note]
    F -. cancel .-> L[Cancellation entry<br/>stock back, payments → advance]
```

An invoice is calculated by the server, previewed, then posted. That takes stock out at average cost and posts revenue and cost of goods sold together. A receipt is recorded later and applied to the oldest invoices first. Mistakes are corrected with returns and credit notes, or by cancelling, never by editing a posted invoice.

### Diagram 7 · Import and LC

```mermaid
flowchart TB
    PO[F8 Import PO in USD] --> LC1[M3 Create LC: supplier, terms, banks, documents, shipment]
    LC1 -->|submit| AB[Awaiting bank]
    AB -->|bank ref| OP[Opened]
    LC1 -. margin + charges .-> CH[lc_charges<br/>Dr LC margin / goods in transit<br/>Cr bank]
    OP --> AM[M5 Amendments<br/>applied when accepted]
    OP --> SH[Shipment lots inside LC Details]
    SH --> DOC[Documents presented<br/>checklist received / discrepant]
    DOC --> PAY[LC payment at bank rate<br/>Dr supplier · Cr margin + bank]
    SH -->|goods arrive| BILL[F3 import bill: currency + rate + LC/lot<br/>Dr inventory · Cr supplier]
    PAY --> FX[Allocation settles bill<br/>FX difference → exchange gain/loss]
    BILL --> FX
    BILL --> LCA[Landed cost allocation<br/>Dr inventory + COGS · Cr goods in transit]
    FX --> CL{Close checks:<br/>paid, charges paid,<br/>documents ok, transit = 0}
    LCA --> CL
    CL -- ok --> CLOSED[Closed · margin released]
    OP -->|expiry date| EXP[Expired → release unused margin]
```

The LC is created from a PO and goes through the bank. Its margin and charges are recorded as they occur and held as assets. Shipments and documents are tracked inside the LC. Payment is made at the bank's rate. When the goods arrive, an import bill brings them into stock, the charges are spread over them as landed cost, and any exchange difference is recognised. The LC closes when everything is settled. No separate shipment module exists (CF-22).

### Diagram 8 · Inventory movement

```mermaid
flowchart LR
    subgraph In
        P[Purchase bill]
        SR[Sales return restock]
        AG[Adjustment gain]
        TI[Transfer in]
        OB[Opening stock]
        LCV[Landed cost value]
    end
    subgraph Out
        S[Sales invoice]
        PR[Purchase return]
        AL[Adjustment loss]
        TO[Transfer out]
    end
    In --> SM[(stock_movements<br/>append-only)]
    Out --> SM
    SM --> SB[stock_balances qty per warehouse]
    SM --> PC[stock_balances value and moving average]
    SM --> JE[journal_entries via posting services]
    JE --> INV[Inventory ledgers per warehouse]
    SB -. nightly check .- SM
    INV -. valuation = ledger .- PC
```

Every quantity or value change is one row in the append-only stock ledger, written by the same transaction as the document and its journal. Balances and average costs are caches of that ledger, and the nightly job proves they match it and the inventory ledgers.

The accounting posting workflow (diagram 9) is in [06 §4](06-Accounting-and-Business-Rules.md#4-the-posting-engine); the LC state machine is in [06 §12.1](06-Accounting-and-Business-Rules.md#121-lc-state-machine-br-040); the user–role–permission diagram (11) is in [07 §6](07-Authentication-and-Authorization.md#6-role-based-access-control); the ER diagrams (3) are in [04 §5](04-Database-Design.md#5-entity-relationship-diagrams); the development dependency diagram (12) is in [10 §1](10-Development-Roadmap.md#1-phase-dependency-diagram).

## 13. Naming conventions across layers

| Concept | MySQL | Laravel | API JSON | React/TypeScript |
|---|---|---|---|---|
| Table / model | `sales_invoices` | `SalesInvoice` (model), `SalesInvoiceResource` | resource `/sales-invoices` | `SalesInvoice` type, `features/sales/invoices` |
| Column / attribute | `invoice_date` | `$invoice->invoice_date` | `"invoice_date"` | `invoice.invoice_date` (snake_case kept in API types) |
| Foreign key | `party_id` | `party()` belongsTo | `"party_id"` input, `"party": {id, code, name}` output | `party_id`, `party` |
| Enum | `status VARCHAR(30)` + CHECK | `SalesInvoiceStatus::Posted` backed enum | `"posted"` | `type SalesInvoiceStatus = 'draft' \| 'posted' \| 'cancelled'` |
| Money | `DECIMAL(18,2)` | `Money` cast (brick/math) | `"118450.00"` | `Money` = branded string; formatted only by `<Amount>` |
| Permission | `permissions.key = 'sales.create'` | `can:sales.create` | `x-permission` | `useCan('sales.create')` |
| Action | — | `SalesInvoicePostingService::post()` | `POST /sales-invoices/{id}/post` | `usePostSalesInvoice()` |

