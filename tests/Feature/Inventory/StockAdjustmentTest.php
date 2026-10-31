<?php

namespace Tests\Feature\Inventory;

use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Stock adjustments: /stock-adjustments (D10; 06 §11.3; BR-029, BR-009, BR-025, BR-049, BR-052; TC-INV-002,
 * TC-INV-003; backlog P11.1-BE-02).
 */
class StockAdjustmentTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $tej;

    private Product $oil;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->tej = $this->warehouse();                       // the first warehouse uses 1301-01
        $this->oil = $this->product('Soybean oil 5L jar');
        $this->rice = $this->product('Chinigura rice 25kg', 'bag', '2400.00');
        $this->bill([[$this->oil, '18', '845.00'], [$this->rice, '10', '2000.00']]);
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

    /** A posted purchase bill into Tejgaon: [[product, qty, cost], …]. */
    private function bill(array $lines): void
    {
        $b = $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'BEO-'.Str::random(5), 'bill_date' => $this->today(),
            'warehouse_id' => $this->tej->id, 'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_cost' => $l[2]], $lines)])->assertCreated();
        $this->req('POST', '/api/v1/purchase-bills/'.$b->json('data.id').'/post')->assertOk();
    }

    private function body(array $lines, array $over = []): array
    {
        return $over + ['adjustment_date' => $this->today(), 'warehouse_id' => $this->tej->id, 'reason' => 'count_difference',
            'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'counted_qty' => $l[1]], $lines)];
    }

    private function onHand(Product $p): array
    {
        $b = DB::table('stock_balances')->where('product_id', $p->id)->where('warehouse_id', $this->tej->id)->first();

        return [(string) $b->qty_on_hand, (string) $b->total_value];
    }

    private function ledger(string $code): int
    {
        return (int) DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');
    }

    public function test_tc_inv_002_a_shortage_of_2_jars_posts_dr_stock_adjustment_cr_inventory(): void
    {
        $r = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '16']]))->assertCreated();
        $r->assertJsonPath('data.status', 'draft')->assertJsonPath('data.lines.0.system_qty', '18.000')->assertJsonPath('data.lines.0.counted_qty', '16.000')
            ->assertJsonPath('data.lines.0.difference_qty', '-2.000')->assertJsonPath('data.lines.0.unit_cost', '845.0000')->assertJsonPath('data.lines.0.value', '-1690.00')
            ->assertJsonPath('data.net_value', '-1690.00')->assertJsonPath('data.version', 1)->assertHeader('ETag', 'W/"1"');
        $this->assertMatchesRegularExpression('/^ADJ-\d{4}-0001$/', $r->json('data.number'));
        $this->assertSame([['ledger_code' => '5002', 'debit' => '1690.00', 'credit' => '0.00'], ['ledger_code' => '1301-01', 'debit' => '0.00', 'credit' => '1690.00']],
            array_map(fn ($l) => ['ledger_code' => $l['ledger_code'], 'debit' => $l['debit'], 'credit' => $l['credit']], $r->json('data.posting_preview')));
        $this->assertSame(0, JournalEntry::count() - 1, 'a draft posts nothing (only the bill)');

        $id = $r->json('data.id');
        $key = (string) Str::uuid();
        $p = $this->req('POST', "/api/v1/stock-adjustments/{$id}/post", [], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->req('POST', "/api/v1/stock-adjustments/{$id}/post", [], ['Idempotency-Key' => $key])->assertOk(); // replay
        $this->assertProblem($this->req('POST', "/api/v1/stock-adjustments/{$id}/post"), 409, 'already_posted');

        $entry = JournalEntry::with('lines')->findOrFail($p->json('data.journal_entry_id'));
        $this->assertSame('stock_adjustment', $entry->entry_type);
        $this->assertSame($r->json('data.number'), $entry->voucher_number);
        $this->assertSame([[$this->ledger('5002'), '1690.00', '0.00'], [$this->ledger('1301-01'), '0.00', '1690.00']],
            $entry->lines->map(fn ($l) => [$l->ledger_id, (string) $l->debit, (string) $l->credit])->all());
        $this->assertSame(['16.000', '13520.00'], $this->onHand($this->oil));
        $m = StockMovement::where('source_type', 'stock_adjustments')->where('source_id', $id)->sole();
        $this->assertSame(['adjustment_out', '2.000', '1690.00', $entry->id], [$m->movement_type, (string) $m->quantity_out, (string) $m->value_out, $m->journal_entry_id]);
        $this->req('GET', "/api/v1/stock-movements?product_id={$this->oil->id}")->assertOk()->assertJsonPath('data.0.source_number', $r->json('data.number'));
    }

    public function test_tc_inv_003_a_gain_posts_dr_inventory_cr_stock_adjustment_and_unchanged_lines_post_nothing(): void
    {
        $id = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->rice, '12'], [$this->oil, '18']]))->assertCreated()->json('data.id');
        $p = $this->req('POST', "/api/v1/stock-adjustments/{$id}/post")->assertOk()->assertJsonPath('data.net_value', '4000.00')
            ->assertJsonPath('data.lines.0.value', '4000.00')->assertJsonPath('data.lines.1.value', '0.00');
        $entry = JournalEntry::with('lines')->findOrFail($p->json('data.journal_entry_id'));
        $this->assertSame([[$this->ledger('1301-01'), '4000.00', '0.00'], [$this->ledger('5002'), '0.00', '4000.00']],
            $entry->lines->map(fn ($l) => [$l->ledger_id, (string) $l->debit, (string) $l->credit])->all());
        $this->assertSame(['12.000', '24000.00'], $this->onHand($this->rice));
        $this->assertSame(1, StockMovement::where('source_type', 'stock_adjustments')->where('source_id', $id)->count(), 'no movement for an unchanged line');
    }

    public function test_posting_asks_for_a_recount_when_stock_moved_since_the_count(): void
    {
        $id = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '16']]))->assertCreated()->json('data.id');
        $this->bill([[$this->oil, '2', '845.00']]);                         // 20 on hand now
        $r = $this->assertProblem($this->req('POST', "/api/v1/stock-adjustments/{$id}/post"), 422, 'validation_failed');
        $this->assertSame(['Soybean oil 5L jar: the system quantity is now 20 (it was 18 when counted); recount and save again'], $r->json('errors')['lines.0.counted_qty']);
        $this->assertSame('draft', StockAdjustment::find($id)->status);

        // Saving again reads the new system quantity
        $this->req('PATCH', "/api/v1/stock-adjustments/{$id}", $this->body([[$this->oil, '19']]), ['If-Match' => 'W/"1"'])->assertOk()
            ->assertJsonPath('data.lines.0.system_qty', '20.000')->assertJsonPath('data.lines.0.difference_qty', '-1.000')->assertJsonPath('data.version', 2);
        $this->req('POST', "/api/v1/stock-adjustments/{$id}/post")->assertOk();
        $this->assertSame('19.000', $this->onHand($this->oil)[0]);
    }

    public function test_reversal_mirrors_the_entry_and_the_movements(): void
    {
        $short = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '16']]))->json('data.id');
        $gain = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->rice, '11']]))->json('data.id');
        foreach ([$short, $gain] as $id) {
            $this->req('POST', "/api/v1/stock-adjustments/{$id}/post")->assertOk();
        }
        $this->assertProblem($this->req('POST', "/api/v1/stock-adjustments/{$short}/reverse", []), 422, 'validation_failed');
        foreach ([$short, $gain] as $id) {
            $this->req('POST', "/api/v1/stock-adjustments/{$id}/reverse", ['reason' => 'Counted the wrong shelf'])->assertOk()->assertJsonPath('data.status', 'reversed');
        }
        $this->assertSame(['18.000', '15210.00'], $this->onHand($this->oil));
        $this->assertSame(['10.000', '20000.00'], $this->onHand($this->rice));
        $this->assertSame(2, JournalEntry::where('entry_type', 'stock_adjustment')->where('status', 'reversed')->count());
        $this->assertSame(2, JournalEntry::where('entry_type', 'reversal')->count());
        $this->assertProblem($this->req('POST', "/api/v1/stock-adjustments/{$short}/reverse", ['reason' => 'Again']), 409, 'already_reversed');
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'stock_adjustments', 'auditable_id' => $short, 'action' => 'reversed']);
    }

    public function test_validation_drafts_and_delete(): void
    {
        $r = $this->assertProblem($this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '-1']], ['reason' => 'lost'])), 422, 'validation_failed');
        $this->assertArrayHasKey('reason', $r->json('errors'));
        $this->assertArrayHasKey('lines.0.counted_qty', $r->json('errors'));
        $service = $this->product('Home delivery', 'pcs', '150.00', 'service');
        $r = $this->assertProblem($this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '1'], [$service, '1'], [$this->oil, '2']])), 422, 'validation_failed');
        $this->assertSame(['Choose a product that keeps stock'], $r->json('errors')['lines.1.product_id']);
        $this->assertSame(['Soybean oil 5L jar is already on line 1'], $r->json('errors')['lines.2.product_id']);
        $this->assertProblem($this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '1']], ['colour' => 'red'])), 422, 'validation_failed');

        $id = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '17']]))->json('data.id');
        $this->assertProblem($this->req('PATCH', "/api/v1/stock-adjustments/{$id}", $this->body([[$this->oil, '17']])), 428, 'if_match_required');
        $this->assertProblem($this->req('PATCH', "/api/v1/stock-adjustments/{$id}", $this->body([[$this->oil, '17']]), ['If-Match' => 'W/"9"']), 409, 'version_conflict');
        $this->req('DELETE', "/api/v1/stock-adjustments/{$id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'stock_adjustments', 'auditable_id' => $id, 'action' => 'voided_number']);
        $next = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '17']]))->json('data');
        $this->assertStringEndsWith('-0002', $next['number'], 'a voided number is never reused');
        $this->req('POST', "/api/v1/stock-adjustments/{$next['id']}/post")->assertOk();
        $this->assertProblem($this->req('DELETE', "/api/v1/stock-adjustments/{$next['id']}"), 409, 'not_draft');
        $this->assertProblem($this->req('PATCH', "/api/v1/stock-adjustments/{$next['id']}", $this->body([[$this->oil, '1']]), ['If-Match' => 'W/"2"']), 409, 'not_draft');
    }

    public function test_list_filters_and_search(): void
    {
        $a = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '17']]))->json('data');
        $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->rice, '9']], ['reason' => 'damaged']))->assertCreated();
        $this->req('POST', "/api/v1/stock-adjustments/{$a['id']}/post")->assertOk();
        $this->req('GET', '/api/v1/stock-adjustments')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.line_count', 1);
        $this->assertSame([$a['number']], array_column($this->req('GET', '/api/v1/stock-adjustments?status=posted')->json('data'), 'number'));
        $this->assertSame(['damaged'], array_column($this->req('GET', '/api/v1/stock-adjustments?q=chinigura')->json('data'), 'reason'));
        $this->assertProblem($this->req('GET', '/api/v1/stock-adjustments?status=cancelled'), 422, 'validation_failed');
    }

    public function test_count_sheet_is_matched_to_products(): void
    {
        $csv = "Code,Counted qty\n{$this->oil->code},16\n{$this->rice->code},\"1,200.5\"\nNOPE-1,3\n";
        $r = $this->call('POST', '/api/v1/stock-adjustments/count-sheet', [], [], ['file' => UploadedFile::fake()->createWithContent('count.csv', $csv)],
            $this->transformHeadersToServerVars(['Authorization' => "Bearer {$this->token}", 'X-Company-Id' => (string) $this->books->id, 'Accept' => 'application/json']))->assertOk();
        $this->assertSame([[$this->oil->id, '16'], [$this->rice->id, '1200.5']], array_map(fn ($l) => [$l['product_id'], $l['counted_qty']], $r->json('data.lines')));
        $this->assertSame(['NOPE-1'], $r->json('data.unknown_codes'));

        $bad = $this->call('POST', '/api/v1/stock-adjustments/count-sheet', [], [], ['file' => UploadedFile::fake()->createWithContent('count.csv', "{$this->oil->code},twelve\n")],
            $this->transformHeadersToServerVars(['Authorization' => "Bearer {$this->token}", 'X-Company-Id' => (string) $this->books->id, 'Accept' => 'application/json']));
        $this->assertSame(['Row 1: "twelve" is not a counted quantity (0 or more, up to 3 decimals)'], $this->assertProblem($bad, 422, 'validation_failed')->json('errors.file'));
        $pdf = $this->call('POST', '/api/v1/stock-adjustments/count-sheet', [], [], ['file' => UploadedFile::fake()->create('count.pdf', 10)],
            $this->transformHeadersToServerVars(['Authorization' => "Bearer {$this->token}", 'X-Company-Id' => (string) $this->books->id, 'Accept' => 'application/json']));
        $this->assertProblem($pdf, 415, 'file_type_not_allowed');
    }

    public function test_permissions_cost_masking_and_isolation(): void
    {
        $this->userWithRole('store', 'store@meghna.example');
        $store = $this->login('store@meghna.example');
        $id = $this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '16']]), [], $store)->assertCreated()->json('data.id');
        $row = $this->req('GET', "/api/v1/stock-adjustments/{$id}", [], [], $store)->assertOk()->json('data');
        $this->assertArrayNotHasKey('net_value', $row);
        $this->assertArrayNotHasKey('posting_preview', $row);
        $this->assertArrayNotHasKey('value', $row['lines'][0]);
        $this->req('POST', "/api/v1/stock-adjustments/{$id}/post", [], [], $store)->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/stock-adjustments/{$id}/reverse", ['reason' => 'x'], [], $store), 403, 'permission_denied');
        $this->userWithRole('viewer', 'viewer@meghna.example');
        $this->assertProblem($this->req('POST', '/api/v1/stock-adjustments', $this->body([[$this->oil, '1']]), [], $this->login('viewer@meghna.example')), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Traders Ltd.', $this->makeUser('other@padma.example'));
        $this->assertProblem($this->api('GET', "/api/v1/stock-adjustments/{$id}", $this->login('other@padma.example'), $other->id), 404, 'not_found');
    }
}
