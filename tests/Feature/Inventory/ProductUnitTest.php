<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Extra units of an item (P10.7-BE-01, PEP-464; BR-069): POST/PATCH /products `units`, Product `unit_id` and `units`
 * with each unit's prices worked out by the server, and the rules that keep posted quantities meaningful.
 */
class ProductUnitTest extends TestCase
{
    private Company $company;

    private string $token;

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->token = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $unit = fn (string $name) => DB::table('units')->where('company_id', $c)->where('name', $name)->value('id');
        $this->ids = ['pcs' => $unit('pcs'), 'box' => $unit('box'), 'carton' => $unit('carton'), 'dozen' => $unit('dozen'),
            'sales' => DB::table('ledgers')->where('company_id', $c)->where('code', '4101')->value('id')];
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }

        return $this->api($method, $uri, $token ?? $this->token, $this->company->id, $data, $headers);
    }

    private function soap(array $extra = []): array
    {
        return $extra + ['item_type' => 'product', 'name' => 'Lux soap 100g', 'unit_id' => $this->ids['pcs'], 'selling_price' => '55.00',
            'wholesale_price' => '50.50', 'last_purchase_cost' => '42.1250', 'income_ledger_id' => $this->ids['sales']];
    }

    public function test_create_with_extra_units_returns_every_unit_with_its_prices(): void
    {
        $r = $this->req('POST', '/api/v1/products', $this->soap(['units' => [
            ['unit_id' => $this->ids['carton'], 'factor' => '144'], ['unit_id' => $this->ids['box'], 'factor' => '12']]]))->assertCreated();

        $r->assertJsonPath('data.unit', 'pcs')->assertJsonPath('data.unit_id', $this->ids['pcs']);
        $units = $r->json('data.units');
        $this->assertSame(['pcs', 'box', 'carton'], array_column($units, 'name'), 'base unit first, then by factor');
        $this->assertSame(['1.000000', '12.000000', '144.000000'], array_column($units, 'factor'));
        $this->assertSame([true, false, false], array_column($units, 'is_base'));
        $this->assertSame(['55.00', '660.00', '7920.00'], array_column($units, 'selling_price'), 'price x factor, by the server');
        $this->assertSame(['50.50', '606.00', '7272.00'], array_column($units, 'wholesale_price'));
        $this->assertSame(['42.1250', '505.5000', '6066.0000'], array_column($units, 'last_purchase_cost'));
        $this->assertSame(2, DB::table('product_units')->where('product_id', $r->json('data.id'))->count());
        $this->assertSame(['units' => [(string) $this->ids['box'] => '12.000000', (string) $this->ids['carton'] => '144.000000']],
            array_intersect_key(AuditLog::where('auditable_type', 'products')->where('auditable_id', $r->json('data.id'))->where('action', 'created')->firstOrFail()->new_values, ['units' => 1]));
    }

    public function test_an_item_without_extra_units_lists_only_its_base_unit(): void
    {
        $r = $this->req('POST', '/api/v1/products', $this->soap())->assertCreated();

        $this->assertSame([['unit_id' => $this->ids['pcs'], 'name' => 'pcs', 'factor' => '1.000000', 'decimals' => 0, 'is_base' => true,
            'selling_price' => '55.00', 'wholesale_price' => '50.50', 'last_purchase_cost' => '42.1250']], $r->json('data.units'));
        $this->assertSame($r->json('data.units'), collect($this->req('GET', '/api/v1/products')->assertOk()->json('data'))->firstWhere('id', $r->json('data.id'))['units'], 'the list returns them too');
    }

    public function test_validation_of_units(): void
    {
        $bad = function (array $units, string $field) {
            $this->assertProblem($this->req('POST', '/api/v1/products', $this->soap(['units' => $units])), 422, 'validation_failed')
                ->assertJsonStructure(['errors' => [$field]]);
        };
        $bad([['unit_id' => $this->ids['pcs'], 'factor' => '1']], 'units.0.unit_id');            // the base unit again
        $bad([['unit_id' => $this->ids['box'], 'factor' => '0']], 'units.0.factor');             // not above 0
        $bad([['unit_id' => $this->ids['box'], 'factor' => '-2']], 'units.0.factor');
        $bad([['unit_id' => $this->ids['box'], 'factor' => '12.1234567']], 'units.0.factor');    // more than 6 decimals
        $bad([['unit_id' => $this->ids['box']]], 'units.0.factor');
        $bad([['unit_id' => $this->ids['box'], 'factor' => '12'], ['unit_id' => $this->ids['box'], 'factor' => '24']], 'units.1.unit_id');
        $bad([['unit_id' => 999999, 'factor' => '12']], 'units.0.unit_id');
        $bad([['unit_id' => $this->ids['box'], 'factor' => '12', 'price' => '1']], 'units.0');
        $this->assertSame(0, DB::table('products')->where('company_id', $this->company->id)->count());

        $other = $this->makeCompany('Other Co', $this->makeUser('owner@other.example'));
        $foreign = DB::table('units')->where('company_id', $other->id)->where('name', 'box')->value('id');
        $bad([['unit_id' => $foreign, 'factor' => '12']], 'units.0.unit_id');
    }

    public function test_patch_replaces_units_but_a_used_unit_keeps_its_factor(): void
    {
        $p = $this->req('POST', '/api/v1/products', $this->soap(['units' => [['unit_id' => $this->ids['box'], 'factor' => '12'],
            ['unit_id' => $this->ids['carton'], 'factor' => '144']]]))->json('data');

        $r = $this->req('PATCH', "/api/v1/products/{$p['id']}", ['units' => [['unit_id' => $this->ids['box'], 'factor' => '10'],
            ['unit_id' => $this->ids['dozen'], 'factor' => '12']]], ['If-Match' => 'W/"1"'])->assertOk();
        $this->assertSame(['pcs', 'box', 'dozen'], array_column($r->json('data.units'), 'name'), 'carton removed, dozen added');
        $this->assertSame('10.000000', $r->json('data.units.1.factor'));
        $this->assertSame(2, $r->json('data.version'));
        $this->assertTrue(AuditLog::where('auditable_type', 'products')->where('auditable_id', $p['id'])->where('action', 'updated')->exists());

        $r = $this->req('PATCH', "/api/v1/products/{$p['id']}", ['selling_price' => '60.00'], ['If-Match' => 'W/"2"'])->assertOk();
        $this->assertSame(['pcs', 'box', 'dozen'], array_column($r->json('data.units'), 'name'), 'units not sent stay as they are');
        $this->assertSame('600.00', $r->json('data.units.1.selling_price'));

        // A box line on a quotation makes the box unit "used"
        DB::table('quotation_lines')->insert(['quotation_id' => $this->quotation(), 'line_no' => 1, 'product_id' => $p['id'], 'product_code' => $p['code'],
            'product_name' => $p['name'], 'unit_name' => 'box', 'unit_id' => $this->ids['box'], 'unit_factor' => '10', 'quantity' => '2', 'unit_price' => '600.00',
            'net_amount' => '1200.00', 'line_total' => '1200.00']);
        $this->assertProblem($this->req('PATCH', "/api/v1/products/{$p['id']}", ['units' => [['unit_id' => $this->ids['box'], 'factor' => '12']]], ['If-Match' => 'W/"3"']), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['units.0.factor']]);
        $this->assertProblem($this->req('PATCH', "/api/v1/products/{$p['id']}", ['units' => []], ['If-Match' => 'W/"3"']), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['units']]);
        $r = $this->req('PATCH', "/api/v1/products/{$p['id']}", ['units' => [['unit_id' => $this->ids['box'], 'factor' => '10.000']]], ['If-Match' => 'W/"3"'])->assertOk();
        $this->assertSame(['pcs', 'box'], array_column($r->json('data.units'), 'name'), 'an unused unit can still go');
    }

    public function test_the_base_unit_cannot_become_one_of_the_extra_units(): void
    {
        $p = $this->req('POST', '/api/v1/products', $this->soap(['units' => [['unit_id' => $this->ids['box'], 'factor' => '12']]]))->json('data');

        $this->assertProblem($this->req('PATCH', "/api/v1/products/{$p['id']}", ['unit_id' => $this->ids['box']], ['If-Match' => 'W/"1"']), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['unit_id']]);
        $this->req('PATCH', "/api/v1/products/{$p['id']}", ['unit_id' => $this->ids['box'], 'units' => [['unit_id' => $this->ids['carton'], 'factor' => '12']]], ['If-Match' => 'W/"1"'])
            ->assertOk()->assertJsonPath('data.units.0.name', 'box')->assertJsonPath('data.units.1.name', 'carton');
    }

    public function test_store_keeper_gets_no_cost_per_unit(): void
    {
        $this->req('POST', '/api/v1/products', $this->soap(['units' => [['unit_id' => $this->ids['box'], 'factor' => '12']]]))->assertCreated();
        $this->addMember($this->company, $this->makeUser('store@meghna.example'), 'store');
        $units = $this->api('GET', '/api/v1/products', $this->login('store@meghna.example'), $this->company->id)->assertOk()->json('data.0.units');

        $this->assertSame(['660.00', '606.00'], [$units[1]['selling_price'], $units[1]['wholesale_price']]);
        $this->assertArrayNotHasKey('last_purchase_cost', $units[1], 'BR-052');
    }

    private function quotation(): int
    {
        $party = $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711000111'])->assertCreated()->json('data.id');

        return DB::table('quotations')->insertGetId(['company_id' => $this->company->id, 'branch_id' => DB::table('branches')->where('company_id', $this->company->id)->value('id'),
            'number' => 'QT-TEST-1', 'party_id' => $party, 'quotation_date' => '2026-11-03', 'valid_until' => '2026-11-30', 'created_at' => now(), 'updated_at' => now()]);
    }
}
