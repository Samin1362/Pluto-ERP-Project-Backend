<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a posted sales invoice (06 §15; BR-003, BR-004, BR-024; backlog P5.1-BE-04). In one transaction:
 *  - every line of the invoice's entry is swapped into a cancellation entry (next system journal number, dated the
 *    cancellation date) that reverses it, COGS included; the original entry becomes `reversed`;
 *  - each stocked line comes back into the warehouse as a `reversal` movement at the cost it left at (the line's
 *    cost_amount). When that covers negative stock (R-16) the true-up goes Dr inventory / Cr COGS, as for a receipt;
 *  - receipts allocated to the invoice become advances again (AllocationService::releaseAll; P6.1-BE-04);
 *  - the invoice is `cancelled` with its reason; its number is kept.
 */
final class SalesInvoiceCancellationService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AuditLogger $audit,
        private readonly AllocationService $allocations,
    ) {}

    public function cancel(SalesInvoice $invoice, string $reason, ?string $note = null, ?string $date = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $note, $date) {
            $invoice = SalesInvoice::with('lines')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'cancelled') {
                throw new ApiProblem('already_reversed', "{$invoice->number} is already cancelled.");
            }
            if ($invoice->status !== 'posted') {
                throw new ApiProblem('invalid_transition', "{$invoice->number} is a draft; delete it instead of cancelling.", extra: ['status' => $invoice->status]);
            }
            $date ??= CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $original = JournalEntry::with('lines')->whereKey($invoice->journal_entry_id)->lockForUpdate()->firstOrFail();
            $warehouse = Warehouse::findOrFail($invoice->warehouse_id);
            $products = Product::withTrashed()->whereIn('id', $invoice->lines->pluck('product_id'))->get()->keyBy('id');

            $b = new JournalBuilder;
            foreach ($original->lines as $l) { // swap every side
                $meta = array_filter(['warehouse_id' => $l->warehouse_id, 'tax_rule_id' => $l->tax_rule_id, 'against_reference' => $l->against_reference]);
                $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
            }
            $stocked = $invoice->lines->filter(fn ($l) => $products[$l->product_id]->track_stock);
            $this->stock->lock($stocked->map(fn ($l) => [$l->product_id, $warehouse->id])->values()->all());
            $issues = StockMovement::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->where('movement_type', 'sale_out')->get()->keyBy('source_line_id');
            foreach ($stocked as $line) {
                $after = $this->stock->stockIn($products[$line->product_id], $warehouse, Quantity::of($line->quantity), Money::of($issues[$line->id]->value_out),
                    $invoice, $date, 'reversal', $line->id);
                if (! $after->trueUp->isZero()) { // negative stock covered (R-16): re-cost what was issued short
                    $b->debit($warehouse->inventory_ledger_id, $after->trueUp, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('cogs'), $after->trueUp);
                }
            }
            $this->allocations->releaseAll($invoice); // BR-024: what was paid on it becomes an advance again

            $entry = $this->posting->persist($b, $invoice, 'cancellation', $date, null, "Cancellation of {$invoice->number} ({$reason})".($note ? ": {$note}" : ''),
                $invoice->number, $invoice->branch_id, $original->id);
            $original->forceFill(['status' => 'reversed'])->save();
            $this->stock->linkJournal($invoice, $entry->id);
            $invoice->forceFill(['status' => 'cancelled', 'cancellation_entry_id' => $entry->id, 'cancel_reason' => $reason, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'balance_due' => Money::zero()])->save();
            $this->audit->record('cancelled', $invoice, ['status' => 'posted'], ['status' => 'cancelled', 'reason' => $reason, 'note' => $note, 'cancellation_entry_id' => $entry->id]);

            return $invoice->refresh()->load('lines');
        }, attempts: 2); // a MySQL deadlock (1213) is retried once (09 §9); the fixed lock order should make it rare
    }
}
