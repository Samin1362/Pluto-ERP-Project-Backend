<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\PeriodGuard;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Models\SalesInvoice;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Draft sales invoices (E3, E6; backlog P5.1-BE-02; BR-006, BR-013, BR-025, BR-049, BR-055). A draft is priced by
 * SalesInvoicePreparer — the same figures calculate showed — and stores totals and lines with the product snapshot.
 * Its INV number is reserved at first save in the year of the invoice date and is never reused: deleting a draft
 * voids it with an audit entry (GAP-09). Only drafts can be changed or deleted (not_draft); stock and books change
 * only when posting (P5.1-BE-03). Stock shortfall and credit limit do not stop a draft (BR-015; posting checks stock).
 */
final class SalesInvoiceService
{
    private const AUDITED = ['party_id', 'invoice_date', 'due_date', 'payment_term_id', 'warehouse_id', 'customer_po_ref', 'subtotal', 'discount_total',
        'tax_total', 'rounding_adjustment', 'total', 'notes'];

    public function __construct(
        private readonly SalesInvoicePreparer $preparer,
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function createDraft(array $in): SalesInvoice
    {
        return DB::transaction(function () use ($in) {
            $prepared = $this->preparer->prepare($in);
            $period = $this->periods->assertOpen($prepared->invoiceDate);
            $invoice = new SalesInvoice(['status' => 'draft', 'payment_status' => 'unpaid', 'amount_settled' => '0']);
            $invoice->number = $this->numbers->next('sales_invoice', $period->fiscal_year_id)['number'];
            $this->fill($invoice, $prepared, $in);
            $invoice->save();
            $this->saveLines($invoice, $prepared);
            $this->audit->record('created', $invoice, null, $this->audited($invoice) + ['number' => $invoice->number]);

            return $invoice->refresh()->load('lines');
        });
    }

    public function updateDraft(SalesInvoice $invoice, array $in, int $expectedVersion): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $in, $expectedVersion) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($invoice);
            if ((int) $invoice->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $invoice->lock_version]);
            }
            $prepared = $this->preparer->prepare($in);
            $this->periods->assertOpen($prepared->invoiceDate);
            $old = $this->audited($invoice);
            $this->fill($invoice, $prepared, $in);
            $invoice->lines()->delete(); // lines are replaced as a whole (PUT)
            $invoice->save();
            $this->saveLines($invoice, $prepared);
            $this->audit->record('updated', $invoice, $old, $this->audited($invoice->refresh()));

            return $invoice->load('lines');
        });
    }

    /** BR-025: drafts only. The number is voided, not freed (BR-006, GAP-09). */
    public function deleteDraft(SalesInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($invoice);
            $old = $this->audited($invoice) + ['number' => $invoice->number];
            $invoice->lines()->delete();
            $invoice->delete();
            $this->audit->record('voided_number', $invoice, ['number' => $invoice->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $invoice, $old);
        });
    }

    /**
     * PATCH /sales-invoices/{id}/delivery (GAP-12, R-31): challan, vehicle and delivery time are not financial, so they
     * may change on a draft or a posted invoice (BR-003); never on a cancelled one. Needs the current version; audited.
     */
    public function updateDelivery(SalesInvoice $invoice, array $in, int $expectedVersion): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $in, $expectedVersion) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'cancelled') {
                throw new ApiProblem('invalid_transition', "{$invoice->number} is cancelled; its delivery details can no longer change.", extra: ['status' => $invoice->status]);
            }
            if ((int) $invoice->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $invoice->lock_version]);
            }
            $fields = ['challan_no', 'vehicle_no', 'delivered_at'];
            $old = $this->delivery($invoice);
            foreach ($fields as $f) {
                if (array_key_exists($f, $in)) {
                    $invoice->{$f} = $f === 'delivered_at' && $in[$f] !== null ? CarbonImmutable::parse($in[$f])->setTimezone(config('app.timezone')) : $in[$f];
                }
            }
            $invoice->save();
            $this->audit->record('updated', $invoice, $old, $this->delivery($invoice->refresh()));

            return $invoice->load('lines');
        });
    }

    private function delivery(SalesInvoice $inv): array
    {
        return ['challan_no' => $inv->challan_no, 'vehicle_no' => $inv->vehicle_no, 'delivered_at' => $inv->delivered_at?->toIso8601String()];
    }

    public function assertDraft(SalesInvoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw new ApiProblem('not_draft', "{$invoice->number} is {$invoice->status}; only drafts can be changed or deleted.", extra: ['status' => $invoice->status]);
        }
    }

    private function fill(SalesInvoice $invoice, PreparedInvoice $p, array $in): void
    {
        $c = $p->calculation;
        $invoice->fill([
            'branch_id' => $in['branch_id'] ?? $p->warehouse->branch_id,
            'party_id' => $p->party->id,
            'party_snapshot' => ['code' => $p->party->code, 'name' => $p->party->name, 'mobile' => $p->party->mobile, 'address' => $p->party->address],
            'customer_po_ref' => $in['customer_po_ref'] ?? null, 'invoice_date' => $p->invoiceDate->toDateString(), 'due_date' => $p->dueDate->toDateString(),
            'payment_term_id' => $p->paymentTermId, 'warehouse_id' => $p->warehouse->id, 'quotation_id' => $in['quotation_id'] ?? null,
            'notes' => $in['notes'] ?? null, 'internal_note' => $in['internal_note'] ?? null,
            'subtotal' => $c->subtotal, 'discount_total' => $c->discountTotal, 'tax_total' => $c->taxTotal, 'rounding_adjustment' => $c->rounding,
            'total' => $c->total, 'balance_due' => $c->total,
        ]);
    }

    private function saveLines(SalesInvoice $invoice, PreparedInvoice $p): void
    {
        foreach ($p->lines as $i => $l) {
            $c = $p->calculation->lines[$i]; // tax_rate is stored with the column's 4 decimals
            $invoice->lines()->create([
                'line_no' => $i + 1, 'product_id' => $l['product']->id, 'product_code' => $l['product']->code, 'product_name' => $l['product']->name,
                'unit_name' => $l['product']->unit?->name ?? '', 'description' => $l['input']['description'] ?? null,
                'quantity' => $l['input']['quantity'], 'unit_price' => $l['unit_price'], 'discount_pct' => $l['input']['discount_pct'] ?? '0',
                'discount_amount' => $c->discountTotal(), 'tax_rule_id' => $c->taxRuleId, 'tax_rate' => $l['tax_rate']->toBigDecimal()->toScale(4, RoundingMode::HalfUp)->toString(),
                'tax_amount' => $c->tax, 'net_amount' => $c->net, 'line_total' => $c->total, 'income_ledger_id' => $l['income_ledger_id'],
            ]);
        }
    }

    private function audited(SalesInvoice $inv): array
    {
        $a = [];
        foreach (self::AUDITED as $k) {
            $v = $inv->getAttribute($k);
            $a[$k] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_object($v) ? (string) $v : $v);
        }

        return $a;
    }
}
