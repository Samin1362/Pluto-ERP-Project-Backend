<?php

namespace App\Domain\Accounting;

use App\Domain\Banking\MoneyAccountService;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Money;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Ledger;
use App\Models\MoneyAccount;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;

/**
 * Turns a VoucherInput into the lines it posts (H9–H12; 05 postVouchersCalculate, R-27; BR-001, BR-002, BR-007,
 * BR-031, BR-053, BR-065). The same result feeds the preview, the draft and the posting, so the screen and the books
 * never disagree:
 *  - journal: the lines as entered, each a debit or a credit (BR-002);
 *  - payment: Dr each line's amount, Dr bank_charges, Cr the money account (amount + charge);
 *  - receipt: Dr the money account (amount − charge), Dr bank_charges, Cr each line's amount;
 *  - contra: Dr the receiving money account(s) on the lines (`counter_money_account_id`, when sent, must be that
 *    account), Dr bank_charges, Cr the paying account (amount + charge).
 * Input mistakes (missing ledgers, both sides on a line, an account not used for payments) are validation_failed;
 * an unbalanced journal is reported (balanced = false), and refused only when saving for posting.
 */
final class VoucherCalculator
{
    public const TYPES = ['journal', 'payment', 'receipt', 'contra'];

    /** Voucher types that need approval above the role's limit (BR-007), and the limit column and approve permission. */
    public const APPROVAL = ['journal' => ['journal_approval_limit', 'accounting.approve'], 'payment' => ['payment_approval_limit', 'banking.approve']];

    public function __construct(private readonly AccountMapper $mapper) {}

    /**
     * @return array{lines: list<array{ledger_id: int, debit: Money, credit: Money, line_narration: ?string, against_reference: ?string, allocations: array}>,
     *   total_debit: Money, total_credit: Money, difference: Money, balanced: bool, needs_approval: bool, approval_limit: ?Money,
     *   warnings: list<array{code: string, message: string, line_no?: int}>, account: ?MoneyAccount, counter: ?MoneyAccount, amount: Money, charge: Money}
     */
    public function calculate(array $in, ?CompanyUser $membership): array
    {
        $type = $in['entry_type'];
        $ledgers = Ledger::whereIn('id', collect($in['lines'] ?? [])->pluck('ledger_id')->filter()->all())->get()->keyBy('id');
        $errors = [];
        foreach ($in['lines'] ?? [] as $i => $l) {
            $ledger = $ledgers[$l['ledger_id']] ?? null;
            if (! $ledger) {
                $errors["lines.{$i}.ledger_id"] = ['Choose a ledger of this company'];
            } elseif (! $ledger->is_active) {
                $errors["lines.{$i}.ledger_id"] = ["{$ledger->name} is inactive"];
            }
        }
        [$account, $counter, $charge] = [null, null, Money::of($in['payment']['bank_charge'] ?? '0')];
        if ($type === 'journal') {
            if (isset($in['payment'])) {
                $errors['payment'] = ['A journal voucher has no cash or bank side; use a payment, receipt or contra'];
            }
        } else {
            [$account, $counter] = $this->accounts($in, $type, $errors);
        }
        $lines = $type === 'journal' ? $this->journalLines($in, $errors) : $this->cashLines($in, $type, $account, $counter, $charge, $errors);
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }

        $dr = Money::sum(array_column($lines, 'debit'));
        $cr = Money::sum(array_column($lines, 'credit'));
        $amount = $dr;
        $warnings = [...$this->funds($lines), ...$this->backdated($in['date'])];
        [$needs, $limit] = $this->approval($type, $amount, $membership);
        if ($needs) {
            $warnings[] = ['code' => 'APPROVAL_REQUIRED', 'message' => "Above your approval limit of {$limit}: it will wait for approval before it posts"];
        }

