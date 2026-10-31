<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\WarehouseService;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Stock transfers: /stock-transfers (D11; 06 §11.4; BR-030, BR-014, BR-017; TC-INV-004; backlog P11.1-BE-03). */
class StockTransferTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $tej;

    private Warehouse $ngj;

    private Warehouse $annex;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->tej = $this->warehouse();                                      // 1301-01
        $this->ngj = $this->warehouse('WH-NGJ', 'Narayanganj depot');         // 1301-02
        $this->useBooks();
        $this->annex = app(WarehouseService::class)->create(['code' => 'WH-TJA', 'name' => 'Tejgaon annex', 'inventory_ledger_id' => $this->tej->inventory_ledger_id,
            'branch_id' => DB::table('branches')->where('company_id', $this->books->id)->value('id')]);
        $this->oil = $this->product('Soybean oil 5L jar');
        $b = $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'BEO-1', 'bill_date' => $this->today(),
            'warehouse_id' => $this->tej->id, 'lines' => [['product_id' => $this->oil->id, 'quantity' => '100', 'unit_cost' => '845.00']]])->assertCreated();
        $this->req('POST', '/api/v1/purchase-bills/'.$b->json('data.id').'/post')->assertOk();
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST' && ! isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function body(Warehouse $to, string $qty, array $over = []): array
    {
        return $over + ['from_warehouse_id' => $this->tej->id, 'to_warehouse_id' => $to->id, 'vehicle' => 'Dhaka Metro Ta 11-2345',
            'lines' => [['product_id' => $this->oil->id, 'quantity' => $qty]]];
    }

    private function stock(Warehouse $w): array
    {
        $b = DB::table('stock_balances')->where('product_id', $this->oil->id)->where('warehouse_id', $w->id)->first();

        return [(string) ($b->qty_on_hand ?? '0.000'), (string) ($b->total_value ?? '0.00'), (string) ($b->qty_in_transit_out ?? '0.000')];
    }

    private function lines(?int $entryId): array
    {
        return JournalEntry::with('lines.ledger')->findOrFail($entryId)->lines->map(fn ($l) => [$l->ledger->code, (string) $l->debit, (string) $l->credit])->all();
    }

    public function test_tc_inv_004_draft_checks(): void
    {
        $this->assertProblem($this->req('POST', '/api/v1/stock-transfers', $this->body($this->tej, '5')), 422, 'same_warehouse');
        $r = $this->assertProblem($this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '101')), 422, 'insufficient_stock');
        $this->assertSame(['Only 100 available in Tejgaon warehouse'], $r->json('errors')['lines.0.quantity']);
        $this->assertProblem($this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '0')), 422, 'validation_failed');

        $t = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '20'))->assertCreated();
        $t->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_units', '20.000')->assertJsonPath('data.value_at_cost', '16900.00')
            ->assertJsonPath('data.lines.0.available', '100.000')->assertJsonPath('data.from_warehouse_name', 'Tejgaon warehouse')
            ->assertJsonPath('data.vehicle', 'Dhaka Metro Ta 11-2345')->assertHeader('ETag', 'W/"1"');
        $this->assertMatchesRegularExpression('/^TRF-\d{4}-0001$/', $t->json('data.number'));
        $this->assertSame(['100.000', '84500.00', '0.000'], $this->stock($this->tej), 'a draft moves nothing');
    }

    public function test_tc_inv_004_same_ledger_moves_stock_without_a_journal(): void
    {
        $id = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->annex, '20'))->json('data.id');
        $before = JournalEntry::count();
        $this->req('POST', "/api/v1/stock-transfers/{$id}/dispatch")->assertOk()->assertJsonPath('data.status', 'dispatched')->assertJsonPath('data.dispatch_entry_id', null)
            ->assertJsonPath('data.lines.0.unit_cost', '845.0000');
        $this->assertSame(['80.000', '67600.00', '20.000'], $this->stock($this->tej));
        $this->req('POST', "/api/v1/stock-transfers/{$id}/receive", ['received_date' => $this->today()])->assertOk()->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.receive_entry_id', null)->assertJsonPath('data.transit_loss_value', '0.00')->assertJsonPath('data.lines.0.received_quantity', '20.000');
        $this->assertSame($before, JournalEntry::count(), 'no journal when both use the same ledger');
        $this->assertSame(['80.000', '67600.00', '0.000'], $this->stock($this->tej));
        $this->assertSame(['20.000', '16900.00', '0.000'], $this->stock($this->annex));
        $this->assertSame(['transfer_out', 'transfer_in'], StockMovement::where('source_type', 'stock_transfers')->where('source_id', $id)->orderBy('id')->pluck('movement_type')->all());
    }

    public function test_tc_inv_004_different_ledgers_post_via_stock_in_transit(): void
    {
        $id = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '20'))->json('data.id');
        $d = $this->req('POST', "/api/v1/stock-transfers/{$id}/dispatch")->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/stock-transfers/{$id}/dispatch"), 409, 'already_posted');
        $this->assertSame([['1405', '16900.00', '0.00'], ['1301-01', '0.00', '16900.00']], $this->lines($d->json('data.dispatch_entry_id')));
        $this->assertSame(['80.000', '67600.00', '20.000'], $this->stock($this->tej));

        $r = $this->req('POST', "/api/v1/stock-transfers/{$id}/receive", ['received_date' => $this->today()])->assertOk();
        $this->assertSame([['1301-02', '16900.00', '0.00'], ['1405', '0.00', '16900.00']], $this->lines($r->json('data.receive_entry_id')));
        $this->assertSame(['20.000', '16900.00', '0.000'], $this->stock($this->ngj));
        $this->assertSame('84500.00', (string) DB::table('stock_balances')->where('product_id', $this->oil->id)->sum('total_value'), 'total value unchanged');
        $this->assertProblem($this->req('POST', "/api/v1/stock-transfers/{$id}/receive", ['received_date' => $this->today()]), 409, 'already_posted');
    }

    public function test_a_short_receipt_posts_the_transit_loss(): void
    {
        $id = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '20'))->json('data.id');
        $this->req('POST', "/api/v1/stock-transfers/{$id}/dispatch")->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/stock-transfers/{$id}/receive",
            ['received_date' => $this->today(), 'lines' => [['product_id' => $this->oil->id, 'quantity' => '21']]]), 422, 'validation_failed');
        $r = $this->req('POST', "/api/v1/stock-transfers/{$id}/receive", ['received_date' => $this->today(), 'lines' => [['product_id' => $this->oil->id, 'quantity' => '18']]])
            ->assertOk()->assertJsonPath('data.transit_loss_value', '1690.00')->assertJsonPath('data.lines.0.received_quantity', '18.000');
        $this->assertSame([['1301-02', '15210.00', '0.00'], ['5002', '1690.00', '0.00'], ['1405', '0.00', '16900.00']], $this->lines($r->json('data.receive_entry_id')));
        $this->assertSame(['18.000', '15210.00', '0.000'], $this->stock($this->ngj));

        // Same ledger: only the loss posts
        $id = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->annex, '10'))->json('data.id');
        $this->req('POST', "/api/v1/stock-transfers/{$id}/dispatch")->assertOk();
        $r = $this->req('POST', "/api/v1/stock-transfers/{$id}/receive", ['received_date' => $this->today(), 'lines' => [['product_id' => $this->oil->id, 'quantity' => '9']]]);
        $this->assertSame([['5002', '845.00', '0.00'], ['1301-01', '0.00', '845.00']], $this->lines($r->json('data.receive_entry_id')));
    }

    public function test_cancel_a_draft_or_a_dispatch_but_not_a_receipt(): void
    {
        $draft = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '5'))->json('data.id');
        $this->req('POST', "/api/v1/stock-transfers/{$draft}/cancel", ['reason' => 'Not needed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertProblem($this->req('POST', "/api/v1/stock-transfers/{$draft}/cancel", ['reason' => 'Again']), 409, 'already_reversed');

        $sent = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '20'))->json('data.id');
        $dispatch = $this->req('POST', "/api/v1/stock-transfers/{$sent}/dispatch")->json('data.dispatch_entry_id');
        $this->req('POST', "/api/v1/stock-transfers/{$sent}/cancel", ['reason' => 'Truck broke down'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(['100.000', '84500.00', '0.000'], $this->stock($this->tej));
        $this->assertSame('reversed', JournalEntry::find($dispatch)->status);
        $this->assertSame(1, JournalEntry::where('reverses_entry_id', $dispatch)->count());

        $done = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '5'))->json('data.id');
        $this->req('POST', "/api/v1/stock-transfers/{$done}/dispatch")->assertOk();
        $this->req('POST', "/api/v1/stock-transfers/{$done}/receive", ['received_date' => $this->today()])->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/stock-transfers/{$done}/cancel", ['reason' => 'x']), 409, 'invalid_transition');
    }

    public function test_drafts_list_permissions_and_isolation(): void
    {
        $id = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '5'))->json('data.id');
        $this->req('PATCH', "/api/v1/stock-transfers/{$id}", $this->body($this->annex, '6'), ['If-Match' => 'W/"1"'])->assertOk()
            ->assertJsonPath('data.to_warehouse_id', $this->annex->id)->assertJsonPath('data.version', 2);
        $this->assertProblem($this->req('PATCH', "/api/v1/stock-transfers/{$id}", $this->body($this->annex, '6'), ['If-Match' => 'W/"1"']), 409, 'version_conflict');
        $other = $this->req('POST', '/api/v1/stock-transfers', $this->body($this->ngj, '1'))->json('data.id');
        $this->req('POST', "/api/v1/stock-transfers/{$other}/dispatch")->assertOk();
        $this->assertProblem($this->req('DELETE', "/api/v1/stock-transfers/{$other}"), 409, 'not_draft');
        $this->req('GET', '/api/v1/stock-transfers')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$other], array_column($this->req('GET', "/api/v1/stock-transfers?warehouse_id={$this->ngj->id}")->json('data'), 'id'));
        $this->assertSame([$id], array_column($this->req('GET', '/api/v1/stock-transfers?status=draft')->json('data'), 'id'));
        $this->req('DELETE', "/api/v1/stock-transfers/{$id}")->assertNoContent();

        $this->userWithRole('store', 'store@meghna.example');
        $row = $this->req('GET', "/api/v1/stock-transfers/{$other}", [], [], $this->login('store@meghna.example'))->assertOk()->json('data');
        $this->assertArrayNotHasKey('value_at_cost', $row);
        $this->assertArrayNotHasKey('unit_cost', $row['lines'][0]);
        $this->userWithRole('viewer', 'viewer@meghna.example');
        $this->assertProblem($this->req('POST', "/api/v1/stock-transfers/{$other}/receive", ['received_date' => $this->today()], [], $this->login('viewer@meghna.example')), 403, 'permission_denied');
        $padma = $this->makeCompany('Padma Traders Ltd.', $this->makeUser('other@padma.example'));
        $this->assertProblem($this->api('GET', "/api/v1/stock-transfers/{$other}", $this->login('other@padma.example'), $padma->id), 404, 'not_found');
    }
}
