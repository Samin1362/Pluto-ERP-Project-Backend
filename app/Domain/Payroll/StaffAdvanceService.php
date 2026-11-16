<?php

namespace App\Domain\Payroll;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Accounting\VoucherCalculator;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\PayrollLine;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceRecovery;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff advances (PEP-523; 06 §13). Giving one posts Dr staff_advances / Cr the money account (BR-031 funds). A new
 * salary sheet takes each open advance's instalment (never more than its balance, nor more than the month's pay);
 * approving the sheet credits staff_advances and records which advance was taken back, oldest first; cancelling the
 * sheet undoes that. An advance nothing was taken back from can be reversed.
 */
class StaffAdvanceService
{
    public function __construct(private readonly AccountMapper $mapper, private readonly PostingService $posting, private readonly VoucherCalculator $calculator,
        private readonly AuditLogger $audit) {}

    public function give(array $in): StaffAdvance
    {
        return DB::transaction(function () use ($in) {
            $employee = Employee::where('status', 'active')->find((int) $in['employee_id'])
                ?? throw new ApiProblem('validation_failed', errors: ['employee_id' => ['Choose an employee who is still working']]);
            $account = MoneyAccount::where('is_active', true)->find((int) $in['money_account_id'])
                ?? throw new ApiProblem('validation_failed', errors: ['money_account_id' => ['Choose an active cash, bank or wallet account']]);
            $amount = Money::of($in['amount']);
            $instalment = Money::of($in['instalment']);
            if (! $amount->isPositive()) {
                throw new ApiProblem('validation_failed', errors: ['amount' => ['Enter an amount above 0']]);
            }
            if (! $instalment->isPositive() || $instalment->isGreaterThan($amount)) {
                throw new ApiProblem('validation_failed', errors: ['instalment' => ['Enter a monthly amount above 0 and not more than the advance']]);
            }
            $label = "Salary advance to {$employee->name} ({$employee->code})";
            $b = (new JournalBuilder)->debit($this->mapper->ledgerFor('staff_advances'), $amount, ['line_narration' => $label])
                ->credit((int) $account->ledger_id, $amount, ['line_narration' => $label, 'against_reference' => $in['reference'] ?? null]);
            $short = $this->calculator->funds(array_map(fn ($l) => ['ledger_id' => $l['ledger_id'], 'debit' => Money::of((string) $l['debit']), 'credit' => Money::of((string) $l['credit'])], $b->lines()));
            if ($short !== []) {
                throw new ApiProblem('insufficient_funds', $short[0]['message'].' (BR-031).');
            }
            $a = StaffAdvance::create(['employee_id' => $employee->id, 'money_account_id' => $account->id, 'given_on' => $in['given_on'], 'amount' => (string) $amount,
                'instalment' => (string) $instalment, 'recovered' => '0', 'status' => 'open', 'reference' => $in['reference'] ?? null, 'notes' => $in['notes'] ?? null]);
            $entry = $this->posting->persist($b, $a, 'journal', $in['given_on'], null, $label, $in['reference'] ?? $employee->code);
            $a->forceFill(['journal_entry_id' => $entry->id])->save();
            $this->audit->record('created', $a, null, ['employee' => $employee->code, 'amount' => (string) $amount, 'instalment' => (string) $instalment, 'voucher' => $entry->voucher_number],
                type: 'staff_advances');

            return $a->refresh();
        }, attempts: 2);
    }

    public function reverse(StaffAdvance $a, string $reason): StaffAdvance
    {
        return DB::transaction(function () use ($a, $reason) {
            $a = StaffAdvance::whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($a->status === 'reversed') {
                throw new ApiProblem('already_reversed', 'This advance is already reversed.');
            }
            if (Money::of((string) $a->recovered)->isPositive() || StaffAdvanceRecovery::where('staff_advance_id', $a->id)->exists()) {
                throw new ApiProblem('advance_recovered', 'Part of this advance was taken back from a salary sheet; cancel that sheet first.');
            }
            $entry = JournalEntry::with('lines')->whereKey($a->journal_entry_id)->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $date = $date < $entry->entry_date->toDateString() ? $entry->entry_date->toDateString() : $date;
            $b = new JournalBuilder;
            foreach ($entry->lines as $l) {
                $b->debit($l->ledger_id, Money::of((string) $l->credit))->credit($l->ledger_id, Money::of((string) $l->debit));
            }
            $reversal = $this->posting->persist($b, null, 'reversal', $date, null, "Reversal of {$entry->voucher_number} (salary advance): {$reason}", $entry->voucher_number, null, $entry->id);
            $entry->forceFill(['status' => 'reversed'])->save();
            $a->forceFill(['status' => 'reversed', 'reversal_entry_id' => $reversal->id, 'reversed_at' => now(), 'reverse_reason' => $reason])->save();
            $this->audit->record('reversed', $a, ['status' => 'open'], ['status' => 'reversed', 'reason' => $reason, 'reversal' => $reversal->voucher_number], type: 'staff_advances');

            return $a->refresh();
        }, attempts: 2);
    }

