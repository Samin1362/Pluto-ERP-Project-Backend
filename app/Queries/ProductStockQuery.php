<?php

namespace App\Queries;

use App\Support\CurrentCompany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Stock figures of products (backlog P3.1-BE-05/06): per warehouse (StockLevel) and per product (on hand, value,
 * average cost, stock status). Everything is summed, divided and compared in MySQL as DECIMAL, never as PHP floats.
 * Status: out_of_stock when nothing is on hand, low_stock when any warehouse is at or below a reorder level > 0.
 */
final class ProductStockQuery
{
    public function __construct(private readonly CurrentCompany $current) {}

    /**
     * StockLevel rows of one product: every active warehouse, plus inactive ones that still hold it.
     *
     * @return list<object>
     */
    public function levels(int $productId): array
    {
        return DB::table('warehouses as w')->where('w.company_id', $this->current->id())
            ->join('products as p', fn ($j) => $j->where('p.id', '=', $productId))
            ->leftJoin('stock_balances as b', fn ($j) => $j->on('b.warehouse_id', '=', 'w.id')->where('b.product_id', '=', $productId))
            ->leftJoin('product_warehouse_settings as s', fn ($j) => $j->on('s.warehouse_id', '=', 'w.id')->where('s.product_id', '=', $productId))
            ->where(fn ($q) => $q->where('w.status', '!=', 'inactive')->orWhere('b.qty_on_hand', '!=', 0))
            ->orderBy('w.name')
            ->selectRaw(self::LEVEL)
            ->get()->all();
    }

    /**
     * StockLevel rows of one warehouse (D8): every stock-tracked product with a balance or a reorder setting there.
     * `below_reorder` keeps the rows at or below a reorder level > 0. Search by code, name or barcode.
     *
     * @param  array{q?: string, below_reorder?: string, sort?: string, page?: int, per_page?: int}  $f
     */
    public function forWarehouse(int $warehouseId, array $f): LengthAwarePaginator
    {
        $q = DB::table('products as p')->where('p.company_id', $this->current->id())->where('p.track_stock', true)->whereNull('p.deleted_at')
            ->join('warehouses as w', fn ($j) => $j->where('w.id', '=', $warehouseId))
            ->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 'p.id')->where('b.warehouse_id', '=', $warehouseId))
            ->leftJoin('product_warehouse_settings as s', fn ($j) => $j->on('s.product_id', '=', 'p.id')->where('s.warehouse_id', '=', $warehouseId))
            ->where(fn ($w) => $w->whereNotNull('b.id')->orWhereNotNull('s.id'))
            ->selectRaw(self::LEVEL);
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('p.code', 'like', $term)->orWhere('p.name', 'like', $term)->orWhere('p.barcode', 'like', $term));
        }
        if (in_array($f['below_reorder'] ?? null, ['true', '1'], true)) {
            $q->where('s.reorder_level', '>', 0)->whereRaw('COALESCE(b.qty_on_hand, 0) <= s.reorder_level');
        }
        $sort = $f['sort'] ?? 'name';
        $col = ['name' => 'p.name', 'code' => 'p.code', 'qty_on_hand' => 'qty_on_hand'][ltrim($sort, '-')];

        return $q->orderBy($col, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('p.id')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
    }

    /** One StockLevel row (aliases: p products, w warehouses, b stock_balances, s product_warehouse_settings). */
    private const LEVEL = "p.id as product_id, p.code as product_code, p.name as product_name, w.id as warehouse_id, w.name as warehouse_name,
        CAST(COALESCE(b.qty_on_hand, 0) AS DECIMAL(18,3)) as qty_on_hand, CAST(COALESCE(s.reorder_level, 0) AS DECIMAL(18,3)) as reorder_level,
        CAST(COALESCE(s.reorder_qty, 0) AS DECIMAL(18,3)) as reorder_qty, CAST(COALESCE(b.total_value, 0) AS DECIMAL(18,2)) as value,
        CASE WHEN b.id IS NULL AND s.id IS NULL THEN 'not_stocked'
             WHEN COALESCE(b.qty_on_hand, 0) <= 0 THEN 'out_of_stock'
             WHEN s.reorder_level > 0 AND b.qty_on_hand <= s.reorder_level THEN 'low_stock'
             ELSE 'in_stock' END as status";

    /**
     * Totals per product id: on_hand, stock_value, avg_cost (value / quantity, 4 decimals) and stock_status
     * (not_tracked for services and untracked items).
     *
     * @return array<int, object>
     */
    public function totals(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }

        return DB::table('products as p')->where('p.company_id', $this->current->id())->whereIn('p.id', $productIds)
            ->selectRaw(self::TOTALS)->get()->keyBy('id')->all();
    }

    /** Select list for the per-product totals, also used by the product list (ProductQuery). */
    public const TOTALS = "p.id,
        (SELECT CAST(COALESCE(SUM(b.qty_on_hand), 0) AS DECIMAL(18,3)) FROM stock_balances b WHERE b.product_id = p.id) as on_hand,
        (SELECT CAST(COALESCE(SUM(b.total_value), 0) AS DECIMAL(18,2)) FROM stock_balances b WHERE b.product_id = p.id) as stock_value,
        (SELECT CAST(COALESCE(SUM(b.total_value) / NULLIF(SUM(b.qty_on_hand), 0), 0) AS DECIMAL(18,4)) FROM stock_balances b WHERE b.product_id = p.id) as avg_cost,
        CASE WHEN p.track_stock = 0 THEN 'not_tracked'
             WHEN (SELECT COALESCE(SUM(b.qty_on_hand), 0) FROM stock_balances b WHERE b.product_id = p.id) <= 0 THEN 'out_of_stock'
             WHEN EXISTS (SELECT 1 FROM product_warehouse_settings s LEFT JOIN stock_balances b ON b.product_id = s.product_id AND b.warehouse_id = s.warehouse_id
                          WHERE s.product_id = p.id AND s.reorder_level > 0 AND COALESCE(b.qty_on_hand, 0) <= s.reorder_level) THEN 'low_stock'
             ELSE 'in_stock' END as stock_status";
}
