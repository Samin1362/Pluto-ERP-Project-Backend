<?php

namespace App\Domain\Sales;

use App\Domain\Documents\Calculation;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quotations (E10, E13; backlog P10.1-BE-01; BR-056, BR-006, BR-025). Lines are priced like an invoice's
 * (SalesInvoicePreparer::priceLines) and stored with the product snapshot; nothing posts. The QT number is reserved at
 * first save in the year of the quotation date and voided, never freed, when a draft is deleted.
 *
 * States (06 §2): draft → sent → accepted / declined / expired → converted. Draft and sent quotations can be edited;
 * send works from draft or sent; accepted / declined / expired are set from draft or sent; only an accepted quotation
 * converts, once (TC-SAL-014: a second convert is 409 already_converted). The quote may go to a prospect (party status
 * prospect); converting makes the prospect an active customer (02: "it becomes active on first invoice").
 */
final class QuotationService
{
    private const EDITABLE = ['draft', 'sent'];

    private const AUDITED = ['party_id', 'quotation_date', 'valid_until', 'warehouse_id', 'payment_term_id', 'subtotal', 'discount_total', 'tax_total',
        'rounding_adjustment', 'total', 'notes'];

    public function __construct(
        private readonly SalesInvoicePreparer $preparer,
        private readonly SalesInvoiceService $invoices,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $in): Quotation
    {
        return DB::transaction(function () use ($in) {
            [$party, $lines, $calc] = $this->price($in);
            $q = new Quotation(['status' => 'draft']);
            $q->number = $this->numbers->next('quotation', $this->fiscalYearId($in['quotation_date']))['number'];
            $this->fill($q, $party, $calc, $in);
            $q->save();
            $this->saveLines($q, $lines, $calc);
            $this->audit->record('created', $q, null, $this->audited($q) + ['number' => $q->number]);

            return $q->refresh()->load('lines');
        });
    }