        return ['lines' => $lines, 'total_debit' => $dr, 'total_credit' => $cr, 'difference' => $dr->minus($cr)->abs(),
            'balanced' => $dr->isEqualTo($cr) && $dr->isPositive(), 'needs_approval' => $needs, 'approval_limit' => $limit,
            'warnings' => $warnings, 'account' => $account, 'counter' => $counter, 'amount' => $amount, 'charge' => $charge];
    }

    /** VoucherPreview (05 schema). */
    public function preview(array $calc): array
    {
        $names = Ledger::whereIn('id', array_column($calc['lines'], 'ledger_id'))->get(['id', 'code', 'name'])->keyBy('id');

        return [
            'lines' => array_map(fn ($l) => ['ledger_id' => $l['ledger_id'], 'ledger_code' => $names[$l['ledger_id']]->code ?? '', 'ledger_name' => $names[$l['ledger_id']]->name ?? '',
                'debit' => (string) $l['debit'], 'credit' => (string) $l['credit']], $calc['lines']),
            'total_debit' => (string) $calc['total_debit'], 'total_credit' => (string) $calc['total_credit'], 'difference' => (string) $calc['difference'],
            'balanced' => $calc['balanced'], 'needs_approval' => $calc['needs_approval'], 'approval_limit' => $calc['approval_limit'] !== null ? (string) $calc['approval_limit'] : null,
            'warnings' => $calc['warnings'], 'amount_in_words' => AmountInWords::of($calc['total_debit']),
        ];
    }

    /** @return array{0: bool, 1: ?Money} whether the amount is above the member's role limit for this type, and that limit */
    public function approval(string $type, Money $amount, ?CompanyUser $membership): array
    {
        if (! isset(self::APPROVAL[$type]) || ! $membership) {
            return [false, null];
        }
        $limit = $membership->role?->{self::APPROVAL[$type][0]};
        if ($limit === null) {
            return [false, null]; // the Owner role has no limit
        }
        $limit = Money::of((string) $limit);

        return [$amount->isGreaterThan($limit), $limit];
    }

    /** BR-053: older than the company's back-date window. */
    public function isBackdated(string $date): bool
    {
        $window = (int) (CompanySetting::value('backdate_warning_days') ?? 0);

        return $window > 0 && CarbonImmutable::parse($date)->lt(CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays($window));
    }

    private function journalLines(array $in, array &$errors): array
    {
        $lines = [];
        if (count($in['lines'] ?? []) < 2) {
            $errors['lines'] = ['A journal needs at least one debit and one credit line'];
        }
        foreach ($in['lines'] ?? [] as $i => $l) {
            if (isset($l['amount']) || ! empty($l['allocations'])) {
                $errors["lines.{$i}.amount"] = ['Journal lines take a debit or a credit'];
            }
            $dr = Money::of($l['debit'] ?? '0');
            $cr = Money::of($l['credit'] ?? '0');
            if ($dr->isPositive() === $cr->isPositive()) {
                $errors["lines.{$i}.debit"] = ['Enter a debit or a credit, not both (BR-002)'];
            }
            $lines[] = $this->line((int) $l['ledger_id'], $dr, $cr, $l);
        }

        return $lines;
    }

    private function cashLines(array $in, string $type, ?MoneyAccount $account, ?MoneyAccount $counter, Money $charge, array &$errors): array
    {
        $rows = $in['lines'] ?? [];
        if ($rows === []) {
            $errors['lines'] = ['Add at least one line'];
        }
        $moneyLedgers = MoneyAccount::pluck('ledger_id')->map(fn ($id) => (int) $id)->all();
        $entered = [];
        foreach ($rows as $i => $l) {
            if (isset($l['debit']) || isset($l['credit'])) {
                $errors["lines.{$i}.amount"] = ['Payment, receipt and contra lines take an amount'];
            }
            $amount = Money::of($l['amount'] ?? '0');
            if (! $amount->isPositive()) {
                $errors["lines.{$i}.amount"] = ['Enter an amount greater than zero'];
            }
            $isMoney = in_array((int) $l['ledger_id'], $moneyLedgers, true);
            if ($type === 'contra' && ! $isMoney) {
                $errors["lines.{$i}.ledger_id"] = ['A contra moves money between cash and bank accounts only'];
            }
            if ($account && (int) $l['ledger_id'] === (int) $account->ledger_id) {
                $errors["lines.{$i}.ledger_id"] = ["{$account->name} is already the voucher's account"];
            }
            if ($type === 'contra' && ! empty($l['allocations'])) {
                $errors["lines.{$i}.allocations"] = ['A contra settles no bills'];
            }
            $entered[] = $type === 'receipt' ? $this->line((int) $l['ledger_id'], Money::zero(), $amount, $l) : $this->line((int) $l['ledger_id'], $amount, Money::zero(), $l);
        }
        if (! $account) {
            return $entered;
        }
        $total = Money::sum(array_map(fn ($l) => $type === 'receipt' ? $l['credit'] : $l['debit'], $entered));
        if ($charge->isNegative() || ($type === 'receipt' && $charge->isPositive() && ! $charge->isLessThan($total))) {
            $errors['payment.bank_charge'] = ['The bank charge must be zero or more, and less than the amount received'];
        }
        $chargeLine = $charge->isPositive() ? [$this->line($this->mapper->ledgerFor('bank_charges')->id, $charge, Money::zero(), ['line_narration' => 'Bank charge'])] : [];
        if ($type === 'receipt') {
            return [$this->line($account->ledger_id, $total->minus($charge), Money::zero(), []), ...$chargeLine, ...$entered];
        }

        return [...$entered, ...$chargeLine, $this->line($account->ledger_id, Money::zero(), $total->plus($charge), [])];
    }

    /** @return array{0: ?MoneyAccount, 1: ?MoneyAccount} */
    private function accounts(array $in, string $type, array &$errors): array
    {
        $p = $in['payment'] ?? null;
        if (! $p) {
            $errors['payment'] = ['Choose the cash or bank account'];

            return [null, null];
        }
        $account = MoneyAccount::find($p['money_account_id']);
        $counter = isset($p['counter_money_account_id']) ? MoneyAccount::find($p['counter_money_account_id']) : null;
        $use = $type === 'receipt' ? 'use_for_receipts' : 'use_for_payments';
        if (! $account) {
            $errors['payment.money_account_id'] = ['Choose a cash or bank account of this company'];
        } elseif (! $account->is_active) {
            $errors['payment.money_account_id'] = ["{$account->name} is inactive"];
        } elseif ($type !== 'contra' && ! $account->{$use}) {
            $errors['payment.money_account_id'] = ["{$account->name} is not used for ".($type === 'receipt' ? 'receipts' : 'payments')];
        }
        if (isset($p['counter_money_account_id'])) {
            if ($type !== 'contra') {
                $errors['payment.counter_money_account_id'] = ['Only a contra has a receiving account'];
            } elseif (! $counter || ! $counter->is_active) {
                $errors['payment.counter_money_account_id'] = ['Choose an active cash or bank account of this company'];
            } elseif ($account && $counter->id === $account->id) {
                $errors['payment.counter_money_account_id'] = ['Choose a different account to move the money to'];
            }
        }
        if ($type === 'contra' && $counter && ! empty($in['lines']) && collect($in['lines'])->pluck('ledger_id')->map(fn ($i) => (int) $i)->unique()->values()->all() !== [(int) $counter->ledger_id]) {
            $errors['payment.counter_money_account_id'] = ['The lines must go to the receiving account'];
        }

        return [$account, $counter];
    }

    private function line(int $ledgerId, Money $debit, Money $credit, array $l): array
    {
        return ['ledger_id' => $ledgerId, 'debit' => $debit, 'credit' => $credit, 'line_narration' => $l['line_narration'] ?? null,
            'against_reference' => $l['against_reference'] ?? null, 'allocations' => $l['allocations'] ?? []];
    }

    /** BR-031: the money accounts this voucher takes money out of, if they would end below zero or below the overdraft. */
    public function funds(array $lines): array
    {
        $net = [];
        foreach ($lines as $l) {
            $net[$l['ledger_id']] = ($net[$l['ledger_id']] ?? Money::zero())->plus($l['debit'])->minus($l['credit']);
        }
        $warnings = [];
        foreach (MoneyAccount::whereIn('ledger_id', array_keys($net))->get() as $a) {
            if (! $net[$a->ledger_id]->isNegative()) {
                continue;
            }
            $after = MoneyAccountService::bookBalance($a->ledger_id)->plus($net[$a->ledger_id]);
            $floor = in_array($a->account_type, ['bank', 'cash_credit'], true) ? Money::of((string) ($a->overdraft_limit ?? '0'))->negated() : Money::zero();
            if ($after->isLessThan($floor)) {
                $available = MoneyAccountService::bookBalance($a->ledger_id)->minus($floor);
                $warnings[] = ['code' => 'INSUFFICIENT_FUNDS', 'message' => "{$a->name} has {$available} available; this voucher needs {$net[$a->ledger_id]->abs()}"];
            }
        }

        return $warnings;
    }

    private function backdated(string $date): array
    {
        if (! $this->isBackdated($date)) {
            return [];
        }
        $window = (int) CompanySetting::value('backdate_warning_days');

        return [['code' => 'BACKDATED', 'message' => 'Dated '.CarbonImmutable::parse($date)->format('j M Y').", more than {$window} days ago: confirm to post it"]];
    }
}
