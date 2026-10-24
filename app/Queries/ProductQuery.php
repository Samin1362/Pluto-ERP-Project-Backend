<?php

namespace App\Queries;

use App\Support\CurrentCompany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read model for GET /products (D1, backlog P3.1-BE-06; FR-030). Stock figures per product come from
 * stock_balances against the reorder levels, for all warehouses or only the one in `warehouse_id`, computed in MySQL.
 */
final class ProductQuery
{
    public function __construct(private readonly CurrentCompany $current) {}

    /** @return LengthAwarePaginator rows: id, on_hand, stock_value, avg_cost, stock_status */
    public function page(array $f): LengthAwarePaginator
    {
        $c = $this->current->id();
        $wh = isset($f['warehouse_id']) ? (int) $f['warehouse_id'] : null;
        $inWh = $wh ? " AND b.warehouse_id = {$wh}" : '';
        $sInWh = $wh ? " AND s.warehouse_id = {$wh}" : '';

        $inner = DB::table('products as p')->where('p.company_id', $c)->whereNull('p.deleted_at')
            ->selectRaw("p.id, p.name, p.code, p.selling_price, p.item_type, p.category_id, p.barcode, p.is_active, p.track_stock,
                (SELECT CAST(COALESCE(SUM(b.qty_on_hand), 0) AS DECIMAL(18,3)) FROM stock_balances b WHERE b.product_id = p.id{$inWh}) as on_hand,
                (SELECT CAST(COALESCE(SUM(b.total_value), 0) AS DECIMAL(18,2)) FROM stock_balances b WHERE b.product_id = p.id{$inWh}) as stock_value,
                (SELECT CAST(COALESCE(SUM(b.total_value) / NULLIF(SUM(b.qty_on_hand), 0), 0) AS DECIMAL(18,4)) FROM stock_balances b WHERE b.product_id = p.id{$inWh}) as avg_cost,
                CASE WHEN p.track_stock = 0 THEN 'not_tracked'
                     WHEN (SELECT COALESCE(SUM(b.qty_on_hand), 0) FROM stock_balances b WHERE b.product_id = p.id{$inWh}) <= 0 THEN 'out_of_stock'
                     WHEN EXISTS (SELECT 1 FROM product_warehouse_settings s LEFT JOIN stock_balances b ON b.product_id = s.product_id AND b.warehouse_id = s.warehouse_id
                                  WHERE s.product_id = p.id{$sInWh} AND s.reorder_level > 0 AND COALESCE(b.qty_on_hand, 0) <= s.reorder_level) THEN 'low_stock'
                     ELSE 'in_stock' END as stock_status");

        if ($wh) {
            // Products stocked in or set up for this warehouse.
            $inner->where(fn ($q) => $q->whereExists(fn ($e) => $e->from('stock_balances as b')->whereColumn('b.product_id', 'p.id')->where('b.warehouse_id', $wh))
                ->orWhereExists(fn ($e) => $e->from('product_warehouse_settings as s')->whereColumn('s.product_id', 'p.id')->where('s.warehouse_id', $wh)));
        }
        if (isset($f['item_type'])) {
            $inner->where('p.item_type', $f['item_type']);
        }
        if (isset($f['category_id'])) {
            // A category includes its sub-categories (D5 has two levels).
            $ids = DB::table('product_categories')->where('company_id', $c)->where(fn ($q) => $q->where('id', $f['category_id'])->orWhere('parent_id', $f['category_id']))->pluck('id');
            $inner->whereIn('p.category_id', $ids);
        }
        $active = $f['active'] ?? 'true';
        if ($active !== 'all') {
            $inner->where('p.is_active', $active === 'true');
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $inner->where(fn ($w) => $w->where('p.name', 'like', $term)->orWhere('p.code', 'like', $term)->orWhere('p.barcode', 'like', $term));
        }

        $q = DB::query()->fromSub($inner, 'x');
        if (isset($f['stock_status'])) {
            $q->where('x.stock_status', $f['stock_status']);
        }
        $sort = $f['sort'] ?? 'name';

        return $q->orderBy('x.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('x.id')
            ->paginate((int) ($f['per_page'] ?? 25), ['x.id', 'x.on_hand', 'x.stock_value', 'x.avg_cost', 'x.stock_status'], 'page', (int) ($f['page'] ?? 1));
    }
}