    /** What an employee still owes on open advances. */
    public static function balance(int $employeeId): Money
    {
        $row = StaffAdvance::where('employee_id', $employeeId)->where('status', 'open')->selectRaw('COALESCE(SUM(amount - recovered), 0) b')->first();

        return Money::of((string) ($row->b ?? '0'));
    }

    /** This month's instalments of an employee's open advances (each capped by its balance), at most $cap. */
    public static function dueInstalments(int $employeeId, Money $cap): Money
    {
        $due = Money::zero();
        foreach (StaffAdvance::where('employee_id', $employeeId)->where('status', 'open')->orderBy('given_on')->orderBy('id')->get() as $a) {
            $left = Money::of((string) $a->amount)->minus(Money::of((string) $a->recovered));
            $due = $due->plus(Money::of((string) $a->instalment)->isGreaterThan($left) ? $left : Money::of((string) $a->instalment));
        }

        return $due->isGreaterThan($cap) ? $cap : $due;
    }

    /** Approving a sheet: take each line's recovery from that employee's open advances, oldest first. */
    public static function recover(PayrollLine $line): void
    {
        $left = Money::of((string) $line->advance_recovery);
        foreach (StaffAdvance::where('employee_id', $line->employee_id)->where('status', 'open')->orderBy('given_on')->orderBy('id')->lockForUpdate()->get() as $a) {
            if (! $left->isPositive()) {
                break;
            }
            $open = Money::of((string) $a->amount)->minus(Money::of((string) $a->recovered));
            $take = $left->isGreaterThan($open) ? $open : $left;
            if (! $take->isPositive()) {
                continue;
            }
            StaffAdvanceRecovery::create(['staff_advance_id' => $a->id, 'payroll_line_id' => $line->id, 'amount' => (string) $take]);
            $recovered = Money::of((string) $a->recovered)->plus($take);
            $a->forceFill(['recovered' => (string) $recovered, 'status' => $recovered->isLessThan(Money::of((string) $a->amount)) ? 'open' : 'settled'])->save();
            $left = $left->minus($take);
        }
        if ($left->isPositive()) {
            throw new ApiProblem('validation_failed', "{$line->employee_name} owes less on advances than the {$line->advance_recovery} to take back; change the sheet.",
                errors: ['lines' => ["{$line->employee_name}: advance recovery is more than they owe"]]);
        }
    }

    /** Cancelling a sheet: give back what its lines took from advances. */
    public static function undo(array $lineIds): void
    {
        foreach (StaffAdvanceRecovery::whereIn('payroll_line_id', $lineIds)->get() as $r) {
            $a = StaffAdvance::whereKey($r->staff_advance_id)->lockForUpdate()->first();
            $a->forceFill(['recovered' => (string) Money::of((string) $a->recovered)->minus(Money::of((string) $r->amount)), 'status' => 'open'])->save();
            $r->delete();
        }
    }

    /** StaffAdvance (05 schema). */
    public static function resource(StaffAdvance $a): array
    {
        $a->loadMissing(['employee:id,code,name', 'moneyAccount:id,name,account_type', 'journalEntry:id,voucher_number']);
        $amount = Money::of((string) $a->amount);
        $recovered = Money::of((string) $a->recovered);

        return ['id' => $a->id, 'employee' => ['id' => $a->employee->id, 'code' => $a->employee->code, 'name' => $a->employee->name],
            'money_account' => ['id' => $a->moneyAccount->id, 'name' => $a->moneyAccount->name, 'account_type' => $a->moneyAccount->account_type],
            'given_on' => $a->given_on->toDateString(), 'amount' => (string) $amount, 'instalment' => (string) Money::of((string) $a->instalment), 'recovered' => (string) $recovered,
            'balance' => (string) ($a->status === 'reversed' ? Money::zero() : $amount->minus($recovered)), 'status' => $a->status, 'reference' => $a->reference, 'notes' => $a->notes,
            'voucher_number' => $a->journalEntry?->voucher_number, 'reversed_at' => $a->reversed_at?->toIso8601String(), 'reverse_reason' => $a->reverse_reason, 'version' => $a->lock_version];
    }
}
