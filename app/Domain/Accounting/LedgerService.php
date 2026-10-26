<?php

namespace App\Domain\Accounting;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\AccountGroup;
use App\Models\Ledger;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Ledger management (H4/H5; backlog P7.1-BE-02; BR-026, BR-027, BR-028). New ledgers are general ledgers in a group;
 * the code is the next free "<group code>NN" when omitted, and always starts with the primary group's digit. The
 * opening balance is an opening_balance entry against 3301 (OpeningBalanceService). Once the ledger has other entries
 * its code and opening are locked (ledger_locked); it may move only to a group of the same nature (nature_mismatch).
 * Party, money-account and warehouse ledgers are edited through their master records. A ledger can be made inactive
 * only with a zero balance and when no posting rule (account mapping) points at it.
 */
final class LedgerService
{
    private const AUDITED = ['code', 'name', 'account_group_id', 'description', 'track_bills', 'show_in_cash_vouchers', 'is_active'];

    /** Sub-ledgers owned by a master record: party, money account, warehouse. */
    private const MASTER_KINDS = ['party' => 'customer or supplier', 'bank' => 'money account', 'cash' => 'money account', 'inventory' => 'warehouse'];

    public function __construct(private readonly OpeningBalanceService $openings, private readonly AccountMapper $mapper, private readonly AuditLogger $audit) {}

    public function create(array $in): Ledger
    {
        return DB::transaction(function () use ($in) {
            $group = AccountGroup::findOrFail($in['account_group_id']);
            $code = $in['code'] ?? $this->nextCode($group);
            $this->checkCode($code, $group);
            $ledger = new Ledger($this->fields($in) + ['code' => $code, 'ledger_kind' => 'general', 'is_system' => false, 'is_active' => true]);
            $ledger->save();
            $opening = $this->opening($in);
            if ($opening !== null) {
                $this->setOpening($ledger, $opening);
            }
            $this->audit->record('created', $ledger, null, $ledger->only(self::AUDITED) + ['opening_balance' => (string) ($opening ?? Money::zero())]);

            return $ledger->refresh();
        });
    }

