<?php

namespace App\Domain\Accounting;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * The books-are-consistent checks (09 §8, NFR-042; backlog P4.1-BE-07/08) for one company, used by the test trait
 * after every posting feature test and by `pluto:integrity` nightly. Each check returns human-readable failures;
 * none means consistent. Plain queries with an explicit company_id, so the command can run for any company.
 *
 * Documents link their entry through `journal_entry_id`; the entry's source_type is the document's morph alias,
 * which by convention is its table name (purchase_bills, sales_invoices, …; registered as models arrive).
 * `balance_due = total − live allocations` joins this list with the allocations of P6.
 */
final class BooksIntegrity
{
    /** Tables whose rows are posted through journal_entry_id (documents, not lines or movements). */
    public const DOCUMENT_TABLES = ['purchase_bills', 'purchase_returns', 'debit_notes', 'sales_invoices', 'sales_returns', 'credit_notes',
        'stock_adjustments', 'lc_charges', 'lc_payments', 'landed_cost_allocations'];

    /** @return array<string, list<string>> check name => failures */
    public function check(int $companyId): array
    {
        return [
            'posted lines balance' => $this->postedLinesBalance($companyId),
            'entries balance and match their lines' => $this->entriesBalance($companyId),
            'documents link back from their entry' => $this->documentLinks($companyId),
            'stock quantity = movements' => $this->stockAgainstMovements($companyId, 'qty'),
            'stock value = movements' => $this->stockAgainstMovements($companyId, 'value'),
            'inventory ledgers = stock valuation' => $this->inventoryLedgers($companyId),
        ];
    }

    /** @return list<string> every failure, prefixed with its check */
    public function failures(int $companyId): array
    {
        $out = [];
        foreach ($this->check($companyId) as $name => $failures) {
            foreach ($failures as $f) {
                $out[] = "{$name}: {$f}";
            }
        }

        return $out;
    }

    private function postedLinesBalance(int $c): array
    {
        $t = DB::table('journal_lines')->where('company_id', $c)->where('is_posted', 1)->selectRaw('COALESCE(SUM(debit), 0) d, COALESCE(SUM(credit), 0) c')->first();

        return Money::of($t->d)->isEqualTo($t->c) ? [] : ["Σ debit {$t->d} ≠ Σ credit {$t->c}"];
    }

    private function entriesBalance(int $c): array
    {
        $rows = DB::table('journal_entries as e')->where('e.company_id', $c)->whereIn('e.status', ['posted', 'reversed'])
            ->leftJoin('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->groupBy('e.id', 'e.voucher_number', 'e.total_debit', 'e.total_credit')
            ->selectRaw('e.id, e.voucher_number, e.total_debit, e.total_credit, COALESCE(SUM(l.debit), 0) d, COALESCE(SUM(l.credit), 0) c, SUM(l.is_posted = 0) unposted')
            ->havingRaw('d <> c OR d <> e.total_debit OR c <> e.total_credit OR e.total_debit <> e.total_credit OR unposted > 0 OR COUNT(l.id) = 0')->get();

        return $rows->map(fn ($r) => "{$r->voucher_number}: header {$r->total_debit}/{$r->total_credit}, lines {$r->d}/{$r->c}".($r->unposted ? ", {$r->unposted} line(s) not marked posted" : ''))->all();
    }

    private function documentLinks(int $c): array
    {
        $out = [];
        foreach (self::DOCUMENT_TABLES as $table) {
            $bad = DB::table("{$table} as d")->where('d.company_id', $c)->whereNotNull('d.journal_entry_id')
                ->leftJoin('journal_entries as e', 'e.id', '=', 'd.journal_entry_id')
                ->where(fn ($q) => $q->whereNull('e.id')->orWhere('e.source_type', '!=', $table)->orWhereColumn('e.source_id', '!=', 'd.id')->orWhereColumn('e.company_id', '!=', 'd.company_id'))
                ->pluck('d.id');
            foreach ($bad as $id) {
                $out[] = "{$table} #{$id}: its journal entry is missing or does not point back to it";
            }
        }

        return $out;
    }

    private function stockAgainstMovements(int $c, string $what): array
    {
        $rows = DB::select('SELECT b.product_id, b.warehouse_id, b.qty_on_hand, b.total_value,
                COALESCE(m.q, 0) AS q, COALESCE(m.v, 0) AS v
            FROM stock_balances b
            LEFT JOIN (SELECT product_id, warehouse_id, SUM(quantity_in - quantity_out) q, SUM(value_in - value_out) v
                       FROM stock_movements WHERE company_id = ? GROUP BY product_id, warehouse_id) m
              ON m.product_id = b.product_id AND m.warehouse_id = b.warehouse_id
            WHERE b.company_id = ?', [$c, $c]);
        $orphans = DB::select('SELECT m.product_id, m.warehouse_id FROM stock_movements m
            LEFT JOIN stock_balances b ON b.product_id = m.product_id AND b.warehouse_id = m.warehouse_id
            WHERE m.company_id = ? AND b.id IS NULL GROUP BY m.product_id, m.warehouse_id', [$c]);
        $out = array_map(fn ($o) => "product {$o->product_id} in warehouse {$o->warehouse_id}: movements without a balance row", $orphans);
        foreach ($rows as $r) {
            if ($what === 'qty' && ! Quantity::of($r->qty_on_hand)->isEqualTo($r->q)) {
                $out[] = "product {$r->product_id} in warehouse {$r->warehouse_id}: balance {$r->qty_on_hand}, movements {$r->q}";
            }
            if ($what === 'value' && ! Money::of($r->total_value)->isEqualTo($r->v)) {
                $out[] = "product {$r->product_id} in warehouse {$r->warehouse_id}: value {$r->total_value}, movements {$r->v}";
            }
        }

        return $out;
    }

    /** Each inventory ledger's posted balance = the stock value of the warehouses that post to it (06 §11.1). */
    private function inventoryLedgers(int $c): array
    {
        $rows = DB::select("SELECT l.id, l.code,
                (SELECT COALESCE(SUM(jl.debit - jl.credit), 0) FROM journal_lines jl WHERE jl.ledger_id = l.id AND jl.is_posted = 1) AS ledger_balance,
                (SELECT COALESCE(SUM(b.total_value), 0) FROM stock_balances b JOIN warehouses w ON w.id = b.warehouse_id WHERE w.inventory_ledger_id = l.id) AS stock_value
            FROM ledgers l WHERE l.company_id = ? AND l.ledger_kind = 'inventory'", [$c]);

        return array_values(array_map(fn ($r) => "{$r->code}: ledger {$r->ledger_balance}, stock {$r->stock_value}",
            array_filter($rows, fn ($r) => ! Money::of($r->ledger_balance)->isEqualTo($r->stock_value))));
    }
}
