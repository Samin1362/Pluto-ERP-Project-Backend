<?php

namespace App\Domain\Accounting;

use App\Domain\Shared\AuditLogger;
use App\Models\AccountingPeriod;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Period lock and unlock (K4; 05 accounting-periods; BR-033; TC-ACC-015; backlog P7.1-BE-07). Periods lock in date
 * order — every earlier period must already be locked — and unlock in reverse — every later one must be open; a
 * period that has not started cannot be locked, and a closed year's periods stay locked. Locking again is a no-op.
 * Unlocking needs a reason. Both are audited. (The pre-lock backup of the contract's transaction note comes with the
 * backup feature; no backup service exists yet.)
 */
final class PeriodService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function lock(AccountingPeriod $period, ?string $note): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $note) {
            $period = AccountingPeriod::with('fiscalYear')->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->status === 'locked') {
                return $period;
            }
            if ($period->start_date->greaterThan(CarbonImmutable::now('Asia/Dhaka')->startOfDay())) {
                throw new ApiProblem('validation_failed', errors: ['period' => ["{$period->name} has not started; it cannot be locked yet"]]);
            }
            $open = AccountingPeriod::where('start_date', '<', $period->start_date)->where('status', '!=', 'locked')->orderBy('start_date')->first();
            if ($open) {
                throw new ApiProblem('lock_out_of_order', "Lock {$open->name} first: periods lock in date order (BR-033).", extra: ['period' => $open->name]);
            }
            $period->forceFill(['status' => 'locked', 'locked_at' => now(), 'locked_by' => auth()->id(), 'lock_note' => $note])->save();
            $this->audit->record('period_locked', $period, ['status' => 'open'], ['status' => 'locked', 'note' => $note], type: 'accounting_periods');

            return $period->load('lockedBy');
        });
    }

    public function unlock(AccountingPeriod $period, string $reason): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $reason) {
            $period = AccountingPeriod::with('fiscalYear')->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($period->status !== 'locked') {
                throw new ApiProblem('validation_failed', errors: ['period' => ["{$period->name} is not locked"]]);
            }
            if ($period->fiscalYear->status === 'closed') {
                throw new ApiProblem('validation_failed', errors: ['period' => ["{$period->fiscalYear->name} is closed; its periods stay locked"]]);
            }
            $later = AccountingPeriod::where('start_date', '>', $period->start_date)->where('status', 'locked')->orderByDesc('start_date')->first();
            if ($later) {
                throw new ApiProblem('lock_out_of_order', "Unlock {$later->name} first: periods unlock from the latest back (BR-033).", extra: ['period' => $later->name]);
            }
            $old = ['status' => 'locked', 'locked_at' => $period->locked_at?->toIso8601String(), 'note' => $period->lock_note];
            $period->forceFill(['status' => 'open', 'locked_at' => null, 'locked_by' => null, 'lock_note' => null])->save();
            $this->audit->record('period_unlocked', $period, $old, ['status' => 'open', 'reason' => $reason], type: 'accounting_periods');

            return $period->load('lockedBy');
        });
    }
}
