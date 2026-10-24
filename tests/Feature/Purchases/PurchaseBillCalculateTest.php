<?php

namespace Tests\Feature\Purchases;

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

/** POST /purchase-bills/calculate (F3, DEC-09; 06 §8; BR-010/011/036/053/055; backlog P4.2-BE-01). */
class PurchaseBillCalculateTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $wh;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier(); // helpers restore the company context the login request cleared
        $this->wh = $this->warehouse('WH-NGJ', 'Narayanganj depot');
        $this->rice = $this->product('Miniket rice 50kg', 'bag', '2500.00');
    }

    private function calc(array $over = [], ?string $token = null)
    {
        $r = $this->api('POST', '/api/v1/purchase-bills/calculate', $token ?? $this->token, $this->books->id, $over + [
            'party_id' => $this->supplier->id, 'supplier_bill_no' => 'CST-0918', 'bill_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->rice->id, 'quantity' => '10', 'unit_cost' => '2340.00']],
        ]);
        $this->useBooks();

        return $r;
    }

    private function rule(string $name, string $rate, string $appliesTo, bool $recoverable = true): TaxRule
    {
        $this->useBooks();

        return TaxRule::create(['name' => $name, 'rate' => $rate, 'applies_to' => $appliesTo, 'is_recoverable' => $recoverable, 'is_active' => true,
            'ledger_id' => Ledger::where('code', '1402')->value('id')]);
    }

    public function test_tc_acc_020_line_totals_through_the_api(): void
    {
        $vat = $this->rule('Input VAT 5%', '5', 'purchases');
        $r = $this->calc(['lines' => [['product_id' => $this->rice->id, 'quantity' => '10', 'unit_cost' => '2340.00', 'discount_pct' => '2', 'tax_rule_id' => $vat->id]]])->assertOk();
        $r->assertJsonPath('data.lines.0.line_no', 1)->assertJsonPath('data.lines.0.gross', '23400.00')->assertJsonPath('data.lines.0.discount_amount', '468.00')
            ->assertJsonPath('data.lines.0.tax_amount', '1146.60')->assertJsonPath('data.lines.0.line_total', '24078.60')->assertJsonPath('data.lines.0.available_qty', '0.000');
        $r->assertJsonPath('data.subtotal', '23400.00')->assertJsonPath('data.discount_total', '468.00')->assertJsonPath('data.tax_total', '1146.60')
            ->assertJsonPath('data.rounding_adjustment', '0.00')->assertJsonPath('data.total', '24078.60')
            ->assertJsonPath('data.amount_in_words', 'Taka twenty-four thousand seventy-eight and sixty paisa only')
            ->assertJsonPath('data.party_balance_before', '0.00')->assertJsonPath('data.party_balance_after', '24078.60')->assertJsonPath('data.warnings', []);
        // Recoverable input VAT is not cost: 22,932.00 net for 10 bags → average 2,293.2000 (06 §8).
        $effects = $r->json('data.effects');
        $this->assertSame('Stock in: 10.000 bag of Miniket rice 50kg into Narayanganj depot; average cost becomes 2293.2000', $effects[0]);
        $this->assertStringContainsString('Payable to Bengal Edible Oils rises by ৳ 24,078.60 to ৳ 24,078.60, due', end($effects));
        $this->assertSame(0, DB::table('purchase_bills')->count(), 'calculate saves nothing');
    }

    public function test_non_recoverable_tax_is_cost_and_services_post_to_an_expense_ledger(): void
    {
        $ait = $this->rule('AIT 5%', '5', 'both', recoverable: false);
        $transport = $this->product('Carriage inward', 'pcs', '0.00', 'service');
        $r = $this->calc(['lines' => [
            ['product_id' => $this->rice->id, 'quantity' => '10', 'unit_cost' => '2000', 'tax_rule_id' => $ait->id],
            ['product_id' => $transport->id, 'quantity' => '1', 'unit_cost' => '1500'],
        ]])->assertOk();
        $effects = $r->json('data.effects');
        $this->assertSame('Stock in: 10.000 bag of Miniket rice 50kg into Narayanganj depot; average cost becomes 2100.0000', $effects[0], 'AIT added to cost');
        $this->assertSame('Expense: 5101 Purchases, non-stock items ৳ 1,500.00', $effects[1]);
        $r->assertJsonPath('data.total', '22500.00')->assertJsonPath('data.lines.1.available_qty', null);
    }

    public function test_06_s8_worked_example_and_the_average_after_existing_stock(): void
    {
        $oil = $this->product('Soybean oil 5L jar');
        $this->useBooks();
        DB::table('stock_balances')->insert(['company_id' => $this->books->id, 'product_id' => $oil->id, 'warehouse_id' => $this->wh->id, 'qty_on_hand' => '100',
            'total_value' => '80000', 'avg_unit_cost' => '800', 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->calc(['lines' => [['product_id' => $oil->id, 'quantity' => '740', 'unit_cost' => '846.00']]])->assertOk();
        $r->assertJsonPath('data.total', '626040.00')->assertJsonPath('data.lines.0.available_qty', '100.000');
        // (80,000 + 6,26,040) ÷ 840 = 840.5238 (BR-017)
        $this->assertStringEndsWith('average cost becomes 840.5238', $r->json('data.effects.0'));
        DB::table('stock_balances')->delete();
    }

    public function test_foreign_currency_needs_a_rate_and_reports_base_balances(): void
    {
        $this->assertProblem($this->calc(['currency_code' => 'usd']), 422, 'validation_failed')->assertJsonPath('errors.exchange_rate.0', 'Enter the USD to BDT rate (more than 0)');
        $this->assertProblem($this->calc(['exchange_rate' => '110']), 422, 'validation_failed')->assertJsonPath('errors.exchange_rate.0', 'A taka bill has exchange rate 1');
        $r = $this->calc(['currency_code' => 'USD', 'exchange_rate' => '110.50', 'lines' => [['product_id' => $this->rice->id, 'quantity' => '10', 'unit_cost' => '20.00']]])->assertOk();
        $r->assertJsonPath('data.total', '200.00')->assertJsonPath('data.amount_in_words', 'USD two hundred only')->assertJsonPath('data.party_balance_after', '22100.00');
    }

    public function test_inactive_references_and_sales_only_tax_rules_are_refused(): void
    {
        $this->supplier->update(['status' => 'inactive']);
        $this->assertProblem($this->calc(), 422, 'inactive_reference')->assertJsonPath('errors.party_id.0', 'Bengal Edible Oils is inactive; reactivate the supplier first');
        $this->supplier->update(['status' => 'active']);
        $this->rice->update(['is_active' => false]);
        $this->assertProblem($this->calc(), 422, 'inactive_reference')->assertJsonPath('errors', ['lines.0.product_id' => ['Miniket rice 50kg is inactive']]);
        $this->rice->update(['is_active' => true]);
        $sales = $this->rule('VAT 15%', '15', 'sales');
        $this->assertProblem($this->calc(['lines' => [['product_id' => $this->rice->id, 'quantity' => '1', 'unit_cost' => '1', 'tax_rule_id' => $sales->id]]]), 422, 'validation_failed')
            ->assertJsonPath('errors', ['lines.0.tax_rule_id' => ['VAT 15% applies to sales only']]);
    }

    public function test_shape_validation(): void
    {
        $r = $this->assertProblem($this->api('POST', '/api/v1/purchase-bills/calculate', $this->token, $this->books->id, ['lines' => []]), 422, 'validation_failed');
        foreach (['party_id', 'supplier_bill_no', 'bill_date', 'warehouse_id', 'lines'] as $f) {
            $this->assertNotNull($r->json("errors.{$f}"), $f);
        }
        $bad = fn (array $line) => $this->assertProblem($this->calc(['lines' => [$line + ['product_id' => $this->rice->id]]]), 422, 'validation_failed');
        $this->assertSame(['The quantity must be more than 0'], $bad(['quantity' => '0', 'unit_cost' => '1'])->json('errors')['lines.0.quantity']);
        $bad(['quantity' => '1.2345', 'unit_cost' => '1']);
        $bad(['quantity' => '1', 'unit_cost' => '-1']);
        $bad(['quantity' => '1', 'unit_cost' => '1', 'colour' => 'red']);
        $this->assertProblem($this->calc(['due_date' => '2000-01-01']), 422, 'validation_failed')->assertJsonPath('errors.due_date.0', 'The due date cannot be before the bill date');
        $this->assertProblem($this->calc(['party_id' => $this->customer()->id]), 422, 'validation_failed');
    }

    public function test_backdated_warning_and_due_date_from_the_payment_term(): void
    {
        $this->useBooks();
        CompanySetting::query()->update(['backdate_warning_days' => 7]);
        $net15 = DB::table('payment_terms')->where('company_id', $this->books->id)->where('name', 'Net 15')->value('id');
        $date = CarbonImmutable::now('Asia/Dhaka')->subDays(10);
        $r = $this->calc(['bill_date' => $date->toDateString(), 'payment_term_id' => $net15])->assertOk();
        $r->assertJsonPath('data.warnings.0.code', 'BACKDATED');
        $effects = $r->json('data.effects');
        $this->assertStringEndsWith('due '.$date->addDays(15)->format('j M Y'), end($effects));
    }

    public function test_permissions_and_isolation(): void
    {
        $this->userWithRole('store');
        $store = $this->login('store@meghna.example');
        $this->assertProblem($this->calc([], $store), 403, 'permission_denied');
        $this->userWithRole('accountant');
        $accountant = $this->login('accountant@meghna.example');
        $this->calc([], $accountant)->assertOk();

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('POST', '/api/v1/purchase-bills/calculate', $this->login('padma@agro.example'), $other->id, [
            'party_id' => $this->supplier->id, 'supplier_bill_no' => 'X', 'bill_date' => '2026-07-05', 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->rice->id, 'quantity' => '1', 'unit_cost' => '1']],
        ]), 422, 'validation_failed');
    }
}
