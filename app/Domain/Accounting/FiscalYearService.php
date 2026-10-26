<?php

namespace App\Domain\Accounting;

use App\Domain\Access\CompanyProvisioner;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Financial years (K4; 06 §16; BR-034; R-05; TC-ACC-016; backlog P7.1-BE-07).
 *  - openNext(): the next year — row, 12 periods, number series — is created 30 days before the current one ends
 *    (daily scheduler `pluto:open-next-year`) or when a year closes, so July can be posted while June is being closed.
 *  - close(): only after the year's end date, with every period locked and the year still open
 *    (fiscal_year_not_closable). It posts the year_end_close entry dated the last day — the one entry allowed into a
 *    locked period — moving each income and expense ledger's balance for the year to retained earnings, then marks
 *    the year closed. A year with no income or expense closes without an entry.
 */
final class FiscalYearService
{
    public const OPEN_AHEAD_DAYS = 30;

    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly CompanyProvisioner $provisioner,
        private readonly CurrentCompany $current,
        private readonly AuditLogger $audit,
    ) {}

    /** @return FiscalYear|null the year it opened, or null when the next year already exists or is not due yet */
    public function openNext(?CarbonImmutable $today = null, bool $force = false): ?FiscalYear
    {
        $today ??= CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $latest = FiscalYear::orderByDesc('start_date')->first();
        if (! $latest || (! $force && $today->lt(CarbonImmutable::parse($latest->end_date)->subDays(self::OPEN_AHEAD_DAYS)))) {
            return null;
        }
        $next = CarbonImmutable::parse($latest->end_date)->addDay();
        $id = $this->provisioner->openYear($this->current->id(), $next->year, $next->month, $today);
        $year = FiscalYear::findOrFail($id);
        $this->audit->record('fiscal_year_opened', $year, null, ['name' => $year->name], type: 'fiscal_years');

        return $year;
    }

    public function close(FiscalYear $year): FiscalYear
    {
        return DB::transaction(function () use ($year) {
            $year = FiscalYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
            $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
            if ($year->status === 'closed') {
                throw new ApiProblem('fiscal_year_not_closable', "{$year->name} is already closed.");
            }
            if (! $today->gt($year->end_date)) {
                throw new ApiProblem('fiscal_year_not_closable', "{$year->name} ends on ".$year->end_date->format('j M Y').'; it can be closed after that day (BR-034).');
            }
            $open = AccountingPeriod::where('fiscal_year_id', $year->id)->where('status', '!=', 'locked')->orderBy('start_date')->pluck('name');
            if ($open->isNotEmpty()) {
                throw new ApiProblem('fiscal_year_not_closable', 'Lock every period first; still open: '.$open->implode(', ').' (BR-034).', extra: ['open_periods' => $open->all()]);
            }
            $last = AccountingPeriod::where('fiscal_year_id', $year->id)->orderByDesc('end_date')->firstOrFail();

            $rows = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->join('account_groups as a', 'a.id', '=', 'g.account_group_id')
                ->where('l.is_posted', 1)->whereBetween('l.entry_date', [$year->start_date->toDateString(), $year->end_date->toDateString()])
                ->whereIn('a.nature', ['income', 'expense'])->groupBy('l.ledger_id')->orderBy('l.ledger_id')
                ->selectRaw('l.ledger_id, CAST(SUM(l.debit) - SUM(l.credit) AS DECIMAL(18,2)) net')->get();
            $b = new JournalBuilder;
            $profit = Money::zero();
            foreach ($rows as $r) {
                $net = Money::of((string) $r->net);
                $b->credit((int) $r->ledger_id, $net); // a debit balance is credited away, a credit balance debited (negative → other side)
                $profit = $profit->minus($net);
            }
            $entry = null;
            if ($rows->contains(fn ($r) => ! Money::of((string) $r->net)->isZero())) {
                $b->credit($this->mapper->ledgerFor('retained_earnings')->id, $profit); // profit → Cr retained earnings, loss → Dr
                $entry = $this->posting->persist($b, $year, 'year_end_close', $year->end_date, null,
                    "Year-end close of {$year->name}: ".($profit->isNegative() ? 'loss' : 'profit')." {$profit->abs()} to retained earnings", null, null, null, $last);
            }
            $year->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id()])->save();
            $this->audit->record('fiscal_year_closed', $year, ['status' => 'open'], ['status' => 'closed', 'profit' => (string) $profit, 'entry' => $entry?->voucher_number],
                type: 'fiscal_years');
            if (! FiscalYear::where('start_date', '>', $year->end_date)->exists()) {
                $this->openNext($today, force: true);
            }

            return $year->refresh()->load('periods.lockedBy');
        });
    }
}
