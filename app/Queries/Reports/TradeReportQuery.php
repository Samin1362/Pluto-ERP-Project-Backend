<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use Illuminate\Support\Facades\DB;

/**
 * Sales and purchase reports (E11, E12, F9, F10; 06 §17; backlog P10.5-BE-01) as ReportData. Only posted documents
 * count; cancelled ones and drafts never do.
 *
 * Sales (E11): per customer, product category or month — invoices, gross (invoice lines' net, before tax), returns (the
 * returned lines' share of that net, plus credit notes not made by a return) and net = gross − returns, with each
 * group's share of the net. With `margin` (reports.financial, DEC-13) also COGS of those invoices less the cost of goods
 * restocked, and gross margin = net − COGS. Credit notes without items fall under "Credit notes (not by item)" when
 * grouping by category. No salesperson grouping (C15 / GAP-10).
 *
 * Purchases (F9): the same per supplier, category or month — bills' base net, returns at the cost they went back at,
 * debit notes not made by a return. Returns (E12, F10): one row per return with its source document, items, reason and
 * note; meta.by_reason gives each reason's value for the bars.
 */
final class TradeReportQuery
{
    private const LABEL = ['customer' => 'Customer', 'supplier' => 'Supplier', 'category' => 'Category', 'month' => 'Month'];

    /** @param  array{date_from: string, date_to: string, group_by?: string, branch_id?: ?int, party_id?: ?int}  $f */
    public function sales(int $c, array $f, bool $margin): array
    {
        $by = $f['group_by'] ?? 'customer';
        $groups = [];
        $add = function (string $key, string $label, string $field, $amount) use (&$groups) {
            $groups[$key] ??= ['key' => $key, 'label' => $label, 'invoices' => 0, 'gross' => Money::zero(), 'returns' => Money::zero(), 'cogs' => Money::zero()];
            $groups[$key][$field] = $field === 'invoices' ? $groups[$key][$field] + (int) $amount : $groups[$key][$field]->plus(Money::of((string) $amount));
        };

        $lines = DB::table('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')->leftJoin('parties as pa', 'pa.id', '=', 'i.party_id')
            ->where('i.company_id', $c)->where('i.status', 'posted')->whereBetween('i.invoice_date', [$f['date_from'], $f['date_to']]);
        $this->scope($lines, 'i', $f);
        foreach ($this->grouped($lines, $by, 'i.invoice_date', 'i.party_id', 'pa.name')->selectRaw('COUNT(DISTINCT i.id) n, SUM(l.net_amount) gross, COALESCE(SUM(l.cost_amount), 0) cogs')->get() as $r) {
            $add($r->k, $r->label, 'invoices', $r->n);
            $add($r->k, $r->label, 'gross', $r->gross);
            $add($r->k, $r->label, 'cogs', $r->cogs);
        }

        $ret = DB::table('sales_return_lines as rl')->join('sales_returns as r', 'r.id', '=', 'rl.sales_return_id')->join('sales_invoice_lines as l', 'l.id', '=', 'rl.sales_invoice_line_id')
            ->leftJoin('products as p', 'p.id', '=', 'rl.product_id')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')->leftJoin('parties as pa', 'pa.id', '=', 'r.party_id')
            ->where('r.company_id', $c)->where('r.status', 'posted')->whereBetween('r.return_date', [$f['date_from'], $f['date_to']]);
        $this->scope($ret, 'r', $f);
        foreach ($this->grouped($ret, $by, 'r.return_date', 'r.party_id', 'pa.name')
            ->selectRaw("SUM(ROUND(l.net_amount * rl.quantity / l.quantity, 2)) net, COALESCE(SUM(CASE WHEN r.stock_action = 'restock' THEN ROUND(rl.unit_cost * rl.quantity, 2) ELSE 0 END), 0) cost")->get() as $r) {
            $add($r->k, $r->label, 'returns', $r->net);
            $add($r->k, $r->label, 'cogs', Money::of((string) $r->cost)->negated());
        }

        $notes = DB::table('credit_notes as n')->leftJoin('parties as pa', 'pa.id', '=', 'n.party_id')->where('n.company_id', $c)->where('n.status', 'posted')
            ->whereNull('n.sales_return_id')->whereBetween('n.note_date', [$f['date_from'], $f['date_to']]);
        $this->scope($notes, 'n', $f);
        foreach ($this->noteGroups($notes, $by, 'n.note_date', 'n.party_id', 'pa.name')->selectRaw('SUM(n.amount) amount')->get() as $r) {
            $add($r->k, $r->label, 'returns', $r->amount);
        }

        return $this->summary('Sales report', $f, $by, $groups, 'invoices', 'Invoices', $margin,
            'Posted invoices in the period, before tax, less returns and credit notes posted in the period');
    }

