<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use App\Queries\LedgerEntriesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * General ledger (H19; 06 §17; backlog P9.1-BE-01; BR-005, TC-ACC-006): one block per ledger with its opening balance,
 * the posted lines in [from, to] with a running balance, and the closing balance (LedgerEntriesQuery does the maths).
 * `ledger_ids` picks the ledgers (default: every ledger with a posted line in the range or a balance before it);
 * `group_by=month` replaces the lines with one row per month.
 *
 * Row: kind (heading|opening|entry|month|closing), ledger_id, code, name, depth, date, voucher_id, voucher_number,
 * entry_type, particulars, narration, debit, credit, balance, balance_side.
 */
final class GeneralLedgerQuery
{
    public function __construct(private readonly LedgerEntriesQuery $entries) {}

    /** @param list<int>|null $ledgerIds */
    public function report(int $companyId, string $from, string $to, ?array $ledgerIds = null, string $groupBy = 'ledger'): array
    {
        $ids = $ledgerIds ?? DB::table('journal_lines')->where('company_id', $companyId)->where('is_posted', 1)->where('entry_date', '<=', $to)
            ->distinct()->pluck('ledger_id')->map(fn ($id) => (int) $id)->all();
        $ledgers = DB::table('ledgers')->where('company_id', $companyId)->whereIn('id', $ids)->orderBy('code')->get(['id', 'code', 'name']);
        $rows = [];
        [$dr, $cr] = [Money::zero(), Money::zero()];
        foreach ($ledgers as $l) {
            $s = $this->entries->statement($l->id, $from, $to);
            if ($ledgerIds === null && $s['lines'] === [] && Money::of($s['opening'])->isZero()) {
                continue;
            }
            $base = ['ledger_id' => $l->id, 'code' => $l->code];
            $rows[] = [...$base, 'kind' => 'heading', 'name' => $l->name, 'particulars' => "{$l->code} · {$l->name}", 'depth' => 0];
            $rows[] = [...$base, 'kind' => 'opening', 'depth' => 1, 'date' => $from, 'particulars' => 'Opening balance', 'balance' => $s['opening'], 'balance_side' => $s['opening_side']];
            if ($groupBy === 'month') {
                foreach ($s['monthly'] as $m) {
                    $rows[] = [...$base, 'kind' => 'month', 'depth' => 1, 'date' => $m['month'].'-01', 'particulars' => CarbonImmutable::parse($m['month'].'-01')->format('F Y'),
                        'debit' => $m['debit'], 'credit' => $m['credit'], 'balance' => $m['closing'], 'balance_side' => $m['closing_side']];
                }
            } else {
                foreach ($s['lines'] as $line) {
                    $rows[] = [...$base, 'kind' => 'entry', 'depth' => 1, ...$line];
                }
            }
            $rows[] = [...$base, 'kind' => 'closing', 'depth' => 1, 'date' => $to, 'particulars' => 'Closing balance', 'debit' => $s['total_debit'], 'credit' => $s['total_credit'],
                'balance' => $s['closing'], 'balance_side' => $s['closing_side']];
            $dr = $dr->plus($s['total_debit']);
            $cr = $cr->plus($s['total_credit']);
        }

        return [
            'title' => 'General ledger',
            'period' => ['from' => $from, 'to' => $to],
            'basis' => 'Posted vouchers only',
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'voucher_number', 'label' => 'Voucher', 'type' => 'code'],
                ['key' => 'particulars', 'label' => 'Particulars', 'type' => 'text'], ['key' => 'debit', 'label' => 'Debit (৳)', 'type' => 'amount'],
                ['key' => 'credit', 'label' => 'Credit (৳)', 'type' => 'amount'], ['key' => 'balance', 'label' => 'Balance (৳)', 'type' => 'amount'],
                ['key' => 'balance_side', 'label' => 'Dr/Cr', 'type' => 'code'],
            ],
            'rows' => $rows,
            'totals' => ['debit' => (string) $dr, 'credit' => (string) $cr],
            'checks' => [],
            'meta' => ['ledgers' => count(array_filter($rows, fn ($r) => $r['kind'] === 'heading')), 'group_by' => $groupBy],
        ];
    }
}
