<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Documents\CalcLine;
use App\Domain\Documents\CalcOptions;
use App\Domain\Documents\Calculation;
use App\Domain\Documents\DocumentCalculator;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\Rate;
use App\Domain\Shared\Taka;
use App\Domain\Shared\UnitCost;
use App\Models\CompanySetting;
use App\Models\Party;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\TaxRule;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns SalesInvoiceInput into a priced, checked invoice (06 §5; BR-010–BR-015, BR-036, BR-055; backlog P5.1-BE-01).
 * Shared by POST /sales-invoices/calculate (DEC-09: the UI shows only the server's figures) and the draft services, so
 * a saved draft carries exactly what calculate showed.
 *
 * A line without unit_price takes the product's price for the customer's tier (wholesale price for wholesale
 * customers, else the selling price). Tax comes only from a rule on the line (BR-036). The company's invoice rounding
 * applies (BR-012). Refuses (422) an inactive customer, warehouse or product (inactive_reference) and a tax rule that is
 * inactive or only for purchases; never refuses on business state: credit limit, overdue invoices and stock shortfall
 * are warnings (BR-015, 05 §3), and become errors only when posting.
 */
final class SalesInvoicePreparer
{
    public function __construct(private readonly DocumentCalculator $calculator, private readonly AccountMapper $mapper) {}

    public function prepare(array $in): PreparedInvoice
    {
        $party = Party::findOrFail($in['party_id']);
        $warehouse = Warehouse::findOrFail($in['warehouse_id']);
        $errors = [];
        if ($party->status !== 'active') {
            $errors['party_id'] = ["{$party->name} is inactive; reactivate the customer first"];
        }
        if ($warehouse->status === 'inactive') {
            $errors['warehouse_id'] = ["{$warehouse->name} is inactive"];
        }
        $products = Product::with('unit')->whereIn('id', array_column($in['lines'], 'product_id'))->get()->keyBy('id');
        $rules = TaxRule::whereIn('id', array_filter(array_column($in['lines'], 'tax_rule_id')))->get()->keyBy('id');
        foreach ($in['lines'] as $i => $l) {
            if (! $products[$l['product_id']]->is_active) {
                $errors["lines.{$i}.product_id"] = ["{$products[$l['product_id']]->name} is inactive"];
            }
            $rule = isset($l['tax_rule_id']) ? $rules[$l['tax_rule_id']] : null;
            if ($rule && (! $rule->is_active || $rule->applies_to === 'purchases')) {
                $errors["lines.{$i}.tax_rule_id"] = [$rule->is_active ? "{$rule->name} applies to purchases only" : "{$rule->name} is inactive"];
            }
        }
        if ($errors) {
            $inactive = array_filter(array_keys($errors), fn ($k) => ! str_ends_with($k, 'tax_rule_id'));
            throw new ApiProblem($inactive ? 'inactive_reference' : 'validation_failed', errors: $errors);
        }

        $settings = CompanySetting::first();
        $lines = [];
        $calcLines = [];
        foreach ($in['lines'] as $i => $l) {
            $p = $products[$l['product_id']];
            $price = Money::of($l['unit_price'] ?? $this->tierPrice($p, $party));
            $taxRate = isset($l['tax_rule_id']) ? Rate::of($rules[$l['tax_rule_id']]->rate) : Rate::zero();
            $calcLines[] = new CalcLine($i + 1, Quantity::of($l['quantity']), UnitCost::of($price), Rate::of($l['discount_pct'] ?? '0'),
                $l['tax_rule_id'] ?? null, $taxRate, (bool) $p->track_stock);
            $lines[] = ['product' => $p, 'input' => $l, 'stocked' => (bool) $p->track_stock, 'unit_price' => $price, 'tax_rate' => $taxRate,
                'income_ledger_id' => $p->income_ledger_id, 'available' => null];
        }
        $calc = $this->calculator->calculate($calcLines, new CalcOptions(rounding: $settings?->invoice_rounding ?? 'none',
            pricesIncludeTax: (bool) $settings?->prices_include_tax));
        if (! $calc->rounding->isZero()) {
            $this->roundingLedger();
        }

        $invoiceDate = CarbonImmutable::parse($in['invoice_date']);
        $termId = $in['payment_term_id'] ?? $party->payment_term_id;
        $due = isset($in['due_date']) ? CarbonImmutable::parse($in['due_date'])
            : $invoiceDate->addDays((int) ($termId ? PaymentTerm::whereKey($termId)->value('days') : 0)); // BR-055

        $warnings = [];
        $window = (int) ($settings?->backdate_warning_days ?? 0);
        if ($window > 0 && $invoiceDate->lessThan(CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays($window))) {
            $warnings[] = ['code' => 'BACKDATED', 'message' => "The invoice date is more than {$window} days ago; posting will ask you to confirm it (BR-053)."];
        }
        $balance = $this->receivableBalance($party);
        array_push($warnings, ...$this->partyWarnings($party, $balance, $calc));
        [$lines, $stockWarnings] = $this->stock($lines, $warehouse);
        array_push($warnings, ...$stockWarnings);

        return new PreparedInvoice($party, $warehouse, $lines, $calc, $invoiceDate, $due, $termId, $warnings,
            $this->effects($lines, $calc, $warehouse, $party, $due, $balance), $balance);
    }

    /** The price a line takes when none is typed: the wholesale price for a wholesale customer (if the item has one). */
    private function tierPrice(Product $p, Party $party): string
    {
        return $party->price_tier === 'wholesale' && $p->wholesale_price !== null ? (string) $p->wholesale_price : (string) $p->selling_price;
    }

    /** BR-012 rounding needs its ledger mapped (05: calculate rejects missing configuration). */
    private function roundingLedger(): void
    {
        try {
            $this->mapper->ledgerFor('rounding');
        } catch (ApiProblem $e) {
            throw $e->errorCode === 'mapping_missing' ? new ApiProblem('rounding_ledger_missing') : $e;
        }
    }

    /** What the customer owes now: debit − credit of its ledger's posted lines. */
    private function receivableBalance(Party $party): Money
    {
        return Money::of((string) DB::table('journal_lines')->where('ledger_id', $party->ledger_id)->where('is_posted', 1)
            ->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));
    }

    /**
     * BR-015 (non-blocking): invoices past their due date still unpaid, and a balance after this invoice above the
     * customer's credit limit.
     *
     * @return list<array{code: string, message: string}>
     */
    private function partyWarnings(Party $party, Money $balance, Calculation $calc): array
    {
        $out = [];
        $overdue = Money::of((string) DB::table('sales_invoices')->where('company_id', $party->company_id)->where('party_id', $party->id)
            ->where('status', 'posted')->where('balance_due', '>', 0)->where('due_date', '<', CarbonImmutable::now('Asia/Dhaka')->toDateString())
            ->selectRaw('CAST(COALESCE(SUM(balance_due), 0) AS DECIMAL(18,2)) o')->value('o'));
        if ($overdue->isPositive()) {
            $out[] = ['code' => 'PARTY_OVERDUE', 'message' => "{$party->name} has ".Taka::format($overdue).' overdue'];
        }
        $after = $balance->plus($calc->total);
        if ($party->credit_limit !== null && $after->isGreaterThan($party->credit_limit)) {
            $out[] = ['code' => 'CREDIT_LIMIT_EXCEEDED', 'message' => "This invoice takes {$party->name} to ".Taka::format($after)
                .', over the '.Taka::format(Money::of((string) $party->credit_limit)).' credit limit'];
        }

        return $out;
    }

    /**
     * Each stocked line's quantity on hand in the warehouse (before this invoice) and a LOW_STOCK warning on the line
     * where the invoice's running need for that item passes it (TC-SAL-020; posting refuses it, BR-014).
     *
     * @return array{0: list<array>, 1: list<array{code: string, message: string, line_no: int}>}
     */
    private function stock(array $lines, Warehouse $warehouse): array
    {
        $onHand = [];
        $needed = [];
        $warnings = [];
        foreach ($lines as $i => $l) {
            if (! $l['stocked']) {
                continue;
            }
            $p = $l['product'];
            $onHand[$p->id] ??= Quantity::of(StockBalance::where('product_id', $p->id)->where('warehouse_id', $warehouse->id)->value('qty_on_hand') ?? '0');
            $lines[$i]['available'] = (string) $onHand[$p->id];
            $before = $needed[$p->id] ?? Quantity::zero();
            $needed[$p->id] = $before->plus(Quantity::of($l['input']['quantity']));
            if ($needed[$p->id]->isGreaterThan($onHand[$p->id]) && ! $before->isGreaterThan($onHand[$p->id])) {
                $warnings[] = ['code' => 'LOW_STOCK', 'line_no' => $i + 1,
                    'message' => 'Only '.self::qty($onHand[$p->id])." {$p->unit?->name} of {$p->name} at {$warehouse->name}; the invoice needs ".self::qty($needed[$p->id])];
            }
        }

        return [$lines, $warnings];
    }

    /**
     * "When you post" effects (E3 aside): stock out per stocked line, income per ledger, output tax, rounding, and the
     * customer's balance with the due date. Costs are not shown: COGS is taken at the average cost at posting (BR-016).
     *
     * @return list<string>
     */
    private function effects(array $lines, Calculation $calc, Warehouse $warehouse, Party $party, CarbonImmutable $due, Money $balance): array
    {
        $effects = [];
        $income = [];
        $tax = [];
        foreach ($lines as $i => $l) {
            $c = $calc->lines[$i];
            $income[$l['income_ledger_id']] = ($income[$l['income_ledger_id']] ?? Money::zero())->plus($c->net);
            if (! $c->tax->isZero()) {
                $ledgerId = TaxRule::whereKey($c->taxRuleId)->value('ledger_id');
                $tax[$ledgerId] = ($tax[$ledgerId] ?? Money::zero())->plus($c->tax);
            }
            if ($l['stocked']) {
                $effects[] = 'Stock out: '.Quantity::of($l['input']['quantity'])." {$l['product']->unit?->name} of {$l['product']->name} from {$warehouse->name}";
            }
        }
        $ledgers = DB::table('ledgers')->whereIn('id', array_keys($income + $tax))->get(['id', 'code', 'name'])->keyBy('id');
        foreach ($income as $id => $amount) {
            $effects[] = "Income: {$ledgers[$id]->code} {$ledgers[$id]->name} ".Taka::format($amount);
        }
        foreach ($tax as $id => $amount) {
            $effects[] = "Output tax: {$ledgers[$id]->code} {$ledgers[$id]->name} ".Taka::format($amount);
        }
        if (! $calc->rounding->isZero()) {
            $effects[] = 'Rounding: '.Taka::format($calc->rounding);
        }
        $effects[] = "Receivable from {$party->name} rises by ".Taka::format($calc->total).' to '.Taka::format($balance->plus($calc->total)).", due {$due->format('j M Y')}";

        return $effects;
    }

    /** A quantity without trailing zeros, for messages ("18", "2.5"). */
    private static function qty(Quantity $q): string
    {
        $s = (string) $q;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
