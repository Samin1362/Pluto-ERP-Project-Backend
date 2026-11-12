# Questions for the tax adviser (PEP-420, check B2 · NFR-070)

**For:** the company's tax adviser · **From:** Pluto ERP project · **Source:** docs/06-Accounting-and-Business-Rules.md §14

Pluto is an accounting and stock system for a Bangladeshi trading company (wholesale goods, some imports under LC).
Before it goes live we need your written answers against current NBR rules. Pluto does **not** assume any Bangladesh tax
rule today. Each answer either confirms what Pluto does, or becomes a dated change request.

**What Pluto does now:** the company sets up its own tax rules (name, rate, sales/purchase, ledger). Output tax is
credited to a liability ledger. Input tax is debited to an asset ledger when the rule is marked recoverable, otherwise it
is added to the cost. Prices can be entered with or without tax. No rates are pre-loaded.

| # | Topic | Question | Your answer |
|---|---|---|---|
| 1 | VAT registration and invoice format | Is the company VAT-registered? Must sales invoices follow Mushak-6.3 (buyer and seller BIN, VAT per line, serial rules)? | |
| 2 | VAT returns and registers | Does the system need to produce Mushak 6.1 / 6.2 purchase and sales registers, or Mushak 9.1 return data? | |
| 3 | VAT rates and exemptions | Which rates apply to our products (standard, reduced, truncated, fixed, exempt)? Grocery items are often exempt or reduced; please list by product group | |
| 4 | Supplementary duty (SD) | Does SD apply to any of our items? Is it charged before VAT? | |
| 5 | VDS and TDS/AIT on supplier payments | Must we deduct VAT at source and TDS/AIT when paying suppliers, post them to payable ledgers and issue certificates? At what rates and thresholds? | |
| 6 | Import duties at customs | Of CD, RD, SD, VAT, AIT and AT, which are recoverable (input credit or advance tax) and which are part of the goods' cost? Pluto now records "customs duty and taxes as assessed" as one cost, with an optional separate recoverable part | |
| 7 | LC regulation | Beyond an optional import authorisation reference, do we need IRC, LCA/IMP form references or Bangladesh Bank reporting in the system? | |
| 8 | Invoice numbering | Must invoice numbers be a gapless sequence? (Pluto never reuses a number; a deleted draft's number is voided and logged, so gaps can occur) | |
| 9 | Record retention | How many years must books and VAT records be kept? (Pluto keeps 8 years by default) | |
| 10 | Financial statements | Must the statements follow IFRS for SMEs / BFRS presentation? (Pluto's are management accounts, marked unaudited) | |
| 11 | Income tax | Is any income tax provision or advance tax tracking expected in the books? | |

**Sign-off**

The answers above reflect the rules in force on the date below.

Name: ____________________ Firm: ____________________ Signature: ____________________ Date: ____________
