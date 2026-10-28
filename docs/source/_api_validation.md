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
| A10 | Every error code exists in the catalogue, has its HTTP status documented on the operation, and every catalogue code is reachable | 71 | ✅ pass |
| A11 | Request-only schemas reject unknown properties (additionalProperties: false) | 90 | ✅ pass |
| A12 | Money, quantity, cost and rate fields are decimal strings (never JSON numbers) | 203 | ✅ pass |
| A13 | PUT/PATCH on a versioned table requires If-Match, and If-Match is only required where a version exists | 39 | ✅ pass |
| A14 | Request fields are stored: each maps to a column (or is declared virtual) with a compatible length, enum values and nullability | 378 | ✅ pass |
| A15 | Response enum fields have exactly the values the database allows | 29 | ✅ pass |
| A16 | Every action of every page maps to API calls, a dialog, navigation or explicit client behaviour, and every target exists | 522 | ✅ pass |
| A17 | Every data-changing endpoint is triggered by at least one page action (only token refresh is system-driven) | 176 | ✅ pass |
| A18 | Every page has at least one endpoint (shared components use their host page's) | 162 | ✅ pass |
| A19 | For every action, at least one role can both open the page and call the endpoint | 322 | ✅ pass |
| A20 | Database operations name real tables; nothing deletes posted or append-only rows; every write changes something | 1284 | ✅ pass |
| A21 | Every parameter has a typed schema | 524 | ✅ pass |
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