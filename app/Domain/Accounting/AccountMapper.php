<?php

namespace App\Domain\Accounting;

use App\Models\AccountMapping;
use App\Models\Ledger;
use App\Support\Problem\ApiProblem;

/**
 * Posting roles → ledgers (06 §3; backlog P4.1-BE-04). Posting services never hard-code account codes: they ask for
 * a mapping key (cogs, rounding, purchases_non_stock, …). A key that is missing, points at a group, or at an inactive
 * ledger is a mapping_missing problem the accountant fixes in Accounting defaults (K16).
 */
final class AccountMapper
{
    /** @var array<string, Ledger> per request */
    private array $cache = [];

    public function ledgerFor(string $key): Ledger
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $mapping = AccountMapping::where('mapping_key', $key)->first();
        $ledger = $mapping?->ledger_id ? Ledger::where('is_active', true)->find($mapping->ledger_id) : null;
        if (! $ledger) {
            throw new ApiProblem('mapping_missing', "Map \"{$key}\" to an active ledger in Accounting defaults first.", extra: ['mapping_key' => $key]);
        }

        return $this->cache[$key] = $ledger;
    }

    /** Group mappings (inventory_group, sundry_creditors_group, …): the account group id new ledgers go under. */
    public function groupIdFor(string $key): int
    {
        $id = AccountMapping::where('mapping_key', $key)->value('account_group_id');
        if (! $id) {
            throw new ApiProblem('mapping_missing', "Map \"{$key}\" to an account group in Accounting defaults first.", extra: ['mapping_key' => $key]);
        }

        return (int) $id;
    }
}
