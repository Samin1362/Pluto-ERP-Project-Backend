# 02 · UI inventory

> **Source of truth:** the Claude Design canvas "Pluto ERP" (13 module prototypes, about 180 state artboards, the design system).
>
> **Generated file.** The per-page sections come from `docs/source/pages.py`. The APIs, entities and allowed roles for each page are **derived** from the API registry and the role matrix, so they can't drift from 05 and 01. Coverage: <!-- GEN:count -->.

## 1. Design system to preserve

| Element | Specification (from `tokens.json`, `lf-theme.css`, DesignSystem board) |
|---|---|
| Name | "Night Ledger": ink on paper, colour for meaning |
| Themes | Paper (light, default) and Midnight (dark), switched with `data-theme` |
| Typography | **Hanken Grotesk** for the interface; **Newsreader** serif for display and figures; **JetBrains Mono** for codes and numbers. Noto Sans/Serif Bengali as fallback for Bangla. Styles: display-xl 40/44, display-l 28/34, figure-xl 30/36, figure-l 22/28, heading 17/24 650, title-sm 15/20, body 14/20, small 13/18, label 11.5/16 uppercase, amount 14/20 tabular, code 12.5/18 |
| Colour | Paper `#f6f4ef`, surface `#fff`, ink `#13202f`, navy shell `#0e1a2b`, brand teal `#0f7b6c` (one primary action per view), brass accent `#8f6118` (rare), positive/negative/warning/info with soft washes, debit/credit column washes, chart-1…5 |
| Spacing | 4 px rhythm: 4, 8, 12, 16, 20, 24, 32, 48 |
| Radius | 4 (badges), 7 (controls), 10 (cards, tables), 14 (dialogs, drawers), pill |
| Shadows | shadow-1 cards, shadow-2 menus and toasts, shadow-3 dialogs and palette |
| Layout | Sidebar 248 px (compact 68 px), top bar 56 px, rows 40/32 px, content max 1440 px; 12-column grid, 24 px gap |
| Breakpoints | 640 (tables stack), 900 (sidebar becomes a drawer), 1200 (sidebar auto-compacts), 1440 (full grid) |
| Components (36 used) | Sidebar, NavSection, NavItem, NavSubItem, Topbar, Logo, PageHeader, StatTile, Card, DataTable (selectable, totals, row actions, washes, Dr/Cr), Pagination, Tabs, Segmented, Field (label, hint, error, term tooltip), Input (prefix/suffix, mono, align), Select, DateInput, Checkbox, Switch, Button (primary, secondary, ghost, danger; kbd hint), IconButton, Badge (tones, dot), Banner, Toast, Dialog, Drawer, Dropdown, Menu, CommandPalette, EmptyState, Skeleton, Amount, BalanceStatus, Avatar, Tooltip, Icon |
| Page templates | Overview, List, Form, Detail, Report and statement, Voucher, Settings. Navigation goes at most three levels: sidebar module → sub-page → tabs/detail, always reflected in breadcrumbs |
| Interaction rules | Sticky form footer (Cancel, Save draft, one primary). "When you save" effects panel. Live balance check above Save. Plain-language term tooltips. Toasts: say what happened, 5 s, errors persist, at most 3. Skeletons match the final layout. Keyboard paths: Ctrl K, Alt S/P/R/Y/J/C, Ctrl S, Ctrl Enter |

**Rule for development:** React components implement these tokens and components one-to-one (08 §2). No screen is redesigned without explicit approval.

## 2. Page summary

<!-- GEN:summary -->

## 3. Missing screens, ambiguities, duplicates and inconsistencies

