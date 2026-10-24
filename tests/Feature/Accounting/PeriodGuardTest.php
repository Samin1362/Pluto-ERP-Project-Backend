<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\PeriodGuard;
use App\Domain\Shared\Money;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Ledger;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** PeriodGuard (BR-004; 06 §2, §16; TC-ACC-004 part) and the journal models' casts (backlog P4.1-BE-03). */
class PeriodGuardTest extends TestCase
{
    private Company $company;

    private PeriodGuard $guard;

    private FiscalYear $fy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        app(CurrentCompany::class)->set($this->company, null);
        $this->guard = app(PeriodGuard::class);
        $this->fy = FiscalYear::firstOrFail();
    }

    private function refused(string $date, string $needle, ?CarbonImmutable $today = null): void
    {
        try {
            $this->guard->assertOpen($date, $today);
            $this->fail("{$date} was accepted");
        } catch (ApiProblem $e) {
            $this->assertSame('period_locked', $e->errorCode);
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString($needle, $e->detail);
        }
    }

    public function test_an_open_period_returns_itself_for_the_entry(): void
    {
        $open = AccountingPeriod::where('status', 'open')->orderBy('start_date')->firstOrFail();
        $p = $this->guard->assertOpen($open->start_date->toDateString());
        $this->assertSame($open->id, $p->id);
        $this->assertSame($this->fy->id, $p->fiscal_year_id);
        // A later day in the same open month is fine (postdated within the month).
        $this->assertSame($open->id, $this->guard->assertOpen($open->end_date->toDateString(), CarbonImmutable::parse($open->start_date->toDateString()))->id);
    }

    public function test_tc_acc_004_a_locked_period_is_refused_naming_it(): void
    {
        $p = AccountingPeriod::where('status', 'open')->orderBy('start_date')->firstOrFail();
        $p->update(['status' => 'locked', 'lock_note' => 'VAT return filed']);
        $this->refused($p->start_date->addDays(3)->toDateString(), "{$p->name} is locked (VAT return filed)");
    }

    public function test_a_future_period_opens_when_it_starts(): void
    {
        $p = AccountingPeriod::orderByDesc('start_date')->firstOrFail();
        $p->update(['status' => 'future']);
        $start = CarbonImmutable::parse($p->start_date->toDateString());
        $this->refused($start->toDateString(), "{$p->name} has not started yet", $start->subDay());
        $this->assertSame($p->id, $this->guard->assertOpen($start->toDateString(), $start)->id, 'usable from its first day');
    }

    public function test_dates_outside_every_year_and_closed_years_are_refused(): void
    {
        $this->refused(CarbonImmutable::parse($this->fy->start_date->toDateString())->subDay()->toDateString(), 'No financial year covers');
        $this->refused(CarbonImmutable::parse($this->fy->end_date->toDateString())->addDay()->toDateString(), 'No financial year covers');
        $this->fy->update(['status' => 'closed']);
        $open = AccountingPeriod::where('status', 'open')->firstOrFail();
        $this->refused($open->start_date->toDateString(), "{$this->fy->name} is closed");
    }

    public function test_periods_of_another_company_are_not_used(): void
    {
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        AccountingPeriod::query()->update(['status' => 'locked']);
        app(CurrentCompany::class)->set($other, null);
        $open = AccountingPeriod::where('status', 'open')->firstOrFail();
        $this->assertSame($other->id, $this->guard->assertOpen($open->start_date->toDateString())->company_id);
    }

    public function test_journal_models_cast_amounts_to_value_objects(): void
    {
        $period = AccountingPeriod::where('status', 'open')->firstOrFail();
        $entry = JournalEntry::create(['fiscal_year_id' => $this->fy->id, 'accounting_period_id' => $period->id, 'entry_type' => 'journal',
            'voucher_number' => 'JV-TEST-1', 'entry_date' => $period->start_date, 'total_debit' => Money::of('626000'), 'total_credit' => '626000.00']);
        $ledger = Ledger::where('code', '1301-01')->firstOrFail();
        JournalLine::create(['journal_entry_id' => $entry->id, 'line_no' => 1, 'ledger_id' => $ledger->id, 'debit' => '626000', 'credit' => Money::zero(),
            'exchange_rate' => '1', 'entry_date' => $period->start_date]);
        $fresh = JournalEntry::with('lines', 'period')->findOrFail($entry->id);
        $this->assertInstanceOf(Money::class, $fresh->total_debit);
        $this->assertSame('626000.00', (string) $fresh->total_debit);
        $this->assertSame('626000.00', (string) $fresh->lines[0]->debit);
        $this->assertSame('0.00', (string) $fresh->lines[0]->credit);
        $this->assertSame('1.000000', (string) $fresh->lines[0]->exchange_rate);
        $this->assertSame($period->id, $fresh->period->id);
        $this->assertSame($this->company->id, $fresh->company_id);
    }
}
