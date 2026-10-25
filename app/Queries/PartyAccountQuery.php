<?php

namespace App\Queries;

use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\Money;
use App\Models\Party;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Party accounts (B5–B8 / C5–C8; FR-023, FR-024; 06 §17; backlog P6.1-BE-05). Reads only posted lines (BR-005):
 *  - ledger(): opening balance before the range, the lines in it with a running balance, totals and closing;
 *  - transactions(): the party's documents and receipts/payments with their status;
 *  - aging() / outstanding(): open document balances (total − live allocations) bucketed by days past due on `as_of`
 *    (not due, 1–30, 31–60, 61–90, 90+), with unapplied advances apart.
 * A customer's balance is "dr" when they owe us; a supplier's is "cr" when we owe them.
 */
final class PartyAccountQuery
{
    public const BUCKETS = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct(private readonly CurrentCompany $current) {}

    /** @return array{0: string, 1: string} the range, defaulting to the current financial year's start … today */
    public static function range(?string $from, ?string $to): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $fyStart = DB::table('fiscal_years')->where('company_id', app(CurrentCompany::class)->id())->where('start_date', '<=', $today)->where('end_date', '>=', $today)->value('start_date');

        return [$from ?? (string) ($fyStart ?? $today), $to ?? $today];
    }

    /** LedgerStatement (05 schema) of the party's ledger for [from, to]. */
    public function ledger(Party $party, string $from, string $to): array
    {
        $ledger = DB::table('ledgers')->where('id', $party->ledger_id)->first(['id', 'code', 'name']);
        $opening = Money::of((string) DB::table('journal_lines')->where('ledger_id', $ledger->id)->where('is_posted', 1)->where('entry_date', '<', $from)
            ->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_id', $ledger->id)->where('l.is_posted', 1)->whereBetween('l.entry_date', [$from, $to])
            ->orderBy('l.entry_date')->orderBy('e.id')->orderBy('l.line_no')
            ->get(['l.entry_date', 'e.id as entry_id', 'e.voucher_number', 'e.entry_type', 'e.narration', 'l.debit', 'l.credit', 'l.line_narration']);
        $others = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->whereIn('l.journal_entry_id', $rows->pluck('entry_id')->unique())
            ->where('l.ledger_id', '!=', $ledger->id)->orderBy('l.line_no')->get(['l.journal_entry_id', 'g.name'])->groupBy('journal_entry_id');
        $balance = $opening;
        $lines = [];
        $monthly = [];
        foreach ($rows as $r) {
            $balance = $balance->plus($r->debit)->minus($r->credit);
            $names = ($others[$r->entry_id] ?? collect())->pluck('name')->unique()->values();
            $lines[] = ['date' => (string) $r->entry_date, 'voucher_id' => $r->entry_id, 'voucher_number' => $r->voucher_number, 'entry_type' => $r->entry_type,
                'particulars' => $names->take(2)->implode(', ').($names->count() > 2 ? ' and others' : ''), 'narration' => $r->line_narration ?? $r->narration,
                'debit' => (string) Money::of($r->debit), 'credit' => (string) Money::of($r->credit), 'balance' => (string) $balance->abs(), 'balance_side' => $balance->isNegative() ? 'cr' : 'dr'];
            $m = substr((string) $r->entry_date, 0, 7);
            $monthly[$m] ??= ['month' => $m, 'debit' => Money::zero(), 'credit' => Money::zero()];
            $monthly[$m]['debit'] = $monthly[$m]['debit']->plus($r->debit);
            $monthly[$m]['credit'] = $monthly[$m]['credit']->plus($r->credit);
        }

        return [
            'ledger' => ['id' => $ledger->id, 'code' => $ledger->code, 'name' => $ledger->name],
            'period' => ['from' => $from, 'to' => $to],
            'opening' => (string) $opening->abs(), 'opening_side' => $opening->isNegative() ? 'cr' : 'dr',
            'lines' => $lines,
            'total_debit' => (string) Money::sum($rows->pluck('debit')), 'total_credit' => (string) Money::sum($rows->pluck('credit')),
            'closing' => (string) $balance->abs(), 'closing_side' => $balance->isNegative() ? 'cr' : 'dr',
            'monthly' => array_values(array_map(fn ($m) => ['month' => $m['month'], 'debit' => (string) $m['debit'], 'credit' => (string) $m['credit']], $monthly)),
            'meta' => null,
        ];
    }

    /**
     * PartyTransaction rows (B5/C5): invoices or bills with their state, and receipts or payments, newest first.
     *
     * @return list<array>
     */
    public function transactions(Party $party, array $f): array
    {
        $customer = $party->party_type === 'customer';
        [$table, $dateCol, $docType, $path] = $customer ? ['sales_invoices', 'invoice_date', 'sales_invoice', '/sales/invoices/'] : ['purchase_bills', 'bill_date', 'purchase_bill', '/purchases/bills/'];
        $docs = DB::table($table)->where('company_id', $party->company_id)->where('party_id', $party->id)
            ->get(['id', 'number', $dateCol.' as date', 'due_date', 'total', 'status', 'payment_status', $customer ? 'customer_po_ref as reference' : 'supplier_bill_no as reference'])
            ->map(fn ($d) => ['id' => $d->id, 'type' => $docType, 'number' => $d->number, 'date' => (string) $d->date, 'reference' => $d->reference, 'due_date' => (string) $d->due_date,
                'amount' => (string) Money::of($d->total), 'status' => $d->status === 'posted' ? $d->payment_status : $d->status, 'url' => $path.$d->id]);
        $paymentType = $customer ? 'receipt' : 'payment';
        $payments = DB::table('payment_details as p')->join('journal_entries as e', 'e.id', '=', 'p.journal_entry_id')->where('p.party_id', $party->id)
            ->where('e.entry_type', $paymentType)->get(['e.id', 'e.voucher_number', 'e.entry_date', 'e.reference', 'e.status', 'p.amount', 'p.unapplied_amount'])
            ->map(fn ($p) => ['id' => $p->id, 'type' => $paymentType, 'number' => $p->voucher_number, 'date' => (string) $p->entry_date, 'reference' => $p->reference, 'due_date' => null,
                'amount' => (string) Money::of($p->amount), 'status' => $p->status === 'reversed' ? 'cancelled' : (Money::of($p->unapplied_amount)->isPositive() ? 'unapplied' : 'applied'),
                'url' => ($customer ? '/sales/payments/' : '/purchases/payments/').$p->id]);
        $all = $docs->concat($payments);
        if (isset($f['document_type'])) {
            $all = $all->where('type', $f['document_type']);
        }
        if (isset($f['date_from'])) {
            $all = $all->filter(fn ($t) => $t['date'] >= $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $all = $all->filter(fn ($t) => $t['date'] <= $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $q = mb_strtolower($f['q']);
            $all = $all->filter(fn ($t) => str_contains(mb_strtolower($t['number'].' '.($t['reference'] ?? '')), $q));
        }
        $sort = $f['sort'] ?? '-date';
        $key = ltrim($sort, '-');

        return $all->sortBy([[$key, str_starts_with($sort, '-') ? 'desc' : 'asc'], ['id', str_starts_with($sort, '-') ? 'desc' : 'asc']])->values()->all();
    }

    /**
     * Aging of one party's open documents on `as_of`, plus its unapplied advance.
     *
     * @return array{buckets: list<array{key: string, amount: string, count: int}>, advance: string}
     */
    public function aging(Party $party, string $asOf): array
    {
        return $this->agingFor([$party->id], $asOf)[$party->id] ?? $this->emptyAging();
    }

    /** OutstandingReport (B7/C7): one row per party with money open, buckets, totals; filter by bucket, q; sort. */
    public function outstanding(array $f): array
    {
        $type = $f['type'] ?? 'customer';
        $asOf = $f['as_of'] ?? CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $parties = Party::query()->where('party_type', $type)
            ->when(($f['q'] ?? '') !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($f['q'], '%_\\').'%')
                ->orWhere('code', 'like', '%'.addcslashes($f['q'], '%_\\').'%')))
            ->get(['id', 'code', 'name', 'ledger_id'])->keyBy('id');
        $aging = $this->agingFor($parties->keys()->all(), $asOf);
        $rows = [];
        $totals = array_fill_keys([...self::BUCKETS, 'total'], [Money::zero(), 0]);
        $advance = Money::zero();
        foreach ($aging as $id => $a) {
            $row = ['party' => ['id' => $id, 'code' => $parties[$id]->code, 'name' => $parties[$id]->name]];
            foreach ($a['buckets'] as $b) {
                $row[$b['key']] = $b['amount'];
            }
            $row['advance'] = $a['advance'];
            if (isset($f['bucket']) && ! Money::of($row[$f['bucket']] ?? '0')->isPositive()) {
                continue;
            }
            $rows[] = $row;
            foreach ($a['buckets'] as $b) {
                $totals[$b['key']] = [$totals[$b['key']][0]->plus($b['amount']), $totals[$b['key']][1] + $b['count']];
            }
            $advance = $advance->plus($a['advance']);
        }
        $sort = $f['sort'] ?? '-total';
        $key = ltrim($sort, '-');
        usort($rows, fn ($x, $y) => (in_array($key, ['name', 'code'], true) ? strcmp($x['party'][$key], $y['party'][$key]) : Money::of($x[$key])->compareTo($y[$key]))
            * (str_starts_with($sort, '-') ? -1 : 1));
        $per = (int) ($f['per_page'] ?? 25);
        $page = (int) ($f['page'] ?? 1);

        return [
            'as_of' => $asOf,
            'buckets' => array_map(fn ($k) => ['key' => $k, 'amount' => (string) $totals[$k][0], 'count' => $totals[$k][1]], [...self::BUCKETS, 'total']),
            'rows' => array_slice($rows, ($page - 1) * $per, $per),
            'totals' => ['parties' => count($rows), 'total' => (string) $totals['total'][0], 'advance' => (string) $advance, 'type' => $type,
                'current_page' => $page, 'per_page' => $per, 'last_page' => max(1, (int) ceil(count($rows) / max($per, 1)))],
        ];
    }

    /** @return array<int, array{buckets: list<array{key: string, amount: string, count: int}>, advance: string}> parties with something open */
    private function agingFor(array $partyIds, string $asOf): array
    {
        if ($partyIds === []) {
            return [];
        }
        $asOfDay = CarbonImmutable::parse($asOf);
        $out = [];
        foreach (['sales_invoices', 'purchase_bills'] as $table) {
            $docs = DB::table($table)->where('company_id', $this->current->id())->whereIn('party_id', $partyIds)->where('status', 'posted')->where('balance_due', '>', 0)
                ->get(['party_id', 'due_date', 'balance_due']);
            foreach ($docs as $d) {
                $days = (int) CarbonImmutable::parse($d->due_date)->diffInDays($asOfDay, false);
                $key = $days <= 0 ? 'not_due' : ($days <= 30 ? 'd1_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'd90_plus')));
                $out[$d->party_id] ??= $this->emptyAgingMoney();
                foreach ([$key, 'total'] as $k) {
                    $out[$d->party_id][$k] = [$out[$d->party_id][$k][0]->plus($d->balance_due), $out[$d->party_id][$k][1] + 1];
                }
            }
        }
        $advances = $this->advances($partyIds);
        foreach ($advances as $pid => $amount) {
            $out[$pid] ??= $this->emptyAgingMoney();
        }
        $result = [];
        foreach ($out as $pid => $a) {
            $result[$pid] = [
                'buckets' => array_map(fn ($k) => ['key' => $k, 'amount' => (string) $a[$k][0], 'count' => $a[$k][1]], [...self::BUCKETS, 'total']),
                'advance' => (string) ($advances[$pid] ?? Money::zero()),
            ];
        }

        return $result;
    }

    /** @return array<int, Money> unapplied settling lines per party (receipts/payments/notes/advances not yet allocated) */
    private function advances(array $partyIds): array
    {
        $out = [];
        $parties = DB::table('parties')->whereIn('id', $partyIds)->get(['id', 'ledger_id', 'party_type']);
        foreach ($parties as $p) {
            $side = $p->party_type === 'customer' ? 'credit' : 'debit';
            $total = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.ledger_id', $p->ledger_id)->where('l.is_posted', 1)
                ->where('e.status', 'posted')->whereIn('e.entry_type', AllocationService::SETTLING_TYPES)->where("l.{$side}", '>', 0)->sum("l.{$side}");
            $used = DB::table('bill_allocations as a')->join('journal_lines as l', 'l.id', '=', 'a.journal_line_id')->where('l.ledger_id', $p->ledger_id)->whereNull('a.reversed_at')->sum('a.amount');
            $left = Money::of((string) $total)->minus((string) $used);
            if ($left->isPositive()) {
                $out[$p->id] = $left;
            }
        }

        return $out;
    }

    private function emptyAgingMoney(): array
    {
        return array_fill_keys([...self::BUCKETS, 'total'], [Money::zero(), 0]);
    }

    private function emptyAging(): array
    {
        return ['buckets' => array_map(fn ($k) => ['key' => $k, 'amount' => '0.00', 'count' => 0], [...self::BUCKETS, 'total']), 'advance' => '0.00'];
    }
}
