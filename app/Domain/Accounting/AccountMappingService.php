<?php

namespace App\Domain\Accounting;

use App\Domain\Shared\AuditLogger;
use App\Models\AccountGroup;
use App\Models\AccountMapping;
use App\Models\Ledger;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Accounting defaults (K16; 06 §3; 05 getAccountMappings / putAccountMappings; TC-ACC-024; backlog P7.1-BE-08). Each
 * posting role maps to a group (new party, money-account and warehouse ledgers go under it) or to an active ledger,
 * of the nature 06 §3 requires. PUT saves the keys it is sent, all or nothing: an unknown key, a wrong kind, an
 * inactive ledger or a wrong nature is validation_failed on that row; emptying a key is mapping_missing (postings
 * need every key). Changes are audited.
 */
final class AccountMappingService
{
    /** key => [group|ledger, allowed natures, what it is used for] (06 §3) */
    public const KEYS = [
        'sundry_debtors_group' => ['group', ['asset'], 'Parent group of new customer ledgers'],
        'sundry_creditors_group' => ['group', ['liability'], 'Parent group of new supplier ledgers'],
        'cash_bank_group' => ['group', ['asset'], 'Parent group of cash and bank ledgers'],
        'inventory_group' => ['group', ['asset'], 'Parent group of warehouse inventory ledgers'],
        'sales_default' => ['ledger', ['income'], 'Product income ledger when none is set'],
        'sales_returns' => ['ledger', ['income'], 'Credit notes for returned goods'],
        'discount_allowed' => ['ledger', ['income', 'expense'], 'Discounts allowed, when posted separately'],
        'discount_received' => ['ledger', ['income'], 'Bill-level discounts on non-stock bills'],
        'cogs' => ['ledger', ['expense'], 'Cost of goods sold'],
        'stock_adjustment' => ['ledger', ['expense'], 'Shortage, damage, expiry and samples; gains reduce it'],
        'purchases_non_stock' => ['ledger', ['expense'], 'Bill lines with no product ledger'],
        'purchase_returns_non_stock' => ['ledger', ['expense'], 'Debit notes on non-stock lines'],
        'stock_in_transit' => ['ledger', ['asset'], 'Transfers between warehouses with different ledgers'],
        'goods_in_transit' => ['ledger', ['asset'], 'LC charges awaiting landed-cost allocation'],
        'lc_margin' => ['ledger', ['asset'], 'LC margin deposits'],
        'lc_acceptance' => ['ledger', ['liability'], 'Usance LC acceptance liability'],
        'fx_gain_loss' => ['ledger', ['expense', 'income'], 'Exchange differences'],
        'bank_charges' => ['ledger', ['expense'], 'Bank fees not capitalised'],
        'rounding' => ['ledger', ['expense'], 'Invoice and bill rounding'],
        'cash_short_over' => ['ledger', ['expense'], 'Cash-count differences'],
        'opening_balance_adjustment' => ['ledger', ['equity'], 'Contra for opening balances'],
        'retained_earnings' => ['ledger', ['equity'], 'Year-end close'],
        'suspense' => ['ledger', ['liability'], 'Unbalanced legacy imports only; must stay zero'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** AccountMappings (05 schema), in 06 §3 order. */
    public function list(): array
    {
        $rows = AccountMapping::get()->keyBy('mapping_key');

        return ['mappings' => array_map(fn ($key) => ['mapping_key' => $key, 'ledger_id' => $rows[$key]->ledger_id ?? null,
            'account_group_id' => $rows[$key]->account_group_id ?? null, 'required_nature' => self::nature($key), 'description' => self::KEYS[$key][2]],
            array_keys(self::KEYS))];
    }

    /** W/"…" for the whole set: the last change. */
    public function version(): string
    {
        return (string) (AccountMapping::max('updated_at') ? strtotime((string) AccountMapping::max('updated_at')) : 0);
    }

    public function save(array $mappings): void
    {
        $errors = [];
        $missing = [];
        $changes = [];
        foreach ($mappings as $i => $m) {
            $key = $m['mapping_key'];
            if (! isset(self::KEYS[$key])) {
                $errors["mappings.{$i}.mapping_key"] = ['Unknown posting role'];

                continue;
            }
            [$kind, $natures] = self::KEYS[$key];
            $id = $kind === 'group' ? ($m['account_group_id'] ?? null) : ($m['ledger_id'] ?? null);
            $other = $kind === 'group' ? ($m['ledger_id'] ?? null) : ($m['account_group_id'] ?? null);
            $field = $kind === 'group' ? 'account_group_id' : 'ledger_id';
            if ($other !== null) {
                $errors["mappings.{$i}.{$field}"] = [$kind === 'group' ? 'This role takes a group, not a ledger' : 'This role takes a ledger, not a group'];

                continue;
            }
            if ($id === null) {
                $missing[] = $key;

                continue;
            }
            $target = $kind === 'group' ? AccountGroup::find($id) : Ledger::find($id);
            $nature = $kind === 'group' ? $target?->nature : ($target ? AccountGroup::whereKey($target->account_group_id)->value('nature') : null);
            if (! $target) {
                $errors["mappings.{$i}.{$field}"] = ['Choose a '.($kind === 'group' ? 'group' : 'ledger').' of this company'];
            } elseif ($kind === 'ledger' && ! $target->is_active) {
                $errors["mappings.{$i}.{$field}"] = ["{$target->name} is inactive"];
            } elseif (! in_array($nature, $natures, true)) {
                $errors["mappings.{$i}.{$field}"] = ["{$target->name} is {$nature}; {$key} needs ".self::nature($key)];
            } else {
                $changes[$key] = [$field, (int) $id];
            }
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }
        if ($missing) {
            throw new ApiProblem('mapping_missing', 'Every posting role needs a ledger or group; empty: '.implode(', ', $missing).'.', extra: ['mapping_key' => $missing[0]]);
        }
        DB::transaction(function () use ($changes) {
            foreach ($changes as $key => [$field, $id]) {
                $row = AccountMapping::where('mapping_key', $key)->lockForUpdate()->first() ?? new AccountMapping(['mapping_key' => $key]);
                if ((int) $row->{$field} === $id) {
                    continue;
                }
                $old = $row->only(['ledger_id', 'account_group_id']);
                $row->fill([$field => $id])->save();
                $this->audit->record('updated', null, [$key => $old], [$key => $row->only(['ledger_id', 'account_group_id'])], type: 'account_mappings');
            }
        });
    }

    public static function nature(string $key): string
    {
        [$kind, $natures] = self::KEYS[$key];

        return implode(' or ', $natures).($kind === 'group' ? ' group' : '');
    }
}
