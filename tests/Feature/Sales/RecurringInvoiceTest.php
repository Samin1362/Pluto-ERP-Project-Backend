<?php

namespace Tests\Feature\Sales;

use App\Domain\Sales\RecurringInvoiceService;
use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoiceService;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Recurring sales invoices (PEP-517): repeat an invoice; the daily run writes draft invoices (never posted); pause, change, end. */
class RecurringInvoiceTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $rent;

    private SalesInvoice $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->rent = $this->product('Shop rent', 'pcs', '25000.00', 'service');
        $this->useBooks();
        $today = $this->today();
        $this->inv = app(SalesInvoiceService::class)->createDraft(['party_id' => $this->karim->id, 'invoice_date' => $today, 'warehouse_id' => $this->wh->id,
            'due_date' => CarbonImmutable::parse($today)->addDays(10)->toDateString(), 'notes' => 'Rent for the shop',
            'lines' => [['product_id' => $this->rent->id, 'quantity' => '1', 'unit_price' => '25000.00', 'description' => 'Monthly rent']]]);
        $this->inv = app(SalesInvoicePostingService::class)->post($this->inv)->refresh();
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null, array $headers = [])
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, ($method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []) + $headers);
        $this->useBooks();

        return $r;
    }

    /** The daily job as it runs on that day (the clock moves there and back; tokens stay valid). */
    private function runOn(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 06:00', 'Asia/Dhaka'));
        $this->artisan('pluto:recurring:run')->assertSuccessful();
        $this->travelBack();
    }

    private function repeat(array $body = [])
    {
        return $this->req('POST', "/api/v1/sales-invoices/{$this->inv->id}/repeat", $body + ['frequency' => 'monthly',
            'next_run_on' => CarbonImmutable::parse($this->today())->addMonthNoOverflow()->toDateString(), 'ends_type' => 'never']);
    }

    public function test_repeating_an_invoice_writes_a_draft_on_each_run_date_and_never_posts(): void
    {
        $r = $this->repeat()->assertCreated();
        $r->assertJsonPath('data.party.name', 'Karim Distributors')->assertJsonPath('data.source_invoice_number', $this->inv->number)
            ->assertJsonPath('data.frequency', 'monthly')->assertJsonPath('data.occurrences_done', 1)->assertJsonPath('data.lines_count', 1)
            ->assertJsonPath('data.is_active', true);
        $id = $r->json('data.id');

        // Nothing is due yet today.
        $this->artisan('pluto:recurring:run')->assertSuccessful();
        $this->useBooks();
        $this->assertSame(1, SalesInvoice::count());

        // Two months later: two drafts (one per missed date), dated on the run dates, due 10 days after each.
        $later = CarbonImmutable::parse($this->today())->addMonthsNoOverflow(2);
        $this->runOn($later->toDateString());
        $this->useBooks();
        $drafts = SalesInvoice::where('status', 'draft')->orderBy('id')->get();
        $this->assertCount(2, $drafts);
        $first = CarbonImmutable::parse($this->today())->addMonthNoOverflow();
        $this->assertSame([$first->toDateString(), $first->addDays(10)->toDateString()], [$drafts[0]->invoice_date->toDateString(), $drafts[0]->due_date->toDateString()]);
        $this->assertSame(['25000.00', 'Rent for the shop', 'Monthly rent'], [(string) $drafts[0]->total, $drafts[0]->notes, $drafts[0]->lines->first()->description]);
        $this->assertSame((int) $this->karim->id, (int) $drafts[1]->party_id);
        $this->assertSame(1, SalesInvoice::where('status', 'posted')->count(), 'runs never post');

        $list = $this->req('GET', '/api/v1/recurring-invoices')->assertOk();
        $list->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.occurrences_done', 3)->assertJsonPath('data.0.last_invoice_number', $drafts[1]->number)
            ->assertJsonPath('data.0.next_run_on', RecurringInvoiceService::step($later, 'monthly')->toDateString());
    }

    public function test_pause_change_and_end(): void
    {
        $r = $this->repeat(['ends_type' => 'after_count', 'ends_after_count' => 2])->assertCreated();
        $id = $r->json('data.id');
        $patch = fn (array $body, int $v) => $this->req('PATCH', "/api/v1/recurring-invoices/{$id}", $body, headers: ['If-Match' => "W/\"{$v}\""]);

        $p = $patch(['is_active' => false], (int) $r->json('data.version'))->assertOk()->assertJsonPath('data.is_active', false);
        $this->runOn(CarbonImmutable::parse($this->today())->addMonthsNoOverflow(3)->toDateString());
        $this->useBooks();
        $this->assertSame(0, SalesInvoice::where('status', 'draft')->count(), 'paused: no drafts');
        $this->assertProblem($patch(['is_active' => true], (int) $r->json('data.version')), 409, 'version_conflict');

        $p = $patch(['is_active' => true, 'frequency' => 'quarterly'], (int) $p->json('data.version'))->assertOk()->assertJsonPath('data.frequency', 'quarterly');
        // after_count 2: the original plus one draft, then it ends.
        $this->runOn(CarbonImmutable::parse($this->today())->addYear()->toDateString());
        $this->useBooks();
        $this->assertSame(1, SalesInvoice::where('status', 'draft')->count());
        $this->req('GET', '/api/v1/recurring-invoices?active=false')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.0.occurrences_done', 2);
        $this->assertProblem($patch(['ends_type' => 'after_count', 'ends_after_count' => null], (int) $this->req('GET', '/api/v1/recurring-invoices')->json('data.0.version')),
            422, 'validation_failed');
    }

    public function test_validation_and_a_skipped_run(): void
    {
        $this->assertProblem($this->repeat(['next_run_on' => $this->today()]), 422, 'validation_failed');
        $this->assertProblem($this->repeat(['ends_type' => 'on_date']), 422, 'validation_failed');
        $this->assertProblem($this->repeat(['frequency' => 'daily']), 422, 'validation_failed');
        DB::table('sales_invoices')->where('id', $this->inv->id)->update(['status' => 'cancelled']);
        $this->assertProblem($this->repeat(), 422, 'validation_failed');
        DB::table('sales_invoices')->where('id', $this->inv->id)->update(['status' => 'posted']);

        // A run date in a locked period is skipped and stays due; the job still succeeds.
        $this->repeat()->assertCreated();
        $next = CarbonImmutable::parse($this->today())->addMonthNoOverflow();
        DB::table('accounting_periods')->where('company_id', $this->books->id)->where('start_date', '<=', $next->toDateString())->where('end_date', '>=', $next->toDateString())
            ->update(['status' => 'locked']);
        $this->runOn($next->toDateString());
        $this->useBooks();
        $this->assertSame(0, SalesInvoice::where('status', 'draft')->count());
        $this->req('GET', '/api/v1/recurring-invoices')->assertJsonPath('data.0.next_run_on', $next->toDateString())->assertJsonPath('data.0.is_active', true);
    }

    public function test_permissions_and_isolation(): void
    {
        $id = $this->repeat()->assertCreated()->json('data.id');
        $this->userWithRole('store');
        $store = $this->login('store@meghna.example');
        $this->assertProblem($this->req('GET', '/api/v1/recurring-invoices', token: $store), 403, 'permission_denied');
        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$this->inv->id}/repeat", ['frequency' => 'monthly', 'next_run_on' => '2099-01-01', 'ends_type' => 'never'], $store),
            403, 'permission_denied');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertSame([], $this->api('GET', '/api/v1/recurring-invoices', $ot, $other->id)->assertOk()->json('data'));
        $this->assertProblem($this->api('PATCH', "/api/v1/recurring-invoices/{$id}", $ot, $other->id, ['is_active' => false], ['If-Match' => 'W/"1"']), 404, 'not_found');
        $this->assertProblem($this->api('POST', "/api/v1/sales-invoices/{$this->inv->id}/repeat", $ot, $other->id,
            ['frequency' => 'monthly', 'next_run_on' => '2099-01-01', 'ends_type' => 'never'], ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->useBooks();
    }
}
