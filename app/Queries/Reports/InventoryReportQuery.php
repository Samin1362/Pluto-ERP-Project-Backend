<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Inventory reports as ReportData (D12–D14; 06 §11.1, §17; BR-052; TC-INV-007; backlog P11.1-BE-04).
 *
 * Valuation (D13, inventory.view_costs): quantity and value per item — or per stock group — as of a date, rebuilt from
 * stock movements (Σ in − Σ out, value likewise), so a past date shows what the books held then. Average = value ÷
 * quantity. The check compares the total with the inventory ledgers' balance on that date; stock sent between two
 * warehouses sharing a ledger and not yet received is still in that ledger, so it is added to the stock side.
 *
 * Low stock (D12): every product at or below its reorder level (> 0) in a warehouse, out = nothing on hand; suggested
 * quantity = reorder quantity, else what brings it back to the level (as "create PO" does); cost = last purchase cost,
 * else the warehouse average. Costs and order values only with inventory.view_costs.
 *
 * Summary (D14): totals, value by stock group, units in and out per month (6 months), fastest movers of the last 30
 * days with days of cover, and what needs attention. Values only with inventory.view_costs.
 */
final class InventoryReportQuery
{
    /** @param  array{as_of: string, warehouse_id?: ?int, level?: string}  $f */
    public function valuation(int $c, array $f): array
    {
        $asOf = $f['as_of'];
        $groups = ($f['level'] ?? 'items') === 'groups';
        $q = DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->leftJoin('stock_groups as g', 'g.id', '=', 'p.stock_group_id')
            ->where('m.company_id', $c)->whereDate('m.moved_on', '<=', $asOf);
        if (! empty($f['warehouse_id'])) {
            $q->where('m.warehouse_id', $f['warehouse_id']);
        }
        $q->selectRaw('SUM(m.quantity_in) - SUM(m.quantity_out) as qty, SUM(m.value_in) - SUM(m.value_out) as value');
        if ($groups) {
            $q->groupBy('g.id', 'g.name')->selectRaw("g.id as group_id, COALESCE(g.name, 'No stock group') as name")->orderByRaw('g.name IS NULL, g.name');
        } else {
            $q->groupBy('p.id', 'p.code', 'p.name', 'g.name')->selectRaw("p.id as product_id, p.code, p.name, COALESCE(g.name, 'No stock group') as stock_group")->orderBy('p.name');
        }
        $rows = [];
        $total = Money::zero();
        $units = Quantity::zero();
        foreach ($q->get() as $r) {
            $qty = Quantity::of((string) $r->qty);
            $value = Money::of((string) $r->value);
            if ($qty->isZero() && $value->isZero()) {
                continue;
            }
            $avg = $qty->isPositive() ? (string) $value->dividedBy($qty, 4) : '0.0000';
            $rows[] = $groups
                ? ['kind' => 'group', 'group_id' => $r->group_id, 'name' => $r->name, 'quantity' => (string) $qty, 'value' => (string) $value]
                : ['kind' => 'item', 'product_id' => $r->product_id, 'code' => $r->code, 'name' => $r->name, 'stock_group' => $r->stock_group, 'quantity' => (string) $qty,
                    'avg_cost' => $avg, 'value' => (string) $value];
            $total = $total->plus($value);
            $units = $units->plus($qty);
        }
        $checks = [];
        if (empty($f['warehouse_id'])) {
            $ledger = Money::of((string) DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->join('ledgers as l', 'l.id', '=', 'jl.ledger_id')->where('je.company_id', $c)->where('l.ledger_kind', 'inventory')
                ->where('jl.is_posted', true)->whereDate('jl.entry_date', '<=', $asOf)->selectRaw('COALESCE(SUM(jl.debit) - SUM(jl.credit), 0) as b')->value('b'));
            $transit = $this->sameLedgerInTransit($c, $asOf);
            $diff = $ledger->minus($total->plus($transit));
            $checks[] = ['key' => 'ledger_agrees', 'ok' => $diff->isZero(), 'message' => $diff->isZero()
                ? 'Agrees with the inventory ledgers (৳ '.$ledger.')'.($transit->isPositive() ? ", including ৳ {$transit} on its way between warehouses that share a ledger" : '')
                : "Differs from the inventory ledgers (৳ {$ledger}) by ৳ {$diff}"];
        }
        $columns = $groups
            ? [['key' => 'name', 'label' => 'Stock group', 'type' => 'text'], ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'qty'], ['key' => 'value', 'label' => 'Value (৳)', 'type' => 'amount']]
            : [['key' => 'code', 'label' => 'Code', 'type' => 'code'], ['key' => 'name', 'label' => 'Item', 'type' => 'text'], ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'qty'],
                ['key' => 'avg_cost', 'label' => 'Avg cost (৳)', 'type' => 'amount'], ['key' => 'value', 'label' => 'Value (৳)', 'type' => 'amount']];

        return [
            'title' => 'Stock valuation', 'period' => ['as_of' => $asOf], 'basis' => 'Moving weighted average per warehouse, from posted stock movements',
            'columns' => $columns, 'rows' => $rows, 'totals' => ['quantity' => (string) $units, 'value' => (string) $total, 'items' => count($rows)], 'checks' => $checks,
            'meta' => ['level' => $groups ? 'groups' : 'items', 'warehouse_id' => $f['warehouse_id'] ?? null],
        ];
    }

    /** @param  array{warehouse_id?: ?int, status?: string, group_by_supplier?: bool}  $f */
    public function lowStock(int $c, array $f, bool $costs): array
    {
        $q = DB::table('product_warehouse_settings as s')->join('products as p', 'p.id', '=', 's.product_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 's.product_id')->on('b.warehouse_id', '=', 's.warehouse_id'))
            ->leftJoin('parties as sup', 'sup.id', '=', 'p.preferred_supplier_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('s.company_id', $c)->where('s.reorder_level', '>', 0)->where('p.track_stock', true)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->where('w.status', '!=', 'inactive')->whereRaw('COALESCE(b.qty_on_hand, 0) <= s.reorder_level')
            ->selectRaw("p.id as product_id, p.code, p.name, u.name as unit, w.id as warehouse_id, w.name as warehouse, CAST(COALESCE(b.qty_on_hand, 0) AS DECIMAL(18,3)) as on_hand,
                s.reorder_level, s.reorder_qty, sup.id as supplier_id, sup.name as supplier,
                CAST(CASE WHEN s.reorder_qty > 0 THEN s.reorder_qty WHEN s.reorder_level - COALESCE(b.qty_on_hand, 0) > 0 THEN s.reorder_level - COALESCE(b.qty_on_hand, 0) ELSE 1 END AS DECIMAL(18,3)) as suggested,
                CAST(COALESCE(p.last_purchase_cost, b.avg_unit_cost, 0) AS DECIMAL(18,4)) as cost,
                CASE WHEN COALESCE(b.qty_on_hand, 0) <= 0 THEN 'out_of_stock' ELSE 'low_stock' END as status");
        if (! empty($f['warehouse_id'])) {
            $q->where('s.warehouse_id', $f['warehouse_id']);
        }
        if (($f['status'] ?? 'all') === 'out') {
            $q->whereRaw('COALESCE(b.qty_on_hand, 0) <= 0');
        } elseif (($f['status'] ?? 'all') === 'low') {
            $q->whereRaw('COALESCE(b.qty_on_hand, 0) > 0');
        }
        $bySupplier = (bool) ($f['group_by_supplier'] ?? false);
        if ($bySupplier) {
            $q->orderByRaw('sup.name IS NULL, sup.name');
        }
        $items = $q->orderByRaw('CASE WHEN COALESCE(b.qty_on_hand, 0) <= 0 THEN 0 ELSE 1 END')->orderBy('p.name')->orderBy('w.name')->get();

        $rows = [];
        $value = Money::zero();
        $out = 0;
        $lastSupplier = false;
        foreach ($items as $r) {
            if ($bySupplier && $r->supplier_id !== $lastSupplier) {
                $rows[] = ['kind' => 'group', 'supplier_id' => $r->supplier_id, 'name' => $r->supplier ?? 'No preferred supplier'];
                $lastSupplier = $r->supplier_id;
            }
            $orderValue = Money::of(Quantity::of((string) $r->suggested)->toBigDecimal()->multipliedBy((string) $r->cost)->toScale(2, RoundingMode::HalfUp));
            $row = ['kind' => 'item', 'product_id' => $r->product_id, 'code' => $r->code, 'name' => $r->name, 'unit' => $r->unit, 'warehouse_id' => $r->warehouse_id,
                'warehouse' => $r->warehouse, 'on_hand' => (string) $r->on_hand, 'reorder_level' => (string) Quantity::of((string) $r->reorder_level), 'status' => $r->status,
                'supplier_id' => $r->supplier_id, 'supplier' => $r->supplier, 'suggested' => (string) $r->suggested];
            if ($costs) {
                $row['cost'] = (string) $r->cost;
                $row['order_value'] = (string) $orderValue;
            }
            $rows[] = $row;
            $value = $value->plus($orderValue);
            $out += $r->status === 'out_of_stock' ? 1 : 0;
        }
        $columns = [['key' => 'name', 'label' => 'Product', 'type' => 'text'], ['key' => 'warehouse', 'label' => 'Warehouse', 'type' => 'text'],
            ['key' => 'on_hand', 'label' => 'On hand', 'type' => 'qty'], ['key' => 'reorder_level', 'label' => 'Reorder at', 'type' => 'qty'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'], ['key' => 'supplier', 'label' => 'Preferred supplier', 'type' => 'text'],
            ['key' => 'suggested', 'label' => 'Suggested', 'type' => 'qty']];
        $totals = ['items' => $items->count(), 'out_of_stock' => $out, 'low_stock' => $items->count() - $out,
            'without_supplier' => $items->whereNull('supplier_id')->count()];
        if ($costs) {
            $columns[] = ['key' => 'order_value', 'label' => 'Order value (৳)', 'type' => 'amount'];
            $totals['order_value'] = (string) $value;
        }

        return [
            'title' => 'Low stock', 'period' => ['as_of' => CarbonImmutable::now('Asia/Dhaka')->toDateString()],
            'basis' => 'Products at or below their reorder level; suggested = reorder quantity, else back to the level',
            'columns' => $columns, 'rows' => $rows, 'totals' => $totals,
            'meta' => ['warehouse_id' => $f['warehouse_id'] ?? null, 'status' => $f['status'] ?? 'all', 'group_by_supplier' => $bySupplier],
        ];
    }

    public function summary(int $c, bool $costs): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka');
        $since = $today->subDays(29)->toDateString();
        $stock = DB::table('stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')->where('b.company_id', $c)
            ->selectRaw('COUNT(DISTINCT CASE WHEN b.qty_on_hand > 0 THEN b.product_id END) as in_stock, COALESCE(SUM(b.total_value), 0) as value')->first();
        $low = DB::table('product_warehouse_settings as s')->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 's.product_id')->on('b.warehouse_id', '=', 's.warehouse_id'))
            ->where('s.company_id', $c)->where('s.reorder_level', '>', 0)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->whereRaw('COALESCE(b.qty_on_hand, 0) <= s.reorder_level')
            ->selectRaw('SUM(COALESCE(b.qty_on_hand, 0) <= 0) as out_n, SUM(COALESCE(b.qty_on_hand, 0) > 0) as low_n')->first();
        $tracked = DB::table('products')->where('company_id', $c)->where('track_stock', true)->where('is_active', true)->whereNull('deleted_at')->count();

        $byGroup = DB::table('stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')->leftJoin('stock_groups as g', 'g.id', '=', 'p.stock_group_id')
            ->where('b.company_id', $c)->groupBy('g.id', 'g.name')
            ->selectRaw("COALESCE(g.name, 'No stock group') as name, SUM(b.qty_on_hand) as qty, SUM(b.total_value) as value")->orderByDesc('value')->get();
        // share: the group's part of the total (by value with costs, else by units), 1 decimal, for the bars
        $whole = $byGroup->reduce(fn ($s, $g) => $s->plus((string) ($costs ? $g->value : $g->qty)), Money::zero());
        $byGroup = $byGroup->map(fn ($g) => array_merge(['name' => $g->name, 'quantity' => (string) Quantity::of((string) $g->qty)],
            $costs ? ['value' => (string) Money::of((string) $g->value)] : [],
            ['share' => $whole->isPositive() ? (string) Money::of((string) ($costs ? $g->value : $g->qty))->multipliedBy(100)->dividedBy($whole, 1) : '0.0']))->all();

        $from = $today->startOfMonth()->subMonths(5)->toDateString();
        // Transfers only move stock between warehouses, so they are left out of the company's in/out flow.
        $flow = DB::table('stock_movements')->where('company_id', $c)->whereDate('moved_on', '>=', $from)
            ->whereNotIn('movement_type', ['transfer_out', 'transfer_in'])->groupByRaw("DATE_FORMAT(moved_on, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(moved_on, '%Y-%m') as month, SUM(quantity_in) as qty_in, SUM(quantity_out) as qty_out, SUM(value_in) as value_in, SUM(value_out) as value_out")
            ->get()->keyBy('month');
        $monthly = [];
        for ($m = $today->startOfMonth()->subMonths(5); $m <= $today; $m = $m->addMonth()) {
            $r = $flow[$m->format('Y-m')] ?? null;
            $monthly[] = array_merge(['month' => $m->format('Y-m'), 'units_in' => (string) Quantity::of((string) ($r->qty_in ?? '0')),
                'units_out' => (string) Quantity::of((string) ($r->qty_out ?? '0'))],
                $costs ? ['value_in' => (string) Money::of((string) ($r->value_in ?? '0')), 'value_out' => (string) Money::of((string) ($r->value_out ?? '0'))] : []);
        }

        $sold = DB::table('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('i.company_id', $c)->where('i.status', 'posted')->where('p.track_stock', true)->whereDate('i.invoice_date', '>=', $since)
            ->groupBy('p.id', 'p.code', 'p.name')->selectRaw('p.id, p.code, p.name, SUM(l.quantity) as sold, SUM(l.net_amount) as revenue')
            ->orderByDesc('sold')->limit(10)->get();
        $onHand = DB::table('stock_balances')->whereIn('product_id', $sold->pluck('id'))->groupBy('product_id')->selectRaw('product_id, SUM(qty_on_hand) as q')->pluck('q', 'product_id');
        $rows = $sold->map(function ($r) use ($onHand) {
            $have = Quantity::of((string) ($onHand[$r->id] ?? '0'));
            $perDay = Quantity::of((string) $r->sold)->toBigDecimal()->dividedBy(30, 6, RoundingMode::HalfUp);

            return ['kind' => 'item', 'product_id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'sold' => (string) Quantity::of((string) $r->sold),
                'on_hand' => (string) $have, 'days_of_cover' => $perDay->isZero() ? null : (int) $have->toBigDecimal()->dividedBy($perDay, 0, RoundingMode::Down)->toInt(),
                'revenue' => (string) Money::of((string) $r->revenue)];
        })->all();

        $attention = DB::table('product_warehouse_settings as s')->join('products as p', 'p.id', '=', 's.product_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 's.product_id')->on('b.warehouse_id', '=', 's.warehouse_id'))
            ->where('s.company_id', $c)->where('s.reorder_level', '>', 0)->where('p.is_active', true)->whereNull('p.deleted_at')
            ->whereRaw('COALESCE(b.qty_on_hand, 0) <= s.reorder_level')
            ->orderByRaw('COALESCE(b.qty_on_hand, 0) <= 0 DESC')->orderBy('p.name')->limit(10)
            ->get(['p.id', 'p.code', 'p.name', 'w.id as warehouse_id', 'w.name as warehouse', DB::raw('CAST(COALESCE(b.qty_on_hand, 0) AS DECIMAL(18,3)) as on_hand'), 's.reorder_level'])
            ->map(fn ($r) => ['product_id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'warehouse_id' => $r->warehouse_id, 'warehouse' => $r->warehouse,
                'on_hand' => (string) $r->on_hand, 'reorder_level' => (string) Quantity::of((string) $r->reorder_level),
                'status' => Quantity::of((string) $r->on_hand)->isPositive() ? 'low_stock' : 'out_of_stock'])->all();

        $totals = ['products' => $tracked, 'in_stock' => (int) $stock->in_stock, 'low_stock' => (int) ($low->low_n ?? 0), 'out_of_stock' => (int) ($low->out_n ?? 0)];
        if ($costs) {
            $totals['stock_value'] = (string) Money::of((string) $stock->value);
        }

        return [
            'title' => 'Inventory summary', 'period' => ['date_from' => $since, 'date_to' => $today->toDateString()], 'basis' => 'Fastest movers: posted invoices of the last 30 days',
            'columns' => [['key' => 'name', 'label' => 'Product', 'type' => 'text'], ['key' => 'sold', 'label' => 'Sold', 'type' => 'qty'],
                ['key' => 'on_hand', 'label' => 'On hand', 'type' => 'qty'], ['key' => 'days_of_cover', 'label' => 'Days of cover', 'type' => 'qty'],
                ['key' => 'revenue', 'label' => 'Revenue (৳)', 'type' => 'amount']],
            'rows' => $rows, 'totals' => $totals,
            'meta' => ['by_group' => $byGroup, 'monthly' => $monthly, 'attention' => $attention],
        ];
    }

    /** Value dispatched by the given date between two warehouses with the same ledger and neither received nor cancelled by then. */
    private function sameLedgerInTransit(int $c, string $asOf): Money
    {
        return Money::of((string) DB::table('stock_movements as m')->join('stock_transfers as t', fn ($j) => $j->on('t.id', '=', 'm.source_id')->where('m.source_type', '=', 'stock_transfers'))
            ->join('warehouses as wf', 'wf.id', '=', 't.from_warehouse_id')->join('warehouses as wt', 'wt.id', '=', 't.to_warehouse_id')
            ->where('m.company_id', $c)->where('m.movement_type', 'transfer_out')->whereDate('m.moved_on', '<=', $asOf)->whereColumn('wf.inventory_ledger_id', 'wt.inventory_ledger_id')
            ->where(fn ($w) => $w->whereNull('t.received_date')->orWhereDate('t.received_date', '>', $asOf))
            ->whereNotExists(fn ($e) => $e->from('stock_movements as r')->whereColumn('r.source_id', 't.id')->where('r.source_type', 'stock_transfers')
                ->where('r.movement_type', 'reversal')->whereDate('r.moved_on', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(m.value_out), 0) as v')->value('v'));
    }
}
