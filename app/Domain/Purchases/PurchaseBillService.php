<?php

namespace App\Domain\Purchases;

use App\Domain\Accounting\PeriodGuard;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Models\PurchaseBill;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Draft purchase bills (F3; backlog P4.2-BE-02; BR-006, BR-018, BR-025, BR-049). A draft is priced by
 * PurchaseBillPreparer — the same figures calculate showed — and stores totals and lines. Its BILL number is reserved
 * at first save in the year of the bill date and is never reused: deleting a draft voids it with an audit entry
 * (GAP-09). Only drafts can be changed or deleted (not_draft); stock and books change only when posting (P4.3).
 */
final class PurchaseBillService
{
    private const AUDITED = ['party_id', 'supplier_bill_no', 'bill_date', 'due_date', 'payment_term_id', 'warehouse_id', 'currency_code', 'exchange_rate',
        'subtotal', 'discount_total', 'tax_total', 'rounding_adjustment', 'total', 'notes'];

    public function __construct(
        private readonly PurchaseBillPreparer $preparer,
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function createDraft(array $in): PurchaseBill
    {
        return DB::transaction(function () use ($in) {
            $prepared = $this->preparer->prepare($in);
            $period = $this->periods->assertOpen($prepared->billDate);
            $this->assertUniqueSupplierBill($prepared->party->id, $in['supplier_bill_no']);
            $bill = new PurchaseBill(['status' => 'draft', 'payment_status' => 'unpaid', 'amount_settled' => '0']);
            $bill->number = $this->numbers->next('purchase_bill', $period->fiscal_year_id)['number'];
            $this->fill($bill, $prepared, $in);
            $this->save($bill, $prepared);
            $this->audit->record('created', $bill, null, $this->audited($bill) + ['number' => $bill->number]);

            return $bill->refresh()->load('lines');
        });
    }

    public function updateDraft(PurchaseBill $bill, array $in, int $expectedVersion): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $in, $expectedVersion) {
            $bill = PurchaseBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($bill);
            if ((int) $bill->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $bill->lock_version]);
            }
            $prepared = $this->preparer->prepare($in);
            $this->periods->assertOpen($prepared->billDate);
            $this->assertUniqueSupplierBill($prepared->party->id, $in['supplier_bill_no'], $bill->id);
            $old = $this->audited($bill);
            $this->fill($bill, $prepared, $in);
            $bill->lines()->delete(); // lines are replaced as a whole (PUT)
            $this->save($bill, $prepared);
            $this->audit->record('updated', $bill, $old, $this->audited($bill->refresh()));

            return $bill->load('lines');
        });
    }

    /** BR-025: drafts only. The number is voided, not freed (BR-006, GAP-09). */
    public function deleteDraft(PurchaseBill $bill): void
    {
        DB::transaction(function () use ($bill) {
            $bill = PurchaseBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($bill);
            $old = $this->audited($bill) + ['number' => $bill->number];
            $bill->lines()->delete();
            $bill->delete();
            $this->audit->record('voided_number', $bill, ['number' => $bill->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $bill, $old);
        });
    }

    public function assertDraft(PurchaseBill $bill): void
    {
        if ($bill->status !== 'draft') {
            throw new ApiProblem('not_draft', "{$bill->number} is {$bill->status}; only drafts can be changed or deleted.", extra: ['status' => $bill->status]);
        }
    }

    /** BR-018: a supplier's bill number is entered once (any status), naming the bill that has it. */
    private function assertUniqueSupplierBill(int $partyId, string $supplierBillNo, ?int $exceptId = null): void
    {
        $existing = PurchaseBill::where('party_id', $partyId)->where('supplier_bill_no', $supplierBillNo)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->value('number');
        if ($existing) {
            throw $this->duplicate($supplierBillNo, $existing);
        }
    }

    private function duplicate(string $supplierBillNo, string $existing): ApiProblem
    {
        return new ApiProblem('duplicate_supplier_bill', "This supplier's bill {$supplierBillNo} is already entered as {$existing}.",
            errors: ['supplier_bill_no' => ["Already entered as {$existing}"]], extra: ['existing_number' => $existing]);
    }

    private function fill(PurchaseBill $bill, PreparedBill $p, array $in): void
    {
        $c = $p->calculation;
        $bill->fill([
            'branch_id' => $in['branch_id'] ?? $p->warehouse->branch_id,
            'party_id' => $p->party->id,
            'party_snapshot' => ['code' => $p->party->code, 'name' => $p->party->name, 'mobile' => $p->party->mobile, 'address' => $p->party->address],
            'supplier_bill_no' => $in['supplier_bill_no'], 'bill_date' => $p->billDate->toDateString(), 'due_date' => $p->dueDate->toDateString(),
            'payment_term_id' => $p->paymentTermId, 'warehouse_id' => $p->warehouse->id,
            'purchase_order_id' => $in['purchase_order_id'] ?? null, 'letter_of_credit_id' => $in['letter_of_credit_id'] ?? null, 'lc_shipment_id' => $in['lc_shipment_id'] ?? null,
            'currency_code' => $p->currency, 'exchange_rate' => $p->exchangeRate,
            'notes' => $in['notes'] ?? null, 'internal_note' => $in['internal_note'] ?? null, 'receipt_condition_note' => $in['receipt_condition_note'] ?? null,
            // The books are in BDT: total is the base amount; total_foreign keeps the supplier's figure for foreign bills.
            'subtotal' => $c->subtotal, 'discount_total' => $c->discountTotal, 'tax_total' => $c->taxTotal, 'rounding_adjustment' => $c->baseRounding,
            'total' => $c->baseTotal, 'total_foreign' => $p->foreign() ? $c->total : null, 'balance_due' => $c->baseTotal,
        ]);
    }

    private function save(PurchaseBill $bill, PreparedBill $p): void
    {
        try {
            $bill->save();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'purchase_bills_company_id_party_id_supplier_bill_no_unique')) { // a parallel save won
                throw $this->duplicate($bill->supplier_bill_no, (string) PurchaseBill::where('party_id', $bill->party_id)->where('supplier_bill_no', $bill->supplier_bill_no)->value('number'));
            }
            throw $e;
        }
        foreach ($p->lines as $i => $l) {
            $c = $p->calculation->lines[$i]; // tax_rate is stored with the column's 4 decimals
            $bill->lines()->create([
                'line_no' => $i + 1, 'product_id' => $l['product']->id, 'product_code' => $l['product']->code, 'product_name' => $l['product']->name,
                'unit_name' => $l['product']->unit?->name ?? '', 'description' => $l['input']['description'] ?? null,
                'quantity' => $l['input']['quantity'], 'unit_cost' => $l['input']['unit_cost'], 'discount_pct' => $l['input']['discount_pct'] ?? '0',
                'discount_amount' => $c->discountTotal(), 'tax_rule_id' => $c->taxRuleId, 'tax_rate' => $l['tax_rate']->toBigDecimal()->toScale(4, RoundingMode::HalfUp)->toString(), 'tax_amount' => $c->tax,
                'net_amount' => $c->net, 'line_total' => $c->total, 'base_net_amount' => $c->baseNet, 'debit_ledger_id' => $l['debit_ledger_id'],
                'purchase_order_line_id' => $l['input']['purchase_order_line_id'] ?? null,
            ]);
        }
    }

    private function audited(PurchaseBill $b): array
    {
        $a = [];
        foreach (self::AUDITED as $k) {
            $v = $b->getAttribute($k);
            $a[$k] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_object($v) ? (string) $v : $v);
        }

        return $a;
    }
}
