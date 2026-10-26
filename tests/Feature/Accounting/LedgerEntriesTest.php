<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\Money;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** GET /ledgers/{id}/entries (H7) and GET /accounting/overview (H1); BR-005; 06 §16; backlog P7.1-BE-03. */
class LedgerEntriesTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id);
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

    /** Two months inside the current financial year (its first day and a month later). */
    private function days(): array
    {
        $this->useBooks();
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $start = CarbonImmutable::parse(DB::table('fiscal_years')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->value('start_date'));

        return [$start->toDateString(), $start->addMonth()->toDateString()];
    }

    public function test_entries_run_a_balance_with_monthly_closings(): void
    {
        [$d1, $d2] = $this->days();
        $this->journal('1101-01', '3101', '50000', $d1, 'Capital introduced');
        $this->journal('6101', '1101-01', '12000', $d1, 'Rent for July');
        $this->journal('6101', '1101-01', '12000', $d2, 'Rent for August');
        $cash = $this->ledger('1101-01');

        $r = $this->req('GET', "/api/v1/ledgers/{$cash}/entries?date_from={$d1}&date_to={$d2}")->assertOk();
        $r->assertJsonPath('data.ledger.code', '1101-01')->assertJsonPath('data.period', ['from' => $d1, 'to' => $d2])
            ->assertJsonPath('data.opening', '0.00')->assertJsonPath('data.total_debit', '50000.00')->assertJsonPath('data.total_credit', '24000.00')
            ->assertJsonPath('data.closing', '26000.00')->assertJsonPath('data.closing_side', 'dr')->assertJsonPath('data.meta.total', 3);
        $this->assertSame(['50000.00', '38000.00', '26000.00'], array_column($r->json('data.lines'), 'balance'));
        $this->assertSame(['Share capital', 'Rent', 'Rent'], array_column($r->json('data.lines'), 'particulars'));
        $this->assertSame([[substr($d1, 0, 7), '50000.00', '12000.00', '38000.00', 'dr'], [substr($d2, 0, 7), '0.00', '12000.00', '26000.00', 'dr']],
            array_map(fn ($m) => array_values($m), $r->json('data.monthly')));

        // The second month alone opens with the first month's closing; a narration search keeps the balances.
        $this->req('GET', "/api/v1/ledgers/{$cash}/entries?date_from={$d2}&date_to={$d2}")->assertJsonPath('data.opening', '38000.00')
            ->assertJsonPath('data.lines.0.balance', '26000.00');
        $found = $this->req('GET', "/api/v1/ledgers/{$cash}/entries?date_from={$d1}&date_to={$d2}&narration=august")->json('data');
        $this->assertSame([['Rent for August', '26000.00']], array_map(fn ($l) => [$l['narration'], $l['balance']], $found['lines']));
        $this->assertSame('26000.00', $found['closing']);
    }

    public function test_entries_validation_permissions_and_isolation(): void
    {
        $cash = $this->ledger('1101-01');
        $this->assertProblem($this->req('GET', "/api/v1/ledgers/{$cash}/entries?date_from=2026-10-10&date_to=2026-10-01"), 422, 'validation_failed')
            ->assertJsonPath('errors.date_to.0', 'The end date must be on or after the start date');
        $this->assertProblem($this->req('GET', "/api/v1/ledgers/{$cash}/entries?per_page=500"), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/ledgers/999999/entries'), 404, 'not_found');
        $this->userWithRole('sales');
        $this->assertProblem($this->req('GET', "/api/v1/ledgers/{$cash}/entries", $this->login('sales@meghna.example')), 403, 'permission_denied');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('GET', "/api/v1/ledgers/{$cash}/entries", $this->login('padma@agro.example'), $other->id), 404, 'not_found');
    }

    public function test_the_overview_reports_tiles_health_checks_and_recent_vouchers(): void
    {
        [$d1] = $this->days();
        $this->journal('1101-01', '3101', '50000', $d1, 'Capital introduced');
        $this->journal('6101', '1101-01', '12000', $d1, 'Rent for July');
        $this->journal('1101-01', '4101', '20000', $d1, 'Cash sales');
        $this->journal('9999', '1101-01', '300', $d1, 'Not yet known');

        $r = $this->req('GET', '/api/v1/accounting/overview')->assertOk();
        $r->assertJsonPath('data.title', 'Accounting overview')->assertJsonPath('data.totals.cash_and_bank', ['amount' => '57700.00', 'side' => 'dr'])
            ->assertJsonPath('data.totals.profit', '8000.00')->assertJsonPath('data.totals.suspense', ['amount' => '300.00', 'side' => 'dr'])
            ->assertJsonPath('data.totals.drafts', 0)->assertJsonPath('data.totals.pending_approvals', 0)->assertJsonPath('data.totals.last_locked_period', null);
        $checks = collect($r->json('data.checks'))->keyBy('key');
        $this->assertTrue($checks['trial_balance']['ok']);
        $this->assertFalse($checks['suspense']['ok']);
        $this->assertSame('Suspense holds 300.00; post it to the right ledgers', $checks['suspense']['message']);
        $this->assertTrue($checks['opening_balances']['ok']);
        $this->assertFalse($checks['last_locked_period']['ok']);
        $this->assertSame(['Not yet known', 'Cash sales', 'Rent for July', 'Capital introduced'], array_column($r->json('data.rows'), 'narration'));
        $this->assertSame(['1', '2', '3', '4', '5', '6'], array_column($r->json('data.meta.groups'), 'code'));

        $this->assertProblem($this->req('GET', '/api/v1/accounting/overview?as_of=2026-10-01'), 422, 'validation_failed');
        $this->userWithRole('sales');
        $this->assertProblem($this->req('GET', '/api/v1/accounting/overview', $this->login('sales@meghna.example')), 403, 'permission_denied');
    }
}
