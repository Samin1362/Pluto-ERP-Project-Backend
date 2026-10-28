<?php

namespace Tests\Feature\Purchases;

use App\Models\DebitNote;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillLine;
use App\Models\PurchaseReturn;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Purchase returns and debit notes: /purchase-returns and /debit-notes (F6, F7, F13; 06 §10; BR-022, BR-057; R-03,
 * R-04; TC-PUR-008, TC-PUR-011, TC-PUR-012; P10.4-BE-01, P10.4-BE-02).
 */
class PurchaseReturnTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $ngj;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->ngj = $this->warehouse('WH-NGJ', 'Narayanganj depot');
        $this->oil = $this->product('Soybean oil 5L jar');
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

    /** A posted bill of `qty` jars at `cost`; returns [bill id, line id]. */
    private function bill(string $qty = '100', string $cost = '846.00', array $line = []): array
    {
        $b = $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'BEO-'.Str::random(5), 'bill_date' => $this->today(),
            'warehouse_id' => $this->ngj->id, 'lines' => [$line + ['product_id' => $this->oil->id, 'quantity' => $qty, 'unit_cost' => $cost]]])->assertCreated()->json('data');
        $this->req('POST', "/api/v1/purchase-bills/{$b['id']}/post")->assertOk();

        return [$b['id'], $b['lines'][0]['id']];
    }

    private function body(int $bill, int $line, string $qty, array $over = []): array
    {
        return $over + ['source_document_id' => $bill, 'return_date' => $this->today(), 'warehouse_id' => $this->ngj->id, 'stock_action' => 'send_back',
            'lines' => [['source_line_id' => $line, 'quantity' => $qty, 'reason' => 'leaking']]];
    }

    private function onHand(): array
    {
        $b = DB::table('stock_balances')->where('product_id', $this->oil->id)->where('warehouse_id', $this->ngj->id)->first();

        return [$b->qty_on_hand, $b->total_value];
    }

    private function payable(): string
    {
        return (string) DB::table('journal_lines')->where('ledger_id', $this->supplier->ledger_id)->where('is_posted', 1)
            ->selectRaw('CAST(SUM(credit) - SUM(debit) AS DECIMAL(18,2)) b')->value('b');
    }

    public function test_tc_pur_008_six_leaking_jars_go_back_at_bill_cost_and_the_supplier_is_debited_once(): void
    {
        [$bill, $line] = $this->bill('100', '846.00');
        $this->assertSame('84600.00', $this->payable());
        $r = $this->req('POST', '/api/v1/purchase-returns', $this->body($bill, $line, '6'))->assertCreated();
        $r->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total', '5076.00')->assertJsonPath('data.lines.0.unit_cost', '846.0000')
            ->assertJsonPath('data.source_number', PurchaseBill::find($bill)->number);
        $id = $r->json('data.id');

        $p = $this->req('POST', "/api/v1/purchase-returns/{$id}/post")->assertOk();
        $p->assertJsonPath('data.status', 'posted')->assertJsonPath('data.note_status', 'posted');
        $this->assertSame(['94.000', '79524.00'], $this->onHand(), 'stock −6 at 846');
        $ret = PurchaseReturn::find($id);
        $note = DebitNote::where('purchase_return_id', $id)->firstOrFail();
        $this->assertSame($ret->journal_entry_id, $note->journal_entry_id, 'R-04: the note shares the return\'s entry');
        $this->assertSame(['posted', 'goods_returned', '5076.00', 'apply'], [$note->status, $note->reason, (string) $note->amount, $note->usage]);
        $this->assertSame('79524.00', $this->payable(), 'debited once: 84,600 − 5,076');
        $this->assertSame(['79524.00', 'part_paid'], [(string) PurchaseBill::find($bill)->balance_due, PurchaseBill::find($bill)->payment_status]);
        $this->assertSame('6.000', (string) PurchaseBillLine::find($line)->returned_quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'purchase_return_out')->count());

        // TC-PUR-012: the return's note cannot be posted (or changed) on its own.
        $this->assertProblem($this->req('POST', "/api/v1/debit-notes/{$note->id}/post"), 409, 'created_from_return');
        $this->assertProblem($this->req('POST', "/api/v1/debit-notes/{$note->id}/cancel", ['reason' => 'x']), 409, 'created_from_return');
        $this->assertProblem($this->req('POST', '/api/v1/purchase-returns', $this->body($bill, $line, '95')), 422, 'return_qty_exceeds');
        $this->assertBooksAreConsistent();
    }

    public function test_tc_pur_011_cancelling_restores_stock_the_supplier_balance_and_cancels_the_note(): void
    {
        [$bill, $line] = $this->bill('100', '846.00');
        $id = $this->req('POST', '/api/v1/purchase-returns', $this->body($bill, $line, '6'))->json('data.id');
        $this->req('POST', "/api/v1/purchase-returns/{$id}/post")->assertOk();

        $c = $this->req('POST', "/api/v1/purchase-returns/{$id}/cancel", ['reason' => 'Supplier refused the jars'])->assertOk();
        $c->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.note_status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Supplier refused the jars');
        $this->assertSame(['100.000', '84600.00'], $this->onHand());
        $this->assertSame('84600.00', $this->payable());
        $this->assertSame('84600.00', (string) PurchaseBill::find($bill)->balance_due);
        $this->assertSame('0.000', (string) PurchaseBillLine::find($line)->returned_quantity);
        $mirror = JournalEntry::where('entry_type', 'cancellation')->where('source_id', $id)->firstOrFail();
        $this->assertStringStartsWith('SJ-', $mirror->voucher_number);
        $this->assertProblem($this->req('POST', "/api/v1/purchase-returns/{$id}/cancel", ['reason' => 'again']), 409, 'already_reversed');
        $this->assertBooksAreConsistent();
    }

    public function test_already_disposed_goods_credit_cogs_and_recoverable_tax_goes_back(): void
    {
        $vat = DB::table('tax_rules')->insertGetId(['company_id' => $this->books->id, 'name' => 'Input VAT 5%', 'rate' => '5.0000', 'applies_to' => 'purchases',
            'is_recoverable' => true, 'is_active' => true, 'ledger_id' => DB::table('ledgers')->where('company_id', $this->books->id)->where('code', 'like', '1%')->value('id'),
            'created_at' => now(), 'updated_at' => now()]);
        [$bill, $line] = $this->bill('10', '1000.00', ['tax_rule_id' => $vat]); // 10,000 + 500 VAT
        $id = $this->req('POST', '/api/v1/purchase-returns', $this->body($bill, $line, '2', ['stock_action' => 'already_disposed', 'usage' => 'credit']))
            ->assertCreated()->assertJsonPath('data.total', '2100.00')->json('data.id');
        $this->req('POST', "/api/v1/purchase-returns/{$id}/post")->assertOk();
        $this->assertSame(['10.000', '10000.00'], $this->onHand(), 'nothing leaves the warehouse');
        $lines = JournalEntry::with('lines')->find(PurchaseReturn::find($id)->journal_entry_id)->lines;
        $this->assertSame('2100.00', (string) $lines->firstWhere('ledger_id', $this->supplier->ledger_id)->debit);
        $this->assertSame('100.00', (string) $lines->firstWhere('tax_rule_id', $vat)->credit, '2/10 of the 500 input VAT');
        $this->assertSame('credit', DebitNote::where('purchase_return_id', $id)->value('usage'));
        $this->assertSame('10500.00', (string) PurchaseBill::find($bill)->balance_due, 'kept as credit, the bill is untouched');
        $this->assertBooksAreConsistent();
    }

    public function test_manual_debit_notes_apply_credit_and_cancel(): void
    {
        [$bill] = $this->bill('10', '846.00'); // 8,460
        $n = $this->req('POST', '/api/v1/debit-notes', ['party_id' => $this->supplier->id, 'note_date' => $this->today(), 'reason' => 'price_difference', 'amount' => '460.00',
            'usage' => 'apply', 'apply_to_document_id' => $bill])->assertCreated()->assertJsonPath('data.kind', 'debit_note')->assertJsonPath('data.status', 'draft');
        $id = $n->json('data.id');
        $this->req('PUT', "/api/v1/debit-notes/{$id}", ['party_id' => $this->supplier->id, 'note_date' => $this->today(), 'reason' => 'price_difference', 'amount' => '400.00',
            'usage' => 'apply', 'apply_to_document_id' => $bill], ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.amount', '400.00');
        $p = $this->req('POST', "/api/v1/debit-notes/{$id}/post")->assertOk();
        $p->assertJsonPath('data.allocations.0.amount', '400.00');
        $this->assertSame(['2101', '5109'], array_map(fn ($l) => substr($l['ledger_code'], 0, 4), $p->json('data.journal')));
        $this->assertSame('8060.00', (string) PurchaseBill::find($bill)->balance_due);

        $this->req('POST', "/api/v1/debit-notes/{$id}/cancel", ['reason' => 'Supplier disputes'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('8460.00', (string) PurchaseBill::find($bill)->balance_due);

        $other = $this->supplier('Padma Traders', '01911000444');
        $this->assertProblem($this->req('POST', '/api/v1/debit-notes', ['party_id' => $other->id, 'note_date' => $this->today(), 'reason' => 'short_supply', 'amount' => '10.00',
            'usage' => 'apply', 'apply_to_document_id' => $bill]), 422, 'foreign_document');
        $this->assertProblem($this->req('POST', '/api/v1/debit-notes', ['party_id' => $this->supplier->id, 'note_date' => $this->today(), 'reason' => 'short_supply',
            'amount' => '10.00', 'usage' => 'apply']), 422, 'validation_failed');
        $c = $this->req('POST', '/api/v1/debit-notes', ['party_id' => $this->supplier->id, 'note_date' => $this->today(), 'reason' => 'quality_claim', 'amount' => '50.00',
            'usage' => 'credit'])->json('data.id');
        $this->req('DELETE', "/api/v1/debit-notes/{$c}")->assertNoContent();
        $this->assertBooksAreConsistent();
    }

    public function test_drafts_list_pdf_permissions_and_isolation(): void
    {
        [$bill, $line] = $this->bill('20', '846.00');
        $this->assertProblem($this->req('POST', '/api/v1/purchase-returns', $this->body($bill, $line, '1', ['stock_action' => 'restock'])), 422, 'validation_failed');
        $id = $this->req('POST', '/api/v1/purchase-returns', $this->body($bill, $line, '3'))->json('data.id');
        $this->req('PUT', "/api/v1/purchase-returns/{$id}", $this->body($bill, $line, '2'), ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.total', '1692.00');
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-returns/{$id}", $this->body($bill, $line, '2'), ['If-Match' => 'W/"1"']), 409, 'version_conflict');
        $gone = $this->req('POST', '/api/v1/purchase-returns', $this->body($bill, $line, '1'))->json('data.id');
        $this->req('DELETE', "/api/v1/purchase-returns/{$gone}")->assertNoContent();
        $this->req('GET', '/api/v1/purchase-returns?reason=leaking&q=Bengal')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.lines', []);
        $this->req('POST', "/api/v1/purchase-returns/{$id}/post")->assertOk();
        $this->assertProblem($this->req('DELETE', "/api/v1/purchase-returns/{$id}"), 409, 'not_draft');
        $this->req('GET', '/api/v1/debit-notes?status=posted')->assertJsonPath('meta.total', 1);
        $note = DebitNote::where('purchase_return_id', $id)->value('id');
        $this->assertStringStartsWith('%PDF', $this->req('GET', "/api/v1/debit-notes/{$note}/pdf")->assertOk()->getContent());

        $this->userWithRole('sales');
        $this->assertProblem($this->req('GET', '/api/v1/purchase-returns', token: $this->login('sales@meghna.example')), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', "/api/v1/debit-notes/{$note}", token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/purchase-returns/{$id}/cancel", ['reason' => 'x'], token: $viewer), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/purchase-returns/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertSame(0, $this->api('GET', '/api/v1/debit-notes', $ot, $other->id)->json('meta.total'));
    }
}
