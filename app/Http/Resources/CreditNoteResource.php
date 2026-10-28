<?php

namespace App\Http\Resources;

use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Taka;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Note (05 schemas) for a credit note (E9, E15): reason, amount and usage with what it applies to or how it is
 * refunded, the invoice and return it came from, the allocations made from it, and once posted its journal lines and
 * what posting did (effects).
 */
class CreditNoteResource extends JsonResource
{
    public function __construct($resource, private readonly bool $summary = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $n = $this->resource;
        $meta = $n->meta();
        $partyLine = $n->journal_entry_id ? DB::table('journal_lines')->where('journal_entry_id', $n->journal_entry_id)->where('ledger_id', $n->party?->ledger_id)->value('id') : null;

        return [
            'id' => $n->id,
            'number' => $n->number,
            'kind' => 'credit_note',
            'status' => $n->status,
            'party' => ['id' => $n->party_id, 'code' => $n->party?->code ?? ($n->party_snapshot['code'] ?? ''), 'name' => $n->party?->name ?? ($n->party_snapshot['name'] ?? '')],
            'note_date' => $n->note_date->toDateString(),
            'reason' => $n->reason,
            'amount' => (string) $n->amount,
            'usage' => $n->usage,
            'source_document_id' => $n->sales_invoice_id,
            'source_number' => $n->invoice?->number,
            'return_id' => $n->sales_return_id,
            'return_number' => $n->salesReturn?->number,
            'apply_to_document_id' => $meta['apply_to_document_id'] ?? null,
            'refund' => $meta['refund'] ?? null,
            'allocations' => $this->summary || ! $partyLine ? [] : DB::table('bill_allocations')->where('journal_line_id', $partyLine)->orderByDesc('id')->pluck('id')
                ->map(fn ($id) => AllocationService::row((int) $id))->all(),
            'posted_at' => $n->posted_at?->toIso8601String(),
            'cancel_reason' => $meta['cancel_reason'] ?? null,
            'effects' => $this->summary ? [] : $this->effects($n, $meta),
            'journal' => $this->summary ? [] : $this->journal($n->journal_entry_id),
            'amount_in_words' => AmountInWords::of($n->amount, 'BDT'),
            'created_at' => $n->created_at?->toIso8601String(),
            'created_by' => ! $this->summary && $n->createdBy ? ['id' => $n->createdBy->id, 'name' => $n->createdBy->name] : null,
            'version' => (int) $n->lock_version,
        ];
    }

    /** What posting does (draft) or did (posted), in plain words for the E9 aside. */
    private function effects($n, array $meta): array
    {
        $amount = Taka::format($n->amount);
        $out = [['text' => "{$n->party?->name} is credited {$amount} (Dr Sales returns, Cr customer)"]];
        $out[] = match ($n->usage) {
            'apply' => ['text' => 'Applied to '.(DB::table('sales_invoices')->where('id', $meta['apply_to_document_id'] ?? 0)->value('number') ?? 'the invoice').', lowering what is still due'],
            'refund' => ['text' => "Refunded {$amount} from ".(DB::table('money_accounts')->where('id', $meta['refund']['money_account_id'] ?? 0)->value('name') ?? 'the money account')],
            default => ['text' => 'Kept as customer credit for a later invoice'],
        };

        return $out;
    }

    /** @return list<array{ledger_id: int, ledger_code: string, ledger_name: string, debit: string, credit: string}> */
    private function journal(?int $entryId): array
    {
        if (! $entryId) {
            return [];
        }

        return DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $entryId)->orderBy('l.line_no')
            ->get(['l.ledger_id', 'g.code as ledger_code', 'g.name as ledger_name', 'l.debit', 'l.credit'])
            ->map(fn ($r) => ['ledger_id' => $r->ledger_id, 'ledger_code' => $r->ledger_code, 'ledger_name' => $r->ledger_name, 'debit' => $r->debit, 'credit' => $r->credit])->all();
    }
}
