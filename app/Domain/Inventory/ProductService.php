<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\Rate;
use App\Domain\Shared\UnitCost;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ProductWarehouseSetting;
use App\Models\Warehouse;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Products and services (D2/D3, backlog P3.1-BE-05/06). Quantities and costs change only through stock documents and
 * postings (BR-003, TC-INV-005), and on creation through the opening stock per warehouse (06 §11.2, P11.1-BE-05).
 * Services do not track stock (CF-09). Extra units (`units`, BR-069, P10.7) are replaced by the list sent; a unit already on
 * a trade document line keeps its factor and cannot be removed, so posted quantities keep their meaning.
 */
final class ProductService
{
    private const AUDITED = ['item_type', 'code', 'name', 'category_id', 'stock_group_id', 'unit_id', 'barcode', 'last_purchase_cost', 'selling_price',
        'wholesale_price', 'tax_rule_id', 'income_ledger_id', 'expense_ledger_id', 'track_stock', 'preferred_supplier_id', 'is_active'];

    /** Tables whose rows make a product "used" (BR-026): it can then only be deactivated, and its kind is fixed. */
    /** Trade document lines that may be in an extra unit (BR-069). */
    private const UNIT_LINES = ['purchase_order_lines', 'purchase_bill_lines', 'quotation_lines', 'sales_invoice_lines'];

    private const HISTORY = ['stock_movements', 'stock_adjustment_lines', 'stock_transfer_lines', 'purchase_order_lines', 'purchase_bill_lines',
        'purchase_return_lines', 'quotation_lines', 'sales_invoice_lines', 'sales_return_lines', 'landed_cost_allocation_lines'];

    public function __construct(private readonly AuditLogger $audit, private readonly CurrentCompany $current, private readonly OpeningStockService $openings) {}

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $this->checkRules($data['item_type'], $data);
            $product = new Product(['is_active' => true]);
            $product->fill(collect($data)->except(['warehouses', 'units'])->all());
            $product->track_stock = $data['item_type'] === 'product';
            $product->code = $data['code'] ?? $this->nextCode($data['category_id'] ?? null);
            $product->save();
            $this->syncWarehouses($product, $data['warehouses'] ?? []);
            $this->syncUnits($product, $data['units'] ?? []);
            foreach ($data['warehouses'] ?? [] as $w) {
                if (Quantity::of($w['opening_qty'] ?? '0')->isPositive()) {
                    $this->openings->post($product, Warehouse::findOrFail($w['warehouse_id']), Quantity::of($w['opening_qty']), UnitCost::of($w['opening_unit_cost']));
                }
            }
            $this->audit->record('created', $product, null, $this->audited($product) + ['warehouses' => $data['warehouses'] ?? []]
                + (empty($data['units']) ? [] : ['units' => $this->unitList($product)]));

