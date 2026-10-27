<?php

namespace Tests\Feature\Reports;

use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoiceService;
use App\Domain\Shared\Money;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Financial statements (H15–H19, J6, J7; 06 §17; backlog P9.1-BE-01..04; BR-001, BR-005, FR-076; TC-ACC-005, TC-ACC-006).
 * One small set of books, worked out by hand (stock through 1405 so the inventory ledger keeps agreeing with stock):
 *   capital 1,00,000 · van 30,000 · stock bought 15,000 · sales 20,000 · returns 1,000 · cost of sales 8,000 · rent 3,000
 *   · interest received 500 · depreciation 1,000 · electricity accrued 700.
 * Net profit 6,800; cash 71,500; assets 1,07,500 = liabilities 700 + equity 1,06,800; TB 1,22,200 each side.
 */
class FinancialReportTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private string $d1;

    private string $d2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->useBooks();
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $start = CarbonImmutable::parse(DB::table('fiscal_years')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->value('start_date'));
        [$this->d1, $this->d2] = [$start->toDateString(), $start->addMonth()->toDateString()];
        foreach ([
            ['1101-01', '3101', '100000', $this->d1, 'Capital introduced'], ['1501', '1101-01', '30000', $this->d1, 'Delivery van'],
            ['1405', '1101-01', '15000', $this->d1, 'Stock bought'], ['1101-01', '4101', '20000', $this->d2, 'Cash sales'],
            ['4109', '1101-01', '1000', $this->d2, 'Goods returned'], ['5001', '1405', '8000', $this->d2, 'Cost of goods sold'],
            ['6101', '1101-01', '3000', $this->d2, 'Rent'], ['1101-01', '4302', '500', $this->d2, 'Interest received'],
            ['6501', '1509', '1000', $this->d2, 'Depreciation of the van'], ['6110', '2202', '700', $this->d2, 'Electricity accrued'],
        ] as [$dr, $cr, $amount, $date, $narration]) {
            $this->journal($dr, $cr, $amount, $date, $narration);
        }
    }

    private function req(string $uri, ?string $token = null)
    {
        $r = $this->api('GET', $uri, $token ?? $this->token, $this->books->id);
        $this->useBooks();

        return $r;
    }

    private function ledger(string $code): int
    {
        $this->useBooks();

        return (int) Ledger::where('code', $code)->value('id');
    }

    private function journal(string $debit, string $credit, string $amount, string $date, string $narration): void
    {
        $this->useBooks();
        app(PostingService::class)->persist((new JournalBuilder)->debit($this->ledger($debit), Money::of($amount))->credit($this->ledger($credit), Money::of($amount)),
            null, 'journal', CarbonImmutable::parse($date), null, $narration);
    }

    /** @return array<string, array> rows keyed by `key` (or by code) */
    private function byKey(array $rows, string $key = 'key'): array
    {
        return collect($rows)->keyBy($key)->all();
    }

    public function test_t_c_ac_c_005_trial_balance_agrees_by_ledger_and_by_group_and_lists_drafts(): void
    {
        $this->api('POST', '/api/v1/vouchers', $this->token, $this->books->id, ['entry_type' => 'journal', 'action' => 'draft', 'date' => $this->d2, 'narration' => 'Not yet',
            'lines' => [['ledger_id' => $this->ledger('6101'), 'debit' => '999.00'], ['ledger_id' => $this->ledger('1101-01'), 'credit' => '999.00']]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->useBooks();

        $r = $this->req('/api/v1/reports/trial-balance')->assertOk();
        $r->assertJsonPath('data.totals.debit', '122200.00')->assertJsonPath('data.totals.credit', '122200.00')->assertJsonPath('data.totals.difference', '0.00')
            ->assertJsonPath('data.checks.0.ok', true)->assertJsonPath('data.checks.1.ok', true)->assertJsonPath('data.meta.drafts.count', 1)
            ->assertJsonPath('data.meta.drafts.items.0.amount', '999.00');
        $ledgers = $this->byKey(array_filter($r->json('data.rows'), fn ($x) => $x['kind'] === 'ledger'), 'code');
        $this->assertSame(['71500.00', null], [$ledgers['1101-01']['debit'], $ledgers['1101-01']['credit']], 'the draft rent is not in the cash balance (BR-005)');
        $this->assertSame([null, '1000.00'], [$ledgers['1509']['debit'], $ledgers['1509']['credit']]);
        $this->assertArrayNotHasKey('4201', $ledgers, 'ledgers without a balance are left out');
        $groups = $this->byKey(array_filter($r->json('data.rows'), fn ($x) => $x['kind'] === 'group'), 'code');
        $this->assertSame('29000.00', $groups['12']['debit'], 'fixed assets net of depreciation');
        $this->assertSame(['0', '1'], [(string) $groups['1']['depth'], (string) $groups['11']['depth']]);

        $g = $this->req('/api/v1/reports/trial-balance?level=group')->assertOk();
        $this->assertSame(['group'], array_values(array_unique(array_column($g->json('data.rows'), 'kind'))));
        $g->assertJsonPath('data.totals.debit', '122200.00');
        // As of the first day only the first three entries count.
        $this->req("/api/v1/reports/trial-balance?as_of={$this->d1}")->assertJsonPath('data.totals.debit', '100000.00')->assertJsonPath('data.meta.drafts.count', 0);
        // Branch filter: every entry here has no branch, so the head office alone shows nothing.
        $ho = DB::table('branches')->where('company_id', $this->books->id)->value('id');
        $this->req("/api/v1/reports/trial-balance?branch_id={$ho}")->assertJsonPath('data.totals.debit', '0.00');
    }

    public function test_t_c_ac_c_006_general_ledger_blocks_open_run_and_close(): void
    {
        $cash = $this->ledger('1101-01');
        $rent = $this->ledger('6101');
        $r = $this->req("/api/v1/reports/general-ledger?date_from={$this->d2}&date_to={$this->d2}&ledger_ids={$cash},{$rent}")->assertOk();
        $rows = $r->json('data.rows');
        $this->assertSame(['heading', 'opening', 'entry', 'entry', 'entry', 'entry', 'closing', 'heading', 'opening', 'entry', 'closing'], array_column($rows, 'kind'));
        $this->assertSame('55000.00', $rows[1]['balance'], 'cash opens with the first day: 1,00,000 − 30,000 − 15,000');
        $this->assertSame(['75000.00', '74000.00', '71000.00', '71500.00'], array_column(array_slice($rows, 2, 4), 'balance'));
        $this->assertSame(['20500.00', '4000.00', '71500.00', 'dr'], [$rows[6]['debit'], $rows[6]['credit'], $rows[6]['balance'], $rows[6]['balance_side']]);
        $r->assertJsonPath('data.totals.debit', '23500.00')->assertJsonPath('data.meta.ledgers', 2);
        // Default: every ledger with a posted line; by month one row per month.
        $all = $this->req("/api/v1/reports/general-ledger?date_from={$this->d1}&date_to={$this->d2}&group_by=month")->assertOk();
        $all->assertJsonPath('data.meta.ledgers', 13)->assertJsonPath('data.totals.debit', '179200.00');
        $this->assertContains('month', array_column($all->json('data.rows'), 'kind'));
    }

    public function test_profit_and_loss_sections_and_prior_year(): void
    {
        $r = $this->req("/api/v1/reports/profit-and-loss?date_from={$this->d1}&date_to={$this->d2}&compare=prior")->assertOk();
        $rows = $this->byKey($r->json('data.rows'));
        $this->assertSame(['19000.00', '8000.00', '11000.00', '3700.00', '7300.00', '500.00', '1000.00', '6800.00', '6800.00'],
            array_map(fn ($k) => $rows[$k]['amount'], ['revenue_total', 'cost_of_sales_total', 'gross_profit', 'operating_expenses_total', 'operating_profit',
                'other_income_total', 'finance_total', 'profit_before_tax', 'net_profit']));
        $this->assertSame('-1000.00', $rows['ledger_'.$this->ledger('4109')]['amount'], 'returns reduce revenue');
        $this->assertSame('0.00', $rows['net_profit']['prior']);
        $r->assertJsonPath('data.totals.net_profit', '6800.00')->assertJsonPath('data.totals.income', '19500.00')->assertJsonPath('data.totals.expenses', '12700.00')
            ->assertJsonPath('data.checks.0.ok', true)->assertJsonPath('data.meta.gross_margin_pct', '57.9')->assertJsonPath('data.period.prior_to', CarbonImmutable::parse($this->d2)->subYear()->toDateString());
        $totals = $this->req("/api/v1/reports/profit-and-loss?date_from={$this->d1}&date_to={$this->d2}&detail=totals")->json('data.rows');
        $this->assertNotContains('ledger', array_column($totals, 'kind'));
        $this->assertCount(2, $this->req("/api/v1/reports/profit-and-loss?date_from={$this->d1}&date_to={$this->d2}")->json('data.columns'));
    }

    public function test_balance_sheet_balances_with_profit_for_the_period(): void
    {
        $r = $this->req("/api/v1/reports/balance-sheet?as_of={$this->d2}&compare=prior")->assertOk();
        $r->assertJsonPath('data.totals.assets', '107500.00')->assertJsonPath('data.totals.liabilities', '700.00')->assertJsonPath('data.totals.equity', '106800.00')
            ->assertJsonPath('data.totals.liabilities_and_equity', '107500.00')->assertJsonPath('data.totals.profit_for_period', '6800.00')
            ->assertJsonPath('data.checks.0.ok', true)->assertJsonPath('data.period.prior_as_of', CarbonImmutable::parse($this->d1)->subDay()->toDateString());
        $rows = $this->byKey($r->json('data.rows'));
        $this->assertSame(['6800.00', '0.00'], [$rows['profit_for_period']['amount'], $rows['profit_for_period']['prior']]);
        $this->assertSame('-1000.00', $rows['ledger_'.$this->ledger('1509')]['amount'], 'accumulated depreciation reduces the assets');
        $this->assertArrayNotHasKey('earlier_profit', $rows);
        $this->assertNotContains('ledger', array_column($this->req("/api/v1/reports/balance-sheet?as_of={$this->d2}&detail=totals")->json('data.rows'), 'kind'));
    }

    public function test_cash_flow_reconciles_to_the_cash_and_bank_balance(): void
    {
        $r = $this->req("/api/v1/reports/cash-flow?date_from={$this->d1}&date_to={$this->d2}")->assertOk();
        $r->assertJsonPath('data.totals.operating', '1500.00')->assertJsonPath('data.totals.investing', '-30000.00')->assertJsonPath('data.totals.financing', '100000.00')
            ->assertJsonPath('data.totals.net_change', '71500.00')->assertJsonPath('data.totals.opening_cash', '0.00')->assertJsonPath('data.totals.closing_cash', '71500.00')
            ->assertJsonPath('data.checks.0.ok', true);
        $rows = $this->byKey($r->json('data.rows'));
        $this->assertSame(['6800.00', '1000.00'], [$rows['net_profit']['amount'], $rows['depreciation']['amount']]);
        $this->assertSame('Increase in current assets', collect($r->json('data.rows'))->firstWhere('amount', '-7000.00')['label']);
        // The second month alone opens with the first month's cash.
        $this->req("/api/v1/reports/cash-flow?date_from={$this->d2}&date_to={$this->d2}")->assertJsonPath('data.totals.opening_cash', '55000.00')
            ->assertJsonPath('data.totals.closing_cash', '71500.00')->assertJsonPath('data.checks.0.ok', true);
    }

    public function test_aging_buckets_parties_and_reconciles_to_their_ledgers(): void
    {
        $this->useBooks();
        $karim = $this->customer();
        $wh = $this->warehouse();
        $trip = $this->product('Delivery trip', 'pcs', '1.00', 'service');
        $today = CarbonImmutable::now('Asia/Dhaka');
        foreach ([['10000', -40], ['3000', 20]] as [$amount, $due]) {
            $this->useBooks();
            $inv = app(SalesInvoicePostingService::class)->post(app(SalesInvoiceService::class)->createDraft(['party_id' => $karim->id, 'invoice_date' => $today->toDateString(),
                'warehouse_id' => $wh->id, 'due_date' => $today->addDays(max($due, 0))->toDateString(), 'lines' => [['product_id' => $trip->id, 'quantity' => $amount, 'unit_price' => '1.00']]]));
            if ($due < 0) { // dates are not money: move the invoice into the past for aging
                DB::table('sales_invoices')->where('id', $inv->id)->update(['invoice_date' => $today->addDays($due - 1)->toDateString(), 'due_date' => $today->addDays($due)->toDateString()]);
            }
        }
        $r = $this->req('/api/v1/reports/receivables-aging')->assertOk();
        $row = $r->json('data.rows.0');
        $this->assertSame(['Karim Distributors', '3000.00', '10000.00', '13000.00', 40], [$row['name'], $row['not_due'], $row['d31_60'], $row['total'], $row['oldest_days']]);
        $r->assertJsonPath('data.totals.total', '13000.00')->assertJsonPath('data.totals.overdue', '10000.00')->assertJsonPath('data.checks.0.ok', true);
        $this->assertCount(9, $r->json('data.columns'));
        $this->assertSame([], $this->req('/api/v1/reports/receivables-aging?q=zzz')->json('data.rows'));
        $this->assertSame([], $this->req('/api/v1/reports/payables-aging')->json('data.rows'));
        $later = $today->addDays(-50)->toDateString(); // before anything was due
        $this->assertSame([], $this->req("/api/v1/reports/receivables-aging?as_of={$later}&overdue_only=true")->json('data.rows'));
    }

    public function test_exports_need_reports_export_and_return_files(): void
    {
        foreach (['xlsx' => 'spreadsheetml', 'csv' => 'text/csv', 'pdf' => 'application/pdf'] as $format => $type) {
            $r = $this->req("/api/v1/reports/trial-balance?format={$format}")->assertOk();
            $this->assertStringContainsString($type, (string) $r->headers->get('Content-Type'));
            $this->assertStringContainsString('trial-balance-', (string) $r->headers->get('Content-Disposition'));
            $body = $format === 'pdf' ? $r->getContent() : $r->streamedContent();
            $this->assertNotEmpty($body);
            if ($format === 'csv') {
                $this->assertStringContainsString('Cash in hand', $body);
                $this->assertStringContainsString('Total,,122200,122200', str_replace('"', '', $body));
            }
        }
        foreach (['profit-and-loss', 'balance-sheet', 'cash-flow', 'general-ledger', 'receivables-aging', 'payables-aging'] as $report) {
            $this->req("/api/v1/reports/{$report}?format=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        // An Accountant may export; a role with reports.financial but without reports.export may only read.
        $this->userWithRole('accountant');
        $this->assertNotEmpty($this->req('/api/v1/reports/balance-sheet?format=csv', $this->login('accountant@meghna.example'))->assertOk()->streamedContent());
        $this->useBooks();
        $role = DB::table('roles')->where('company_id', $this->books->id)->where('slug', 'accountant')->value('id');
        DB::table('role_permissions')->where('role_id', $role)->where('permission_id', DB::table('permissions')->where('key', 'reports.export')->value('id'))->delete();
        DB::table('roles')->where('id', $role)->increment('lock_version'); // a role edit bumps its version, which refreshes the permission cache
        $acc = $this->login('accountant@meghna.example');
        $this->assertProblem($this->req('/api/v1/reports/balance-sheet?format=csv', $acc), 403, 'permission_denied');
        $this->req('/api/v1/reports/balance-sheet', $acc)->assertOk();
    }

    /** DEC-13 (owner decision 25 Oct 2026): only Owner, Finance manager and Accountant see the financial statements. */
    public function test_permissions_validation_and_company_isolation(): void
    {
        $paths = ['trial-balance', 'profit-and-loss', 'balance-sheet', 'cash-flow', 'general-ledger', 'receivables-aging', 'payables-aging'];
        foreach (['finance' => 200, 'accountant' => 200, 'sales' => 403, 'viewer' => 403, 'store' => 403] as $role => $status) {
            $this->userWithRole($role);
            $t = $this->login("{$role}@meghna.example");
            foreach ($paths as $p) {
                $this->assertSame($status, $this->req("/api/v1/reports/{$p}", $t)->status(), "{$role} on {$p}");
            }
        }
        $this->assertProblem($this->req('/api/v1/reports/trial-balance?level=account'), 422, 'validation_failed');
        $this->assertProblem($this->req('/api/v1/reports/trial-balance?date_from=2026-01-01'), 422, 'validation_failed')->assertJsonPath('errors.date_from.0', 'This field is not part of the API.');
        $this->assertProblem($this->req('/api/v1/reports/profit-and-loss?date_from=2026-10-10&date_to=2026-10-01'), 422, 'validation_failed');
        $this->assertProblem($this->req('/api/v1/reports/general-ledger?ledger_ids=1,abc'), 422, 'validation_failed');
        $this->assertProblem($this->req('/api/v1/reports/trial-balance?format=docx'), 422, 'validation_failed');

        // Another company's ledgers and branches are not visible, and its books never leak into these (07 §7.4).
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->useBooks();
        $cash = $this->ledger('1101-01');
        $ho = DB::table('branches')->where('company_id', $this->books->id)->value('id');
        $this->assertProblem($this->api('GET', "/api/v1/reports/general-ledger?ledger_ids={$cash}", $ot, $other->id), 422, 'validation_failed')
            ->assertJsonPath('errors.ledger_ids.0', 'One of the ledgers does not exist');
        $this->assertProblem($this->api('GET', "/api/v1/reports/trial-balance?branch_id={$ho}", $ot, $other->id), 422, 'validation_failed');
        $this->api('GET', '/api/v1/reports/trial-balance', $ot, $other->id)->assertOk()->assertJsonPath('data.totals.debit', '0.00')->assertJsonPath('data.rows', []);
        $this->api('GET', '/api/v1/reports/general-ledger', $ot, $other->id)->assertOk()->assertJsonPath('data.rows', []);
        $this->api('GET', '/api/v1/reports/balance-sheet', $ot, $other->id)->assertOk()->assertJsonPath('data.totals.assets', '0.00');
        $this->useBooks();
        $this->req('/api/v1/reports/trial-balance')->assertJsonPath('data.totals.debit', '122200.00');
    }
}
