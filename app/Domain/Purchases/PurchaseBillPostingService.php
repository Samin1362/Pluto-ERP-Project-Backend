<?php

namespace App\Domain\Purchases;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\TaxRule;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Posts a draft purchase bill (06 §4, §8; BR-001, BR-004, BR-009, BR-017, BR-019, BR-053; backlog P4.3-BE-01).
 * In one transaction: lock the bill (still a draft?), check the period and that supplier, warehouse and products are
 * active, lock the stock rows in product/warehouse order, take each stocked line into stock at its base net amount
 * plus any non-recoverable tax (BR-017), then journal
 *   Dr warehouse inventory (stock lines) · Dr the line's expense ledger (non-stock and services) ·
 *   Dr the tax rule's ledger (recoverable input tax) · Cr supplier (bill total)
 * under the bill's own number, link it, and mark the bill posted. Any failure rolls everything back.
 * A repeat with the same Idempotency-Key replays the response (middleware); with a new key it is already_posted.
 */
final class PurchaseBillPostingService
{
    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AuditLogger $audit,
    ) {}

    public function post(PurchaseBill $bill, bool $confirmBackdated = false): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $confirmBackdated) {
            $bill = PurchaseBill::with('lines')->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status === 'posted') {
                throw new ApiProblem('already_posted', "{$bill->number} is already posted.");
            }
            if ($bill->status !== 'draft') {
                throw new ApiProblem('not_draft', "{$bill->number} is {$bill->status}; only a draft can be posted.");
            }
            $this->periods->assertOpen($bill->bill_date);
            $this->assertNotBackdated($bill, $confirmBackdated);
            [$party, $warehouse, $products] = $this->activeReferences($bill);

            $rate = $bill->exchange_rate->toBigDecimal();
            $base = fn (Money $m) => $bill->currency_code === 'BDT' ? $m : Money::of($m->toBigDecimal()->multipliedBy($rate));
            $rules = TaxRule::whereIn('id', $bill->lines->pluck('tax_rule_id')->filter())->get()->keyBy('id');

            $b = new JournalBuilder;
            $stocked = $bill->lines->filter(fn ($l) => $products[$l->product_id]->track_stock);
            $this->stock->lock($stocked->map(fn ($l) => [$l->product_id, $warehouse->id])->values()->all());
            foreach ($bill->lines as $i => $line) {
                $tax = $base($line->tax_amount);
                $rule = $line->tax_rule_id ? $rules[$line->tax_rule_id] : null;
                $recoverable = $rule && $rule->is_recoverable && $tax->isPositive();
                $cost = $line->base_net_amount->plus($recoverable ? Money::zero() : $tax); // non-recoverable tax is cost (06 §8)
                if ($products[$line->product_id]->track_stock) {
                    $after = $this->stock->stockIn($products[$line->product_id], $warehouse, Quantity::of($line->quantity), $cost, $bill, $bill->bill_date,
                        'purchase_in', $line->id);
                    $b->debit($warehouse->inventory_ledger_id, $cost, ['warehouse_id' => $warehouse->id]);
                    if (! $after->trueUp->isZero()) { // negative stock covered (R-16): re-cost what was issued short
                        $b->debit($warehouse->inventory_ledger_id, $after->trueUp, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('cogs'), $after->trueUp);
                    }
                } else {
                    $b->debit($line->debit_ledger_id, $cost);
                }
                if ($recoverable) {
                    $b->debit($rule->ledger_id, $tax, ['tax_rule_id' => $rule->id]);
                }
            }
            if (! $bill->rounding_adjustment->isZero()) {
                $b->debit($this->mapper->ledgerFor('rounding'), $bill->rounding_adjustment);
            }
            $foreign = $bill->currency_code === 'BDT' ? [] : ['foreign_currency_code' => $bill->currency_code, 'foreign_amount' => $bill->total_foreign,
                'exchange_rate' => (string) $bill->exchange_rate];
            $b->credit($party->ledger_id, $bill->total, $foreign + ['against_reference' => $bill->number]);

            $entry = $this->posting->persist($b, $bill, 'purchase_bill', $bill->bill_date, $bill->number,
                "Bill {$bill->supplier_bill_no} from {$party->name}", $bill->supplier_bill_no, $bill->branch_id);
            $this->stock->linkJournal($bill, $entry->id);
            $bill->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'posted_by' => Auth::id(), 'balance_due' => $bill->total])->save();
            $this->audit->record('posted', $bill, ['status' => 'draft'], ['status' => 'posted', 'journal_entry_id' => $entry->id, 'total' => (string) $bill->total]);

            return $bill->refresh()->load('lines');
        }, attempts: 2); // a MySQL deadlock (1213) is retried once (09 §9); the fixed lock order should make it rare
    }

    /** BR-053: a bill dated before the back-date window needs explicit confirmation. */
    private function assertNotBackdated(PurchaseBill $bill, bool $confirmed): void
    {
        $window = (int) (CompanySetting::value('backdate_warning_days') ?? 0);
        if ($window > 0 && ! $confirmed && $bill->bill_date->lt(CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays($window))) {
            throw new ApiProblem('backdated_confirmation_required', "{$bill->number} is dated {$bill->bill_date->format('j M Y')}, more than {$window} days ago; confirm to post it.",
                errors: ['confirm_backdated' => ['Confirm the back-dated bill']]);
        }
    }

    /** @return array{0: Party, 1: Warehouse, 2: Collection<int, Product>} */
    private function activeReferences(PurchaseBill $bill): array
    {
        $party = Party::findOrFail($bill->party_id);
        $warehouse = Warehouse::findOrFail($bill->warehouse_id);
        $products = Product::withTrashed()->whereIn('id', $bill->lines->pluck('product_id'))->get()->keyBy('id');
        $errors = [];
        if ($party->status !== 'active') {
            $errors['party_id'] = ["{$party->name} is inactive"];
        }
        if ($warehouse->status === 'inactive') {
            $errors['warehouse_id'] = ["{$warehouse->name} is inactive"];
        }
        foreach ($bill->lines->values() as $i => $l) {
            $p = $products[$l->product_id];
            if (! $p->is_active || $p->trashed()) {
                $errors["lines.{$i}.product_id"] = ["{$p->name} is inactive"];
            }
        }
        if ($errors) {
            throw new ApiProblem('inactive_reference', "{$bill->number} uses inactive records; reactivate them or edit the draft.", errors: $errors);
        }

        return [$party, $warehouse, $products];
    }
}