    /** @param  array{date_from: string, date_to: string, group_by?: string, branch_id?: ?int, party_id?: ?int}  $f */
    public function purchases(int $c, array $f): array
    {
        $by = $f['group_by'] ?? 'supplier';
        $groups = [];
        $add = function (string $key, string $label, string $field, $amount) use (&$groups) {
            $groups[$key] ??= ['key' => $key, 'label' => $label, 'invoices' => 0, 'gross' => Money::zero(), 'returns' => Money::zero(), 'cogs' => Money::zero()];
            $groups[$key][$field] = $field === 'invoices' ? $groups[$key][$field] + (int) $amount : $groups[$key][$field]->plus(Money::of((string) $amount));
        };
        $lines = DB::table('purchase_bill_lines as l')->join('purchase_bills as i', 'i.id', '=', 'l.purchase_bill_id')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')->leftJoin('parties as pa', 'pa.id', '=', 'i.party_id')
            ->where('i.company_id', $c)->where('i.status', 'posted')->whereBetween('i.bill_date', [$f['date_from'], $f['date_to']]);
        $this->scope($lines, 'i', $f);
        foreach ($this->grouped($lines, $by, 'i.bill_date', 'i.party_id', 'pa.name')->selectRaw('COUNT(DISTINCT i.id) n, SUM(l.base_net_amount) gross')->get() as $r) {
            $add($r->k, $r->label, 'invoices', $r->n);
            $add($r->k, $r->label, 'gross', $r->gross);
        }
        $ret = DB::table('purchase_return_lines as rl')->join('purchase_returns as r', 'r.id', '=', 'rl.purchase_return_id')
            ->leftJoin('products as p', 'p.id', '=', 'rl.product_id')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')->leftJoin('parties as pa', 'pa.id', '=', 'r.party_id')
            ->where('r.company_id', $c)->where('r.status', 'posted')->whereBetween('r.return_date', [$f['date_from'], $f['date_to']]);
        $this->scope($ret, 'r', $f);
        foreach ($this->grouped($ret, $by, 'r.return_date', 'r.party_id', 'pa.name')->selectRaw('SUM(ROUND(rl.unit_cost * rl.quantity, 2)) net')->get() as $r) {
            $add($r->k, $r->label, 'returns', $r->net);
        }
        $notes = DB::table('debit_notes as n')->leftJoin('parties as pa', 'pa.id', '=', 'n.party_id')->where('n.company_id', $c)->where('n.status', 'posted')
            ->whereNull('n.purchase_return_id')->whereBetween('n.note_date', [$f['date_from'], $f['date_to']]);
        $this->scope($notes, 'n', $f);
        foreach ($this->noteGroups($notes, $by, 'n.note_date', 'n.party_id', 'pa.name')->selectRaw('SUM(n.amount) amount')->get() as $r) {
            $add($r->k, $r->label, 'returns', $r->amount);
        }

        return $this->summary('Purchase report', $f, $by, $groups, 'invoices', 'Bills', false,
            'Posted bills in the period (BDT, before tax), less returns at cost and debit notes posted in the period');
    }

