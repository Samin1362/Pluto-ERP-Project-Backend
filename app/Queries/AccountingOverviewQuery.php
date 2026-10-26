<?php

namespace App\Queries;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * H1 accounting overview (05 getAccountingOverview → ReportData; backlog P7.1-BE-03; 06 §16 health checks; BR-005):
 *  - totals: the four tiles (cash and bank, receivables, payables, profit so far this financial year) and the health
 *    figures (suspense, opening balance adjustment, drafts, pending approvals, unmatched statement lines, last locked
 *    period);
 *  - checks: trial balance agrees, suspense empty, opening balances balanced, no drafts, no pending approvals, no
 *    unmatched bank lines, a period locked;
 *  - rows: the ten most recent vouchers; meta.groups: the primary groups' balances for the group bars.
 * Amounts are absolute decimal strings with a dr|cr side.
 */
final class AccountingOverviewQuery
{
    public function __construct(private readonly AccountTreeQuery $tree) {}

    public function overview(int $c): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $fy = DB::table('fiscal_years')->where('company_id', $c)->where('start_date', '<=', $today)->where('end_date', '>=', $today)->first();
        [$groups, $net] = $this->tree->balances($c, $today);
        $mapping = DB::table('account_mappings')->where('company_id', $c)->get(['mapping_key', 'ledger_id', 'account_group_id'])->keyBy('mapping_key');
        $groupNet = fn (string $key) => $net[$mapping[$key]->account_group_id ?? 0] ?? Money::zero();
        $ledgerNet = fn (string $key) => Money::of((string) DB::table('journal_lines')->where('company_id', $c)->where('ledger_id', $mapping[$key]->ledger_id ?? 0)
            ->where('is_posted', 1)->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));

        $profit = Money::zero();
        if ($fy) {
            $profit = Money::of((string) DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->join('account_groups as a', 'a.id', '=', 'g.account_group_id')
                ->where('l.company_id', $c)->where('l.is_posted', 1)->whereBetween('l.entry_date', [$fy->start_date, $fy->end_date])->whereIn('a.nature', ['income', 'expense'])
                ->selectRaw('CAST(COALESCE(SUM(l.credit) - SUM(l.debit), 0) AS DECIMAL(18,2)) p')->value('p'));
        }
        $tb = DB::table('journal_lines')->where('company_id', $c)->where('is_posted', 1)
            ->selectRaw('CAST(COALESCE(SUM(debit), 0) AS DECIMAL(18,2)) dr, CAST(COALESCE(SUM(credit), 0) AS DECIMAL(18,2)) cr')->first();
        $suspense = $ledgerNet('suspense');
        $adjustment = $ledgerNet('opening_balance_adjustment');
        $drafts = DB::table('journal_entries')->where('company_id', $c)->where('status', 'draft')->count();
        $pending = DB::table('approval_requests')->where('company_id', $c)->where('status', 'pending')->count();
        $unmatched = DB::table('bank_statement_lines')->where('company_id', $c)->where('status', 'unmatched')->count();
        $locked = DB::table('accounting_periods')->where('company_id', $c)->where('status', 'locked')->orderByDesc('end_date')->first(['name', 'end_date']);

        $amount = fn (Money $m) => ['amount' => (string) $m->abs(), 'side' => $m->isNegative() ? 'cr' : 'dr'];
        $totals = [
            'cash_and_bank' => $amount($groupNet('cash_bank_group')), 'receivables' => $amount($groupNet('sundry_debtors_group')),
            'payables' => $amount($groupNet('sundry_creditors_group')), 'profit' => (string) $profit,
            'suspense' => $amount($suspense), 'opening_balance_adjustment' => $amount($adjustment),
            'drafts' => $drafts, 'pending_approvals' => $pending, 'unmatched_statement_lines' => $unmatched,
            'last_locked_period' => $locked ? ['name' => $locked->name, 'end_date' => (string) $locked->end_date] : null,
        ];
        $tbOk = Money::of((string) $tb->dr)->isEqualTo((string) $tb->cr);
        $checks = [
            ['key' => 'trial_balance', 'ok' => $tbOk, 'message' => $tbOk ? 'Trial balance agrees' : "Trial balance differs: debits {$tb->dr}, credits {$tb->cr}"],
            ['key' => 'suspense', 'ok' => $suspense->isZero(), 'message' => $suspense->isZero() ? 'Suspense is empty' : "Suspense holds {$suspense->abs()}; post it to the right ledgers"],
            ['key' => 'opening_balances', 'ok' => $adjustment->isZero(), 'message' => $adjustment->isZero() ? 'Opening balances agree'
                : "Opening balance adjustment shows {$adjustment->abs()} ".($adjustment->isNegative() ? 'Cr' : 'Dr').'; some opening balances are still missing'],
            ['key' => 'drafts', 'ok' => $drafts === 0, 'message' => $drafts === 0 ? 'No draft vouchers' : "{$drafts} draft ".($drafts === 1 ? 'voucher' : 'vouchers').' not posted'],
            ['key' => 'pending_approvals', 'ok' => $pending === 0, 'message' => $pending === 0 ? 'Nothing waiting for approval' : "{$pending} waiting for approval"],
            ['key' => 'unmatched_statement_lines', 'ok' => $unmatched === 0, 'message' => $unmatched === 0 ? 'Bank statements are matched' : "{$unmatched} bank statement lines to match"],
            ['key' => 'last_locked_period', 'ok' => $locked !== null, 'message' => $locked ? "Books locked up to {$locked->name}" : 'No period is locked yet'],
        ];

        $rows = DB::table('journal_entries')->where('company_id', $c)->orderByDesc('entry_date')->orderByDesc('id')->limit(10)
            ->get(['id', 'entry_date', 'voucher_number', 'entry_type', 'narration', 'total_debit', 'status'])
            ->map(fn ($e) => ['id' => $e->id, 'date' => (string) $e->entry_date, 'voucher_number' => $e->voucher_number, 'entry_type' => $e->entry_type,
                'narration' => $e->narration, 'amount' => (string) Money::of((string) $e->total_debit), 'status' => $e->status])->all();
        $primaries = array_values(array_map(fn ($g) => ['id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'nature' => $g->nature] + $amount($net[$g->id]),
            array_filter($groups, fn ($g) => $g->parent_id === null)));

        return [
            'title' => 'Accounting overview',
            'period' => ['from' => $fy ? (string) $fy->start_date : $today, 'to' => $today],
            'basis' => null,
            'columns' => [['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'voucher_number', 'label' => 'Voucher', 'type' => 'code'],
                ['key' => 'entry_type', 'label' => 'Type', 'type' => 'text'], ['key' => 'narration', 'label' => 'Narration', 'type' => 'text'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'amount'], ['key' => 'status', 'label' => 'Status', 'type' => 'text']],
            'rows' => $rows,
            'totals' => $totals,
            'checks' => $checks,
            'meta' => ['groups' => $primaries],
        ];
    }
}
