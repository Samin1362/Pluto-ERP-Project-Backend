<?php

namespace App\Domain\Purchases;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Documents\CalcLine;
use App\Domain\Documents\CalcOptions;
use App\Domain\Documents\DocumentCalculator;
use App\Domain\Documents\LineUnits;
use App\Domain\Inventory\AverageCostCalculator;
use App\Domain\Lc\LcPostingService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\Rate;
use App\Domain\Shared\Taka;
use App\Domain\Shared\UnitCost;
use App\Models\CompanySetting;
use App\Models\LetterOfCredit;
use App\Models\Party;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockBalance;
use App\Models\TaxRule;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns PurchaseBillInput into a priced, checked bill (06 §8; BR-010, BR-011, BR-036, BR-053, BR-055; backlog
 * P4.2-BE-01). Shared by POST /purchase-bills/calculate (DEC-09: the UI shows only the server's figures) and by the
 * draft services, so a saved draft carries exactly what calculate showed.
 *
 * Refuses (422) an inactive supplier, warehouse or product (inactive_reference), a tax rule that is inactive or only
 * for sales, and a foreign currency without an exchange rate. Each line's debit ledger follows 06 §8: the warehouse's
 * inventory ledger for stock; for non-stock lines the product's expense ledger, else the supplier's default purchase
 * ledger, else purchases_non_stock. Bills keep the supplier's own figures, so no company rounding is applied.
 */
final class PurchaseBillPreparer
{
    public function __construct(
        private readonly DocumentCalculator $calculator,
        private readonly AccountMapper $mapper,
        private readonly AverageCostCalculator $avg,
    ) {}

    public function prepare(array $in): PreparedBill
    {
        $party = Party::findOrFail($in['party_id']);
        $warehouse = Warehouse::findOrFail($in['warehouse_id']);
        $this->assertOrderLinks($in, $party);
        $errors = [];
        if ($party->status !== 'active') {
            $errors['party_id'] = ["{$party->name} is inactive; reactivate the supplier first"];
        }
        if ($warehouse->status === 'inactive') {
            $errors['warehouse_id'] = ["{$warehouse->name} is inactive"];
        }
        $products = Product::with(['unit', 'units.unit'])->whereIn('id', array_column($in['lines'], 'product_id'))->get()->keyBy('id');
        $rules = TaxRule::whereIn('id', array_filter(array_column($in['lines'], 'tax_rule_id')))->get()->keyBy('id');
        foreach ($in['lines'] as $i => $l) {
            if (! $products[$l['product_id']]->is_active) {
                $errors["lines.{$i}.product_id"] = ["{$products[$l['product_id']]->name} is inactive"];
            }
            $rule = isset($l['tax_rule_id']) ? $rules[$l['tax_rule_id']] : null;
            if ($rule && (! $rule->is_active || $rule->applies_to === 'sales')) {
                $errors["lines.{$i}.tax_rule_id"] = [$rule->is_active ? "{$rule->name} applies to sales only" : "{$rule->name} is inactive"];
            }
        }
        $units = LineUnits::resolve($products, $in['lines'], $errors); // BR-069
        // A line received against an order line is in the order line's unit, so received quantities add up (BR-069).
        $orderUnits = PurchaseOrderLine::whereIn('id', array_filter(array_column($in['lines'], 'purchase_order_line_id')))->get(['id', 'unit_id', 'unit_name'])->keyBy('id');
        foreach ($in['lines'] as $i => $l) {
            $ol = empty($l['purchase_order_line_id']) ? null : ($orderUnits[$l['purchase_order_line_id']] ?? null);
            $orderUnit = $ol ? (int) ($ol->unit_id ?? $products[$l['product_id']]->unit_id) : null;
            if ($ol && ! isset($errors["lines.{$i}.unit_id"]) && $orderUnit !== $units[$i]['unit_id']) {
                $errors["lines.{$i}.unit_id"] = ["The order line is in {$ol->unit_name}; receive it in {$ol->unit_name}"];
            }
        }
        if ($errors) {
            $inactive = array_filter(array_keys($errors), fn ($k) => ! str_ends_with($k, 'tax_rule_id') && ! str_ends_with($k, 'unit_id'));
            throw new ApiProblem($inactive ? 'inactive_reference' : 'validation_failed', errors: $errors);
        }

        [$currency, $rate] = $this->currency($in, $party);
        $settings = CompanySetting::first();
        $lines = [];
        $calcLines = [];
        foreach ($in['lines'] as $i => $l) {
            $p = $products[$l['product_id']];
            $taxRate = isset($l['tax_rule_id']) ? Rate::of($rules[$l['tax_rule_id']]->rate) : Rate::zero();
            $calcLines[] = new CalcLine($i + 1, Quantity::of($l['quantity']), UnitCost::of($l['unit_cost']), Rate::of($l['discount_pct'] ?? '0'),
                $l['tax_rule_id'] ?? null, $taxRate, $p->track_stock);
            $lines[] = ['product' => $p, 'input' => $l, 'unit' => $units[$i], 'stocked' => (bool) $p->track_stock, 'tax_rate' => $taxRate,
                'debit_ledger_id' => $p->track_stock ? $warehouse->inventory_ledger_id
                    : ($p->expense_ledger_id ?? $party->default_purchase_ledger_id ?? $this->mapper->ledgerFor('purchases_non_stock')->id),
                'available' => null];
        }
        $calc = $this->calculator->calculate($calcLines, new CalcOptions(rounding: 'none', pricesIncludeTax: (bool) $settings?->prices_include_tax,
            currency: $currency, exchangeRate: $currency === 'BDT' ? null : $rate));

        $billDate = CarbonImmutable::parse($in['bill_date']);
        $termId = $in['payment_term_id'] ?? $party->payment_term_id;
        $due = isset($in['due_date']) ? CarbonImmutable::parse($in['due_date'])
            : $billDate->addDays((int) ($termId ? PaymentTerm::whereKey($termId)->value('days') : 0)); // BR-055

        $warnings = [];
        $window = (int) ($settings?->backdate_warning_days ?? 0);
        if ($window > 0 && $billDate->lessThan(CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays($window))) {
            $warnings[] = ['code' => 'BACKDATED', 'message' => "The bill date is more than {$window} days ago; posting will ask you to confirm it (BR-053)."];
        }

        $balance = $this->payableBalance($party);
        [$lines, $effects] = $this->effects($lines, $calc, $warehouse, $party, $due, $balance);

        return new PreparedBill($party, $warehouse, $lines, $calc, $currency, $rate, $billDate, $due, $termId, $warnings, $effects, $balance);
    }

    /**
     * A bill made from a purchase order (P10.2-BE-01) must be the same supplier's, against an order still expecting
     * goods, and each linked line must be a line of that order for the same item (422 validation_failed).
     */
    private function assertOrderLinks(array $in, Party $party): void
    {
        $errors = [];
        $po = isset($in['purchase_order_id']) ? PurchaseOrder::find($in['purchase_order_id']) : null;
        if ($po && $po->party_id !== $party->id) {
            $errors['purchase_order_id'] = ["{$po->number} is another supplier's order"];
        } elseif ($po && in_array($po->status, ['closed', 'cancelled'], true)) {
            $errors['purchase_order_id'] = ["{$po->number} is {$po->status}"];
        }
        $orderLines = $po ? PurchaseOrderLine::where('purchase_order_id', $po->id)->pluck('product_id', 'id') : collect();
        foreach ($in['lines'] as $i => $l) {
            if (empty($l['purchase_order_line_id'])) {
                continue;
            }
            if (! $po || ! $orderLines->has($l['purchase_order_line_id'])) {
                $errors["lines.{$i}.purchase_order_line_id"] = ['This line is not on the chosen purchase order'];
            } elseif ((int) $orderLines[$l['purchase_order_line_id']] !== (int) $l['product_id']) {
                $errors["lines.{$i}.purchase_order_line_id"] = ['The order line is for a different item'];
            }
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }
    }

    /** @return array{0: string, 1: Rate} the bill currency (default: the supplier's) and its rate to BDT */
    private function currency(array $in, Party $party): array
    {
        // Import mode (F3; 06 §12.5; P16.1-BE-07): an LC bill is in the LC's currency at the rate of the LC's payments
        // (weighted by foreign amount), or the planning rate while nothing is paid; the user may enter another rate.
        if (! empty($in['letter_of_credit_id'])) {
            $lc = LetterOfCredit::find($in['letter_of_credit_id']);
            $errors = [];
            if (! $lc || $lc->party_id !== $party->id) {
                $errors['letter_of_credit_id'] = ['This LC is for another supplier'];
            } elseif (in_array($lc->status, ['draft', 'awaiting_bank', 'cancelled'], true)) {
                $errors['letter_of_credit_id'] = ["{$lc->lc_number} is not opened yet"];
            }
            if ($lc && ! empty($in['lc_shipment_id']) && ! DB::table('lc_shipments')->where('id', $in['lc_shipment_id'])->where('letter_of_credit_id', $lc->id)->exists()) {
                $errors['lc_shipment_id'] = ['This lot belongs to another LC'];
            }
            if ($errors) {
                throw new ApiProblem('validation_failed', errors: $errors);
            }
            $in['currency_code'] ??= $lc->currency_code;
            if (($in['currency_code'] ?? 'BDT') !== 'BDT' && empty($in['exchange_rate'])) {
                $in['exchange_rate'] = LcPostingService::billRate($lc);
            }
        }
        $currency = $in['currency_code'] ?? ($party->default_currency_code ?: 'BDT');
        if ($currency === 'BDT') {
            if (isset($in['exchange_rate']) && ! Rate::of($in['exchange_rate'])->isEqualTo(1)) {
                throw new ApiProblem('validation_failed', errors: ['exchange_rate' => ['A taka bill has exchange rate 1']]);
            }

            return ['BDT', Rate::of(1)];
        }
        if (! isset($in['exchange_rate']) || ! Rate::of($in['exchange_rate'])->isPositive()) {
            throw new ApiProblem('validation_failed', errors: ['exchange_rate' => ["Enter the {$currency} to BDT rate (more than 0)"]]);
        }

        return [$currency, Rate::of($in['exchange_rate'])];
    }

    /** What the supplier is owed now: credit − debit of its ledger's posted lines. */
    private function payableBalance(Party $party): Money
    {
        return Money::of((string) DB::table('journal_lines')->where('company_id', $party->company_id)->where('ledger_id', $party->ledger_id)->where('is_posted', 1)
            ->selectRaw('CAST(COALESCE(SUM(credit) - SUM(debit), 0) AS DECIMAL(18,2)) b')->value('b'));
    }

    /**
     * "When you save" effects (F3 aside) and each stocked line's quantity on hand: the stock that goes in with the
     * average cost it leads to (BR-017, previewed per line in order), non-stock lines' expense, the supplier's credit.
     *
     * @return array{0: list<array>, 1: list<string>}
     */
    private function effects(array $lines, $calc, Warehouse $warehouse, Party $party, CarbonImmutable $due, Money $balance): array
    {
        $effects = [];
        $positions = [];
        $onHand = [];
        $expenses = [];
        foreach ($lines as $i => $l) {
            $c = $calc->lines[$i];
            if (! $l['stocked']) {
                $expenses[$l['debit_ledger_id']] = ($expenses[$l['debit_ledger_id']] ?? Money::zero())->plus($c->baseNet);

                continue;
            }
            $p = $l['product'];
            if (! isset($positions[$p->id])) {
                $b = StockBalance::where('product_id', $p->id)->where('warehouse_id', $warehouse->id)->first();
                $positions[$p->id] = [Quantity::of($b->qty_on_hand ?? '0'), Money::of($b->total_value ?? '0'), UnitCost::of($b->avg_unit_cost ?? '0')];
                $onHand[$p->id] = (string) $positions[$p->id][0];
            }
            $lines[$i]['available'] = $onHand[$p->id]; // on hand before this bill
            [$q, $v, $a] = $positions[$p->id];
            $qty = LineUnits::baseQuantity($l['input']['quantity'], $l['unit']['factor']); // stock and average cost in base units (BR-069)
            $after = $this->avg->afterIn($q, $v, $a, $qty, $c->baseNet->plus($this->nonRecoverableTax($l, $c)));
            $positions[$p->id] = [$after->qty, $after->value, $after->avg];
            $inUnit = $l['unit']['factor']->isEqualTo(1) ? '' : ' ('.Quantity::of($l['input']['quantity'])." {$l['unit']['name']})";
            $effects[] = "Stock in: {$qty} {$p->unit?->name}{$inUnit} of {$p->name} into {$warehouse->name}; average cost becomes {$after->avg}";
        }
        foreach ($expenses as $ledgerId => $amount) {
            $ledger = DB::table('ledgers')->where('id', $ledgerId)->first(['code', 'name']);
            $effects[] = "Expense: {$ledger->code} {$ledger->name} ".Taka::format($amount);
        }
        $total = $calc->baseTotal;
        $effects[] = "Payable to {$party->name} rises by ".Taka::format($total).' to '.Taka::format($balance->plus($total)).", due {$due->format('j M Y')}";

        return [$lines, $effects];
    }

    /** Input tax on a rule that is not recoverable is part of the cost (06 §8). */
    private function nonRecoverableTax(array $line, $calcLine): Money
    {
        if ($calcLine->tax->isZero() || ! isset($line['input']['tax_rule_id'])) {
            return Money::zero();
        }

        return TaxRule::whereKey($line['input']['tax_rule_id'])->value('is_recoverable') ? Money::zero() : $calcLine->baseTax;
    }
}
