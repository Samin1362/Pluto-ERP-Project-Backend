<?php

namespace App\Domain\Accounting;

use App\Domain\Shared\AuditLogger;
use App\Models\AccountGroup;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Account groups (H2/H3; backlog P7.1-BE-01; BR-027). A new group sits under an existing one and takes its nature,
 * normal balance and report section from it (the nature is fixed by the primary group at the root). Seeded groups
 * cannot be changed. A group may move only under a group of the same nature, never under itself or its own sub-groups;
 * its code is locked once any ledger under it has posted entries.
 */
final class AccountGroupService
{
    private const AUDITED = ['code', 'name', 'parent_id', 'cash_flow_category', 'sort_order', 'used_in_cash_vouchers'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $in): AccountGroup
    {
        return DB::transaction(function () use ($in) {
            $parent = AccountGroup::findOrFail($in['parent_id']);
            $group = new AccountGroup($this->fields($in) + ['nature' => $parent->nature, 'normal_balance' => $parent->normal_balance,
                'report_section' => $parent->report_section, 'is_system' => false]);
            $group->save();
            $this->audit->record('created', $group, null, $group->only(self::AUDITED));

            return $group->refresh();
        });
    }

    public function update(AccountGroup $group, array $in, int $expectedVersion): AccountGroup
    {
        return DB::transaction(function () use ($group, $in, $expectedVersion) {
            $group = AccountGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ((int) $group->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $group->lock_version]);
            }
            if ($group->is_system) {
                throw new ApiProblem('validation_failed', errors: ['code' => ['Built-in groups cannot be changed; add a sub-group instead']]);
            }
            if (isset($in['parent_id']) && (int) $in['parent_id'] !== (int) $group->parent_id) {
                $parent = AccountGroup::findOrFail($in['parent_id']);
                if (in_array($parent->id, $this->subtree($group->id), true)) {
                    throw new ApiProblem('validation_failed', errors: ['parent_id' => ['A group cannot move under itself or its own sub-groups']]);
                }
                if ($parent->nature !== $group->nature) {
                    throw new ApiProblem('nature_mismatch', "Move it under another {$group->nature} group.");
                }
            }
            if (isset($in['code']) && $in['code'] !== $group->code && $this->hasEntries($group->id)) {
                throw new ApiProblem('ledger_locked', 'The code cannot change once ledgers in this group have entries.');
            }
            $old = $group->only(self::AUDITED);
            $group->fill($this->fields($in));
            $group->lock_version = (int) $group->lock_version + 1;
            $group->save();
            $this->audit->record('updated', $group, $old, $group->only(self::AUDITED));

            return $group->refresh();
        });
    }

    private function fields(array $in): array
    {
        return array_intersect_key($in, array_flip(self::AUDITED));
    }

    /** @return list<int> the group and every group below it */
    private function subtree(int $id): array
    {
        $ids = [$id];
        for ($level = [$id]; $level !== [];) {
            $level = AccountGroup::whereIn('parent_id', $level)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    private function hasEntries(int $groupId): bool
    {
        return DB::table('journal_lines')->join('ledgers', 'ledgers.id', '=', 'journal_lines.ledger_id')
            ->whereIn('ledgers.account_group_id', $this->subtree($groupId))->exists();
    }
}
