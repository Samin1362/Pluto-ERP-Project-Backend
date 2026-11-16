<?php

namespace App\Domain\Payroll;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Accounting\VoucherCalculator;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\PayrollLine;
use App\Models\PayrollPayment;
use App\Models\PayrollPaymentLine;
use App\Models\PayrollRun;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Paying salaries (PEP-522; 06 §13). A payment takes money out of one account on one day for some or all of an approved
 * sheet's lines (without lines: everything still due, in full). Each line is never paid beyond its net; the account
 * must cover it (BR-031). Posts Dr salaries_payable / Cr the account's ledger; reversing posts the mirror entry and the
 * lines are due again.
 */
class PayrollPaymentService
{
    public function __construct(private readonly AccountMapper $mapper, private readonly PostingService $posting, private readonly VoucherCalculator $calculator,
        private readonly AuditLogger $audit) {}

    public function pay(PayrollRun $run, array $in): PayrollPayment
    {
        return DB::transaction(function () use ($run, $in) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'approved') {
                throw new ApiProblem('payroll_run_not_approved', $run->status === 'draft' ? 'Approve the sheet before paying it.' : 'This sheet is cancelled.');
            }
            $account = MoneyAccount::where('is_active', true)->find((int) $in['money_account_id'])
                ?? throw new ApiProblem('validation_failed', errors: ['money_account_id' => ['Choose an active cash, bank or wallet account']]);
            $lines = PayrollLine::where('payroll_run_id', $run->id)->lockForUpdate()->orderBy('employee_code')->get()->keyBy('id');
            $pay = []; // line id => Money
            if (empty($in['lines'])) {
                foreach ($lines as $l) {
                    $due = self::due($l);
                    if ($due->isPositive()) {
                        $pay[$l->id] = $due;
                    }
                }
                if (! $pay) {
                    throw new ApiProblem('nothing_to_pay', 'Everyone on '.PayrollRunService::label($run).' is already paid.');
                }
            } else {
                foreach ($in['lines'] as $i => $want) {
                    $l = $lines[(int) $want['payroll_line_id']] ?? throw new ApiProblem('validation_failed', errors: ["lines.{$i}.payroll_line_id" => ['This employee is not on this sheet']]);
                    $amount = Money::of($want['amount']);
                    if (! $amount->isPositive()) {
                        throw new ApiProblem('validation_failed', errors: ["lines.{$i}.amount" => ['Enter an amount above 0']]);
                    }
                    if ($amount->isGreaterThan(self::due($l))) {
                        throw new ApiProblem('payroll_overpaid', "{$l->employee_name} has ".self::due($l).' still due; '.$amount.' is more.', errors: ["lines.{$i}.amount" => ['More than is still due']]);
                    }
                    $pay[$l->id] = ($pay[$l->id] ?? Money::zero())->plus($amount);
                }
            }
            $total = Money::sum(array_values($pay));
            $label = PayrollRunService::label($run);
            $b = (new JournalBuilder)->debit($this->mapper->ledgerFor('salaries_payable'), $total, ['line_narration' => $label])
                ->credit((int) $account->ledger_id, $total, ['line_narration' => $label, 'against_reference' => $in['reference'] ?? null]);
            $short = $this->calculator->funds(array_map(fn ($l) => ['ledger_id' => $l['ledger_id'], 'debit' => Money::of((string) $l['debit']), 'credit' => Money::of((string) $l['credit'])], $b->lines()));
            if ($short !== []) {
                throw new ApiProblem('insufficient_funds', $short[0]['message'].' (BR-031).');
            }
            $payment = PayrollPayment::create(['payroll_run_id' => $run->id, 'money_account_id' => $account->id, 'paid_on' => $in['paid_on'], 'amount' => (string) $total,
                'employees_count' => count($pay), 'reference' => $in['reference'] ?? null, 'status' => 'posted']);
            $entry = $this->posting->persist($b, $payment, 'journal', $in['paid_on'], null, "{$label}: paid to ".count($pay).' employees from '.$account->name, $in['reference'] ?? $label);
            $payment->forceFill(['journal_entry_id' => $entry->id])->save();
            foreach ($pay as $lineId => $amount) {
                PayrollPaymentLine::create(['payroll_payment_id' => $payment->id, 'payroll_line_id' => $lineId, 'amount' => (string) $amount]);
                $l = $lines[$lineId];
                $l->forceFill(['paid_amount' => (string) Money::of((string) $l->paid_amount)->plus($amount)])->save();
            }
            $this->audit->record('paid', $run, null, ['payment_id' => $payment->id, 'amount' => (string) $total, 'from' => $account->name, 'voucher' => $entry->voucher_number],
                type: 'payroll_runs');

            return $payment->refresh();
        }, attempts: 2);
    }

    public function reverse(PayrollPayment $payment, string $reason): PayrollPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = PayrollPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'reversed') {
                throw new ApiProblem('already_reversed', 'This salary payment is already reversed.');
            }
            $entry = JournalEntry::with('lines')->whereKey($payment->journal_entry_id)->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $date = $date < $entry->entry_date->toDateString() ? $entry->entry_date->toDateString() : $date;
            $b = new JournalBuilder;
            foreach ($entry->lines as $l) {
                $b->debit($l->ledger_id, Money::of((string) $l->credit))->credit($l->ledger_id, Money::of((string) $l->debit));
            }
            $reversal = $this->posting->persist($b, null, 'reversal', $date, null, "Reversal of {$entry->voucher_number} (salary payment): {$reason}", $entry->voucher_number, null, $entry->id);
            $entry->forceFill(['status' => 'reversed'])->save();
            foreach ($payment->lines()->get() as $pl) {
                $l = PayrollLine::whereKey($pl->payroll_line_id)->lockForUpdate()->first();
                $l->forceFill(['paid_amount' => (string) Money::of((string) $l->paid_amount)->minus(Money::of((string) $pl->amount))])->save();
            }
            $payment->forceFill(['status' => 'reversed', 'reversal_entry_id' => $reversal->id, 'reversed_at' => now(), 'reverse_reason' => $reason])->save();
            $this->audit->record('reversed', $payment, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason, 'reversal' => $reversal->voucher_number], type: 'payroll_payments');

            return $payment->refresh();
        }, attempts: 2);
    }

    public static function due(PayrollLine $l): Money
    {
        return Money::of((string) $l->net)->minus(Money::of((string) $l->paid_amount));
    }

    /** PayrollPayment (05 schema). */
    public static function resource(PayrollPayment $p): array
    {
        $p->loadMissing(['moneyAccount:id,name,account_type', 'journalEntry:id,voucher_number']);
        $lines = DB::table('payroll_payment_lines as pl')->join('payroll_lines as l', 'l.id', '=', 'pl.payroll_line_id')->where('pl.payroll_payment_id', $p->id)
            ->orderBy('l.employee_code')->get(['pl.payroll_line_id', 'l.employee_code', 'l.employee_name', 'pl.amount']);

        return ['id' => $p->id, 'payroll_run_id' => $p->payroll_run_id,
            'money_account' => ['id' => $p->moneyAccount->id, 'name' => $p->moneyAccount->name, 'account_type' => $p->moneyAccount->account_type],
            'paid_on' => $p->paid_on->toDateString(), 'amount' => (string) Money::of((string) $p->amount), 'employees_count' => $p->employees_count, 'reference' => $p->reference,
            'status' => $p->status, 'voucher_number' => $p->journalEntry?->voucher_number, 'reversed_at' => $p->reversed_at?->toIso8601String(), 'reverse_reason' => $p->reverse_reason,
            'lines' => $lines->map(fn ($l) => ['payroll_line_id' => (int) $l->payroll_line_id, 'employee_code' => $l->employee_code, 'employee_name' => $l->employee_name,
                'amount' => (string) Money::of((string) $l->amount)])->all(),
            'version' => $p->lock_version];
    }
}
