# Runbook: import the opening data (go-live migration)

Backlog P17.1-BE-05 (BR-028; 10 §4). Moves the owner's balances on the go-live date into Pluto: customers and
suppliers with what they owe / are owed, ledger balances (capital, loans, fixed assets…), bank and cash accounts, and
opening stock. Rehearse on a staging copy first (PEP-419 UAT), then repeat on live.

## 1. Prepare the files
Copy the templates from `docs/import-templates/` and fill them from the old books **as at the day before go-live**
(the opening entries are dated the financial year's start, BR-028):

| File | Columns (header row, any order) | Notes |
|---|---|---|
| `customers.csv` | name, mobile, email, address, district, contact_person, credit_limit, opening_balance, opening_side | side `dr` (they owe us) is the default |
| `suppliers.csv` | name, mobile, email, address, district, contact_person, opening_balance, opening_side | side `cr` (we owe them) is the default |
| `ledgers.csv` | code, name, group, opening_balance, opening_side | an existing code sets that ledger's opening; a new code also needs name and group (code or name) |
| `banks.csv` | account_type, name, bank_name, branch_name, account_name, account_number, opening_balance | type bank, cash, wallet, card or cash_credit |
| `stock.csv` | product_code, warehouse_code, quantity, unit_cost | products and warehouses that already exist (create the warehouses first; the template's `WH-1` is only a sample code); new products with stock go through Inventory → Products → Import (D1) |

Amounts are plain numbers with up to 2 decimals (`125000.50`, no commas or ৳). Save as CSV UTF-8.

## 2. Check, then import (in this order)
```bash
mkdir -p storage/app/import && cp ~/opening/*.csv storage/app/import/     # live: copy into the app container instead
for t in banks ledgers customers suppliers stock; do
  ./scripts/live.sh artisan pluto:import:opening $t storage/app/import/$t.csv --dry-run --errors=storage/app/import/$t-errors.csv
done
```
A dry run executes every row inside a transaction and rolls it back, so it finds business errors too (duplicate party,
locked opening stock). Fix the rows named in `<type>-errors.csv` (row numbers count the header as row 1) and repeat
until every dry run says `0 errors`. Then run the same commands without `--dry-run`.

**All or nothing:** one bad row means nothing from that file is saved. Running a file twice is refused row by row
("Already in the books"), so nothing is doubled.

## 3. Check the result
- Reports → Trial balance at the go-live date: total Dr = total Cr; **Opening balance adjustment (3301)** should be
  the owner's equity difference only. If 3301 is not what the accountant expects, a balance is missing or on the wrong side.
- Customers → Outstanding and Suppliers → Outstanding agree with the old books' party lists.
- Inventory → Valuation agrees with the old stock sheet.
- The accountant signs the opening trial balance (PEP-420).
