# 02 · UI inventory

> **Source of truth:** the Claude Design canvas "Pluto ERP" (13 module prototypes, about 180 state artboards, the design system).
>
> **Generated file.** The per-page sections come from `docs/source/pages.py`. The APIs, entities and allowed roles for each page are **derived** from the API registry and the role matrix, so they can't drift from 05 and 01. Coverage: 162 page entries (incl. dialogs, 4 new auth/settings screens and 7 new list screens, GAP-24/25) + 15 reused report entries.

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

| Code | Page | Module | Template | View permission | Endpoints | Design source |
|---|---|---|---|---|---|---|
| [S0](#page-s0) | App shell | Shell | Shell | `dashboard.view` | 9 | Shell.dc.html (04) |
| [S1](#page-s1) | Sign in (NEW: not in design) | Shell | Auth | `public` | 2 | — |
| [S2](#page-s2) | Forgot / reset password (NEW) | Shell | Auth | `public` | 2 | — |
| [S3](#page-s3) | Accept invitation (NEW) | Shell | Auth | `public` | 2 | — |
| [A1](#page-a1) | Main dashboard | Dashboard | Overview | `dashboard.view` | 1 | Dashboard.dc.html (03, 05 dark, 06 tablet, 07 phone) |
| [A2](#page-a2) | Financial overview | Dashboard | Lens | `dashboard.view` | 1 | Dash-A2.dc.html |
| [A3](#page-a3) | Sales overview lens | Dashboard | Lens | `dashboard.view` | 1 | Dash-A3.dc.html |
| [A4](#page-a4) | Expense overview lens | Dashboard | Lens | `dashboard.view` | 1 | Dash-A4.dc.html |
| [A5](#page-a5) | Outstanding receivables | Dashboard | Lens | `dashboard.view` | 1 | Dash-A5.dc.html |
| [A6](#page-a6) | Outstanding payables | Dashboard | Lens | `dashboard.view` | 1 | Dash-A6.dc.html |
| [A7](#page-a7) | Cash and bank summary | Dashboard | Lens | `dashboard.view` | 1 | Dash-A7.dc.html |
| [A8](#page-a8) | Inventory alerts | Dashboard | Lens | `dashboard.view` | 1 | Dash-A8.dc.html |
| [B1](#page-b1) | Customer list | Parties | List | `parties.view` | 7 | Parties.dc.html (B1) |
| [B2](#page-b2) | Add customer | Parties | Form | `parties.create` | 4 | Cust-Add.dc.html |
| [B3](#page-b3) | Edit customer | Parties | Form | `parties.edit` | 5 | Cust-Edit.dc.html |
| [B4](#page-b4) | Customer details | Parties | Detail | `parties.view` | 8 | Cust-Details.dc.html |
| [B5](#page-b5) | Customer transaction history | Parties | List | `parties.view` | 4 | Cust-History.dc.html |
| [B6](#page-b6) | Customer ledger | Parties | Ledger | `parties.view` | 3 | Cust-Ledger.dc.html |
| [B7](#page-b7) | Outstanding balances | Parties | Report | `parties.view` | 2 | Cust-Outstanding.dc.html |
| [B8](#page-b8) | Customer statement | Parties | Print | `parties.view` | 4 | Cust-Statement.dc.html |
| [C1](#page-c1) | Supplier list | Parties | List | `parties.view` | 6 | Supp-List.dc.html |
| [C2](#page-c2) | Add supplier | Parties | Form | `parties.create` | 5 | Supp-Add.dc.html |
| [C3](#page-c3) | Edit supplier | Parties | Form | `parties.edit` | 5 | Supp-Edit.dc.html |
| [C4](#page-c4) | Supplier details | Parties | Detail | `parties.view` | 8 | Supp-Details.dc.html |
| [C5](#page-c5) | Supplier transaction history | Parties | List | `parties.view` | 3 | Supp-History.dc.html |
| [C6](#page-c6) | Supplier ledger | Parties | Ledger | `parties.view` | 3 | Supp-Ledger.dc.html |
| [C7](#page-c7) | Outstanding bills | Parties | Report | `parties.view` | 2 | Supp-Outstanding.dc.html |
| [C8](#page-c8) | Supplier statement | Parties | Print | `parties.view` | 4 | Supp-Statement.dc.html |
| [D1](#page-d1) | Product list | Inventory | List | `inventory.view` | 5 | Inventory.dc.html (D1) |
| [D2](#page-d2) | Add product or service | Inventory | Form | `inventory.create` | 7 | Inv-Add.dc.html, Inv-Service.dc.html |
| [D3](#page-d3) | Edit product | Inventory | Form | `inventory.edit` | 4 | Inv-Edit.dc.html |
| [D4](#page-d4) | Product details | Inventory | Detail | `inventory.view` | 1 | Inv-Details.dc.html |
| [D5](#page-d5) | Product categories | Inventory | List | `inventory.view` | 5 | Inv-Categories.dc.html |
| [D6](#page-d6) | Stock groups | Inventory | Tree | `inventory.view` | 4 | Inv-Groups.dc.html |
| [D7](#page-d7) | Warehouse list | Inventory | List | `inventory.view` | 2 | Inv-Warehouses.dc.html |
| [D8](#page-d8) | Warehouse details | Inventory | Detail | `inventory.view` | 6 | Inv-Warehouse.dc.html |
| [D9](#page-d9) | Stock movement history | Inventory | List | `inventory.view` | 3 | Inv-Movements.dc.html |
| [D10](#page-d10) | Stock adjustment | Inventory | Form | `inventory.create` | 10 | Inv-Adjust.dc.html |
| [D11](#page-d11) | Stock transfer | Inventory | Form | `inventory.create` | 10 | Inv-Transfer.dc.html |
| [D12](#page-d12) | Low-stock report | Inventory | Report | `inventory.view` | 4 | Inv-LowStock.dc.html |
| [D13](#page-d13) | Stock valuation report | Inventory | Report | `inventory.view_costs` | 1 | Inv-Valuation.dc.html |
| [D14](#page-d14) | Inventory summary | Inventory | Overview | `inventory.view` | 1 | Inv-Summary.dc.html |
| [E1](#page-e1) | Sales overview | Sales | Overview | `sales.view` | 3 | Sal-Overview.dc.html |
| [E2](#page-e2) | Sales invoice list | Sales | List | `sales.view` | 5 | Trade.dc.html (E2) |
| [E3](#page-e3) | Create sales invoice | Sales | Form | `sales.create` | 10 | Sal-Create.dc.html |
| [E4](#page-e4) | Invoice preview | Sales | Print | `sales.view` | 3 | Sal-Preview.dc.html |
| [E5](#page-e5) | Invoice details | Sales | Detail | `sales.view` | 15 | Sal-Details.dc.html |
| [E6](#page-e6) | Edit draft invoice | Sales | Form | `sales.edit` | 5 | Sal-Edit.dc.html |
| [E7](#page-e7) | Customer payment | Sales | Form | `sales.create` | 6 | Sal-Payment.dc.html |
| [E8](#page-e8) | Sales return | Sales | Form | `sales.create` | 5 | Sal-Return.dc.html |
| [E9](#page-e9) | Credit note | Sales | Form | `sales.create` | 5 | Sal-Credit.dc.html |
| [E10](#page-e10) | Sales quotation | Sales | Form | `sales.create` | 11 | Sal-Quote.dc.html |
| [E11](#page-e11) | Sales report | Sales | Report | `reports.view` | 1 | Sal-Report.dc.html |
| [E12](#page-e12) | Sales return report | Sales | Report | `reports.view` | 1 | Sal-ReturnReport.dc.html |
| [E13](#page-e13) | Quotation list (NEW) | Sales | List | `sales.view` | 7 | — |
| [E14](#page-e14) | Payments received (NEW) | Sales | List | `sales.view` | 6 | — |
| [E15](#page-e15) | Returns and credit notes (NEW) | Sales | List | `sales.view` | 9 | — |
| [Sal-DlgPost](#page-sal-dlgpost) | Dialog · Post invoice | Sales | Dialog | `sales.edit` | 1 | Sal-DlgPost.dc.html |
| [Sal-DlgCancel](#page-sal-dlgcancel) | Dialog · Cancel invoice | Sales | Dialog | `sales.edit` | 1 | Sal-DlgCancel.dc.html |
| [Sal-DlgDelete](#page-sal-dlgdelete) | Dialog · Delete draft | Sales | Dialog | `sales.delete` | 1 | Sal-DlgDelete.dc.html |
| [F1](#page-f1) | Purchase overview | Purchases | Overview | `purchases.view` | 3 | Pur-Overview.dc.html |
| [F2](#page-f2) | Purchase bill list | Purchases | List | `purchases.view` | 3 | Pur-List.dc.html |
| [F3](#page-f3) | Create purchase bill | Purchases | Form | `purchases.create` | 12 | Pur-Create.dc.html |
| [F4](#page-f4) | Purchase bill details | Purchases | Detail | `purchases.view` | 10 | Pur-Details.dc.html |
| [F5](#page-f5) | Supplier payment | Purchases | Form | `purchases.create` | 6 | Pur-Payment.dc.html |
| [F6](#page-f6) | Purchase return | Purchases | Form | `purchases.create` | 5 | Pur-Return.dc.html |
| [F7](#page-f7) | Debit note | Purchases | Form | `purchases.create` | 5 | Pur-Debit.dc.html |
| [F8](#page-f8) | Purchase order | Purchases | Form | `purchases.create` | 11 | Pur-Order.dc.html |
| [F9](#page-f9) | Purchase report | Purchases | Report | `reports.view` | 1 | Pur-Report.dc.html |
| [F10](#page-f10) | Purchase return report | Purchases | Report | `reports.view` | 1 | Pur-ReturnReport.dc.html |
| [F11](#page-f11) | Purchase orders (NEW) | Purchases | List | `purchases.view` | 7 | — |
| [F12](#page-f12) | Payments made (NEW) | Purchases | List | `purchases.view` | 6 | — |
| [F13](#page-f13) | Returns and debit notes (NEW) | Purchases | List | `purchases.view` | 9 | — |
| [Pur-DlgPay](#page-pur-dlgpay) | Dialog · Confirm supplier payment | Purchases | Dialog | `purchases.create` | 1 | Pur-DlgPay.dc.html |
| [G1](#page-g1) | Cash and bank overview | Banking | Overview | `banking.view` | 3 | Banking.dc.html (G1) |
| [G2](#page-g2) | Cash account details | Banking | Detail | `banking.view` | 5 | Bnk-Cash.dc.html |
| [G3](#page-g3) | Bank account list | Banking | List | `banking.view` | 2 | Bnk-Banks.dc.html |
| [G4](#page-g4) | Add bank account | Banking | Form | `banking.create` | 1 | Bnk-AddBank.dc.html |
| [G5](#page-g5) | Bank account details | Banking | Detail | `banking.view` | 5 | Bnk-Bank.dc.html |
| [G6](#page-g6) | Money received | Banking | Form | `banking.create` | 4 | Bnk-Received.dc.html |
| [G7](#page-g7) | Money paid | Banking | Form | `banking.create` | 4 | Bnk-Paid.dc.html |
| [G8](#page-g8) | Cash transfer | Banking | Form | `banking.create` | 3 | Bnk-CashTransfer.dc.html |
| [G9](#page-g9) | Bank transfer | Banking | Form | `banking.create` | 3 | Bnk-BankTransfer.dc.html |
| [G10](#page-g10) | Bank reconciliation | Banking | Workspace | `banking.reconcile` | 9 | Bnk-Reconcile.dc.html |
| [G11](#page-g11) | Cash book | Banking | Ledger | `banking.view` | 3 | Bnk-CashBook.dc.html |
| [G12](#page-g12) | Bank book | Banking | Ledger | `banking.view` | 3 | Bnk-BankBook.dc.html |
| [G13](#page-g13) | Cash and bank transactions | Banking | List | `banking.view` | 1 | Bnk-History.dc.html |
| [I1](#page-i1) | Income overview | IncExp | Overview | `banking.view` | 2 | Inc-Overview.dc.html |
| [I4](#page-i4) | Expense overview | IncExp | Overview | `banking.view` | 2 | IncExp.dc.html |
| [I2](#page-i2) | Income transactions | IncExp | List | `banking.view` | 4 | Inc-List.dc.html |
| [I5](#page-i5) | Expense transactions | IncExp | List | `banking.view` | 6 | Exp-List.dc.html |
| [I3](#page-i3) | Add income | IncExp | Form | `banking.create` | 4 | Inc-Add.dc.html, Exp-AddErr.dc.html |
| [I6](#page-i6) | Add expense | IncExp | Form | `banking.create` | 6 | Exp-Add.dc.html, Exp-AddErr.dc.html |
| [I7](#page-i7) | Expense categories and budgets | IncExp | List | `banking.view` | 4 | Exp-Cats.dc.html |
| [I10](#page-i10) | Recurring entries (NEW) | IncExp | List | `banking.view` | 2 | — |
| [I8](#page-i8) | Income report | IncExp | Report | `reports.financial` | 1 | Inc-Report.dc.html |
| [I9](#page-i9) | Expense report | IncExp | Report | `reports.financial` | 1 | Exp-Report.dc.html |
| [H1](#page-h1) | Accounting overview | Accounting | Overview | `accounting.view` | 3 | Accounting.dc.html (H1) |
| [H2](#page-h2) | Chart of accounts | Accounting | Tree | `accounting.view` | 4 | Acc-COA.dc.html |
| [H3](#page-h3) | Account group details | Accounting | Detail | `accounting.view` | 4 | Acc-Group.dc.html |
| [H4](#page-h4) | Add ledger account | Accounting | Form | `accounting.create` | 2 | Acc-AddLedger.dc.html |
| [H5](#page-h5) | Edit ledger account | Accounting | Form | `accounting.edit` | 3 | Acc-EditLedger.dc.html |
| [H6](#page-h6) | Ledger list | Accounting | List | `accounting.view` | 1 | Acc-Ledgers.dc.html |
| [H7](#page-h7) | Ledger details | Accounting | Ledger | `accounting.view` | 4 | Acc-Ledger.dc.html |
| [H8](#page-h8) | Voucher list | Accounting | List | `accounting.view` | 3 | Acc-Vouchers.dc.html |
| [H9](#page-h9) | Journal voucher | Accounting | Voucher | `accounting.create` | 7 | Acc-Journal.dc.html, Acc-JournalUnbal.dc.html |
| [H10](#page-h10) | Payment voucher | Accounting | Voucher | `accounting.create` | 7 | Acc-Payment.dc.html |
| [H11](#page-h11) | Receipt voucher | Accounting | Voucher | `accounting.create` | 6 | Acc-Receipt.dc.html |
| [H12](#page-h12) | Contra voucher | Accounting | Voucher | `accounting.create` | 6 | Acc-Contra.dc.html |
| [H13](#page-h13) | Voucher details | Accounting | Detail | `accounting.view` | 10 | Acc-Voucher.dc.html |
| [Acc-DlgReverse](#page-acc-dlgreverse) | Dialog · Reverse voucher | Accounting | Dialog | `accounting.create` | 1 | Acc-DlgReverse.dc.html |
| [H14](#page-h14) | Printable voucher | Accounting | Print | `accounting.view` | 2 | Acc-Print.dc.html |
| [H15](#page-h15) | Trial balance | Reports | Statement | `reports.financial` | 1 | Rpt-TB.dc.html |
| [H16](#page-h16) | Profit and loss | Reports | Statement | `reports.financial` | 1 | Rpt-PL.dc.html |
| [H17](#page-h17) | Balance sheet | Reports | Statement | `reports.financial` | 1 | Rpt-BS.dc.html |
| [H18](#page-h18) | Cash flow statement | Reports | Statement | `reports.financial` | 1 | Rpt-CF.dc.html |
| [H19](#page-h19) | General ledger | Reports | Statement | `reports.financial` | 1 | Rpt-GL.dc.html |
| [K16](#page-k16) | Accounting defaults (NEW: GAP-01) | Accounting | Settings | `settings.edit` | 4 | — |
| [J1](#page-j1) | Reports centre | Reports | Hub | `reports.view` | 5 | Statements.dc.html (J1) |
| [J6](#page-j6) | Receivables aging | Reports | Report | `reports.financial` | 2 | Rpt-AR.dc.html |
| [J7](#page-j7) | Payables aging | Reports | Report | `reports.financial` | 2 | Rpt-AP.dc.html |
| [K1](#page-k1) | Company profile | Settings | Detail | `settings.view` | 4 | Settings.dc.html (K1) |
| [K2](#page-k2) | Edit company | Settings | Form | `settings.edit` | 3 | Settings.dc.html (K2) |
| [K3](#page-k3) | Business preferences | Settings | Settings | `settings.edit` | 5 | Settings.dc.html (K3) |
| [K4](#page-k4) | Financial-year settings | Settings | Settings | `settings.view` | 4 | Settings.dc.html (K4) |
| [K5](#page-k5) | Currency and region | Settings | Settings | `settings.edit` | 2 | Settings.dc.html (K5) |
| [K6](#page-k6) | Tax configuration | Settings | Settings | `settings.view` | 6 | Settings.dc.html (K6) |
| [K7](#page-k7) | Invoice customization | Settings | Settings | `settings.edit` | 2 | Settings.dc.html (K7) |
| [K8](#page-k8) | User list | Settings | List | `settings.users` | 2 | Settings.dc.html (K8) |
| [K9](#page-k9) | Invite user | Settings | Dialog | `settings.users` | 2 | Settings.dc.html (K9) |
| [K10](#page-k10) | User details | Settings | Detail | `settings.users` | 7 | Settings.dc.html (K10) |
| [K11](#page-k11) | Roles and permissions | Settings | Matrix | `settings.roles` | 6 | Settings.dc.html (K11) |
| [K12](#page-k12) | Notification preferences | Settings | Settings | `auth.self` | 2 | Settings.dc.html (K12) |
| [K13](#page-k13) | Appearance | Settings | Settings | `auth.self` | 2 | Settings.dc.html (K13) |
| [K14](#page-k14) | Security | Settings | Settings | `auth.self` | 9 | Settings.dc.html (K14) |
| [K15](#page-k15) | Backup and export | Settings | Settings | `settings.backup` | 8 | Settings.dc.html (K15) |
| [L1](#page-l1) | Global search results | States | List | `auth.self` | 1 | States.dc.html (L1) |
| [L2](#page-l2) | Command palette | States | Overlay | `auth.self` | 1 | States.dc.html (L2) |
| [L3](#page-l3) | Notifications center | States | List | `auth.self` | 4 | States.dc.html (L3) |
| [L4](#page-l4) | Notification detail drawer | States | Drawer | `accounting.approve` | 4 | States.dc.html (L4) |
| [L5](#page-l5) | Quick-create menu | States | Overlay | `auth.self` | 2 | States.dc.html (L5) |
| [L6](#page-l6) | Confirmation dialog | States | Dialog | `auth.self` | 1 | States.dc.html (L6) |
| [L7](#page-l7) | Delete confirmation | States | Dialog | `auth.self` | 1 | States.dc.html (L7) |
| [L8](#page-l8) | Validation errors | States | State | `auth.self` | 0 | States.dc.html (L8) |
| [L9](#page-l9) | Empty states | States | State | `auth.self` | 0 | States.dc.html (L9) |
| [L10](#page-l10) | Loading states | States | State | `auth.self` | 0 | States.dc.html (L10) |
| [L11](#page-l11) | Error states | States | State | `auth.self` | 0 | States.dc.html (L11) |
| [L12](#page-l12) | Success and other toasts | States | Toast | `auth.self` | 0 | States.dc.html (L12) |
| [L13](#page-l13) | Unsaved changes | States | Dialog | `auth.self` | 0 | States.dc.html (L13) |
| [L14](#page-l14) | Access denied | States | State | `auth.self` | 0 | States.dc.html (L14) |
| [L15](#page-l15) | Not found | States | State | `auth.self` | 0 | States.dc.html (L15) |
| [M1](#page-m1) | LC dashboard | LC | Overview | `lc.view` | 1 | LC.dc.html (M1) |
| [M2](#page-m2) | LC list | LC | List | `lc.view` | 1 | LC-List.dc.html |
| [M3](#page-m3) | Create LC | LC | Form | `lc.create` | 9 | LC-Create*.dc.html |
| [M4](#page-m4) | LC details | LC | Detail | `lc.view` | 26 | LC-Details.dc.html + LC-Det*.dc.html (11) |
| [M5](#page-m5) | LC amendments | LC | List | `lc.view` | 4 | LC-Amendments.dc.html |
| [M6](#page-m6) | LC documents | LC | Matrix | `lc.view` | 5 | LC-Documents.dc.html |
| [M7](#page-m7) | Charges and cost summary | LC | Report | `lc.view` | 9 | LC-Charges.dc.html |
| [M8](#page-m8) | LC reports | LC | Report | `lc.view` | 2 | LC-Rpt*.dc.html (8) |
| [LC-DlgPay](#page-lc-dlgpay) | Dialog · Record LC payment | LC | Dialog | `lc.create` | 2 | LC-DlgPay.dc.html |
| [LC-DlgClose](#page-lc-dlgclose) | Dialog · Close LC | LC | Dialog | `lc.edit` | 1 | LC-DlgClose.dc.html |
| [LC-DrawerCharge](#page-lc-drawercharge) | Drawer · Add charge or import expense | LC | Drawer | `lc.create` | 1 | LC-DrawerCharge.dc.html |
| J2 | Sales report | Reports | → E11 | as E11 | — | reused |
| J3 | Purchase report | Reports | → F9 | as F9 | — | reused |
| J4 | Income report | Reports | → I8 | as I8 | — | reused |
| J5 | Expense report | Reports | → I9 | as I9 | — | reused |
| J8 | Cash flow report | Reports | → H18 | as H18 | — | reused |
| J9 | Cash book | Reports | → G11 | as G11 | — | reused |
| J10 | Bank book | Reports | → G12 | as G12 | — | reused |
| J11 | Inventory report | Reports | → D14 | as D14 | — | reused |
| J12 | Stock valuation | Reports | → D13 | as D13 | — | reused |
| J13 | Trial balance | Reports | → H15 | as H15 | — | reused |
| J14 | Profit and loss | Reports | → H16 | as H16 | — | reused |
| J15 | Balance sheet | Reports | → H17 | as H17 | — | reused |
| J16 | General ledger | Reports | → H19 | as H19 | — | reused |
| J17 | Customer statement | Reports | → B8 | as B8 | — | reused |
| J18 | Supplier statement | Reports | → C8 | as C8 | — | reused |

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


### Shell


<a id="page-s0"></a>
#### S0 · App shell

1. **Purpose:** Persistent navigation, company and FY context, quick create, search and notifications on every page.
2. **Components and layout:** App shell: sidebar (company switcher, module nav with badges), top bar (breadcrumbs, search/Ctrl K, FY selector, quick-create, help, notifications, profile). Responsive: icon rail 900–1199 px, drawer < 900 px. _Source: Shell.dc.html (04)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Company switcher | select | req | Only companies where the membership is active |
   | Financial-year selector | select | req | Open and closed FYs; 'Custom period…' sets report range |
5. **Actions → API** ([full map](05-API-Specification.md#actions-s0)): Switch company (reloads all data) → GET /me → GET /company/settings → GET /fiscal-years (with the new X-Company-Id)<br>Open command palette (Ctrl K) → dlg:L2<br>Quick create menu: Sales invoice Alt S, Purchase bill Alt P, Receipt Alt R, Payment Alt Y, Contra Alt C, Journal Alt J, Customer, Supplier, Product → dlg:L5<br>Open notifications → GET /notifications<br>Profile menu: profile, security, sign out → nav:K14 \| POST /auth/logout<br>Create a company (NEW, company switcher) → POST /companies → GET /me
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /auth/logout`](05-API-Specification.md#postauthlogout), [`GET /me`](05-API-Specification.md#getme), [`POST /companies`](05-API-Specification.md#postcompanies), [`GET /company/settings`](05-API-Specification.md#getcompanysettings), [`GET /branches`](05-API-Specification.md#getbranches), [`GET /fiscal-years`](05-API-Specification.md#getfiscalyears), [`GET /search`](05-API-Specification.md#getsearch), [`GET /notifications`](05-API-Specification.md#getnotifications), [`GET /warehouses`](05-API-Specification.md#getwarehouses)
10. **Entities:** `account_groups`, `account_mappings`, `accounting_periods`, `audit_logs`, `branches`, `companies`, `company_settings`, `company_users`, `fiscal_years`, `journal_entries`, `ledgers`, `letters_of_credit`, `notifications`, `number_sequences`, `parties`, `payment_terms`, `permissions`, `product_warehouse_settings`, `products`, `purchase_bills`, `role_permissions`, `roles`, `sales_invoices`, `stock_balances`, `user_sessions`, `users`, `warehouses`
11. **Workflows:** Find anything fast · FR: FR-005, FR-012, FR-013
12. **States:** Offline banner; notification badge updates.
13. **Depends on:** [L2](#page-l2), [L3](#page-l3), [L5](#page-l5)

<a id="page-s1"></a>
#### S1 · Sign in (NEW: not in design)

1. **Purpose:** Authenticate with e-mail and password; second step asks the TOTP or a recovery code when 2-step is on.
2. **Components and layout:** Centered card on paper background using the Pluto logo, Field, Input and Button components.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Email | text | req | Valid e-mail |
   | Password | password | req |  |
   | Authenticator code | text | cond | 6 digits; shown only after step 1 when 2FA is on |
5. **Actions → API** ([full map](05-API-Specification.md#actions-s1)): Sign in → POST /auth/login<br>Use a recovery code → POST /auth/two-factor<br>Forgot password → nav:S2
6. **Tables:** —
7. **Validation and rules:** Generic error 'E-mail or password is incorrect' (no account enumeration)<br>Lockout message after 5 failures shows the retry time<br>BR-061: Deactivating a user or changing their password revokes all their sessions immediately.
8. **Roles allowed:** `public` → (anyone). Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /auth/login`](05-API-Specification.md#postauthlogin), [`POST /auth/two-factor`](05-API-Specification.md#postauthtwofactor)
10. **Entities:** `audit_logs`, `company_users`, `user_sessions`, `users`
11. **Workflows:** — · FR: FR-001
12. **States:** Busy button; generic errors; lockout timer.
13. **Depends on:** — · Note: GAP-13

<a id="page-s2"></a>
#### S2 · Forgot / reset password (NEW)

1. **Purpose:** Request a reset link and set a new password from the link.
2. **Components and layout:** Same card layout as S1.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Email | text | req |  |
   | New password | password | req | >= 10 chars, not breached |
   | Confirm password | password | req | Must match |
5. **Actions → API** ([full map](05-API-Specification.md#actions-s2)): Send link → POST /auth/password/forgot<br>Set password → POST /auth/password/reset
6. **Tables:** —
7. **Validation and rules:** Always shows 'If the address exists, we sent a link'
8. **Roles allowed:** `public` → (anyone). Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /auth/password/forgot`](05-API-Specification.md#postauthpasswordforgot), [`POST /auth/password/reset`](05-API-Specification.md#postauthpasswordreset)
10. **Entities:** `password_reset_tokens`, `user_sessions`, `users`
11. **Workflows:** — · FR: FR-003
12. **States:** Busy button; generic errors; lockout timer.
13. **Depends on:** — · Note: GAP-13

<a id="page-s3"></a>
#### S3 · Accept invitation (NEW)

1. **Purpose:** Invited user sets name and password and joins the company.
2. **Components and layout:** Card layout.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Name | text | req |  |
   | Password | password | req | as S2 |
5. **Actions → API** ([full map](05-API-Specification.md#actions-s3)): Join company → POST /invitations/{token}/accept
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `public` → (anyone). Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /invitations/{token}`](05-API-Specification.md#getinvitationstoken), [`POST /invitations/{token}/accept`](05-API-Specification.md#postinvitationstokenaccept)
10. **Entities:** `companies`, `company_user_branches`, `company_users`, `roles`, `user_invitation_branches`, `user_invitations`, `users`
11. **Workflows:** — · FR: FR-006
12. **States:** Busy button; generic errors; lockout timer.
13. **Depends on:** — · Note: GAP-13

### Dashboard


<a id="page-a1"></a>
#### A1 · Main dashboard

1. **Purpose:** Morning view of the business: KPIs, revenue vs expenses, cash, money owed, upcoming payments, quick actions, recent transactions, inventory alerts.
2. **Components and layout:** Greeting header with period segmented control and two actions; reconciliation banner; 4 KPI StatTiles; 12-month bar chart with hover; cash position list; money-owed aging bars; upcoming payments; quick actions; recent transactions table; inventory alerts. _Source: Dashboard.dc.html (03, 05 dark, 06 tablet, 07 phone)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Period | segmented | req | This month / quarter / FY to date |
5. **Actions → API** ([full map](05-API-Specification.md#actions-a1)): New invoice (Alt S) → nav:E3<br>Record receipt → nav:E7<br>Reconcile (from banner) → nav:G10<br>Dismiss banner → ui:hide the banner for this session (sessionStorage)<br>Open any tile or row (deep link) → nav:*
6. **Table:** columns Date, Voucher, Type, Party, Amount (৳); server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /dashboard`](05-API-Specification.md#getdashboard)
10. **Entities:** `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `product_warehouse_settings`, `purchase_bills`, `sales_invoices`, `stock_balances`
11. **Workflows:** Bill a customer, Pay a supplier · FR: FR-010
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [A2](#page-a2), [A5](#page-a5), [A6](#page-a6), [A7](#page-a7), [A8](#page-a8), [E3](#page-e3), [G10](#page-g10)

<a id="page-a2"></a>
#### A2 · Financial overview

1. **Purpose:** Condensed P&L share of revenue, net profit by month, key ratios (gross/net margin, current ratio, debtor and creditor days).
2. **Components and layout:** Same shell and page header as A1 with lens sub-navigation; KPI tiles, one chart or bar list, one table linking onward. _Source: Dash-A2.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-a2)): Open row (deep link) → nav:*<br>View all → nav:H16
6. **Table:** columns per lens; filters Period; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /dashboard/lenses/{lens}`](05-API-Specification.md#getdashboardlenseslens)
10. **Entities:** `ie_categories`, `journal_lines`, `money_accounts`, `parties`, `product_warehouse_settings`, `purchase_bills`, `sales_invoices`, `stock_balances`
11. **Workflows:** — · FR: FR-011
12. **States:** As Overview.
13. **Depends on:** [H16](#page-h16), [H17](#page-h17)

<a id="page-a3"></a>
#### A3 · Sales overview lens

1. **Purpose:** Sales by category bars and top customers table.
2. **Components and layout:** Same shell and page header as A1 with lens sub-navigation; KPI tiles, one chart or bar list, one table linking onward. _Source: Dash-A3.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-a3)): Open row (deep link) → nav:*<br>View all → nav:E11
6. **Table:** columns per lens; filters Period; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /dashboard/lenses/{lens}`](05-API-Specification.md#getdashboardlenseslens)
10. **Entities:** `ie_categories`, `journal_lines`, `money_accounts`, `parties`, `product_warehouse_settings`, `purchase_bills`, `sales_invoices`, `stock_balances`
11. **Workflows:** — · FR: FR-011
12. **States:** As Overview.
13. **Depends on:** [E1](#page-e1), [E11](#page-e11)

<a id="page-a4"></a>
#### A4 · Expense overview lens

1. **Purpose:** Expenses by category against budget.
2. **Components and layout:** Same shell and page header as A1 with lens sub-navigation; KPI tiles, one chart or bar list, one table linking onward. _Source: Dash-A4.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-a4)): Open row (deep link) → nav:*<br>View all → nav:I9
6. **Table:** columns per lens; filters Period; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /dashboard/lenses/{lens}`](05-API-Specification.md#getdashboardlenseslens)
10. **Entities:** `ie_categories`, `journal_lines`, `money_accounts`, `parties`, `product_warehouse_settings`, `purchase_bills`, `sales_invoices`, `stock_balances`
11. **Workflows:** — · FR: FR-011
12. **States:** As Overview.
13. **Depends on:** [I4](#page-i4), [I9](#page-i9)

<a id="page-a5"></a>
#### A5 · Outstanding receivables

1. **Purpose:** Aging buckets and customers with balances; select to send statements.
2. **Components and layout:** Same shell and page header as A1 with lens sub-navigation; KPI tiles, one chart or bar list, one table linking onward. _Source: Dash-A5.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-a5)): Open row (deep link) → nav:*<br>View all → nav:B7
6. **Table:** columns per lens; filters Period; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /dashboard/lenses/{lens}`](05-API-Specification.md#getdashboardlenseslens)
10. **Entities:** `ie_categories`, `journal_lines`, `money_accounts`, `parties`, `product_warehouse_settings`, `purchase_bills`, `sales_invoices`, `stock_balances`
11. **Workflows:** — · FR: FR-011
12. **States:** As Overview.
13. **Depends on:** [B7](#page-b7), [J6](#page-j6)

<a id="page-a6"></a>
#### A6 · Outstanding payables

1. **Purpose:** Aging buckets and suppliers; upcoming bills.
2. **Components and layout:** Same shell and page header as A1 with lens sub-navigation; KPI tiles, one chart or bar list, one table linking onward. _Source: Dash-A6.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-a6)): Open row (deep link) → nav:*<br>View all → nav:C7
6. **Table:** columns per lens; filters Period; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /dashboard/lenses/{lens}`](05-API-Specification.md#getdashboardlenseslens)
10. **Entities:** `ie_categories`, `journal_lines`, `money_accounts`, `parties`, `product_warehouse_settings`, `purchase_bills`, `sales_invoices`, `stock_balances`
11. **Workflows:** — · FR: FR-011
12. **States:** As Overview.
13. **Depends on:** [C7](#page-c7), [J7](#page-j7)

<a id="page-a7"></a>
#### A7 · Cash and bank summary

1. **Purpose:** 30-day combined balance, accounts with reconciliation status, recent movements.
2. **Components and layout:** Same shell and page header as A1 with lens sub-navigation; KPI tiles, one chart or bar list, one table linking onward. _Source: Dash-A7.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-a7)): Open row (deep link) → nav:*<br>View all → nav:G1
6. **Table:** columns per lens; filters Period; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /dashboard/lenses/{lens}`](05-API-Specification.md#getdashboardlenseslens)
10. **Entities:** `ie_categories`, `journal_lines`, `money_accounts`, `parties`, `product_warehouse_settings`, `purchase_bills`, `sales_invoices`, `stock_balances`
11. **Workflows:** — · FR: FR-011
12. **States:** As Overview.
13. **Depends on:** [G1](#page-g1), [G10](#page-g10)

<a id="page-a8"></a>
#### A8 · Inventory alerts

1. **Purpose:** Items below reorder level (products only), stock value by warehouse.
2. **Components and layout:** Same shell and page header as A1 with lens sub-navigation; KPI tiles, one chart or bar list, one table linking onward. _Source: Dash-A8.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-a8)): Open row (deep link) → nav:*<br>View all → nav:D12
6. **Table:** columns per lens; filters Period; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `dashboard.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /dashboard/lenses/{lens}`](05-API-Specification.md#getdashboardlenseslens)
10. **Entities:** `ie_categories`, `journal_lines`, `money_accounts`, `parties`, `product_warehouse_settings`, `purchase_bills`, `sales_invoices`, `stock_balances`
11. **Workflows:** — · FR: FR-011
12. **States:** As Overview.
13. **Depends on:** [D12](#page-d12), [F8](#page-f8)

### Parties


<a id="page-b1"></a>
#### B1 · Customer list

1. **Purpose:** Every customer with contact, terms, receivable balance and status.
2. **Components and layout:** Page header with Import/Export/New; 4 summary tiles; toolbar (search, status segmented All/With balance/Overdue, area select, sort); bulk bar on selection; selectable DataTable; pagination. _Source: Parties.dc.html (B1)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | name, code or mobile |
   | Status | segmented | opt | all, balance, overdue |
   | Area | select | opt | district |
   | Sort | select | opt | balance desc (default), name, recent |
5. **Actions → API** ([full map](05-API-Specification.md#actions-b1)): New customer → nav:B2<br>Open row → nav:B4<br>Edit (row) → nav:B3<br>Send statements (bulk) → POST /parties/statements/send<br>Export (bulk) → export:GET /parties<br>Mark inactive (bulk) → dlg:L6 → POST /parties/bulk-deactivate
6. **Table:** columns Customer, Contact, Mobile, Area, Terms, Receivable (৳), Status; filters status, area; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /parties`](05-API-Specification.md#getparties), [`POST /parties/{id}/deactivate`](05-API-Specification.md#postpartiesiddeactivate), [`POST /parties/bulk-deactivate`](05-API-Specification.md#postpartiesbulkdeactivate), [`POST /parties/statements/send`](05-API-Specification.md#postpartiesstatementssend), [`GET /party-groups`](05-API-Specification.md#getpartygroups)
10. **Entities:** `audit_logs`, `export_jobs`, `ledgers`, `parties`, `party_groups`, `payment_terms`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-020
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [B2](#page-b2), [B4](#page-b4)

<a id="page-b2"></a>
#### B2 · Add customer

1. **Purpose:** Create a customer; its ledger is created under 1201 Sundry debtors.
2. **Components and layout:** Grouped cards: Basic details, Contact, Accounting, Notes; right 'Before you save' checklist; sticky footer Cancel / Save and add another / Save. _Source: Cust-Add.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Name | text | req | As on documents; max 190 |
   | Customer code | readonly | — | Generated CUS-NNNN |
   | Type | select | opt | Business, Individual, Walk-in counter |
   | Customer group | select | opt |  |
   | Contact person | text | opt |  |
   | Mobile number | text | req | BR-063 (+88 prefix shown) |
   | Email | text | opt | valid e-mail |
   | Alternate number | text | opt |  |
   | Address | text | opt |  |
   | District | select | opt |  |
   | Area or thana | text | opt |  |
   | Opening balance | money | opt | >= 0; as of FY start; locked after first document (BR-028) |
   | Balance type | segmented | req | They owe you (Dr) / Advance received (Cr) |
   | Payment terms | select | opt | default company terms |
   | Credit limit | money | opt | >= 0; warns before invoicing past it |
   | Control ledger | readonly | — | group the ledger rolls up to |
   | Tax registration number | text | opt |  |
   | Notes | textarea | opt | team only |
5. **Actions → API** ([full map](05-API-Specification.md#actions-b2)): Cancel (unsaved-changes dialog if dirty) → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save and add another → POST /parties → ui:reset the form<br>Save (Ctrl S) → POST /parties → nav:B4<br>Add group inline (NEW) → POST /party-groups
6. **Tables:** —
7. **Validation and rules:** Name required: 'Enter the customer's name'<br>Mobile required and 11 digits starting 01: 'Enter 11 digits starting 01, e.g. 01711-284530'<br>Banner 'Fix N fields to save this customer' listing errors<br>Possible-duplicate warning when name or mobile matches an existing party (non-blocking)<br>BR-063: Local party mobile numbers have 11 digits starting 01 (01XXX-XXXXXX); e-mail addresses must be valid when given.<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.
8. **Roles allowed:** `parties.create` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /payment-terms`](05-API-Specification.md#getpaymentterms), [`POST /parties`](05-API-Specification.md#postparties), [`GET /party-groups`](05-API-Specification.md#getpartygroups), [`POST /party-groups`](05-API-Specification.md#postpartygroups)
10. **Entities:** `account_mappings`, `audit_logs`, `journal_entries`, `journal_lines`, `ledgers`, `number_sequences`, `parties`, `party_bank_accounts`, `party_groups`, `payment_terms`
11. **Workflows:** — · FR: FR-021
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [B1](#page-b1), [H2](#page-h2)

<a id="page-b3"></a>
#### B3 · Edit customer

1. **Purpose:** Edit a customer. Opening balance is read-only once documents exist; shows current balance and audit line.
2. **Components and layout:** Same form as B2 with info banner, 'Make inactive' zone, current balance in the aside, 'Unsaved changes' badge. _Source: Cust-Edit.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Same as B2 | — | — | opening balance read-only |
5. **Actions → API** ([full map](05-API-Specification.md#actions-b3)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes (If-Match) → PATCH /parties/{id}<br>Make inactive → dlg:L6 → POST /parties/{id}/deactivate \| POST /parties/{id}/reactivate<br>Delete (L7 confirmation, only without history) → dlg:L7 → DELETE /parties/{id}
6. **Tables:** —
7. **Validation and rules:** As B2<br>409 conflict banner: 'Someone else changed this record' with reload<br>BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.
8. **Roles allowed:** `parties.edit` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties/{id}`](05-API-Specification.md#getpartiesid), [`PATCH /parties/{id}`](05-API-Specification.md#patchpartiesid), [`POST /parties/{id}/deactivate`](05-API-Specification.md#postpartiesiddeactivate), [`POST /parties/{id}/reactivate`](05-API-Specification.md#postpartiesidreactivate), [`DELETE /parties/{id}`](05-API-Specification.md#deletepartiesid)
10. **Entities:** `audit_logs`, `journal_entries`, `journal_lines`, `ledgers`, `parties`, `party_bank_accounts`
11. **Workflows:** — · FR: FR-021, FR-025
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [B4](#page-b4)

<a id="page-b4"></a>
#### B4 · Customer details

1. **Purpose:** Everything about one customer: KPIs, open invoices, 6-month trend, contact, terms, credit used and activity.
2. **Components and layout:** Identity header (initials, name, status badge, code, contact) with Edit / Statement / New invoice; tabs Overview · History · Ledger; 4 StatTiles; open documents card with Record payment; monthly bars; aside. _Source: Cust-Details.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-b4)): Edit → nav:B3<br>Statement → nav:B8<br>New invoice (Alt S) → nav:E3<br>Record payment → nav:E7<br>Switch tab → GET /parties/{id}/transactions \| GET /parties/{id}/ledger \| GET /audit-logs<br>Apply advance or credit (NEW, R-08) → GET /parties/{id}/open-documents → POST /allocations<br>Remove allocation (NEW, R-08) → dlg:L6 → DELETE /allocations/{id}
6. **Table:** columns Invoice, Date, Due, Status, Amount (৳), Paid (৳), Balance (৳); server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /audit-logs`](05-API-Specification.md#getauditlogs), [`GET /parties/{id}`](05-API-Specification.md#getpartiesid), [`GET /parties/{id}/open-documents`](05-API-Specification.md#getpartiesidopendocuments), [`GET /parties/{id}/transactions`](05-API-Specification.md#getpartiesidtransactions), [`GET /parties/{id}/ledger`](05-API-Specification.md#getpartiesidledger), [`POST /customer-receipts`](05-API-Specification.md#postcustomerreceipts), [`POST /allocations`](05-API-Specification.md#postallocations), [`DELETE /allocations/{id}`](05-API-Specification.md#deleteallocationsid)
10. **Entities:** `audit_logs`, `bill_allocations`, `credit_notes`, `debit_notes`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `parties`, `party_bank_accounts`, `payment_details`, `purchase_bills`, `purchase_orders`, `purchase_returns`, `quotations`, `sales_invoices`, `sales_returns`, `users`
11. **Workflows:** — · FR: FR-022
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [B5](#page-b5), [B6](#page-b6), [B8](#page-b8), [E7](#page-e7)

<a id="page-b5"></a>
#### B5 · Customer transaction history

1. **Purpose:** All documents of the customer.
2. **Components and layout:** Detail header + History tab: search, document type select, date range, export; table; pagination. _Source: Cust-History.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | number or reference |
   | Document type | select | opt | Invoices, Receipts, Credit notes, Returns |
   | From / to | date | opt | to >= from |
5. **Actions → API** ([full map](05-API-Specification.md#actions-b5)): Open document → nav:*<br>Export → export:GET /parties/{id}/transactions
6. **Table:** columns Date, Number, Type, Reference, Due, Amount (৳), Status; filters type, date; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /parties/{id}/transactions`](05-API-Specification.md#getpartiesidtransactions), [`GET /sales-invoices`](05-API-Specification.md#getsalesinvoices)
10. **Entities:** `credit_notes`, `debit_notes`, `export_jobs`, `journal_entries`, `parties`, `purchase_bills`, `purchase_orders`, `purchase_returns`, `quotations`, `sales_invoices`, `sales_returns`
11. **Workflows:** — · FR: FR-023
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [B4](#page-b4)

<a id="page-b6"></a>
#### B6 · Customer ledger

1. **Purpose:** The customer's ledger with opening, debits, credits and running Dr/Cr balance.
2. **Components and layout:** Date range, detailed/summary density, print, export; summary strip (opening, invoiced, received, closing); ledger table with debit/credit washes. _Source: Cust-Ledger.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From / to | date | req | default current month to date |
   | Density | segmented | opt | detailed/summary |
5. **Actions → API** ([full map](05-API-Specification.md#actions-b6)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /parties/{id}/ledger<br>Open voucher → nav:*
6. **Table:** columns Date, Voucher, Particulars, Debit (৳), Credit (৳), Balance (৳); filters date; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /parties/{id}/ledger`](05-API-Specification.md#getpartiesidledger)
10. **Entities:** `export_jobs`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-023
12. **States:** As Report; opening and closing always shown, even with no entries.
13. **Depends on:** [H7](#page-h7)

<a id="page-b7"></a>
#### B7 · Outstanding balances

1. **Purpose:** Open invoices across customers by age bucket (not due, 1–30, 31–60, 61–90, 90+).
2. **Components and layout:** Bucket buttons (total + 5) acting as filters; segmented filter; search; selectable table with totals and row actions (statement, record payment). _Source: Cust-Outstanding.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-b7)): Filter by bucket → GET /parties/outstanding<br>Send reminders/statements (bulk) → POST /parties/statements/send<br>Record payment (row) → nav:E7
6. **Table:** columns Customer, Invoice, Date, Due, Age, Balance (৳); filters bucket; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties/outstanding`](05-API-Specification.md#getpartiesoutstanding), [`POST /parties/statements/send`](05-API-Specification.md#postpartiesstatementssend)
10. **Entities:** `audit_logs`, `export_jobs`, `parties`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-023
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [J6](#page-j6)

<a id="page-b8"></a>
#### B8 · Customer statement

1. **Purpose:** Printable statement of account (A4) with summary, ledger lines and aging.
2. **Components and layout:** Toolbar (period, aging checkbox, PDF, Print, E-mail); paper-white statement with company header, party block, summary, compact ledger, aging row and footer. _Source: Cust-Statement.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From / to | date | req |  |
   | Show ageing summary | checkbox | opt | default on |
5. **Actions → API** ([full map](05-API-Specification.md#actions-b8)): PDF → GET /parties/{id}/statement/pdf<br>Print → GET /parties/{id}/statement/pdf → ui:print<br>Email to customer → POST /parties/statements/send
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties/{id}/ledger`](05-API-Specification.md#getpartiesidledger), [`GET /parties/{id}/statement`](05-API-Specification.md#getpartiesidstatement), [`GET /parties/{id}/statement/pdf`](05-API-Specification.md#getpartiesidstatementpdf), [`POST /parties/statements/send`](05-API-Specification.md#postpartiesstatementssend)
10. **Entities:** `audit_logs`, `companies`, `export_jobs`, `journal_entries`, `journal_lines`, `ledgers`, `parties`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-024
12. **States:** Loading preview skeleton; PDF generation toast; print uses the browser dialog.
13. **Depends on:** [B6](#page-b6)

<a id="page-c1"></a>
#### C1 · Supplier list

1. **Purpose:** Every supplier with contact, terms, payable balance and status.
2. **Components and layout:** Page header with Import/Export/New; 4 summary tiles; toolbar (search, status segmented All/With balance/Overdue, area select, sort); bulk bar on selection; selectable DataTable; pagination. _Source: Supp-List.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | name, code or mobile |
   | Status | segmented | opt | all, balance, overdue |
   | Area | select | opt | district |
   | Sort | select | opt | balance desc (default), name, recent |
5. **Actions → API** ([full map](05-API-Specification.md#actions-c1)): New supplier → nav:C2<br>Open row → nav:C4<br>Edit (row) → nav:C3<br>Send statements (bulk) → POST /parties/statements/send<br>Export (bulk) → export:GET /parties<br>Mark inactive (bulk) → dlg:L6 → POST /parties/bulk-deactivate
6. **Table:** columns Supplier, Contact, Mobile, Area, Terms, Payable (৳), Status; filters status, area; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /parties`](05-API-Specification.md#getparties), [`POST /parties/{id}/deactivate`](05-API-Specification.md#postpartiesiddeactivate), [`POST /parties/bulk-deactivate`](05-API-Specification.md#postpartiesbulkdeactivate), [`POST /parties/statements/send`](05-API-Specification.md#postpartiesstatementssend)
10. **Entities:** `audit_logs`, `export_jobs`, `ledgers`, `parties`, `party_groups`, `payment_terms`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-020
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [C2](#page-c2), [C4](#page-c4)

<a id="page-c2"></a>
#### C2 · Add supplier

1. **Purpose:** Create a supplier; its ledger is created under 2101 Sundry creditors.
2. **Components and layout:** Grouped cards: Basic details, Contact, Accounting, Payment details, Notes; right 'Before you save' checklist; sticky footer Cancel / Save and add another / Save. _Source: Supp-Add.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Name | text | req | As on documents; max 190 |
   | Supplier code | readonly | — | Generated SUP-NNNN |
   | Type | select | opt | Manufacturer, Distributor, Service provider, Individual |
   | Supplier group | select | opt |  |
   | Contact person | text | opt |  |
   | Mobile number | text | req | BR-063 (+88 prefix shown) |
   | Email | text | opt | valid e-mail |
   | Alternate number | text | opt |  |
   | Address | text | opt |  |
   | District | select | opt |  |
   | Area or thana | text | opt |  |
   | Opening balance | money | opt | >= 0; as of FY start; locked after first document (BR-028) |
   | Balance type | segmented | req | Advance paid (Dr) / You owe them (Cr) |
   | Payment terms | select | opt | default company terms |
   | Default purchase account | select | opt | expense/purchase ledger for non-stock lines |
   | Bank | select | opt |  |
   | Account name | text | opt |  |
   | Account number | text | opt |  |
   | Branch | text | opt |  |
   | Control ledger | readonly | — | group the ledger rolls up to |
   | Tax registration number | text | opt |  |
   | Notes | textarea | opt | team only |
5. **Actions → API** ([full map](05-API-Specification.md#actions-c2)): Cancel (unsaved-changes dialog if dirty) → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save and add another → POST /parties → ui:reset the form<br>Save (Ctrl S) → POST /parties → nav:C4<br>Add group inline (NEW) → POST /party-groups
6. **Tables:** —
7. **Validation and rules:** Name required: 'Enter the supplier's name'<br>Mobile required and 11 digits starting 01: 'Enter 11 digits starting 01, e.g. 01711-284530'<br>Banner 'Fix N fields to save this supplier' listing errors<br>Possible-duplicate warning when name or mobile matches an existing party (non-blocking)<br>BR-063: Local party mobile numbers have 11 digits starting 01 (01XXX-XXXXXX); e-mail addresses must be valid when given.<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.
8. **Roles allowed:** `parties.create` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /payment-terms`](05-API-Specification.md#getpaymentterms), [`POST /parties`](05-API-Specification.md#postparties), [`GET /party-groups`](05-API-Specification.md#getpartygroups), [`POST /party-groups`](05-API-Specification.md#postpartygroups), [`GET /ledgers`](05-API-Specification.md#getledgers)
10. **Entities:** `account_groups`, `account_mappings`, `audit_logs`, `journal_entries`, `journal_lines`, `ledgers`, `number_sequences`, `parties`, `party_bank_accounts`, `party_groups`, `payment_terms`
11. **Workflows:** — · FR: FR-021
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [C1](#page-c1), [H2](#page-h2)

<a id="page-c3"></a>
#### C3 · Edit supplier

1. **Purpose:** Edit a supplier. Opening balance is read-only once documents exist; shows current balance and audit line.
2. **Components and layout:** Same form as C2 with info banner, 'Make inactive' zone, current balance in the aside, 'Unsaved changes' badge. _Source: Supp-Edit.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Same as C2 | — | — | opening balance read-only |
5. **Actions → API** ([full map](05-API-Specification.md#actions-c3)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes (If-Match) → PATCH /parties/{id}<br>Make inactive → dlg:L6 → POST /parties/{id}/deactivate \| POST /parties/{id}/reactivate<br>Delete (L7 confirmation, only without history) → dlg:L7 → DELETE /parties/{id}
6. **Tables:** —
7. **Validation and rules:** As C2<br>409 conflict banner: 'Someone else changed this record' with reload<br>BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.
8. **Roles allowed:** `parties.edit` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties/{id}`](05-API-Specification.md#getpartiesid), [`PATCH /parties/{id}`](05-API-Specification.md#patchpartiesid), [`POST /parties/{id}/deactivate`](05-API-Specification.md#postpartiesiddeactivate), [`POST /parties/{id}/reactivate`](05-API-Specification.md#postpartiesidreactivate), [`DELETE /parties/{id}`](05-API-Specification.md#deletepartiesid)
10. **Entities:** `audit_logs`, `journal_entries`, `journal_lines`, `ledgers`, `parties`, `party_bank_accounts`
11. **Workflows:** — · FR: FR-021, FR-025
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [C4](#page-c4)

<a id="page-c4"></a>
#### C4 · Supplier details

1. **Purpose:** Everything about one supplier: KPIs, open bills, 6-month trend, contact, terms and activity.
2. **Components and layout:** Identity header (initials, name, status badge, code, contact) with Edit / Statement / New bill; tabs Overview · History · Ledger; 4 StatTiles; open documents card with Record payment; monthly bars; aside. _Source: Supp-Details.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-c4)): Edit → nav:C3<br>Statement → nav:C8<br>New bill (Alt P) → nav:F3<br>Record payment → nav:F5<br>Switch tab → GET /parties/{id}/transactions \| GET /parties/{id}/ledger \| GET /audit-logs<br>Apply advance or credit (NEW, R-08) → GET /parties/{id}/open-documents → POST /allocations<br>Remove allocation (NEW, R-08) → dlg:L6 → DELETE /allocations/{id}
6. **Table:** columns Bill, Date, Due, Status, Amount (৳), Paid (৳), Balance (৳); server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /audit-logs`](05-API-Specification.md#getauditlogs), [`GET /parties/{id}`](05-API-Specification.md#getpartiesid), [`GET /parties/{id}/open-documents`](05-API-Specification.md#getpartiesidopendocuments), [`GET /parties/{id}/transactions`](05-API-Specification.md#getpartiesidtransactions), [`GET /parties/{id}/ledger`](05-API-Specification.md#getpartiesidledger), [`POST /allocations`](05-API-Specification.md#postallocations), [`DELETE /allocations/{id}`](05-API-Specification.md#deleteallocationsid), [`POST /supplier-payments`](05-API-Specification.md#postsupplierpayments)
10. **Entities:** `approval_requests`, `audit_logs`, `bill_allocations`, `credit_notes`, `debit_notes`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `parties`, `party_bank_accounts`, `payment_details`, `purchase_bills`, `purchase_orders`, `purchase_returns`, `quotations`, `roles`, `sales_invoices`, `sales_returns`, `users`
11. **Workflows:** — · FR: FR-022
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [C5](#page-c5), [C6](#page-c6), [C8](#page-c8), [F5](#page-f5)

<a id="page-c5"></a>
#### C5 · Supplier transaction history

1. **Purpose:** All documents of the supplier.
2. **Components and layout:** Detail header + History tab: search, document type select, date range, export; table; pagination. _Source: Supp-History.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | number or reference |
   | Document type | select | opt | Bills, Payments, Debit notes, Purchase orders |
   | From / to | date | opt | to >= from |
5. **Actions → API** ([full map](05-API-Specification.md#actions-c5)): Open document → nav:*<br>Export → export:GET /parties/{id}/transactions
6. **Table:** columns Date, Number, Type, Reference, Due, Amount (৳), Status; filters type, date; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /parties/{id}/transactions`](05-API-Specification.md#getpartiesidtransactions)
10. **Entities:** `credit_notes`, `debit_notes`, `export_jobs`, `journal_entries`, `purchase_bills`, `purchase_orders`, `purchase_returns`, `quotations`, `sales_invoices`, `sales_returns`
11. **Workflows:** — · FR: FR-023
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [C4](#page-c4)

<a id="page-c6"></a>
#### C6 · Supplier ledger

1. **Purpose:** The supplier's ledger with opening, debits, credits and running Dr/Cr balance.
2. **Components and layout:** Date range, detailed/summary density, print, export; summary strip (opening, invoiced, received, closing); ledger table with debit/credit washes. _Source: Supp-Ledger.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From / to | date | req | default current month to date |
   | Density | segmented | opt | detailed/summary |
5. **Actions → API** ([full map](05-API-Specification.md#actions-c6)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /parties/{id}/ledger<br>Open voucher → nav:*
6. **Table:** columns Date, Voucher, Particulars, Debit (৳), Credit (৳), Balance (৳); filters date; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /parties/{id}/ledger`](05-API-Specification.md#getpartiesidledger)
10. **Entities:** `export_jobs`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-023
12. **States:** As Report; opening and closing always shown, even with no entries.
13. **Depends on:** [H7](#page-h7)

<a id="page-c7"></a>
#### C7 · Outstanding bills

1. **Purpose:** Open bills across suppliers by age bucket (not due, 1–30, 31–60, 61–90, 90+).
2. **Components and layout:** Bucket buttons (total + 5) acting as filters; segmented filter; search; selectable table with totals and row actions (statement, record payment). _Source: Supp-Outstanding.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-c7)): Filter by bucket → GET /parties/outstanding<br>Send reminders/statements (bulk) → POST /parties/statements/send<br>Record payment (row) → nav:F5
6. **Table:** columns Supplier, Bill, Date, Due, Age, Balance (৳); filters bucket; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties/outstanding`](05-API-Specification.md#getpartiesoutstanding), [`POST /parties/statements/send`](05-API-Specification.md#postpartiesstatementssend)
10. **Entities:** `audit_logs`, `export_jobs`, `parties`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-023
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [J7](#page-j7)

<a id="page-c8"></a>
#### C8 · Supplier statement

1. **Purpose:** Printable statement of account (A4) with summary, ledger lines and aging.
2. **Components and layout:** Toolbar (period, aging checkbox, PDF, Print, E-mail); paper-white statement with company header, party block, summary, compact ledger, aging row and footer. _Source: Supp-Statement.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From / to | date | req |  |
   | Show ageing summary | checkbox | opt | default on |
5. **Actions → API** ([full map](05-API-Specification.md#actions-c8)): PDF → GET /parties/{id}/statement/pdf<br>Print → GET /parties/{id}/statement/pdf → ui:print<br>Email to supplier → POST /parties/statements/send
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `parties.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties/{id}/ledger`](05-API-Specification.md#getpartiesidledger), [`GET /parties/{id}/statement`](05-API-Specification.md#getpartiesidstatement), [`GET /parties/{id}/statement/pdf`](05-API-Specification.md#getpartiesidstatementpdf), [`POST /parties/statements/send`](05-API-Specification.md#postpartiesstatementssend)
10. **Entities:** `audit_logs`, `companies`, `export_jobs`, `journal_entries`, `journal_lines`, `ledgers`, `parties`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-024
12. **States:** Loading preview skeleton; PDF generation toast; print uses the browser dialog.
13. **Depends on:** [C6](#page-c6)

### Inventory


<a id="page-d1"></a>
#### D1 · Product list

1. **Purpose:** All products and services with cost, price, on hand and stock status.
2. **Components and layout:** Header (Import, Export, New item); toolbar: search (name, code, barcode), type segmented, category, warehouse, stock-level selects; selectable table; pagination. _Source: Inventory.dc.html (D1)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | name, code or barcode |
   | Type | segmented | opt | all/product/service |
   | Category | select | opt |  |
   | Warehouse | select | opt |  |
   | Stock | select | opt | in/low/out |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d1)): New item → nav:D2<br>Import → POST /products/import (dry_run=true) → POST /products/import (dry_run=false)<br>Export → export:GET /products<br>Open → nav:D4<br>Edit (row) → nav:D3
6. **Table:** columns Item, Type, Category, Unit, Cost (৳)*, Price (৳), On hand, Stock; filters type, category, warehouse, stock; search; server-side pagination and sorting.
7. **Validation and rules:** BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API.
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /products`](05-API-Specification.md#getproducts), [`POST /products/import`](05-API-Specification.md#postproductsimport), [`GET /product-categories`](05-API-Specification.md#getproductcategories)
10. **Entities:** `audit_logs`, `export_jobs`, `ledgers`, `product_categories`, `product_warehouse_settings`, `products`, `stock_balances`, `tax_rules`, `units`
11. **Workflows:** — · FR: FR-030
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [D2](#page-d2), [D4](#page-d4) · Note: * hidden without inventory.view_costs

<a id="page-d2"></a>
#### D2 · Add product or service

1. **Purpose:** Create an item. Choosing Service hides stock fields (D2 service variant).
2. **Components and layout:** Kind cards (Physical product / Service); cards Basic details, Pricing, Inventory (products only) with per-warehouse grid; aside invoice-line preview with margin; footer. _Source: Inv-Add.dc.html, Inv-Service.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Kind | segmented | req | product or service |
   | Name | text | req | include pack size |
   | Item code | text | opt | suggested from category prefix; unique |
   | Category | select | opt |  |
   | Unit | select | req |  |
   | Barcode | text | opt | unique (products) |
   | Purchase cost | money | opt | products; >= 0 |
   | Selling price | money | req | >= 0 |
   | Wholesale price | money | opt | >= 0 |
   | Tax rule | select | opt | from K6 |
   | Income account | select | req | income ledger |
   | Track stock | switch | — | on for products |
   | Stock group | select | opt |  |
   | Valuation method | readonly | — | company setting |
   | Preferred supplier | select | opt |  |
   | Per warehouse: opening qty, reorder at, reorder qty | number | opt | >= 0; opening qty only on create |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d2)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save and add another → POST /products → ui:reset the form<br>Save item → POST /products → nav:D4<br>Add unit inline (NEW) → POST /units
6. **Tables:** —
7. **Validation and rules:** Name and selling price required<br>Code and barcode unique in company<br>Opening quantity requires a cost (posts opening stock, BR-028)<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.<br>BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API.
8. **Roles allowed:** `inventory.create` → Owner, Store keeper. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /tax-rules`](05-API-Specification.md#gettaxrules), [`POST /products`](05-API-Specification.md#postproducts), [`GET /product-categories`](05-API-Specification.md#getproductcategories), [`GET /stock-groups`](05-API-Specification.md#getstockgroups), [`GET /units`](05-API-Specification.md#getunits), [`POST /units`](05-API-Specification.md#postunits), [`GET /ledgers`](05-API-Specification.md#getledgers)
10. **Entities:** `account_groups`, `audit_logs`, `journal_entries`, `journal_lines`, `ledgers`, `product_categories`, `product_warehouse_settings`, `products`, `stock_balances`, `stock_groups`, `stock_movements`, `tax_rules`, `units`
11. **Workflows:** — · FR: FR-031
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [D5](#page-d5), [D6](#page-d6), [D7](#page-d7), [K6](#page-k6)

<a id="page-d3"></a>
#### D3 · Edit product

1. **Purpose:** Edit an item. Quantities change only through stock documents.
2. **Components and layout:** As D2 with info banner linking to Stock adjustment and history panel (created, price changes). _Source: Inv-Edit.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Same as D2 | — | — | on-hand quantity read-only; kind locked once used |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d3)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes → PATCH /products/{id}<br>Go to stock adjustment → nav:D10<br>Delete product (NEW, only without history) → dlg:L7 → DELETE /products/{id}
6. **Tables:** —
7. **Validation and rules:** BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.
8. **Roles allowed:** `inventory.edit` → Owner, Store keeper. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /audit-logs`](05-API-Specification.md#getauditlogs), [`GET /products/{id}`](05-API-Specification.md#getproductsid), [`PATCH /products/{id}`](05-API-Specification.md#patchproductsid), [`DELETE /products/{id}`](05-API-Specification.md#deleteproductsid)
10. **Entities:** `audit_logs`, `product_warehouse_settings`, `products`, `purchase_bill_lines`, `sales_invoice_lines`, `stock_balances`, `stock_movements`, `users`
11. **Workflows:** — · FR: FR-031
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [D10](#page-d10)

<a id="page-d4"></a>
#### D4 · Product details

1. **Purpose:** Stock by warehouse, recent movements, item facts and price history.
2. **Components and layout:** Header actions Edit / Adjust / Transfer; 4 StatTiles (on hand, value*, sold 30 days, margin*); stock-by-warehouse table; recent movements; aside facts and price history. _Source: Inv-Details.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-d4)): Edit → nav:D3<br>Adjust → nav:D10<br>Transfer → nav:D11<br>All movements → nav:D9
6. **Table:** columns Warehouse, On hand, Reorder at, Value (৳)*, Status; server-side pagination and sorting.
7. **Validation and rules:** BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API.
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /products/{id}`](05-API-Specification.md#getproductsid)
10. **Entities:** `audit_logs`, `product_warehouse_settings`, `products`, `stock_balances`
11. **Workflows:** — · FR: FR-032
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [D9](#page-d9), [D10](#page-d10), [D11](#page-d11)

<a id="page-d5"></a>
#### D5 · Product categories

1. **Purpose:** Two-level category tree with code prefixes, item counts and stock value; inline drawer to add.
2. **Components and layout:** Table with indentation and row actions; inline Drawer 'New category'. _Source: Inv-Categories.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Name | text | req | unique |
   | Parent category | select | opt | top level if empty; max 2 levels |
   | Code prefix | text | opt | 2–10 uppercase |
   | Default unit | select | opt |  |
   | Default stock group | select | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d5)): New category → POST /product-categories<br>Edit → PATCH /product-categories/{id}<br>Reorder (drag) → PATCH /product-categories/{id} (sort_order)<br>Delete (only if unused) → dlg:L7 → DELETE /product-categories/{id}
6. **Table:** columns Category, Code prefix, Items, Stock value (৳); server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /product-categories`](05-API-Specification.md#getproductcategories), [`POST /product-categories`](05-API-Specification.md#postproductcategories), [`PATCH /product-categories/{id}`](05-API-Specification.md#patchproductcategoriesid), [`DELETE /product-categories/{id}`](05-API-Specification.md#deleteproductcategoriesid), [`GET /units`](05-API-Specification.md#getunits)
10. **Entities:** `product_categories`, `products`, `units`
11. **Workflows:** — · FR: FR-033
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [D2](#page-d2)

<a id="page-d6"></a>
#### D6 · Stock groups

1. **Purpose:** Reporting hierarchy for valuation, with items, share and value.
2. **Components and layout:** Info banner (groups vs categories); tree table; Print; New stock group. _Source: Inv-Groups.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-d6)): New stock group → POST /stock-groups<br>Edit → PATCH /stock-groups/{id}<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Delete stock group (NEW, only when unused) → dlg:L7 → DELETE /stock-groups/{id}
6. **Table:** columns Stock group, Items, Share, Value (৳); server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /stock-groups`](05-API-Specification.md#getstockgroups), [`POST /stock-groups`](05-API-Specification.md#poststockgroups), [`PATCH /stock-groups/{id}`](05-API-Specification.md#patchstockgroupsid), [`DELETE /stock-groups/{id}`](05-API-Specification.md#deletestockgroupsid)
10. **Entities:** `products`, `stock_groups`
11. **Workflows:** — · FR: FR-033
12. **States:** Loading skeleton rows; empty search 'No accounts match'.
13. **Depends on:** [D13](#page-d13)

<a id="page-d7"></a>
#### D7 · Warehouse list

1. **Purpose:** Cards per warehouse: status, address, manager, stock value, products, below reorder, space used.
2. **Components and layout:** Card grid + 'Add a warehouse' card. _Source: Inv-Warehouses.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-d7)): Open → nav:D8<br>Transfer → nav:D11<br>New warehouse (drawer: code, name, branch, address, manager, phone, inventory ledger, default for sales, status, opens on) → POST /warehouses
6. **Tables:** —
7. **Validation and rules:** Code unique; branch required; inventory ledger auto-created under 1301 unless chosen
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /warehouses`](05-API-Specification.md#getwarehouses), [`POST /warehouses`](05-API-Specification.md#postwarehouses)
10. **Entities:** `account_mappings`, `ledgers`, `product_warehouse_settings`, `stock_balances`, `warehouses`
11. **Workflows:** — · FR: FR-033
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [D8](#page-d8), [D11](#page-d11)

<a id="page-d8"></a>
#### D8 · Warehouse details

1. **Purpose:** Stock in one warehouse with search and below-reorder filter; facts and recent transfers.
2. **Components and layout:** 4 tiles; stock table with search and checkbox; pagination; aside. _Source: Inv-Warehouse.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt |  |
   | Only below reorder level | checkbox | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d8)): Edit → PATCH /warehouses/{id}<br>Count stock → nav:D10<br>Transfer → nav:D11
6. **Table:** columns Code, Product, On hand, Reorder at, Value (৳)*, Status; filters below_reorder; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /warehouses/{id}`](05-API-Specification.md#getwarehousesid), [`PATCH /warehouses/{id}`](05-API-Specification.md#patchwarehousesid), [`GET /warehouses/{id}/stock`](05-API-Specification.md#getwarehousesidstock), [`GET /stock-adjustments`](05-API-Specification.md#getstockadjustments), [`GET /stock-transfers`](05-API-Specification.md#getstocktransfers), [`POST /stock-transfers/{id}/receive`](05-API-Specification.md#poststocktransfersidreceive)
10. **Entities:** `journal_entries`, `journal_lines`, `number_sequences`, `product_warehouse_settings`, `products`, `stock_adjustment_lines`, `stock_adjustments`, `stock_balances`, `stock_movements`, `stock_transfer_lines`, `stock_transfers`, `warehouses`
11. **Workflows:** — · FR: FR-033
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [D10](#page-d10), [D11](#page-d11)

<a id="page-d9"></a>
#### D9 · Stock movement history

1. **Purpose:** Every quantity change from invoices, bills, transfers, returns and adjustments.
2. **Components and layout:** Toolbar: search, movement type, warehouse, date range; table; pagination; Export. _Source: Inv-Movements.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | product or reference |
   | Movement type | select | opt |  |
   | Warehouse | select | opt |  |
   | From / to | date | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d9)): Open source document → nav:*<br>Export → export:GET /stock-movements
6. **Table:** columns Date, Reference, Product, Movement, Warehouse, In, Out, Balance; filters type, warehouse, date; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /stock-movements`](05-API-Specification.md#getstockmovements)
10. **Entities:** `export_jobs`, `products`, `stock_movements`, `warehouses`
11. **Workflows:** — · FR: FR-034
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [D4](#page-d4)

<a id="page-d10"></a>
#### D10 · Stock adjustment

1. **Purpose:** Correct quantities after a count, damage or expiry; posts the value change.
2. **Components and layout:** Details card (number, date, warehouse, reason); count grid (in system, counted, difference, unit cost*, value*); notes; aside 'What gets posted' with BalanceStatus; footer Save draft / Post adjustment. _Source: Inv-Adjust.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Adjustment number | readonly | — | ADJ series |
   | Date | date | req | open period |
   | Warehouse | select | req |  |
   | Reason | select | req | count difference, damaged, expired, theft or loss, free samples |
   | Lines: product, counted qty | table | req | counted >= 0; at least one line with difference |
   | Notes | textarea | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d10)): Import count sheet → POST /stock-adjustments/count-sheet<br>Add product → GET /products<br>Remove line → ui:remove the line<br>Save draft → POST /stock-adjustments \| PATCH /stock-adjustments/{id}<br>Post adjustment → POST /stock-adjustments \| PATCH /stock-adjustments/{id} → dlg:L6 → POST /stock-adjustments/{id}/post<br>Delete draft (NEW) → dlg:L7 → DELETE /stock-adjustments/{id}<br>Reverse posted adjustment (NEW) → dlg:L6 → POST /stock-adjustments/{id}/reverse
6. **Tables:** —
7. **Validation and rules:** Counted quantity must be a number >= 0<br>System quantity is re-read at posting; if it changed since the draft, the user is asked to recount<br>BR-029: A stock adjustment posts the value of the difference: a shortage debits the stock-adjustment ledger and credits inventory; a gain does the opposite.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.
8. **Roles allowed:** `inventory.create` → Owner, Store keeper. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /products`](05-API-Specification.md#getproducts), [`GET /warehouses`](05-API-Specification.md#getwarehouses), [`GET /stock-adjustments`](05-API-Specification.md#getstockadjustments), [`POST /stock-adjustments`](05-API-Specification.md#poststockadjustments), [`GET /stock-adjustments/{id}`](05-API-Specification.md#getstockadjustmentsid), [`PATCH /stock-adjustments/{id}`](05-API-Specification.md#patchstockadjustmentsid), [`DELETE /stock-adjustments/{id}`](05-API-Specification.md#deletestockadjustmentsid), [`POST /stock-adjustments/{id}/post`](05-API-Specification.md#poststockadjustmentsidpost), [`POST /stock-adjustments/{id}/reverse`](05-API-Specification.md#poststockadjustmentsidreverse), [`POST /stock-adjustments/count-sheet`](05-API-Specification.md#poststockadjustmentscountsheet)
10. **Entities:** `account_mappings`, `accounting_periods`, `audit_logs`, `journal_entries`, `journal_lines`, `number_sequences`, `product_categories`, `product_warehouse_settings`, `products`, `stock_adjustment_lines`, `stock_adjustments`, `stock_balances`, `stock_movements`, `units`, `warehouses`
11. **Workflows:** Inventory movement · FR: FR-035
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [K16](#page-k16)

<a id="page-d11"></a>
#### D11 · Stock transfer

1. **Purpose:** Move stock between warehouses: Draft > Dispatched > Received.
2. **Components and layout:** Step indicator; Route card (from, to, number, dispatch date, vehicle, driver); items grid (available, transfer, after); aside summary; footer Save draft / Dispatch transfer; received later by destination. _Source: Inv-Transfer.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From | select | req |  |
   | To | select | req | != From |
   | Dispatch date | date | req |  |
   | Vehicle | text | opt |  |
   | Driver | text | opt |  |
   | Lines: product, quantity | table | req | 0 < qty <= available |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d11)): Add product → GET /products<br>Save draft → POST /stock-transfers \| PATCH /stock-transfers/{id}<br>Dispatch transfer → dlg:L6 → POST /stock-transfers/{id}/dispatch<br>Confirm receipt (destination) → POST /stock-transfers/{id}/receive<br>Cancel draft → dlg:L7 → DELETE /stock-transfers/{id} \| dlg:L6 → POST /stock-transfers/{id}/cancel
6. **Tables:** —
7. **Validation and rules:** 'Only N available' inline per line<br>Banner 'You can't move more than the source warehouse holds'<br>BR-030: A stock transfer requires different warehouses and quantity <= available at the source; total value is unchanged; a journal is posted only if the warehouses use different inventory ledgers.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock.
8. **Roles allowed:** `inventory.create` → Owner, Store keeper. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /products`](05-API-Specification.md#getproducts), [`GET /warehouses`](05-API-Specification.md#getwarehouses), [`GET /stock-transfers`](05-API-Specification.md#getstocktransfers), [`POST /stock-transfers`](05-API-Specification.md#poststocktransfers), [`GET /stock-transfers/{id}`](05-API-Specification.md#getstocktransfersid), [`PATCH /stock-transfers/{id}`](05-API-Specification.md#patchstocktransfersid), [`DELETE /stock-transfers/{id}`](05-API-Specification.md#deletestocktransfersid), [`POST /stock-transfers/{id}/dispatch`](05-API-Specification.md#poststocktransfersiddispatch), [`POST /stock-transfers/{id}/receive`](05-API-Specification.md#poststocktransfersidreceive), [`POST /stock-transfers/{id}/cancel`](05-API-Specification.md#poststocktransfersidcancel)
10. **Entities:** `audit_logs`, `journal_entries`, `journal_lines`, `number_sequences`, `product_categories`, `product_warehouse_settings`, `products`, `stock_balances`, `stock_movements`, `stock_transfer_lines`, `stock_transfers`, `units`, `warehouses`
11. **Workflows:** Inventory movement · FR: FR-036
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [D7](#page-d7)

<a id="page-d12"></a>
#### D12 · Low-stock report

1. **Purpose:** Items at or below reorder level with suggested order quantity and value.
2. **Components and layout:** Warehouse select, All/Out/Low segmented, group by supplier checkbox; 4 tiles; selectable table. _Source: Inv-LowStock.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-d12)): Create purchase orders (from selection) → POST /purchase-orders/from-low-stock<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/inventory/low-stock
6. **Table:** columns Product, Warehouse, On hand, Reorder at, Status, Preferred supplier, Suggested, Order value (৳)*; filters warehouse, status; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /reports/inventory/low-stock`](05-API-Specification.md#getreportsinventorylowstock), [`POST /purchase-orders/from-low-stock`](05-API-Specification.md#postpurchaseordersfromlowstock)
10. **Entities:** `export_jobs`, `parties`, `product_warehouse_settings`, `products`, `purchase_order_lines`, `purchase_orders`, `stock_balances`
11. **Workflows:** Restock low items · FR: FR-037
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [F8](#page-f8)

<a id="page-d13"></a>
#### D13 · Stock valuation report

1. **Purpose:** Quantity and weighted-average value per item by stock group, as of a date.
2. **Components and layout:** As-of date, warehouse, items/groups segmented; paper statement; footer agreement with ledger 1301. _Source: Inv-Valuation.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | As of | date | req |  |
   | Warehouse | select | opt |  |
   | Show | segmented | opt | items/groups |
5. **Actions → API** ([full map](05-API-Specification.md#actions-d13)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/inventory/valuation
6. **Table:** columns Code, Item, Quantity, Avg cost (৳), Value (৳); filters warehouse; server-side pagination and sorting.
7. **Validation and rules:** BR-016: Cost of goods sold is quantity x the product's moving weighted average cost in the issuing warehouse at posting; the unit cost is stored on the invoice line.
8. **Roles allowed:** `inventory.view_costs` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/inventory/valuation`](05-API-Specification.md#getreportsinventoryvaluation)
10. **Entities:** `products`, `stock_groups`, `stock_movements`
11. **Workflows:** — · FR: FR-037
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [D6](#page-d6), [H15](#page-h15)

<a id="page-d14"></a>
#### D14 · Inventory summary

1. **Purpose:** Stock value by group, in/out flow, fastest movers, needs attention.
2. **Components and layout:** 4 tiles; group value bars; in/out monthly bars; movers table; attention list. _Source: Inv-Summary.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-d14)): Transfer → nav:D11<br>Adjust stock → nav:D10<br>Valuation → nav:D13<br>Low-stock report → nav:D12
6. **Table:** columns Product, Sold, On hand, Days of cover, Revenue (৳); server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `inventory.view` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/inventory/summary`](05-API-Specification.md#getreportsinventorysummary)
10. **Entities:** `sales_invoice_lines`, `stock_balances`, `stock_groups`, `stock_movements`
11. **Workflows:** — · FR: FR-037
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [D12](#page-d12), [D13](#page-d13)

### Sales


<a id="page-e1"></a>
#### E1 · Sales overview

1. **Purpose:** Sales this month, invoices, waiting to be paid, open quotations; monthly bars; status breakdown; recent; top products.
2. **Components and layout:** 4 tiles; 12-month bars; status stacked bar with drill buttons; recent invoices card; top products. _Source: Sal-Overview.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-e1)): New quotation → nav:E10<br>New invoice → nav:E3<br>Drill to status tab → nav:E2<br>View all → nav:E2
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `sales.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /sales/overview`](05-API-Specification.md#getsalesoverview), [`GET /sales-invoices`](05-API-Specification.md#getsalesinvoices), [`GET /quotations`](05-API-Specification.md#getquotations)
10. **Entities:** `parties`, `quotations`, `sales_invoice_lines`, `sales_invoices`
11. **Workflows:** — · FR: FR-040
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [E2](#page-e2), [E3](#page-e3), [E10](#page-e10)

<a id="page-e2"></a>
#### E2 · Sales invoice list

1. **Purpose:** Every invoice with status tabs (All, Drafts, Unpaid, Overdue, Paid).
2. **Components and layout:** Tabs with counts; search, date range, customer select; selectable table with totals; row actions print/more; pagination; empty state with Clear filters. _Source: Trade.dc.html (E2)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Status tab | tabs | opt |  |
   | Search | text | opt | invoice, customer or amount |
   | From / to | date | opt |  |
   | Customer | select | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e2)): New invoice → nav:E3<br>Export → export:GET /sales-invoices<br>Open → nav:E5<br>Print (row) → GET /sales-invoices/{id}/pdf → ui:print<br>Post selected drafts (bulk, L6) → dlg:L6 → POST /sales-invoices/bulk-post
6. **Table:** columns Invoice, Date, Customer, Due, Status, Amount (৳), Balance (৳); filters status, date, party; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `sales.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /sales-invoices`](05-API-Specification.md#getsalesinvoices), [`POST /sales-invoices/bulk-post`](05-API-Specification.md#postsalesinvoicesbulkpost), [`GET /sales-invoices/{id}/pdf`](05-API-Specification.md#getsalesinvoicesidpdf)
10. **Entities:** `account_mappings`, `accounting_periods`, `company_settings`, `export_jobs`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `parties`, `sales_invoice_lines`, `sales_invoices`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-040
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [E3](#page-e3), [E5](#page-e5)

<a id="page-e3"></a>
#### E3 · Create sales invoice

1. **Purpose:** Pick a customer, add items and check server-calculated totals before saving.
2. **Components and layout:** Banners (overdue warning, errors); header fields; items grid (item, qty, unit, price, disc %, tax, amount); Find item / Paste from sheet; notes and internal note; aside party card (balance, overdue, credit limit bar), totals, amount in words, 'When you save' effects; sticky footer. _Source: Sal-Create.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Customer | select | req | active customer; quick-create allowed |
   | Invoice number | readonly | — | next in INV series |
   | Customer PO | text | opt |  |
   | Invoice date | date | req | open period (BR-004) |
   | Due date | date | req | >= invoice date; default from terms (BR-055) |
   | Payment terms | select | opt |  |
   | Dispatch from | select | req | warehouse |
   | Lines: item | select | req | active product/service |
   | Lines: qty | number | req | > 0; unit precision |
   | Lines: price | money | req | >= 0; default selling or wholesale by price tier |
   | Lines: disc % | pct | opt | 0–100 |
   | Lines: tax | select | opt | tax rule (only when rules exist) |
   | Notes for the customer | textarea | opt | printed |
   | Internal note | textarea | opt | team only |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e3)): Find item → GET /products<br>Paste from sheet → ui:parse pasted rows → GET /products<br>Add line (Ctrl Enter) → ui:add a line → POST /sales-invoices/calculate<br>Remove line → ui:remove the line → POST /sales-invoices/calculate<br>Cancel (unsaved dialog) → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save draft → POST /sales-invoices<br>Preview → POST /sales-invoices → nav:E4<br>Print → POST /sales-invoices → GET /sales-invoices/{id}/pdf → ui:print<br>Save invoice (Ctrl S) > Post dialog → POST /sales-invoices → dlg:Sal-DlgPost
6. **Tables:** —
7. **Validation and rules:** 'Fix N lines to save' banner; lines with qty <= 0 marked red<br>Insufficient stock per line (BR-014) returned as line errors<br>Credit-limit and overdue are warnings only (BR-015)<br>Totals always from POST /sales-invoices/calculate (BR-010)<br>BR-010: Money uses decimal arithmetic only. Line amounts are rounded half-up to 2 decimals; document totals are the sum of rounded lines; the server's calculation is authoritative.<br>BR-011: Line gross = quantity x unit price; discount = gross x discount% ; tax = (gross - discount) x tax rate; line total = gross - discount + tax.<br>BR-012: Invoice rounding (company setting: none, nearest 1, nearest 5 taka) is posted to the mapped rounding ledger.<br>BR-013: A sales invoice needs an active customer, a warehouse, at least one line with quantity > 0 and price >= 0, and a due date on or after the invoice date.<br>BR-014: Posting a stock-out is rejected if quantity exceeds the warehouse's on-hand quantity, unless the company allows negative stock.<br>BR-015: Exceeding a customer's credit limit or invoicing a customer with overdue invoices shows a warning but does not block.<br>BR-016: Cost of goods sold is quantity x the product's moving weighted average cost in the issuing warehouse at posting; the unit cost is stored on the invoice line.<br>BR-055: Due date defaults to document date + the party's payment-term days and may be edited.<br>BR-064: Posting a sales invoice creates: Dr customer (total), Cr each line's income ledger (net), Cr tax ledgers (tax), Cr/Dr rounding; and for stocked lines Dr COGS, Cr warehouse inventory (cost).<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.<br>BR-050: Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.
8. **Roles allowed:** `sales.create` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /payment-terms`](05-API-Specification.md#getpaymentterms), [`GET /tax-rules`](05-API-Specification.md#gettaxrules), [`GET /parties`](05-API-Specification.md#getparties), [`POST /parties`](05-API-Specification.md#postparties), [`GET /products`](05-API-Specification.md#getproducts), [`GET /warehouses`](05-API-Specification.md#getwarehouses), [`POST /sales-invoices/calculate`](05-API-Specification.md#postsalesinvoicescalculate), [`POST /sales-invoices`](05-API-Specification.md#postsalesinvoices), [`POST /sales-invoices/{id}/post`](05-API-Specification.md#postsalesinvoicesidpost), [`GET /sales-invoices/{id}/pdf`](05-API-Specification.md#getsalesinvoicesidpdf)
10. **Entities:** `account_mappings`, `accounting_periods`, `audit_logs`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `parties`, `party_bank_accounts`, `party_groups`, `payment_details`, `payment_terms`, `product_categories`, `product_warehouse_settings`, `products`, `purchase_bills`, `sales_invoice_lines`, `sales_invoices`, `stock_balances`, `stock_movements`, `tax_rules`, `units`, `warehouses`
11. **Workflows:** Bill a customer, Sales-to-payment-to-accounting · FR: FR-041
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [B2](#page-b2), [D2](#page-d2), [K6](#page-k6), [E4](#page-e4), [E5](#page-e5)

<a id="page-e4"></a>
#### E4 · Invoice preview

1. **Purpose:** How the customer will see the invoice before posting.
2. **Components and layout:** Preview badge bar (Back to edit, PDF, Print, Save and post); A4 invoice per K7 design settings. _Source: Sal-Preview.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-e4)): Back to edit → nav:E6<br>PDF → GET /sales-invoices/{id}/pdf<br>Print → GET /sales-invoices/{id}/pdf → ui:print<br>Save and post → dlg:Sal-DlgPost
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `sales.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /sales-invoices/{id}`](05-API-Specification.md#getsalesinvoicesid), [`POST /sales-invoices/{id}/post`](05-API-Specification.md#postsalesinvoicesidpost), [`GET /sales-invoices/{id}/pdf`](05-API-Specification.md#getsalesinvoicesidpdf)
10. **Entities:** `account_mappings`, `accounting_periods`, `audit_logs`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `payment_details`, `sales_invoice_lines`, `sales_invoices`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-041
12. **States:** Loading preview skeleton; PDF generation toast; print uses the browser dialog.
13. **Depends on:** [E3](#page-e3), [K7](#page-k7)

<a id="page-e5"></a>
#### E5 · Invoice details

1. **Purpose:** A posted invoice: summary strip, lines, payments, ledger entries, activity, customer, delivery, attachments.
2. **Components and layout:** Header with More (duplicate, credit note, PDF, cancel), Print, Record payment; summary strip; tabs Invoice / Payments / Ledger entries / Activity; aside. _Source: Sal-Details.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-e5)): Record payment (Alt R) → nav:E7<br>Print → GET /sales-invoices/{id}/pdf → ui:print<br>Duplicate → nav:E3 (prefilled from this invoice; prices recalculated by POST /sales-invoices/calculate)<br>Create credit note → nav:E9<br>Download PDF → GET /sales-invoices/{id}/pdf<br>Cancel invoice (dialog) → dlg:Sal-DlgCancel<br>Attach file → POST /attachments<br>Apply advance or credit (NEW, R-08) → GET /parties/{id}/open-documents → POST /allocations<br>Remove allocation (NEW, R-08) → dlg:L6 → DELETE /allocations/{id}<br>Edit delivery details (NEW) → PATCH /sales-invoices/{id}/delivery<br>E-mail to customer (NEW) → POST /sales-invoices/{id}/send
6. **Table:** columns #, Item, Code, Qty, Price (৳), Disc, Amount (৳); server-side pagination and sorting.
7. **Validation and rules:** BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances.
8. **Roles allowed:** `sales.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /attachments`](05-API-Specification.md#getattachments), [`GET /attachments/{id}/url`](05-API-Specification.md#getattachmentsidurl), [`GET /audit-logs`](05-API-Specification.md#getauditlogs), [`GET /parties/{id}/open-documents`](05-API-Specification.md#getpartiesidopendocuments), [`POST /sales-invoices/calculate`](05-API-Specification.md#postsalesinvoicescalculate), [`GET /sales-invoices/{id}`](05-API-Specification.md#getsalesinvoicesid), [`PATCH /sales-invoices/{id}/delivery`](05-API-Specification.md#patchsalesinvoicesiddelivery), [`POST /sales-invoices/{id}/cancel`](05-API-Specification.md#postsalesinvoicesidcancel), [`GET /sales-invoices/{id}/pdf`](05-API-Specification.md#getsalesinvoicesidpdf), [`POST /sales-invoices/{id}/send`](05-API-Specification.md#postsalesinvoicesidsend), [`POST /customer-receipts`](05-API-Specification.md#postcustomerreceipts), [`POST /allocations`](05-API-Specification.md#postallocations), [`DELETE /allocations/{id}`](05-API-Specification.md#deleteallocationsid), [`POST /credit-notes`](05-API-Specification.md#postcreditnotes)
10. **Entities:** `attachments`, `audit_logs`, `bill_allocations`, `company_settings`, `credit_notes`, `debit_notes`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `parties`, `payment_details`, `products`, `purchase_bills`, `sales_invoice_lines`, `sales_invoices`, `stock_balances`, `stock_movements`, `tax_rules`, `users`
11. **Workflows:** — · FR: FR-042
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [E7](#page-e7), [E8](#page-e8), [Sal-DlgCancel](#page-sal-dlgcancel)

<a id="page-e6"></a>
#### E6 · Edit draft invoice

1. **Purpose:** Change a draft; posting assigns the date and locks it.
2. **Components and layout:** As E3 plus 'Draft, not yet posted' banner, Delete draft, Unsaved changes badge; primary 'Post invoice'. _Source: Sal-Edit.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Same as E3 | — | — |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e6)): Delete draft (dialog) → dlg:Sal-DlgDelete<br>Save draft → PUT /sales-invoices/{id}<br>Preview → PUT /sales-invoices/{id} → nav:E4<br>Post invoice → PUT /sales-invoices/{id} → dlg:Sal-DlgPost
6. **Tables:** —
7. **Validation and rules:** BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.
8. **Roles allowed:** `sales.edit` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /sales-invoices/calculate`](05-API-Specification.md#postsalesinvoicescalculate), [`GET /sales-invoices/{id}`](05-API-Specification.md#getsalesinvoicesid), [`PUT /sales-invoices/{id}`](05-API-Specification.md#putsalesinvoicesid), [`DELETE /sales-invoices/{id}`](05-API-Specification.md#deletesalesinvoicesid), [`POST /sales-invoices/{id}/post`](05-API-Specification.md#postsalesinvoicesidpost)
10. **Entities:** `account_mappings`, `accounting_periods`, `audit_logs`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `parties`, `payment_details`, `products`, `sales_invoice_lines`, `sales_invoices`, `stock_balances`, `stock_movements`, `tax_rules`
11. **Workflows:** — · FR: FR-041, FR-046
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [Sal-DlgDelete](#page-sal-dlgdelete), [Sal-DlgPost](#page-sal-dlgpost)

<a id="page-e7"></a>
#### E7 · Customer payment

1. **Purpose:** Record money received and apply it to open invoices (oldest first).
2. **Components and layout:** Header fields; allocation table with apply inputs and 'Re-apply oldest first'; narration; aside summary (applied, kept as advance, balance after) and posting preview; confirm dialog. _Source: Sal-Payment.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Received from | select | req | customer with balance |
   | Voucher number | readonly | — | RV series |
   | Date | date | req | open period |
   | Amount received | money | req | > 0 |
   | Deposit to | select | req | money account (use_for_receipts) |
   | Method | segmented | req | cash, cheque, bank transfer, bKash |
   | Cheque number, cheque date, drawn on | text/date | cond | when method = cheque |
   | Transaction reference | text | cond | bank/bKash |
   | Apply (per open invoice) | money | opt | 0 <= apply <= balance |
   | Narration | textarea | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e7)): Re-apply oldest first → ui:re-allocate oldest due first on the client (the server re-validates, BR-020)<br>Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Print voucher → GET /customer-receipts/{id}/pdf<br>Save receipt (confirm dialog) → dlg:L6 → POST /customer-receipts
6. **Tables:** —
7. **Validation and rules:** 'Enter an amount greater than zero'<br>Apply above balance marked invalid<br>Warning banner when unapplied > 0: kept as advance<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-021: Allocations, notes and returns may reference only documents of the same party and company.<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-050: Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.<br>BR-053: A voucher dated more than the configured number of days in the past requires explicit confirmation.
8. **Roles allowed:** `sales.create` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties`](05-API-Specification.md#getparties), [`GET /parties/{id}/open-documents`](05-API-Specification.md#getpartiesidopendocuments), [`POST /customer-receipts`](05-API-Specification.md#postcustomerreceipts), [`GET /customer-receipts/{id}`](05-API-Specification.md#getcustomerreceiptsid), [`GET /customer-receipts/{id}/pdf`](05-API-Specification.md#getcustomerreceiptsidpdf), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts)
10. **Entities:** `audit_logs`, `bank_statement_lines`, `bill_allocations`, `companies`, `credit_notes`, `debit_notes`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `parties`, `party_groups`, `payment_details`, `payment_terms`, `purchase_bills`, `sales_invoices`
11. **Workflows:** Bill a customer, Sales-to-payment-to-accounting · FR: FR-043
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [E5](#page-e5), [B6](#page-b6)

<a id="page-e8"></a>
#### E8 · Sales return

1. **Purpose:** Goods coming back against an original invoice; creates a credit note.
2. **Components and layout:** Header fields; items table (original, rate, return qty, reason, value); stock segmented (return to stock / write off); aside value and effect. _Source: Sal-Return.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Customer | readonly | — | from invoice |
   | Original invoice | select | req | posted invoice of the customer |
   | Return number | readonly | — | SR series |
   | Date | date | req |  |
   | Return qty per line | number | req | 0 <= qty <= original - returned (BR-022) |
   | Reason per line | select | req |  |
   | Stock | segmented | req | restock/write off |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e8)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save draft → POST /sales-returns \| PUT /sales-returns/{id}<br>Save return and create credit note → POST /sales-returns \| PUT /sales-returns/{id} → POST /sales-returns/{id}/post → nav:E9
6. **Tables:** —
7. **Validation and rules:** 'Quantity too high' banner<br>BR-022: Returned quantity per line <= original quantity - quantity already returned.<br>BR-021: Allocations, notes and returns may reference only documents of the same party and company.
8. **Roles allowed:** `sales.create` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /sales-returns`](05-API-Specification.md#getsalesreturns), [`POST /sales-returns`](05-API-Specification.md#postsalesreturns), [`GET /sales-returns/{id}`](05-API-Specification.md#getsalesreturnsid), [`PUT /sales-returns/{id}`](05-API-Specification.md#putsalesreturnsid), [`POST /sales-returns/{id}/post`](05-API-Specification.md#postsalesreturnsidpost)
10. **Entities:** `credit_notes`, `journal_entries`, `journal_lines`, `number_sequences`, `sales_invoice_lines`, `sales_return_lines`, `sales_returns`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-044
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [E9](#page-e9)

<a id="page-e9"></a>
#### E9 · Credit note

1. **Purpose:** Post the credit and choose: reduce invoice, keep as credit, or refund.
2. **Components and layout:** Header (party, number, date, created from, reason, amount); usage choice cards; aside effect on balances and posting preview. _Source: Sal-Credit.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Reason | select | req |  |
   | Amount | money | req | from return; editable only for price corrections |
   | Usage | radio cards | req | apply/credit/refund |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e9)): Back → nav:*<br>Print → GET /credit-notes/{id}/pdf<br>Post credit note → POST /credit-notes \| PUT /credit-notes/{id} → dlg:L6 → POST /credit-notes/{id}/post
6. **Tables:** —
7. **Validation and rules:** BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).<br>BR-064: Posting a sales invoice creates: Dr customer (total), Cr each line's income ledger (net), Cr tax ledgers (tax), Cr/Dr rounding; and for stocked lines Dr COGS, Cr warehouse inventory (cost).
8. **Roles allowed:** `sales.create` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /credit-notes/{id}`](05-API-Specification.md#getcreditnotesid), [`PUT /credit-notes/{id}`](05-API-Specification.md#putcreditnotesid), [`POST /credit-notes`](05-API-Specification.md#postcreditnotes), [`POST /credit-notes/{id}/post`](05-API-Specification.md#postcreditnotesidpost), [`GET /credit-notes/{id}/pdf`](05-API-Specification.md#getcreditnotesidpdf)
10. **Entities:** `account_mappings`, `bill_allocations`, `companies`, `credit_notes`, `journal_entries`, `journal_lines`, `number_sequences`, `parties`, `sales_invoices`
11. **Workflows:** — · FR: FR-044
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [E8](#page-e8), [E5](#page-e5)

<a id="page-e10"></a>
#### E10 · Sales quotation

1. **Purpose:** An offer to a customer or prospect; nothing posts; converts to an invoice.
2. **Components and layout:** Invoice form variant: Valid until, Proposed terms, Terms of offer, info banner. _Source: Sal-Quote.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Customer or prospect | select | req |  |
   | Valid until | date | req | >= quotation date |
   | Lines | table | req | as E3 |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e10)): Save draft → POST /quotations \| PUT /quotations/{id}<br>Preview → GET /quotations/{id}/pdf<br>Send quotation → POST /quotations/{id}/send<br>Convert to invoice (accepted) → POST /quotations/{id}/convert → nav:E6
6. **Tables:** —
7. **Validation and rules:** BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities.
8. **Roles allowed:** `sales.create` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /products`](05-API-Specification.md#getproducts), [`POST /sales-invoices/calculate`](05-API-Specification.md#postsalesinvoicescalculate), [`POST /sales-invoices`](05-API-Specification.md#postsalesinvoices), [`GET /quotations`](05-API-Specification.md#getquotations), [`POST /quotations`](05-API-Specification.md#postquotations), [`GET /quotations/{id}`](05-API-Specification.md#getquotationsid), [`PUT /quotations/{id}`](05-API-Specification.md#putquotationsid), [`POST /quotations/{id}/send`](05-API-Specification.md#postquotationsidsend), [`POST /quotations/{id}/convert`](05-API-Specification.md#postquotationsidconvert), [`POST /quotations/{id}/status`](05-API-Specification.md#postquotationsidstatus), [`GET /quotations/{id}/pdf`](05-API-Specification.md#getquotationsidpdf)
10. **Entities:** `audit_logs`, `companies`, `company_settings`, `number_sequences`, `parties`, `product_categories`, `product_warehouse_settings`, `products`, `quotation_lines`, `quotations`, `sales_invoice_lines`, `sales_invoices`, `stock_balances`, `tax_rules`, `units`
11. **Workflows:** — · FR: FR-045
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [E3](#page-e3)

<a id="page-e11"></a>
#### E11 · Sales report

1. **Purpose:** Gross, returns, net sales and share, grouped by customer, category or month.
2. **Components and layout:** Date range, branch, Group by segmented; 4 tiles; table with totals; Print / Export. _Source: Sal-Report.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From / to | date | req |  |
   | Branch | select | opt |  |
   | Group by | segmented | req | customer/category/month |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e11)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/sales
6. **Table:** columns Customer|Category|Month, Invoices, Gross (৳), Returns (৳), Net (৳), Share; filters date, branch; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/sales`](05-API-Specification.md#getreportssales)
10. **Entities:** `ie_categories`, `journal_lines`, `parties`, `product_categories`, `purchase_bill_lines`, `purchase_bills`, `purchase_returns`, `sales_invoice_lines`, `sales_invoices`, `sales_returns`
11. **Workflows:** — · FR: FR-047
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [J1](#page-j1) · Note: GAP-10 salesperson

<a id="page-e12"></a>
#### E12 · Sales return report

1. **Purpose:** Every return and the credit note that settled it; share by reason.
2. **Components and layout:** Date range, customer, reason; 4 tiles; table; 'By reason' bars. _Source: Sal-ReturnReport.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-e12)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/sales-returns
6. **Table:** columns Return, Date, Customer, Against, Items, Reason, Credit note, Status, Value (৳); filters date, party, reason; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/sales-returns`](05-API-Specification.md#getreportssalesreturns)
10. **Entities:** `ie_categories`, `journal_lines`, `parties`, `product_categories`, `purchase_bill_lines`, `purchase_bills`, `purchase_returns`, `sales_invoice_lines`, `sales_invoices`, `sales_returns`
11. **Workflows:** — · FR: FR-047
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [E8](#page-e8)

<a id="page-e13"></a>
#### E13 · Quotation list (NEW)

1. **Purpose:** Every quotation with customer, date, valid until, total and status; the entry point for the sidebar item Quotations.
2. **Components and layout:** List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | number or customer |
   | Status | segmented | opt | all, draft, sent, accepted, declined, expired, converted |
   | Date range | date | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e13)): New quotation → nav:E10<br>Open → nav:E10<br>Mark accepted / declined / expired (row) → POST /quotations/{id}/status<br>Convert to invoice (row, accepted) → POST /quotations/{id}/convert → nav:E6<br>Print (row) → GET /quotations/{id}/pdf → ui:print<br>Delete draft (row) → dlg:L7 → DELETE /quotations/{id}<br>Export → export:GET /quotations
6. **Table:** columns Number, Customer, Date, Valid until, Total (৳), Status; filters status, date; search; server-side pagination and sorting.
7. **Validation and rules:** BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities.<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.
8. **Roles allowed:** `sales.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /quotations`](05-API-Specification.md#getquotations), [`POST /quotations/{id}/convert`](05-API-Specification.md#postquotationsidconvert), [`POST /quotations/{id}/status`](05-API-Specification.md#postquotationsidstatus), [`DELETE /quotations/{id}`](05-API-Specification.md#deletequotationsid), [`GET /quotations/{id}/pdf`](05-API-Specification.md#getquotationsidpdf)
10. **Entities:** `audit_logs`, `companies`, `company_settings`, `export_jobs`, `number_sequences`, `parties`, `quotation_lines`, `quotations`, `sales_invoice_lines`, `sales_invoices`
11. **Workflows:** — · FR: FR-045
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [E10](#page-e10) · Note: GAP-24

<a id="page-e14"></a>
#### E14 · Payments received (NEW)

1. **Purpose:** Customer receipts (RV) with customer, account, method, amount and unapplied advance; the entry point for the sidebar item Payments received.
2. **Components and layout:** List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | voucher or customer |
   | Account | select | opt | money account |
   | Unapplied only | switch | opt |  |
   | Date range | date | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e14)): New receipt → nav:E7<br>Open (drawer) → GET /customer-receipts/{id}<br>Print voucher (row) → GET /customer-receipts/{id}/pdf → ui:print<br>Export → export:GET /customer-receipts
6. **Table:** columns Voucher, Date, Customer, Account, Method, Amount (৳), Unapplied (৳); filters account, unapplied, date; search; server-side pagination and sorting.
7. **Validation and rules:** BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.
8. **Roles allowed:** `sales.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /customer-receipts`](05-API-Specification.md#getcustomerreceipts), [`GET /customer-receipts/{id}`](05-API-Specification.md#getcustomerreceiptsid), [`GET /customer-receipts/{id}/pdf`](05-API-Specification.md#getcustomerreceiptsidpdf), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts)
10. **Entities:** `bank_statement_lines`, `bill_allocations`, `companies`, `export_jobs`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `parties`, `payment_details`, `sales_invoices`
11. **Workflows:** — · FR: FR-043
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [E7](#page-e7), [B4](#page-b4) · Note: GAP-24

<a id="page-e15"></a>
#### E15 · Returns and credit notes (NEW)

1. **Purpose:** Sales returns and credit notes in one list with type, source invoice, amount, usage and status; the entry point for the sidebar item Returns and credit notes.
2. **Components and layout:** List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Type | segmented | opt | all, returns, credit notes |
   | Status | select | opt | draft, posted, cancelled |
   | Date range | date | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-e15)): New return → nav:E8<br>New credit note → nav:E9<br>Open → nav:E8 \| nav:E9<br>Print credit note (row) → GET /credit-notes/{id}/pdf → ui:print<br>Delete draft (row) → dlg:L7 → DELETE /sales-returns/{id} \| dlg:L7 → DELETE /credit-notes/{id}<br>Cancel posted (row, dialog) → dlg:L6 → POST /sales-returns/{id}/cancel \| dlg:L6 → POST /credit-notes/{id}/cancel<br>Export → export:GET /sales-returns \| export:GET /credit-notes
6. **Table:** columns Number, Type, Date, Customer, Invoice, Amount (৳), Usage, Status; filters type, status, date; search; server-side pagination and sorting.
7. **Validation and rules:** BR-022: Returned quantity per line <= original quantity - quantity already returned.<br>BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.
8. **Roles allowed:** `sales.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /sales-returns`](05-API-Specification.md#getsalesreturns), [`DELETE /sales-returns/{id}`](05-API-Specification.md#deletesalesreturnsid), [`POST /sales-returns/{id}/cancel`](05-API-Specification.md#postsalesreturnsidcancel), [`GET /credit-notes`](05-API-Specification.md#getcreditnotes), [`DELETE /credit-notes/{id}`](05-API-Specification.md#deletecreditnotesid), [`POST /credit-notes/{id}/cancel`](05-API-Specification.md#postcreditnotesidcancel), [`GET /credit-notes/{id}/pdf`](05-API-Specification.md#getcreditnotesidpdf)
10. **Entities:** `audit_logs`, `bill_allocations`, `companies`, `credit_notes`, `export_jobs`, `journal_entries`, `journal_lines`, `number_sequences`, `parties`, `sales_invoice_lines`, `sales_invoices`, `sales_return_lines`, `sales_returns`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-044
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [E8](#page-e8), [E9](#page-e9) · Note: GAP-24

<a id="page-sal-dlgpost"></a>
#### Sal-DlgPost · Dialog · Post invoice

1. **Purpose:** Confirms posting with customer, total and due; explains credit-note correction.
2. **Components and layout:** Pluto Dialog with summary rows and footer Keep / Confirm. _Source: Sal-DlgPost.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-sal-dlgpost)): Keep → ui:close the dialog<br>Confirm → POST /sales-invoices/{id}/post
6. **Tables:** —
7. **Validation and rules:** BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances.<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.
8. **Roles allowed:** `sales.edit` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /sales-invoices/{id}/post`](05-API-Specification.md#postsalesinvoicesidpost)
10. **Entities:** `account_mappings`, `accounting_periods`, `audit_logs`, `bill_allocations`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `payment_details`, `sales_invoice_lines`, `sales_invoices`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-041, FR-046
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** [E5](#page-e5), [E6](#page-e6)

<a id="page-sal-dlgcancel"></a>
#### Sal-DlgCancel · Dialog · Cancel invoice

1. **Purpose:** Danger dialog with required reason (customer cancelled, entered by mistake, wrong customer, duplicate).
2. **Components and layout:** Pluto Dialog with summary rows and footer Keep / Confirm. _Source: Sal-DlgCancel.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-sal-dlgcancel)): Keep → ui:close the dialog<br>Confirm → POST /sales-invoices/{id}/cancel
6. **Tables:** —
7. **Validation and rules:** BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances.<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.
8. **Roles allowed:** `sales.edit` → Owner, Finance manager, Accountant, Sales staff. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /sales-invoices/{id}/cancel`](05-API-Specification.md#postsalesinvoicesidcancel)
10. **Entities:** `audit_logs`, `bill_allocations`, `journal_entries`, `journal_lines`, `number_sequences`, `sales_invoices`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-041, FR-046
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** [E5](#page-e5), [E6](#page-e6)

<a id="page-sal-dlgdelete"></a>
#### Sal-DlgDelete · Dialog · Delete draft

1. **Purpose:** Danger dialog; drafts never reached the books.
2. **Components and layout:** Pluto Dialog with summary rows and footer Keep / Confirm. _Source: Sal-DlgDelete.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-sal-dlgdelete)): Keep → ui:close the dialog<br>Confirm → DELETE /sales-invoices/{id}
6. **Tables:** —
7. **Validation and rules:** BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances.<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.
8. **Roles allowed:** `sales.delete` → Owner. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`DELETE /sales-invoices/{id}`](05-API-Specification.md#deletesalesinvoicesid)
10. **Entities:** `audit_logs`, `sales_invoice_lines`, `sales_invoices`
11. **Workflows:** — · FR: FR-041, FR-046
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** [E5](#page-e5), [E6](#page-e6)

### Purchases


<a id="page-f1"></a>
#### F1 · Purchase overview

1. **Purpose:** Purchases this month, bills, owed to suppliers, open POs; bars; status; recent; top suppliers.
2. **Components and layout:** As E1 with supplier language. _Source: Pur-Overview.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-f1)): New purchase order → nav:F8<br>New bill → nav:F3
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `purchases.view` → Owner, Finance manager, Accountant, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /purchases/overview`](05-API-Specification.md#getpurchasesoverview), [`GET /purchase-bills`](05-API-Specification.md#getpurchasebills), [`GET /purchase-orders`](05-API-Specification.md#getpurchaseorders)
10. **Entities:** `parties`, `purchase_bills`, `purchase_orders`
11. **Workflows:** — · FR: FR-050
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [F2](#page-f2), [F3](#page-f3), [F8](#page-f8)

<a id="page-f2"></a>
#### F2 · Purchase bill list

1. **Purpose:** Every supplier bill with tabs All, Drafts, Unpaid, Part paid, Overdue, Paid.
2. **Components and layout:** As E2 plus Supplier ref column. _Source: Pur-List.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | bill, supplier or reference |
   | From / to | date | opt |  |
   | Supplier | select | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f2)): New bill → nav:F3<br>Export → export:GET /purchase-bills<br>Open → nav:F4
6. **Table:** columns Bill, Date, Supplier, Supplier ref, Due, Status, Amount (৳), Balance (৳); filters status, date, party; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `purchases.view` → Owner, Finance manager, Accountant, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /purchase-bills`](05-API-Specification.md#getpurchasebills)
10. **Entities:** `export_jobs`, `parties`, `purchase_bills`
11. **Workflows:** — · FR: FR-050
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [F3](#page-f3), [F4](#page-f4)

<a id="page-f3"></a>
#### F3 · Create purchase bill

1. **Purpose:** Enter the supplier's bill as received; items go into stock when posted.
2. **Components and layout:** As E3 with 'Cost' column, 'Receive into', supplier's bill number (required) and last-cost hints; import mode adds currency, exchange rate, LC and lot (GAP-06). _Source: Pur-Create.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Supplier | select | req | active supplier |
   | Bill number | readonly | — | BILL series |
   | Supplier's bill number | text | req | unique per supplier (BR-018) |
   | Bill date | date | req | open period |
   | Due date | date | req | >= bill date |
   | Payment terms | select | opt |  |
   | Receive into | select | req | warehouse |
   | Purchase order | select | opt | open PO of the supplier |
   | Currency / exchange rate | select/rate | cond | import mode; rate > 0 |
   | Letter of credit / lot | select | cond | import mode |
   | Lines: item, qty, cost, disc %, tax | table | req | qty > 0; cost >= 0 |
   | Notes, internal note | textarea | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f3)): Find item → GET /products<br>Add line → ui:add a line → POST /purchase-bills/calculate<br>Save draft → POST /purchase-bills \| PUT /purchase-bills/{id}<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Save bill > Post dialog → POST /purchase-bills \| PUT /purchase-bills/{id} → dlg:L6 → POST /purchase-bills/{id}/post<br>Delete draft (NEW) → dlg:L7 → DELETE /purchase-bills/{id}
6. **Tables:** —
7. **Validation and rules:** Duplicate supplier bill number: 'This supplier's bill CST-0918 is already entered as BILL-2026-0219'<br>Line errors as E3<br>BR-018: A purchase bill requires the supplier's bill number, unique per supplier within the company.<br>BR-019: Posting a bill increases stock in the receiving warehouse and debits that warehouse's inventory ledger for stocked lines, or the line's expense ledger for non-stock lines; the supplier is credited.<br>BR-017: On every stock-in to a warehouse, that warehouse's average cost = (warehouse value + incoming value) / (warehouse quantity + incoming quantity); when current quantity <= 0 the incoming unit cost becomes the average.<br>BR-010: Money uses decimal arithmetic only. Line amounts are rounded half-up to 2 decimals; document totals are the sum of rounded lines; the server's calculation is authoritative.<br>BR-011: Line gross = quantity x unit price; discount = gross x discount% ; tax = (gross - discount) x tax rate; line total = gross - discount + tax.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.<br>BR-050: Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.<br>BR-043: Differences between the rate at which a foreign bill was booked and the rate at which it is paid post to the exchange gain or loss ledger.
8. **Roles allowed:** `purchases.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /payment-terms`](05-API-Specification.md#getpaymentterms), [`GET /tax-rules`](05-API-Specification.md#gettaxrules), [`GET /parties`](05-API-Specification.md#getparties), [`GET /products`](05-API-Specification.md#getproducts), [`GET /warehouses`](05-API-Specification.md#getwarehouses), [`POST /purchase-bills/calculate`](05-API-Specification.md#postpurchasebillscalculate), [`POST /purchase-bills`](05-API-Specification.md#postpurchasebills), [`PUT /purchase-bills/{id}`](05-API-Specification.md#putpurchasebillsid), [`DELETE /purchase-bills/{id}`](05-API-Specification.md#deletepurchasebillsid), [`POST /purchase-bills/{id}/post`](05-API-Specification.md#postpurchasebillsidpost), [`GET /purchase-orders`](05-API-Specification.md#getpurchaseorders), [`GET /letters-of-credit`](05-API-Specification.md#getlettersofcredit)
10. **Entities:** `account_mappings`, `accounting_periods`, `audit_logs`, `company_settings`, `journal_entries`, `journal_lines`, `lc_charges`, `lc_documents`, `lc_payments`, `lc_shipments`, `ledgers`, `letters_of_credit`, `money_accounts`, `number_sequences`, `parties`, `party_groups`, `payment_terms`, `product_categories`, `product_warehouse_settings`, `products`, `purchase_bill_lines`, `purchase_bills`, `purchase_order_lines`, `purchase_orders`, `sales_invoices`, `stock_balances`, `stock_movements`, `tax_rules`, `units`, `warehouses`
11. **Workflows:** Purchase-to-inventory-to-accounting, Restock low items · FR: FR-051, FR-098
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [C2](#page-c2), [D2](#page-d2), [F8](#page-f8), [M4](#page-m4)

<a id="page-f4"></a>
#### F4 · Purchase bill details

1. **Purpose:** Bill summary, lines, payments, ledger entries, activity, receipt of goods, attachments.
2. **Components and layout:** As E5 with 'Receipt of goods' facts and linked return. _Source: Pur-Details.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-f4)): Record payment (Alt Y) → nav:F5<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Create debit note → nav:F7<br>Cancel bill (dialog) → dlg:L6 → POST /purchase-bills/{id}/cancel<br>Apply advance or credit (NEW, R-08) → GET /parties/{id}/open-documents → POST /allocations<br>Remove allocation (NEW, R-08) → dlg:L6 → DELETE /allocations/{id}
6. **Tables:** —
7. **Validation and rules:** BR-024: Cancelling a posted invoice or bill requires a reason, posts a cancellation entry that reverses the original journal and stock, and turns its allocated payments into advances.
8. **Roles allowed:** `purchases.view` → Owner, Finance manager, Accountant, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /attachments`](05-API-Specification.md#getattachments), [`GET /attachments/{id}/url`](05-API-Specification.md#getattachmentsidurl), [`GET /audit-logs`](05-API-Specification.md#getauditlogs), [`GET /parties/{id}/open-documents`](05-API-Specification.md#getpartiesidopendocuments), [`POST /allocations`](05-API-Specification.md#postallocations), [`DELETE /allocations/{id}`](05-API-Specification.md#deleteallocationsid), [`GET /purchase-bills/{id}`](05-API-Specification.md#getpurchasebillsid), [`POST /purchase-bills/{id}/cancel`](05-API-Specification.md#postpurchasebillsidcancel), [`POST /supplier-payments`](05-API-Specification.md#postsupplierpayments)
10. **Entities:** `approval_requests`, `attachments`, `audit_logs`, `bill_allocations`, `credit_notes`, `debit_notes`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `payment_details`, `purchase_bill_lines`, `purchase_bills`, `roles`, `sales_invoices`, `stock_balances`, `stock_movements`, `users`
11. **Workflows:** — · FR: FR-051
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [F5](#page-f5), [F6](#page-f6)

<a id="page-f5"></a>
#### F5 · Supplier payment

1. **Purpose:** Money out to a supplier, applied to open bills.
2. **Components and layout:** As E7 with 'Pay to', 'Pay from' (account balances shown), and confirm dialog. _Source: Pur-Payment.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Pay to | select | req |  |
   | Amount paid | money | req | > 0 and <= account balance (BR-031) |
   | Pay from | select | req | money account (use_for_payments) |
   | Method and cheque/reference | — | cond | as E7 |
   | Apply per bill | money | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f5)): Re-apply oldest first → ui:re-allocate oldest due first on the client (the server re-validates, BR-020)<br>Print voucher → GET /supplier-payments/{id}/pdf<br>Save payment (confirm) → dlg:Pur-DlgPay
6. **Tables:** —
7. **Validation and rules:** BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-043: Differences between the rate at which a foreign bill was booked and the rate at which it is paid post to the exchange gain or loss ledger.
8. **Roles allowed:** `purchases.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties`](05-API-Specification.md#getparties), [`GET /parties/{id}/open-documents`](05-API-Specification.md#getpartiesidopendocuments), [`POST /supplier-payments`](05-API-Specification.md#postsupplierpayments), [`GET /supplier-payments/{id}`](05-API-Specification.md#getsupplierpaymentsid), [`GET /supplier-payments/{id}/pdf`](05-API-Specification.md#getsupplierpaymentsidpdf), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts)
10. **Entities:** `approval_requests`, `bank_statement_lines`, `bill_allocations`, `companies`, `credit_notes`, `debit_notes`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `parties`, `party_groups`, `payment_details`, `payment_terms`, `purchase_bills`, `roles`, `sales_invoices`
11. **Workflows:** Pay a supplier · FR: FR-052
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [F4](#page-f4), [C6](#page-c6), [G12](#page-g12), [Pur-DlgPay](#page-pur-dlgpay)

<a id="page-f6"></a>
#### F6 · Purchase return

1. **Purpose:** Goods going back to a supplier; creates a debit note.
2. **Components and layout:** As E8; stock options 'Send back' / 'Already disposed'. _Source: Pur-Return.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Original bill | select | req |  |
   | Return qty, reason per line | table | req | BR-022 |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f6)): Save draft → POST /purchase-returns \| PUT /purchase-returns/{id}<br>Save return and create debit note → POST /purchase-returns \| PUT /purchase-returns/{id} → POST /purchase-returns/{id}/post → nav:F7
6. **Tables:** —
7. **Validation and rules:** BR-022: Returned quantity per line <= original quantity - quantity already returned.<br>BR-057: A purchase return credits inventory at the original bill cost of the returned lines; the supplier is debited through the debit note.
8. **Roles allowed:** `purchases.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /purchase-returns`](05-API-Specification.md#getpurchasereturns), [`POST /purchase-returns`](05-API-Specification.md#postpurchasereturns), [`GET /purchase-returns/{id}`](05-API-Specification.md#getpurchasereturnsid), [`PUT /purchase-returns/{id}`](05-API-Specification.md#putpurchasereturnsid), [`POST /purchase-returns/{id}/post`](05-API-Specification.md#postpurchasereturnsidpost)
10. **Entities:** `audit_logs`, `bill_allocations`, `debit_notes`, `journal_entries`, `journal_lines`, `number_sequences`, `purchase_bill_lines`, `purchase_bills`, `purchase_return_lines`, `purchase_returns`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-053
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [F7](#page-f7)

<a id="page-f7"></a>
#### F7 · Debit note

1. **Purpose:** Reduce what you owe: apply to a bill, keep as credit, or ask for a refund.
2. **Components and layout:** As E9. _Source: Pur-Debit.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Reason | select | req |  |
   | Amount | money | req |  |
   | Usage | radio cards | req |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f7)): Back → nav:*<br>Print → GET /debit-notes/{id}/pdf<br>Post debit note → POST /debit-notes \| PUT /debit-notes/{id} → dlg:L6 → POST /debit-notes/{id}/post
6. **Tables:** —
7. **Validation and rules:** BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).<br>BR-057: A purchase return credits inventory at the original bill cost of the returned lines; the supplier is debited through the debit note.
8. **Roles allowed:** `purchases.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /debit-notes/{id}`](05-API-Specification.md#getdebitnotesid), [`PUT /debit-notes/{id}`](05-API-Specification.md#putdebitnotesid), [`POST /debit-notes`](05-API-Specification.md#postdebitnotes), [`POST /debit-notes/{id}/post`](05-API-Specification.md#postdebitnotesidpost), [`GET /debit-notes/{id}/pdf`](05-API-Specification.md#getdebitnotesidpdf)
10. **Entities:** `bill_allocations`, `companies`, `debit_notes`, `journal_entries`, `journal_lines`, `number_sequences`, `parties`, `purchase_bills`
11. **Workflows:** — · FR: FR-053
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [F6](#page-f6) · Note: GAP-03

<a id="page-f8"></a>
#### F8 · Purchase order

1. **Purpose:** Ask a supplier for goods; nothing posts until received.
2. **Components and layout:** Invoice form variant with Expected delivery, Deliver to, Created from. _Source: Pur-Order.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Supplier | select | req |  |
   | Order date, expected delivery | date | req/opt |  |
   | Deliver to | select | req |  |
   | Currency | select | opt | BDT default; foreign for imports |
   | Lines | table | req |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f8)): Save draft → POST /purchase-orders \| PUT /purchase-orders/{id}<br>Send order → POST /purchase-orders/{id}/send<br>Convert to bill → POST /purchase-orders/{id}/convert → nav:F3<br>Create LC → nav:M3
6. **Tables:** —
7. **Validation and rules:** BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities.
8. **Roles allowed:** `purchases.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /products`](05-API-Specification.md#getproducts), [`POST /purchase-bills/calculate`](05-API-Specification.md#postpurchasebillscalculate), [`GET /purchase-orders`](05-API-Specification.md#getpurchaseorders), [`POST /purchase-orders`](05-API-Specification.md#postpurchaseorders), [`GET /purchase-orders/{id}`](05-API-Specification.md#getpurchaseordersid), [`PUT /purchase-orders/{id}`](05-API-Specification.md#putpurchaseordersid), [`POST /purchase-orders/{id}/send`](05-API-Specification.md#postpurchaseordersidsend), [`POST /purchase-orders/{id}/convert`](05-API-Specification.md#postpurchaseordersidconvert), [`POST /purchase-orders/{id}/status`](05-API-Specification.md#postpurchaseordersidstatus), [`GET /purchase-orders/{id}/pdf`](05-API-Specification.md#getpurchaseordersidpdf), [`POST /letters-of-credit`](05-API-Specification.md#postlettersofcredit)
10. **Entities:** `companies`, `company_settings`, `lc_document_template_items`, `lc_documents`, `lc_shipments`, `letters_of_credit`, `number_sequences`, `parties`, `product_categories`, `product_warehouse_settings`, `products`, `purchase_bill_lines`, `purchase_bills`, `purchase_order_lines`, `purchase_orders`, `stock_balances`, `tax_rules`, `units`
11. **Workflows:** Restock low items, Import and LC · FR: FR-054
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [D12](#page-d12), [F3](#page-f3), [M3](#page-m3)

<a id="page-f9"></a>
#### F9 · Purchase report

1. **Purpose:** Purchases and returns by supplier, category or month.
2. **Components and layout:** As E11. _Source: Pur-Report.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-f9)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/purchases
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/purchases`](05-API-Specification.md#getreportspurchases)
10. **Entities:** `ie_categories`, `journal_lines`, `parties`, `product_categories`, `purchase_bill_lines`, `purchase_bills`, `purchase_returns`, `sales_invoice_lines`, `sales_invoices`, `sales_returns`
11. **Workflows:** — · FR: FR-055
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [J1](#page-j1)

<a id="page-f10"></a>
#### F10 · Purchase return report

1. **Purpose:** Every return to suppliers and its debit note.
2. **Components and layout:** As E12. _Source: Pur-ReturnReport.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-f10)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/purchase-returns
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/purchase-returns`](05-API-Specification.md#getreportspurchasereturns)
10. **Entities:** `ie_categories`, `journal_lines`, `parties`, `product_categories`, `purchase_bill_lines`, `purchase_bills`, `purchase_returns`, `sales_invoice_lines`, `sales_invoices`, `sales_returns`
11. **Workflows:** — · FR: FR-055
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [F6](#page-f6)

<a id="page-f11"></a>
#### F11 · Purchase orders (NEW)

1. **Purpose:** Every purchase order with supplier, dates, currency, total and receipt status; the entry point for the sidebar item Purchase orders.
2. **Components and layout:** List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | number or supplier |
   | Status | segmented | opt | all, draft, sent, partly received, received, closed, cancelled |
   | Date range | date | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f11)): New purchase order → nav:F8<br>Open → nav:F8<br>Convert to bill (row) → POST /purchase-orders/{id}/convert → nav:F3<br>Close or cancel (row) → dlg:L6 → POST /purchase-orders/{id}/status<br>Print (row) → GET /purchase-orders/{id}/pdf → ui:print<br>Delete draft (row) → dlg:L7 → DELETE /purchase-orders/{id}<br>Export → export:GET /purchase-orders
6. **Table:** columns Number, Supplier, Order date, Expected, Currency, Total, Status; filters status, date; search; server-side pagination and sorting.
7. **Validation and rules:** BR-056: Quotations and purchase orders never post; converting copies lines and prices; bills update purchase-order received quantities.<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.
8. **Roles allowed:** `purchases.view` → Owner, Finance manager, Accountant, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /purchase-orders`](05-API-Specification.md#getpurchaseorders), [`POST /purchase-orders/{id}/convert`](05-API-Specification.md#postpurchaseordersidconvert), [`POST /purchase-orders/{id}/status`](05-API-Specification.md#postpurchaseordersidstatus), [`DELETE /purchase-orders/{id}`](05-API-Specification.md#deletepurchaseordersid), [`GET /purchase-orders/{id}/pdf`](05-API-Specification.md#getpurchaseordersidpdf)
10. **Entities:** `audit_logs`, `companies`, `export_jobs`, `number_sequences`, `parties`, `purchase_bill_lines`, `purchase_bills`, `purchase_order_lines`, `purchase_orders`
11. **Workflows:** — · FR: FR-054
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [F8](#page-f8) · Note: GAP-24

<a id="page-f12"></a>
#### F12 · Payments made (NEW)

1. **Purpose:** Supplier payments (PV) with supplier, account, method, amount and approval status; the entry point for the sidebar item Payments made.
2. **Components and layout:** List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | voucher or supplier |
   | Status | select | opt | posted, awaiting approval, rejected |
   | Account | select | opt |  |
   | Date range | date | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f12)): New payment → nav:F5<br>Open (drawer) → GET /supplier-payments/{id}<br>Print voucher (row) → GET /supplier-payments/{id}/pdf → ui:print<br>Export → export:GET /supplier-payments
6. **Table:** columns Voucher, Date, Supplier, Account, Method, Amount (৳), Status; filters status, account, date; search; server-side pagination and sorting.
7. **Validation and rules:** BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.
8. **Roles allowed:** `purchases.view` → Owner, Finance manager, Accountant, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /supplier-payments`](05-API-Specification.md#getsupplierpayments), [`GET /supplier-payments/{id}`](05-API-Specification.md#getsupplierpaymentsid), [`GET /supplier-payments/{id}/pdf`](05-API-Specification.md#getsupplierpaymentsidpdf), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts)
10. **Entities:** `approval_requests`, `bank_statement_lines`, `bill_allocations`, `companies`, `export_jobs`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `parties`, `payment_details`, `purchase_bills`
11. **Workflows:** — · FR: FR-052
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [F5](#page-f5), [C4](#page-c4) · Note: GAP-24

<a id="page-f13"></a>
#### F13 · Returns and debit notes (NEW)

1. **Purpose:** Purchase returns and debit notes in one list; the entry point for the sidebar item Returns and debit notes.
2. **Components and layout:** List template as E2 (NEW, GAP-24): page header with New; status segmented filter; toolbar (search, date range, party); DataTable with row menu; pagination.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Type | segmented | opt | all, returns, debit notes |
   | Status | select | opt | draft, posted, cancelled |
   | Date range | date | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-f13)): New return → nav:F6<br>New debit note → nav:F7<br>Open → nav:F6 \| nav:F7<br>Print debit note (row) → GET /debit-notes/{id}/pdf → ui:print<br>Delete draft (row) → dlg:L7 → DELETE /purchase-returns/{id} \| dlg:L7 → DELETE /debit-notes/{id}<br>Cancel posted (row, dialog) → dlg:L6 → POST /purchase-returns/{id}/cancel \| dlg:L6 → POST /debit-notes/{id}/cancel<br>Export → export:GET /purchase-returns \| export:GET /debit-notes
6. **Table:** columns Number, Type, Date, Supplier, Bill, Amount (৳), Usage, Status; filters type, status, date; search; server-side pagination and sorting.
7. **Validation and rules:** BR-022: Returned quantity per line <= original quantity - quantity already returned.<br>BR-023: A credit/debit note applied to a document cannot exceed that document's balance; a refund usage requires recording a payment out (credit note) or money in (debit note).<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.<br>BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-057: A purchase return credits inventory at the original bill cost of the returned lines; the supplier is debited through the debit note.
8. **Roles allowed:** `purchases.view` → Owner, Finance manager, Accountant, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /purchase-returns`](05-API-Specification.md#getpurchasereturns), [`DELETE /purchase-returns/{id}`](05-API-Specification.md#deletepurchasereturnsid), [`POST /purchase-returns/{id}/cancel`](05-API-Specification.md#postpurchasereturnsidcancel), [`GET /debit-notes`](05-API-Specification.md#getdebitnotes), [`DELETE /debit-notes/{id}`](05-API-Specification.md#deletedebitnotesid), [`POST /debit-notes/{id}/cancel`](05-API-Specification.md#postdebitnotesidcancel), [`GET /debit-notes/{id}/pdf`](05-API-Specification.md#getdebitnotesidpdf)
10. **Entities:** `audit_logs`, `bill_allocations`, `companies`, `debit_notes`, `export_jobs`, `journal_entries`, `journal_lines`, `number_sequences`, `parties`, `purchase_bill_lines`, `purchase_bills`, `purchase_return_lines`, `purchase_returns`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-053
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [F6](#page-f6), [F7](#page-f7) · Note: GAP-24

<a id="page-pur-dlgpay"></a>
#### Pur-DlgPay · Dialog · Confirm supplier payment

1. **Purpose:** Summary of payment before saving.
2. **Components and layout:** Dialog with To, Amount, Applied, From. _Source: Pur-DlgPay.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-pur-dlgpay)): Cancel → ui:close the dialog<br>Save payment → POST /supplier-payments
6. **Tables:** —
7. **Validation and rules:** BR-050: Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.
8. **Roles allowed:** `purchases.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /supplier-payments`](05-API-Specification.md#postsupplierpayments)
10. **Entities:** `approval_requests`, `bill_allocations`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `payment_details`, `purchase_bills`, `roles`
11. **Workflows:** — · FR: FR-052
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** [F5](#page-f5)

### Banking


<a id="page-g1"></a>
#### G1 · Cash and bank overview

1. **Purpose:** Every place money sits and what moved this month.
2. **Components and layout:** 4 tiles; account buttons with balance, status and share; 30-day combined chart; quick actions; reconciliation banner; recent movements. _Source: Banking.dc.html (G1)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-g1)): Transfer → nav:G8 \| nav:G9<br>Money paid → nav:G7<br>Money received → nav:G6<br>Reconcile → POST /reconciliations → nav:G10<br>Open account → nav:G2 \| nav:G5
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /banking/overview`](05-API-Specification.md#getbankingoverview), [`GET /money-transactions`](05-API-Specification.md#getmoneytransactions), [`POST /reconciliations`](05-API-Specification.md#postreconciliations)
10. **Entities:** `bank_reconciliations`, `bank_statement_lines`, `journal_entries`, `journal_lines`, `money_accounts`, `payment_details`
11. **Workflows:** — · FR: FR-060
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [G2](#page-g2), [G5](#page-g5), [G6](#page-g6), [G7](#page-g7), [G10](#page-g10)

<a id="page-g2"></a>
#### G2 · Cash account details

1. **Purpose:** Cash book this month and the last cash count by denomination.
2. **Components and layout:** Cash-book card; aside cash count (denominations, counted total, match badge) and account facts. _Source: Bnk-Cash.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Denomination counts | number | req | >= 0 integers |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g2)): Cash transfer → nav:G8<br>Count cash (records cash count; difference needs approval) → POST /cash-counts<br>Reverse cash-count adjustment (NEW, R-03) → dlg:L6 → POST /cash-counts/{id}/reverse
6. **Tables:** —
7. **Validation and rules:** BR-058: A cash-count difference posts to the cash short/over ledger and requires approval.
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /attachments`](05-API-Specification.md#getattachments), [`GET /money-accounts/{id}`](05-API-Specification.md#getmoneyaccountsid), [`GET /money-accounts/{id}/book`](05-API-Specification.md#getmoneyaccountsidbook), [`POST /cash-counts`](05-API-Specification.md#postcashcounts), [`POST /cash-counts/{id}/reverse`](05-API-Specification.md#postcashcountsidreverse)
10. **Entities:** `approval_requests`, `attachments`, `audit_logs`, `bank_statement_imports`, `cash_count_denominations`, `cash_counts`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `payment_details`
11. **Workflows:** — · FR: FR-060, FR-065
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [G11](#page-g11)

<a id="page-g3"></a>
#### G3 · Bank account list

1. **Purpose:** Bank and wallet accounts with reconciliation status and book balance.
2. **Components and layout:** Table with totals and actions; info banner on statement import. _Source: Bnk-Banks.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-g3)): Add account → nav:G4<br>Open → nav:G5<br>Import statement → POST /bank-statement-imports
6. **Table:** columns Bank, Branch, Account, Type, Reconciled to, Status, Book balance (৳); server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`POST /bank-statement-imports`](05-API-Specification.md#postbankstatementimports)
10. **Entities:** `bank_statement_imports`, `bank_statement_lines`, `journal_lines`, `ledgers`, `money_accounts`
11. **Workflows:** — · FR: FR-060
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [G4](#page-g4), [G5](#page-g5)

<a id="page-g4"></a>
#### G4 · Add bank account

1. **Purpose:** Add a bank, mobile-wallet or card account; its ledger is created.
2. **Components and layout:** Account type segmented; Account details card; Opening balance card; Use-for switches; aside invoice preview. _Source: Bnk-AddBank.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Account type | segmented | req | bank/wallet/card/cash credit (NEW option, R-13) |
   | Overdraft / cash-credit limit | money | cond | NEW (R-13); >= 0; bank and cash credit only |
   | Bank or provider | select | req |  |
   | Branch | text | opt |  |
   | Account name | text | req |  |
   | Account number | text | req | >= 10 digits for banks |
   | Routing number | text | opt | 9 digits |
   | Currency | readonly | — | BDT |
   | Opening balance | money | opt |  |
   | As of | date | opt |  |
   | Ledger | readonly | — | created under 1100 |
   | Receiving customer payments / Paying suppliers / Show on invoices | switch | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g4)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Add account → POST /money-accounts
6. **Tables:** —
7. **Validation and rules:** 'Enter the full account number, at least 10 digits'<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.
8. **Roles allowed:** `banking.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /money-accounts`](05-API-Specification.md#postmoneyaccounts)
10. **Entities:** `account_mappings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`
11. **Workflows:** — · FR: FR-061
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [G3](#page-g3)

<a id="page-g5"></a>
#### G5 · Bank account details

1. **Purpose:** Bank book this month and statement imports.
2. **Components and layout:** Bank-book card; aside facts and statement imports list. _Source: Bnk-Bank.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-g5)): Reconcile → POST /reconciliations → nav:G10<br>Import statement → POST /bank-statement-imports<br>Bank book → nav:G12<br>Edit account (NEW) → PATCH /money-accounts/{id}
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-accounts/{id}`](05-API-Specification.md#getmoneyaccountsid), [`PATCH /money-accounts/{id}`](05-API-Specification.md#patchmoneyaccountsid), [`GET /money-accounts/{id}/book`](05-API-Specification.md#getmoneyaccountsidbook), [`POST /bank-statement-imports`](05-API-Specification.md#postbankstatementimports), [`POST /reconciliations`](05-API-Specification.md#postreconciliations)
10. **Entities:** `bank_reconciliations`, `bank_statement_imports`, `bank_statement_lines`, `cash_count_denominations`, `cash_counts`, `journal_entries`, `journal_lines`, `money_accounts`, `payment_details`
11. **Workflows:** — · FR: FR-060
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [G10](#page-g10), [G12](#page-g12)

<a id="page-g6"></a>
#### G6 · Money received

1. **Purpose:** Non-invoice money in: other income, owner or loan, or a customer (redirects to E7).
2. **Components and layout:** Who segmented; party/payee; category account; voucher number; date; amount; account; method; reference; narration; attachment; aside balance before/after and posting. _Source: Bnk-Received.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Received from / Paid to | segmented | req | customer / other / owner or loan |
   | Payer / Payee | text | req |  |
   | Income / Expense account | select | req | ledger |
   | Date | date | req |  |
   | Amount | money | req | > 0 |
   | Account | select | req | money account |
   | Method | segmented | req |  |
   | Reference | text | opt |  |
   | Narration | text | opt |  |
   | Attachment | file | opt | JPG/PNG/PDF <= 10 MB |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g6)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save and new → POST /vouchers → ui:reset the form<br>Save → POST /vouchers
6. **Tables:** —
7. **Validation and rules:** 'Enter an amount greater than zero'<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-051: Attachments must be JPG, PNG or PDF up to 10 MB, verified by content type sniffing.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-050: Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.
8. **Roles allowed:** `banking.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`POST /vouchers`](05-API-Specification.md#postvouchers), [`POST /vouchers/calculate`](05-API-Specification.md#postvoucherscalculate)
10. **Entities:** `approval_requests`, `attachments`, `bank_statement_lines`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `payment_details`, `roles`
11. **Workflows:** — · FR: FR-062
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [G1](#page-g1)

<a id="page-g7"></a>
#### G7 · Money paid

1. **Purpose:** Non-bill money out: an expense, owner or loan, or a supplier (redirects to F5).
2. **Components and layout:** Who segmented; party/payee; category account; voucher number; date; amount; account; method; reference; narration; attachment; aside balance before/after and posting. _Source: Bnk-Paid.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Received from / Paid to | segmented | req | customer / other / owner or loan |
   | Payer / Payee | text | req |  |
   | Income / Expense account | select | req | ledger |
   | Date | date | req |  |
   | Amount | money | req | > 0 and <= account balance |
   | Account | select | req | money account |
   | Method | segmented | req |  |
   | Reference | text | opt |  |
   | Narration | text | opt |  |
   | Attachment | file | opt | JPG/PNG/PDF <= 10 MB |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g7)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save and new → POST /vouchers → ui:reset the form<br>Save → POST /vouchers
6. **Tables:** —
7. **Validation and rules:** 'Enter an amount greater than zero'<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-051: Attachments must be JPG, PNG or PDF up to 10 MB, verified by content type sniffing.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-050: Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.
8. **Roles allowed:** `banking.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`POST /vouchers`](05-API-Specification.md#postvouchers), [`POST /vouchers/calculate`](05-API-Specification.md#postvoucherscalculate)
10. **Entities:** `approval_requests`, `attachments`, `bank_statement_lines`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `payment_details`, `roles`
11. **Workflows:** — · FR: FR-062
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [G1](#page-g1)

<a id="page-g8"></a>
#### G8 · Cash transfer

1. **Purpose:** Move money between your own accounts (contra entry).
2. **Components and layout:** From/To cards with now/after balances; amount; date; voucher number; handed to; narration; aside contra explanation and posting. _Source: Bnk-CashTransfer.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From | select | req |  |
   | To | select | req | != From |
   | Amount | money | req | > 0, <= source balance |
   | Date | date | req |  |
   | Handed to | text | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g8)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save transfer → POST /vouchers
6. **Tables:** —
7. **Validation and rules:** 'More than the source account holds'<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.
8. **Roles allowed:** `banking.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`POST /vouchers`](05-API-Specification.md#postvouchers), [`POST /vouchers/calculate`](05-API-Specification.md#postvoucherscalculate)
10. **Entities:** `approval_requests`, `bank_statement_lines`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `payment_details`, `roles`
11. **Workflows:** — · FR: FR-063
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [G1](#page-g1)

<a id="page-g9"></a>
#### G9 · Bank transfer

1. **Purpose:** Move money between your own accounts (contra entry).
2. **Components and layout:** From/To cards with now/after balances; amount; date; voucher number; method, bank charge, bank reference; narration; aside contra explanation and posting. _Source: Bnk-BankTransfer.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From | select | req |  |
   | To | select | req | != From |
   | Amount | money | req | > 0, <= source balance |
   | Date | date | req |  |
   | Transfer method | select | opt | BEFTN, RTGS, NPSB, Cheque |
   | Bank charge | money | opt | >= 0; posts to Bank charges |
   | Bank reference | text | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g9)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save transfer → POST /vouchers
6. **Tables:** —
7. **Validation and rules:** 'More than the source account holds'<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.
8. **Roles allowed:** `banking.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`POST /vouchers`](05-API-Specification.md#postvouchers), [`POST /vouchers/calculate`](05-API-Specification.md#postvoucherscalculate)
10. **Entities:** `approval_requests`, `bank_statement_lines`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `payment_details`, `roles`
11. **Workflows:** — · FR: FR-063
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [G1](#page-g1)

<a id="page-g10"></a>
#### G10 · Bank reconciliation

1. **Purpose:** Match statement lines to the books; create missing entries; finish at zero difference.
2. **Components and layout:** Summary strip (statement, book, matched, difference); filter segmented; accept all; import; two-column rows (statement | books) with Match / Create receipt / Record charge / Mark as pair / Undo; footer Save progress / Finish. _Source: Bnk-Reconcile.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Filter | segmented | opt | to match / matched / all |
   | Statement file | file | cond | CSV or XLSX |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g10)): Import statement → POST /bank-statement-imports → POST /reconciliations<br>Accept all suggestions → POST /reconciliations/{id}/accept-suggestions<br>Match → POST /reconciliations/{id}/lines/{lineId}/match<br>Create receipt → POST /reconciliations/{id}/lines/{lineId}/create-entry<br>Record charge → POST /reconciliations/{id}/lines/{lineId}/create-entry (kind=bank_charge)<br>Mark as pair → POST /reconciliations/{id}/lines/pair<br>Undo → POST /reconciliations/{id}/lines/{lineId}/undo<br>Save progress → ui:nothing to send; every match is saved when made<br>Finish reconciliation → dlg:L6 → POST /reconciliations/{id}/finish
6. **Tables:** —
7. **Validation and rules:** Finish disabled until all lines handled and difference 0 (BR-032)<br>Re-import of the same file rejected<br>BR-032: A reconciliation finishes only when every statement line is matched, created, paired or ignored and the difference is zero.<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.
8. **Roles allowed:** `banking.reconcile` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /bank-statement-imports`](05-API-Specification.md#postbankstatementimports), [`POST /reconciliations`](05-API-Specification.md#postreconciliations), [`GET /reconciliations/{id}`](05-API-Specification.md#getreconciliationsid), [`POST /reconciliations/{id}/lines/{lineId}/match`](05-API-Specification.md#postreconciliationsidlineslineidmatch), [`POST /reconciliations/{id}/lines/{lineId}/create-entry`](05-API-Specification.md#postreconciliationsidlineslineidcreateentry), [`POST /reconciliations/{id}/lines/pair`](05-API-Specification.md#postreconciliationsidlinespair), [`POST /reconciliations/{id}/lines/{lineId}/undo`](05-API-Specification.md#postreconciliationsidlineslineidundo), [`POST /reconciliations/{id}/accept-suggestions`](05-API-Specification.md#postreconciliationsidacceptsuggestions), [`POST /reconciliations/{id}/finish`](05-API-Specification.md#postreconciliationsidfinish)
10. **Entities:** `audit_logs`, `bank_reconciliations`, `bank_statement_imports`, `bank_statement_lines`, `journal_entries`, `journal_lines`, `money_accounts`, `number_sequences`, `payment_details`
11. **Workflows:** Close the month · FR: FR-064
12. **States:** Loading lines; 'Everything is reconciled' empty state (L9); errors per action with Undo.
13. **Depends on:** [G5](#page-g5), [H15](#page-h15)

<a id="page-g11"></a>
#### G11 · Cash book

1. **Purpose:** Cash book: receipts and payments with running balance for one account.
2. **Components and layout:** Account select, date range, every-entry / daily-totals; summary; table. _Source: Bnk-CashBook.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Account | select | req |  |
   | From / to | date | req |  |
   | View | segmented | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g11)): Open voucher → nav:*<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /money-accounts/{id}/book
6. **Table:** columns Date, Voucher, Particulars, Receipts (৳), Payments (৳), Balance (৳); filters account, date; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /money-accounts/{id}/book`](05-API-Specification.md#getmoneyaccountsidbook)
10. **Entities:** `export_jobs`, `journal_entries`, `journal_lines`, `payment_details`
11. **Workflows:** — · FR: FR-060, FR-076
12. **States:** As Report; opening and closing always shown, even with no entries.
13. **Depends on:** [J1](#page-j1)

<a id="page-g12"></a>
#### G12 · Bank book

1. **Purpose:** Bank book: receipts and payments with running balance for one account.
2. **Components and layout:** Account select, date range, every-entry / daily-totals; summary; table. _Source: Bnk-BankBook.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Account | select | req |  |
   | From / to | date | req |  |
   | View | segmented | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-g12)): Open voucher → nav:*<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /money-accounts/{id}/book
6. **Table:** columns Date, Voucher, Particulars, Receipts (৳), Payments (৳), Balance (৳); filters account, date; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /money-accounts/{id}/book`](05-API-Specification.md#getmoneyaccountsidbook)
10. **Entities:** `export_jobs`, `journal_entries`, `journal_lines`, `payment_details`
11. **Workflows:** — · FR: FR-060, FR-076
12. **States:** As Report; opening and closing always shown, even with no entries.
13. **Depends on:** [J1](#page-j1)

<a id="page-g13"></a>
#### G13 · Cash and bank transactions

1. **Purpose:** All money in, out and transfers across accounts.
2. **Components and layout:** Search, In/Out/Transfers segmented, account select; table; pagination. _Source: Bnk-History.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-g13)): Open voucher → nav:*
6. **Table:** columns Date, Voucher, Account, Particulars, Type, In (৳), Out (৳); filters type, account; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-transactions`](05-API-Specification.md#getmoneytransactions)
10. **Entities:** `journal_entries`, `journal_lines`, `money_accounts`, `payment_details`
11. **Workflows:** — · FR: FR-060
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [G1](#page-g1)

### IncExp


<a id="page-i1"></a>
#### I1 · Income overview

1. **Purpose:** Other income FY to date, by category and month, recent entries.
2. **Components and layout:** 4 tiles; category bars; month bars (budget line for expenses); recent table. _Source: Inc-Overview.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-i1)): Add income → nav:I3<br>Income report → nav:I8
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /ie-entries`](05-API-Specification.md#getieentries), [`GET /income-expenses/overview`](05-API-Specification.md#getincomeexpensesoverview)
10. **Entities:** `attachments`, `ie_categories`, `journal_entries`, `journal_lines`, `payment_details`
11. **Workflows:** — · FR: FR-068
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [I2](#page-i2)

<a id="page-i4"></a>
#### I4 · Expense overview

1. **Purpose:** Expenses FY to date, by category, by month against budget, missing receipts.
2. **Components and layout:** 4 tiles; category bars; month bars (budget line for expenses); recent table. _Source: IncExp.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-i4)): Add expense → nav:I6<br>Expense report → nav:I9
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /ie-entries`](05-API-Specification.md#getieentries), [`GET /income-expenses/overview`](05-API-Specification.md#getincomeexpensesoverview)
10. **Entities:** `attachments`, `ie_categories`, `journal_entries`, `journal_lines`, `payment_details`
11. **Workflows:** — · FR: FR-068
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [I5](#page-i5)

<a id="page-i2"></a>
#### I2 · Income transactions

1. **Purpose:** Posted lines on income category ledgers, whatever voucher created them (GAP-16).
2. **Components and layout:** Search, category, date range, 'Missing receipt only'; selectable table with totals; row actions attach receipt / edit. _Source: Inc-List.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt |  |
   | Category | select | opt |  |
   | From / to | date | opt |  |
   | Missing receipt only | checkbox | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-i2)): Attach receipt → POST /attachments<br>Open voucher → nav:*<br>Add income → nav:I3
6. **Table:** columns Date, Number, Description, Category, Received into, Receipt, Amount (৳); filters category, date, missing_receipt; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /ie-categories`](05-API-Specification.md#getiecategories), [`GET /ie-entries`](05-API-Specification.md#getieentries), [`GET /recurring-templates`](05-API-Specification.md#getrecurringtemplates)
10. **Entities:** `attachments`, `ie_categories`, `journal_entries`, `journal_lines`, `ledgers`, `payment_details`, `recurring_templates`
11. **Workflows:** — · FR: FR-068
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [I3](#page-i3)

<a id="page-i5"></a>
#### I5 · Expense transactions

1. **Purpose:** Posted lines on expense category ledgers, whatever voucher created them (GAP-16).
2. **Components and layout:** Search, category, date range, 'Missing receipt only'; selectable table with totals; row actions attach receipt / edit. _Source: Exp-List.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt |  |
   | Category | select | opt |  |
   | From / to | date | opt |  |
   | Missing receipt only | checkbox | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-i5)): Attach receipt → POST /attachments<br>Open voucher → nav:*<br>Add expense → nav:I6
6. **Table:** columns Date, Number, Description, Category, Paid from, Receipt, Amount (৳); filters category, date, missing_receipt; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /attachments/{id}/url`](05-API-Specification.md#getattachmentsidurl), [`GET /ie-categories`](05-API-Specification.md#getiecategories), [`GET /ie-entries`](05-API-Specification.md#getieentries), [`GET /recurring-templates`](05-API-Specification.md#getrecurringtemplates), [`PATCH /recurring-templates/{id}`](05-API-Specification.md#patchrecurringtemplatesid)
10. **Entities:** `attachments`, `ie_categories`, `journal_entries`, `journal_lines`, `ledgers`, `payment_details`, `recurring_templates`
11. **Workflows:** — · FR: FR-068
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [I6](#page-i6)

<a id="page-i3"></a>
#### I3 · Add income

1. **Purpose:** Lightweight income entry with receipt attachment and optional recurrence.
2. **Components and layout:** Fields grid; attachment drop zone; recurring switch reveals Repeats / Next on / Ends; aside summary and posting preview. _Source: Inc-Add.dc.html, Exp-AddErr.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Amount | money | req | > 0 |
   | Date | date | req |  |
   | Category | select | req |  |
   | Paid from / Received into | select | req | money account |
   | Payment method | segmented | opt |  |
   | Received from | text | opt |  |
   | Reference | text | opt |  |
   | Tax rule | select | opt |  |
   | Notes | text | opt |  |
   | Receipt or document | file | opt | JPG/PNG/PDF <= 10 MB |
   | Recurring | switch | opt |  |
   | Repeats | select | cond | weekly/monthly/quarterly/yearly |
   | Next on | date | cond |  |
   | Ends | select | cond | never/after 12/on date |
5. **Actions → API** ([full map](05-API-Specification.md#actions-i3)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save and add another → POST /ie-entries → ui:reset the form<br>Save income → POST /ie-entries
6. **Tables:** —
7. **Validation and rules:** 'Enter an amount greater than zero'<br>'Choose a category'<br>Budget warning at 90% (non-blocking)<br>BR-054: An expense or income entry needs amount > 0, a category and a money account; it posts the category ledger against the money account.<br>BR-048: Recurring templates create drafts only; nothing posts automatically.<br>BR-051: Attachments must be JPG, PNG or PDF up to 10 MB, verified by content type sniffing.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.
8. **Roles allowed:** `banking.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`GET /ie-categories`](05-API-Specification.md#getiecategories), [`POST /ie-entries`](05-API-Specification.md#postieentries)
10. **Entities:** `approval_requests`, `attachments`, `bank_statement_lines`, `ie_categories`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `payment_details`, `recurring_templates`
11. **Workflows:** — · FR: FR-066
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [I7](#page-i7), [G1](#page-g1)

<a id="page-i6"></a>
#### I6 · Add expense

1. **Purpose:** Lightweight expense entry with receipt attachment and optional recurrence.
2. **Components and layout:** Fields grid; attachment drop zone; recurring switch reveals Repeats / Next on / Ends; aside summary, budget bar and posting preview. _Source: Exp-Add.dc.html, Exp-AddErr.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Amount | money | req | > 0 |
   | Date | date | req |  |
   | Category | select | req |  |
   | Paid from / Received into | select | req | money account |
   | Payment method | segmented | opt |  |
   | Paid to | text | opt |  |
   | Reference | text | opt |  |
   | Tax rule | select | opt |  |
   | Notes | text | opt |  |
   | Receipt or document | file | opt | JPG/PNG/PDF <= 10 MB |
   | Recurring | switch | opt |  |
   | Repeats | select | cond | weekly/monthly/quarterly/yearly |
   | Next on | date | cond |  |
   | Ends | select | cond | never/after 12/on date |
5. **Actions → API** ([full map](05-API-Specification.md#actions-i6)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save and add another → POST /ie-entries → ui:reset the form<br>Save expense → POST /ie-entries<br>Remove attachment (NEW) → DELETE /attachments/{id}
6. **Tables:** —
7. **Validation and rules:** 'Enter an amount greater than zero'<br>'Choose a category'<br>Budget warning at 90% (non-blocking)<br>BR-054: An expense or income entry needs amount > 0, a category and a money account; it posts the category ledger against the money account.<br>BR-048: Recurring templates create drafts only; nothing posts automatically.<br>BR-051: Attachments must be JPG, PNG or PDF up to 10 MB, verified by content type sniffing.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.
8. **Roles allowed:** `banking.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /tax-rules`](05-API-Specification.md#gettaxrules), [`POST /attachments`](05-API-Specification.md#postattachments), [`DELETE /attachments/{id}`](05-API-Specification.md#deleteattachmentsid), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`GET /ie-categories`](05-API-Specification.md#getiecategories), [`POST /ie-entries`](05-API-Specification.md#postieentries)
10. **Entities:** `approval_requests`, `attachments`, `audit_logs`, `bank_statement_lines`, `ie_categories`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `payment_details`, `recurring_templates`, `tax_rules`
11. **Workflows:** — · FR: FR-066
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [I7](#page-i7), [G1](#page-g1)

<a id="page-i7"></a>
#### I7 · Expense categories and budgets

1. **Purpose:** Categories with their ledger, monthly budget, spent, used % and status.
2. **Components and layout:** Table with progress; totals; inline drawer New category. _Source: Exp-Cats.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Name | text | req |  |
   | Ledger account | select | req | new or existing expense ledger |
   | Monthly budget | money | opt | >= 0 |
   | Warn me at 90% | switch | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-i7)): New category → POST /ie-categories<br>Edit category → PATCH /ie-categories/{id}<br>This month / FY to date → GET /ie-categories (period)
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /ie-categories`](05-API-Specification.md#getiecategories), [`POST /ie-categories`](05-API-Specification.md#postiecategories), [`PATCH /ie-categories/{id}`](05-API-Specification.md#patchiecategoriesid), [`GET /ledgers`](05-API-Specification.md#getledgers)
10. **Entities:** `account_groups`, `ie_categories`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-067
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [I6](#page-i6)

<a id="page-i10"></a>
#### I10 · Recurring entries (NEW)

1. **Purpose:** Recurring income, expense and journal templates with frequency, next run and remaining occurrences. Each run creates a draft for confirmation (BR-048).
2. **Components and layout:** List template (NEW, GAP-25): toolbar (kind, active); DataTable with row menu.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Kind | segmented | opt | all, income, expense, journal |
   | Active | switch | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-i10)): Pause or resume (row) → PATCH /recurring-templates/{id} (is_active)<br>Edit schedule (row) → PATCH /recurring-templates/{id}<br>End (row) → dlg:L6 → PATCH /recurring-templates/{id} (ends_type=on_date)
6. **Table:** columns Kind, Description, Frequency, Next run, Done / ends, Status; filters kind, active; server-side pagination and sorting.
7. **Validation and rules:** BR-048: Recurring templates create drafts only; nothing posts automatically.
8. **Roles allowed:** `banking.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /recurring-templates`](05-API-Specification.md#getrecurringtemplates), [`PATCH /recurring-templates/{id}`](05-API-Specification.md#patchrecurringtemplatesid)
10. **Entities:** `recurring_templates`
11. **Workflows:** — · FR: FR-066
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [I3](#page-i3), [I6](#page-i6) · Note: GAP-25

<a id="page-i8"></a>
#### I8 · Income report

1. **Purpose:** Income by category and month (or quarter). Profit-and-loss figures: reports.financial (DEC-13).
2. **Components and layout:** Date range, branch, by month/quarter; tiles; statement table. _Source: Inc-Report.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-i8)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/income
6. **Table:** columns Category, Ledger, Jul..Oct (৳), Total (৳), Share; filters date, branch; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/income`](05-API-Specification.md#getreportsincome)
10. **Entities:** `ie_categories`, `journal_lines`
11. **Workflows:** — · FR: FR-068
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [J1](#page-j1)

<a id="page-i9"></a>
#### I9 · Expense report

1. **Purpose:** Expense by category and month (or quarter). Profit-and-loss figures: reports.financial (DEC-13).
2. **Components and layout:** Date range, branch, by month/quarter; tiles; statement table. _Source: Exp-Report.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-i9)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/expenses
6. **Table:** columns Category, Ledger, Jul..Oct (৳), Total (৳), Share; filters date, branch; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/expenses`](05-API-Specification.md#getreportsexpenses)
10. **Entities:** `ie_categories`, `journal_lines`
11. **Workflows:** — · FR: FR-068
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [J1](#page-j1)

### Accounting


<a id="page-h1"></a>
#### H1 · Accounting overview

1. **Purpose:** State of the books: totals, books-health checks, where the money sits, recent vouchers, voucher shortcuts.
2. **Components and layout:** 4 tiles; health checklist with actions (TB agrees, unreconciled lines, drafts, suspense empty, last locked period); group bars; recent vouchers; Enter-a-voucher buttons. _Source: Accounting.dc.html (H1)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-h1)): Trial balance → nav:H15<br>Chart of accounts → nav:H2<br>Journal voucher (Alt J) → nav:H9<br>Payment (Alt Y) → nav:H10<br>Receipt (Alt R) → nav:H11<br>Contra (Alt C) → nav:H12
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `accounting.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /approvals`](05-API-Specification.md#getapprovals), [`GET /accounting/overview`](05-API-Specification.md#getaccountingoverview), [`GET /vouchers`](05-API-Specification.md#getvouchers)
10. **Entities:** `accounting_periods`, `approval_requests`, `bank_statement_lines`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-070
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [H2](#page-h2), [H8](#page-h8), [H9](#page-h9), [H15](#page-h15)

<a id="page-h2"></a>
#### H2 · Chart of accounts

1. **Purpose:** Groups and ledgers with live balances; expand/collapse; trial-balance check.
2. **Components and layout:** Search, expand all, groups only, show zero balances; tree rows with kind badge, nature, balance, open action; footer balance check. _Source: Acc-COA.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Find account | text | opt |  |
   | Show zero balances | checkbox | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h2)): New group → POST /account-groups<br>New ledger → nav:H4<br>Export → export:GET /account-groups<br>Open group → nav:H3<br>Open ledger → nav:H7
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `accounting.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /account-groups`](05-API-Specification.md#getaccountgroups), [`POST /account-groups`](05-API-Specification.md#postaccountgroups)
10. **Entities:** `account_groups`, `export_jobs`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-071
12. **States:** Loading skeleton rows; empty search 'No accounts match'.
13. **Depends on:** [H3](#page-h3), [H4](#page-h4), [H7](#page-h7)

<a id="page-h3"></a>
#### H3 · Account group details

1. **Purpose:** Group balance, sub-accounts and settings, with its path in the chart.
2. **Components and layout:** 4 tiles; accounts table; aside group settings and path. _Source: Acc-Group.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-h3)): Edit group (name, parent within nature, sort) → PATCH /account-groups/{id}<br>Ledger in this group → nav:H6 (GET /ledgers?account_group_id=)
6. **Table:** columns Code, Ledger, Kind, Share, Balance (৳); server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `accounting.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /account-groups`](05-API-Specification.md#getaccountgroups), [`GET /account-groups/{id}`](05-API-Specification.md#getaccountgroupsid), [`PATCH /account-groups/{id}`](05-API-Specification.md#patchaccountgroupsid), [`GET /ledgers`](05-API-Specification.md#getledgers)
10. **Entities:** `account_groups`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-071
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [H4](#page-h4)

<a id="page-h4"></a>
#### H4 · Add ledger account

1. **Purpose:** Create a ledger under a group; nature and normal balance follow the group.
2. **Components and layout:** Ledger card; Opening balance card; Behaviour card; aside 'Where it will appear'. _Source: Acc-AddLedger.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Ledger name | text | req | unique in company |
   | Under group | select | req |  |
   | Ledger code | text | opt | next free code suggested |
   | Nature | readonly | — | from group |
   | Normal balance | readonly | — |  |
   | Opening amount | money | opt |  |
   | Side | segmented | opt | Dr/Cr |
   | Track bill by bill | switch | opt |  |
   | Show in payment and receipt vouchers | switch | opt |  |
   | Tax rule | select | opt |  |
   | Description | text | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h4)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save and add another → POST /ledgers → ui:reset the form<br>Create ledger → POST /ledgers → nav:H7
6. **Tables:** —
7. **Validation and rules:** 'Enter a ledger name'<br>Code unique<br>BR-027: A ledger's code and opening balance are locked once it has entries; the group may change only within the same nature.<br>BR-028: Opening balances (parties, ledgers, banks, stock) are posted as opening_balance entries dated the FY start against the opening balance adjustment ledger, and are locked after the first document.
8. **Roles allowed:** `accounting.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /account-groups`](05-API-Specification.md#getaccountgroups), [`POST /ledgers`](05-API-Specification.md#postledgers)
10. **Entities:** `account_groups`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-071
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [H2](#page-h2) · Note: GAP-21 cost centre switch hidden

<a id="page-h5"></a>
#### H5 · Edit ledger account

1. **Purpose:** Rename or regroup a ledger; code and opening locked once entries exist.
2. **Components and layout:** As H4 with entries banner, FY summary aside, Make inactive. _Source: Acc-EditLedger.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Same as H4 | — | — | code/opening read-only when entries exist |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h5)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes → PATCH /ledgers/{id}<br>Make inactive → dlg:L6 → POST /ledgers/{id}/deactivate
6. **Tables:** —
7. **Validation and rules:** BR-027: A ledger's code and opening balance are locked once it has entries; the group may change only within the same nature.<br>BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.
8. **Roles allowed:** `accounting.edit` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /ledgers/{id}`](05-API-Specification.md#getledgersid), [`PATCH /ledgers/{id}`](05-API-Specification.md#patchledgersid), [`POST /ledgers/{id}/deactivate`](05-API-Specification.md#postledgersiddeactivate)
10. **Entities:** `account_mappings`, `audit_logs`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-071
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [H7](#page-h7)

<a id="page-h6"></a>
#### H6 · Ledger list

1. **Purpose:** All ledgers with nature and balance; nature segmented; density.
2. **Components and layout:** Search with '/' shortcut; nature segmented; density; table with totals. _Source: Acc-Ledgers.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-h6)): Open → nav:H7<br>Edit → nav:H5
6. **Table:** columns Code, Ledger, Nature, Balance (৳); filters nature; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `accounting.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /ledgers`](05-API-Specification.md#getledgers)
10. **Entities:** `account_groups`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-071
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [H7](#page-h7)

<a id="page-h7"></a>
#### H7 · Ledger details

1. **Purpose:** Entries with running balance; opening/debits/credits/closing; monthly closing balances.
2. **Components and layout:** Date range, period segmented, show narration; summary; ledger table; aside facts and monthly bars. _Source: Acc-Ledger.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | From / to | date | req |  |
   | Period | segmented | opt | month/FY/custom |
   | Show narration | checkbox | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h7)): Open voucher → nav:H13<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /ledgers/{id}/entries
6. **Table:** columns Date, Voucher, Particulars, Debit (৳), Credit (৳), Balance (৳); filters date; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `accounting.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /ledgers/{id}`](05-API-Specification.md#getledgersid), [`GET /ledgers/{id}/entries`](05-API-Specification.md#getledgersidentries)
10. **Entities:** `export_jobs`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-072
12. **States:** As Report; opening and closing always shown, even with no entries.
13. **Depends on:** [H13](#page-h13)

<a id="page-h8"></a>
#### H8 · Voucher list

1. **Purpose:** All vouchers with type tabs, search, date range and status filter. Also serves the Day book (J1).
2. **Components and layout:** Tabs All/Journal/Payment/Receipt/Contra; search; dates; status; selectable table; pagination. _Source: Acc-Vouchers.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Type tab | tabs | opt |  |
   | Search | text | opt | number, ledger or narration |
   | From / to | date | opt |  |
   | Status | select | opt | posted/draft/submitted/reversed |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h8)): Open → nav:H13<br>Print (row) → GET /vouchers/{id}/pdf → ui:print<br>Duplicate (row) → nav:H9 (prefilled from GET /vouchers/{id})
6. **Table:** columns Date, Voucher, Type, Ledgers, Narration, Status, Amount (৳); filters type, date, status; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `accounting.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /vouchers`](05-API-Specification.md#getvouchers), [`GET /vouchers/{id}`](05-API-Specification.md#getvouchersid), [`GET /vouchers/{id}/pdf`](05-API-Specification.md#getvouchersidpdf)
10. **Entities:** `attachments`, `audit_logs`, `bill_allocations`, `companies`, `journal_entries`, `journal_lines`, `ledgers`, `payment_details`
11. **Workflows:** — · FR: FR-074
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [H13](#page-h13)

<a id="page-h9"></a>
#### H9 · Journal voucher

1. **Purpose:** Adjustments with no cash: debit and credit lines with a live balance check.
2. **Components and layout:** Header (number, date, reference, template); entries grid (ledger, line narration, debit, credit) with washes; 'Put difference on last line'; totals; narration; BalanceStatus; out-of-balance banner (H9 unbalanced); footer. _Source: Acc-Journal.dc.html, Acc-JournalUnbal.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Voucher number | readonly | — | JV series |
   | Date | date | req | open period |
   | Reference | text | opt |  |
   | Template | select | opt | depreciation, accrue salaries, accrue interest, reclassify, blank |
   | Lines: ledger | select | req | active ledger |
   | Lines: debit or credit | money | req | exactly one > 0 (BR-002) |
   | Line narration | text | opt |  |
   | Narration | textarea | req |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h9)): Add line → ui:add a line → POST /vouchers/calculate<br>Remove line → ui:remove the line → POST /vouchers/calculate<br>Put difference on last line → ui:put the difference on the last line → POST /vouchers/calculate<br>Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save draft → POST /vouchers (action=draft) \| PUT /vouchers/{id}<br>Submit for approval (above limit) → POST /vouchers (action=submit) \| POST /vouchers/{id}/submit<br>Post voucher → POST /vouchers (action=post) \| POST /vouchers/{id}/post
6. **Tables:** —
7. **Validation and rules:** Post disabled while out of balance: banner shows the difference<br>At least two lines<br>Server re-validates BR-001<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.<br>BR-002: Each journal line carries a debit or a credit greater than zero, never both and never neither.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-008: Nobody approves a voucher they submitted (segregation of duties).<br>BR-053: A voucher dated more than the configured number of days in the past requires explicit confirmation.<br>BR-050: Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.
8. **Roles allowed:** `accounting.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /ledgers`](05-API-Specification.md#getledgers), [`POST /vouchers`](05-API-Specification.md#postvouchers), [`PUT /vouchers/{id}`](05-API-Specification.md#putvouchersid), [`POST /vouchers/{id}/submit`](05-API-Specification.md#postvouchersidsubmit), [`POST /vouchers/{id}/post`](05-API-Specification.md#postvouchersidpost), [`POST /vouchers/calculate`](05-API-Specification.md#postvoucherscalculate), [`GET /voucher-templates`](05-API-Specification.md#getvouchertemplates)
10. **Entities:** `account_groups`, `accounting_periods`, `approval_requests`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `notifications`, `number_sequences`, `payment_details`, `roles`
11. **Workflows:** Close the month, Accounting posting · FR: FR-073
12. **States:** As Form, plus the live BalanceStatus (balanced / out of balance by ৳ X); Post disabled while unbalanced.
13. **Depends on:** [H13](#page-h13)

<a id="page-h10"></a>
#### H10 · Payment voucher

1. **Purpose:** Money out of cash or bank to one or more ledgers.
2. **Components and layout:** Header (number, date, account with current/after balance, method); lines (ledger, against reference, narration, amount); total and words; narration; generated double-entry table; footer Preview / Post. _Source: Acc-Payment.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Account | select | req | money account |
   | Lines: ledger | select | req |  |
   | Against | text | opt | bill/invoice reference (allocates when it matches) |
   | Amount | money | req | > 0 |
   | Narration | textarea | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h10)): Add line → ui:add a line → POST /vouchers/calculate<br>Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Preview (H14) → POST /vouchers (action=draft) \| PUT /vouchers/{id} → nav:H14<br>Post / Submit → POST /vouchers (action=post; 202 when approval is needed) \| POST /vouchers/{id}/post
6. **Tables:** —
7. **Validation and rules:** Badge 'More than the account holds' blocks (BR-031)<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-008: Nobody approves a voucher they submitted (segregation of duties).<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.
8. **Roles allowed:** `accounting.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`GET /ledgers`](05-API-Specification.md#getledgers), [`POST /vouchers`](05-API-Specification.md#postvouchers), [`PUT /vouchers/{id}`](05-API-Specification.md#putvouchersid), [`POST /vouchers/{id}/submit`](05-API-Specification.md#postvouchersidsubmit), [`POST /vouchers/{id}/post`](05-API-Specification.md#postvouchersidpost), [`POST /vouchers/calculate`](05-API-Specification.md#postvoucherscalculate)
10. **Entities:** `account_groups`, `accounting_periods`, `approval_requests`, `bank_statement_lines`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `notifications`, `number_sequences`, `payment_details`, `roles`
11. **Workflows:** — · FR: FR-073
12. **States:** As Form, plus the live BalanceStatus (balanced / out of balance by ৳ X); Post disabled while unbalanced.
13. **Depends on:** [H14](#page-h14)

<a id="page-h11"></a>
#### H11 · Receipt voucher

1. **Purpose:** Money into cash or bank from one or more ledgers.
2. **Components and layout:** Header (number, date, account with current/after balance, method); lines (ledger, against reference, narration, amount); total and words; narration; generated double-entry table; footer Preview / Post. _Source: Acc-Receipt.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Account | select | req | money account |
   | Lines: ledger | select | req |  |
   | Against | text | opt | bill/invoice reference (allocates when it matches) |
   | Amount | money | req | > 0 |
   | Narration | textarea | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h11)): Add line → ui:add a line → POST /vouchers/calculate<br>Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Preview (H14) → POST /vouchers (action=draft) \| PUT /vouchers/{id} → nav:H14<br>Post / Submit → POST /vouchers (action=post) \| POST /vouchers/{id}/post
6. **Tables:** —
7. **Validation and rules:** Badge 'More than the account holds' blocks (BR-031)<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-008: Nobody approves a voucher they submitted (segregation of duties).<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.
8. **Roles allowed:** `accounting.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`GET /ledgers`](05-API-Specification.md#getledgers), [`POST /vouchers`](05-API-Specification.md#postvouchers), [`PUT /vouchers/{id}`](05-API-Specification.md#putvouchersid), [`POST /vouchers/{id}/post`](05-API-Specification.md#postvouchersidpost), [`POST /vouchers/calculate`](05-API-Specification.md#postvoucherscalculate)
10. **Entities:** `account_groups`, `accounting_periods`, `approval_requests`, `bank_statement_lines`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `payment_details`, `roles`
11. **Workflows:** — · FR: FR-073
12. **States:** As Form, plus the live BalanceStatus (balanced / out of balance by ৳ X); Post disabled while unbalanced.
13. **Depends on:** [H14](#page-h14)

<a id="page-h12"></a>
#### H12 · Contra voucher

1. **Purpose:** Between your own cash and bank accounts.
2. **Components and layout:** Header (number, date, account with current/after balance, method); lines (ledger, against reference, narration, amount); total and words; narration; generated double-entry table; footer Preview / Post. _Source: Acc-Contra.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Account | select | req | money account |
   | Lines: ledger | select | req |  |
   | Against | text | opt | bill/invoice reference (allocates when it matches) |
   | Amount | money | req | > 0 |
   | Narration | textarea | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h12)): Add line → ui:add a line → POST /vouchers/calculate<br>Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Preview (H14) → POST /vouchers (action=draft) \| PUT /vouchers/{id} → nav:H14<br>Post / Submit → POST /vouchers (action=post; 202 when approval is needed) \| POST /vouchers/{id}/post
6. **Tables:** —
7. **Validation and rules:** Badge 'More than the account holds' blocks (BR-031)<br>BR-065: A receipt debits the money account and credits the party ledger; a payment debits the party (or expense) ledger and credits the money account; bank charges debit the bank-charges ledger.<br>BR-031: A payment, transfer or contra cannot take a cash or wallet account below zero, or a bank or cash-credit account below minus its overdraft_limit (zero when not set).<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-008: Nobody approves a voucher they submitted (segregation of duties).<br>BR-020: Allocations never exceed a document's open balance, their sum never exceeds the payment; default allocation is oldest due first; any excess stays as an advance on the party ledger.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.
8. **Roles allowed:** `accounting.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`GET /ledgers`](05-API-Specification.md#getledgers), [`POST /vouchers`](05-API-Specification.md#postvouchers), [`PUT /vouchers/{id}`](05-API-Specification.md#putvouchersid), [`POST /vouchers/{id}/post`](05-API-Specification.md#postvouchersidpost), [`POST /vouchers/calculate`](05-API-Specification.md#postvoucherscalculate)
10. **Entities:** `account_groups`, `accounting_periods`, `approval_requests`, `bank_statement_lines`, `bill_allocations`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `money_accounts`, `number_sequences`, `payment_details`, `roles`
11. **Workflows:** — · FR: FR-073
12. **States:** As Form, plus the live BalanceStatus (balanced / out of balance by ৳ X); Post disabled while unbalanced.
13. **Depends on:** [H14](#page-h14)

<a id="page-h13"></a>
#### H13 · Voucher details

1. **Purpose:** Summary strip, entries, narration, audit trail, linked documents.
2. **Components and layout:** Header Reverse / More (duplicate, print, delete draft); strip; entries table; narration; aside audit trail and linked. _Source: Acc-Voucher.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-h13)): Reverse (dialog) → dlg:Acc-DlgReverse<br>Duplicate → nav:H9 (prefilled from GET /vouchers/{id})<br>Print → GET /vouchers/{id}/pdf → ui:print<br>Approve / Reject (when pending) → POST /approvals/{id}/approve \| POST /approvals/{id}/reject<br>Delete (drafts only, GAP-07) → dlg:L7 → DELETE /vouchers/{id}
6. **Tables:** —
7. **Validation and rules:** BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-025: Only drafts can be deleted; a posted document can only be cancelled or reversed.
8. **Roles allowed:** `accounting.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /approvals/{id}`](05-API-Specification.md#getapprovalsid), [`POST /approvals/{id}/approve`](05-API-Specification.md#postapprovalsidapprove), [`POST /approvals/{id}/reject`](05-API-Specification.md#postapprovalsidreject), [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /attachments`](05-API-Specification.md#getattachments), [`GET /audit-logs`](05-API-Specification.md#getauditlogs), [`GET /vouchers/{id}`](05-API-Specification.md#getvouchersid), [`DELETE /vouchers/{id}`](05-API-Specification.md#deletevouchersid), [`POST /vouchers/{id}/reverse`](05-API-Specification.md#postvouchersidreverse), [`GET /vouchers/{id}/pdf`](05-API-Specification.md#getvouchersidpdf)
10. **Entities:** `accounting_periods`, `approval_requests`, `attachments`, `audit_logs`, `bill_allocations`, `companies`, `journal_entries`, `journal_lines`, `notifications`, `number_sequences`, `payment_details`, `users`
11. **Workflows:** — · FR: FR-074, FR-014
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [Acc-DlgReverse](#page-acc-dlgreverse), [H14](#page-h14)

<a id="page-acc-dlgreverse"></a>
#### Acc-DlgReverse · Dialog · Reverse voucher

1. **Purpose:** Creates a reversing entry dated today (or chosen open date) with every line swapped; both linked.
2. **Components and layout:** Info dialog with date and reason. _Source: Acc-DlgReverse.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Reversal date | date | req | open period |
   | Reason | text | req |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-acc-dlgreverse)): Cancel → ui:close the dialog<br>Create reversing entry → POST /vouchers/{id}/reverse
6. **Tables:** —
7. **Validation and rules:** BR-003: Posted journal entries, documents and stock movements are immutable; corrections are made only by reversal, cancellation or a credit/debit note. Non-financial fields (delivery challan, vehicle, delivered time, internal note, attachments) may still be updated after posting, and every change is audited.<br>BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-009: A document posts at most once: one journal entry per document (unique journal_entry_id); a repeated post request returns the existing result.
8. **Roles allowed:** `accounting.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /vouchers/{id}/reverse`](05-API-Specification.md#postvouchersidreverse)
10. **Entities:** `accounting_periods`, `audit_logs`, `bill_allocations`, `journal_entries`, `journal_lines`, `number_sequences`
11. **Workflows:** — · FR: FR-074
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** [H13](#page-h13)

<a id="page-h14"></a>
#### H14 · Printable voucher

1. **Purpose:** A4 voucher with entries, amount in words, narration and signature boxes.
2. **Components and layout:** Paper layout with company header, voucher meta, table, signatures. _Source: Acc-Print.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-h14)): Back → nav:*<br>PDF → GET /vouchers/{id}/pdf<br>Print → GET /vouchers/{id}/pdf → ui:print
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `accounting.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /vouchers/{id}`](05-API-Specification.md#getvouchersid), [`GET /vouchers/{id}/pdf`](05-API-Specification.md#getvouchersidpdf)
10. **Entities:** `attachments`, `audit_logs`, `bill_allocations`, `companies`, `journal_entries`, `journal_lines`, `payment_details`
11. **Workflows:** — · FR: FR-074
12. **States:** Loading preview skeleton; PDF generation toast; print uses the browser dialog.
13. **Depends on:** [H13](#page-h13)

<a id="page-k16"></a>
#### K16 · Accounting defaults (NEW: GAP-01)

1. **Purpose:** Configure account mappings used by automatic postings.
2. **Components and layout:** Settings layout: one row per mapping key with ledger picker and explanation.
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Ledger per mapping key | select | req | ledger of the right nature |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k16)): Save changes → PUT /account-mappings
6. **Tables:** —
7. **Validation and rules:** Nature check per key (e.g. cogs must be an expense ledger)<br>BR-064: Posting a sales invoice creates: Dr customer (total), Cr each line's income ledger (net), Cr tax ledgers (tax), Cr/Dr rounding; and for stocked lines Dr COGS, Cr warehouse inventory (cost).
8. **Roles allowed:** `settings.edit` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /account-mappings`](05-API-Specification.md#getaccountmappings), [`PUT /account-mappings`](05-API-Specification.md#putaccountmappings), [`GET /account-groups`](05-API-Specification.md#getaccountgroups), [`GET /ledgers`](05-API-Specification.md#getledgers)
10. **Entities:** `account_groups`, `account_mappings`, `audit_logs`, `journal_lines`, `ledgers`
11. **Workflows:** — · FR: FR-075
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** [H2](#page-h2) · Note: GAP-01

### Reports


<a id="page-h15"></a>
#### H15 · Trial balance

1. **Purpose:** Closing debit and credit of every ledger (or group) as of a date, with checks.
2. **Components and layout:** Toolbar (date or period, compare, detail/level, basis badge); paper statement; agreement check; aside checks or ratios; rows drill into the general ledger. _Source: Rpt-TB.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | As of / period | date | req |  |
   | Compare | segmented | opt | prior/none |
   | Detail | segmented | opt | ledgers/totals |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h15)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export (xlsx, pdf, csv) → export:GET /reports/trial-balance<br>Drill into ledger → nav:H7
6. **Tables:** —
7. **Validation and rules:** BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/trial-balance`](05-API-Specification.md#getreportstrialbalance)
10. **Entities:** `account_groups`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** Close the month · FR: FR-076
12. **States:** As Report, plus agreement check (green) or difference (warning).
13. **Depends on:** [H7](#page-h7)

<a id="page-h16"></a>
#### H16 · Profit and loss

1. **Purpose:** Revenue, COGS, gross profit, operating expenses, other income, finance costs; compared with last year.
2. **Components and layout:** Toolbar (date or period, compare, detail/level, basis badge); paper statement; agreement check; aside checks or ratios; rows drill into the general ledger. _Source: Rpt-PL.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | As of / period | date | req |  |
   | Compare | segmented | opt | prior/none |
   | Detail | segmented | opt | ledgers/totals |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h16)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export (xlsx, pdf, csv) → export:GET /reports/profit-and-loss<br>Drill into ledger → nav:H7
6. **Tables:** —
7. **Validation and rules:** BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/profit-and-loss`](05-API-Specification.md#getreportsprofitandloss)
10. **Entities:** `account_groups`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** Close the month · FR: FR-076
12. **States:** As Report, plus agreement check (green) or difference (warning).
13. **Depends on:** [H7](#page-h7)

<a id="page-h17"></a>
#### H17 · Balance sheet

1. **Purpose:** Assets, equity and liabilities as of a date, compared with last year end.
2. **Components and layout:** Toolbar (date or period, compare, detail/level, basis badge); paper statement; agreement check; aside checks or ratios; rows drill into the general ledger. _Source: Rpt-BS.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | As of / period | date | req |  |
   | Compare | segmented | opt | prior/none |
   | Detail | segmented | opt | ledgers/totals |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h17)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export (xlsx, pdf, csv) → export:GET /reports/balance-sheet<br>Drill into ledger → nav:H7
6. **Tables:** —
7. **Validation and rules:** BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/balance-sheet`](05-API-Specification.md#getreportsbalancesheet)
10. **Entities:** `account_groups`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** Close the month · FR: FR-076
12. **States:** As Report, plus agreement check (green) or difference (warning).
13. **Depends on:** [H7](#page-h7)

<a id="page-h18"></a>
#### H18 · Cash flow statement

1. **Purpose:** Indirect method reconciled to the cash and bank group.
2. **Components and layout:** Toolbar (date or period, compare, detail/level, basis badge); paper statement; agreement check; aside checks or ratios; rows drill into the general ledger. _Source: Rpt-CF.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | As of / period | date | req |  |
   | Compare | segmented | opt | prior/none |
   | Detail | segmented | opt | ledgers/totals |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h18)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export (xlsx, pdf, csv) → export:GET /reports/cash-flow<br>Drill into ledger → nav:H7
6. **Tables:** —
7. **Validation and rules:** BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/cash-flow`](05-API-Specification.md#getreportscashflow)
10. **Entities:** `account_groups`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** Close the month · FR: FR-076
12. **States:** As Report, plus agreement check (green) or difference (warning).
13. **Depends on:** [H7](#page-h7)

<a id="page-h19"></a>
#### H19 · General ledger

1. **Purpose:** One block per chosen ledger: opening, entries, closing.
2. **Components and layout:** Toolbar (date or period, compare, detail/level, basis badge); paper statement; agreement check; aside checks or ratios; rows drill into the general ledger. _Source: Rpt-GL.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | As of / period | date | req |  |
   | Compare | segmented | opt | prior/none |
   | Detail | segmented | opt | ledgers/totals |
5. **Actions → API** ([full map](05-API-Specification.md#actions-h19)): Print → ui:browser print of the loaded view (print stylesheet)<br>Export (xlsx, pdf, csv) → export:GET /reports/general-ledger<br>Drill into ledger → nav:H7
6. **Tables:** —
7. **Validation and rules:** BR-005: Drafts, submitted and rejected vouchers never affect ledger balances, stock or reports.<br>BR-001: Every posted journal entry balances: total debit = total credit > 0.
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /reports/general-ledger`](05-API-Specification.md#getreportsgeneralledger)
10. **Entities:** `account_groups`, `journal_entries`, `journal_lines`, `ledgers`
11. **Workflows:** Close the month · FR: FR-076
12. **States:** As Report, plus agreement check (green) or difference (warning).
13. **Depends on:** [H7](#page-h7)

<a id="page-j1"></a>
#### J1 · Reports centre

1. **Purpose:** Find any report; statements, books, receivables/payables, operations. Reports read posted vouchers only.
2. **Components and layout:** Search; sections of report buttons with module tags; reused screens open with the Reports breadcrumb. _Source: Statements.dc.html (J1)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Find a report | text | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-j1)): Open report → nav:*
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.view` → Owner, Finance manager, Accountant, Sales staff, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid), [`GET /reports/inventory/valuation`](05-API-Specification.md#getreportsinventoryvaluation), [`GET /reports/sales`](05-API-Specification.md#getreportssales), [`GET /reports/purchases`](05-API-Specification.md#getreportspurchases)
10. **Entities:** `export_jobs`, `ie_categories`, `journal_lines`, `parties`, `product_categories`, `products`, `purchase_bill_lines`, `purchase_bills`, `purchase_returns`, `sales_invoice_lines`, `sales_invoices`, `sales_returns`, `stock_groups`, `stock_movements`
11. **Workflows:** — · FR: FR-076
12. **States:** Search with no-match state.
13. **Depends on:** [H15](#page-h15), [H16](#page-h16), [H17](#page-h17), [H18](#page-h18), [H19](#page-h19), [J6](#page-j6), [J7](#page-j7), [E11](#page-e11), [F9](#page-f9), [I8](#page-i8), [I9](#page-i9), [G11](#page-g11), [G12](#page-g12), [D13](#page-d13), [D14](#page-d14), [B8](#page-b8), [C8](#page-c8), [H8](#page-h8)

<a id="page-j6"></a>
#### J6 · Receivables aging

1. **Purpose:** What customers owe by days past due.
2. **Components and layout:** Total and overdue; stacked bucket bar; search; overdue-only; table with buckets and oldest-age badge; statement action. _Source: Rpt-AR.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | As of | date | req |  |
   | Search | text | opt |  |
   | Overdue only | checkbox | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-j6)): Statement (row) → nav:B8<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/receivables-aging
6. **Table:** columns Customer, Not due, 1–30, 31–60, 61–90, 90+, Total (৳), Oldest; filters overdue; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties/{id}/statement`](05-API-Specification.md#getpartiesidstatement), [`GET /reports/receivables-aging`](05-API-Specification.md#getreportsreceivablesaging)
10. **Entities:** `account_groups`, `companies`, `journal_entries`, `journal_lines`, `ledgers`, `parties`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-076
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [B8](#page-b8)

<a id="page-j7"></a>
#### J7 · Payables aging

1. **Purpose:** What suppliers are owed by days past due.
2. **Components and layout:** Total and overdue; stacked bucket bar; search; overdue-only; table with buckets and oldest-age badge; statement action. _Source: Rpt-AP.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | As of | date | req |  |
   | Search | text | opt |  |
   | Overdue only | checkbox | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-j7)): Statement (row) → nav:C8<br>Print → ui:browser print of the loaded view (print stylesheet)<br>Export → export:GET /reports/payables-aging
6. **Table:** columns Supplier, Not due, 1–30, 31–60, 61–90, 90+, Total (৳), Oldest; filters overdue; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `reports.financial` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties/{id}/statement`](05-API-Specification.md#getpartiesidstatement), [`GET /reports/payables-aging`](05-API-Specification.md#getreportspayablesaging)
10. **Entities:** `account_groups`, `companies`, `journal_entries`, `journal_lines`, `ledgers`, `parties`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-076
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [C8](#page-c8)

### Settings


<a id="page-k1"></a>
#### K1 · Company profile

1. **Purpose:** Identity, registration, contact and locations.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K1)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-k1)): Edit company → nav:K2<br>Manage locations (NEW, GAP-14) → POST /branches \| PATCH /branches/{id}
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `settings.view` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /company`](05-API-Specification.md#getcompany), [`GET /branches`](05-API-Specification.md#getbranches), [`POST /branches`](05-API-Specification.md#postbranches), [`PATCH /branches/{id}`](05-API-Specification.md#patchbranchesid)
10. **Entities:** `branches`, `companies`
11. **Workflows:** — · FR: FR-080
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** —

<a id="page-k2"></a>
#### K2 · Edit company

1. **Purpose:** Legal name, trading name, business type, trade licence, tax registration, address, phone, email, website, logo.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K2)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Legal name | text | req |  |
   | Trading name | text | opt |  |
   | Business type | select | req |  |
   | Trade licence number | text | opt |  |
   | Tax registration number | text | opt |  |
   | Registered address | text | opt |  |
   | Phone | text | opt |  |
   | Email | text | opt | 'Enter an email like name@company.com' |
   | Website | text | opt |  |
   | Logo | file | opt | PNG/SVG >= 400 px wide |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k2)): Replace logo → PUT /company/logo<br>Remove logo → dlg:L7 → DELETE /company/logo<br>Discard → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes → PATCH /company
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `settings.edit` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`PATCH /company`](05-API-Specification.md#patchcompany), [`PUT /company/logo`](05-API-Specification.md#putcompanylogo), [`DELETE /company/logo`](05-API-Specification.md#deletecompanylogo)
10. **Entities:** `audit_logs`, `companies`
11. **Workflows:** Set up a business · FR: FR-080
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** —

<a id="page-k3"></a>
#### K3 · Business preferences

1. **Purpose:** Numbering series, defaults (warehouse, terms, valuation, rounding) and controls.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K3)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Prefix / next number per series | table | req | next >= last used + 1 |
   | Default warehouse | select | opt |  |
   | Default payment terms | select | opt |  |
   | Stock valuation | select | req | weighted average (FIFO later) |
   | Rounding on invoices | select | req |  |
   | Allow selling below zero stock | switch | opt |  |
   | Require approval above limits | switch | opt |  |
   | Warn when voucher date > 7 days back | switch | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k3)): Discard → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes → PATCH /company/settings → PATCH /number-sequences/{id} (each changed series)
6. **Tables:** —
7. **Validation and rules:** BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.
8. **Roles allowed:** `settings.edit` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /company/settings`](05-API-Specification.md#getcompanysettings), [`PATCH /company/settings`](05-API-Specification.md#patchcompanysettings), [`GET /payment-terms`](05-API-Specification.md#getpaymentterms), [`GET /number-sequences`](05-API-Specification.md#getnumbersequences), [`PATCH /number-sequences/{id}`](05-API-Specification.md#patchnumbersequencesid)
10. **Entities:** `audit_logs`, `company_settings`, `journal_entries`, `number_sequences`, `payment_terms`
11. **Workflows:** — · FR: FR-081
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

<a id="page-k4"></a>
#### K4 · Financial-year settings

1. **Purpose:** Current FY, open and locked periods, close year.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K4)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-k4)): Lock period → dlg:L6 → POST /accounting-periods/{id}/lock<br>Unlock period (reason) → dlg:L6 → POST /accounting-periods/{id}/unlock<br>Close FY (after year end) → dlg:L6 → POST /fiscal-years/{id}/close
6. **Tables:** —
7. **Validation and rules:** BR-033: Periods lock in date order by users with accounting.lock_period; unlocking needs the same permission, a reason, and is audited.<br>BR-034: A financial year closes only after its end date with every period locked; closing posts a year-end entry moving net profit to retained earnings.
8. **Roles allowed:** `settings.view` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /fiscal-years`](05-API-Specification.md#getfiscalyears), [`POST /accounting-periods/{id}/lock`](05-API-Specification.md#postaccountingperiodsidlock), [`POST /accounting-periods/{id}/unlock`](05-API-Specification.md#postaccountingperiodsidunlock), [`POST /fiscal-years/{id}/close`](05-API-Specification.md#postfiscalyearsidclose)
10. **Entities:** `accounting_periods`, `audit_logs`, `company_backups`, `fiscal_years`, `journal_entries`, `journal_lines`, `number_sequences`
11. **Workflows:** Set up a business · FR: FR-082
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

<a id="page-k5"></a>
#### K5 · Currency and region

1. **Purpose:** Base currency (locked), symbol position, decimals, negatives, grouping, date format, time zone, week start, print language.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K5)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Symbol position | segmented | req |  |
   | Decimal places | segmented | req | 2 or 0 |
   | Negative amounts | segmented | req |  |
   | Number grouping | buttons | req | lakh/million |
   | Date format | select | req |  |
   | Week starts on | select | req |  |
   | Language on printed documents | segmented | req | en/bn/both |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k5)): Discard → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes → PATCH /company/settings
6. **Tables:** —
7. **Validation and rules:** BR-035: The base currency cannot change once any journal entry exists.
8. **Roles allowed:** `settings.edit` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /company/settings`](05-API-Specification.md#getcompanysettings), [`PATCH /company/settings`](05-API-Specification.md#patchcompanysettings)
10. **Entities:** `audit_logs`, `company_settings`, `journal_entries`
11. **Workflows:** — · FR: FR-083
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

<a id="page-k6"></a>
#### K6 · Tax configuration

1. **Purpose:** Tax rules you define (none by default) and how tax shows on documents; 'Add tax rule' drawer.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K6)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Rule name | text | req | unique |
   | Rate | pct | req | 0–100; entered by user |
   | Applies to | select | req | sales/purchases/both |
   | Posts to ledger | select | req | tax payable/receivable or new |
   | Recoverable as input credit | switch | opt | NEW (R-14); purchases only; off = tax added to cost |
   | Prices entered | segmented | req | exclude/include tax |
   | Show tax registration number on invoices | switch | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k6)): Add tax rule → POST /tax-rules<br>Edit / deactivate rule → PATCH /tax-rules/{id}<br>Save → PATCH /company/settings
6. **Tables:** —
7. **Validation and rules:** BR-036: No tax rate is assumed: tax is calculated only from user-defined tax rules attached to a line, and posts to the rule's ledger.
8. **Roles allowed:** `settings.view` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /company/settings`](05-API-Specification.md#getcompanysettings), [`PATCH /company/settings`](05-API-Specification.md#patchcompanysettings), [`GET /tax-rules`](05-API-Specification.md#gettaxrules), [`POST /tax-rules`](05-API-Specification.md#posttaxrules), [`PATCH /tax-rules/{id}`](05-API-Specification.md#patchtaxrulesid), [`GET /ledgers`](05-API-Specification.md#getledgers)
10. **Entities:** `account_groups`, `audit_logs`, `company_settings`, `journal_entries`, `journal_lines`, `ledgers`, `tax_rules`
11. **Workflows:** Set up a business · FR: FR-084
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

<a id="page-k7"></a>
#### K7 · Invoice customization

1. **Purpose:** Layout, accent colour, what shows on the invoice, footer note; live A4 preview.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K7)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Layout | buttons | req | classic/banded/minimal |
   | Accent colour | swatches | req |  |
   | Logo / words / bank / signature / tax no. | switch | opt |  |
   | Footer note | text | opt | <= 255 |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k7)): Discard → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes → PATCH /company/settings
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `settings.edit` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /company/settings`](05-API-Specification.md#getcompanysettings), [`PATCH /company/settings`](05-API-Specification.md#patchcompanysettings)
10. **Entities:** `audit_logs`, `company_settings`, `journal_entries`
11. **Workflows:** Set up a business · FR: FR-085
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

<a id="page-k8"></a>
#### K8 · User list

1. **Purpose:** People with role, last active and status; seats used.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K8)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search people | text | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k8)): Invite user → dlg:K9<br>Open user → nav:K10
6. **Tables:** —
7. **Validation and rules:** BR-059: Active plus invited users cannot exceed the company's seat limit.
8. **Roles allowed:** `settings.users` → Owner. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /users`](05-API-Specification.md#getusers), [`DELETE /invitations/{id}`](05-API-Specification.md#deleteinvitationsid)
10. **Entities:** `company_users`, `roles`, `user_invitations`, `users`
11. **Workflows:** — · FR: FR-006
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** —

<a id="page-k9"></a>
#### K9 · Invite user

1. **Purpose:** Email, name, role; uses a seat; link expires in 7 days.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K9)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Email address | text | req | valid; not already a member |
   | Name | text | opt |  |
   | Role | select | req |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k9)): Cancel → ui:close the dialog<br>Send invite → POST /invitations
6. **Tables:** —
7. **Validation and rules:** BR-059: Active plus invited users cannot exceed the company's seat limit.
8. **Roles allowed:** `settings.users` → Owner. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /invitations`](05-API-Specification.md#postinvitations), [`GET /roles`](05-API-Specification.md#getroles)
10. **Entities:** `branches`, `companies`, `company_users`, `roles`, `user_invitation_branches`, `user_invitations`
11. **Workflows:** Set up a business · FR: FR-006
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** —

<a id="page-k10"></a>
#### K10 · User details

1. **Purpose:** Profile, role badges, 2-step status, recent activity, what the role allows; deactivate dialog.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K10)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-k10)): Edit role → nav:K11<br>Change role → PATCH /users/{id}<br>Deactivate (dialog) → dlg:L6 → POST /users/{id}/deactivate<br>Reactivate → POST /users/{id}/reactivate<br>Resend invite → POST /invitations/{id}/resend<br>Revoke invitation (NEW) → dlg:L6 → DELETE /invitations/{id}
6. **Tables:** —
7. **Validation and rules:** BR-061: Deactivating a user or changing their password revokes all their sessions immediately.<br>BR-060: The Owner role always has every permission and a company always keeps at least one active Owner.
8. **Roles allowed:** `settings.users` → Owner. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /users/{id}`](05-API-Specification.md#getusersid), [`PATCH /users/{id}`](05-API-Specification.md#patchusersid), [`POST /users/{id}/deactivate`](05-API-Specification.md#postusersiddeactivate), [`POST /users/{id}/reactivate`](05-API-Specification.md#postusersidreactivate), [`POST /invitations/{id}/resend`](05-API-Specification.md#postinvitationsidresend), [`DELETE /invitations/{id}`](05-API-Specification.md#deleteinvitationsid), [`GET /audit-logs`](05-API-Specification.md#getauditlogs)
10. **Entities:** `audit_logs`, `company_user_branches`, `company_users`, `roles`, `user_invitations`, `user_sessions`, `users`
11. **Workflows:** — · FR: FR-008
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** —

<a id="page-k11"></a>
#### K11 · Roles and permissions

1. **Purpose:** Role chips; module × action checkbox matrix; approval limits.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K11)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Permission cells | checkbox | — | Owner locked |
   | Payments without approval up to | money | opt |  |
   | Journal vouchers without approval up to | money | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k11)): New role → POST /roles<br>Duplicate role → POST /roles (copy_from_role_id)<br>Discard → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save changes → PUT /roles/{id} (each changed role)<br>Delete custom role (NEW, only without members) → dlg:L7 → DELETE /roles/{id}
6. **Tables:** —
7. **Validation and rules:** BR-060: The Owner role always has every permission and a company always keeps at least one active Owner.<br>BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.
8. **Roles allowed:** `settings.roles` → Owner. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /roles`](05-API-Specification.md#getroles), [`GET /roles/{id}`](05-API-Specification.md#getrolesid), [`POST /roles`](05-API-Specification.md#postroles), [`PUT /roles/{id}`](05-API-Specification.md#putrolesid), [`DELETE /roles/{id}`](05-API-Specification.md#deleterolesid), [`GET /permissions`](05-API-Specification.md#getpermissions)
10. **Entities:** `audit_logs`, `company_users`, `permissions`, `role_permissions`, `roles`
11. **Workflows:** Set up a business · FR: FR-007
12. **States:** As Settings.
13. **Depends on:** —

<a id="page-k12"></a>
#### K12 · Notification preferences

1. **Purpose:** Per event: in app, email, SMS; daily summary time.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K12)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Event × channel | switch | opt |  |
   | Daily summary time | select | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k12)): Save changes → PUT /me/notification-preferences
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /me/notification-preferences`](05-API-Specification.md#getmenotificationpreferences), [`PUT /me/notification-preferences`](05-API-Specification.md#putmenotificationpreferences)
10. **Entities:** `notification_preferences`
11. **Workflows:** — · FR: FR-013, FR-086
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

<a id="page-k13"></a>
#### K13 · Appearance

1. **Purpose:** Theme, density, text size, reduce motion, sidebar collapsed.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K13)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Theme | buttons | req |  |
   | Table density | segmented | req |  |
   | Text size | segmented | req |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k13)): Save changes → PUT /me/preferences
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /me/preferences`](05-API-Specification.md#getmepreferences), [`PUT /me/preferences`](05-API-Specification.md#putmepreferences)
10. **Entities:** `user_preferences`
11. **Workflows:** — · FR: FR-086
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

<a id="page-k14"></a>
#### K14 · Security

1. **Purpose:** Password, 2-step, company-wide 2-step requirement (Owner), idle sign-out, signed-in sessions.
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K14)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Current / new password | password | req |  |
   | 2-step verification | switch | opt | requires TOTP confirmation |
   | Require 2-step for everyone | switch | opt | Owner only |
   | Sign out after inactivity | select | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k14)): Change password → PUT /me/password<br>Enable/disable 2-step → POST /me/two-factor → POST /me/two-factor/confirm \| DELETE /me/two-factor<br>Sign out (session) → DELETE /me/sessions/{id}<br>Sign out everywhere else → POST /me/sessions/revoke-others
6. **Tables:** —
7. **Validation and rules:** BR-061: Deactivating a user or changing their password revokes all their sessions immediately.
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`PUT /me/password`](05-API-Specification.md#putmepassword), [`POST /me/two-factor`](05-API-Specification.md#postmetwofactor), [`POST /me/two-factor/confirm`](05-API-Specification.md#postmetwofactorconfirm), [`DELETE /me/two-factor`](05-API-Specification.md#deletemetwofactor), [`GET /me/sessions`](05-API-Specification.md#getmesessions), [`DELETE /me/sessions/{id}`](05-API-Specification.md#deletemesessionsid), [`POST /me/sessions/revoke-others`](05-API-Specification.md#postmesessionsrevokeothers), [`GET /company/settings`](05-API-Specification.md#getcompanysettings), [`PATCH /company/settings`](05-API-Specification.md#patchcompanysettings)
10. **Entities:** `audit_logs`, `company_settings`, `journal_entries`, `user_sessions`, `users`
11. **Workflows:** — · FR: FR-002, FR-004, FR-009, FR-086
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

<a id="page-k15"></a>
#### K15 · Backup and export

1. **Purpose:** Month-end export pack, automatic backups, history, restore (Owner, type RESTORE).
2. **Components and layout:** Settings layout: section rail (Business, People, You, Data) on the left, grouped cards on the right, sticky unsaved-changes bar. _Source: Settings.dc.html (K15)._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Period | select | req |  |
   | Format | segmented | req | xlsx/pdf/both |
   | Reports | checkbox | req | at least one |
   | Daily backup | switch | opt |  |
   | Keep for | select | opt | 30/90/365 days |
   | Type RESTORE | text | req | restore dialog |
5. **Actions → API** ([full map](05-API-Specification.md#actions-k15)): Prepare export pack → POST /export-jobs → GET /export-jobs/{id}<br>Download backup → GET /backups/{id}/download<br>Back up now → POST /backups<br>Restore… (dialog) → dlg:L6 → POST /backups/{id}/restore
6. **Tables:** —
7. **Validation and rules:** BR-062: Restore is Owner-only, requires typing RESTORE, and always takes a pre-restore snapshot first.
8. **Roles allowed:** `settings.backup` → Owner, Finance manager. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /company/settings`](05-API-Specification.md#getcompanysettings), [`PATCH /company/settings`](05-API-Specification.md#patchcompanysettings), [`GET /backups`](05-API-Specification.md#getbackups), [`POST /backups`](05-API-Specification.md#postbackups), [`GET /backups/{id}/download`](05-API-Specification.md#getbackupsiddownload), [`POST /backups/{id}/restore`](05-API-Specification.md#postbackupsidrestore), [`POST /export-jobs`](05-API-Specification.md#postexportjobs), [`GET /export-jobs/{id}`](05-API-Specification.md#getexportjobsid)
10. **Entities:** `audit_logs`, `company_backups`, `company_settings`, `export_jobs`, `journal_entries`
11. **Workflows:** — · FR: FR-087
12. **States:** Loading skeleton cards; sticky 'Unsaved changes' bar; 409 on concurrent edits; success toast.
13. **Depends on:** —

### States


<a id="page-l1"></a>
#### L1 · Global search results

1. **Purpose:** Grouped results (parties, invoices, vouchers, ledgers, products) with type tabs; respects role.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L1)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l1)): —
6. **Tables:** —
7. **Validation and rules:** BR-037: Every read and write is scoped to the active company; any reference to another company's record is rejected as not found.<br>BR-052: Users without inventory.view_costs never receive cost, value or margin fields from the API.
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /search`](05-API-Specification.md#getsearch)
10. **Entities:** `journal_entries`, `ledgers`, `letters_of_credit`, `parties`, `products`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-012
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** —

<a id="page-l2"></a>
#### L2 · Command palette

1. **Purpose:** Ctrl K quick search and actions.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L2)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l2)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /search`](05-API-Specification.md#getsearch)
10. **Entities:** `journal_entries`, `ledgers`, `letters_of_credit`, `parties`, `products`, `purchase_bills`, `sales_invoices`
11. **Workflows:** — · FR: FR-012
12. **States:** Results stream as you type; 'No results'.
13. **Depends on:** —

<a id="page-l3"></a>
#### L3 · Notifications center

1. **Purpose:** Tabs All/Unread/Approvals/Alerts; grouped by day; mark all read.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L3)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l3)): Open notification (marks it read) → POST /notifications/{id}/read → nav:*<br>Mark all read → POST /notifications/read-all
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /notifications`](05-API-Specification.md#getnotifications), [`POST /notifications/{id}/read`](05-API-Specification.md#postnotificationsidread), [`POST /notifications/read-all`](05-API-Specification.md#postnotificationsreadall), [`GET /approvals`](05-API-Specification.md#getapprovals)
10. **Entities:** `approval_requests`, `journal_entries`, `notifications`
11. **Workflows:** — · FR: FR-013
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** —

<a id="page-l4"></a>
#### L4 · Notification detail drawer

1. **Purpose:** Approval drawer for a pending voucher: amount vs limit, facts, context banner, comment, Reject / Approve.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L4)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l4)): Approve → POST /approvals/{id}/approve<br>Reject (comment required) → POST /approvals/{id}/reject
6. **Tables:** —
7. **Validation and rules:** BR-007: A payment or journal voucher whose amount exceeds the submitter's role limit must be approved by a user with the module's approve permission before it posts.<br>BR-008: Nobody approves a voucher they submitted (segregation of duties).
8. **Roles allowed:** `accounting.approve` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /approvals`](05-API-Specification.md#getapprovals), [`GET /approvals/{id}`](05-API-Specification.md#getapprovalsid), [`POST /approvals/{id}/approve`](05-API-Specification.md#postapprovalsidapprove), [`POST /approvals/{id}/reject`](05-API-Specification.md#postapprovalsidreject)
10. **Entities:** `approval_requests`, `audit_logs`, `bill_allocations`, `journal_entries`, `journal_lines`, `notifications`, `users`
11. **Workflows:** — · FR: FR-014
12. **States:** As Dialog.
13. **Depends on:** —

<a id="page-l5"></a>
#### L5 · Quick-create menu

1. **Purpose:** Create menu with voucher shortcuts.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L5)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l5)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /parties`](05-API-Specification.md#postparties), [`POST /products`](05-API-Specification.md#postproducts)
10. **Entities:** `account_mappings`, `audit_logs`, `journal_entries`, `journal_lines`, `ledgers`, `number_sequences`, `parties`, `party_bank_accounts`, `product_warehouse_settings`, `products`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-041
12. **States:** Results stream as you type; 'No results'.
13. **Depends on:** —

<a id="page-l6"></a>
#### L6 · Confirmation dialog

1. **Purpose:** Bulk 'Post 12 draft invoices?' with list and total.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L6)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l6)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /sales-invoices/bulk-post`](05-API-Specification.md#postsalesinvoicesbulkpost)
10. **Entities:** `account_mappings`, `accounting_periods`, `journal_entries`, `journal_lines`, `number_sequences`, `sales_invoice_lines`, `sales_invoices`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-041
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** —

<a id="page-l7"></a>
#### L7 · Delete confirmation

1. **Purpose:** Type the customer name to delete a party with no history.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L7)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l7)): —
6. **Tables:** —
7. **Validation and rules:** BR-026: A party, product, warehouse or ledger with history cannot be deleted, only made inactive; inactive records cannot be used on new documents.
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`DELETE /parties/{id}`](05-API-Specification.md#deletepartiesid)
10. **Entities:** `audit_logs`, `journal_lines`, `ledgers`, `parties`
11. **Workflows:** — · FR: FR-025
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** —

<a id="page-l8"></a>
#### L8 · Validation errors

1. **Purpose:** Banner with role=alert, inline field errors, focus moves to first error.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L8)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l8)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** none of its own (uses host page data)
10. **Entities:** —
11. **Workflows:** —
12. **States:** Is itself a state pattern.
13. **Depends on:** —

<a id="page-l9"></a>
#### L9 · Empty states

1. **Purpose:** First use, no matches, no data in period, all done.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L9)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l9)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** none of its own (uses host page data)
10. **Entities:** —
11. **Workflows:** —
12. **States:** Is itself a state pattern.
13. **Depends on:** —

<a id="page-l10"></a>
#### L10 · Loading states

1. **Purpose:** Skeletons matching final layout; 'Still loading…' after 8 s; actions disabled.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L10)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l10)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** none of its own (uses host page data)
10. **Entities:** —
11. **Workflows:** —
12. **States:** Is itself a state pattern.
13. **Depends on:** —

<a id="page-l11"></a>
#### L11 · Error states

1. **Purpose:** Offline banner; widget-level failure with error ref; period locked; concurrent edit conflict.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L11)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l11)): —
6. **Tables:** —
7. **Validation and rules:** BR-004: A document or voucher date must fall inside an open accounting period of an open financial year; posting, editing or reversing into a locked period is rejected.<br>BR-049: Updates must send the current version (If-Match); a mismatch is rejected with 409 and the user's input is preserved.
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** none of its own (uses host page data)
10. **Entities:** —
11. **Workflows:** —
12. **States:** Is itself a state pattern.
13. **Depends on:** —

<a id="page-l12"></a>
#### L12 · Success and other toasts

1. **Purpose:** Toast rules: say what happened, 5 s, errors persist, max 3, polite live region.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L12)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l12)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** none of its own (uses host page data)
10. **Entities:** —
11. **Workflows:** —
12. **States:** —
13. **Depends on:** —

<a id="page-l13"></a>
#### L13 · Unsaved changes

1. **Purpose:** Lists changed fields; Save and leave / Discard / Keep editing.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L13)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l13)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** none of its own (uses host page data)
10. **Entities:** —
11. **Workflows:** —
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** —

<a id="page-l14"></a>
#### L14 · Access denied

1. **Purpose:** 403 with role explanation and 'Ask Owner for access'.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L14)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l14)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** none of its own (uses host page data)
10. **Entities:** —
11. **Workflows:** —
12. **States:** Is itself a state pattern.
13. **Depends on:** —

<a id="page-l15"></a>
#### L15 · Not found

1. **Purpose:** 404 with search and links.
2. **Components and layout:** Shared component patterns shown in context on real pages. _Source: States.dc.html (L15)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-l15)): —
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `auth.self` → Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** none of its own (uses host page data)
10. **Entities:** —
11. **Workflows:** —
12. **States:** Is itself a state pattern.
13. **Depends on:** —

### LC


<a id="page-m1"></a>
#### M1 · LC dashboard

1. **Purpose:** KPIs (active, commitments, outstanding, due in 30 days), alerts, 60-day deadlines, commitments by bank, pipeline, active LCs, recent activity.
2. **Components and layout:** 4 KPI blocks; 6 alert buttons; deadlines list; bank bars; stage pipeline; active table; activity list. _Source: LC.dc.html (M1)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-m1)): New LC → nav:M3<br>Open alert → nav:M4<br>Open LC → nav:M4<br>All LCs → nav:M2
6. **Table:** columns LC, Supplier, Outstanding (US$), Expiry, Status; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `lc.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /lc/overview`](05-API-Specification.md#getlcoverview)
10. **Entities:** `audit_logs`, `lc_amendments`, `lc_charges`, `lc_documents`, `lc_payments`, `lc_shipments`, `letters_of_credit`
11. **Workflows:** Import and LC · FR: FR-090
12. **States:** Loading skeleton tiles and charts; each widget fails independently with an error ref (L11). Empty period: 'Nothing posted in this period'.
13. **Depends on:** [M2](#page-m2), [M4](#page-m4), [M8](#page-m8)

<a id="page-m2"></a>
#### M2 · LC list

1. **Purpose:** All LCs with tabs All/Draft/Active/Closed/Expired, search, bank, supplier, needs-attention filter.
2. **Components and layout:** Tabs; toolbar; table with totals; empty state. _Source: LC-List.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Search | text | opt | LC number, supplier, goods or bank ref |
   | Issuing bank | select | opt |  |
   | Supplier | select | opt |  |
   | Needs attention only | checkbox | opt |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-m2)): New LC → nav:M3<br>Open LC → nav:M4
6. **Table:** columns LC, Supplier and goods, Issuing bank, Amount (US$), Outstanding (US$), Latest shipment, Expiry, Documents, Status; filters status, bank, supplier, attention; search; server-side pagination and sorting.
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `lc.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /letters-of-credit`](05-API-Specification.md#getlettersofcredit)
10. **Entities:** `lc_charges`, `lc_documents`, `lc_payments`, `letters_of_credit`, `money_accounts`, `parties`
11. **Workflows:** — · FR: FR-091
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [M3](#page-m3), [M4](#page-m4)

<a id="page-m3"></a>
#### M3 · Create LC

1. **Purpose:** Six sections in any order: supplier and purchase, terms and amount, banks and dates, documents, shipment (optional), review; save draft or submit to bank.
2. **Components and layout:** Info banner; section nav with done/error marks; section cards; sticky footer with draft number and total; Next section. _Source: LC-Create*.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | International supplier | select | req | supplier |
   | Link to | segmented | req | purchase order / intended import |
   | Purchase order | select | cond | open PO |
   | Intended import | text | cond |  |
   | Goods description | text | req |  |
   | Import authorization reference | text | opt |  |
   | LC type | select | req |  |
   | Payment tenor | segmented | req | sight/usance |
   | Usance days | number | cond | > 0 |
   | Incoterm | select | req |  |
   | Currency | select | req | USD/EUR/CNY |
   | LC amount | money | req | > 0 |
   | Tolerance | pct | opt | >= 0 |
   | Planning exchange rate | rate | req | > 0 |
   | Partial shipments / Transhipment / Confirmation | switch | opt |  |
   | Issuing bank | select | req | our bank account |
   | Advising bank | text | opt |  |
   | Margin deposit % | pct | opt | 0–100 |
   | Margin from account | select | cond | when margin > 0 |
   | Application date | date | opt |  |
   | Latest shipment date | date | opt | <= expiry |
   | Expiry date | date | req | > application date |
   | Presentation period | number | opt | days |
   | Opening status | select | req |  |
   | Bank reference | text | cond | required when opened |
   | Opening date | date | cond |  |
   | Required documents | checklist | opt | from template |
   | Record shipment information | switch | opt |  |
   | Port of loading / discharge, expected shipment, mode | — | cond |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-m3)): Cancel → ui:discard changes (L13 unsaved-changes dialog when dirty)<br>Save draft → POST /letters-of-credit \| PUT /letters-of-credit/{id}<br>Next section → ui:validate this section on the client and scroll to the next<br>Submit to bank → POST /letters-of-credit \| PUT /letters-of-credit/{id} → POST /letters-of-credit/{id}/transition (action=submit)<br>Add document → POST /letters-of-credit/{id}/documents<br>Change template → GET /lc-document-templates → PUT /letters-of-credit/{id} (document_template_id)<br>Delete draft (NEW) → dlg:L7 → DELETE /letters-of-credit/{id}
6. **Tables:** —
7. **Validation and rules:** Section marks '!' on errors; review lists missing supplier, amount, bank<br>Banner 'Go back to the marked sections. You can still save a draft.'<br>BR-039: Submitting an LC to the bank requires supplier, amount > 0, issuing bank and expiry date; marking it opened requires the bank reference and opening date.<br>BR-040: LC status follows the state machine in 06 section 12.1; closed and cancelled LCs are read-only.<br>BR-006: Document numbers come from the company's sequence for the document type and FY, are unique, are reserved at first save and are never reused; voiding a draft number is audited.
8. **Roles allowed:** `lc.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /parties`](05-API-Specification.md#getparties), [`GET /purchase-orders`](05-API-Specification.md#getpurchaseorders), [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`POST /letters-of-credit`](05-API-Specification.md#postlettersofcredit), [`PUT /letters-of-credit/{id}`](05-API-Specification.md#putlettersofcreditid), [`DELETE /letters-of-credit/{id}`](05-API-Specification.md#deletelettersofcreditid), [`POST /letters-of-credit/{id}/transition`](05-API-Specification.md#postlettersofcreditidtransition), [`POST /letters-of-credit/{id}/documents`](05-API-Specification.md#postlettersofcreditiddocuments), [`GET /lc-document-templates`](05-API-Specification.md#getlcdocumenttemplates)
10. **Entities:** `audit_logs`, `bank_statement_lines`, `journal_lines`, `lc_document_template_items`, `lc_document_templates`, `lc_documents`, `lc_shipments`, `ledgers`, `letters_of_credit`, `money_accounts`, `number_sequences`, `parties`, `party_groups`, `payment_terms`, `purchase_bills`, `purchase_orders`, `sales_invoices`
11. **Workflows:** Import and LC · FR: FR-092
12. **States:** Loading: skeleton fields when editing. Validation: banner 'Fix N fields' (role=alert) and inline errors from 422 `errors` (L8). Saving: primary button busy and disabled; Idempotency-Key reused on retry. Success: toast and navigate to details. Conflict: 409 banner with reload, edits kept (L11). Unsaved changes dialog on leave (L13).
13. **Depends on:** [F8](#page-f8), [G4](#page-g4), [M6](#page-m6)

<a id="page-m4"></a>
#### M4 · LC details

1. **Purpose:** One LC: status and flags, workflow milestones, summary strip, tabs Overview, Terms, Documents, Shipment, Charges and payments, Amendments, Activity.
2. **Components and layout:** Header with LC picker and More (record payment, add charge, print, close); milestone stepper (steps can be 'Not needed'); tabs. Variants: LC-2026-016 usance due, -012 expired partly used, -018 two lots + amendments, -020 awaiting bank, -021 draft. _Source: LC-Details.dc.html + LC-Det*.dc.html (11)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-m4)): Record payment (dialog) → dlg:LC-DlgPay<br>Add charge (drawer) → dlg:LC-DrawerCharge<br>Record amendment → POST /letters-of-credit/{id}/amendments<br>Attach scan → POST /attachments<br>Add document → POST /letters-of-credit/{id}/documents<br>Update document status → PATCH /lc-documents/{id}<br>Add/update shipment lot → POST /letters-of-credit/{id}/shipments \| PATCH /lc-shipments/{id}<br>Receive goods (opens F3 in import mode) → nav:F3 (import mode, LC and lot prefilled)<br>Allocate landed cost → POST /letters-of-credit/{id}/landed-cost/preview → POST /letters-of-credit/{id}/landed-cost<br>Open cost summary → GET /letters-of-credit/{id}/cost-summary<br>Close LC (dialog) → dlg:LC-DlgClose<br>Print LC summary → GET /letters-of-credit/{id}/pdf → ui:print<br>Reverse payment, charge or landed-cost allocation (NEW, R-03) → dlg:L6 → POST /lc-payments/{id}/reverse \| dlg:L6 → POST /lc-charges/{id}/reverse \| dlg:L6 → POST /landed-cost-allocations/{id}/reverse<br>Pay a charge (NEW, row) → POST /lc-charges/{id}/pay<br>Remove attachment (NEW) → DELETE /attachments/{id}
6. **Tables:** —
7. **Validation and rules:** BR-040: LC status follows the state machine in 06 section 12.1; closed and cancelled LCs are read-only.<br>BR-042: LC payment taka amount = foreign amount x bank rate (rounded to 2 dp); total paid may not exceed LC amount x (1 + tolerance).<br>BR-044: An LC margin deposit is a refundable asset, never part of landed cost; it is applied to the payment or released at closure. The margin applied to payments may not exceed the margin deposited for that LC.<br>BR-045: LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold.<br>BR-046: An LC can be closed only when the supplier is fully paid (or the LC expired), no charges are unpaid and no documents are missing or discrepant, or with an explicit override reason.
8. **Roles allowed:** `lc.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /attachments`](05-API-Specification.md#postattachments), [`GET /attachments/{id}/url`](05-API-Specification.md#getattachmentsidurl), [`DELETE /attachments/{id}`](05-API-Specification.md#deleteattachmentsid), [`GET /audit-logs`](05-API-Specification.md#getauditlogs), [`POST /purchase-bills`](05-API-Specification.md#postpurchasebills), [`GET /letters-of-credit/{id}`](05-API-Specification.md#getlettersofcreditid), [`POST /letters-of-credit/{id}/transition`](05-API-Specification.md#postlettersofcreditidtransition), [`POST /letters-of-credit/{id}/close`](05-API-Specification.md#postlettersofcreditidclose), [`GET /letters-of-credit/{id}/documents`](05-API-Specification.md#getlettersofcreditiddocuments), [`POST /letters-of-credit/{id}/documents`](05-API-Specification.md#postlettersofcreditiddocuments), [`PATCH /lc-documents/{id}`](05-API-Specification.md#patchlcdocumentsid), [`POST /letters-of-credit/{id}/amendments`](05-API-Specification.md#postlettersofcreditidamendments), [`PATCH /lc-amendments/{id}`](05-API-Specification.md#patchlcamendmentsid), [`POST /letters-of-credit/{id}/shipments`](05-API-Specification.md#postlettersofcreditidshipments), [`PATCH /lc-shipments/{id}`](05-API-Specification.md#patchlcshipmentsid), [`POST /lc-charges`](05-API-Specification.md#postlccharges), [`POST /lc-charges/{id}/reverse`](05-API-Specification.md#postlcchargesidreverse), [`POST /lc-charges/{id}/pay`](05-API-Specification.md#postlcchargesidpay), [`POST /letters-of-credit/{id}/payments`](05-API-Specification.md#postlettersofcreditidpayments), [`GET /letters-of-credit/{id}/payments`](05-API-Specification.md#getlettersofcreditidpayments), [`POST /lc-payments/{id}/reverse`](05-API-Specification.md#postlcpaymentsidreverse), [`POST /letters-of-credit/{id}/landed-cost/preview`](05-API-Specification.md#postlettersofcreditidlandedcostpreview), [`POST /letters-of-credit/{id}/landed-cost`](05-API-Specification.md#postlettersofcreditidlandedcost), [`POST /landed-cost-allocations/{id}/reverse`](05-API-Specification.md#postlandedcostallocationsidreverse), [`GET /letters-of-credit/{id}/pdf`](05-API-Specification.md#getlettersofcreditidpdf), [`GET /letters-of-credit/{id}/cost-summary`](05-API-Specification.md#getlettersofcreditidcostsummary)
10. **Entities:** `account_mappings`, `attachments`, `audit_logs`, `bill_allocations`, `companies`, `journal_entries`, `journal_lines`, `landed_cost_allocation_lines`, `landed_cost_allocations`, `lc_amendment_changes`, `lc_amendments`, `lc_charges`, `lc_documents`, `lc_payments`, `lc_shipments`, `letters_of_credit`, `money_accounts`, `number_sequences`, `parties`, `payment_details`, `products`, `purchase_bill_lines`, `purchase_bills`, `stock_balances`, `stock_movements`, `users`
11. **Workflows:** Import and LC · FR: FR-093, FR-096, FR-098
12. **States:** Loading: skeleton strip and tabs. Not found: 404 page (L15). Error per card with Retry. Success toasts after actions.
13. **Depends on:** [M5](#page-m5), [M6](#page-m6), [M7](#page-m7), [LC-DlgPay](#page-lc-dlgpay), [LC-DlgClose](#page-lc-dlgclose), [LC-DrawerCharge](#page-lc-drawercharge), [F3](#page-f3)

<a id="page-m5"></a>
#### M5 · LC amendments

1. **Purpose:** All amendments with filter; form to record one: LC, new expiry, new latest shipment, new amount, bank fee, other change, status; preview of changes.
2. **Components and layout:** Filter segmented; table; form card with diff preview. _Source: LC-Amendments.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | LC | select | req | active, opened |
   | New expiry date | date | opt |  |
   | New latest shipment | date | opt | <= new expiry |
   | New amount | money | opt | > 0 |
   | Bank fee (৳) | money | opt | >= 0 |
   | Other change | text | opt |  |
   | Status | segmented | req | requested/issued/accepted |
5. **Actions → API** ([full map](05-API-Specification.md#actions-m5)): Clear → ui:clear the filters<br>Record amendment → POST /letters-of-credit/{id}/amendments \| PATCH /lc-amendments/{id}<br>Open LC → nav:M4
6. **Tables:** —
7. **Validation and rules:** Record disabled until something changes<br>Amount error when invalid<br>BR-041: LC amendment changes apply to the LC only when the amendment is accepted; each accepted change is recorded with old and new values.
8. **Roles allowed:** `lc.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /letters-of-credit`](05-API-Specification.md#getlettersofcredit), [`GET /lc-amendments`](05-API-Specification.md#getlcamendments), [`POST /letters-of-credit/{id}/amendments`](05-API-Specification.md#postlettersofcreditidamendments), [`PATCH /lc-amendments/{id}`](05-API-Specification.md#patchlcamendmentsid)
10. **Entities:** `audit_logs`, `lc_amendment_changes`, `lc_amendments`, `lc_charges`, `lc_documents`, `lc_payments`, `letters_of_credit`, `money_accounts`, `parties`
11. **Workflows:** — · FR: FR-094
12. **States:** Loading: skeleton rows matching columns (L10). Empty: first-use empty state with primary action, or 'No matches' with Clear filters (L9). Error: inline banner with Retry; offline banner (L11). Success: toast after bulk actions (L12). 403 page if view permission missing (L14).
13. **Depends on:** [M4](#page-m4)

<a id="page-m6"></a>
#### M6 · LC documents

1. **Purpose:** Document status matrix across open LCs, follow-up list, default checklist template.
2. **Components and layout:** KPIs; legend; LC × document-type grid; needs follow-up list; template list with Edit templates. _Source: LC-Documents.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-m6)): Open LC documents → nav:M4<br>Edit templates (NEW editor, GAP-14) → POST /lc-document-templates \| PUT /lc-document-templates/{id}
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `lc.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`PATCH /lc-documents/{id}`](05-API-Specification.md#patchlcdocumentsid), [`GET /lc-documents/matrix`](05-API-Specification.md#getlcdocumentsmatrix), [`GET /lc-document-templates`](05-API-Specification.md#getlcdocumenttemplates), [`PUT /lc-document-templates/{id}`](05-API-Specification.md#putlcdocumenttemplatesid), [`POST /lc-document-templates`](05-API-Specification.md#postlcdocumenttemplates)
10. **Entities:** `audit_logs`, `lc_document_template_items`, `lc_document_templates`, `lc_documents`, `letters_of_credit`
11. **Workflows:** — · FR: FR-095
12. **States:** As Settings.
13. **Depends on:** [M4](#page-m4)

<a id="page-m7"></a>
#### M7 · Charges and cost summary

1. **Purpose:** All charges with LC and paid filters; landed cost breakdown for an arrived LC with per-kg cost.
2. **Components and layout:** KPIs; filters; charges table; landed-cost bar and rows; note 'Duties … Pluto does not calculate them'. _Source: LC-Charges.dc.html._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-m7)): Add charge → dlg:LC-DrawerCharge<br>Choose LC → GET /letters-of-credit → GET /letters-of-credit/{id}/cost-summary<br>Allocate landed cost → POST /letters-of-credit/{id}/landed-cost/preview → POST /letters-of-credit/{id}/landed-cost<br>Pay a charge (NEW, row) → POST /lc-charges/{id}/pay
6. **Tables:** —
7. **Validation and rules:** BR-045: LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold.<br>BR-047: Customs duties and taxes are entered as assessed; Pluto does not calculate them.
8. **Roles allowed:** `lc.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /letters-of-credit`](05-API-Specification.md#getlettersofcredit), [`GET /lc-charges`](05-API-Specification.md#getlccharges), [`POST /lc-charges`](05-API-Specification.md#postlccharges), [`POST /lc-charges/{id}/reverse`](05-API-Specification.md#postlcchargesidreverse), [`POST /lc-charges/{id}/pay`](05-API-Specification.md#postlcchargesidpay), [`POST /letters-of-credit/{id}/landed-cost/preview`](05-API-Specification.md#postlettersofcreditidlandedcostpreview), [`POST /letters-of-credit/{id}/landed-cost`](05-API-Specification.md#postlettersofcreditidlandedcost), [`POST /landed-cost-allocations/{id}/reverse`](05-API-Specification.md#postlandedcostallocationsidreverse), [`GET /letters-of-credit/{id}/cost-summary`](05-API-Specification.md#getlettersofcreditidcostsummary)
10. **Entities:** `account_mappings`, `audit_logs`, `journal_entries`, `journal_lines`, `landed_cost_allocation_lines`, `landed_cost_allocations`, `lc_charges`, `lc_documents`, `lc_payments`, `letters_of_credit`, `money_accounts`, `number_sequences`, `parties`, `payment_details`, `purchase_bill_lines`, `purchase_bills`, `stock_balances`, `stock_movements`
11. **Workflows:** — · FR: FR-097, FR-098
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [M4](#page-m4)

<a id="page-m8"></a>
#### M8 · LC reports

1. **Purpose:** Eight tabs: register, active and expired, outstanding commitments, upcoming expiry, document completion, amendment history, charges and payments, import cost summary.
2. **Components and layout:** Tabs; date range; bank; segment; paper report table. _Source: LC-Rpt*.dc.html (8)._
3–4. **Inputs:** none (read-only page; filters listed below)
5. **Actions → API** ([full map](05-API-Specification.md#actions-m8)): Export (xlsx, pdf) → export:GET /reports/lc/{report}<br>Open LC → nav:M4
6. **Tables:** —
7. **Validation and rules:** Standard server validation (422).
8. **Roles allowed:** `lc.view` → Owner, Finance manager, Accountant, Viewer. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /lc-amendments`](05-API-Specification.md#getlcamendments), [`GET /reports/lc/{report}`](05-API-Specification.md#getreportslcreport)
10. **Entities:** `landed_cost_allocations`, `lc_amendment_changes`, `lc_amendments`, `lc_charges`, `lc_documents`, `lc_payments`, `letters_of_credit`
11. **Workflows:** — · FR: FR-099
12. **States:** Loading skeleton table; empty 'No data for this period'; export shows progress toast; large exports become a job.
13. **Depends on:** [M4](#page-m4)

<a id="page-lc-dlgpay"></a>
#### LC-DlgPay · Dialog · Record LC payment

1. **Purpose:** Amount (US$), rate applied by bank, paid on, debited from; taka equivalent.
2. **Components and layout:** Dialog. _Source: LC-DlgPay.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Amount | money | req | > 0; total <= amount x (1 + tolerance) |
   | Rate applied by bank | rate | req | > 0 |
   | Paid on | date | req |  |
   | Debited from | select | req |  |
5. **Actions → API** ([full map](05-API-Specification.md#actions-lc-dlgpay)): Cancel → ui:close the dialog<br>Record payment → POST /letters-of-credit/{id}/payments
6. **Tables:** —
7. **Validation and rules:** BR-042: LC payment taka amount = foreign amount x bank rate (rounded to 2 dp); total paid may not exceed LC amount x (1 + tolerance).<br>BR-043: Differences between the rate at which a foreign bill was booked and the rate at which it is paid post to the exchange gain or loss ledger.<br>BR-044: An LC margin deposit is a refundable asset, never part of landed cost; it is applied to the payment or released at closure. The margin applied to payments may not exceed the margin deposited for that LC.<br>BR-050: Create and post requests accept an Idempotency-Key; a replay with the same key returns the original response without side effects.
8. **Roles allowed:** `lc.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`GET /money-accounts`](05-API-Specification.md#getmoneyaccounts), [`POST /letters-of-credit/{id}/payments`](05-API-Specification.md#postlettersofcreditidpayments)
10. **Entities:** `account_mappings`, `bank_statement_lines`, `bill_allocations`, `journal_entries`, `journal_lines`, `lc_payments`, `ledgers`, `letters_of_credit`, `money_accounts`, `number_sequences`, `purchase_bills`
11. **Workflows:** — · FR: FR-097
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** [M4](#page-m4)

<a id="page-lc-dlgclose"></a>
#### LC-DlgClose · Dialog · Close LC

1. **Purpose:** Checklist of open items (supplier paid, unpaid charges, document issues, margin release).
2. **Components and layout:** Warning dialog with checks. _Source: LC-DlgClose.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | Override reason | text | cond | when checks fail |
5. **Actions → API** ([full map](05-API-Specification.md#actions-lc-dlgclose)): Cancel → ui:close the dialog<br>Close LC → POST /letters-of-credit/{id}/close
6. **Tables:** —
7. **Validation and rules:** BR-046: An LC can be closed only when the supplier is fully paid (or the LC expired), no charges are unpaid and no documents are missing or discrepant, or with an explicit override reason.
8. **Roles allowed:** `lc.edit` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /letters-of-credit/{id}/close`](05-API-Specification.md#postlettersofcreditidclose)
10. **Entities:** `journal_entries`, `journal_lines`, `lc_charges`, `lc_documents`, `lc_payments`, `letters_of_credit`, `number_sequences`
11. **Workflows:** — · FR: FR-099
12. **States:** Confirm button busy while the request runs; errors shown inside the dialog.
13. **Depends on:** [M4](#page-m4)

<a id="page-lc-drawercharge"></a>
#### LC-DrawerCharge · Drawer · Add charge or import expense

1. **Purpose:** LC, type, amount, paid to, payment status, add to landed cost.
2. **Components and layout:** Drawer. _Source: LC-DrawerCharge.dc.html._
3. **Inputs** (and 4. required/optional):

   | Field | Type | Req. | Rule |
   |---|---|---|---|
   | LC | select | req |  |
   | Type | select | req | 13 types |
   | Amount (৳) | money | req | > 0 |
   | Paid to | text | opt |  |
   | Payment status | segmented | req | unpaid/paid |
   | Paid from | select | cond | when paid |
   | Add to landed cost | switch | opt | not for margin |
5. **Actions → API** ([full map](05-API-Specification.md#actions-lc-drawercharge)): Cancel → ui:close the drawer<br>Add charge → POST /lc-charges
6. **Tables:** —
7. **Validation and rules:** BR-044: An LC margin deposit is a refundable asset, never part of landed cost; it is applied to the payment or released at closure. The margin applied to payments may not exceed the margin deposited for that LC.<br>BR-045: LC charges marked for landed cost are held in goods-in-transit and allocated to import bill lines by value (or quantity); the share for quantity already sold goes to cost of goods sold.<br>BR-047: Customs duties and taxes are entered as assessed; Pluto does not calculate them.
8. **Roles allowed:** `lc.create` → Owner, Finance manager, Accountant. Actions additionally check their own permission (see endpoints).
9. **APIs:** [`POST /lc-charges`](05-API-Specification.md#postlccharges)
10. **Entities:** `account_mappings`, `journal_entries`, `journal_lines`, `lc_charges`, `money_accounts`, `number_sequences`, `payment_details`
11. **Workflows:** — · FR: FR-097
12. **States:** As Dialog.
13. **Depends on:** [M4](#page-m4)
