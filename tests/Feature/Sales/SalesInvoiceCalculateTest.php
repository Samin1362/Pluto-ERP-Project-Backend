<?php

namespace Tests\Feature\Sales;

use App\Models\AccountMapping;
use App\Models\CompanySetting;
use App\Models\Ledger;
use App\Models\Party;
use App\Models\Product;
use App\Models\TaxRule;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST /sales-invoices/calculate (E3, DEC-09; 06 §5; BR-010–BR-015, BR-036, BR-055; backlog P5.1-BE-01). */
class SalesInvoiceCalculateTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
    }

    private function calc(array $over = [], ?string $token = null)
    {
        $r = $this->api('POST', '/api/v1/sales-invoices/calculate', $token ?? $this->token, $this->books->id, $over + [
            'party_id' => $this->karim->id, 'invoice_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '10']],
        ]);
        $this->useBooks();

        return $r;
    }

    private function stock(Product $p, string $qty): void
    {
        $this->useBooks();
        DB::table('stock_balances')->insert(['company_id' => $this->books->id, 'product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'qty_on_hand' => $qty,
            'total_value' => '0', 'avg_unit_cost' => '0', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A posted, unpaid invoice of Karim's due `daysAgo` days ago (the posting service arrives in P5.1-BE-03). */
    private function postedInvoice(string $balanceDue, int $daysAgo): void
    {
        $this->useBooks();
        $date = CarbonImmutable::now('Asia/Dhaka')->subDays($daysAgo + 15)->toDateString();
        DB::table('sales_invoices')->insert(['company_id' => $this->books->id, 'branch_id' => $this->wh->branch_id, 'number' => 'INV-2026-'.random_int(1000, 9999),
            'party_id' => $this->karim->id, 'invoice_date' => $date, 'due_date' => CarbonImmutable::now('Asia/Dhaka')->subDays($daysAgo)->toDateString(),
            'warehouse_id' => $this->wh->id, 'total' => $balanceDue, 'balance_due' => $balanceDue, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_tc_sal_002_the_e3_sample_totals_and_words(): void
    {
        // 06 §5 worked example (INV-2026-0587, Karim Distributors): five stocked lines and a delivery service.
        $rice = $this->product('Miniket rice 25kg', 'bag', '2340.00');
        $tea = $this->product('Ispahani tea 400g', 'pcs', '255.00');
        $soap = $this->product('Lux soap 150g', 'pcs', '220.00');
        $lentils = $this->product('Red lentils', 'kg', '125.00');
        $delivery = $this->product('Delivery trip', 'pcs', '150.00', 'service');
        foreach ([[$this->oil, '60'], [$rice, '40'], [$tea, '500'], [$soap, '300'], [$lentils, '80']] as [$p, $q]) {
            $this->stock($p, $q);
        }
        $this->postedInvoice('212800.00', 5);
        $r = $this->calc(['lines' => [
            ['product_id' => $this->oil->id, 'quantity' => '40'], ['product_id' => $rice->id, 'quantity' => '12'], ['product_id' => $tea->id, 'quantity' => '120'],
            ['product_id' => $soap->id, 'quantity' => '96'], ['product_id' => $lentils->id, 'quantity' => '6'], ['product_id' => $delivery->id, 'quantity' => '2'],
        ]])->assertOk();

        $this->assertSame(['37600.00', '28080.00', '30600.00', '21120.00', '750.00', '300.00'], array_column($r->json('data.lines'), 'line_total'));
        $r->assertJsonPath('data.lines.0.gross', '37600.00')->assertJsonPath('data.lines.0.available_qty', '60.000')->assertJsonPath('data.lines.5.available_qty', null);
        $r->assertJsonPath('data.subtotal', '118450.00')->assertJsonPath('data.discount_total', '0.00')->assertJsonPath('data.tax_total', '0.00')
            ->assertJsonPath('data.rounding_adjustment', '0.00')->assertJsonPath('data.total', '118450.00')
            ->assertJsonPath('data.amount_in_words', 'Taka one lakh eighteen thousand four hundred fifty only')
            ->assertJsonPath('data.party_balance_before', '0.00')->assertJsonPath('data.party_balance_after', '118450.00');
        $this->assertSame(['PARTY_OVERDUE'], array_column($r->json('data.warnings'), 'code'));
        $r->assertJsonPath('data.warnings.0.message', 'Karim Distributors has ৳ 2,12,800.00 overdue');

        $effects = $r->json('data.effects');
        $this->assertSame('Stock out: 40.000 jar of Soybean oil 5L jar from Tejgaon warehouse', $effects[0]);
        $this->assertContains('Income: 4101 '.Ledger::where('code', '4101')->value('name').' ৳ 1,18,150.00', $effects);
        $this->assertContains('Income: 4201 '.Ledger::where('code', '4201')->value('name').' ৳ 300.00', $effects);
        $this->assertStringStartsWith('Receivable from Karim Distributors rises by ৳ 1,18,450.00 to ৳ 1,18,450.00, due', end($effects));
        $this->assertSame(0, DB::table('sales_invoices')->where('status', 'draft')->count(), 'calculate saves nothing');
    }

    public function test_br_011_discount_tax_and_company_rounding(): void
    {
        $vat = TaxRule::create(['name' => 'VAT 7.5%', 'rate' => '7.5', 'applies_to' => 'sales', 'is_recoverable' => true, 'is_active' => true,
            'ledger_id' => Ledger::where('code', '2201')->value('id') ?? Ledger::where('code', '1402')->value('id')]);
        // 05 §5.2 line 2: 10 × 2,340 less 2 % = 22,932.00; 7.5 % VAT = 1,719.90; total 24,651.90 → nearest taka 24,652.00.
        $r = $this->calc(['lines' => [['product_id' => $this->oil->id, 'quantity' => '10', 'unit_price' => '2340.00', 'discount_pct' => '2.0000', 'tax_rule_id' => $vat->id]]])->assertOk();
        $r->assertJsonPath('data.lines.0.gross', '23400.00')->assertJsonPath('data.lines.0.discount_amount', '468.00')
            ->assertJsonPath('data.lines.0.tax_amount', '1719.90')->assertJsonPath('data.lines.0.line_total', '24651.90')
            ->assertJsonPath('data.rounding_adjustment', '0.10')->assertJsonPath('data.total', '24652.00');
        $this->assertContains('Rounding: ৳ 0.10', $r->json('data.effects'));

        CompanySetting::query()->update(['invoice_rounding' => 'none']);
        $this->calc(['lines' => [['product_id' => $this->oil->id, 'quantity' => '10', 'unit_price' => '2340.00', 'discount_pct' => '2', 'tax_rule_id' => $vat->id]]])
            ->assertJsonPath('data.rounding_adjustment', '0.00')->assertJsonPath('data.total', '24651.90');
    }

    public function test_rounding_needs_its_ledger_mapped(): void
    {
        AccountMapping::where('mapping_key', 'rounding')->delete(); // before any request: the mapper caches per request
        $this->assertProblem($this->calc(['lines' => [['product_id' => $this->oil->id, 'quantity' => '1', 'unit_price' => '0.40']]]), 422, 'rounding_ledger_missing');
        $this->calc(['lines' => [['product_id' => $this->oil->id, 'quantity' => '1', 'unit_price' => '1.00']]])->assertOk(); // nothing to round: no ledger needed
    }

    public function test_price_tier_defaults_and_typed_prices(): void
    {
        $this->oil->update(['wholesale_price' => '900.00']);
        $this->calc()->assertJsonPath('data.lines.0.gross', '9400.00');
        $this->karim->update(['price_tier' => 'wholesale']);
        $this->calc()->assertJsonPath('data.lines.0.gross', '9000.00');
        $this->calc(['lines' => [['product_id' => $this->oil->id, 'quantity' => '10', 'unit_price' => '955.50']]])->assertJsonPath('data.lines.0.gross', '9555.00');
        $this->oil->update(['wholesale_price' => null]);
        $this->calc()->assertJsonPath('data.lines.0.gross', '9400.00'); // no wholesale price: the selling price
    }

    public function test_tc_sal_006_credit_limit_exceeded_is_a_warning(): void
    {
        $this->karim->update(['credit_limit' => '5000.00']);
        $r = $this->calc()->assertOk();
        $r->assertJsonPath('data.credit_limit', '5000.00')->assertJsonPath('data.warnings.0.code', 'CREDIT_LIMIT_EXCEEDED')
            ->assertJsonPath('data.warnings.0.message', 'This invoice takes Karim Distributors to ৳ 9,400.00, over the ৳ 5,000.00 credit limit');
        $this->karim->update(['credit_limit' => '9400.00']);
        $this->assertNotContains('CREDIT_LIMIT_EXCEEDED', array_column($this->calc()->json('data.warnings'), 'code'), 'at the limit is fine');
    }

    public function test_tc_sal_020_stock_shortfall_per_line_is_a_warning(): void
    {
        $this->stock($this->oil, '18');
        $r = $this->calc(['lines' => [['product_id' => $this->oil->id, 'quantity' => '12'], ['product_id' => $this->oil->id, 'quantity' => '12']]])->assertOk();
        $r->assertJsonPath('data.lines.0.available_qty', '18.000')->assertJsonPath('data.lines.1.available_qty', '18.000');
        $this->assertSame([['code' => 'LOW_STOCK', 'line_no' => 2, 'message' => 'Only 18 jar of Soybean oil 5L jar at Tejgaon warehouse; the invoice needs 24']],
            $r->json('data.warnings'), 'warned once, on the line that runs out');
        $this->calc(['lines' => [['product_id' => $this->oil->id, 'quantity' => '18']]])->assertJsonPath('data.warnings', []);
    }

    public function test_due_date_defaults_from_the_payment_term_and_backdating_warns(): void
    {
        $net30 = DB::table('payment_terms')->where('company_id', $this->books->id)->where('name', 'Net 30')->value('id');
        CompanySetting::query()->update(['backdate_warning_days' => 7]);
        $date = CarbonImmutable::now('Asia/Dhaka')->subDays(10);
        $r = $this->calc(['invoice_date' => $date->toDateString(), 'payment_term_id' => $net30])->assertOk();
        $r->assertJsonPath('data.warnings.0.code', 'BACKDATED');
        $effects = $r->json('data.effects');
        $this->assertStringEndsWith('due '.$date->addDays(30)->format('j M Y'), end($effects));
        $effects = $this->calc(['due_date' => '2099-01-31'])->json('data.effects');
        $this->assertStringEndsWith('due 31 Jan 2099', end($effects));
    }

    public function test_inactive_references_and_purchase_only_tax_rules_are_refused(): void
    {
        $this->karim->update(['status' => 'inactive']);
        $this->assertProblem($this->calc(), 422, 'inactive_reference')->assertJsonPath('errors.party_id.0', 'Karim Distributors is inactive; reactivate the customer first');
        $this->karim->update(['status' => 'active']);
        $this->oil->update(['is_active' => false]);
        $this->assertProblem($this->calc(), 422, 'inactive_reference')->assertJsonPath('errors', ['lines.0.product_id' => ['Soybean oil 5L jar is inactive']]);
        $this->oil->update(['is_active' => true]);
        $input = TaxRule::create(['name' => 'Input VAT 5%', 'rate' => '5', 'applies_to' => 'purchases', 'is_recoverable' => true, 'is_active' => true,
            'ledger_id' => Ledger::where('code', '1402')->value('id')]);
        $this->assertProblem($this->calc(['lines' => [['product_id' => $this->oil->id, 'quantity' => '1', 'tax_rule_id' => $input->id]]]), 422, 'validation_failed')
            ->assertJsonPath('errors', ['lines.0.tax_rule_id' => ['Input VAT 5% applies to purchases only']]);
    }

    public function test_shape_validation(): void
    {
        $r = $this->assertProblem($this->api('POST', '/api/v1/sales-invoices/calculate', $this->token, $this->books->id, ['lines' => []]), 422, 'validation_failed');
        foreach (['party_id', 'invoice_date', 'warehouse_id', 'lines'] as $f) {
            $this->assertNotNull($r->json("errors.{$f}"), $f);
        }
        $this->useBooks();
        $bad = fn (array $line) => $this->assertProblem($this->calc(['lines' => [$line + ['product_id' => $this->oil->id]]]), 422, 'validation_failed');
        $this->assertSame(['The quantity must be more than 0'], $bad(['quantity' => '0'])->json('errors')['lines.0.quantity']);
        $bad(['quantity' => '1.2345']);
        $bad(['quantity' => '1', 'unit_price' => '-1']);
        $bad(['quantity' => '1', 'unit_price' => '1.234']);
        $this->assertSame(['Enter a discount between 0 and 100 %'], $bad(['quantity' => '1', 'discount_pct' => '120'])->json('errors')['lines.0.discount_pct']);
        $bad(['quantity' => '1', 'unit_cost' => '1']);
        $this->assertProblem($this->calc(['due_date' => '2000-01-01']), 422, 'validation_failed')->assertJsonPath('errors.due_date.0', 'The due date cannot be before the invoice date');
        $this->assertProblem($this->calc(['party_id' => $this->supplier()->id]), 422, 'validation_failed')->assertJsonPath('errors.party_id.0', 'Choose one of your customers');
        $this->assertProblem($this->calc(['currency_code' => 'USD']), 422, 'validation_failed');
    }

    public function test_permissions_and_isolation(): void
    {
        $this->userWithRole('store');
        $this->assertProblem($this->calc([], $this->login('store@meghna.example')), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $this->assertProblem($this->calc([], $this->login('viewer@meghna.example')), 403, 'permission_denied');
        $this->userWithRole('sales');
        $this->calc([], $this->login('sales@meghna.example'))->assertOk();

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('POST', '/api/v1/sales-invoices/calculate', $this->login('padma@agro.example'), $other->id, [
            'party_id' => $this->karim->id, 'invoice_date' => '2026-10-20', 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '1']],
        ]), 422, 'validation_failed');
    }
}
