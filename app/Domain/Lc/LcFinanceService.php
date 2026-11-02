<?php

namespace App\Domain\Lc;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\BankFacility;
use App\Models\JournalEntry;
use App\Models\LcLoan;
use App\Models\LcLoanAccrual;
use App\Models\LcLoanRepayment;
use App\Models\LcPayment;
use App\Models\LetterOfCredit;
use App\Models\MoneyAccount;
use App\Support\Problem\ApiProblem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * LC finance (P16 LC finance; 06 §12.11-12.12; BR-066, BR-067; owner decisions 2 Nov 2026):
 * - bank facilities: LC and loan limits per bank, with live utilisation and the warn/block check;
 * - PAD/LTR loans opened by a financed LC payment, interest by days on the outstanding principal, month-end accruals,
 *   repayments (principal + interest due), reversal of the latest repayment, PAD → LTR conversion.
 */
final class LcFinanceService
{
    private const OPEN_LC = ['awaiting_bank', 'opened', 'partly_shipped', 'documents_presented', 'payment_due'];

    public function __construct(private readonly AccountMapper $mapper, private readonly PostingService $posting, private readonly AuditLogger $audit,
        private readonly LcPostingService $lcPosting) {}

    // ---------------------------------------------------------------- facilities (06 §12.11)

    public function saveFacility(array $in, ?int $id = null, ?int $version = null): BankFacility
    {
        return DB::transaction(function () use ($in, $id, $version) {
            $f = $id ? BankFacility::whereKey($id)->lockForUpdate()->firstOrFail() : new BankFacility;
            if ($id && (int) $f->lock_version !== (int) $version) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $f->lock_version]);
            }
            $account = MoneyAccount::find($in['money_account_id']);
            if (! $account || ! in_array($account->account_type, ['bank', 'cash_credit'], true)) {
                throw new ApiProblem('validation_failed', errors: ['money_account_id' => ['Choose a bank account']]);
            }
            if (BankFacility::where('money_account_id', $account->id)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw new ApiProblem('validation_failed', errors: ['money_account_id' => ['This bank already has a facility; edit it instead']]);
            }
            $old = $id ? $f->only(['lc_limit', 'loan_limit', 'limit_action', 'interest_rate_pct', 'day_count', 'loan_tenor_days', 'is_active']) : null;
            $f->fill(array_intersect_key($in, array_flip(['money_account_id', 'lc_limit', 'loan_limit', 'limit_action', 'interest_rate_pct', 'day_count', 'loan_tenor_days', 'notes', 'is_active'])));
            if ($id) {
                $f->lock_version = (int) $f->lock_version + 1;
            }
            $f->save();
            $this->audit->record($id ? 'updated' : 'created', $f, $old, $f->only(['lc_limit', 'loan_limit', 'limit_action', 'interest_rate_pct', 'day_count', 'loan_tenor_days', 'is_active']),
                type: 'bank_facilities');

