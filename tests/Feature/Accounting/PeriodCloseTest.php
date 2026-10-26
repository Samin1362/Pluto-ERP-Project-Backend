<?php

namespace Tests\Feature\Accounting;

use App\Domain\Access\CompanyProvisioner;
use App\Domain\Accounting\FiscalYearService;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\Money;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Ledger;
use App\Models\NumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Period lock/unlock, year close and opening the next year (K4; BR-033, BR-034; R-05; TC-ACC-015/016; backlog P7.1-BE-07). */
class PeriodCloseTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $headers = str_contains($uri, '/close') ? ['Idempotency-Key' => (string) Str::uuid()] : [];
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    /** @return list<AccountingPeriod> the periods of the year that contains `$date`, in date order */
    private function periods(?string $date = null): array
    {
        $this->useBooks();
        $date ??= CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $fy = FiscalYear::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();

        return AccountingPeriod::where('fiscal_year_id', $fy->id)->orderBy('start_date')->get()->all();
    }

    private function ledger(string $code): int
    {
        $this->useBooks();

        return (int) Ledger::where('code', $code)->value('id');
    }

    public function test_t_c_ac_c_015_periods_lock_in_date_order_and_unlock_with_a_reason(): void
    {
        $p = $this->periods();
        $today = CarbonImmutable::now('Asia/Dhaka');
        if ($p[1]->start_date->greaterThan($today)) {
            $this->markTestSkipped('The financial year has only one started month');
        }
        $this->assertProblem($this->req('POST', "/api/v1/accounting-periods/{$p[1]->id}/lock"), 422, 'lock_out_of_order')->assertJsonPath('period', $p[0]->name);
        $r = $this->req('POST', "/api/v1/accounting-periods/{$p[0]->id}/lock", ['note' => 'Reconciled'])->assertOk();
        $r->assertJsonPath('data.status', 'locked')->assertJsonPath('data.lock_note', 'Reconciled')->assertJsonPath('data.locked_by.name', 'Owner');
        $this->req('POST', "/api/v1/accounting-periods/{$p[0]->id}/lock")->assertOk()->assertJsonPath('data.lock_note', 'Reconciled'); // no-op
        $this->req('POST', "/api/v1/accounting-periods/{$p[1]->id}/lock")->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/accounting-periods/{$p[0]->id}/unlock", ['reason' => 'Late bill']), 422, 'lock_out_of_order')
            ->assertJsonPath('period', $p[1]->name);
        $this->assertProblem($this->req('POST', "/api/v1/accounting-periods/{$p[1]->id}/unlock"), 422, 'validation_failed');
        $this->req('POST', "/api/v1/accounting-periods/{$p[1]->id}/unlock", ['reason' => 'Late supplier bill'])->assertOk()->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.locked_by', null);
        $this->assertSame(['reason' => 'Late supplier bill', 'status' => 'open'], collect(AuditLog::where('action', 'period_unlocked')->sole()->new_values)->sortKeys()->all());

        $future = collect($p)->first(fn ($x) => $x->start_date->greaterThan($today));
        if ($future) {
            $this->assertProblem($this->req('POST', "/api/v1/accounting-periods/{$future->id}/lock"), 422, 'validation_failed');
        }
        $this->userWithRole('accountant');
        $this->assertProblem($this->req('POST', "/api/v1/accounting-periods/{$p[1]->id}/lock", token: $this->login('accountant@meghna.example')), 403, 'permission_denied');
        $this->req('GET', '/api/v1/fiscal-years')->assertOk()->assertJsonPath('data.0.periods.0.status', 'locked');
    }

    public function test_t_c_ac_c_016_closing_a_year_moves_its_profit_to_retained_earnings(): void
    {
        $this->useBooks();
        $current = FiscalYear::firstOrFail();
        $prevStart = CarbonImmutable::parse($current->start_date)->subYear();
        $prevId = app(CompanyProvisioner::class)->openYear($this->books->id, $prevStart->year, $prevStart->month, CarbonImmutable::now('Asia/Dhaka'));
        $prev = FiscalYear::findOrFail($prevId);
        $posting = app(PostingService::class);
        $posting->persist((new JournalBuilder)->debit($this->ledger('1101-01'), Money::of('20000'))->credit($this->ledger('4101'), Money::of('20000')),
            null, 'journal', $prevStart->addMonths(2), 'JV-T-1', 'Cash sales');
        $posting->persist((new JournalBuilder)->debit($this->ledger('6101'), Money::of('12000'))->credit($this->ledger('1101-01'), Money::of('12000')),
            null, 'journal', $prevStart->addMonths(3), 'JV-T-2', 'Rent');

        $this->assertProblem($this->req('POST', "/api/v1/fiscal-years/{$prevId}/close"), 422, 'fiscal_year_not_closable');
        foreach (AccountingPeriod::where('fiscal_year_id', $prevId)->orderBy('start_date')->pluck('id') as $id) {
            $this->req('POST', "/api/v1/accounting-periods/{$id}/lock")->assertOk();
        }
        $this->userWithRole('finance');
        $this->assertProblem($this->req('POST', "/api/v1/fiscal-years/{$prevId}/close", token: $this->login('finance@meghna.example')), 403, 'permission_denied');

        $r = $this->req('POST', "/api/v1/fiscal-years/{$prevId}/close")->assertOk();
        $r->assertJsonPath('data.status', 'closed')->assertJsonPath('data.name', $prev->name)->assertJsonPath('data.periods.11.status', 'locked');
        $this->useBooks();
        $entry = JournalEntry::with('lines')->where('entry_type', 'year_end_close')->sole();
        $this->assertSame([$prev->end_date->toDateString(), 'posted', 'fiscal_years', $prevId], [$entry->entry_date->toDateString(), $entry->status, $entry->source_type, $entry->source_id]);
        $this->assertMatchesRegularExpression('/^SJ-/', $entry->voucher_number);
        $net = fn (string $code) => (string) DB::table('journal_lines')->where('ledger_id', $this->ledger($code))->where('is_posted', 1)
            ->whereBetween('entry_date', [$prev->start_date->toDateString(), $prev->end_date->toDateString()])->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b');
        $this->assertSame(['0.00', '0.00', '-8000.00', '8000.00'], [$net('4101'), $net('6101'), $net('3201'), $net('1101-01')]);

        $this->assertProblem($this->req('POST', "/api/v1/fiscal-years/{$prevId}/close"), 422, 'fiscal_year_not_closable');
        $this->assertProblem($this->req('POST', "/api/v1/fiscal-years/{$current->id}/close"), 422, 'fiscal_year_not_closable');
        $this->assertProblem($this->req('POST', '/api/v1/accounting-periods/'.AccountingPeriod::where('fiscal_year_id', $prevId)->orderByDesc('start_date')->value('id').'/unlock',
            ['reason' => 'Late bill']), 422, 'validation_failed');
        $this->useBooks();
        $this->assertSame(1, AuditLog::where('action', 'fiscal_year_closed')->count());
    }

    public function test_the_next_year_opens_thirty_days_before_the_end(): void
    {
        $this->useBooks();
        $current = FiscalYear::firstOrFail();
        $years = app(FiscalYearService::class);
        $this->assertNull($years->openNext(CarbonImmutable::parse($current->end_date)->subDays(31)));
        $next = $years->openNext(CarbonImmutable::parse($current->end_date)->subDays(29));
        $this->assertSame(CarbonImmutable::parse($current->end_date)->addDay()->toDateString(), $next->start_date->toDateString());
        $this->assertSame(12, AccountingPeriod::where('fiscal_year_id', $next->id)->where('status', 'future')->count());
        $this->assertSame('JV-'.$next->start_date->year.'-', NumberSequence::where('fiscal_year_id', $next->id)->where('document_type', 'journal')->value('prefix'));
        $this->assertNull($years->openNext(CarbonImmutable::parse($current->end_date)->subDays(5)), 'only once');
        $this->artisan('pluto:open-next-year', ['--company' => $this->books->id])->assertSuccessful();
    }
}
