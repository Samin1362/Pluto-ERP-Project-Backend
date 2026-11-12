<?php

namespace Tests\Feature\Platform;

use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use App\Queries\BankingQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * GET /dashboard and GET /dashboard/lenses/{lens} (A1–A8; FR-010, FR-011; OBJ-2; ERR-8; DEC-13; backlog P14.1-BE-01/02).
 *
 * The month: opening bank ৳ 2,00,000; bought 100 oil at 800 from Bengal (bill due in 3 days); sold Karim 20 oil at
 * 940; paid ৳ 4,000 rent from the bank.
 */
class DashboardTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private array $bank;

    private Party $karim;

    private Party $bengal;

    private Warehouse $wh;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->bank = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012345678', 'opening_balance' => '200000.00'])->assertCreated()->json('data');
        $this->karim = $this->customer();
        $this->bengal = $this->supplier();
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        DB::table('product_warehouse_settings')->updateOrInsert(['company_id' => $this->books->id, 'product_id' => $this->oil->id, 'warehouse_id' => $this->wh->id],
            ['reorder_level' => '100', 'reorder_qty' => '50', 'created_at' => now(), 'updated_at' => now()]);
        $this->postDoc('purchase-bills', ['party_id' => $this->bengal->id, 'supplier_bill_no' => 'BEO-1', 'bill_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'due_date' => CarbonImmutable::parse($this->today())->addDays(3)->toDateString(), 'lines' => [['product_id' => $this->oil->id, 'quantity' => '100', 'unit_cost' => '800.00']]]);
        $this->postDoc('sales-invoices', ['party_id' => $this->karim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '20']]]);
        $rent = $this->req('POST', '/api/v1/ie-categories', ['kind' => 'expense', 'name' => 'Rent', 'ledger_id' => $this->ledger('6101')])->assertCreated()->json('data');
        $this->req('POST', '/api/v1/ie-entries', ['kind' => 'expense', 'amount' => '4000.00', 'date' => $this->today(), 'category_id' => $rent['id'],
            'money_account_id' => $this->bank['id'], 'method' => 'bank_transfer'])->assertCreated();
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function ledger(string $code): int
    {
        return (int) DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function postDoc(string $resource, array $body): array
    {
        $d = $this->req('POST', "/api/v1/{$resource}", $body)->assertCreated()->json('data');
        $this->req('POST', "/api/v1/{$resource}/{$d['id']}/post")->assertOk();

        return $d;
    }

    private function kpis($r): array
    {
        return collect($r->json('data.kpis'))->keyBy('key')->all();
    }

    public function test_the_dashboard_shows_the_same_figures_as_the_reports_for_the_period(): void
    {
        $r = $this->req('GET', '/api/v1/dashboard')->assertOk();
        $from = CarbonImmutable::parse($this->today())->startOfMonth()->toDateString();
        $r->assertJsonPath('data.period.name', 'this_month')->assertJsonPath('data.period.date_from', $from)->assertJsonPath('data.period.date_to', $this->today())
            ->assertJsonPath('data.widget_errors', []);
        $pl = $this->req('GET', "/api/v1/reports/profit-and-loss?date_from={$from}&date_to={$this->today()}")->json('data.totals');
        $k = $this->kpis($r);
        $this->assertSame(['revenue', 'expenses', 'net_profit', 'cash'], array_keys($k));
        $this->assertSame([$pl['revenue'], $pl['expenses'], $pl['net_profit']], [$k['revenue']['value'], $k['expenses']['value'], $k['net_profit']['value']]);
        $this->assertSame(['18800.00', '20000.00', '-1200.00'], [$k['revenue']['value'], $k['expenses']['value'], $k['net_profit']['value']], 'COGS 16,000 + rent 4,000');
        $this->assertSame($this->req('GET', '/api/v1/banking/overview')->json('data.totals.total'), $k['cash']['value']);
        $this->assertSame('196000.00', $k['cash']['value']);
        $this->assertSame('/reports/profit-and-loss', $k['revenue']['link']);
        $this->assertCount(6, $k['revenue']['trend']);
        $this->assertSame('100.0', end($k['revenue']['trend_pct']));

        $months = $r->json('data.revenue_vs_expenses');
        $this->assertCount(12, $months);
        $this->assertSame(['18800.00', '20000.00', '-1200.00', '94.0', '100.0'], [end($months)['revenue'], end($months)['expenses'], end($months)['net_profit'],
            end($months)['revenue_pct'], end($months)['expenses_pct']]);

        $aging = $this->req('GET', '/api/v1/reports/receivables-aging')->json('data.totals');
        $r->assertJsonPath('data.money_owed.receivable.total', $aging['total'])->assertJsonPath('data.money_owed.receivable.total', '18800.00')
            ->assertJsonPath('data.money_owed.payable.total', '80000.00')->assertJsonPath('data.money_owed.receivable.buckets.0.key', 'not_due')
            ->assertJsonPath('data.money_owed.receivable.buckets.0.pct', '100.0');
        $r->assertJsonPath('data.cash_position.0.name', $this->bank['name'])->assertJsonPath('data.cash_position.0.balance', '196000.00');
        $r->assertJsonPath('data.upcoming_payments.0.kind', 'purchase_bill')->assertJsonPath('data.upcoming_payments.0.amount', '80000.00')
            ->assertJsonPath('data.upcoming_payments.0.days', 3)->assertJsonPath('data.upcoming_payments.0.party', $this->bengal->name);
        $this->assertCount(4, $r->json('data.recent_transactions'), 'opening balance, bill, invoice, rent');
        $this->assertSame('expense', $r->json('data.recent_transactions.0.type'));
        $this->assertStringStartsWith('/accounting/vouchers/', $r->json('data.recent_transactions.0.url'));
        $r->assertJsonPath('data.inventory_alerts.0.name', 'Soybean oil 5L jar')->assertJsonPath('data.inventory_alerts.0.on_hand', '80.000')
            ->assertJsonPath('data.inventory_alerts.0.status', 'low_stock');

        $this->req('GET', '/api/v1/dashboard?period=this_year')->assertOk()->assertJsonPath('data.period.name', 'this_year');
        $this->req('GET', '/api/v1/dashboard?period=this_quarter')->assertOk()->assertJsonPath('data.kpis.0.value', '18800.00');
        $this->assertProblem($this->req('GET', '/api/v1/dashboard?period=yesterday'), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/dashboard?colour=red'), 422, 'validation_failed');
    }

    public function test_obj_2_a_posting_shows_at_once_although_the_dashboard_is_cached(): void
    {
        $this->req('GET', '/api/v1/dashboard')->assertJsonPath('data.kpis.1.value', '20000.00');
        $rent = DB::table('ie_categories')->where('company_id', $this->books->id)->value('id');
        $this->req('POST', '/api/v1/ie-entries', ['kind' => 'expense', 'amount' => '1000.00', 'date' => $this->today(), 'category_id' => $rent,
            'money_account_id' => $this->bank['id'], 'method' => 'bank_transfer'])->assertCreated();
        $this->req('GET', '/api/v1/dashboard')->assertJsonPath('data.kpis.1.value', '21000.00')->assertJsonPath('data.kpis.3.value', '195000.00');
    }

    public function test_today_feed_lists_what_was_posted_since_midnight_in_dhaka(): void
    {
        $r = $this->req('GET', '/api/v1/dashboard')->assertOk();
        $r->assertJsonPath('data.today.date', $this->today())->assertJsonPath('data.today.count', 4);
        $this->assertSame('expense', $r->json('data.today.items.0.type'));
        $this->assertSame('4000.00', $r->json('data.today.items.0.amount'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $r->json('data.today.items.0.time'));
        $this->assertStringEndsWith('+06:00', $r->json('data.today.items.0.posted_at'));
        $this->assertStringStartsWith('/accounting/vouchers/', $r->json('data.today.items.0.url'));
        $this->assertNotEmpty($r->json('data.today.items.0.by'));
        $this->assertSame($this->karim->name, collect($r->json('data.today.items'))->firstWhere('type', 'sales_invoice')['party']);

        // posted_at is UTC: 23:59 Dhaka yesterday is 17:59 UTC the day before; 00:00 Dhaka today counts.
        $start = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $ids = DB::table('journal_entries')->where('company_id', $this->books->id)->where('status', 'posted')->orderBy('id')->pluck('id');
        DB::table('journal_entries')->where('id', $ids[0])->update(['posted_at' => $start->subMinute()->utc()->toDateTimeString()]);
        DB::table('journal_entries')->where('id', $ids[1])->update(['posted_at' => $start->utc()->toDateTimeString()]);
        $q = app(\App\Queries\DashboardQuery::class)->today($this->books->id);
        $this->assertSame(3, $q['count']);
        $this->assertNotContains($ids[0], array_column($q['items'], 'id'));
        $this->assertSame('00:00', collect($q['items'])->firstWhere('id', $ids[1])['time']);

        // Another company's postings never show.
        $this->assertSame(0, app(\App\Queries\DashboardQuery::class)->today($this->books->id + 999)['count']);
    }

    public function test_err_8_a_failing_widget_comes_back_empty_and_the_rest_still_loads(): void
    {
        $this->mock(BankingQuery::class, fn ($m) => $m->shouldReceive('overview')->andThrow(new RuntimeException('bank feed down')));
        $r = $this->req('GET', '/api/v1/dashboard')->assertOk();
        $r->assertJsonPath('data.cash_position', [])->assertJsonPath('data.money_owed.receivable.total', '18800.00');
        $this->assertSame(['kpis', 'cash_position'], array_column($r->json('data.widget_errors'), 'widget'));
        $this->assertNotEmpty($r->json('data.widget_errors.0.ref'));
    }

    public function test_dec_13_sales_staff_and_viewer_see_no_profit_cash_or_balances(): void
    {
        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $r = $this->req('GET', '/api/v1/dashboard', token: $sales)->assertOk();
        $this->assertSame(['sales'], array_keys($this->kpis($r)));
        $this->assertSame(['18800.00', '1 posted invoices'], [$r->json('data.kpis.0.value'), $r->json('data.kpis.0.foot')]);
        $r->assertJsonPath('data.revenue_vs_expenses', [])->assertJsonPath('data.cash_position', [])->assertJsonPath('data.money_owed', [])
            ->assertJsonPath('data.upcoming_payments', [])->assertJsonPath('data.recent_transactions', []);
        $this->assertCount(1, $r->json('data.inventory_alerts'));

        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $v = $this->req('GET', '/api/v1/dashboard', token: $viewer)->assertOk();
        $this->assertSame(['sales', 'purchases'], array_keys($this->kpis($v)));
        $v->assertJsonPath('data.kpis.1.value', '80000.00')->assertJsonPath('data.money_owed', [])->assertJsonPath('data.cash_position', []);
        $this->assertCount(4, $v->json('data.recent_transactions'), 'vouchers are open to accounting.view');

        foreach (['financial', 'cash', 'receivables', 'payables'] as $lens) {
            $this->assertProblem($this->req('GET', "/api/v1/dashboard/lenses/{$lens}", token: $viewer), 403, 'permission_denied');
        }
        $this->req('GET', '/api/v1/dashboard/lenses/sales', token: $sales)->assertOk();
        $this->assertProblem($this->req('GET', '/api/v1/dashboard/lenses/expenses', token: $sales), 403, 'permission_denied');

        $this->userWithRole('store');
        $this->assertProblem($this->req('GET', '/api/v1/dashboard', token: $this->login('store@meghna.example')), 403, 'permission_denied');
    }

    public function test_lenses_agree_with_their_reports(): void
    {
        $fin = $this->req('GET', '/api/v1/dashboard/lenses/financial')->assertOk()->assertJsonPath('data.lens', 'financial');
        $k = collect($fin->json('data.kpis'))->keyBy('key');
        $this->assertSame(['18800.00', '2800.00', '20000.00', '-1200.00'], [$k['revenue']['value'], $k['gross_profit']['value'], $k['expenses']['value'], $k['net_profit']['value']]);
        $this->assertSame('Gross margin 14.9 %', $k['gross_profit']['foot']);
        $this->assertCount(12, $fin->json('data.series'));
        $series = $fin->json('data.series');
        $this->assertTrue(end($series)['negative']);
        $rows = collect($fin->json('data.table.rows'))->keyBy('key');
        $this->assertSame(['18800.00', '100.0'], [$rows['revenue_total']['amount'], $rows['revenue_total']['share']]);
        $this->assertSame(['16000.00', '85.1'], [$rows['cost_of_sales_total']['amount'], $rows['cost_of_sales_total']['share']]);
        $ratios = collect($fin->json('data.table.meta.ratios'))->keyBy('key');
        $this->assertSame(['14.9', '-6.4'], [$ratios['gross_margin']['value'], $ratios['net_margin']['value']]);
        $this->assertNotNull($ratios['current_ratio']['value']);

        $sales = $this->req('GET', '/api/v1/dashboard/lenses/sales')->assertOk();
        $sales->assertJsonPath('data.kpis.0.value', '18800.00')->assertJsonPath('data.table.rows.0.name', $this->karim->name)
            ->assertJsonPath('data.table.rows.0.share', '100.0')->assertJsonPath('data.table.rows.0.url', "/customers/{$this->karim->id}");

        $this->req('GET', '/api/v1/dashboard/lenses/expenses')->assertOk()->assertJsonPath('data.kpis.0.value', '4000.00')->assertJsonPath('data.series.0.name', 'Rent');
        $rec = $this->req('GET', '/api/v1/dashboard/lenses/receivables')->assertOk();
        $rec->assertJsonPath('data.kpis.0.value', '18800.00')->assertJsonPath('data.table.rows.0.url', "/customers/{$this->karim->id}");
        $this->assertCount(5, $rec->json('data.series'));
        $this->req('GET', '/api/v1/dashboard/lenses/payables')->assertOk()->assertJsonPath('data.kpis.0.value', '80000.00')
            ->assertJsonPath('data.table.meta.upcoming.0.amount', '80000.00');
        $cash = $this->req('GET', '/api/v1/dashboard/lenses/cash')->assertOk()->assertJsonPath('data.kpis.0.value', '196000.00');
        $this->assertCount(30, $cash->json('data.series'));
        $inv = $this->req('GET', '/api/v1/dashboard/lenses/inventory')->assertOk()->assertJsonPath('data.kpis.0.value', '64000.00');
        $inv->assertJsonPath('data.series.0.name', $this->wh->name)->assertJsonPath('data.table.rows.0.name', 'Soybean oil 5L jar');

        $this->req('GET', '/api/v1/dashboard/lenses/marketing')->assertNotFound();
        $this->assertProblem($this->req('GET', '/api/v1/dashboard/lenses/sales?period=forever'), 422, 'validation_failed');
    }
}
