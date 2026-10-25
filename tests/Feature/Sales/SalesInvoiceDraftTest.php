<?php

namespace Tests\Feature\Sales;

use App\Domain\Sales\SalesInvoiceService;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Draft sales invoices and the list: POST/GET/PUT/DELETE /sales-invoices (E2, E3, E6; BR-006, BR-013, BR-025, BR-049,
 * BR-055; TC-SAL-001, TC-SAL-013, TC-SAL-015, TC-SAL-017; P5.1-BE-02).
 */
class SalesInvoiceDraftTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $oil;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $this->prefix = 'INV-'.FiscalYear::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->value('start_date')->year.'-';
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function body(array $over = []): array
    {
        return $over + ['party_id' => $this->karim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '40']]];
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    public function test_create_reserves_the_number_and_stores_the_calculated_invoice(): void
    {
        $delivery = $this->product('Delivery trip', 'pcs', '150.00', 'service');
        $r = $this->req('POST', '/api/v1/sales-invoices', $this->body(['customer_po_ref' => ' PO-2231 ', 'notes' => 'Deliver before noon', 'lines' => [
            ['product_id' => $this->oil->id, 'quantity' => '40'],
            ['product_id' => $this->oil->id, 'quantity' => '10', 'unit_price' => '950.00', 'discount_pct' => '2', 'description' => 'Promo carton'],
            ['product_id' => $delivery->id, 'quantity' => '2'],
        ]]))->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.number', "{$this->prefix}0001")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.overdue', false)->assertJsonPath('data.party.code', 'CUS-0001')->assertJsonPath('data.party.name', 'Karim Distributors')
            ->assertJsonPath('data.customer_po_ref', 'PO-2231')->assertJsonPath('data.warehouse_id', $this->wh->id)
            ->assertJsonPath('data.subtotal', '47400.00')->assertJsonPath('data.discount_total', '190.00')->assertJsonPath('data.total', '47210.00')
            ->assertJsonPath('data.balance_due', '47210.00')->assertJsonPath('data.amount_settled', '0.00')->assertJsonPath('data.journal', [])
            ->assertJsonPath('data.delivery', null)->assertJsonPath('data.cancel_reason', null)->assertJsonPath('data.posted_at', null)
            ->assertJsonPath('data.lines.0.unit_price', '940.00')->assertJsonPath('data.lines.0.unit', 'jar')->assertJsonPath('data.lines.0.product_code', $this->oil->code)
            ->assertJsonPath('data.lines.1.discount_amount', '190.00')->assertJsonPath('data.lines.1.net_amount', '9310.00')->assertJsonPath('data.lines.1.description', 'Promo carton')
            ->assertJsonPath('data.lines.0.unit_cost', null)->assertJsonPath('data.lines.0.returned_quantity', '0.000')
            ->assertJsonPath('data.amount_in_words', 'Taka forty-seven thousand two hundred ten only')
            ->assertJsonPath('data.created_by.name', 'Owner')->assertJsonPath('data.version', 1);
        $inv = SalesInvoice::with('lines')->findOrFail($r->json('data.id'));
        $income = fn (string $code) => DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');
        $this->assertSame([$income('4101'), $income('4101'), $income('4201')], $inv->lines->pluck('income_ledger_id')->all(), 'income ledger snapshot per line');
        $this->assertSame('Karim Distributors', $inv->party_snapshot['name']);
        $this->assertSame($this->wh->branch_id, $inv->branch_id);
        $this->assertSame('Deliver before noon', $inv->notes);
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'sales_invoices')->where('auditable_id', $inv->id)->exists());
        $this->assertSame(0, DB::table('stock_movements')->count() + DB::table('journal_entries')->count(), 'a draft changes no stock and no books');

        $this->req('GET', "/api/v1/sales-invoices/{$inv->id}")->assertOk()->assertHeader('ETag', 'W/"1"')->assertJsonCount(3, 'data.lines')
            ->assertJsonPath('data.number', "{$this->prefix}0001");
    }

    public function test_tc_sal_001_customer_quantity_and_due_date_are_required_rules(): void
    {
        $r = $this->assertProblem($this->req('POST', '/api/v1/sales-invoices', ['invoice_date' => $this->today(), 'due_date' => '2000-01-01', 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '0']]]), 422, 'validation_failed');
        $this->assertNotNull($r->json('errors.party_id'));
        $this->assertNotNull($r->json('errors')['lines.0.quantity'] ?? null);
        $this->assertNotNull($r->json('errors.due_date'));
        $this->karim->update(['status' => 'inactive']);
        $this->assertProblem($this->req('POST', '/api/v1/sales-invoices', $this->body()), 422, 'inactive_reference');
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_tc_sal_015_due_date_from_the_customers_terms_unless_supplied(): void
    {
        $net14 = DB::table('payment_terms')->where('company_id', $this->books->id)->where('name', 'Net 14')->value('id');
        $this->karim->update(['payment_term_id' => $net14]);
        $this->req('POST', '/api/v1/sales-invoices', $this->body())->assertCreated()
            ->assertJsonPath('data.due_date', CarbonImmutable::parse($this->today())->addDays(14)->toDateString());
        $due = CarbonImmutable::parse($this->today())->addDays(3)->toDateString();
        $this->req('POST', '/api/v1/sales-invoices', $this->body(['due_date' => $due]))->assertCreated()->assertJsonPath('data.due_date', $due);
    }

    public function test_tc_sal_017_replace_needs_the_current_version_and_only_drafts_change(): void
    {
        $inv = $this->req('POST', '/api/v1/sales-invoices', $this->body(['lines' => [['product_id' => $this->oil->id, 'quantity' => '1'], ['product_id' => $this->oil->id, 'quantity' => '2']]]))->json('data');
        $this->assertProblem($this->req('PUT', "/api/v1/sales-invoices/{$inv['id']}", $this->body()), 428, 'if_match_required');
        $r = $this->req('PUT', "/api/v1/sales-invoices/{$inv['id']}", $this->body(['customer_po_ref' => 'PO-9']), ['If-Match' => 'W/"1"'])->assertOk();
        $r->assertJsonPath('data.version', 2)->assertJsonPath('data.number', $inv['number'], 'the number stays')->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.total', '37600.00')->assertJsonPath('data.customer_po_ref', 'PO-9')->assertHeader('ETag', 'W/"2"');
        $this->assertSame(1, DB::table('sales_invoice_lines')->where('sales_invoice_id', $inv['id'])->count());
        $this->assertTrue(AuditLog::where('action', 'updated')->where('auditable_type', 'sales_invoices')->where('auditable_id', $inv['id'])->exists());
        $this->assertProblem($this->req('PUT', "/api/v1/sales-invoices/{$inv['id']}", $this->body(), ['If-Match' => 'W/"1"']), 409, 'version_conflict')
            ->assertJsonPath('current_version', 2);

        DB::table('sales_invoices')->where('id', $inv['id'])->update(['status' => 'posted']);
        $this->assertProblem($this->req('PUT', "/api/v1/sales-invoices/{$inv['id']}", $this->body(), ['If-Match' => 'W/"2"']), 409, 'not_draft');
    }

    public function test_tc_sal_013_only_drafts_are_deleted_and_the_number_is_voided_never_reissued(): void
    {
        $first = $this->req('POST', '/api/v1/sales-invoices', $this->body())->json('data');
        $second = $this->req('POST', '/api/v1/sales-invoices', $this->body())->json('data');
        $this->assertSame("{$this->prefix}0002", $second['number']);
        DB::table('sales_invoices')->where('id', $first['id'])->update(['status' => 'posted']);
        $this->assertProblem($this->req('DELETE', "/api/v1/sales-invoices/{$first['id']}"), 409, 'not_draft');

        $this->req('DELETE', "/api/v1/sales-invoices/{$second['id']}")->assertNoContent();
        $this->assertSame(0, DB::table('sales_invoice_lines')->where('sales_invoice_id', $second['id'])->count());
        $void = AuditLog::where('action', 'voided_number')->where('auditable_type', 'sales_invoices')->where('auditable_id', $second['id'])->firstOrFail();
        $this->assertSame(['number' => "{$this->prefix}0002"], $void->old_values);
        $this->assertProblem($this->req('GET', "/api/v1/sales-invoices/{$second['id']}"), 404, 'not_found');
        $this->req('POST', '/api/v1/sales-invoices', $this->body())->assertJsonPath('data.number', "{$this->prefix}0003", 'never reissued');
    }

    public function test_stock_shortfall_and_credit_limit_do_not_stop_a_draft(): void
    {
        $this->karim->update(['credit_limit' => '100.00']);
        $this->req('POST', '/api/v1/sales-invoices', $this->body(['lines' => [['product_id' => $this->oil->id, 'quantity' => '999']]]))->assertCreated();
    }

    public function test_list_tabs_filters_search_and_sort(): void
    {
        $rahman = $this->customer('Rahman Super Shop', '01811000222');
        $first = CarbonImmutable::parse(DB::table('accounting_periods')->where('company_id', $this->books->id)->where('status', 'open')->min('start_date'));
        $make = fn (Party $p, string $po, CarbonImmutable $date, string $qty) => app(SalesInvoiceService::class)->createDraft(['party_id' => $p->id,
            'invoice_date' => $date->toDateString(), 'warehouse_id' => $this->wh->id, 'customer_po_ref' => $po, 'lines' => [['product_id' => $this->oil->id, 'quantity' => $qty]]]);
        $old = $make($this->karim, 'PO-OLD', $first, '10');
        $current = $make($this->karim, 'PO-NOW', CarbonImmutable::parse($this->today()), '5');
        $make($rahman, 'PO-DRAFT', CarbonImmutable::parse($this->today()), '1');
        $old->forceFill(['status' => 'posted', 'due_date' => $first->toDateString(), 'payment_status' => 'part_paid', 'amount_settled' => '400.00',
            'balance_due' => '9000.00'])->saveQuietly();
        $current->forceFill(['status' => 'posted', 'due_date' => CarbonImmutable::parse($this->today())->addDays(30)->toDateString()])->saveQuietly();
        $refs = fn (string $query = '') => array_column($this->req('GET', '/api/v1/sales-invoices'.$query)->assertOk()->json('data'), 'customer_po_ref');

        $r = $this->req('GET', '/api/v1/sales-invoices')->assertOk();
        $this->assertSame(3, $r->json('meta.total'));
        $this->assertSame([], $r->json('data.0.lines'), 'list rows carry no lines');
        $this->assertSame(['PO-DRAFT', 'PO-NOW', 'PO-OLD'], $refs(), 'newest first');
        $this->assertSame(['PO-DRAFT'], $refs('?status=draft'));
        $this->assertSame(['PO-OLD'], $refs('?payment_status=part_paid'));
        $this->assertSame(['PO-OLD'], $refs('?overdue=true'));
        $this->assertSame(['PO-DRAFT', 'PO-NOW'], $refs('?overdue=false'));
        $this->assertSame(['PO-DRAFT'], $refs("?party_id={$rahman->id}"));
        $this->assertSame(['PO-DRAFT'], $refs('?q=rahman'));
        $this->assertSame(['PO-NOW'], $refs('?q=PO-NOW'));
        $this->assertSame(['PO-OLD'], $refs('?q='.urlencode($old->number)));
        $this->assertSame(['PO-OLD'], $refs('?date_to='.$first->toDateString()));
        $this->assertSame(['PO-DRAFT', 'PO-NOW', 'PO-OLD'], $refs('?sort=-number'));
        $this->assertSame(['PO-DRAFT', 'PO-NOW', 'PO-OLD'], $refs('?sort=total'));
        $this->req('GET', '/api/v1/sales-invoices?overdue=true')->assertJsonPath('data.0.overdue', true);
        foreach (['?status=open', '?sort=colour', '?colour=red', '?party_id='.$this->supplier()->id, '?per_page=500'] as $bad) {
            $this->assertProblem($this->req('GET', '/api/v1/sales-invoices'.$bad), 422, 'validation_failed');
        }
    }

    public function test_idempotent_create_locked_period_permissions_costs_and_isolation(): void
    {
        $key = (string) Str::uuid();
        $a = $this->req('POST', '/api/v1/sales-invoices', $this->body(), ['Idempotency-Key' => $key])->assertCreated();
        $this->req('POST', '/api/v1/sales-invoices', $this->body(), ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $a->json('data.id'));
        $this->assertSame(1, SalesInvoice::count());
        $id = $a->json('data.id');

        AccountingPeriod::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->update(['status' => 'locked']);
        $this->assertProblem($this->req('POST', '/api/v1/sales-invoices', $this->body()), 422, 'period_locked');
        AccountingPeriod::query()->where('status', 'locked')->update(['status' => 'open']);

        DB::table('sales_invoice_lines')->where('sales_invoice_id', $id)->update(['unit_cost' => '845.0000', 'cost_amount' => '33800.00']);
        $this->req('GET', "/api/v1/sales-invoices/{$id}")->assertJsonPath('data.lines.0.unit_cost', '845.0000')->assertJsonPath('data.lines.0.cost_amount', '33800.00');
        $this->userWithRole('store');
        $store = $this->login('store@meghna.example');
        $this->assertProblem($this->req('GET', "/api/v1/sales-invoices/{$id}", token: $store), 403, 'permission_denied');
        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $this->req('POST', '/api/v1/sales-invoices', $this->body(), token: $sales)->assertCreated();
        $this->assertProblem($this->req('DELETE', "/api/v1/sales-invoices/{$id}", token: $sales), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/sales-invoices', token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', '/api/v1/sales-invoices', $this->body(), token: $viewer), 403, 'permission_denied');
        // A role without inventory.view_costs sees no COGS on the lines (BR-052).
        $role = DB::table('company_users')->where('user_id', DB::table('users')->where('email', 'viewer@meghna.example')->value('id'))->value('role_id');
        DB::table('role_permissions')->where('role_id', $role)->whereIn('permission_id', DB::table('permissions')->where('key', 'inventory.view_costs')->pluck('id'))->delete();
        DB::table('roles')->where('id', $role)->increment('lock_version'); // the resolver caches by role version
        $this->req('GET', "/api/v1/sales-invoices/{$id}", token: $viewer)->assertJsonPath('data.lines.0.unit_cost', null)->assertJsonPath('data.lines.0.cost_amount', null);

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/sales-invoices/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('DELETE', "/api/v1/sales-invoices/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertSame(0, $this->api('GET', '/api/v1/sales-invoices', $ot, $other->id)->json('meta.total'));
    }
}
