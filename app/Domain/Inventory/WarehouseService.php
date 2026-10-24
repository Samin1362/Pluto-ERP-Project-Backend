<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Models\AccountMapping;
use App\Models\Ledger;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Warehouses (D7/D8, backlog P3.1-BE-04). Each warehouse posts to an inventory ledger under the group mapped as
 * `inventory_group` (06 §3, 1301): the seeded "1301-01 Inventory, main warehouse" goes to the first warehouse, later
 * ones get a new 1301-NN in the same transaction, unless an existing inventory ledger is chosen (shared, CF-10).
 */
final class WarehouseService
{
    private const AUDITED = ['code', 'name', 'branch_id', 'address', 'manager_user_id', 'phone', 'inventory_ledger_id', 'is_default_for_sales', 'status', 'opens_on'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data) {
            $groupId = $this->inventoryGroupId();
            $ledgerId = $data['inventory_ledger_id'] ?? null;
            if ($ledgerId !== null) {
                $this->checkInventoryLedger($ledgerId, $groupId);
            } else {
                $ledgerId = $this->ledgerFor($data['name'], $groupId)->id;
            }
            $warehouse = new Warehouse(['status' => 'active', 'is_default_for_sales' => false]);
            $warehouse->fill(['inventory_ledger_id' => $ledgerId] + $data);
            $warehouse->save();
            if ($warehouse->is_default_for_sales) {
                $this->onlyDefault($warehouse);
            }
            $this->audit->record('created', $warehouse, null, $this->audited($warehouse));

            return $warehouse->refresh();
        });
    }

    public function update(Warehouse $warehouse, array $data, int $expectedVersion): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data, $expectedVersion) {
            $warehouse = Warehouse::whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            if ((int) $warehouse->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $warehouse->lock_version]);
            }
            $hasStock = StockBalance::where('warehouse_id', $warehouse->id)->where(fn ($q) => $q->where('qty_on_hand', '!=', 0)->orWhere('qty_in_transit_out', '!=', 0))->exists();
            if (($data['status'] ?? $warehouse->status) === 'inactive' && $warehouse->status !== 'inactive' && $hasStock) {
                throw new ApiProblem('validation_failed', errors: ['status' => ['This warehouse still holds stock; transfer or adjust it to zero before making it inactive.']]);
            }
            if (array_key_exists('inventory_ledger_id', $data) && $data['inventory_ledger_id'] !== $warehouse->inventory_ledger_id) {
                if ($data['inventory_ledger_id'] === null) {
                    throw new ApiProblem('validation_failed', errors: ['inventory_ledger_id' => ['A warehouse always has an inventory ledger.']]);
                }
                if ($hasStock) {
                    throw new ApiProblem('validation_failed', errors: ['inventory_ledger_id' => ['The inventory ledger cannot change while the warehouse holds stock.']]);
                }
                $this->checkInventoryLedger($data['inventory_ledger_id'], $this->inventoryGroupId());
            }
            $old = $this->audited($warehouse);
            $oldName = $warehouse->name;
            $warehouse->fill($data)->save();
            // Its own ledger follows a rename ("Inventory, <name>"); a shared or renamed-by-hand ledger is left alone.
            if ($warehouse->name !== $oldName) {
                Ledger::whereKey($warehouse->inventory_ledger_id)->where('name', "Inventory, {$oldName}")->update(['name' => "Inventory, {$warehouse->name}"]);
            }
            if ($warehouse->is_default_for_sales) {
                $this->onlyDefault($warehouse);
            }
            $new = $this->audited($warehouse);
            if ($old !== $new) {
                $this->audit->record('updated', $warehouse, array_diff_assoc($old, $new), array_diff_assoc($new, $old));
            }

            return $warehouse->refresh();
        });
    }

    private function inventoryGroupId(): int
    {
        return AccountMapping::where('mapping_key', 'inventory_group')->value('account_group_id')
            ?? throw new ApiProblem('mapping_missing', 'Map inventory_group in Accounting defaults first.');
    }

    /** The seeded 1301-01 while no warehouse uses it, otherwise a new ledger with the next free 1301-NN. */
    private function ledgerFor(string $warehouseName, int $groupId): Ledger
    {
        $groupCode = DB::table('account_groups')->where('id', $groupId)->lockForUpdate()->value('code');
        $codes = Ledger::where('account_group_id', $groupId)->where('code', 'like', "{$groupCode}-%")->pluck('id', 'code');
        $first = "{$groupCode}-01";
        if (isset($codes[$first]) && ! Warehouse::where('inventory_ledger_id', $codes[$first])->exists()
            && Ledger::whereKey($codes[$first])->where('ledger_kind', 'inventory')->exists()) {
            $ledger = Ledger::find($codes[$first]);
            $ledger->update(['name' => "Inventory, {$warehouseName}"]);

            return $ledger;
        }
        $next = 1 + collect($codes->keys())->map(fn ($c) => (int) substr($c, strlen($groupCode) + 1))->max();

        return Ledger::create([
            'account_group_id' => $groupId, 'code' => $groupCode.'-'.str_pad((string) $next, 2, '0', STR_PAD_LEFT),
            'name' => "Inventory, {$warehouseName}", 'ledger_kind' => 'inventory', 'is_active' => true,
        ]);
    }

    private function checkInventoryLedger(int $ledgerId, int $groupId): void
    {
        $ok = Ledger::whereKey($ledgerId)->where('account_group_id', $groupId)->where('ledger_kind', 'inventory')->where('is_active', true)->exists();
        if (! $ok) {
            throw new ApiProblem('validation_failed', errors: ['inventory_ledger_id' => ['Choose an active inventory ledger under 1301 Inventory, or leave it empty to create one.']]);
        }
    }

    /** One default warehouse for sales: setting it on one clears it on the others. */
    private function onlyDefault(Warehouse $warehouse): void
    {
        Warehouse::where('id', '!=', $warehouse->id)->where('is_default_for_sales', true)->get()
            ->each(fn (Warehouse $w) => $w->update(['is_default_for_sales' => false]));
    }

    private function audited(Warehouse $w): array
    {
        $a = $w->only(self::AUDITED);
        $a['opens_on'] = $w->opens_on?->toDateString();

        return $a;
    }
}
