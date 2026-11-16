<?php

namespace App\Domain\Payroll;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Support\Problem\ApiProblem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Salary sheets (PEP-521; 06 §13). A month's sheet takes everyone employed in that month; someone who joined or left in
 * it is paid for their days (each part of the structure × days paid / days in month, HALF_UP to the paisa). While the
 * sheet is a draft, overtime, other earnings and deductions are typed per line; the server works out gross and net.
 * Approving posts one entry on the pay date: Dr salaries_expense (gross − deductions) / Cr salaries_payable (net).
 * Cancelling posts the mirror entry, before any salary is paid.
 */
class PayrollRunService
{
    private const STRUCTURE = ['basic_salary', 'house_rent', 'medical', 'conveyance'];

    private const EDITABLE = ['overtime', 'other_earnings', 'deductions', 'note'];

    public function __construct(private readonly AccountMapper $mapper, private readonly PostingService $posting, private readonly AuditLogger $audit) {}

    public function create(string $month, ?string $payDate): PayrollRun
    {
        $start = CarbonImmutable::parse("{$month}-01")->startOfDay();
        $end = $start->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($start, $end, $payDate) {
            if (PayrollRun::where('kind', 'salary')->whereDate('period_month', $start->toDateString())->where('status', '!=', 'cancelled')->lockForUpdate()->exists()) {
                throw new ApiProblem('payroll_run_exists', 'There is already a salary sheet for '.$start->format('F Y').'; open it, or cancel it first.');
            }
            $employees = Employee::whereDate('joining_date', '<=', $end->toDateString())
                ->where(fn ($q) => $q->where('status', 'active')->orWhereDate('left_on', '>=', $start->toDateString()))
                ->orderBy('code')->get();
            if ($employees->isEmpty()) {
                throw new ApiProblem('no_employees', 'Nobody was employed in '.$start->format('F Y').'. Add employees first.');
            }
            $run = PayrollRun::create(['kind' => 'salary', 'period_month' => $start->toDateString(), 'pay_date' => $payDate ?? $end->toDateString(), 'status' => 'draft']);
            $daysInMonth = (int) $start->daysInMonth;
            foreach ($employees as $e) {
                $from = $e->joining_date->gt($start) ? CarbonImmutable::parse($e->joining_date) : $start;
                $to = $e->left_on && $e->left_on->lt($end) ? CarbonImmutable::parse($e->left_on) : $end;
                $days = max(0, (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1);
                $line = new PayrollLine(['payroll_run_id' => $run->id, 'employee_id' => $e->id, 'employee_code' => $e->code, 'employee_name' => $e->name,
                    'designation' => $e->designation, 'days_in_month' => $daysInMonth, 'days_paid' => min($days, $daysInMonth),
                    'overtime' => '0', 'other_earnings' => '0', 'deductions' => '0', 'advance_recovery' => '0', 'paid_amount' => '0']);
                foreach (self::STRUCTURE as $k) {
                    $line->{$k} = (string) self::prorate((string) $e->{$k}, $line->days_paid, $daysInMonth);
                }
                $this->work($line);
                $line->save();
            }
            $this->totals($run);
            $this->audit->record('created', $run, null, ['month' => $start->format('Y-m'), 'employees' => $run->employees_count, 'total_net' => (string) $run->total_net],
                type: 'payroll_runs');

            return $run->refresh();
        });
    }

    public function update(PayrollRun $run, array $in, int $expectedVersion): PayrollRun
    {
        return DB::transaction(function () use ($run, $in, $expectedVersion) {
            $run = $this->lockDraft($run, $expectedVersion);
            $old = $run->only(['pay_date', 'notes', 'total_net']);
            if (array_key_exists('pay_date', $in)) {
                $run->pay_date = $in['pay_date'];
            }
            if (array_key_exists('notes', $in)) {
                $run->notes = $in['notes'];
            }
            $lines = $run->lines()->get()->keyBy('id');
            foreach ($in['lines'] ?? [] as $i => $change) {
                $line = $lines[(int) $change['id']] ?? throw new ApiProblem('validation_failed', errors: ["lines.{$i}.id" => ['This line is not on this sheet']]);
                foreach (self::EDITABLE as $k) {
                    if (array_key_exists($k, $change)) {
                        $line->{$k} = $k === 'note' ? $change[$k] : (string) Money::of($change[$k] ?? '0');
                    }
                }
                if ($this->work($line)->isNegative()) {
                    throw new ApiProblem('validation_failed', errors: ["lines.{$i}.deductions" => ["Deductions are more than {$line->employee_name}'s pay this month"]]);
                }
                $line->save();
            }
            $this->totals($run);
            $this->audit->record('updated', $run, $old, $run->only(['pay_date', 'notes', 'total_net']), type: 'payroll_runs');

            return $run->refresh();
        });
    }

