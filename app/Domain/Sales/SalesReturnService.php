<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sales returns (E8, E15; 06 §7; BR-021, BR-022, BR-025, BR-006; TC-SAL-010; backlog P10.3-BE-01).
 *
 * A return is made against one posted invoice (same customer, BR-021) and names, per invoice line, the quantity coming
 * back (at most what is still returnable, BR-022) and why. Each line is valued at the invoice's price after discount,
 * with its tax: line_total × returned / invoiced. Its SR number is reserved at first save; drafts can change or be
 * deleted (number voided).
 *
 * Posting (one transaction): restock brings the goods back as sales_return_in movements at the invoice line's cost
 * (BR-017) — Dr warehouse inventory / Cr COGS — while a write-off moves no stock and posts nothing (the cost is already in
 * COGS); the invoice lines' returned_quantity grows; a draft credit note for the return's value is created for E9.
 * Cancelling (R-03) mirrors the stock entry, takes the quantities back off the invoice and cancels the credit note with
 * it, unless the note was applied or refunded (409 note_applied: cancel the note first).
 */
final class SalesReturnService
{
    public const REASONS = ['expired', 'damaged_in_transit', 'leaking', 'wrong_item', 'not_needed'];

    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly CreditNoteService $notes,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $in): SalesReturn
    {
        return DB::transaction(function () use ($in) {
            [$invoice, $lines] = $this->prepare($in);
            $period = $this->periods->assertOpen($in['return_date']);
            $ret = new SalesReturn(['status' => 'draft']);
            $ret->number = $this->numbers->next('sales_return', $period->fiscal_year_id)['number'];
            $this->fill($ret, $invoice, $lines, $in);
            $ret->save();
            $this->saveLines($ret, $lines);
            $this->audit->record('created', $ret, null, ['number' => $ret->number, 'invoice' => $invoice->number, 'total' => (string) $ret->total]);

            return $ret->refresh()->load('lines');
        });
    }

    public function update(SalesReturn $ret, array $in, int $expectedVersion): SalesReturn
    {
        return DB::transaction(function () use ($ret, $in, $expectedVersion) {
            $ret = SalesReturn::whereKey($ret->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($ret);
            if ((int) $ret->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $ret->lock_version]);
            }
            [$invoice, $lines] = $this->prepare($in);
            $this->periods->assertOpen($in['return_date']);
            $old = ['total' => (string) $ret->total, 'stock_action' => $ret->stock_action];
            $this->fill($ret, $invoice, $lines, $in);
            $ret->lines()->delete();
            $ret->save();
            $this->saveLines($ret, $lines);
            $this->audit->record('updated', $ret, $old, ['total' => (string) $ret->total, 'stock_action' => $ret->stock_action]);

            return $ret->load('lines');
        });
    }

    public function delete(SalesReturn $ret): void
    {
        DB::transaction(function () use ($ret) {
            $ret = SalesReturn::whereKey($ret->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($ret);
            $ret->lines()->delete();
            $ret->delete();
            $this->audit->record('voided_number', $ret, ['number' => $ret->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $ret, ['number' => $ret->number, 'total' => (string) $ret->total]);
        });
    }

    public function post(SalesReturn $ret): SalesReturn
    {
        return DB::transaction(function () use ($ret) {
            $ret = SalesReturn::with('lines')->whereKey($ret->id)->lockForUpdate()->firstOrFail();
            if ($ret->status === 'posted') {
                throw new ApiProblem('already_posted', "{$ret->number} is already posted.");
            }
            $this->assertDraft($ret);
            $this->periods->assertOpen($ret->return_date);
            $invoice = SalesInvoice::whereKey($ret->sales_invoice_id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'posted') {
                throw new ApiProblem('validation_failed', errors: ['source_document_id' => ["{$invoice->number} is {$invoice->status}; returns are made against posted invoices"]]);
            }
            $invLines = SalesInvoiceLine::where('sales_invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->assertReturnable($ret->lines->map(fn ($l) => ['source_line_id' => $l->sales_invoice_line_id, 'quantity' => (string) $l->quantity])->all(), $invLines);

            $b = new JournalBuilder;
            if ($ret->stock_action === 'restock') {
                $warehouse = Warehouse::findOrFail($ret->warehouse_id);
                $products = Product::withTrashed()->whereIn('id', $ret->lines->pluck('product_id'))->get()->keyBy('id');
                $stocked = $ret->lines->filter(fn ($l) => $products[$l->product_id]->track_stock);
                $this->stock->lock($stocked->map(fn ($l) => [$l->product_id, $warehouse->id])->values()->all());
                $cogs = $this->mapper->ledgerFor('cogs');
                foreach ($stocked as $l) {
                    $unitCost = $invLines[$l->sales_invoice_line_id]->unit_cost;
                    $value = Money::of($unitCost->toBigDecimal()->multipliedBy($l->quantity->toBigDecimal())->toScale(2, RoundingMode::HalfUp));
                    $after = $this->stock->stockIn($products[$l->product_id], $warehouse, Quantity::of($l->quantity), $value, $ret, $ret->return_date, 'sales_return_in', $l->id);
                    $l->forceFill(['unit_cost' => $unitCost])->save();
                    $b->debit($warehouse->inventory_ledger_id, $value->plus($after->trueUp), ['warehouse_id' => $warehouse->id])->credit($cogs, $value->plus($after->trueUp));
                }
            }
            $entryId = null;
            if ($b->totalDebit()->isPositive()) {
                $entry = $this->posting->persist($b, $ret, 'sales_return', $ret->return_date, $ret->number, "Goods returned by {$ret->party_snapshot['name']} against {$invoice->number}",
                    $invoice->number, $ret->branch_id);
                $this->stock->linkJournal($ret, $entry->id);
                $entryId = $entry->id;
            }
            foreach ($ret->lines as $l) {
                $il = $invLines[$l->sales_invoice_line_id];
                $il->forceFill(['returned_quantity' => Quantity::of($il->returned_quantity)->plus(Quantity::of($l->quantity))])->save();
            }
            $ret->forceFill(['status' => 'posted', 'journal_entry_id' => $entryId, 'posted_at' => now()])->save();
            $note = $this->notes->fromReturn($ret, $invoice);
            $this->audit->record('posted', $ret, ['status' => 'draft'], ['status' => 'posted', 'journal_entry_id' => $entryId, 'credit_note' => $note->number]);

            return $ret->refresh()->load('lines');
        }, attempts: 2);
    }

    public function cancel(SalesReturn $ret, string $reason): SalesReturn
    {
        return DB::transaction(function () use ($ret, $reason) {
            $ret = SalesReturn::with('lines')->whereKey($ret->id)->lockForUpdate()->firstOrFail();
            if ($ret->status === 'cancelled') {
                throw new ApiProblem('already_reversed', "{$ret->number} is already cancelled.");
            }
            if ($ret->status !== 'posted') {
                throw new ApiProblem('invalid_transition', "{$ret->number} is a draft; delete it instead of cancelling.", extra: ['status' => $ret->status]);
            }
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $note = CreditNote::where('sales_return_id', $ret->id)->whereIn('status', ['draft', 'posted'])->lockForUpdate()->first();
            if ($note?->status === 'posted') {
                $this->notes->cancel($note, $reason, "Return {$ret->number} cancelled", refuseApplied: true);
            } elseif ($note) {
                $note->forceFill(['status' => 'cancelled'])->save();
                $this->audit->record('cancelled', $note, ['status' => 'draft'], ['status' => 'cancelled', 'reason' => "Return {$ret->number} cancelled"]);
            }

            $cancellationId = null;
            if ($ret->journal_entry_id) {
                $original = JournalEntry::with('lines')->whereKey($ret->journal_entry_id)->lockForUpdate()->firstOrFail();
                $warehouse = Warehouse::findOrFail($ret->warehouse_id);
                $products = Product::withTrashed()->whereIn('id', $ret->lines->pluck('product_id'))->get()->keyBy('id');
                $b = new JournalBuilder;
                foreach ($original->lines as $l) { // swap every side
                    $meta = array_filter(['warehouse_id' => $l->warehouse_id]);
                    $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
                }
                $receipts = StockMovement::where('source_type', $ret->getMorphClass())->where('source_id', $ret->id)->where('movement_type', 'sales_return_in')->get()->keyBy('source_line_id');
                $stocked = $ret->lines->filter(fn ($l) => isset($receipts[$l->id]))->values();
                $this->stock->lock($stocked->map(fn ($l) => [$l->product_id, $warehouse->id])->all());
                foreach ($stocked as $i => $l) {
                    $orig = Money::of($receipts[$l->id]->value_in);
                    $out = $this->stock->reverseIn($products[$l->product_id], $warehouse, Quantity::of($l->quantity), $orig, $ret, $date, $l->id, "lines.{$i}.quantity");
                    $diff = $orig->minus($out);
                    if (! $diff->isZero()) { // R-17: stock gave back less (or more) value than the return put in
                        $b->debit($warehouse->inventory_ledger_id, $diff, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('stock_adjustment'), $diff);
                    }
                }
                $entry = $this->posting->persist($b, $ret, 'cancellation', $date, null, "Cancellation of {$ret->number} ({$reason})", $ret->number, $ret->branch_id, $original->id);
                $original->forceFill(['status' => 'reversed'])->save();
                $this->stock->linkJournal($ret, $entry->id);
                $cancellationId = $entry->id;
            }
            $invLines = SalesInvoiceLine::whereIn('id', $ret->lines->pluck('sales_invoice_line_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($ret->lines as $l) {
                $il = $invLines[$l->sales_invoice_line_id];
                $left = Quantity::of($il->returned_quantity)->minus(Quantity::of($l->quantity));
                $il->forceFill(['returned_quantity' => $left->isNegative() ? Quantity::zero() : $left])->save();
            }
            $ret->forceFill(['status' => 'cancelled', 'party_snapshot' => array_merge($ret->party_snapshot ?? [], ['cancel_reason' => $reason,
                'cancellation_entry_id' => $cancellationId])])->save();
            $this->audit->record('cancelled', $ret, ['status' => 'posted'], ['status' => 'cancelled', 'reason' => $reason, 'cancellation_entry_id' => $cancellationId]);

            return $ret->refresh()->load('lines');
        }, attempts: 2);
    }

    private function assertDraft(SalesReturn $ret): void
    {
        if ($ret->status !== 'draft') {
            throw new ApiProblem('not_draft', "{$ret->number} is {$ret->status}; only a draft can be changed, posted or deleted.", extra: ['status' => $ret->status]);
        }
    }

    /**
     * The invoice (posted, of a customer) and each returned line priced from its invoice line.
     *
     * @return array{0: SalesInvoice, 1: list<array{invoice_line: SalesInvoiceLine, quantity: Quantity, value: Money, reason: string}>}
     */
    private function prepare(array $in): array
    {
        $invoice = SalesInvoice::findOrFail($in['source_document_id']);
        if ($invoice->status !== 'posted') {
            throw new ApiProblem('validation_failed', errors: ['source_document_id' => ["{$invoice->number} is {$invoice->status}; returns are made against posted invoices"]]);
        }
        if (Warehouse::whereKey($in['warehouse_id'])->value('status') === 'inactive') {
            throw new ApiProblem('inactive_reference', errors: ['warehouse_id' => ['This warehouse is inactive']]);
        }
        if (($in['usage'] ?? null) === 'apply' && isset($in['apply_to_document_id'])) {
            $target = SalesInvoice::find($in['apply_to_document_id']);
            if (! $target || $target->party_id !== $invoice->party_id || $target->status !== 'posted') {
                throw new ApiProblem('foreign_document', errors: ['apply_to_document_id' => ["Choose a posted invoice of {$invoice->party_snapshot['name']}"]]);
            }
        }
        $invLines = SalesInvoiceLine::where('sales_invoice_id', $invoice->id)->get()->keyBy('id');
        $errors = [];
        foreach ($in['lines'] as $i => $l) {
            if (! $invLines->has($l['source_line_id'])) {
                $errors["lines.{$i}.source_line_id"] = ["This line is not on {$invoice->number}"];
            }
        }
        if ($errors) {
            throw new ApiProblem('foreign_document', 'A line does not belong to the invoice being returned.', errors: $errors);
        }
        $this->assertReturnable($in['lines'], $invLines);

        return [$invoice, array_map(function ($l) use ($invLines) {
            $il = $invLines[$l['source_line_id']];
            $qty = Quantity::of($l['quantity']);
            $value = $qty->isEqualTo(Quantity::of($il->quantity)) ? Money::of($il->line_total)
                : Money::of($il->line_total->toBigDecimal()->multipliedBy($qty->toBigDecimal())->dividedBy($il->quantity->toBigDecimal(), 2, RoundingMode::HalfUp));

            return ['invoice_line' => $il, 'quantity' => $qty, 'value' => $value, 'reason' => $l['reason']];
        }, $in['lines'])];
    }

    /** BR-022: per invoice line, what this return takes back may not pass invoiced − already returned. */
    private function assertReturnable(array $lines, $invLines): void
    {
        $asked = [];
        $errors = [];
        foreach ($lines as $i => $l) {
            $il = $invLines[$l['source_line_id']];
            $asked[$il->id] = ($asked[$il->id] ?? Quantity::zero())->plus(Quantity::of($l['quantity']));
            $left = Quantity::of($il->quantity)->minus(Quantity::of($il->returned_quantity));
            if ($asked[$il->id]->isGreaterThan($left)) {
                $errors["lines.{$i}.quantity"] = ["Only {$this->qty($left)} {$il->unit_name} of {$il->product_name} can still be returned"];
            }
        }
        if ($errors) {
            throw new ApiProblem('return_qty_exceeds', errors: $errors);
        }
    }

    private function fill(SalesReturn $ret, SalesInvoice $invoice, array $lines, array $in): void
    {
        $usage = $in['usage'] ?? null;
        $ret->fill([
            'branch_id' => $invoice->branch_id, 'party_id' => $invoice->party_id,
            'party_snapshot' => ($invoice->party_snapshot ?? []) + ['note' => ['usage' => $usage, 'apply_to_document_id' => $usage === 'apply' ? ($in['apply_to_document_id'] ?? $invoice->id) : null]],
            'sales_invoice_id' => $invoice->id, 'return_date' => $in['return_date'], 'warehouse_id' => $in['warehouse_id'], 'stock_action' => $in['stock_action'],
            'notes' => $in['notes'] ?? null, 'total' => Money::sum(array_map(fn ($l) => $l['value'], $lines)),
        ]);
    }

    private function saveLines(SalesReturn $ret, array $lines): void
    {
        foreach ($lines as $i => $l) {
            $ret->lines()->create(['line_no' => $i + 1, 'sales_invoice_line_id' => $l['invoice_line']->id, 'product_id' => $l['invoice_line']->product_id,
                'quantity' => $l['quantity'], 'unit_price' => $l['invoice_line']->unit_price, 'value' => $l['value'], 'reason' => $l['reason']]);
        }
    }

    private function qty(Quantity $q): string
    {
        $s = (string) $q;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
