<?php

namespace Tests\Feature\Sales;

use App\Domain\Purchases\PurchaseBillPostingService;
use App\Domain\Purchases\PurchaseBillService;
use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Sales returns and credit notes: /sales-returns and /credit-notes (E8, E9, E15; 06 §7; BR-021, BR-022, BR-023; R-03;
 * TC-SAL-010, TC-SAL-011, TC-SAL-018; P10.3-BE-01, P10.3-BE-02).
 */
class SalesReturnTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $karim;

    private Party $supplier;

    private Warehouse $tej;

    private Product $oil;

    private string $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->supplier = $this->supplier();
        $this->tej = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $this->year = (string) DB::table('fiscal_years')->where('company_id', $this->books->id)->whereDate('start_date', '<=', $this->today())
            ->whereDate('end_date', '>=', $this->today())->value(DB::raw('YEAR(start_date)'));
        $this->receive($this->oil, '100', '800.00');
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

    private function receive(Product $p, string $qty, string $cost): void
    {
        $this->useBooks();
        Auth::setUser(User::where('email', 'owner@meghna.example')->firstOrFail());
        $bill = app(PurchaseBillService::class)->createDraft(['party_id' => $this->supplier->id, 'supplier_bill_no' => 'SB-'.Str::random(6), 'bill_date' => $this->today(),
            'warehouse_id' => $this->tej->id, 'lines' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost]]]);
        app(PurchaseBillPostingService::class)->post($bill);
    }

    /** A posted invoice of `qty` jars of oil at 940 (tax rule optional); returns [invoice id, line id]. */
    private function invoice(string $qty = '60', array $line = []): array
    {
        $inv = $this->req('POST', '/api/v1/sales-invoices', ['party_id' => $this->karim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->tej->id,
            'lines' => [$line + ['product_id' => $this->oil->id, 'quantity' => $qty]]])->assertCreated()->json('data');
        $this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/post")->assertOk();

        return [$inv['id'], $inv['lines'][0]['id']];
    }

    private function returnBody(int $invoice, int $line, string $qty, array $over = []): array
    {
        return $over + ['source_document_id' => $invoice, 'return_date' => $this->today(), 'warehouse_id' => $this->tej->id, 'stock_action' => 'restock',
            'lines' => [['source_line_id' => $line, 'quantity' => $qty, 'reason' => 'leaking']]];
    }

    private function onHand(): array
    {
        $b = DB::table('stock_balances')->where('product_id', $this->oil->id)->where('warehouse_id', $this->tej->id)->first();

        return [$b->qty_on_hand, $b->total_value];
    }

    public function test_tc_sal_010_restock_brings_goods_back_at_the_original_cost_and_creates_a_draft_credit_note(): void
    {
        [$inv, $line] = $this->invoice('60');
        $this->assertSame(['40.000', '32000.00'], $this->onHand());
        $r = $this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '12', ['notes' => 'Two cartons leaking']))->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.number', "SR-{$this->year}-0001")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.source_document_id', $inv)
            ->assertJsonPath('data.party.name', 'Karim Distributors')->assertJsonPath('data.total', '11280.00')->assertJsonPath('data.lines.0.value', '11280.00')
            ->assertJsonPath('data.lines.0.reason', 'leaking')->assertJsonPath('data.lines.0.product_name', 'Soybean oil 5L jar')->assertJsonPath('data.note_id', null);
        $this->assertSame(['40.000', '32000.00'], $this->onHand(), 'a draft moves nothing');

        $p = $this->req('POST', '/api/v1/sales-returns/'.$r->json('data.id').'/post')->assertOk();
        $p->assertJsonPath('data.status', 'posted')->assertJsonPath('data.lines.0.unit_cost', '800.0000')->assertJsonPath('data.note_number', "CN-{$this->year}-0001")
            ->assertJsonPath('data.note_status', 'draft');
        $this->assertSame(['52.000', '41600.00'], $this->onHand(), 'stock +12 at the original cost (BR-017)');
        $entry = JournalEntry::with('lines')->findOrFail(SalesReturn::find($r->json('data.id'))->journal_entry_id);
        $this->assertSame('sales_return', $entry->entry_type);
        $this->assertEquals(['9600.00', '9600.00'], [(string) $entry->total_debit, (string) $entry->total_credit], 'Dr inventory / Cr COGS = 12 × 800');
        $this->assertSame('12.000', (string) SalesInvoiceLine::find($line)->returned_quantity);
        $note = CreditNote::where('sales_return_id', $r->json('data.id'))->firstOrFail();
        $this->assertSame(['draft', 'goods_returned', '11280.00', 'apply'], [$note->status, $note->reason, (string) $note->amount, $note->usage]);

        $this->assertProblem($this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '50')), 422, 'return_qty_exceeds');
        $this->assertProblem($this->req('POST', '/api/v1/sales-returns/'.$r->json('data.id').'/post'), 409, 'already_posted');
        $this->assertBooksAreConsistent();
    }

    public function test_a_write_off_moves_no_stock_and_posts_nothing_until_the_credit_note(): void
    {
        [$inv, $line] = $this->invoice('10');
        $id = $this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '2', ['stock_action' => 'write_off', 'usage' => 'credit']))->json('data.id');
        $this->req('POST', "/api/v1/sales-returns/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertNull(SalesReturn::find($id)->journal_entry_id);
        $this->assertSame(['90.000', '72000.00'], $this->onHand());
        $this->assertSame('credit', CreditNote::where('sales_return_id', $id)->value('usage'), 'the usage the return asked for');
    }

    public function test_tc_sal_011_credit_note_apply_credit_and_refund(): void
    {
        [$inv, $line] = $this->invoice('20'); // 18,800 due
        $ret = $this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '2', ['stock_action' => 'write_off']))->json('data.id');
        $this->req('POST', "/api/v1/sales-returns/{$ret}/post")->assertOk();
        $note = CreditNote::where('sales_return_id', $ret)->firstOrFail();

        // apply: Dr 4109 / Cr customer, then allocated to the invoice
        $n = $this->req('POST', "/api/v1/credit-notes/{$note->id}/post")->assertOk();
        $n->assertJsonPath('data.status', 'posted')->assertJsonPath('data.usage', 'apply')->assertJsonPath('data.apply_to_document_id', $inv)
            ->assertJsonPath('data.return_number', "SR-{$this->year}-0001")->assertJsonPath('data.allocations.0.amount', '1880.00');
        $this->assertSame(['4109', '1201'], array_map(fn ($l) => substr($l['ledger_code'], 0, 4), $n->json('data.journal')));
        $i = SalesInvoice::find($inv);
        $this->assertSame(['16920.00', 'part_paid'], [(string) $i->balance_due, $i->payment_status]);

        // credit: a hand-made goodwill note stays on the customer's account
        $c = $this->req('POST', '/api/v1/credit-notes', ['party_id' => $this->karim->id, 'note_date' => $this->today(), 'reason' => 'goodwill', 'amount' => '500.00',
            'usage' => 'credit'])->assertCreated()->assertJsonPath('data.return_id', null);
        $this->req('POST', '/api/v1/credit-notes/'.$c->json('data.id').'/post')->assertOk()->assertJsonPath('data.allocations', []);
        $this->assertSame('16920.00', (string) SalesInvoice::find($inv)->balance_due);

        // refund: needs the refund block; posts a PV Dr customer / Cr money account
        $this->assertProblem($this->req('POST', '/api/v1/credit-notes', ['party_id' => $this->karim->id, 'note_date' => $this->today(), 'reason' => 'price_correction',
            'amount' => '300.00', 'usage' => 'refund']), 422, 'validation_failed')->assertJsonPath('errors', ['refund.money_account_id' => ['Choose the account the refund is paid from']]);
        $cash = MoneyAccount::findOrFail($this->req('POST', '/api/v1/money-accounts', ['account_type' => 'cash', 'name' => 'Cash in hand', 'opening_balance' => '5000.00'])
            ->assertCreated()->json('data.id'));
        $r = $this->req('POST', '/api/v1/credit-notes', ['party_id' => $this->karim->id, 'note_date' => $this->today(), 'reason' => 'price_correction', 'amount' => '300.00',
            'usage' => 'refund', 'refund' => ['money_account_id' => $cash->id, 'method' => 'cash']])->assertCreated();
        $this->req('POST', '/api/v1/credit-notes/'.$r->json('data.id').'/post')->assertOk();
        $pv = JournalEntry::where('entry_type', 'payment')->where('source_id', $r->json('data.id'))->firstOrFail();
        $this->assertStringStartsWith('PV-', $pv->voucher_number);
        $this->assertSame('300.00', (string) DB::table('payment_details')->where('journal_entry_id', $pv->id)->value('amount'));
        $this->assertBooksAreConsistent();
    }

    public function test_tc_sal_018_cancelling_an_applied_credit_note_reopens_the_invoice(): void
    {
        [$inv] = $this->invoice('10'); // 9,400
        $n = $this->req('POST', '/api/v1/credit-notes', ['party_id' => $this->karim->id, 'note_date' => $this->today(), 'reason' => 'price_correction', 'amount' => '400.00',
            'usage' => 'apply', 'apply_to_document_id' => $inv])->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/credit-notes/{$n}/post")->assertOk();
        $this->assertSame('9000.00', (string) SalesInvoice::find($inv)->balance_due);

        $c = $this->req('POST', "/api/v1/credit-notes/{$n}/cancel", ['reason' => 'Price was right'])->assertOk();
        $c->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Price was right')->assertJsonPath('data.allocations.0.reversed', true);
        $i = SalesInvoice::find($inv);
        $this->assertSame(['9400.00', 'unpaid'], [(string) $i->balance_due, $i->payment_status]);
        $mirror = JournalEntry::where('entry_type', 'cancellation')->where('source_id', $n)->firstOrFail();
        $this->assertStringStartsWith('SJ-', $mirror->voucher_number);
        $this->assertSame('reversed', JournalEntry::find(CreditNote::find($n)->journal_entry_id)->status);
        $this->assertProblem($this->req('POST', "/api/v1/credit-notes/{$n}/cancel", ['reason' => 'again']), 409, 'already_reversed');

        // A note bigger than the invoice's balance cannot be applied (BR-023).
        $big = $this->req('POST', '/api/v1/credit-notes', ['party_id' => $this->karim->id, 'note_date' => $this->today(), 'reason' => 'goodwill', 'amount' => '99999.00',
            'usage' => 'apply', 'apply_to_document_id' => $inv])->json('data.id');
        $this->assertProblem($this->req('POST', "/api/v1/credit-notes/{$big}/post"), 422, 'allocation_exceeds_balance');
        $this->assertSame('draft', CreditNote::find($big)->status, 'nothing is kept when applying fails');
        $this->assertBooksAreConsistent();
    }

    public function test_cancelling_a_return_takes_the_stock_back_and_respects_its_credit_note(): void
    {
        [$inv, $line] = $this->invoice('20');
        $ret = $this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '5'))->json('data.id');
        $this->req('POST', "/api/v1/sales-returns/{$ret}/post")->assertOk();
        $note = CreditNote::where('sales_return_id', $ret)->firstOrFail();
        $this->req('POST', "/api/v1/credit-notes/{$note->id}/post")->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/sales-returns/{$ret}/cancel", ['reason' => 'Wrong items counted']), 409, 'note_applied');
        $this->assertProblem($this->req('DELETE', "/api/v1/credit-notes/{$note->id}"), 409, 'not_draft');

        $this->req('POST', "/api/v1/credit-notes/{$note->id}/cancel", ['reason' => 'Return was wrong'])->assertOk();
        $c = $this->req('POST', "/api/v1/sales-returns/{$ret}/cancel", ['reason' => 'Wrong items counted'])->assertOk();
        $c->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Wrong items counted');
        $this->assertSame(['80.000', '64000.00'], $this->onHand(), 'the 5 jars leave again at the cost they came back at');
        $this->assertSame('0.000', (string) SalesInvoiceLine::find($line)->returned_quantity);
        $this->assertSame('reversed', JournalEntry::find(SalesReturn::find($ret)->journal_entry_id)->status);

        // A return whose note is still a draft cancels it along.
        $r2 = $this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '1'))->json('data.id');
        $this->req('POST', "/api/v1/sales-returns/{$r2}/post")->assertOk();
        $n2 = CreditNote::where('sales_return_id', $r2)->firstOrFail();
        $this->assertProblem($this->req('DELETE', "/api/v1/credit-notes/{$n2->id}"), 409, 'invalid_transition');
        $this->req('POST', "/api/v1/sales-returns/{$r2}/cancel", ['reason' => 'Customer kept it'])->assertOk();
        $this->assertSame('cancelled', CreditNote::find($n2->id)->status);
        $this->assertBooksAreConsistent();
    }

    public function test_drafts_validation_list_pdf_permissions_and_isolation(): void
    {
        [$inv, $line] = $this->invoice('10');
        $draft = $this->req('POST', '/api/v1/sales-invoices', ['party_id' => $this->karim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->tej->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '1']]])->json('data');
        $this->assertProblem($this->req('POST', '/api/v1/sales-returns', $this->returnBody($draft['id'], $draft['lines'][0]['id'], '1')), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $draft['lines'][0]['id'], '1')), 422, 'foreign_document');
        $this->assertProblem($this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '1', ['lines' => [['source_line_id' => $line, 'quantity' => '1',
            'reason' => 'bored']]])), 422, 'validation_failed');

        $id = $this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '3'))->json('data.id');
        $this->assertProblem($this->req('PUT', "/api/v1/sales-returns/{$id}", $this->returnBody($inv, $line, '2')), 428, 'if_match_required');
        $this->req('PUT', "/api/v1/sales-returns/{$id}", $this->returnBody($inv, $line, '2'), ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.total', '1880.00');
        $this->assertProblem($this->req('PUT', "/api/v1/sales-returns/{$id}", $this->returnBody($inv, $line, '2'), ['If-Match' => 'W/"1"']), 409, 'version_conflict');
        $gone = $this->req('POST', '/api/v1/sales-returns', $this->returnBody($inv, $line, '1'))->json('data.id');
        $this->req('DELETE', "/api/v1/sales-returns/{$gone}")->assertNoContent();

        $this->req('GET', '/api/v1/sales-returns')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.lines', []);
        $this->req('GET', '/api/v1/sales-returns?reason=leaking&q=Karim')->assertJsonPath('meta.total', 1);
        $this->req('GET', '/api/v1/sales-returns?reason=expired')->assertJsonPath('meta.total', 0);
        $this->req('POST', "/api/v1/sales-returns/{$id}/post")->assertOk();
        $this->req('GET', '/api/v1/credit-notes?status=draft&usage=apply')->assertJsonPath('meta.total', 1);
        $note = CreditNote::where('sales_return_id', $id)->value('id');
        $this->assertStringStartsWith('%PDF', $this->req('GET', "/api/v1/credit-notes/{$note}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());

        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', "/api/v1/sales-returns/{$id}", token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/credit-notes/{$note}/post", token: $viewer), 403, 'permission_denied');
        $this->userWithRole('store');
        $this->assertProblem($this->req('GET', '/api/v1/credit-notes', token: $this->login('store@meghna.example')), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/sales-returns/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('GET', "/api/v1/credit-notes/{$note}", $ot, $other->id), 404, 'not_found');
        $this->assertSame(0, $this->api('GET', '/api/v1/credit-notes', $ot, $other->id)->json('meta.total'));
    }
}