    /** E12 / F10: one row per posted or cancelled return in the period. */
    public function returns(int $c, string $side, array $f): array
    {
        $sales = $side === 'sales';
        [$table, $lineTable, $fk, $doc, $docFk, $noteTable] = $sales
            ? ['sales_returns', 'sales_return_lines', 'sales_return_id', 'sales_invoices', 'sales_invoice_id', 'credit_notes']
            : ['purchase_returns', 'purchase_return_lines', 'purchase_return_id', 'purchase_bills', 'purchase_bill_id', 'debit_notes'];
        $q = DB::table("{$table} as r")->join("{$doc} as d", 'd.id', '=', "r.{$docFk}")->leftJoin('parties as pa', 'pa.id', '=', 'r.party_id')
            ->where('r.company_id', $c)->whereIn('r.status', ['posted', 'cancelled'])->whereBetween('r.return_date', [$f['date_from'], $f['date_to']]);
        $this->scope($q, 'r', $f);
        if (! empty($f['reason'])) {
            $q->whereExists(fn ($w) => $w->from("{$lineTable} as x")->whereColumn("x.{$fk}", 'r.id')->where('x.reason', $f['reason']));
        }
        $rows = [];
        $total = Money::zero();
        $byReason = [];
        foreach ($q->orderBy('r.return_date')->orderBy('r.id')->get(['r.id', 'r.number', 'r.return_date', 'r.status', 'r.total', 'r.stock_action', 'r.party_id', 'pa.name as party', 'd.number as against']) as $r) {
            $lines = DB::table($lineTable)->where($fk, $r->id)->get(['reason', 'value', 'quantity']);
            $reasons = $lines->pluck('reason')->unique()->values();
            $note = DB::table($noteTable)->where($fk, $r->id)->orderByDesc('id')->first(['number', 'status']);
            $rows[] = ['kind' => 'return', 'return_id' => $r->id, 'number' => $r->number, 'date' => $r->return_date, 'party_id' => $r->party_id, 'party' => $r->party,
                'against' => $r->against, 'items' => $lines->count(), 'reason' => $reasons->count() === 1 ? $reasons[0] : 'mixed', 'stock_action' => $r->stock_action,
                'note' => $note?->number, 'note_status' => $note?->status, 'status' => $r->status, 'value' => (string) Money::of((string) $r->total)];
            if ($r->status === 'posted') {
                $total = $total->plus(Money::of((string) $r->total));
                foreach ($lines as $l) {
                    $byReason[$l->reason] = ($byReason[$l->reason] ?? Money::zero())->plus(Money::of((string) $l->value));
                }
            }
        }
        arsort($byReason);

        return [
            'title' => $sales ? 'Sales return report' : 'Purchase return report',
            'period' => ['date_from' => $f['date_from'], 'date_to' => $f['date_to']],
            'basis' => 'Returns in the period; cancelled returns are listed but not counted',
            'columns' => [['key' => 'number', 'label' => 'Return', 'type' => 'code'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'party', 'label' => $sales ? 'Customer' : 'Supplier', 'type' => 'text'], ['key' => 'against', 'label' => 'Against', 'type' => 'code'],
                ['key' => 'items', 'label' => 'Items', 'type' => 'qty'], ['key' => 'reason', 'label' => 'Reason', 'type' => 'text'],
                ['key' => 'note', 'label' => $sales ? 'Credit note' : 'Debit note', 'type' => 'code'], ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                ['key' => 'value', 'label' => 'Value (৳)', 'type' => 'amount']],
            'rows' => $rows,
            'totals' => ['count' => count(array_filter($rows, fn ($r) => $r['status'] === 'posted')), 'value' => (string) $total],
            'checks' => [],
            'meta' => ['by_reason' => array_map(fn ($k, $v) => ['reason' => $k, 'value' => (string) $v,
                'share' => $total->isPositive() ? (string) $v->multipliedBy(100)->dividedBy($total, 1) : '0.0'], array_keys($byReason), array_values($byReason))],
        ];
    }

    private function scope($q, string $alias, array $f): void
    {
        if (! empty($f['branch_id'])) {
            $q->where("{$alias}.branch_id", $f['branch_id']);
        }
        if (! empty($f['party_id'])) {
            $q->where("{$alias}.party_id", $f['party_id']);
        }
    }

    /** Adds the group key (k) and its label for customer/supplier, category or month. */
    private function grouped($q, string $by, string $dateCol, string $partyCol, string $partyName)
    {
        return match ($by) {
            'category' => $q->selectRaw("COALESCE(CONCAT('c', pc.id), 'c0') k, COALESCE(pc.name, 'Uncategorised') label")->groupByRaw('k, label'),
            'month' => $q->selectRaw("DATE_FORMAT({$dateCol}, '%Y-%m') k, DATE_FORMAT({$dateCol}, '%b %Y') label")->groupByRaw('k, label'),
            default => $q->selectRaw("CONCAT('p', {$partyCol}) k, {$partyName} label")->groupByRaw("k, {$partyName}"),
        };
    }