    public function update(Quotation $q, array $in, int $expectedVersion): Quotation
    {
        return DB::transaction(function () use ($q, $in, $expectedVersion) {
            $q = $this->lock($q);
            if (! in_array($q->status, self::EDITABLE, true)) {
                throw new ApiProblem('invalid_transition', "{$q->number} is {$q->status}; only a draft or sent quotation can be changed.", extra: ['status' => $q->status]);
            }
            if ((int) $q->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $q->lock_version]);
            }
            [$party, $lines, $calc] = $this->price($in);
            $this->fiscalYearId($in['quotation_date']);
            $old = $this->audited($q);
            $this->fill($q, $party, $calc, $in);
            $q->lines()->delete(); // lines are replaced as a whole (PUT)
            $q->save();
            $this->saveLines($q, $lines, $calc);
            $this->audit->record('updated', $q, $old, $this->audited($q->refresh()));

            return $q->load('lines');
        });
    }

    /** BR-025: drafts only. The number is voided, not freed (BR-006). */
    public function delete(Quotation $q): void
    {
        DB::transaction(function () use ($q) {
            $q = $this->lock($q);
            if ($q->status !== 'draft') {
                throw new ApiProblem('not_draft', "{$q->number} is {$q->status}; only drafts can be deleted.", extra: ['status' => $q->status]);
            }
            $old = $this->audited($q) + ['number' => $q->number];
            $q->lines()->delete();
            $q->delete();
            $this->audit->record('voided_number', $q, ['number' => $q->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $q, $old);
        });
    }

    /** POST /quotations/{id}/send: draft → sent (sending again keeps it sent); freezes the customer's details for reprints. */
    public function markSent(Quotation $q): Quotation
    {
        return DB::transaction(function () use ($q) {
            $q = $this->lock($q);
            if (! in_array($q->status, self::EDITABLE, true)) {
                throw new ApiProblem('invalid_transition', "{$q->number} is {$q->status}; only a draft or sent quotation can be sent.", extra: ['status' => $q->status]);
            }
            $old = ['status' => $q->status];
            $q->status = 'sent';
            $q->sent_at = now();
            $q->party_snapshot = $this->snapshot($q->party);
            $q->save();
            $this->audit->record('updated', $q, $old, ['status' => 'sent']);

            return $q->load('lines');
        });
    }

    /** POST /quotations/{id}/status: accepted, declined or expired, from draft or sent (StatusChange; closed/cancelled are PO-only). */
    public function setStatus(Quotation $q, string $status, ?string $reason): Quotation
    {
        return DB::transaction(function () use ($q, $status, $reason) {
            $q = $this->lock($q);
            if (! in_array($q->status, self::EDITABLE, true) && $q->status !== $status) {
                throw new ApiProblem('invalid_transition', "{$q->number} is {$q->status}; it cannot be marked {$status}.", extra: ['status' => $q->status]);
            }
            $old = ['status' => $q->status];
            $q->status = $status;
            $q->save();
            $this->audit->record('updated', $q, $old, array_filter(['status' => $status, 'reason' => $reason]));

            return $q->load('lines');
        });
    }

    /**
     * POST /quotations/{id}/convert (BR-056, TC-SAL-014): an accepted quotation becomes a draft invoice dated today with the
     * same lines, prices, discounts and tax rules, in the quotation's warehouse (or the company's first active one). A
     * prospect is activated first. The quotation turns converted and never converts again.
     */
    public function convert(Quotation $q): SalesInvoice
    {
        return DB::transaction(function () use ($q) {
            $q = $this->lock($q);
            if ($q->status === 'converted') {
                $number = SalesInvoice::where('quotation_id', $q->id)->value('number');
                throw new ApiProblem('already_converted', "{$q->number} was already converted".($number ? " to {$number}" : '').'.', extra: ['status' => $q->status]);
            }
            if ($q->status !== 'accepted') {
                throw new ApiProblem('invalid_transition', "{$q->number} is {$q->status}; mark it accepted before converting it to an invoice.", extra: ['status' => $q->status]);
            }
            $q->load('lines', 'party');
            if ($q->party->status === 'prospect') {
                $q->party->status = 'active';
                $q->party->save();
                $this->audit->record('updated', $q->party, ['status' => 'prospect'], ['status' => 'active', 'reason' => "Quotation {$q->number} converted"]);
            }
            $warehouseId = $q->warehouse_id ?? Warehouse::where('status', '!=', 'inactive')->orderBy('id')->value('id');
            $invoice = $this->invoices->createDraft([
                'party_id' => $q->party_id, 'invoice_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(), 'payment_term_id' => $q->payment_term_id,
                'warehouse_id' => $warehouseId, 'quotation_id' => $q->id, 'notes' => $q->notes,
                'lines' => $q->lines->map(fn ($l) => [
                    'product_id' => $l->product_id, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
                    'discount_pct' => (string) $l->discount_pct, 'tax_rule_id' => $l->tax_rule_id,
                ])->all(),
            ]);
            $q->status = 'converted';
            $q->save();
            $this->audit->record('updated', $q, ['status' => 'accepted'], ['status' => 'converted', 'invoice' => $invoice->number]);

            return $invoice;
        });
    }

    /** @return array{0: Party, 1: list<array>, 2: Calculation} */
    private function price(array $in): array
    {
        $party = Party::findOrFail($in['party_id']);
        $errors = [];
        if ($party->status === 'inactive') {
            $errors['party_id'] = ["{$party->name} is inactive; reactivate the customer first"];
        }
        if (isset($in['warehouse_id']) && Warehouse::whereKey($in['warehouse_id'])->value('status') === 'inactive') {
            $errors['warehouse_id'] = ['This warehouse is inactive'];
        }
        [$lines, $calc] = $this->preparer->priceLines($party, $in['lines'], $errors);

        return [$party, $lines, $calc];
    }

    /** The QT series runs per financial year; a quotation date outside every year cannot be numbered. */
    private function fiscalYearId(string $date): int
    {
        $id = FiscalYear::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->value('id');
        if (! $id) {
            throw new ApiProblem('validation_failed', errors: ['quotation_date' => ['No financial year covers this date; create the year in Settings › Financial years first']]);
        }

        return $id;
    }

    private function lock(Quotation $q): Quotation
    {
        return Quotation::whereKey($q->id)->lockForUpdate()->firstOrFail();
    }

    private function snapshot(Party $p): array
    {
        return ['code' => $p->code, 'name' => $p->name, 'mobile' => $p->mobile, 'address' => $p->address];
    }

    private function fill(Quotation $q, Party $party, $calc, array $in): void
    {
        $q->fill([
            'branch_id' => isset($in['warehouse_id']) ? Warehouse::whereKey($in['warehouse_id'])->value('branch_id') : ($q->branch_id ?? DB::table('branches')
                ->where('company_id', $party->company_id)->where('is_active', true)->orderBy('id')->value('id')),
            'party_id' => $party->id, 'party_snapshot' => $this->snapshot($party),
            'quotation_date' => $in['quotation_date'], 'valid_until' => $in['valid_until'],
            'warehouse_id' => $in['warehouse_id'] ?? null, 'payment_term_id' => $in['payment_term_id'] ?? $party->payment_term_id,
            'notes' => $in['notes'] ?? null, 'internal_note' => $in['internal_note'] ?? null,
            'subtotal' => $calc->subtotal, 'discount_total' => $calc->discountTotal, 'tax_total' => $calc->taxTotal, 'rounding_adjustment' => $calc->rounding,
            'total' => $calc->total,
        ]);
    }

    private function saveLines(Quotation $q, array $lines, $calc): void
    {
        foreach ($lines as $i => $l) {
            $c = $calc->lines[$i];
            $q->lines()->create([
                'line_no' => $i + 1, 'product_id' => $l['product']->id, 'product_code' => $l['product']->code, 'product_name' => $l['product']->name,
                'unit_name' => $l['product']->unit?->name ?? '', 'description' => $l['input']['description'] ?? null,
                'quantity' => $l['input']['quantity'], 'unit_price' => $l['unit_price'], 'discount_pct' => $l['input']['discount_pct'] ?? '0',
                'discount_amount' => $c->discountTotal(), 'tax_rule_id' => $c->taxRuleId, 'tax_rate' => $l['tax_rate']->toBigDecimal()->toScale(4, RoundingMode::HalfUp)->toString(),
                'tax_amount' => $c->tax, 'net_amount' => $c->net, 'line_total' => $c->total,
            ]);
        }
    }

    private function audited(Quotation $q): array
    {
        $a = [];
        foreach (self::AUDITED as $k) {
            $v = $q->getAttribute($k);
            $a[$k] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_object($v) ? (string) $v : $v);
        }

        return $a;
    }
}
