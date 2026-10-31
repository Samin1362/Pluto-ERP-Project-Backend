<?php

namespace App\Http\Resources;

use App\Domain\Banking\ReconciliationService;
use App\Domain\Shared\Money;
use App\Models\BankStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation (05 schemas; G10): the totals, the statement lines (with the suggestion for an unmatched line, and the
 * book line a matched or created one points at) and, for the right pane, the book entries not yet on the statement.
 * `filter`: all, unmatched, handled.
 */
class ReconciliationResource extends JsonResource
{
    public function __construct($resource, private readonly string $filter = 'all')
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $service = app(ReconciliationService::class);
        $all = BankStatementLine::where('bank_reconciliation_id', $r->id)->orderBy('txn_date')->orderBy('id')->get();
        $suggest = $r->status === 'in_progress' ? $service->suggestions($r) : [];
        $matched = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('jl.id', $all->pluck('matched_journal_line_id')->filter())->get(['jl.id', 'jl.entry_date', 'je.id as voucher_id', 'je.voucher_number'])->keyBy('id');
        $lines = $all->filter(fn ($l) => match ($this->filter) {
            'unmatched' => $l->status === 'unmatched', 'handled' => $l->status !== 'unmatched', default => true
        });
        $book = fn ($l) => $matched[$l->matched_journal_line_id] ?? null;

        return [
            'id' => $r->id, 'money_account_id' => $r->money_account_id, 'money_account_name' => $r->account?->name,
            'statement_date' => $r->statement_date->toDateString(), 'statement_balance' => (string) $r->statement_balance,
            'book_balance' => (string) $r->book_balance, 'difference' => (string) $r->difference,
            'matched_count' => $all->where('status', '!=', 'unmatched')->count(), 'line_count' => $all->count(),
            'unhandled_count' => $all->where('status', 'unmatched')->count(), 'status' => $r->status,
            'lines' => $lines->values()->map(fn ($l) => [
                'id' => $l->id, 'txn_date' => $l->txn_date->toDateString(), 'description' => $l->description, 'bank_reference' => $l->bank_reference,
                'amount' => (string) Money::of((string) $l->amount), 'status' => $l->status, 'suggestion' => $suggest[$l->id] ?? null,
                'matched' => $book($l) ? ['journal_line_id' => (int) $l->matched_journal_line_id, 'voucher_id' => (int) $book($l)->voucher_id,
                    'voucher_number' => $book($l)->voucher_number, 'date' => (string) $book($l)->entry_date]
                    : ($l->paired_line_id ? ['paired_with' => (int) $l->paired_line_id] : null),
            ])->all(),
            'book_entries' => $r->status === 'in_progress' ? $service->uncleared($r)->values()->all() : [],
            'version' => (int) $r->lock_version,
        ];
    }
}
