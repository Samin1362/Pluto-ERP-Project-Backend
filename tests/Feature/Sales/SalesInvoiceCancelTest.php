<?php

namespace Tests\Feature\Sales;

use App\Domain\Purchases\PurchaseBillPostingService;
use App\Domain\Purchases\PurchaseBillService;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
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
 * POST /sales-invoices/{id}/cancel, POST /sales-invoices/bulk-post, PATCH /sales-invoices/{id}/delivery (06 §15; BR-003,
 * BR-004, BR-009, BR-014, BR-024; R-31, GAP-12; TC-SAL-012 (stock and journal part), TC-SAL-016; P5.1-BE-04).
 */
class SalesInvoiceCancelTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $karim;

    private Party $supplier;

    private Warehouse $tej;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->supplier = $this->supplier();
        $this->tej = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
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
        app(PurchaseBillPostingService::class)->post(app(PurchaseBillService::class)->createDraft(['party_id' => $this->supplier->id, 'supplier_bill_no' => 'SB-'.Str::random(6),
            'bill_date' => $this->today(), 'warehouse_id' => $this->tej->id, 'lines' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost]]]));
    }

    private function draft(string $qty = '10', ?Product $p = null): array
    {
        return $this->req('POST', '/api/v1/sales-invoices', ['party_id' => $this->karim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->tej->id,
            'lines' => [['product_id' => ($p ?? $this->oil)->id, 'quantity' => $qty]]])->assertCreated()->json('data');
    }

    private function posted(string $qty = '10'): array
    {
        $inv = $this->draft($qty);

        return $this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/post")->assertOk()->json('data');
    }

    private function onHand(): array
    {
        $b = DB::table('stock_balances')->where('product_id', $this->oil->id)->where('warehouse_id', $this->tej->id)->first();

        return [$b->qty_on_hand, $b->total_value, $b->avg_unit_cost];
    }

    public function test_tc_sal_012_cancel_reverses_the_journal_and_cogs_and_brings_the_stock_back(): void
    {
        $this->receive($this->oil, '100', '800.00');
        $this->receive($this->oil, '50', '860.00'); // average 820.0000
        $inv = $this->posted('40');
        $this->assertSame(['110.000', '90200.00', '820.0000'], $this->onHand());
        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel"), 422, 'validation_failed')->assertJsonPath('errors.reason.0', 'Choose why it is cancelled');

        $r = $this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel", ['reason' => 'customer_cancelled', 'note' => 'Order withdrawn by phone'])->assertOk();
        $r->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'customer_cancelled')->assertJsonPath('data.balance_due', '0.00')
            ->assertJsonPath('data.number', $inv['number'], 'the number is kept');
        $this->assertSame(['150.000', '123000.00', '820.0000'], $this->onHand(), 'stock back at the cost it left at');

        $saved = SalesInvoice::findOrFail($inv['id']);
        $original = JournalEntry::with('lines')->findOrFail($saved->journal_entry_id);
        $cancel = JournalEntry::with('lines')->findOrFail($saved->cancellation_entry_id);
        $this->assertSame(['reversed', 'cancellation', $original->id], [$original->status, $cancel->entry_type, $cancel->reverses_entry_id]);
        $swap = fn ($lines) => $lines->map(fn ($l) => [$l->ledger_id, (string) $l->debit, (string) $l->credit])->sort()->values()->all();
        $this->assertSame($swap($original->lines), $cancel->lines->map(fn ($l) => [$l->ledger_id, (string) $l->credit, (string) $l->debit])->sort()->values()->all(),
            'every line, COGS included, reversed');
        $back = StockMovement::where('source_type', 'sales_invoices')->where('source_id', $inv['id'])->where('movement_type', 'reversal')->sole();
        $this->assertSame(['40.000', '32800.00', $cancel->id], [(string) $back->quantity_in, (string) $back->value_in, $back->journal_entry_id]);
        $this->assertTrue(AuditLog::where('action', 'cancelled')->where('auditable_type', 'sales_invoices')->where('auditable_id', $inv['id'])->exists());
        $this->req('GET', "/api/v1/parties/{$this->karim->id}")->assertJsonPath('data.balance', '0.00');

        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel", ['reason' => 'duplicate']), 409, 'already_reversed');
        $draft = $this->draft('1');
        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$draft['id']}/cancel", ['reason' => 'duplicate']), 409, 'invalid_transition');
    }

    public function test_cancel_into_a_locked_period_permissions_and_isolation(): void
    {
        $this->receive($this->oil, '20', '800.00');
        $inv = $this->posted();
        AccountingPeriod::query()->update(['status' => 'locked']);
        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel", ['reason' => 'duplicate']), 422, 'period_locked');
        AccountingPeriod::query()->update(['status' => 'open']);
        $this->userWithRole('viewer');
        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel", ['reason' => 'duplicate'], token: $this->login('viewer@meghna.example')), 403, 'permission_denied');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel", $this->login('padma@agro.example'), $other->id, ['reason' => 'duplicate'],
            ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->useBooks();
        $this->assertSame('posted', SalesInvoice::findOrFail($inv['id'])->status);
        $this->userWithRole('sales');
        $this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel", ['reason' => 'wrong_party'], token: $this->login('sales@meghna.example'))->assertOk();
    }

    public function test_cancel_after_the_stock_went_negative_trues_up_cogs(): void
    {
        $this->receive($this->oil, '10', '800.00');
        CompanySetting::query()->update(['allow_negative_stock' => true]);
        $first = $this->posted('10'); // leaves 0 at 8,000 out
        $this->posted('5');           // −5 at the last average 800
        $this->req('POST', "/api/v1/sales-invoices/{$first['id']}/cancel", ['reason' => 'entered_by_mistake'])->assertOk();
        $this->assertSame('5.000', $this->onHand()[0]); // books checked by BooksAreConsistent
    }

    public function test_tc_sal_016_bulk_post_twelve_drafts_where_one_lacks_stock(): void
    {
        $this->receive($this->oil, '200', '800.00');
        $rice = $this->product('Miniket rice 25kg', 'bag', '2340.00'); // never received
        $ids = [];
        for ($i = 0; $i < 11; $i++) {
            $ids[] = $this->draft('10')['id'];
        }
        array_splice($ids, 5, 0, [$short = $this->draft('3', $rice)['id']]);
        $r = $this->req('POST', '/api/v1/sales-invoices/bulk-post', ['ids' => $ids])->assertOk();
        $this->assertSame(array_values(array_diff($ids, [$short])), $r->json('data.succeeded'));
        $this->assertSame([['id' => $short, 'code' => 'insufficient_stock', 'detail' => 'One line needs more stock than the warehouse holds.']], $r->json('data.failed'));
        $this->assertSame(11, SalesInvoice::where('status', 'posted')->count());
        $this->assertSame('draft', SalesInvoice::findOrFail($short)->status, 'its own transaction rolled back alone');
        $this->assertSame(11, JournalEntry::where('entry_type', 'sales_invoice')->count());
        $this->assertSame('90.000', $this->onHand()[0]);

        $again = $this->req('POST', '/api/v1/sales-invoices/bulk-post', ['ids' => [$ids[0], 999999]])->assertOk();
        $this->assertSame([], $again->json('data.succeeded'));
        $this->assertSame(['already_posted', 'not_found'], array_column($again->json('data.failed'), 'code'));
        $this->assertProblem($this->req('POST', '/api/v1/sales-invoices/bulk-post', ['ids' => []]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/sales-invoices/bulk-post', ['ids' => array_fill(0, 101, 1)]), 422, 'validation_failed');
        $this->userWithRole('viewer');
        $this->assertProblem($this->req('POST', '/api/v1/sales-invoices/bulk-post', ['ids' => [$short]], token: $this->login('viewer@meghna.example')), 403, 'permission_denied');
    }

    public function test_r_31_delivery_details_change_after_posting_and_are_audited(): void
    {
        $this->receive($this->oil, '20', '800.00');
        $inv = $this->posted();
        $url = "/api/v1/sales-invoices/{$inv['id']}/delivery";
        $this->assertProblem($this->req('PATCH', $url, ['challan_no' => 'DC-2026-0412']), 428, 'if_match_required');
        $r = $this->req('PATCH', $url, ['challan_no' => ' DC-2026-0412 ', 'vehicle_no' => 'Dhaka Metro-Ta 11-2233', 'delivered_at' => '2026-10-24T15:30:00+06:00'],
            ['If-Match' => 'W/"2"'])->assertOk()->assertHeader('ETag', 'W/"3"');
        $r->assertJsonPath('data.delivery.challan_no', 'DC-2026-0412')->assertJsonPath('data.delivery.vehicle_no', 'Dhaka Metro-Ta 11-2233')
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.total', $inv['total']);
        $this->assertSame('2026-10-24T09:30:00', SalesInvoice::findOrFail($inv['id'])->delivered_at->setTimezone('UTC')->format('Y-m-d\TH:i:s'));
        $audit = AuditLog::where('action', 'updated')->where('auditable_type', 'sales_invoices')->where('auditable_id', $inv['id'])->latest('id')->firstOrFail();
        $this->assertSame(['challan_no' => null, 'vehicle_no' => null, 'delivered_at' => null], $audit->old_values);
        $this->assertSame('DC-2026-0412', $audit->new_values['challan_no']);

        // Only the fields sent change; null clears one.
        $this->req('PATCH', $url, ['vehicle_no' => null], ['If-Match' => 'W/"3"'])->assertOk()
            ->assertJsonPath('data.delivery.challan_no', 'DC-2026-0412')->assertJsonPath('data.delivery.vehicle_no', null);
        $this->assertProblem($this->req('PATCH', $url, ['vehicle_no' => 'X'], ['If-Match' => 'W/"3"']), 409, 'version_conflict')->assertJsonPath('current_version', 4);
        $this->assertProblem($this->req('PATCH', $url, ['total' => '1.00'], ['If-Match' => 'W/"4"']), 422, 'validation_failed');
        $this->assertProblem($this->req('PATCH', $url, ['delivered_at' => 'tomorrow'], ['If-Match' => 'W/"4"']), 422, 'validation_failed');
        $this->assertProblem($this->req('PATCH', $url, ['challan_no' => str_repeat('9', 31)], ['If-Match' => 'W/"4"']), 422, 'validation_failed');

        $this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel", ['reason' => 'duplicate'])->assertOk();
        $this->assertProblem($this->req('PATCH', $url, ['vehicle_no' => 'X'], ['If-Match' => 'W/"5"']), 409, 'invalid_transition');
        $this->userWithRole('viewer');
        $this->assertProblem($this->req('PATCH', $url, ['vehicle_no' => 'X'], ['If-Match' => 'W/"5"'], $this->login('viewer@meghna.example')), 403, 'permission_denied');
    }
}