    public function update(Ledger $ledger, array $in, int $expectedVersion): Ledger
    {
        return DB::transaction(function () use ($ledger, $in, $expectedVersion) {
            $ledger = Ledger::whereKey($ledger->id)->lockForUpdate()->firstOrFail();
            if ((int) $ledger->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $ledger->lock_version]);
            }
            $this->ownedByMaster($ledger);
            $group = AccountGroup::findOrFail($in['account_group_id'] ?? $ledger->account_group_id);
            if ((int) $group->id !== (int) $ledger->account_group_id && $group->nature !== AccountGroup::whereKey($ledger->account_group_id)->value('nature')) {
                throw new ApiProblem('nature_mismatch', 'A ledger can move only to a group of the same nature (BR-027).');
            }
            $opening = $this->opening($in);
            $codeChanges = isset($in['code']) && $in['code'] !== $ledger->code;
            $openingChanges = $opening !== null && ! $this->openings->current($ledger, $ledger->id)->isEqualTo($opening);
            if (($codeChanges || $openingChanges) && $this->openings->locked($ledger, $ledger->id)) {
                throw new ApiProblem('ledger_locked', $codeChanges ? 'The code cannot change once the ledger has entries (BR-027).'
                    : 'The opening balance cannot change once the ledger has entries (BR-028).');
            }
            if ($codeChanges) {
                $this->checkCode($in['code'], $group);
            }
            $old = $ledger->only(self::AUDITED);
            $ledger->fill($this->fields($in));
            $ledger->lock_version = (int) $ledger->lock_version + 1;
            $ledger->save();
            if ($openingChanges) {
                $this->setOpening($ledger, $opening);
            }
            $this->audit->record('updated', $ledger, $old, $ledger->only(self::AUDITED));

            return $ledger->refresh();
        });
    }

    /** BR-026: inactive, never deleted. Refused while the ledger has a balance or a posting rule uses it (has_history). */
    public function deactivate(Ledger $ledger): Ledger
    {
        return DB::transaction(function () use ($ledger) {
            $ledger = Ledger::whereKey($ledger->id)->lockForUpdate()->firstOrFail();
            if (! $ledger->is_active) {
                return $ledger;
            }
            $this->ownedByMaster($ledger, true);
            $keys = DB::table('account_mappings')->where('ledger_id', $ledger->id)->pluck('mapping_key')->all();
            if ($keys !== []) {
                throw new ApiProblem('has_history', 'Posting rules use this ledger ('.implode(', ', $keys).'); map them to another ledger in Accounting defaults first.');
            }
            $balance = Money::of((string) DB::table('journal_lines')->where('ledger_id', $ledger->id)->where('is_posted', 1)
                ->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));
            if (! $balance->isZero()) {
                throw new ApiProblem('has_history', "The ledger still has a balance of {$balance->abs()} ".($balance->isNegative() ? 'Cr' : 'Dr').'; clear it with a journal first.');
            }
            $ledger->forceFill(['is_active' => false, 'lock_version' => (int) $ledger->lock_version + 1])->save();
            $this->audit->record('deactivated', $ledger, ['is_active' => true], ['is_active' => false]);

            return $ledger->refresh();
        });
    }

    private function fields(array $in): array
    {
        return array_intersect_key($in, array_flip(['code', 'name', 'account_group_id', 'description', 'track_bills', 'show_in_cash_vouchers']));
    }

    /** Signed opening (Dr positive) from opening_balance + opening_side, or null when not sent. */
    private function opening(array $in): ?Money
    {
        if (! array_key_exists('opening_balance', $in)) {
            return null;
        }
        $amount = Money::of($in['opening_balance'] ?? '0');

        return ($in['opening_side'] ?? 'dr') === 'cr' ? $amount->negated() : $amount;
    }

    private function setOpening(Ledger $ledger, Money $amount): void
    {
        if ((int) $ledger->id === (int) $this->mapper->ledgerFor('opening_balance_adjustment')->id) {
            throw new ApiProblem('validation_failed', errors: ['opening_balance' => ['The opening balance adjustment ledger cannot have its own opening balance']]);
        }
        $this->openings->set($ledger, $ledger->id, $amount, $ledger->name);
    }

    /** Edits answer validation_failed; deactivation answers has_history (its contract has no 422 for this). */
    private function ownedByMaster(Ledger $ledger, bool $deactivating = false): void
    {
        $master = self::MASTER_KINDS[$ledger->ledger_kind] ?? (DB::table('money_accounts')->where('ledger_id', $ledger->id)->exists() ? 'money account' : null);
        if ($master === null) {
            return;
        }
        if ($deactivating) {
            throw new ApiProblem('has_history', "This ledger belongs to a {$master}; make that {$master} inactive instead.");
        }
        throw new ApiProblem('validation_failed', errors: ['code' => ["This ledger belongs to a {$master}; change it from that {$master}"]]);
    }

    /** The code must start with the primary group's code (1 assets, 2 liabilities, …) and be free. */
    private function checkCode(string $code, AccountGroup $group): void
    {
        $root = $group;
        for ($guard = 0; $root->parent_id !== null && $guard < 20; $guard++) {
            $root = AccountGroup::findOrFail($root->parent_id);
        }
        if (! str_starts_with($code, $root->code)) {
            throw new ApiProblem('validation_failed', errors: ['code' => ["{$root->name} ledgers have codes starting with {$root->code}"]]);
        }
        if (Ledger::where('code', $code)->exists()) {
            throw new ApiProblem('validation_failed', errors: ['code' => ['Another ledger already uses this code']]);
        }
    }

    /** The first free "<group code>NN" (01–99), then "<group code>NNN". */
    private function nextCode(AccountGroup $group): string
    {
        $taken = Ledger::where('code', 'like', addcslashes($group->code, '%_\\').'%')->lockForUpdate()->pluck('code')->flip();
        foreach ([2, 3] as $width) {
            for ($n = 1; $n < 10 ** $width; $n++) {
                $code = $group->code.str_pad((string) $n, $width, '0', STR_PAD_LEFT);
                if (! isset($taken[$code]) && strlen($code) <= 20) {
                    return $code;
                }
            }
        }
        throw new ApiProblem('validation_failed', errors: ['code' => ['Enter a code; this group has no free automatic code']]);
    }
}
