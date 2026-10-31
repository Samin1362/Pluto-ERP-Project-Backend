<?php

namespace App\Domain\Banking;

use App\Domain\Accounting\VoucherService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\CompanyUser;
use App\Models\MoneyAccount;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bank reconciliation (G10; BR-032, BR-065; TC-BNK-004, TC-BNK-005; backlog P12.1-BE-05).
 *
 * A reconciliation takes the account's imported statement lines up to the statement date that no finished
 * reconciliation has taken, and each is handled: matched to a posted book line of the same signed amount, created
 * (the missing receipt, payment or bank charge is posted as a voucher and matched), paired with offsetting lines
 * (sum zero), or ignored. Book side: the account ledger's posted balance at the statement date, less the book lines not
 * yet seen on the statement ("uncleared": dated after the last finished reconciliation — or, before the first one, less
 * than a month before the account's first statement line — not an opening balance, and matched to no line).
 * Difference = statement balance − (book − uncleared): it falls to zero as lines are matched and missing entries made.
 * Suggestions: an uncleared book line of the same amount within ±3 days, a matching reference first, each book line
 * suggested once. Finish (BR-032) only when every line is handled (lines_unhandled) and the difference is zero
 * (difference_not_zero).
 */
final class ReconciliationService
{
    private const WINDOW_DAYS = 3;

    public function __construct(private readonly VoucherService $vouchers, private readonly AuditLogger $audit) {}

    /** Start, or resume the account's open one with the new statement date and balance. */
    public function start(array $in): BankReconciliation
    {
        return DB::transaction(function () use ($in) {
            $account = MoneyAccount::whereKey($in['money_account_id'])->lockForUpdate()->firstOrFail();
            if ($account->account_type === 'cash') {
                throw new ApiProblem('validation_failed', errors: ['money_account_id' => ["{$account->name} is a cash account; count it instead"]]);
            }
            $last = BankReconciliation::where('money_account_id', $account->id)->where('status', 'finished')->max('statement_date');
            if ($last && $in['statement_date'] <= CarbonImmutable::parse($last)->toDateString()) {
                throw new ApiProblem('validation_failed', errors: ['statement_date' => ['The account is reconciled up to '.CarbonImmutable::parse($last)->format('j M Y').'; choose a later date']]);
            }
            $rec = BankReconciliation::where('money_account_id', $account->id)->where('status', 'in_progress')->first()
                ?? new BankReconciliation(['money_account_id' => $account->id, 'status' => 'in_progress', 'book_balance' => '0', 'difference' => '0']);
            $rec->fill(['statement_date' => $in['statement_date'], 'statement_balance' => $in['statement_balance']])->save();
            // Lines past the new date go back to the pool; lines up to it (not taken by a finished one) join.
            BankStatementLine::where('bank_reconciliation_id', $rec->id)->where('txn_date', '>', $in['statement_date'])->where('status', 'unmatched')
                ->update(['bank_reconciliation_id' => null]);
            BankStatementLine::where('money_account_id', $account->id)->whereNull('bank_reconciliation_id')->where('txn_date', '<=', $in['statement_date'])
                ->update(['bank_reconciliation_id' => $rec->id]);
            $this->refresh($rec);
            $this->audit->record('created', $rec, null, ['account' => $account->name, 'statement_date' => $in['statement_date'], 'statement_balance' => (string) $rec->statement_balance]);

            return $rec;
        });
    }

    public function match(BankReconciliation $rec, int $lineId, int $journalLineId): BankReconciliation
    {
        return $this->change($rec, function ($rec) use ($lineId, $journalLineId) {
            $line = $this->line($rec, $lineId, 'unmatched');
            if (BankStatementLine::where('matched_journal_line_id', $journalLineId)->exists()) { // TC-BNK-005
                throw new ApiProblem('duplicate', 'That book entry is already matched to another statement line.', errors: ['journal_line_id' => ['Already matched to another statement line']]);
            }
            $book = $this->uncleared($rec)->firstWhere('journal_line_id', $journalLineId);
            if (! $book) {
                throw new ApiProblem('validation_failed', errors: ['journal_line_id' => ['Choose a posted entry of this account that is not matched yet']]);
            }
            if (! Money::of($book['amount'])->isEqualTo($line->amount)) {
                throw new ApiProblem('validation_failed', errors: ['journal_line_id' => ["The amounts differ: the statement shows {$line->amount}, the book {$book['amount']}"]]);
            }
            $line->forceFill(['status' => 'matched', 'matched_journal_line_id' => $journalLineId, 'matched_by' => auth()->id(), 'matched_at' => now()])->save();
        });
    }

    /** The missing voucher (receipt for money in; payment or bank charge for money out), posted on the line's date, and matched. */
    public function createEntry(BankReconciliation $rec, int $lineId, array $in, CompanyUser $membership): BankReconciliation
    {
        return $this->change($rec, function ($rec) use ($lineId, $in, $membership) {
            $line = $this->line($rec, $lineId, 'unmatched');
            $incoming = $line->amount->isPositive();
            if ($incoming !== ($in['kind'] === 'receipt')) {
                throw new ApiProblem('validation_failed', errors: ['kind' => [$incoming ? 'Money came in: record a receipt' : 'Money went out: record a payment or a bank charge']]);
            }
            $account = MoneyAccount::findOrFail($rec->money_account_id);
            [$entry, $approval] = $this->vouchers->create(['entry_type' => $incoming ? 'receipt' : 'payment', 'action' => 'post', 'date' => $line->txn_date->toDateString(),
                'confirm_backdated' => true, 'reference' => $line->bank_reference,
                'narration' => $in['narration'] ?? ($in['kind'] === 'bank_charge' ? "Bank charge: {$line->description}" : $line->description),
                'payment' => array_filter(['money_account_id' => $account->id, 'method' => 'bank_transfer', 'party_id' => $in['party_id'] ?? null,
                    'transaction_reference' => $line->bank_reference]),
                'lines' => [['ledger_id' => $in['ledger_id'], 'amount' => (string) $line->amount->abs()]]], $membership);
            if ($approval || $entry->status !== 'posted') {
                throw new ApiProblem('validation_failed', "{$entry->voucher_number} needs approval before it posts; approve it, then match it to this line.",
                    errors: ['kind' => ['Above your approval limit: the voucher waits for approval']]);
            }
            $bookLine = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->where('ledger_id', $account->ledger_id)->value('id');
            $line->forceFill(['status' => 'created', 'matched_journal_line_id' => $bookLine, 'matched_by' => auth()->id(), 'matched_at' => now()])->save();
        });
    }

    /** Two or more lines summing to zero become a pair; a single line is ignored (no book effect). */
    public function pair(BankReconciliation $rec, array $lineIds): BankReconciliation
    {
        return $this->change($rec, function ($rec) use ($lineIds) {
            $lines = collect($lineIds)->map(fn ($id) => $this->line($rec, (int) $id, 'unmatched'));
            if ($lines->count() === 1) {
                $lines->first()->forceFill(['status' => 'ignored', 'matched_by' => auth()->id(), 'matched_at' => now()])->save();

                return;
            }
            $sum = Money::sum($lines->map(fn ($l) => $l->amount));
            if (! $sum->isZero()) {
                throw new ApiProblem('validation_failed', errors: ['line_ids' => ["Paired lines must cancel out; these add up to {$sum}"]]);
            }
            $first = $lines->first()->id;
            foreach ($lines as $l) {
                $l->forceFill(['status' => 'paired', 'paired_line_id' => $first, 'matched_by' => auth()->id(), 'matched_at' => now()])->save();
            }
        });
    }

    /** Back to unmatched (a pair as a whole). A created voucher stays posted; it simply shows as an uncleared book line again. */
    public function undo(BankReconciliation $rec, int $lineId): BankReconciliation
    {
        return $this->change($rec, function ($rec) use ($lineId) {
            $line = $this->line($rec, $lineId);
            $group = $line->status === 'paired'
                ? BankStatementLine::where('bank_reconciliation_id', $rec->id)->where('paired_line_id', $line->paired_line_id)->get() : collect([$line]);
            foreach ($group as $l) {
                $l->forceFill(['status' => 'unmatched', 'matched_journal_line_id' => null, 'paired_line_id' => null, 'matched_by' => null, 'matched_at' => null])->save();
            }
        });
    }

    public function acceptSuggestions(BankReconciliation $rec): BankReconciliation
    {
        return $this->change($rec, function ($rec) {
            foreach ($this->suggestions($rec) as $lineId => $s) {
                BankStatementLine::whereKey($lineId)->update(['status' => 'matched', 'matched_journal_line_id' => $s['journal_line_id'], 'matched_by' => auth()->id(), 'matched_at' => now()]);
            }
        });
    }

    public function finish(BankReconciliation $rec): BankReconciliation
    {
        return $this->change($rec, function ($rec) {
            $open = BankStatementLine::where('bank_reconciliation_id', $rec->id)->where('status', 'unmatched')->count();
            if ($open > 0) {
                throw new ApiProblem('lines_unhandled', "{$open} statement line".($open === 1 ? ' is' : 's are').' not handled yet (BR-032).', extra: ['unhandled' => $open]);
            }
            $this->refresh($rec);
            if (! $rec->difference->isZero()) {
                throw new ApiProblem('difference_not_zero', "The difference is {$rec->difference}; it must be zero to finish (BR-032).", extra: ['difference' => (string) $rec->difference]);
            }
            $rec->forceFill(['status' => 'finished', 'finished_at' => now(), 'finished_by' => auth()->id()])->save();
            MoneyAccount::whereKey($rec->money_account_id)->update(['last_reconciled_on' => $rec->statement_date->toDateString()]);
            $imports = BankStatementLine::where('bank_reconciliation_id', $rec->id)->distinct()->pluck('bank_statement_import_id');
            foreach ($imports as $importId) {
                if (! BankStatementLine::where('bank_statement_import_id', $importId)->where(fn ($q) => $q->where('status', 'unmatched')->orWhereNull('bank_reconciliation_id'))->exists()) {
                    BankStatementImport::whereKey($importId)->update(['status' => 'reconciled']);
                }
            }
            $this->audit->record('finished', $rec, ['status' => 'in_progress'], ['status' => 'finished', 'statement_balance' => (string) $rec->statement_balance]);
        }, open: false);
    }

    /**
     * Uncleared book lines: posted lines on the account's ledger in (cutoff, statement date] not matched to any statement line.
     *
     * @return Collection<int, array{journal_line_id: int, date: string, voucher_number: string, particulars: string, reference: ?string, amount: string}>
     */
    public function uncleared(BankReconciliation $rec): Collection
    {
        $account = MoneyAccount::findOrFail($rec->money_account_id);

        return DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('payment_details as pd', 'pd.journal_entry_id', '=', 'je.id')->leftJoin('parties as p', 'p.id', '=', 'pd.party_id')
            ->where('jl.ledger_id', $account->ledger_id)->where('jl.is_posted', 1)->where('je.entry_type', '!=', 'opening_balance') // the statement starts from it
            ->where('jl.entry_date', '>', $this->cutoff($rec))->where('jl.entry_date', '<=', $rec->statement_date->toDateString())
            ->whereNotIn('jl.id', BankStatementLine::where('money_account_id', $account->id)->whereNotNull('matched_journal_line_id')->select('matched_journal_line_id'))
            ->orderBy('jl.entry_date')->orderBy('jl.id')
            ->get(['jl.id', 'jl.entry_date', 'je.voucher_number', 'je.reference', 'pd.transaction_reference', 'pd.cheque_number', 'jl.debit', 'jl.credit',
                DB::raw("COALESCE(p.name, NULLIF(pd.payee_text, ''), NULLIF(je.narration, ''), je.voucher_number) as particulars")])
            ->map(fn ($r) => ['journal_line_id' => (int) $r->id, 'date' => (string) $r->entry_date, 'voucher_number' => $r->voucher_number, 'particulars' => $r->particulars,
                'reference' => $r->transaction_reference ?: ($r->cheque_number ?: $r->reference),
                'amount' => (string) Money::of((string) $r->debit)->minus((string) $r->credit)]);
    }

    /** @return array<int, array> statement line id → the suggested book line */
    public function suggestions(BankReconciliation $rec): array
    {
        $book = $this->uncleared($rec)->all();
        $taken = [];
        $out = [];
        foreach (BankStatementLine::where('bank_reconciliation_id', $rec->id)->where('status', 'unmatched')->orderBy('txn_date')->orderBy('id')->get() as $l) {
            $best = null;
            foreach ($book as $b) {
                if (isset($taken[$b['journal_line_id']]) || ! Money::of($b['amount'])->isEqualTo($l->amount)) {
                    continue;
                }
                $days = abs(CarbonImmutable::parse($b['date'])->diffInDays($l->txn_date, false));
                if ($days > self::WINDOW_DAYS) {
                    continue;
                }
                $score = ($l->bank_reference && $b['reference'] && strcasecmp($l->bank_reference, $b['reference']) === 0 ? 0 : 10) + $days;
                if (! $best || $score < $best[0]) {
                    $best = [$score, $b];
                }
            }
            if ($best) {
                $taken[$best[1]['journal_line_id']] = true;
                $out[$l->id] = $best[1];
            }
        }

        return $out;
    }

    /** Book balance at the statement date and the difference, saved on the reconciliation. */
    public function refresh(BankReconciliation $rec): void
    {
        $account = MoneyAccount::findOrFail($rec->money_account_id);
        $book = Money::of((string) DB::table('journal_lines')->where('ledger_id', $account->ledger_id)->where('is_posted', 1)
            ->where('entry_date', '<=', $rec->statement_date->toDateString())->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) b')->value('b'));
        $uncleared = Money::sum($this->uncleared($rec)->map(fn ($b) => Money::of($b['amount'])));
        $rec->forceFill(['book_balance' => $book, 'difference' => Money::of((string) $rec->statement_balance)->minus($book->minus($uncleared))])->save();
    }

    private function cutoff(BankReconciliation $rec): string
    {
        $last = BankReconciliation::where('money_account_id', $rec->money_account_id)->where('status', 'finished')->where('id', '!=', $rec->id)->max('statement_date');
        $first = BankStatementLine::where('money_account_id', $rec->money_account_id)->min('txn_date');
        // No finished reconciliation yet: what is older than a month before the first statement line was cleared long ago.
        $before = $first ? CarbonImmutable::parse($first)->subDays(31)->toDateString() : $rec->statement_date->toDateString();

        return max($last ? CarbonImmutable::parse($last)->toDateString() : '0000-00-00', $before);
    }

    private function line(BankReconciliation $rec, int $id, ?string $status = null): BankStatementLine
    {
        $line = BankStatementLine::where('bank_reconciliation_id', $rec->id)->whereKey($id)->lockForUpdate()->first()
            ?? throw new ApiProblem('not_found', 'That statement line is not part of this reconciliation.');
        if ($status && $line->status !== $status) {
            throw new ApiProblem('invalid_transition', "That line is already {$line->status}; undo it first.", extra: ['status' => $line->status]);
        }

        return $line;
    }

    private function change(BankReconciliation $rec, callable $fn, bool $open = true): BankReconciliation
    {
        return DB::transaction(function () use ($rec, $fn, $open) {
            $rec = BankReconciliation::whereKey($rec->id)->lockForUpdate()->firstOrFail();
            if ($rec->status !== 'in_progress') {
                throw new ApiProblem('invalid_transition', 'This reconciliation is finished.', extra: ['status' => $rec->status]);
            }
            $fn($rec);
            if ($open) {
                $this->refresh($rec); // saves, which bumps the version
            }

            return $rec->refresh();
        }, attempts: 2);
    }
}
