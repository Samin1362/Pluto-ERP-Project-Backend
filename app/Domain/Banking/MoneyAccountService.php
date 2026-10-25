<?php

namespace App\Domain\Banking;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\OpeningBalanceService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\Ledger;
use App\Models\MoneyAccount;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Money accounts (G3/G4; backlog P6.1-BE-01; BR-028, BR-031, R-13). Creating one also creates its ledger — cash
 * 1101-NN (the seeded "Cash in hand" first), bank 1102-NN, wallet 1103-NN, card 1104-NN under the cash_bank_group,
 * cash credit 2301-NN under current liabilities — and, for a non-zero opening balance, an opening_balance entry dated
 * the financial year's start against opening_balance_adjustment (positive = money in the account, Dr; negative =
 * overdrawn, Cr). The opening balance can change only while the opening entry is the ledger's only entry (BR-028): the
 * old entry is reversed and a new one posted. The type is fixed after creation.
 */
final class MoneyAccountService
{
    private const AUDITED = ['account_type', 'name', 'bank_name', 'branch_name', 'account_name', 'account_number', 'routing_number', 'overdraft_limit',
        'custodian_user_id', 'use_for_receipts', 'use_for_payments', 'show_on_invoices', 'is_active'];

    private const CODES = ['cash' => '1101', 'bank' => '1102', 'wallet' => '1103', 'card' => '1104', 'cash_credit' => '2301'];

    public function __construct(
        private readonly AccountMapper $mapper,
        private readonly OpeningBalanceService $openings,
        private readonly AuditLogger $audit,
        private readonly CurrentCompany $current,
    ) {}

    public function create(array $in): MoneyAccount
    {
        return DB::transaction(function () use ($in) {
            $account = new MoneyAccount($this->fields($in) + ['account_type' => $in['account_type'],
                'currency_code' => $this->current->company()->base_currency_code, 'is_active' => true]);
            $account->name = $this->name($in);
            $account->ledger_id = $this->ledgerFor($account)->id;
            $account->save();
            $opening = Money::of($in['opening_balance'] ?? '0');
            $this->openings->set($account, $account->ledger_id, $opening, $account->name, $in['opening_date'] ?? null);
            $this->audit->record('created', $account, null, $this->audited($account) + ['opening_balance' => (string) $opening]);

            return $account->refresh();
        });
    }

    public function update(MoneyAccount $account, array $in, int $expectedVersion): MoneyAccount
    {
        return DB::transaction(function () use ($account, $in, $expectedVersion) {
            $account = MoneyAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ((int) $account->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $account->lock_version]);
            }
            if (isset($in['account_type']) && $in['account_type'] !== $account->account_type) {
                throw new ApiProblem('validation_failed', errors: ['account_type' => ['The account type cannot change; add a new account instead']]);
            }
            $old = $this->audited($account);
            $account->fill($this->fields($in));
            if (array_key_exists('name', $in) || array_key_exists('bank_name', $in) || array_key_exists('account_number', $in)) {
                $account->name = $this->name($in + $account->only(['bank_name', 'account_number', 'account_type']));
            }
            $account->lock_version = (int) $account->lock_version + 1; // a new version even when only the opening balance changed
            $account->save();
            Ledger::whereKey($account->ledger_id)->update(['name' => $account->name, 'is_active' => $account->is_active]);
            if (array_key_exists('opening_balance', $in)) {
                $this->openings->set($account, $account->ledger_id, Money::of($in['opening_balance'] ?? '0'), $account->name, $in['opening_date'] ?? null);
            }
            $this->audit->record('updated', $account, $old, $this->audited($account->refresh()));

            return $account;
        });
    }

    /** The account's ledger balance (posted lines, Dr − Cr; negative = overdrawn). */
    public static function bookBalance(int $ledgerId): Money
    {
        return Money::of((string) DB::table('journal_lines')->where('ledger_id', $ledgerId)->where('is_posted', 1)
            ->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));
    }

    private function fields(array $in): array
    {
        $keys = ['overdraft_limit', 'bank_name', 'branch_name', 'account_name', 'account_number', 'routing_number', 'custodian_user_id',
            'use_for_receipts', 'use_for_payments', 'show_on_invoices', 'is_active'];

        return array_intersect_key($in, array_flip($keys));
    }

    /** "When omitted: '<bank> ••<last 4 digits>'" (05); a cash account without a bank is "Cash in hand". */
    private function name(array $in): string
    {
        if (! empty($in['name'])) {
            return $in['name'];
        }
        if (! empty($in['bank_name'])) {
            return trim($in['bank_name'].(! empty($in['account_number']) ? ' ••'.substr($in['account_number'], -4) : ''));
        }

        return ['cash' => 'Cash in hand', 'bank' => 'Bank account', 'wallet' => 'Mobile wallet', 'card' => 'Card', 'cash_credit' => 'Cash credit'][$in['account_type']];
    }

    /** Its own ledger; the first cash account takes over the seeded 1101-01 "Cash in hand" while no account uses it. */
    private function ledgerFor(MoneyAccount $account): Ledger
    {
        $cc = $account->account_type === 'cash_credit';
        $groupId = $cc ? (int) DB::table('account_groups')->where('code', '21')->value('id') : $this->mapper->groupIdFor('cash_bank_group');
        $prefix = self::CODES[$account->account_type];
        $codes = Ledger::where('code', 'like', "{$prefix}-%")->lockForUpdate()->pluck('id', 'code');
        if ($account->account_type === 'cash' && isset($codes["{$prefix}-01"]) && ! MoneyAccount::where('ledger_id', $codes["{$prefix}-01"])->exists()) {
            $ledger = Ledger::find($codes["{$prefix}-01"]);
            $ledger->update(['name' => $account->name]);

            return $ledger;
        }
        $next = 1 + (int) collect($codes->keys())->map(fn ($c) => (int) substr($c, strlen($prefix) + 1))->max();

        return Ledger::create(['account_group_id' => $groupId, 'code' => $prefix.'-'.str_pad((string) $next, 2, '0', STR_PAD_LEFT), 'name' => $account->name,
            'ledger_kind' => $account->account_type === 'cash' ? 'cash' : 'bank', 'show_in_cash_vouchers' => true, 'is_active' => true]);
    }

    private function audited(MoneyAccount $a): array
    {
        $out = $a->only(self::AUDITED);
        $out['account_number'] = $a->maskedNumber(); // never the full number in the audit log

        return $out;
    }
}