The IDs below are the same as in [00 §5](00-Discovery-and-Decisions.md#5-contradictions-gaps-and-risks-found-in-the-design).

### 3.1 Missing screens (needed by the requirements, not in the design)
| ID | Screen | Why it is needed | Proposed placement |
|---|---|---|---|
| S1–S3 (GAP-13) | Sign in, two-factor step, forgot/reset password, accept invitation | Auth flows behind K9 and K14 | Centered card on paper, using the existing Field/Button components |
| K16 (GAP-01) | Accounting defaults (posting-account mappings) | D10 refers to "Settings › Inventory"; every automatic posting needs configurable ledgers | Settings › Business, new row |
| GAP-06 | Import mode of the purchase bill (currency, rate, LC, lot) and "Allocate landed cost" action | LC goods must enter stock with landed cost | Variant of F3; action on the M4 Charges tab |
| GAP-14 | Locations (branches) list and drawer, Units list, LC checklist template editor | Referenced ("2 locations", "Edit templates") but not drawn | Drawer patterns, as D5 |
| — | User profile (name, phone) | Profile menu in the shell | Section in K14 |
| GAP-11 | Cheque book register | Toast mentions a used-up cheque book | Deferred |
| GAP-12 | Delivery challan print | Challan referenced on E5 | Print variant of E4 (`GET /sales-invoices/{id}/pdf?variant=challan`) |

### 3.2 Ambiguities
| Topic | Ambiguity | Resolution proposed |
|---|---|---|
| Expense list (I5) | Mixes EXP- entries with PV- payment vouchers | The list shows posted lines on expense-category ledgers, whatever the voucher type (GAP-16) |
| Store keeper | "Sees no money figures" | Field-level permission `inventory.view_costs` (GAP-15) |
| Price list "Wholesale A" | No price-list screen | Party `price_tier` standard/wholesale picks `selling_price` or `wholesale_price` (AS-12) |
| Prospects | E10 quotes "Nilambari Hotel (prospect)" | Party `status = prospect`; it becomes active on first invoice |
| Approval scope | K11 has Approve on Sales, Purchases, Inventory, Cash and bank, Accounting, but only payments and journals have limits | v1 applies approval to payments, receipts above limit, journals, cash-count differences and stock-adjustment reversals; other Approve ticks are reserved |
| Income and expenses in the roles matrix | No row for I-module or LC (GAP-23) | I-module uses `banking.*`; LC gets its own `lc.*` row, defaulting like Purchases |

### 3.3 Duplicate functionality (intentional reuse vs real duplication)
| Area | Observation | Decision |
|---|---|---|
| Reports J2–J18 | 15 report entries reuse module screens | Keep: one implementation, opened with a Reports breadcrumb |
| Money in/out | G6/G7, H10/H11, E7/F5 and I3/I6 all create receipts or payments | Keep the four entry points; they share **one** backend (`/vouchers`, `/customer-receipts`, `/supplier-payments`, `/ie-entries` all create `journal_entries` + `payment_details`) |
| Cash and bank books | G11/G12 and J9/J10 | Same screen |
| Day book | J1 links to H8 filtered to one date | Same screen with `date_from = date_to` |
| Customers and suppliers | B and C modules mirror each other | One implementation parameterised by `party_type` (as the prototype already does) |
| Sales and purchases | E and F mirror each other | Shared document editor with side-specific columns and rules |

### 3.4 Inconsistencies found
GAP-02 (transfers vs per-warehouse ledgers), GAP-03 (purchase returns ledger), GAP-07 (delete posted voucher), GAP-08 (number series list), GAP-09 (reusing draft numbers), GAP-10 (salesperson grouping), GAP-20 (stock groups vs warehouse ledgers). Each one, with its recommendation, is in [00 §5](00-Discovery-and-Decisions.md#5-contradictions-gaps-and-risks-found-in-the-design).

## 4. Page details

Each entry follows the 13 points of the brief: (1) purpose · (2) components and layout · (3–4) inputs, types, required/optional · (5) actions · (6) tables, columns, filters and search · (7) validation · (8) roles · (9) APIs · (10) entities · (11) workflows · (12) states · (13) dependencies. Columns marked * need `inventory.view_costs`.

<!-- GEN:details -->