    /** Notes without items: by party or month as usual; under one "not by item" row when grouping by category. */
    private function noteGroups($q, string $by, string $dateCol, string $partyCol, string $partyName)
    {
        return $by === 'category' ? $q->selectRaw("'notes' k, 'Notes (not by item)' label")->groupByRaw('k, label') : $this->grouped($q, $by, $dateCol, $partyCol, $partyName);
    }

    private function summary(string $title, array $f, string $by, array $groups, string $countKey, string $countLabel, bool $margin, string $basis): array
    {
        $net = Money::zero();
        $totals = ['invoices' => 0, 'gross' => Money::zero(), 'returns' => Money::zero(), 'net' => Money::zero(), 'cogs' => Money::zero(), 'margin' => Money::zero()];
        foreach ($groups as $g) {
            $net = $net->plus($g['gross']->minus($g['returns']));
        }
        $rows = [];
        foreach ($by === 'month' ? collect($groups)->sortKeys()->all() : collect($groups)->sortByDesc(fn ($g) => (float) (string) $g['gross']->minus($g['returns']))->all() as $g) {
            $gNet = $g['gross']->minus($g['returns']);
            $row = ['kind' => 'group', 'key' => $g['key'], 'label' => $g['label'] ?? '—', $countKey => $g['invoices'], 'gross' => (string) $g['gross'],
                'returns' => (string) $g['returns'], 'net' => (string) $gNet, 'share' => $net->isPositive() ? (string) $gNet->multipliedBy(100)->dividedBy($net, 1) : '0.0'];
            if ($margin) {
                $row['cogs'] = (string) $g['cogs'];
                $row['margin'] = (string) $gNet->minus($g['cogs']);
                $totals['cogs'] = $totals['cogs']->plus($g['cogs']);
            }
            $rows[] = $row;
            $totals['invoices'] += $g['invoices'];
            $totals['gross'] = $totals['gross']->plus($g['gross']);
            $totals['returns'] = $totals['returns']->plus($g['returns']);
        }
        $totals['net'] = $totals['gross']->minus($totals['returns']);
        $totals['margin'] = $totals['net']->minus($totals['cogs']);
        $out = ['count' => $totals['invoices'], 'gross' => (string) $totals['gross'], 'returns' => (string) $totals['returns'], 'net' => (string) $totals['net']];
        $columns = [['key' => 'label', 'label' => self::LABEL[$by], 'type' => 'text'], ['key' => $countKey, 'label' => $countLabel, 'type' => 'qty'],
            ['key' => 'gross', 'label' => 'Gross (৳)', 'type' => 'amount'], ['key' => 'returns', 'label' => 'Returns (৳)', 'type' => 'amount'],
            ['key' => 'net', 'label' => 'Net (৳)', 'type' => 'amount'], ['key' => 'share', 'label' => 'Share', 'type' => 'pct']];
        if ($margin) {
            $columns[] = ['key' => 'cogs', 'label' => 'Cost of sales (৳)', 'type' => 'amount'];
            $columns[] = ['key' => 'margin', 'label' => 'Gross margin (৳)', 'type' => 'amount'];
            $out += ['cogs' => (string) $totals['cogs'], 'margin' => (string) $totals['margin'],
                'margin_pct' => $totals['net']->isPositive() ? (string) $totals['margin']->multipliedBy(100)->dividedBy($totals['net'], 1) : '0.0'];
        }

        return [
            'title' => $title,
            'period' => ['date_from' => $f['date_from'], 'date_to' => $f['date_to']],
            'basis' => $basis,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $out,
            'checks' => [['key' => 'net', 'ok' => $totals['net']->isEqualTo($totals['gross']->minus($totals['returns'])), 'message' => 'Net = gross − returns']],
            'meta' => ['group_by' => $by, 'margin_shown' => $margin],
        ];
    }
}
