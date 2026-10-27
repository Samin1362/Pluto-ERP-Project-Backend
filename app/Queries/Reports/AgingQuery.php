<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use App\Queries\PartyAccountQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Receivables and payables aging (J6/J7; 06 §17; backlog P9.1-BE-04): the P6 outstanding logic (open document balances
 * by days past due on `as_of`: not due, 1–30, 31–60, 61–90, 90+) as ReportData, one row per customer or supplier with
 * the oldest days past due; unapplied advances apart. `overdue_only` keeps parties with something past due. Check: the
 * open documents less the advances agree with the party ledgers' balance (opening balances entered on a party ledger
 * are not documents, so they show as a difference).
 *
 * Row: kind (party), party_id, code, name, not_due, d1_30, d31_60, d61_90, d90_plus, total, advance, oldest_days.
 */
final class AgingQuery
{
    public function __construct(private readonly PartyAccountQuery $parties) {}

    public function report(int $companyId, string $type, string $asOf, ?string $q = null, bool $overdueOnly = false): array
    {
        $o = $this->parties->outstanding(['type' => $type, 'as_of' => $asOf, 'q' => $q ?? '', 'per_page' => PHP_INT_MAX, 'sort' => 'name']);
        $oldest = $this->oldestDue($companyId, $type === 'customer' ? 'sales_invoices' : 'purchase_bills');
        $keys = [...PartyAccountQuery::BUCKETS, 'total'];
        $rows = [];
        $totals = array_fill_keys([...$keys, 'advance'], Money::zero());
        $overdueTotal = Money::zero();
        foreach ($o['rows'] as $r) {
            $overdue = Money::of($r['total'])->minus($r['not_due']);
            if ($overdueOnly && ! $overdue->isPositive()) {
                continue;
            }
            $due = $oldest[$r['party']['id']] ?? null;
            $days = $due ? max(0, (int) CarbonImmutable::parse($due)->diffInDays(CarbonImmutable::parse($asOf), false)) : null;
            $row = ['kind' => 'party', 'party_id' => $r['party']['id'], 'code' => $r['party']['code'], 'name' => $r['party']['name']];
            foreach ([...$keys, 'advance'] as $k) {
                $row[$k] = (string) Money::of($r[$k] ?? '0');
                $totals[$k] = $totals[$k]->plus($row[$k]);
            }
            $row['oldest_days'] = $days;
            $rows[] = $row;
            $overdueTotal = $overdueTotal->plus($overdue);
        }
        $ledger = $this->ledgerBalance($companyId, $type);
        $open = $overdueOnly ? null : $totals['total']->minus($totals['advance']);
        $party = $type === 'customer' ? 'Customer' : 'Supplier';
        $columns = [['key' => 'name', 'label' => $party, 'type' => 'text'], ['key' => 'not_due', 'label' => 'Not due', 'type' => 'amount'],
            ['key' => 'd1_30', 'label' => '1–30', 'type' => 'amount'], ['key' => 'd31_60', 'label' => '31–60', 'type' => 'amount'],
            ['key' => 'd61_90', 'label' => '61–90', 'type' => 'amount'], ['key' => 'd90_plus', 'label' => '90+', 'type' => 'amount'],
            ['key' => 'total', 'label' => 'Total (৳)', 'type' => 'amount'], ['key' => 'advance', 'label' => 'Advance (৳)', 'type' => 'amount'],
            ['key' => 'oldest_days', 'label' => 'Oldest (days)', 'type' => 'qty']];

        return [
            'title' => $type === 'customer' ? 'Receivables aging' : 'Payables aging',
            'period' => ['as_of' => $asOf],
            'basis' => 'Open posted documents, by days past due',
            'columns' => $columns,
            'rows' => $rows,
            'totals' => [...array_map(fn ($m) => (string) $m, $totals), 'overdue' => (string) $overdueTotal, 'parties' => count($rows)],
            'checks' => $open === null ? [] : [[
                'key' => 'party_balances', 'ok' => $open->isEqualTo($ledger),
                'message' => $open->isEqualTo($ledger) ? "Agrees with the {$type} ledgers (৳ {$ledger})"
                    : "Differs from the {$type} ledgers (৳ {$ledger}) by ৳ ".$open->minus($ledger)->abs().': opening balances or items not on a document',
            ]],
            'meta' => ['type' => $type, 'overdue_only' => $overdueOnly, 'buckets' => PartyAccountQuery::BUCKETS],
        ];
    }

    /** @return array<int, string> party id => earliest due date among its open documents */
    private function oldestDue(int $companyId, string $table): array
    {
        return DB::table($table)->where('company_id', $companyId)->where('status', 'posted')->where('balance_due', '>', 0)
            ->groupBy('party_id')->selectRaw('party_id, MIN(due_date) d')->pluck('d', 'party_id')->map(fn ($d) => (string) $d)->all();
    }

    /** Σ posted balance of the party ledgers of this type: what customers owe (Dr) or what we owe suppliers (Cr). */
    private function ledgerBalance(int $companyId, string $type): Money
    {
        $net = Money::of((string) DB::table('journal_lines as l')->join('parties as p', 'p.ledger_id', '=', 'l.ledger_id')
            ->where('l.company_id', $companyId)->where('p.company_id', $companyId)->where('p.party_type', $type)->where('l.is_posted', 1)
            ->selectRaw('CAST(COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS DECIMAL(18,2)) b')->value('b'));

        return $type === 'customer' ? $net : $net->negated();
    }
}
