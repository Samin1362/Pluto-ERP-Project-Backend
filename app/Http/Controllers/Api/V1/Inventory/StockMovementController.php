<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Access\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockMovementListRequest;
use App\Models\StockMovement;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;

/**
 * GET /stock-movements (D9, backlog P4.3-BE-03): the stock ledger, newest first, with each movement's source document
 * number for "Open source document". unit_cost and value are omitted without inventory.view_costs (BR-052, R-24).
 * Search matches the product (code or name) or the source document number.
 */
class StockMovementController extends Controller
{
    /** Source morph alias → its table's number column (documents as they arrive: bills now, invoices in P5 …). */
    private const SOURCES = ['purchase_bills' => 'number', 'sales_invoices' => 'number'];

    public function __construct(private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    public function index(StockMovementListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = StockMovement::query()->with(['product', 'warehouse'])->select('stock_movements.*');
        $number = 'NULL';
        foreach (self::SOURCES as $alias => $col) {
            $q->leftJoin("{$alias} as src_{$alias}", fn ($j) => $j->on("src_{$alias}.id", '=', 'stock_movements.source_id')->where('stock_movements.source_type', '=', $alias));
            $number = "COALESCE(src_{$alias}.{$col}, {$number})";
        }
        $q->selectRaw("{$number} as source_number");
        foreach (['movement_type', 'warehouse_id', 'product_id'] as $col) {
            if (isset($f[$col])) {
                $q->where("stock_movements.{$col}", $f[$col]);
            }
        }
        if (isset($f['date_from'])) {
            $q->whereDate('stock_movements.moved_on', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('stock_movements.moved_on', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->join('products as p', 'p.id', '=', 'stock_movements.product_id')
                ->where(fn ($w) => $w->where('p.code', 'like', $term)->orWhere('p.name', 'like', $term)->orWhereRaw("{$number} LIKE ?", [$term]));
        }
        $desc = ($f['sort'] ?? '-moved_on') === '-moved_on';
        $page = $q->orderBy('stock_movements.moved_on', $desc ? 'desc' : 'asc')->orderBy('stock_movements.id', $desc ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
        $costs = $this->permissions->allows($this->current->membership(), 'inventory.view_costs');

        return response()->json([
            'data' => $page->getCollection()->map(fn (StockMovement $m) => $this->row($m, $costs))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    private function row(StockMovement $m, bool $costs): array
    {
        $in = $m->quantity_in->isPositive();
        $row = [
            'id' => $m->id, 'moved_on' => $m->moved_on->toDateString(), 'movement_type' => $m->movement_type,
            'product' => ['id' => $m->product_id, 'name' => $m->product?->name ?? ''], 'warehouse' => ['id' => $m->warehouse_id, 'name' => $m->warehouse?->name ?? ''],
            'quantity_in' => (string) $m->quantity_in, 'quantity_out' => (string) $m->quantity_out, 'qty_balance_after' => (string) $m->qty_balance_after,
        ];
        if ($costs) {
            $row['unit_cost'] = (string) $m->unit_cost;
            $row['value'] = (string) ($in ? $m->value_in : $m->value_out); // the movement's own value; direction is in the quantities
        }

        return $row + ['source_type' => $m->source_type, 'source_id' => $m->source_id, 'source_number' => (string) ($m->source_number ?? '')];
    }
}
