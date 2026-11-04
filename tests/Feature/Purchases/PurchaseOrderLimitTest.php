<?php

namespace Tests\Feature\Purchases;

use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Bills may not receive more than their purchase order still expects (BR-056, TC-PUR-013): calculate warns
 * ORDER_QTY_EXCEEDED, save and post refuse 422 order_qty_exceeded. An import bill whose LC is raised against the same
 * order may use the LC's tolerance. The LC response names its order so F3 can fill the bill from it.
 */
class PurchaseOrderLimitTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $wh;

    private Product $oil;

    private string $po1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $this->po1 = 'PO-'.FiscalYear::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->value('start_date')->year.'-0001';
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [])
    {
        if ($method === 'POST' && ! isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }
        $r = $this->api($method, $uri, $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    /** A sent order for 100 jars; returns [order id, order line id]. */
    private function order(array $over = []): array
    {
        $id = $this->req('POST', '/api/v1/purchase-orders', $over + ['party_id' => $this->supplier->id, 'order_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '100', 'unit_cost' => '800.00']]])->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/purchase-orders/{$id}/send", ['to' => ['sales@bengal.example']])->assertOk();

        return [$id, PurchaseOrder::find($id)->lines()->value('id')];
    }

    private function bill(int $po, int $line, array $quantities, array $over = []): array
    {
        return $over + ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'BEO-'.Str::random(6), 'bill_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'purchase_order_id' => $po, 'lines' => array_map(fn ($q) => ['product_id' => $this->oil->id, 'quantity' => $q, 'unit_cost' => '800.00', 'purchase_order_line_id' => $line], $quantities)];
    }

    private function postBill(array $body): int
    {
        $id = $this->req('POST', '/api/v1/purchase-bills', $body)->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/purchase-bills/{$id}/post")->assertOk();

        return $id;
    }

    public function test_tc_pur_013_a_bill_cannot_take_more_than_the_order_still_expects(): void
    {
        [$po, $line] = $this->order();
        $this->postBill($this->bill($po, $line, ['60']));

        // 40 left: calculate warns, save refuses, on the line's quantity.
        $over = $this->bill($po, $line, ['50']);
        $this->req('POST', '/api/v1/purchase-bills/calculate', $over)->assertOk()
            ->assertJsonPath('data.warnings.0.code', 'ORDER_QTY_EXCEEDED')->assertJsonPath('data.warnings.0.line_no', 1);
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $over), 422, 'order_qty_exceeded')
            ->assertJsonPath('errors', ['lines.0.quantity' => ["Only 40.000 jar of Soybean oil 5L jar is left on {$this->po1}; this bill has 50.000 jar"]]);

        // Two lines against the same order line count together.
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $this->bill($po, $line, ['30', '20'])), 422, 'order_qty_exceeded')
            ->assertJsonStructure(['errors' => ['lines.0.quantity', 'lines.1.quantity']]);

        // Exactly what is left is fine; an unlinked extra line is not limited.
        $exact = $this->bill($po, $line, ['40']);
        $exact['lines'][] = ['product_id' => $this->oil->id, 'quantity' => '5', 'unit_cost' => '800.00'];
        $this->req('POST', '/api/v1/purchase-bills/calculate', $exact)->assertOk()->assertJsonPath('data.warnings', []);
        $this->req('POST', '/api/v1/purchase-bills', $exact)->assertCreated();

        // A draft edited past the order is refused too.
        $draft = $this->req('POST', '/api/v1/purchase-bills', $this->bill($po, $line, ['10']))->assertCreated()->json('data.id');
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-bills/{$draft}", $this->bill($po, $line, ['41']), ['If-Match' => 'W/"1"']), 422, 'order_qty_exceeded');
    }

    public function test_tc_pur_013_two_drafts_that_each_fit_cannot_both_post(): void
    {
        [$po, $line] = $this->order();
        $a = $this->req('POST', '/api/v1/purchase-bills', $this->bill($po, $line, ['70']))->assertCreated()->json('data.id');
        $b = $this->req('POST', '/api/v1/purchase-bills', $this->bill($po, $line, ['70']))->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/purchase-bills/{$a}/post")->assertOk();
        $entries = DB::table('journal_entries')->count();
        $movements = DB::table('stock_movements')->count();

        $this->assertSame("Only 30.000 jar of Soybean oil 5L jar is left on {$this->po1}; this bill has 70.000 jar",
            $this->assertProblem($this->req('POST', "/api/v1/purchase-bills/{$b}/post"), 422, 'order_qty_exceeded')->json('errors')['lines.0.quantity'][0]);
        $this->assertSame('draft', PurchaseBill::find($b)->status, 'nothing posts');
        $this->assertSame($entries, DB::table('journal_entries')->count());
        $this->assertSame($movements, DB::table('stock_movements')->count(), 'no stock in for the refused bill');
        $this->req('GET', "/api/v1/purchase-orders/{$po}")->assertJsonPath('data.lines.0.received_quantity', '70.000');

        // Cancelling the first bill frees the order again.
        $this->req('POST', "/api/v1/purchase-bills/{$a}/cancel", ['reason' => 'duplicate'])->assertOk();
        $this->req('POST', "/api/v1/purchase-bills/{$b}/post")->assertOk();
        $this->assertBooksAreConsistent();
    }

    public function test_tc_pur_013_an_lc_raised_against_the_order_adds_its_tolerance(): void
    {
        [$po, $line] = $this->order(['currency_code' => 'USD', 'exchange_rate' => '121.500000']);
        $lc = $this->lc(['purchase_order_id' => $po, 'tolerance_pct' => '5']);

        $this->req('GET', "/api/v1/letters-of-credit/{$lc}")->assertOk()->assertJsonPath('data.purchase_order_id', $po)
            ->assertJsonPath('data.purchase_order_number', $this->po1)->assertJsonPath('data.quantity', '100.000')->assertJsonPath('data.quantity_unit', 'jar');
        $this->req('GET', '/api/v1/letters-of-credit')->assertJsonPath('data.0.purchase_order_number', $this->po1);

        $import = fn (string $q) => $this->bill($po, $line, [$q], ['letter_of_credit_id' => $lc, 'currency_code' => 'USD', 'exchange_rate' => '121.500000']);
        $this->assertSame("Only 105.000 jar of Soybean oil 5L jar is left on {$this->po1} (incl. the LC's 5% tolerance); this bill has 106.000 jar",
            $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $import('106')), 422, 'order_qty_exceeded')->json('errors')['lines.0.quantity'][0]);
        $this->postBill($import('105'));
        $this->assertSame("Everything on {$this->po1} for Soybean oil 5L jar has already been received (incl. the LC's 5% tolerance)",
            $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $import('1')), 422, 'order_qty_exceeded')->json('errors')['lines.0.quantity'][0]);
        $this->req('GET', "/api/v1/purchase-orders/{$po}")->assertJsonPath('data.status', 'received');
    }

    public function test_tc_pur_013_an_lc_for_another_order_gives_no_tolerance(): void
    {
        [$po, $line] = $this->order(['currency_code' => 'USD', 'exchange_rate' => '121.500000']);
        $lc = $this->lc(['tolerance_pct' => '10']); // not raised against this order
        $this->req('GET', "/api/v1/letters-of-credit/{$lc}")->assertJsonPath('data.purchase_order_id', null)->assertJsonPath('data.purchase_order_number', null);
        $this->assertSame("Only 100.000 jar of Soybean oil 5L jar is left on {$this->po1}; this bill has 101.000 jar",
            $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $this->bill($po, $line, ['101'],
                ['letter_of_credit_id' => $lc, 'currency_code' => 'USD', 'exchange_rate' => '121.500000'])), 422, 'order_qty_exceeded')->json('errors')['lines.0.quantity'][0]);
    }

    private function bank(): int
    {
        return $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => (string) random_int(10000000, 99999999), 'opening_balance' => '0.00'])->assertCreated()->json('data.id');
    }

    /** An opened USD LC for the supplier (the opening workflow is LcTest's; here only its terms matter). */
    private function lc(array $over): int
    {
        $id = $this->req('POST', '/api/v1/letters-of-credit', $over + ['party_id' => $this->supplier->id, 'goods_description' => '100 jars soybean oil', 'quantity' => '100',
            'quantity_unit' => 'jar', 'tenor' => 'sight', 'incoterm' => 'CFR Chattogram', 'currency_code' => 'USD', 'amount' => '660.00', 'planning_rate' => '121.50',
            'issuing_bank_account_id' => $this->bank(), 'expiry_date' => CarbonImmutable::parse($this->today())->addDays(60)->toDateString()])
            ->assertCreated()->json('data.id');
        DB::table('letters_of_credit')->where('id', $id)->update(['status' => 'opened', 'opening_status' => 'opened', 'bank_reference' => 'SBL/LC/T1', 'opened_on' => $this->today()]);

        return $id;
    }
}
