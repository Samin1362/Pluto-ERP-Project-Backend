<?php

namespace App\Domain\Purchases;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Models\DebitNote;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillLine;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\TaxRule;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Purchase returns (F6, F13; 06 §10; BR-022, BR-057; R-03, R-04; TC-PUR-008, TC-PUR-011; backlog P10.4-BE-01).
 *
 * A return is made against one posted bill and names, per bill line, the quantity going back and why (BR-022: at most
 * what is still returnable). Each line is valued at the cost it came in at, in BDT — the receipt's value per unit for
 * stock, the base net per unit otherwise — plus its share of recoverable input tax.
 *
 * Posting is one entry, which is also the debit note's (R-04, so the supplier is debited once): Dr supplier (total) /
 * Cr warehouse inventory for goods sent back (purchase_return_out at the bill cost; BR-057), Cr COGS for stock already
 * disposed of, Cr purchase_returns_non_stock for non-stock lines, Cr the tax rule's ledger for the tax. The debit note
 * is created posted, applied to the bill when it has the balance for it (or as the return asked), and the bill lines'
 * returned_quantity grows. Cancelling (TC-PUR-011) mirrors the entry, brings the stock back at the same value, releases
 * the note's allocations and cancels the note in the same transaction.
 */
final class PurchaseReturnService
{
    public const REASONS = ['leaking', 'broken', 'short_supply', 'stale_stock', 'torn_packs', 'quality_claim'];

    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AllocationService $allocations,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $in): PurchaseReturn
    {
        return DB::transaction(function () use ($in) {
            [$bill, $lines] = $this->prepare($in);
            $period = $this->periods->assertOpen($in['return_date']);
            $ret = new PurchaseReturn(['status' => 'draft']);
            $ret->number = $this->numbers->next('purchase_return', $period->fiscal_year_id)['number'];
            $this->fill($ret, $bill, $lines, $in);
            $ret->save();
            $this->saveLines($ret, $lines);
            $this->audit->record('created', $ret, null, ['number' => $ret->number, 'bill' => $bill->number, 'total' => (string) $ret->total]);

            return $ret->refresh()->load('lines');
        });
    }

    public function update(PurchaseReturn $ret, array $in, int $expectedVersion): PurchaseReturn
    {
        return DB::transaction(function () use ($ret, $in, $expectedVersion) {
            $ret = PurchaseReturn::whereKey($ret->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($ret);
            if ((int) $ret->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $ret->lock_version]);
            }
            [$bill, $lines] = $this->prepare($in);
            $this->periods->assertOpen($in['return_date']);
            $old = ['total' => (string) $ret->total, 'stock_action' => $ret->stock_action];
            $this->fill($ret, $bill, $lines, $in);
            $ret->lines()->delete();
            $ret->save();
            $this->saveLines($ret, $lines);
            $this->audit->record('updated', $ret, $old, ['total' => (string) $ret->total, 'stock_action' => $ret->stock_action]);

            return $ret->load('lines');
        });
    }

    public function delete(PurchaseReturn $ret): void
    {
        DB::transaction(function () use ($ret) {
            $ret = PurchaseReturn::whereKey($ret->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($ret);
            $ret->lines()->delete();
            $ret->delete();
            $this->audit->record('voided_number', $ret, ['number' => $ret->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $ret, ['number' => $ret->number, 'total' => (string) $ret->total]);
        });
    }

    public function post(PurchaseReturn $ret): PurchaseReturn
    {
        return DB::transaction(function () use ($ret) {
            $ret = PurchaseReturn::with('lines')->whereKey($ret->id)->lockForUpdate()->firstOrFail();
            if ($ret->status === 'posted') {
                throw new ApiProblem('already_posted', "{$ret->number} is already posted.");
            }
            $this->assertDraft($ret);
            $period = $this->periods->assertOpen($ret->return_date);
            $bill = PurchaseBill::whereKey($ret->purchase_bill_id)->lockForUpdate()->firstOrFail();
            if ($bill->status !== 'posted') {
                throw new ApiProblem('validation_failed', errors: ['source_document_id' => ["{$bill->number} is {$bill->status}; returns are made against posted bills"]]);
            }
            $billLines = PurchaseBillLine::where('purchase_bill_id', $bill->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->assertReturnable($ret->lines->map(fn ($l) => ['source_line_id' => $l->purchase_bill_line_id, 'quantity' => (string) $l->quantity])->all(), $billLines);
            $party = Party::findOrFail($ret->party_id);
            $warehouse = Warehouse::findOrFail($ret->warehouse_id);
            $products = Product::withTrashed()->whereIn('id', $ret->lines->pluck('product_id'))->get()->keyBy('id');
            $sendBack = $ret->stock_action === 'send_back';

            $b = (new JournalBuilder)->debit($party->ledger_id, $ret->total, ['against_reference' => $bill->number]);
            if ($sendBack) {
                $this->stock->lock($ret->lines->filter(fn ($l) => $products[$l->product_id]->track_stock)->map(fn ($l) => [$l->product_id, $warehouse->id])->values()->all());
            }
            foreach ($ret->lines->values() as $i => $l) {
                $bl = $billLines[$l->purchase_bill_line_id];
                $tax = $this->taxShare($bl, $bill, Quantity::of($l->quantity));
                $cost = $l->value->minus($tax);
                if (! $products[$l->product_id]->track_stock) {
                    $b->credit($this->mapper->ledgerFor('purchase_returns_non_stock'), $cost);
                } elseif ($sendBack) {
                    $out = $this->stock->reverseIn($products[$l->product_id], $warehouse, Quantity::of($l->quantity), $cost, $ret, $ret->return_date, $l->id,
                        "lines.{$i}.quantity", 'purchase_return_out');
                    $b->credit($warehouse->inventory_ledger_id, $cost, ['warehouse_id' => $warehouse->id]);
                    $diff = $cost->minus($out);
                    if (! $diff->isZero()) { // R-17: the stock gave up less (or more) value than the bill cost
                        $b->debit($warehouse->inventory_ledger_id, $diff, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('stock_adjustment'), $diff);
                    }
                } else {
                    $b->credit($this->mapper->ledgerFor('cogs'), $cost); // already disposed of: the cost sat in COGS
                }
                if ($tax->isPositive()) {
                    $b->credit((int) TaxRule::whereKey($bl->tax_rule_id)->value('ledger_id'), $tax, ['tax_rule_id' => $bl->tax_rule_id]);
                }
                $bl->forceFill(['returned_quantity' => Quantity::of($bl->returned_quantity)->plus(Quantity::of($l->quantity))])->save();
            }
            $entry = $this->posting->persist($b, $ret, 'purchase_return', $ret->return_date, $ret->number, "Goods returned to {$party->name} against {$bill->number}",
                $bill->number, $ret->branch_id);
            $this->stock->linkJournal($ret, $entry->id);
            $ret->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now()])->save();

            // R-04: the debit note is this same entry, created posted.
            $hint = $ret->party_snapshot['note'] ?? [];
            $usage = $hint['usage'] ?? ($bill->balance_due->isLessThan($ret->total) ? 'credit' : 'apply');
            $target = $usage === 'apply' ? (int) ($hint['apply_to_document_id'] ?? $bill->id) : null;
            $note = new DebitNote(['status' => 'posted', 'branch_id' => $ret->branch_id, 'party_id' => $party->id, 'purchase_return_id' => $ret->id,
                'purchase_bill_id' => $bill->id, 'note_date' => $ret->return_date->toDateString(), 'reason' => 'goods_returned', 'amount' => $ret->total, 'usage' => $usage,
                'journal_entry_id' => $entry->id, 'posted_at' => now(),
                'party_snapshot' => ['code' => $party->code, 'name' => $party->name, 'mobile' => $party->mobile, 'address' => $party->address, 'note' => ['apply_to_document_id' => $target]]]);
            $note->number = $this->numbers->next('debit_note', $period->fiscal_year_id)['number'];
            $note->save();
            if ($target) {
                $line = (int) $entry->lines->firstWhere('ledger_id', $party->ledger_id)->id;
                $this->allocations->allocate($line, 'purchase_bill', $target, $ret->total, $ret->return_date->toDateString());
            }
            $this->audit->record('posted', $ret, ['status' => 'draft'], ['status' => 'posted', 'journal_entry_id' => $entry->id, 'debit_note' => $note->number]);
            $this->audit->record('created', $note, null, ['number' => $note->number, 'return' => $ret->number, 'amount' => (string) $note->amount, 'usage' => $usage]);

            return $ret->refresh()->load('lines');
        }, attempts: 2);
    }

    public function cancel(PurchaseReturn $ret, string $reason): PurchaseReturn
    {
        return DB::transaction(function () use ($ret, $reason) {
            $ret = PurchaseReturn::with('lines')->whereKey($ret->id)->lockForUpdate()->firstOrFail();
            if ($ret->status === 'cancelled') {
                throw new ApiProblem('already_reversed', "{$ret->number} is already cancelled.");
            }
            if ($ret->status !== 'posted') {
                throw new ApiProblem('invalid_transition', "{$ret->number} is a draft; delete it instead of cancelling.", extra: ['status' => $ret->status]);
            }
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $original = JournalEntry::with('lines')->whereKey($ret->journal_entry_id)->lockForUpdate()->firstOrFail();
            $party = Party::withTrashed()->findOrFail($ret->party_id);
            $this->allocations->releaseLine((int) $original->lines->firstWhere('ledger_id', $party->ledger_id)->id);

            $b = new JournalBuilder;
            foreach ($original->lines as $l) { // swap every side
                $meta = array_filter(['warehouse_id' => $l->warehouse_id, 'tax_rule_id' => $l->tax_rule_id, 'against_reference' => $l->against_reference]);
                $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
            }
            $issues = StockMovement::where('source_type', $ret->getMorphClass())->where('source_id', $ret->id)->where('movement_type', 'purchase_return_out')->get()->keyBy('source_line_id');
            if ($issues->isNotEmpty()) {
                $warehouse = Warehouse::findOrFail($ret->warehouse_id);
                $products = Product::withTrashed()->whereIn('id', $ret->lines->pluck('product_id'))->get()->keyBy('id');
                $this->stock->lock($ret->lines->filter(fn ($l) => isset($issues[$l->id]))->map(fn ($l) => [$l->product_id, $warehouse->id])->values()->all());
                foreach ($ret->lines as $l) {
                    if (! isset($issues[$l->id])) {
                        continue;
                    }
                    $after = $this->stock->stockIn($products[$l->product_id], $warehouse, Quantity::of($l->quantity), Money::of($issues[$l->id]->value_out), $ret, $date, 'reversal', $l->id);
                    if (! $after->trueUp->isZero()) { // negative stock covered (R-16)
                        $b->debit($warehouse->inventory_ledger_id, $after->trueUp, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('cogs'), $after->trueUp);
                    }
                }
            }
            $entry = $this->posting->persist($b, $ret, 'cancellation', $date, null, "Cancellation of {$ret->number} ({$reason})", $ret->number, $ret->branch_id, $original->id);
            $original->forceFill(['status' => 'reversed'])->save();
            $this->stock->linkJournal($ret, $entry->id);

            $billLines = PurchaseBillLine::whereIn('id', $ret->lines->pluck('purchase_bill_line_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($ret->lines as $l) {
                $bl = $billLines[$l->purchase_bill_line_id];
                $left = Quantity::of($bl->returned_quantity)->minus(Quantity::of($l->quantity));
                $bl->forceFill(['returned_quantity' => $left->isNegative() ? Quantity::zero() : $left])->save();
            }
            $note = DebitNote::where('purchase_return_id', $ret->id)->where('status', 'posted')->lockForUpdate()->first();
            if ($note) {
                $note->forceFill(['status' => 'cancelled', 'party_snapshot' => array_merge($note->party_snapshot ?? [], ['note' => $note->meta() + ['cancel_reason' => $reason,
                    'cancellation_entry_id' => $entry->id]])])->save();
                $this->audit->record('cancelled', $note, ['status' => 'posted'], ['status' => 'cancelled', 'reason' => "Return {$ret->number} cancelled"]);
            }
            $ret->forceFill(['status' => 'cancelled', 'party_snapshot' => array_merge($ret->party_snapshot ?? [], ['cancel_reason' => $reason, 'cancellation_entry_id' => $entry->id])])->save();
            $this->audit->record('cancelled', $ret, ['status' => 'posted'], ['status' => 'cancelled', 'reason' => $reason, 'cancellation_entry_id' => $entry->id]);

            return $ret->refresh()->load('lines');
        }, attempts: 2);
    }

    private function assertDraft(PurchaseReturn $ret): void
    {
        if ($ret->status !== 'draft') {
            throw new ApiProblem('not_draft', "{$ret->number} is {$ret->status}; only a draft can be changed, posted or deleted.", extra: ['status' => $ret->status]);
        }
    }

    /**
     * @return array{0: PurchaseBill, 1: list<array{bill_line: PurchaseBillLine, quantity: Quantity, unit_cost: UnitCost, value: Money, reason: string}>}
     */
    private function prepare(array $in): array
    {
        $bill = PurchaseBill::findOrFail($in['source_document_id']);
        if ($bill->status !== 'posted') {
            throw new ApiProblem('validation_failed', errors: ['source_document_id' => ["{$bill->number} is {$bill->status}; returns are made against posted bills"]]);
        }
        if (Warehouse::whereKey($in['warehouse_id'])->value('status') === 'inactive') {
            throw new ApiProblem('inactive_reference', errors: ['warehouse_id' => ['This warehouse is inactive']]);
        }
        if (($in['usage'] ?? null) === 'apply' && isset($in['apply_to_document_id'])) {
            $target = PurchaseBill::find($in['apply_to_document_id']);
            if (! $target || $target->party_id !== $bill->party_id || $target->status !== 'posted') {
                throw new ApiProblem('foreign_document', errors: ['apply_to_document_id' => ["Choose a posted bill of {$bill->party_snapshot['name']}"]]);
            }
        }
        $billLines = PurchaseBillLine::where('purchase_bill_id', $bill->id)->get()->keyBy('id');
        $errors = [];
        foreach ($in['lines'] as $i => $l) {
            if (! $billLines->has($l['source_line_id'])) {
                $errors["lines.{$i}.source_line_id"] = ["This line is not on {$bill->number}"];
            }
        }
        if ($errors) {
            throw new ApiProblem('foreign_document', 'A line does not belong to the bill being returned.', errors: $errors);
        }
        $this->assertReturnable($in['lines'], $billLines);
        $receipts = StockMovement::where('source_type', $bill->getMorphClass())->where('source_id', $bill->id)->where('movement_type', 'purchase_in')->get()->keyBy('source_line_id');

        return [$bill, array_map(function ($l) use ($billLines, $receipts, $bill) {
            $bl = $billLines[$l['source_line_id']];
            $qty = Quantity::of($l['quantity']);
            $whole = $qty->isEqualTo(Quantity::of($bl->quantity));
            $in = $receipts[$bl->id] ?? null; // the receipt's value per unit (non-recoverable tax and landed cost included)
            $base = $in ? Money::of($in->value_in) : Money::of($bl->base_net_amount);
            $unit = UnitCost::of($base->dividedBy($bl->quantity, UnitCost::SCALE));
            $cost = $whole ? $base : Money::of($base->toBigDecimal()->multipliedBy($qty->toBigDecimal())->dividedBy($bl->quantity->toBigDecimal(), 2, RoundingMode::HalfUp));

            return ['bill_line' => $bl, 'quantity' => $qty, 'unit_cost' => $unit, 'value' => $cost->plus($this->taxShare($bl, $bill, $qty)), 'reason' => $l['reason']];
        }, $in['lines'])];
    }

    /** The returned quantity's share of the line's recoverable input tax, in BDT (non-recoverable tax is already in the cost). */
    private function taxShare(PurchaseBillLine $bl, PurchaseBill $bill, Quantity $qty): Money
    {
        if (! $bl->tax_rule_id || ! $bl->tax_amount->isPositive() || ! TaxRule::whereKey($bl->tax_rule_id)->value('is_recoverable')) {
            return Money::zero();
        }
        $tax = $bl->tax_amount->toBigDecimal()->multipliedBy($bill->exchange_rate->toBigDecimal());

        return Money::of($tax->multipliedBy($qty->toBigDecimal())->dividedBy($bl->quantity->toBigDecimal(), 2, RoundingMode::HalfUp));
    }

    /** BR-022: per bill line, what this return sends back may not pass billed − already returned. */
    private function assertReturnable(array $lines, $billLines): void
    {
        $asked = [];
        $errors = [];
        foreach ($lines as $i => $l) {
            $bl = $billLines[$l['source_line_id']];
            $asked[$bl->id] = ($asked[$bl->id] ?? Quantity::zero())->plus(Quantity::of($l['quantity']));
            $left = Quantity::of($bl->quantity)->minus(Quantity::of($bl->returned_quantity));
            if ($asked[$bl->id]->isGreaterThan($left)) {
                $s = (string) $left;
                $errors["lines.{$i}.quantity"] = ['Only '.(str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s)." {$bl->unit_name} of {$bl->product_name} can still be returned"];
            }
        }
        if ($errors) {
            throw new ApiProblem('return_qty_exceeds', errors: $errors);
        }
    }

    private function fill(PurchaseReturn $ret, PurchaseBill $bill, array $lines, array $in): void
    {
        $usage = $in['usage'] ?? null;
        $ret->fill([
            'branch_id' => $bill->branch_id, 'party_id' => $bill->party_id,
            'party_snapshot' => ($bill->party_snapshot ?? []) + ['note' => ['usage' => $usage, 'apply_to_document_id' => $usage === 'apply' ? ($in['apply_to_document_id'] ?? $bill->id) : null]],
            'purchase_bill_id' => $bill->id, 'return_date' => $in['return_date'], 'warehouse_id' => $in['warehouse_id'], 'stock_action' => $in['stock_action'],
            'notes' => $in['notes'] ?? null, 'total' => Money::sum(array_map(fn ($l) => $l['value'], $lines)),
        ]);
    }

    private function saveLines(PurchaseReturn $ret, array $lines): void
    {
        foreach ($lines as $i => $l) {
            $ret->lines()->create(['line_no' => $i + 1, 'purchase_bill_line_id' => $l['bill_line']->id, 'product_id' => $l['bill_line']->product_id,
                'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost'], 'value' => $l['value'], 'reason' => $l['reason']]);
        }
    }
}