    public function delete(PayrollRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = $this->lockDraft($run, null);
            $this->audit->record('deleted', $run, ['month' => $run->period_month->format('Y-m'), 'total_net' => (string) $run->total_net], null, type: 'payroll_runs');
            $run->lines()->delete();
            $run->delete();
        });
    }

    public function approve(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = $this->lockDraft($run, null);
            $lines = $run->lines()->get();
            $expense = Money::zero();
            $net = Money::zero();
            foreach ($lines as $l) {
                $expense = $expense->plus(Money::of((string) $l->gross))->minus(Money::of((string) $l->deductions));
                $net = $net->plus(Money::of((string) $l->net));
            }
            $label = self::label($run);
            $entry = null;
            if ($expense->isPositive()) {
                $b = (new JournalBuilder)->debit($this->mapper->ledgerFor('salaries_expense'), $expense, ['line_narration' => $label])
                    ->credit($this->mapper->ledgerFor('salaries_payable'), $net, ['line_narration' => $label]);
                $entry = $this->posting->persist($b, $run, 'journal', $run->pay_date->toDateString(), null, "{$label}: {$run->employees_count} employees", $label);
            }
            $run->forceFill(['status' => 'approved', 'journal_entry_id' => $entry?->id, 'approved_at' => now(), 'approved_by' => Auth::id()])->save();
            $this->audit->record('approved', $run, ['status' => 'draft'], ['status' => 'approved', 'voucher' => $entry?->voucher_number, 'total_net' => (string) $net], type: 'payroll_runs');

            return $run->refresh();
        }, attempts: 2);
    }

    public function cancel(PayrollRun $run, string $reason): PayrollRun
    {
        return DB::transaction(function () use ($run, $reason) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'approved') {
                throw new ApiProblem('payroll_run_not_approved', $run->status === 'draft' ? 'This sheet is a draft; delete it instead.' : 'This sheet is already cancelled.');
            }
            if ($run->lines()->where('paid_amount', '>', 0)->exists()) {
                throw new ApiProblem('payroll_run_not_approved', 'Some salaries on this sheet are paid; reverse those payments first.');
            }
            $reversal = null;
            if ($run->journal_entry_id) {
                $entry = JournalEntry::with('lines')->whereKey($run->journal_entry_id)->lockForUpdate()->firstOrFail();
                $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
                $date = $date < $entry->entry_date->toDateString() ? $entry->entry_date->toDateString() : $date;
                $b = new JournalBuilder;
                foreach ($entry->lines as $l) {
                    $b->debit($l->ledger_id, Money::of((string) $l->credit))->credit($l->ledger_id, Money::of((string) $l->debit));
                }
                $reversal = $this->posting->persist($b, null, 'reversal', $date, null, "Reversal of {$entry->voucher_number} (".self::label($run)."): {$reason}",
                    $entry->voucher_number, null, $entry->id);
                $entry->forceFill(['status' => 'reversed'])->save();
            }
            $run->forceFill(['status' => 'cancelled', 'reversal_entry_id' => $reversal?->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->audit->record('cancelled', $run, ['status' => 'approved'], ['status' => 'cancelled', 'reason' => $reason, 'reversal' => $reversal?->voucher_number], type: 'payroll_runs');

            return $run->refresh();
        }, attempts: 2);
    }

    /** PayrollRun (05 schema); lines when asked. */
    public static function resource(PayrollRun $run, bool $withLines = false): array
    {
        $run->loadMissing(['journalEntry:id,voucher_number', 'approver:id,name']);
        // PEP-522: what is paid so far, from the lines; part paid until every net is paid.
        $paid = Money::of((string) PayrollLine::where('payroll_run_id', $run->id)->sum('paid_amount'));
        $status = ! $paid->isPositive() ? 'unpaid' : ($paid->isLessThan(Money::of((string) $run->total_net)) ? 'part_paid' : 'paid');
        $out = ['id' => $run->id, 'kind' => $run->kind, 'period_month' => $run->period_month->toDateString(), 'label' => self::label($run),
            'pay_date' => $run->pay_date->toDateString(), 'status' => $run->status, 'employees_count' => $run->employees_count,
            'total_gross' => (string) Money::of((string) $run->total_gross), 'total_deductions' => (string) Money::of((string) $run->total_deductions),
            'total_net' => (string) Money::of((string) $run->total_net), 'total_paid' => (string) $paid, 'payment_status' => $status,
            'notes' => $run->notes, 'journal_entry_id' => $run->journal_entry_id,
            'voucher_number' => $run->journalEntry?->voucher_number, 'approved_at' => $run->approved_at?->toIso8601String(),
            'approved_by' => $run->approver ? ['id' => $run->approver->id, 'name' => $run->approver->name] : null, 'cancel_reason' => $run->cancel_reason,
            'version' => $run->lock_version];
        if ($withLines) {
            $out['lines'] = $run->lines()->get()->map(fn (PayrollLine $l) => ['id' => $l->id, 'employee_id' => $l->employee_id, 'employee_code' => $l->employee_code,
                'employee_name' => $l->employee_name, 'designation' => $l->designation, 'days_in_month' => $l->days_in_month, 'days_paid' => $l->days_paid]
                + collect([...self::STRUCTURE, 'overtime', 'other_earnings', 'deductions', 'advance_recovery', 'gross', 'net', 'paid_amount'])
                    ->mapWithKeys(fn ($k) => [$k => (string) Money::of((string) $l->{$k})])->all()
                + ['note' => $l->note])->all();
        }

        return $out;
    }

    public static function label(PayrollRun $run): string
    {
        return ($run->kind === 'bonus' ? 'Festival bonus, ' : 'Salaries, ').$run->period_month->format('F Y');
    }

    public static function prorate(string $monthly, int $days, int $daysInMonth): Money
    {
        if ($days >= $daysInMonth) {
            return Money::of($monthly);
        }

        return Money::of(BigDecimal::of($monthly)->multipliedBy($days)->dividedBy($daysInMonth, 2, RoundingMode::HALF_UP));
    }

    /** gross = structure + overtime + other earnings; net = gross − deductions − advance recovery. Returns the net. */
    private function work(PayrollLine $l): Money
    {
        $gross = Money::zero();
        foreach ([...self::STRUCTURE, 'overtime', 'other_earnings'] as $k) {
            $gross = $gross->plus(Money::of((string) ($l->{$k} ?? '0')));
        }
        $net = $gross->minus(Money::of((string) ($l->deductions ?? '0')))->minus(Money::of((string) ($l->advance_recovery ?? '0')));
        $l->gross = (string) $gross;
        $l->net = (string) $net;

        return $net;
    }

    private function totals(PayrollRun $run): void
    {
        $lines = $run->lines()->get();
        $gross = Money::zero();
        $ded = Money::zero();
        $net = Money::zero();
        foreach ($lines as $l) {
            $gross = $gross->plus(Money::of((string) $l->gross));
            $ded = $ded->plus(Money::of((string) $l->deductions))->plus(Money::of((string) $l->advance_recovery));
            $net = $net->plus(Money::of((string) $l->net));
        }
        $run->forceFill(['employees_count' => $lines->count(), 'total_gross' => (string) $gross, 'total_deductions' => (string) $ded, 'total_net' => (string) $net])->save();
    }

    private function lockDraft(PayrollRun $run, ?int $expectedVersion): PayrollRun
    {
        $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($run->status !== 'draft') {
            throw new ApiProblem('payroll_run_not_draft', 'This sheet is '.$run->status.'; only a draft can be changed.');
        }
        if ($expectedVersion !== null && $run->lock_version !== $expectedVersion) {
            throw new ApiProblem('version_conflict', extra: ['current_version' => $run->lock_version]);
        }

        return $run;
    }
}