            return $f->refresh();
        });
    }

    /** Utilisation of one bank (BR-066): planned taka still to pay on its open LCs, its open acceptances, its open loans. */
    public function utilisation(int $bankAccountId, ?int $exceptLcId = null): array
    {
        $lcs = LetterOfCredit::where('issuing_bank_account_id', $bankAccountId)->whereIn('status', self::OPEN_LC)->when($exceptLcId, fn ($q) => $q->whereKeyNot($exceptLcId))->get();
        $planned = Money::zero();
        $acceptances = Money::zero();
        foreach ($lcs as $lc) {
            $left = $lc->amount->minus(LcPostingService::paidForeign($lc->id));
            if ($left->isPositive()) {
                $planned = $planned->plus(Money::of($left->toBigDecimal()->multipliedBy($lc->planning_rate->toBigDecimal())->toScale(2, RoundingMode::HalfUp)));
            }
            $acc = $this->lcPosting->tagged($lc->id, 'lc_acceptance')->negated();
            if ($acc->isPositive()) {
                $acceptances = $acceptances->plus($acc);
            }
        }
        $loans = Money::zero();
        $open = LcLoan::where('money_account_id', $bankAccountId)->where('status', 'open')->get();
        foreach ($open as $loan) {
            $loans = $loans->plus($this->outstanding($loan));
        }

        return ['lc_utilised' => $planned->plus($acceptances), 'acceptances' => $acceptances, 'loans' => $loans, 'open_lcs' => $lcs->count(), 'open_loans' => $open->count()];
    }

    public function facilityResource(BankFacility $f): array
    {
        $u = $this->utilisation((int) $f->money_account_id);
        $lcLimit = Money::of((string) $f->lc_limit);
        $loanLimit = Money::of((string) $f->loan_limit);

        return ['id' => $f->id, 'money_account_id' => (int) $f->money_account_id, 'bank_name' => (string) DB::table('money_accounts')->where('id', $f->money_account_id)->value('name'),
            'lc_limit' => (string) $lcLimit, 'loan_limit' => (string) $loanLimit, 'limit_action' => $f->limit_action, 'interest_rate_pct' => (string) $f->interest_rate_pct,
            'day_count' => (string) $f->day_count, 'loan_tenor_days' => (int) $f->loan_tenor_days, 'notes' => $f->notes, 'is_active' => (bool) $f->is_active,
            'lc_utilised' => (string) $u['lc_utilised'], 'lc_available' => $lcLimit->isPositive() ? (string) $lcLimit->minus($u['lc_utilised']) : null,
            'loans_outstanding' => (string) $u['loans'], 'loan_available' => $loanLimit->isPositive() ? (string) $loanLimit->minus($u['loans']) : null,
            'acceptances_outstanding' => (string) $u['acceptances'], 'open_lcs' => $u['open_lcs'], 'open_loans' => $u['open_loans'], 'version' => (int) $f->lock_version];
    }

    private function facilityFor(?int $bankAccountId): ?BankFacility
    {
        return $bankAccountId ? BankFacility::where('money_account_id', $bankAccountId)->where('is_active', true)->first() : null;
    }

    /** Submitting an LC (BR-066): its planned taka plus the bank's utilisation against the LC limit. @return array|null the warning */
    public function checkLcLimit(LetterOfCredit $lc): ?array
    {
        $f = $this->facilityFor($lc->issuing_bank_account_id);
        if (! $f || ! Money::of((string) $f->lc_limit)->isPositive()) {
            return null;
        }
        $planned = Money::of($lc->amount->toBigDecimal()->multipliedBy($lc->planning_rate->toBigDecimal())->toScale(2, RoundingMode::HalfUp));
        $total = $this->utilisation((int) $f->money_account_id, $lc->id)['lc_utilised']->plus($planned);
        if (! $total->isGreaterThan(Money::of((string) $f->lc_limit))) {
            return null;
        }
        $msg = "This LC takes the bank's LC use to ৳ {$total}, over its limit of ৳ ".Money::of((string) $f->lc_limit).'.';
        if ($f->limit_action === 'block') {
            throw new ApiProblem('lc_limit_exceeded', $msg, extra: ['limit' => (string) Money::of((string) $f->lc_limit), 'would_be' => (string) $total]);
        }

        return ['code' => 'LC_LIMIT_EXCEEDED', 'message' => $msg];
    }

    /** The LC_LIMIT_EXCEEDED / LOAN_LIMIT_EXCEEDED flags of an LC's bank (shown on the LC while over the limit). @return list<array> */
    public function limitFlags(LetterOfCredit $lc): array
    {
        $f = $this->facilityFor($lc->issuing_bank_account_id);
        if (! $f) {
            return [];
        }
        $u = $this->utilisation((int) $f->money_account_id);
        $flags = [];
        if (Money::of((string) $f->lc_limit)->isPositive() && $u['lc_utilised']->isGreaterThan(Money::of((string) $f->lc_limit))) {
            $flags[] = ['code' => 'LC_LIMIT_EXCEEDED', 'message' => "The bank's LC use (৳ {$u['lc_utilised']}) is over its limit of ৳ ".Money::of((string) $f->lc_limit)];
        }
        if (Money::of((string) $f->loan_limit)->isPositive() && $u['loans']->isGreaterThan(Money::of((string) $f->loan_limit))) {
            $flags[] = ['code' => 'LOAN_LIMIT_EXCEEDED', 'message' => "The bank's PAD/LTR loans (৳ {$u['loans']}) are over its limit of ৳ ".Money::of((string) $f->loan_limit)];
        }
        $due = LcLoan::where('letter_of_credit_id', $lc->id)->where('status', 'open')->where('due_date', '<=', CarbonImmutable::now('Asia/Dhaka')->addDays(7)->toDateString())->count();
        if ($due > 0) {
            $flags[] = ['code' => 'LOAN_DUE', 'message' => "{$due} loan(s) due within 7 days or overdue"];
        }

        return $flags;
    }

    // ---------------------------------------------------------------- loans (06 §12.12)

    /** Opens the loan a financed payment created (called inside the payment's transaction). */
    public function openLoan(LetterOfCredit $lc, LcPayment $p, Money $principal, string $kind, array $terms): LcLoan
    {
        $f = $this->facilityFor((int) $p->money_account_id);
        if ($f && Money::of((string) $f->loan_limit)->isPositive()) {
            $total = $this->utilisation((int) $f->money_account_id)['loans']->plus($principal);
            if ($total->isGreaterThan(Money::of((string) $f->loan_limit)) && $f->limit_action === 'block') {
                throw new ApiProblem('loan_limit_exceeded', "This loan takes the bank's PAD/LTR loans to ৳ {$total}, over its limit of ৳ ".Money::of((string) $f->loan_limit).'.',
                    extra: ['limit' => (string) Money::of((string) $f->loan_limit), 'would_be' => (string) $total]);
            }
        }
        $start = CarbonImmutable::parse($terms['start_date'] ?? $p->payment_date->toDateString());
        $tenor = (int) ($terms['tenor_days'] ?? $f?->loan_tenor_days ?? 90);
        $n = LcLoan::where('letter_of_credit_id', $lc->id)->count() + 1;
        $loan = new LcLoan;
        $loan->forceFill(['letter_of_credit_id' => $lc->id, 'lc_payment_id' => $p->id, 'money_account_id' => $p->money_account_id, 'loan_number' => "{$lc->lc_number}/L{$n}",
            'loan_kind' => $kind, 'principal' => (string) $principal, 'start_date' => $start->toDateString(), 'due_date' => $start->addDays($tenor)->toDateString(),
            'interest_rate_pct' => (string) ($terms['interest_rate_pct'] ?? $f?->interest_rate_pct ?? '0'), 'day_count' => (string) ($terms['day_count'] ?? $f?->day_count ?? '360'),
            'interest_from' => $start->toDateString(), 'status' => 'open'])->save();
        $this->audit->record('opened', $loan, null, ['loan_number' => $loan->loan_number, 'kind' => $kind, 'principal' => (string) $principal, 'rate' => (string) $loan->interest_rate_pct],
            type: 'lc_loans');

        return $loan;
    }

    public function outstanding(LcLoan $loan): Money
    {
        $repaid = (string) DB::table('lc_loan_repayments')->where('lc_loan_id', $loan->id)->where('status', 'posted')->sum('principal_amount');

        return Money::of((string) $loan->principal)->minus($repaid);
    }

    public function accruedUnpaid(LcLoan $loan): Money
    {
        return Money::of((string) DB::table('lc_loan_accruals')->where('lc_loan_id', $loan->id)->where('status', 'posted')->sum('amount'));
    }

    /** Interest = principal x rate / 100 x days / day count, 2 dp half up (BR-067). */
    public static function interest(Money $principal, string $ratePct, string $dayCount, int $days): Money
    {
        if ($days <= 0 || ! $principal->isPositive()) {
            return Money::zero();
        }

        return Money::of($principal->toBigDecimal()->multipliedBy($ratePct)->multipliedBy($days)->dividedBy(BigDecimal::of(100)->multipliedBy($dayCount), 2, RoundingMode::HalfUp));
    }

    public function quote(LcLoan $loan, string $asOf): array
    {
        $from = CarbonImmutable::parse($loan->interest_from->toDateString());
        $days = max(0, (int) $from->diffInDays(CarbonImmutable::parse($asOf), false));
        $out = $loan->status === 'open' ? $this->outstanding($loan) : Money::zero();
        $interest = self::interest($out, (string) $loan->interest_rate_pct, (string) $loan->day_count, $days);
        $accrued = $this->accruedUnpaid($loan);

        return ['as_of' => $asOf, 'outstanding_principal' => (string) $out, 'interest_from' => $from->toDateString(), 'days' => $days, 'interest_rate_pct' => (string) $loan->interest_rate_pct,
            'day_count' => (string) $loan->day_count, 'interest' => (string) $interest, 'accrued_unpaid' => (string) $accrued, 'total_interest' => (string) $interest->plus($accrued)];
    }

    public function loanResource(LcLoan $loan, bool $full = true): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $q = $this->quote($loan, $today);
        $repaid = Money::of((string) DB::table('lc_loan_repayments')->where('lc_loan_id', $loan->id)->where('status', 'posted')->sum('principal_amount'));
        $interestPaid = Money::of((string) DB::table('lc_loan_repayments')->where('lc_loan_id', $loan->id)->where('status', 'posted')->selectRaw('COALESCE(SUM(interest_amount + accrued_cleared), 0) s')->value('s'));
        $out = ['id' => $loan->id, 'letter_of_credit_id' => (int) $loan->letter_of_credit_id, 'lc_number' => (string) DB::table('letters_of_credit')->where('id', $loan->letter_of_credit_id)->value('lc_number'),
            'lc_payment_id' => (int) $loan->lc_payment_id, 'loan_number' => $loan->loan_number, 'loan_kind' => $loan->loan_kind, 'money_account_id' => (int) $loan->money_account_id,
            'bank_name' => (string) DB::table('money_accounts')->where('id', $loan->money_account_id)->value('name'), 'principal' => (string) $loan->principal,
            'repaid_principal' => (string) $repaid, 'outstanding_principal' => $q['outstanding_principal'], 'interest_paid' => (string) $interestPaid, 'accrued_unpaid' => $q['accrued_unpaid'],
            'interest_rate_pct' => (string) $loan->interest_rate_pct, 'day_count' => (string) $loan->day_count, 'start_date' => $loan->start_date->toDateString(),
            'due_date' => $loan->due_date->toDateString(), 'days_to_due' => $loan->status === 'open' ? (int) CarbonImmutable::parse($today)->diffInDays($loan->due_date, false) : null,
            'interest_from' => $q['interest_from'], 'interest_due_today' => $q['total_interest'], 'converted_on' => $loan->converted_on?->toDateString(), 'status' => $loan->status,
            'notes' => $loan->notes, 'version' => (int) $loan->lock_version];
        if ($full) {
            $out['repayments'] = LcLoanRepayment::where('lc_loan_id', $loan->id)->orderBy('repayment_date')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id, 'repayment_date' => $r->repayment_date->toDateString(), 'principal_amount' => (string) $r->principal_amount, 'interest_amount' => (string) $r->interest_amount,
                'accrued_cleared' => (string) $r->accrued_cleared, 'interest_from' => $r->interest_from->toDateString(), 'interest_days' => (int) $r->interest_days,
                'money_account_id' => (int) $r->money_account_id, 'reference' => $r->reference, 'status' => $r->status, 'journal_entry_id' => $r->journal_entry_id])->all();
        }

        return $out;
    }

    private function lockLoan(int $id): LcLoan
    {
        return LcLoan::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** Repays principal with the interest due to the date (BR-067). */
    public function repay(int $loanId, array $in): LcLoan
    {
        return DB::transaction(function () use ($loanId, $in) {
            $loan = $this->lockLoan($loanId);
            if ($loan->status !== 'open') {
                throw new ApiProblem('invalid_transition', "{$loan->loan_number} is {$loan->status}.");
            }
            $date = $in['repayment_date'];
            if ($date < $loan->interest_from->toDateString()) {
                throw new ApiProblem('validation_failed', errors: ['repayment_date' => ['Interest is already settled up to '.$loan->interest_from->toDateString().'; choose that date or later']]);
            }
            $principal = Money::of($in['principal_amount']);
            $outstanding = $this->outstanding($loan);
            if ($principal->isGreaterThan($outstanding)) {
                throw new ApiProblem('repayment_exceeds_outstanding', "Only ৳ {$outstanding} principal is outstanding on {$loan->loan_number}.",
                    errors: ['principal_amount' => ["At most ৳ {$outstanding}"]]);
            }
            $q = $this->quote($loan, $date);
            $interest = Money::of($q['interest']);
            $accrued = Money::of($q['accrued_unpaid']);
            $total = $principal->plus($interest)->plus($accrued);
            if (! $total->isPositive()) {
                throw new ApiProblem('validation_failed', errors: ['principal_amount' => ['Nothing to repay on this date']]);
            }
            $account = MoneyAccount::where('is_active', true)->find($in['money_account_id']) ?? throw new ApiProblem('validation_failed', errors: ['money_account_id' => ['Choose an active money account']]);
            $tag = ['letter_of_credit_id' => (int) $loan->letter_of_credit_id, 'against_reference' => $loan->loan_number];
            $b = new JournalBuilder;
            if ($principal->isPositive()) {
                $b->debit($this->mapper->ledgerFor('lc_loan'), $principal, $tag);
            }
            if ($accrued->isPositive()) {
                $b->debit($this->mapper->ledgerFor('interest_payable'), $accrued, $tag);
            }
            if ($interest->isPositive()) {
                $b->debit($this->mapper->ledgerFor('interest_expense'), $interest, $tag);
            }
            $b->credit($account->ledger_id, $total, $tag);
            $r = new LcLoanRepayment;
            $r->forceFill(['lc_loan_id' => $loan->id, 'repayment_date' => $date, 'principal_amount' => (string) $principal, 'interest_amount' => (string) $interest,
                'accrued_cleared' => (string) $accrued, 'interest_from' => $q['interest_from'], 'interest_days' => $q['days'], 'money_account_id' => $account->id,
                'reference' => $in['reference'] ?? null, 'status' => 'posted'])->save();
            $entry = $this->posting->persist($b, $r, 'lc_payment', $date, null, "Repayment of {$loan->loan_number}: principal ৳ {$principal}, interest ৳ ".$interest->plus($accrued),
                $in['reference'] ?? $loan->loan_number);
            $r->forceFill(['journal_entry_id' => $entry->id])->save();
            DB::table('lc_loan_accruals')->where('lc_loan_id', $loan->id)->where('status', 'posted')->update(['status' => 'paid', 'updated_at' => now()]);
            $loan->forceFill(['interest_from' => $date, 'status' => $outstanding->minus($principal)->isZero() ? 'repaid' : 'open'])->save();
            $this->audit->record('repaid', $loan, null, ['principal' => (string) $principal, 'interest' => (string) $interest, 'accrued' => (string) $accrued, 'date' => $date], type: 'lc_loans');

            return $loan->refresh();
        });
    }

    /** Reverses the latest posted repayment: mirror entry; principal, accruals and the interest date come back. */
    public function reverseRepayment(int $id, string $reason): LcLoan
    {
        return DB::transaction(function () use ($id, $reason) {
            $r = LcLoanRepayment::whereKey($id)->lockForUpdate()->firstOrFail();
            $loan = $this->lockLoan((int) $r->lc_loan_id);
            if ($r->status !== 'posted') {
                throw new ApiProblem('already_reversed', 'This repayment is already reversed.');
            }
            $latest = LcLoanRepayment::where('lc_loan_id', $loan->id)->where('status', 'posted')->orderByDesc('repayment_date')->orderByDesc('id')->first();
            if ($latest->id !== $r->id) {
                throw new ApiProblem('invalid_transition', 'Reverse the later repayments of this loan first.');
            }
            $this->lcPosting->mirror(JournalEntry::findOrFail($r->journal_entry_id), CarbonImmutable::now('Asia/Dhaka')->toDateString(), "Reversal of a {$loan->loan_number} repayment: {$reason}");
            $r->forceFill(['status' => 'reversed'])->save();
            if (Money::of((string) $r->accrued_cleared)->isPositive()) {
                $prev = LcLoanRepayment::where('lc_loan_id', $loan->id)->where('status', 'posted')->max('repayment_date') ?? $loan->start_date->toDateString();
                DB::table('lc_loan_accruals')->where('lc_loan_id', $loan->id)->where('status', 'paid')->where('accrual_date', '>=', $prev)
                    ->where('accrual_date', '<', $r->interest_from->toDateString())->update(['status' => 'posted', 'updated_at' => now()]);
            }
            $loan->forceFill(['interest_from' => $r->interest_from->toDateString(), 'status' => 'open'])->save();
            $this->audit->record('repayment_reversed', $loan, null, ['repayment_id' => $r->id, 'reason' => $reason], type: 'lc_loans');

            return $loan->refresh();
        });
    }

    /** PAD → LTR on new terms: interest to the conversion date is accrued at the old rate first (BR-067). */
    public function convert(int $loanId, array $in, int $version): LcLoan
    {
        return DB::transaction(function () use ($loanId, $in, $version) {
            $loan = $this->lockLoan($loanId);
            if ((int) $loan->lock_version !== $version) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $loan->lock_version]);
            }
            if ($loan->status !== 'open' || $loan->loan_kind !== 'pad') {
                throw new ApiProblem('invalid_transition', "{$loan->loan_number} is not an open PAD.");
            }
            $on = $in['converted_on'];
            if ($on < $loan->interest_from->toDateString()) {
                throw new ApiProblem('validation_failed', errors: ['converted_on' => ['On or after '.$loan->interest_from->toDateString()]]);
            }
            $this->accrue($loan, CarbonImmutable::parse($on)->subDay()->toDateString(), 'Interest to the PAD → LTR conversion');
            $old = $loan->only(['loan_kind', 'interest_rate_pct', 'due_date']);
            $loan->forceFill(['loan_kind' => 'ltr', 'interest_rate_pct' => (string) $in['interest_rate_pct'], 'converted_on' => $on,
                'due_date' => CarbonImmutable::parse($on)->addDays((int) $in['tenor_days'])->toDateString(), 'notes' => $in['notes'] ?? $loan->notes,
                'lock_version' => (int) $loan->lock_version + 1])->save();
            $this->audit->record('converted', $loan, $old, $loan->only(['loan_kind', 'interest_rate_pct', 'due_date']), type: 'lc_loans');

            return $loan->refresh();
        });
    }

    /** Accrues interest from interest_from through $through (inclusive): Dr interest expense / Cr interest payable. */
    public function accrue(LcLoan $loan, string $through, string $label = 'Month-end interest'): ?LcLoanAccrual
    {
        $next = CarbonImmutable::parse($through)->addDay()->toDateString();
        $q = $this->quote($loan, $next);
        $amount = Money::of($q['interest']);
        if ($q['days'] <= 0 || ! $amount->isPositive() || LcLoanAccrual::where('lc_loan_id', $loan->id)->where('accrual_date', $through)->exists()) {
            return null;
        }
        $tag = ['letter_of_credit_id' => (int) $loan->letter_of_credit_id, 'against_reference' => $loan->loan_number];
        $b = (new JournalBuilder)->debit($this->mapper->ledgerFor('interest_expense'), $amount, $tag)->credit($this->mapper->ledgerFor('interest_payable'), $amount, $tag);
        $a = new LcLoanAccrual;
        $a->forceFill(['lc_loan_id' => $loan->id, 'accrual_date' => $through, 'interest_from' => $q['interest_from'], 'days' => $q['days'], 'amount' => (string) $amount, 'status' => 'posted'])->save();
        $entry = $this->posting->persist($b, $a, 'lc_payment', $through, null, "{$label}: {$loan->loan_number}, {$q['days']} days at {$loan->interest_rate_pct} %", $loan->loan_number);
        $a->forceFill(['journal_entry_id' => $entry->id])->save();
        $loan->forceFill(['interest_from' => $next])->save();

        return $a;
    }

    /** pluto:loans:accrue: every open loan of the current company, interest through $through. @return int accruals posted */
    public function accrueAll(string $through): int
    {
        $n = 0;
        foreach (LcLoan::where('status', 'open')->where('interest_from', '<=', $through)->pluck('id') as $id) {
            DB::transaction(function () use ($id, $through, &$n) {
                if ($this->accrue($this->lockLoan((int) $id), $through)) {
                    $n++;
                }
            });
        }

        return $n;
    }

    /** An LC payment that opened a loan is being reversed: the loan goes with it (only before any repayment). */
    public function reverseLoanOf(LcPayment $p, string $reason): void
    {
        $loan = LcLoan::where('lc_payment_id', $p->id)->lockForUpdate()->first();
        if (! $loan || $loan->status === 'reversed') {
            return;
        }
        if (LcLoanRepayment::where('lc_loan_id', $loan->id)->where('status', 'posted')->exists()) {
            throw new ApiProblem('invalid_transition', "{$loan->loan_number} has repayments; reverse them first.");
        }
        $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        foreach (LcLoanAccrual::where('lc_loan_id', $loan->id)->whereIn('status', ['posted', 'paid'])->get() as $a) {
            $this->lcPosting->mirror(JournalEntry::findOrFail($a->journal_entry_id), $date, "Reversal of {$loan->loan_number} interest: {$reason}");
            $a->forceFill(['status' => 'reversed'])->save();
        }
        $loan->forceFill(['status' => 'reversed'])->save();
        $this->audit->record('reversed', $loan, ['status' => 'open'], ['status' => 'reversed', 'reason' => $reason], type: 'lc_loans');
    }
}
