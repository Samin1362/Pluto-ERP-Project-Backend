<?php

namespace App\Domain\Accounting;

use App\Models\AccountingPeriod;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * BR-004: an entry date must fall in an open accounting period of an open financial year (backlog P4.1-BE-03,
 * 06 §2 and §16). Months after today are seeded as `future` and become usable when they start; a period that has not
 * started, a locked period, a closed year or a date outside every year is refused with period_locked naming why.
 */
final class PeriodGuard
{
    /** @return AccountingPeriod the open period containing the date (its fiscal_year_id goes on the entry) */
    public function assertOpen(CarbonInterface|string $date, ?CarbonInterface $today = null): AccountingPeriod
    {
        $day = CarbonImmutable::parse($date instanceof CarbonInterface ? $date->toDateString() : $date)->startOfDay();
        $today = CarbonImmutable::parse(($today ?? CarbonImmutable::now('Asia/Dhaka'))->toDateString());
        $label = $day->format('j M Y');

        $period = AccountingPeriod::with('fiscalYear')->whereDate('start_date', '<=', $day)->whereDate('end_date', '>=', $day)->first();
        if (! $period) {
            throw new ApiProblem('period_locked', "No financial year covers {$label}. Create the year in Settings › Financial years first.",
                extra: ['entry_date' => $day->toDateString()]);
        }
        $extra = ['entry_date' => $day->toDateString(), 'period' => $period->name];
        if ($period->fiscalYear?->status === 'closed') {
            throw new ApiProblem('period_locked', "{$period->fiscalYear->name} is closed; {$label} cannot be posted.", extra: $extra);
        }
        if ($period->status === 'locked') {
            throw new ApiProblem('period_locked', "{$period->name} is locked".($period->lock_note ? " ({$period->lock_note})" : '')."; {$label} cannot be posted.", extra: $extra);
        }
        if ($period->status === 'future' && $period->start_date->greaterThan($today)) {
            throw new ApiProblem('period_locked', "{$period->name} has not started yet; {$label} cannot be posted.", extra: $extra);
        }

        return $period; // open, or a future period that has started (a later date in an open month is fine)
    }
}