            return $product->refresh();
        });
    }

    public function update(Product $product, array $data, int $expectedVersion): Product
    {
        return DB::transaction(function () use ($product, $data, $expectedVersion) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ((int) $product->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $product->lock_version]);
            }
            $type = $data['item_type'] ?? $product->item_type;
            if ($type !== $product->item_type && $this->hasHistory($product)) {
                throw new ApiProblem('validation_failed', errors: ['item_type' => ['This item is already used on documents, so it cannot change between product and service.']]);
            }
            $this->checkRules($type, $data + ['income_ledger_id' => $product->income_ledger_id]);
            $old = $this->audited($product);
            $oldUnits = $this->unitList($product);
            $product->fill(collect($data)->except(['warehouses', 'units'])->all());
            $product->track_stock = $type === 'product';
            $product->save();
            if (array_key_exists('units', $data)) {
                $this->syncUnits($product, $data['units'] ?? []);
            } elseif ($product->wasChanged('unit_id')) {
                $this->checkUnits($product, $oldUnits); // the new base unit may not be one of the extra units
            }
            $newUnits = $this->unitList($product);
            if ($oldUnits !== $newUnits && ! $product->wasChanged()) {
                // The units are part of the item, so its version (ETag) moves too (BR-049). Not touch(): within the same
                // second updated_at would not change and no update (and no version step) would run.
                Product::whereKey($product->id)->update(['lock_version' => (int) $product->lock_version + 1, 'updated_at' => now()]);
            }
            if ($type === 'service') {
                ProductWarehouseSetting::where('product_id', $product->id)->delete();
            }
            $this->syncWarehouses($product, $data['warehouses'] ?? []);
            $new = $this->audited($product);
            if ($old !== $new || ! empty($data['warehouses']) || $oldUnits !== $newUnits) {
                $units = $oldUnits !== $newUnits;
                $this->audit->record('updated', $product, array_diff_assoc($old, $new) + ($units ? ['units' => $oldUnits] : []),
                    array_diff_assoc($new, $old) + (empty($data['warehouses']) ? [] : ['warehouses' => $data['warehouses']]) + ($units ? ['units' => $newUnits] : []));
            }

            return $product->refresh();
        });
    }

    /**
     * DELETE /products/{id}: only an unused item (BR-026, TC-INV-006); a used one is made inactive with PATCH
     * is_active=false instead. Soft delete, so its code is never reused.
     */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($this->hasHistory($product)) {
                throw new ApiProblem('has_history', 'This item is used on stock or trade documents; make it inactive instead.');
            }
            $old = $this->audited($product);
            ProductWarehouseSetting::where('product_id', $product->id)->delete();
            ProductUnit::where('product_id', $product->id)->delete();
            $product->delete();
            $this->audit->record('deleted', $product, $old);
        });
    }

    /** BR-026: used anywhere in stock or trade documents. */
    public function hasHistory(Product $product): bool
    {
        foreach (self::HISTORY as $table) {
            if (DB::table($table)->where('product_id', $product->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Ledger natures (picker purposes income_for_product / expense_for_product) and the service rules. */
    private function checkRules(string $type, array $data): void
    {
        $errors = [];
        if (isset($data['income_ledger_id']) && ! $this->ledgerIs($data['income_ledger_id'], 'income')) {
            $errors['income_ledger_id'] = ['Choose an active income account'];
        }
        if (isset($data['expense_ledger_id']) && ! $this->ledgerIs($data['expense_ledger_id'], 'expense')) {
            $errors['expense_ledger_id'] = ['Choose an active expense account'];
        }
        if ($type === 'service' && ! empty($data['warehouses'])) {
            $errors['warehouses'] = ['Services have no stock, so they have no warehouse settings'];
        }
        foreach ($data['warehouses'] ?? [] as $i => $w) {
            if (Quantity::of($w['opening_qty'] ?? '0')->isPositive() && ($w['opening_unit_cost'] ?? null) === null) {
                $errors["warehouses.{$i}.opening_unit_cost"] = ['Enter the cost per unit of the opening stock'];
            }
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }
    }

    private function ledgerIs(int $ledgerId, string $nature): bool
    {
        return DB::table('ledgers')->join('account_groups', 'account_groups.id', '=', 'ledgers.account_group_id')
            ->where('ledgers.company_id', $this->current->id())->where('ledgers.id', $ledgerId)->where('ledgers.is_active', true)
            ->where('ledgers.ledger_kind', 'general')->where('account_groups.nature', $nature)->exists();
    }

    /**
     * Next free code in the series of the category's prefix (e.g. OIL-0007; a sub-category without one uses its
     * parent's), or PRD-0001… without a prefix. The company row is locked so two saves cannot take the same number;
     * soft-deleted codes are never reused.
     */
    private function nextCode(?int $categoryId): string
    {
        Company::whereKey($this->current->id())->lockForUpdate()->first();
        $category = $categoryId ? ProductCategory::with('parent')->find($categoryId) : null;
        $prefix = $category?->code_prefix ?: ($category?->parent?->code_prefix ?: 'PRD');
        $last = Product::withTrashed()->where('code', 'like', "{$prefix}-%")->pluck('code')
            ->map(fn ($c) => preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $c, $m) ? (int) $m[1] : 0)->max() ?? 0;

        return $prefix.'-'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Upserts reorder settings for the warehouses sent; warehouses not sent keep theirs. */
    private function syncWarehouses(Product $product, array $rows): void
    {
        foreach ($rows as $row) {
            $setting = ProductWarehouseSetting::firstOrNew(['product_id' => $product->id, 'warehouse_id' => $row['warehouse_id']]);
            foreach (['reorder_level', 'reorder_qty'] as $field) {
                if (array_key_exists($field, $row)) {
                    $setting->{$field} = $row[$field] ?? '0';
                }
            }
            $setting->save();
        }
    }

    /**
     * Replaces the item's extra units with `$rows` (BR-069). The base unit cannot be an extra unit, factors are above 0,
     * and a unit already used on a trade document line can be neither removed nor given another factor.
     */
    private function syncUnits(Product $product, array $rows): void
    {
        $factors = [];
        $index = [];
        foreach ($rows as $i => $row) {
            $factors[(int) $row['unit_id']] = Rate::of($row['factor'])->toString();
            $index[(int) $row['unit_id']] = $i;
        }
        $this->checkUnits($product, $factors, $index);
        $current = ProductUnit::with('unit')->where('product_id', $product->id)->get()->keyBy('unit_id');
        $used = $this->usedUnits($product);
        $errors = [];
        foreach ($current as $unitId => $pu) {
            $kept = array_key_exists($unitId, $factors);
            if (in_array((int) $unitId, $used, true) && (! $kept || ! Rate::of($factors[$unitId])->isEqualTo($pu->factor))) {
                $field = $kept ? "units.{$index[$unitId]}.factor" : 'units';
                $errors[$field] = ["{$pu->unit?->name} is already used on documents with factor {$this->trim((string) $pu->factor)}, so it must stay with that factor"];
            }
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }
        foreach ($current as $unitId => $pu) {
            if (! array_key_exists($unitId, $factors)) {
                $pu->delete();
            } elseif (! Rate::of($factors[$unitId])->isEqualTo($pu->factor)) {
                $pu->update(['factor' => $factors[$unitId]]);
            }
        }
        foreach ($factors as $unitId => $factor) {
            if (! $current->has($unitId)) {
                ProductUnit::create(['product_id' => $product->id, 'unit_id' => $unitId, 'factor' => $factor]);
            }
        }
    }

    /**
     * The base unit is not an extra unit and every factor is above 0.
     *
     * @param  array<int, string>  $factors  unit_id => factor
     * @param  array<int, int>  $index  unit_id => position in the request (empty when the base unit changed alone)
     */
    private function checkUnits(Product $product, array $factors, array $index = []): void
    {
        $errors = [];
        foreach ($factors as $unitId => $factor) {
            if ((int) $unitId === (int) $product->unit_id) {
                $errors[isset($index[$unitId]) ? "units.{$index[$unitId]}.unit_id" : 'unit_id'] = ['This is the base unit; an extra unit must be a different one'];
            } elseif (! Rate::of($factor)->isPositive()) {
                $errors["units.{$index[$unitId]}.factor"] = ['Enter a number above 0'];
            }
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }
    }

    /** Extra units of the item that are on any trade document line (BR-069). */
    private function usedUnits(Product $product): array
    {
        $used = [];
        foreach (self::UNIT_LINES as $table) {
            $used = array_merge($used, DB::table($table)->where('product_id', $product->id)->whereNotNull('unit_id')->distinct()->pluck('unit_id')->all());
        }

        return array_values(array_unique(array_map('intval', $used)));
    }

    /** unit_id => factor, for audit logs. */
    private function unitList(Product $product): array
    {
        return ProductUnit::where('product_id', $product->id)->orderBy('unit_id')->pluck('factor', 'unit_id')->map(fn ($f) => (string) $f)->all();
    }

    private function trim(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    private function audited(Product $p): array
    {
        return $p->only(self::AUDITED);
    }
}
