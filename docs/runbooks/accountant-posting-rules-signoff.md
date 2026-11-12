# Accountant sign-off: posting rules (PEP-420, check B1)

**For:** the company's accountant · **From:** Pluto ERP project · **Rules source:** docs/06-Accounting-and-Business-Rules.md §5–§16

Pluto writes every journal itself from the business documents. Before go-live, please check that each document type
below posts the journal you expect. Use the vouchers you created during UAT in staging (runbook uat-and-go-live.md,
A5–A9): open the document, then its **Ledger entries** tab, and compare it with the expected entry. Tick ✅ or write
what is wrong in the last column. Anything marked ❌ becomes a Jira defect and is fixed before go-live.

Valuation is **moving weighted average per product and warehouse**. Back-dated stock documents do not re-cost later
sales (§11.1). FIFO is not offered in v1.

| # | Document | Expected journal (Dr / Cr) | Voucher checked | ✅ / remark |
|---|---|---|---|---|
| 1 | Sales invoice (§5) | Dr customer (total) / Cr income ledger (net per line) + Cr tax ledger (tax) ± rounding. Stocked lines also: Dr COGS / Cr warehouse inventory at average cost. Discounts net by default | | |
| 2 | Cash sale (§5) | The invoice above plus a receipt in the same transaction: Dr cash/bank / Cr customer | | |
| 3 | Customer receipt (§6) | Dr bank/cash / Cr customer. Bank charge deducted: Dr bank charges, money account gets the net. Unallocated amount stays as an advance | | |
| 4 | Sales return, restocked (§7) | Dr warehouse inventory / Cr COGS at the original unit cost | | |
| 5 | Credit note (§7) | Dr 4109 Sales returns (+ tax) / Cr customer. Refund also posts Dr customer / Cr money account | | |
| 6 | Purchase bill (§8) | Dr warehouse inventory (stock lines) or expense ledger (non-stock) + Dr recoverable input tax / Cr supplier. Non-recoverable tax is added to cost. Header discount lowers the stock lines' cost | | |
| 7 | Supplier payment (§9) | Dr supplier (+ Dr bank charges) / Cr money account. Above the role's limit it waits for approval | | |
| 8 | Purchase return / debit note (§10) | Dr supplier / Cr warehouse inventory at the original bill cost (no separate "purchase returns" ledger for stock). Price claim on goods already sold: Cr COGS | | |
| 9 | Opening stock (§11.2) | Dr warehouse inventory / Cr 3301 Opening balance adjustment, dated FY start | | |
| 10 | Stock adjustment (§11.3) | Shortage: Dr 5002 Stock adjustment / Cr inventory. Gain: the reverse. At average cost | | |
| 11 | Stock transfer (§11.4) | Same inventory ledger: no journal. Different ledgers: Dr stock in transit / Cr source, then Dr destination / Cr stock in transit. Transit loss is posted as a shortage | | |
| 12 | Cash count difference (§13) | Shortage: Dr cash short/over / Cr cash. Excess: the reverse | | |
| 13 | Income, expense, contra (§13) | Money in: Dr money account / Cr income. Money out: Dr expense (+ recoverable tax) / Cr money account. Transfer: Dr destination / Cr source | | |
| 14 | LC margin (§12.2) | Deposit: Dr 1403 LC margin / Cr bank. Released at closure: the reverse | | |
| 15 | LC charges (§12.3) | Dr 1404 Goods in transit (tagged LC) / Cr bank or C&F agent. Charges marked "not landed cost": Dr bank charges or expense | | |
| 16 | LC payment, sight (§12.4) | Dr supplier (foreign × bank rate) / Cr 1403 margin applied / Cr bank (the rest) | | |
| 17 | Import bill and FX (§12.5) | As the purchase bill, at the payment rate. Settlement difference: Dr/Cr 6603 FX gain/loss against the supplier | | |
| 18 | Usance LC (§12.6) | Acceptance: Dr supplier / Cr 2302 LC acceptance. Maturity: Dr 2302 / Cr margin + bank, difference to FX | | |
| 19 | Landed cost (§12.7) | Dr inventory (share still on hand) + Dr COGS (share already sold) / Cr 1404 Goods in transit. Average cost rises | | |
| 20 | PAD/LTR loan (§12.12) | Financed payment: Dr supplier / Cr margin / Cr LC loan. Accrual: Dr interest expense / Cr interest payable. Repayment: Dr loan + interest / Cr bank | | |
| 21 | FX revaluation (§12.13) | Dr/Cr FX gain/loss against supplier or LC acceptance on the revaluation date, mirrored the next day | | |
| 22 | Cancellation / reversal (§15) | Mirror entry; stock back at the original costs; number kept; reason recorded. Locked periods are never changed | | |
| 23 | Month close (§16) | Banks reconciled → drafts reviewed → TB balanced → period locked. Posting into a locked month is refused | | |
| 24 | Year-end close (§16) | Income and expense ledgers zeroed to retained earnings on the last day of the FY | | |

**Points that need your decision (not just a tick):**
- 3301 Opening balance adjustment: after the opening import it should equal opening equity. Please reclassify it to
  capital / retained earnings with a JV (§16).
- Interest on a usance LC: Pluto treats it as an expense, not landed cost (§12.6). Please confirm.
- Recoverable import VAT/AT: debited to a tax receivable, not landed cost (§12.3), subject to the tax adviser's answer.

**Sign-off**

I have checked the journals above in staging and they follow the agreed accounting treatment, except as noted.

Name: ____________________ Signature: ____________________ Date: ____________
