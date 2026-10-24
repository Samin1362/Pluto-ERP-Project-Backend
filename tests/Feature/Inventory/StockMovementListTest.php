<?php

namespace Tests\Feature\Inventory;

use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** GET /stock-movements (D9; BR-052, R-24; backlog P4.3-BE-03). Movements come from real bill postings and a cancellation. */
class StockMovementListTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $tej;

    private Warehouse $ngj;

    private Product $oil;

    private Product $rice;

    private array $bills = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->tej = $this->warehouse('WH-TEJ', 'Tejgaon warehouse');
        $this->ngj = $this->warehouse('WH-NGJ', 'Narayanganj depot');
        $this->oil = $this->product('Soybean oil 5L jar');
        $this->rice = $this->product('Miniket rice 50kg', 'bag');
        $this->bills[] = $this->bill($this->tej, [[$this->oil, '740', '846.00'], [$this->rice, '20', '2300']]);
        $this->bills[] = $this->bill($this->ngj, [[$this->oil, '10', '850']]);
        $this->hit('POST', '/purchase-bills/'.$this->bills[1]['id'].'/cancel', ['reason' => 'duplicate']);
    }

    private function hit(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, "/api/v1{$uri}", $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function bill(Warehouse $w, array $lines): array
    {
        $b = $this->hit('POST', '/purchase-bills', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'SB-'.Str::random(6),
            'bill_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(), 'warehouse_id' => $w->id,
            'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_cost' => $l[2]], $lines)])->assertCreated()->json('data');
        $this->hit('POST', "/purchase-bills/{$b['id']}/post")->assertOk();

        return $b;
    }

    private function types(string $query = ''): array
    {
        return array_column($this->hit('GET', '/stock-movements'.$query)->assertOk()->json('data'), 'movement_type');
    }

    public function test_the_stock_ledger_newest_first_with_source_numbers(): void
    {
        $r = $this->hit('GET', '/stock-movements')->assertOk()->assertJsonPath('meta.total', 4);
        $rows = $r->json('data');
        $this->assertSame(['reversal', 'purchase_in', 'purchase_in', 'purchase_in'], array_column($rows, 'movement_type'));
        $this->assertSame([$this->bills[1]['number'], 'purchase_bills', $this->bills[1]['id']], [$rows[0]['source_number'], $rows[0]['source_type'], $rows[0]['source_id']]);
        $oilIn = collect($rows)->first(fn ($m) => $m['movement_type'] === 'purchase_in' && $m['warehouse']['name'] === 'Tejgaon warehouse' && $m['product']['id'] === $this->oil->id);
        $this->assertSame(['740.000', '0.000', '740.000', '846.0000', '626040.00', 'Soybean oil 5L jar'],
            [$oilIn['quantity_in'], $oilIn['quantity_out'], $oilIn['qty_balance_after'], $oilIn['unit_cost'], $oilIn['value'], $oilIn['product']['name']]);
        $this->assertSame(['0.000', '10.000', '0.000', '8500.00'], [$rows[0]['quantity_in'], $rows[0]['quantity_out'], $rows[0]['qty_balance_after'], $rows[0]['value']]);
    }

    public function test_filters_and_search(): void
    {
        $this->assertSame(['reversal'], $this->types('?movement_type=reversal'));
        $this->assertCount(2, $this->types("?warehouse_id={$this->ngj->id}"));
        $this->assertSame(['purchase_in'], $this->types("?product_id={$this->rice->id}"));
        $this->assertCount(2, $this->types('?q='.$this->bills[0]['number']), 'by source document number');
        $this->assertCount(1, $this->types('?q='.$this->rice->code), 'by product code');
        $this->assertCount(3, $this->types('?q=soybean'), 'by product name');
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $this->assertCount(4, $this->types("?date_from={$today}&date_to={$today}"));
        $this->assertSame([], $this->types('?date_to=2000-01-01'));
        $this->assertSame('purchase_in', $this->types('?sort=moved_on')[0]);
        foreach (['?movement_type=sale', '?warehouse_id=999999', '?source_type=purchase_bills', '?sort=qty'] as $bad) {
            $this->assertProblem($this->hit('GET', '/stock-movements'.$bad), 422, 'validation_failed');
        }
    }

    public function test_r24_a_store_keeper_sees_quantities_but_no_cost_or_value(): void
    {
        $this->userWithRole('store');
        $rows = $this->hit('GET', '/stock-movements', token: $this->login('store@meghna.example'))->assertOk()->json('data');
        foreach ($rows as $m) {
            $this->assertArrayNotHasKey('unit_cost', $m);
            $this->assertArrayNotHasKey('value', $m);
        }
        $this->assertSame('740.000', collect($rows)->firstWhere('quantity_in', '740.000')['qty_balance_after']);
    }

    public function test_isolation(): void
    {
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->api('GET', '/api/v1/stock-movements', $this->login('padma@agro.example'), $other->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->useBooks();
    }
}
