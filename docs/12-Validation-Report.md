# 12 · Validation report (Phase D)

> Run on 18 Oct 2026 against this package. Re-run any time with:
> `python3 docs/source/build_db.py && python3 docs/source/build_schema_doc.py && python3 docs/source/build_docs.py && python3 docs/source/check_consistency.py && python3 docs/source/check_mermaid.py <mmdc>`

## 1. Results summary

| Check | Method | Result |
|---|---|---|
| Every FK references an existing PK or unique key | `build_db.py` resolves all 385 FK columns against `schema.py` | ✅ 0 errors |
| MySQL 8.0 physical rules (types, error 3823/3104, names, key/row sizes, decimal classes, deletion rules, 1:1 links) | `validate_schema.py`, 24 rules ([14 §3.1](14-Physical-Schema-and-Migrations.md#31-schema-rules-mysql-80-and-project-conventions)) | ✅ 24/24 |
| Laravel migrations | `php -l`, strict schema-builder harness (up in order, down in reverse), exact comparison with the contract ([14 §3.2](14-Physical-Schema-and-Migrations.md#32-migrations)) | ✅ 83 migrations, 0 differences; **not yet run on a MySQL server** (contract test provided) |
| No circular FK dependencies | Topological sort into migration levels | ✅ 11 levels, no cycle (one cycle, `tax_rules` ↔ `ledgers`, was found and removed during design) |
| `SET NULL` only on nullable columns; no FLOAT/DOUBLE money | Static checks in `build_db.py` | ✅ |
| Many-to-many only via junction tables | Junction flag + unique pair check | ✅ 4 junctions: `role_permissions`, `company_users`, `company_user_branches`, `user_invitation_branches` |
| ER diagrams agree with the schema | Diagrams are generated from the schema, **and** `check_consistency.py` parses every `erDiagram` in every document (including the hand-written one in 07) and verifies each line is a real FK | ✅ 483 relationship lines verified (04 and the full diagram in 14) |
| Data dictionary agrees with diagrams | Same source | ✅ by construction |
| API ↔ database | Each of 309 endpoints lists its tables and derived operations (lock/read/insert/update/delete); all tables exist; ledger and stock writes are coherent (rule A24) | ✅ |
| API ↔ business rules / requirements | Each rule or FR referenced by an endpoint or page exists | ✅ |
| API ↔ UI | Every page maps to ≥ 1 endpoint, except shared states, dialogs and the shell (which use their host page's endpoints) | ✅ 162 pages (+15 reused report entries); 522/522 page actions mapped to an endpoint, dialog, navigation or client behaviour |
| UI permissions ↔ API permissions | Anyone who can open a page can call each GET it uses (200 page/endpoint pairs) | ✅ after fixes (§3); 1 documented action-gated exception |
| Requirement coverage | Every FR referenced by a page or endpoint | ✅ 76/76 |
| Business-rule test coverage | Every BR has ≥ 1 test case | ✅ 65/65 rules, 148 test cases |
| Traceability matrices | 6 workflows: each step's page, endpoint, tables, rules and tests resolve | ✅ |
| Accounting mapping keys ↔ seed data | Keys in 06 §3 = keys seeded in 04 §11.3 | ✅ 23 keys |
| Accounting worked examples ↔ design | Recomputed (E5 invoice ৳ 1,18,450 / COGS ৳ 1,04,264 / totals ৳ 2,22,714; F4 bill ৳ 6,26,000; RV ৳ 2,74,700; LC payment ৳ 46,88,640, margin ৳ 9,33,120, bank ৳ 37,55,520; FX ৳ 23,040; landed cost split ৳ 1,44,000 / ৳ 96,000) | ✅ arithmetic checked |
| OpenAPI 3.1 contract | `validate_api.py`: the document validates against the **official OpenAPI 3.1 schema** (OpenAPI Initiative, `src/schemas/validation`), every component schema against the JSON Schema 2020-12 meta-schema, plus 22 project rules (refs, operation ids, security, idempotency, Location, strict bodies, decimal-string money, errors ↔ catalogue, request fields ↔ DB columns, page actions ↔ endpoints, preview warnings, ledger write invariants); 6 negative tests | ✅ 24/24 rules, 309 operations, 197 schemas; 6/6 negative tests detected ([05 §8](05-API-Specification.md#8-contract-validation)) |
| Mermaid syntax | Every ```mermaid block in every document is rendered with mermaid-cli 11 / Chromium | ✅ 25/25 render (3 syntax errors were found and fixed) |
| Internal links and anchors | 1,649 relative links and anchors checked | ✅ |
| IDs in prose | Every BR/FR/NFR/TC/GAP/DEC/AS/CF id mentioned anywhere exists | ✅ |
| Endpoints quoted in hand-written docs | Each `METHOD /path` in 03/06/07/08/10/11 exists in the API registry | ✅ |

## 2. Required diagrams, where they are

| # | Diagram | Location |
|---|---|---|
| 1 | System context | [03 §1 Diagram 1](03-System-Architecture.md#diagram-1--system-context) |
| 2 | High-level architecture | [03 §1 Diagram 2](03-System-Architecture.md#diagram-2--high-level-architecture) |
| 3 | Entity-relationship (overview + 9 module diagrams) | [04 §5](04-Database-Design.md#5-entity-relationship-diagrams) |
| 4 | Authentication sequence | [07 §1](07-Authentication-and-Authorization.md#1-overview) and condensed in [03 §5](03-System-Architecture.md#diagram-4--authentication-flow-condensed) |
| 5 | API request sequence | [03 §6 Diagram 3](03-System-Architecture.md#diagram-3--frontend-to-backend-request-flow) |
| 6 | Purchase workflow | [03 §12 Diagram 5](03-System-Architecture.md#diagram-5--purchase--inventory--accounting) |
| 7 | Sales workflow | [03 §12 Diagram 6](03-System-Architecture.md#diagram-6--sales--payment--accounting) |
| 8 | Inventory movement | [03 §12 Diagram 8](03-System-Architecture.md#diagram-8--inventory-movement) |
| 9 | Accounting posting | [06 §4](06-Accounting-and-Business-Rules.md#4-the-posting-engine) |
| 10 | LC and import (workflow + state machine) | [03 §12 Diagram 7](03-System-Architecture.md#diagram-7--import-and-lc), [06 §12.1](06-Accounting-and-Business-Rules.md#121-lc-state-machine-br-040) |
| 11 | User-role-permission | [07 §6.1](07-Authentication-and-Authorization.md#61-model) |
| 12 | Development dependency | [10 §1](10-Development-Roadmap.md#1-phase-dependency-diagram) |
| + | Security boundaries | [03 §11](03-System-Architecture.md#11-security-boundaries) |

## 3. Issues found and fixed during validation

| Found | Fix |
|---|---|
| FK cycle `tax_rules.ledger_id` ↔ `ledgers.tax_rule_id` | Ledger-level tax rule removed (tax rules attach to products and lines) |
| Sales staff could open E7 but not list deposit accounts (`GET /money-accounts` required `banking.view`) | Picker opened to all members with balances and numbers masked |
| Store keeper and sales staff could open D2/C2 but not list ledgers for "Income account" / "Default purchase account" | `GET /ledgers` opened as a picker; balances only with `accounting.view` |
| Pages wired to the wrong read endpoint (C5 → bills list, F10/E12 → document lists, A1/J1 → vouchers, A4/A7 → module overviews, H19 → ledger entries, D10 → mappings) | Pages now use their dedicated endpoints (party transactions, report endpoints, dashboard lenses, GL report, adjustment posting preview) |
| `GET /branches` needed `settings.view`, but every user needs branch filters | Opened to all members |
| LC payment design initially debited goods in transit, which would break FX settlement | LC payments debit the supplier (an advance); FX is recognised on allocation (06 §12.4–12.5); usance `payment_kind` added |
| Mermaid: `;` inside sequence messages, mixed edge syntax | Corrected; all diagrams render |
| Heading anchor with "⚑" produced a different slug | Heading reworded; links verified |

## 4. Limitations of this validation (stated honestly)

1. **OpenAPI** is validated against the official OpenAPI 3.1 schema with Python `jsonschema` (vendored in `docs/source/oas31/`). `@redocly/cli` lint could not be installed here (registries blocked). **Action (P0 CI):** also run `npx @redocly/cli lint docs/openapi.yaml`. The contract has not been exercised against a running API; that is TC-CON-006 once endpoints exist.
2. **No application code exists.** The commands in 10 §3 and the README are plans; none were executed. Nothing claims that an endpoint "works".
3. The **component library internals** (`bundle.js`) were not read line by line; component names and props were taken from how the screens use them.
4. **Business rules were derived from prototype behaviour and copy**, not from a signed specification. An accountant review of 06 is required (P10 exit criterion).
5. **Bangladesh tax and regulatory items** (06 §14) are unverified by design; none are implemented.

## 5. Unresolved issues and requirements not yet testable

| ID | Issue | Impact | Owner decision needed |
|---|---|---|---|
| DEC-01 | ORM | ✅ Resolved 18 Oct 2026: Eloquent | — |
| DEC-02 | JWT library and token storage | Auth implementation | Yes, blocking P1 |
| DEC-08 | First vertical slice = purchase bill | Roadmap order | Yes |
| DEC-03/11 | Tenancy model; strictness of branch isolation | Every query/report | Yes |
| DEC-04 | Party can be both customer and supplier? | Party model, netting | Yes |
| DEC-06 | Draft numbers voided vs gapless numbering (also a VAT question) | Numbering service | Yes |
| DEC-07 / GAP-07 | Remove "Delete voucher" for posted vouchers and the "allow editing posted vouchers" toggle | UI change to the design | Yes |
| GAP-03 | Purchase returns credit inventory, not a "Purchase returns" ledger | Posting rule differs from the design preview | Yes |
| GAP-02 / GAP-20 | Inventory ledger per warehouse; stock groups are reporting-only | Posting and BS presentation | Yes |
| DEC-10 | LC postings in v1 (recommended) vs tracking only | Scope of P9 | Yes |
| DEC-13 (new) | The design gives Sales staff "Reports: View", yet L1 says "Sales staff never see ledgers". Should financial statements (TB, P&L, BS, GL, aging) need a separate permission such as `reports.financial`? | Permission catalogue | Yes |
| — | The Store keeper role can post stock adjustments (design K11) although it "sees no money figures"; the posting preview is hidden for them | Control risk | Confirm |
| RISK-01 | VAT/Mushak and withholding requirements | Possible new documents and fields | Adviser input |
| NFR-041 | Availability target depends on hosting | Ops design | Hosting choice |
| NFR-001 | Performance targets need real volume estimates | Indexing, replicas | Volume estimate |
