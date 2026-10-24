<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\AuditLogger;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductWarehouseSetting;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Products and services (D2/D3, backlog P3.1-BE-05/06). Quantities and costs are never set here: they change only
 * through stock documents and postings (BR-003, TC-INV-005). Services do not track stock (CF-09).
 */
final class ProductService
{
    private const AUDITED = ['item_type', 'code', 'name', 'category_id', 'stock_group_id', 'unit_id', 'barcode', 'last_purchase_cost', 'selling_price',
        'wholesale_price', 'tax_rule_id', 'income_ledger_id', 'expense_ledger_id', 'track_stock', 'preferred_supplier_id', 'is_active'];

    /** Tables whose rows make a product "used" (BR-026): it can then only be deactivated, and its kind is fixed. */
    private const HISTORY = ['stock_movements', 'stock_adjustment_lines', 'stock_transfer_lines', 'purchase_order_lines', 'purchase_bill_lines',
        'purchase_return_lines', 'quotation_lines', 'sales_invoice_lines', 'sales_return_lines', 'landed_cost_allocation_lines'];

    public function __construct(private readonly AuditLogger $audit, private readonly CurrentCompany $current) {}

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $this->checkRules($data['item_type'], $data);
            $product = new Product(['is_active' => true]);
            $product->fill(collect($data)->except('warehouses')->all());
            $product->track_stock = $data['item_type'] === 'product';
            $product->code = $data['code'] ?? $this->nextCode($data['category_id'] ?? null);
            $product->save();
            $this->syncWarehouses($product, $data['warehouses'] ?? []);
            $this->audit->record('created', $product, null, $this->audited($product) + ['warehouses' => $data['warehouses'] ?? []]);

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
            $product->fill(collect($data)->except('warehouses')->all());
            $product->track_stock = $type === 'product';
            $product->save();
            if ($type === 'service') {
                ProductWarehouseSetting::where('product_id', $product->id)->delete();
            }
            $this->syncWarehouses($product, $data['warehouses'] ?? []);
            $new = $this->audited($product);
            if ($old !== $new || ! empty($data['warehouses'])) {
                $this->audit->record('updated', $product, array_diff_assoc($old, $new), array_diff_assoc($new, $old) + (empty($data['warehouses']) ? [] : ['warehouses' => $data['warehouses']]));
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

    private function audited(Product $p): array
    {
        return $p->only(self::AUDITED);
    }
}
