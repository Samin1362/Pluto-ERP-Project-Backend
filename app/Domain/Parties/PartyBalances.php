<?php

namespace App\Domain\Parties;

use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Party balances read from posted journal lines of the party's ledger (the ledger is the single source of truth).
 * Amounts are computed in SQL DECIMAL and returned as decimal strings; PHP never converts money to float.
 */
final class PartyBalances
{
    public function __construct(private readonly CurrentCompany $current) {}

    /**
     * @param  list<int>  $ledgerIds
     * @return array<int, array{net: string, has_entries: bool}> net = debit - credit
     */
    public function forLedgers(array $ledgerIds): array
    {
        if (! $ledgerIds) {
            return [];
        }
        $rows = DB::table('journal_lines')
            ->where('company_id', $this->current->id())->where('is_posted', true)->whereIn('ledger_id', $ledgerIds)
            ->groupBy('ledger_id')
            ->selectRaw('ledger_id, CAST(SUM(debit) - SUM(credit) AS DECIMAL(18,2)) AS net, COUNT(*) AS n')->get();
        $out = [];
        foreach ($ledgerIds as $id) {
            $out[$id] = ['net' => '0.00', 'has_entries' => false];
        }
        foreach ($rows as $r) {
            $out[$r->ledger_id] = ['net' => (string) $r->net, 'has_entries' => $r->n > 0];
        }

        return $out;
    }
}
