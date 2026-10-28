<?php

namespace App\Domain\Purchases;

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
use App\Models\PurchaseBill;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a posted bill (06 §15; BR-003, BR-014, BR-024; R-17; backlog P4.3-BE-02). In one transaction:
 *  - every line of the bill's entry is swapped into a cancellation entry (next system journal number, dated the
 *    cancellation date) that reverses it; the original entry becomes `reversed`;
 *  - each stocked line leaves the warehouse as a `reversal` movement at its original value (insufficient_stock when
 *    it was sold meanwhile, unless negative stock is allowed — TC-PUR-010). When R-17 lowers that value, the
 *    difference goes to the stock-adjustment ledger so the inventory ledger keeps matching the stock;
 *  - payments and advances allocated to the bill become advances again (AllocationService::releaseAll; P6.1-BE-04);
 *  - the bill is `cancelled` with its reason; its number is kept.
 */
final class PurchaseBillCancellationService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AuditLogger $audit,
        private readonly AllocationService $allocations,
        private readonly PurchaseOrderReceipts $orders,
    ) {}

    public function cancel(PurchaseBill $bill, string $reason, ?string $note = null, ?string $date = null): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $reason, $note, $date) {
            $bill = PurchaseBill::with('lines')->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status === 'cancelled') {
                throw new ApiProblem('already_reversed', "{$bill->number} is already cancelled.");
            }
            if ($bill->status !== 'posted') {
                throw new ApiProblem('invalid_transition', "{$bill->number} is a draft; delete it instead of cancelling.", extra: ['status' => $bill->status]);
            }
            $date ??= CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $original = JournalEntry::with('lines')->whereKey($bill->journal_entry_id)->lockForUpdate()->firstOrFail();
            $warehouse = Warehouse::findOrFail($bill->warehouse_id);
            $products = Product::withTrashed()->whereIn('id', $bill->lines->pluck('product_id'))->get()->keyBy('id');

            $b = new JournalBuilder;
            foreach ($original->lines as $l) { // swap every side
                $meta = array_filter(['warehouse_id' => $l->warehouse_id, 'tax_rule_id' => $l->tax_rule_id, 'against_reference' => $l->against_reference,
                    'foreign_currency_code' => $l->foreign_currency_code, 'foreign_amount' => $l->foreign_amount, 'exchange_rate' => $l->exchange_rate?->toString()]);
                $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
            }
            $stocked = $bill->lines->filter(fn ($l) => $products[$l->product_id]->track_stock)->values();
            $this->stock->lock($stocked->map(fn ($l) => [$l->product_id, $warehouse->id])->all());
            $receipts = StockMovement::where('source_type', $bill->getMorphClass())->where('source_id', $bill->id)->where('movement_type', 'purchase_in')->get()->keyBy('source_line_id');
            foreach ($bill->lines->values() as $i => $line) {
                if (! $products[$line->product_id]->track_stock) {
                    continue;
                }
                $orig = Money::of($receipts[$line->id]->value_in);
                $out = $this->stock->reverseIn($products[$line->product_id], $warehouse, Quantity::of($line->quantity), $orig, $bill, $date, $line->id, "lines.{$i}.quantity");
                $diff = $orig->minus($out);
                if (! $diff->isZero()) { // R-17: stock gave back less (or more) value than the bill put in
                    $b->debit($warehouse->inventory_ledger_id, $diff, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('stock_adjustment'), $diff);
                }
            }
            $this->allocations->releaseAll($bill); // BR-024: what was paid on it becomes an advance again

            $entry = $this->posting->persist($b, $bill, 'cancellation', $date, null, "Cancellation of {$bill->number} ({$reason})".($note ? ": {$note}" : ''),
                $bill->number, $bill->branch_id, $original->id);
            $original->forceFill(['status' => 'reversed'])->save();
            $this->stock->linkJournal($bill, $entry->id);
            $bill->forceFill(['status' => 'cancelled', 'cancellation_entry_id' => $entry->id, 'cancel_reason' => $reason, 'cancelled_at' => now(),
                'cancelled_by' => Auth::id(), 'balance_due' => Money::zero()])->save();
            $this->audit->record('cancelled', $bill, ['status' => 'posted'], ['status' => 'cancelled', 'reason' => $reason, 'note' => $note, 'cancellation_entry_id' => $entry->id]);
            $this->orders->billCancelled($bill); // the goods no longer count as received on the order

            return $bill->refresh()->load('lines');
        }, attempts: 2); // a MySQL deadlock (1213) is retried once (09 §9); the fixed lock order should make it rare
    }
}
