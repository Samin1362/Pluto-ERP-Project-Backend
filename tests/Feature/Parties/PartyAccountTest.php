<?php

namespace Tests\Feature\Parties;

use App\Domain\Banking\MoneyAccountService;
use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoiceService;
use App\Mail\PartyStatementMail;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Party ledger, transactions, outstanding/aging and statements (B5–B8, C5–C8; FR-023, FR-024; 06 §17; backlog P6.1-BE-05). */
class PartyAccountTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $trip;

    private MoneyAccount $cash;

    /** @var array<string, SalesInvoice> */
    private array $inv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->trip = $this->product('Delivery trip', 'pcs', '1.00', 'service');
        $this->useBooks();
        $this->cash = app(MoneyAccountService::class)->create(['account_type' => 'cash']);
        // Three posted invoices: 10,000 due 40 days ago, 5,000 due 10 days ago, 3,000 not due; then a 4,000 receipt, oldest first.
        $this->inv['old'] = $this->invoice('10000', -40);
        $this->inv['mid'] = $this->invoice('5000', -10);
        $this->inv['new'] = $this->invoice('3000', 20);
        $this->req('POST', '/api/v1/customer-receipts', ['party_id' => $this->karim->id, 'date' => $this->today(), 'amount' => '4000.00',
            'money_account_id' => $this->cash->id, 'method' => 'cash', 'auto_allocate' => true])->assertCreated();
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function invoice(string $amount, int $dueInDays, ?Party $to = null): SalesInvoice
    {
        $this->useBooks();
        $inv = app(SalesInvoiceService::class)->createDraft(['party_id' => ($to ?? $this->karim)->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'due_date' => CarbonImmutable::parse($this->today())->addDays(max($dueInDays, 0))->toDateString(),
            'lines' => [['product_id' => $this->trip->id, 'quantity' => $amount, 'unit_price' => '1.00']]]);
        $inv = app(SalesInvoicePostingService::class)->post($inv);
        if ($dueInDays < 0) { // dates are not money: move the due date into the past for aging
            DB::table('sales_invoices')->where('id', $inv->id)->update(['invoice_date' => CarbonImmutable::parse($this->today())->addDays($dueInDays - 1)->toDateString(),
                'due_date' => CarbonImmutable::parse($this->today())->addDays($dueInDays)->toDateString()]);
        }

        return $inv->refresh();
    }

    public function test_the_ledger_runs_its_balance_and_closes_at_the_ledgers_trial_balance(): void
    {
        $r = $this->req('GET', "/api/v1/parties/{$this->karim->id}/ledger")->assertOk();
        $r->assertJsonPath('data.ledger.code', $this->karim->ledger->code)->assertJsonPath('data.opening', '0.00')->assertJsonPath('data.opening_side', 'dr')
            ->assertJsonPath('data.total_debit', '18000.00')->assertJsonPath('data.total_credit', '4000.00')->assertJsonPath('data.closing', '14000.00')
            ->assertJsonPath('data.closing_side', 'dr')->assertJsonPath('data.period.to', $this->today());
        $this->assertSame(['10000.00', '15000.00', '18000.00', '14000.00'], array_column($r->json('data.lines'), 'balance'));
        $this->assertSame(['sales_invoice', 'sales_invoice', 'sales_invoice', 'receipt'], array_column($r->json('data.lines'), 'entry_type'));
        $this->assertSame('Cash in hand', $r->json('data.lines.3.particulars'));
        $tb = DB::table('journal_lines')->where('ledger_id', $this->karim->ledger_id)->where('is_posted', 1)->selectRaw('SUM(debit) - SUM(credit) b')->value('b');
        $this->assertSame('14000.00', (string) $tb, 'running balance = trial balance of the party ledger');
        $this->assertSame([['month' => substr($this->today(), 0, 7), 'debit' => '18000.00', 'credit' => '4000.00', 'closing' => '14000.00', 'closing_side' => 'dr']], $r->json('data.monthly'));

        // A range after today: everything is in the opening balance.
        $tomorrow = CarbonImmutable::parse($this->today())->addDay()->toDateString();
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/ledger?date_from={$tomorrow}&date_to={$tomorrow}")->assertJsonPath('data.opening', '14000.00')
            ->assertJsonPath('data.lines', [])->assertJsonPath('data.closing', '14000.00');
        $this->assertProblem($this->req('GET', "/api/v1/parties/{$this->karim->id}/ledger?colour=red"), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', "/api/v1/parties/{$this->karim->id}/ledger?date_from=2026-10-10&date_to=2026-10-01"), 422, 'validation_failed');
    }

    public function test_transactions_list_documents_and_receipts_with_status(): void
    {
        $r = $this->req('GET', "/api/v1/parties/{$this->karim->id}/transactions")->assertOk();
        $r->assertJsonPath('meta.total', 4);
        $byNumber = collect($r->json('data'))->keyBy('number');
        $this->assertSame('part_paid', $byNumber[$this->inv['old']->number]['status']);
        $this->assertSame(['unpaid', '/sales/invoices/'.$this->inv['new']->id], [$byNumber[$this->inv['new']->number]['status'], $byNumber[$this->inv['new']->number]['url']]);
        $receipt = collect($r->json('data'))->firstWhere('type', 'receipt');
        $this->assertSame(['4000.00', 'applied'], [$receipt['amount'], $receipt['status']]);
        $this->assertSame([$receipt['number']], array_column($this->req('GET', "/api/v1/parties/{$this->karim->id}/transactions?document_type=receipt")->json('data'), 'number'));
        $this->assertSame([$this->inv['mid']->number], array_column($this->req('GET', "/api/v1/parties/{$this->karim->id}/transactions?q=".urlencode($this->inv['mid']->number))->json('data'), 'number'));
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/transactions?per_page=2&page=2")->assertJsonPath('meta.last_page', 2)->assertJsonCount(2, 'data');
    }

    public function test_outstanding_buckets_by_days_past_due_with_advances_apart(): void
    {
        $rahman = $this->customer('Rahman Super Shop', '01811000222');
        $this->invoice('700', -100, $rahman);
        $this->req('POST', '/api/v1/customer-receipts', ['party_id' => $rahman->id, 'date' => $this->today(), 'amount' => '200.00', 'money_account_id' => $this->cash->id, 'method' => 'cash'])->assertCreated();

        $r = $this->req('GET', '/api/v1/parties/outstanding?type=customer')->assertOk();
        $buckets = collect($r->json('data.buckets'))->pluck('amount', 'key')->all();
        // Karim: 10,000 − 4,000 = 6,000 (40 days, 31–60) · 5,000 (10 days, 1–30) · 3,000 not due. Rahman: 700 over 90 days, advance 200.
        $this->assertSame(['not_due' => '3000.00', 'd1_30' => '5000.00', 'd31_60' => '6000.00', 'd61_90' => '0.00', 'd90_plus' => '700.00', 'total' => '14700.00'], $buckets);
        $this->assertSame(['Karim Distributors', 'Rahman Super Shop'], array_column(array_column($r->json('data.rows'), 'party'), 'name'), 'largest total first');
        $this->assertSame('200.00', collect($r->json('data.rows'))->firstWhere('party.name', 'Rahman Super Shop')['advance']);
        $r->assertJsonPath('data.totals.advance', '200.00')->assertJsonPath('data.totals.parties', 2);
        $this->assertSame(['Rahman Super Shop'], array_column(array_column($this->req('GET', '/api/v1/parties/outstanding?bucket=d90_plus')->json('data.rows'), 'party'), 'name'));
        $this->assertSame([], $this->req('GET', '/api/v1/parties/outstanding?type=supplier')->json('data.rows'));
        // As of 30 days on, the not-due invoice is 10 days late.
        $later = CarbonImmutable::parse($this->today())->addDays(30)->toDateString();
        $this->assertSame('3000.00', collect($this->req('GET', "/api/v1/parties/outstanding?as_of={$later}")->json('data.buckets'))->firstWhere('key', 'd1_30')['amount']);
        $this->assertProblem($this->req('GET', '/api/v1/parties/outstanding?bucket=d200'), 422, 'validation_failed');
    }

    public function test_statement_json_pdf_and_send(): void
    {
        $r = $this->req('GET', "/api/v1/parties/{$this->karim->id}/statement?aging=true")->assertOk();
        $r->assertJsonPath('data.statement_no', 'ST-CUS-0001-'.str_replace('-', '', $this->today()))->assertJsonPath('data.party.name', 'Karim Distributors')
            ->assertJsonPath('data.summary.closing', '14000.00')->assertJsonPath('data.summary.closing_side', 'dr')->assertJsonCount(4, 'data.lines')
            ->assertJsonPath('data.aging.5.key', 'total')->assertJsonPath('data.aging.5.amount', '14000.00');
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/statement")->assertJsonPath('data.aging', null);

        $pdf = $this->req('GET', "/api/v1/parties/{$this->karim->id}/statement/pdf?aging=true")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $html = view('pdf.party-statement', ['s' => $r->json('data'), 'party' => $this->karim, 'company' => $this->books])->render();
        foreach (['Statement of account', 'Karim Distributors', 'Opening balance', '14,000.00', 'You owe us', '31–60 days'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }

        Mail::fake();
        $this->karim->update(['email' => 'accounts@karim.example']);
        $rahman = $this->customer('Rahman Super Shop', '01811000222');
        $job = $this->req('POST', '/api/v1/parties/statements/send', ['party_ids' => [$this->karim->id, $rahman->id], 'date_from' => $this->today(), 'date_to' => $this->today(),
            'include_aging' => true])->assertStatus(202);
        $job->assertJsonPath('data.kind', 'report')->assertJsonPath('data.status', 'complete')->assertJsonPath('data.error', 'No e-mail on file for: CUS-0002');
        Mail::assertQueued(PartyStatementMail::class, fn (PartyStatementMail $m) => $m->hasTo('accounts@karim.example') && str_starts_with(base64_decode($m->pdf), '%PDF'));
        Mail::assertQueuedCount(1);
        $this->assertProblem($this->req('POST', '/api/v1/parties/statements/send', ['party_ids' => [], 'date_from' => $this->today(), 'date_to' => $this->today()]), 422, 'validation_failed');
    }

    public function test_permissions_and_isolation(): void
    {
        $this->userWithRole('sales');
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/ledger", token: $this->login('sales@meghna.example'))->assertOk();
        $this->userWithRole('store');
        $store = $this->login('store@meghna.example');
        foreach (["/api/v1/parties/{$this->karim->id}/ledger", "/api/v1/parties/{$this->karim->id}/statement", '/api/v1/parties/outstanding'] as $u) {
            $this->assertProblem($this->req('GET', $u, token: $store), 403, 'permission_denied');
        }
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/parties/{$this->karim->id}/ledger", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('GET', "/api/v1/parties/{$this->karim->id}/statement/pdf", $ot, $other->id), 404, 'not_found');
        $this->assertSame([], $this->api('GET', '/api/v1/parties/outstanding', $ot, $other->id)->json('data.rows'));
        $this->useBooks();
    }
}
