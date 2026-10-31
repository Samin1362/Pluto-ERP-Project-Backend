<?php

namespace App\Http\Resources;

use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Taka;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Note (05 schemas) for a debit note (F7, F13): reason, amount and usage, the bill and return it came from, the
 * allocations made from it, and once posted its journal lines (for a return's note, the return's entry: R-04).
 */
class DebitNoteResource extends JsonResource
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
        $amount = Taka::format($n->amount);

        return [
            'id' => $n->id,
            'number' => $n->number,
            'kind' => 'debit_note',
            'status' => $n->status,
            'party' => ['id' => $n->party_id, 'code' => $n->party?->code ?? ($n->party_snapshot['code'] ?? ''), 'name' => $n->party?->name ?? ($n->party_snapshot['name'] ?? '')],
            'note_date' => $n->note_date->toDateString(),
            'reason' => $n->reason,
            'amount' => (string) $n->amount,
            'usage' => $n->usage,
            'source_document_id' => $n->purchase_bill_id,
            'source_number' => $n->bill?->number,
            'return_id' => $n->purchase_return_id,
            'return_number' => $n->purchaseReturn?->number,
            'apply_to_document_id' => $meta['apply_to_document_id'] ?? null,
            'refund' => null,
            'allocations' => $this->summary || ! $partyLine ? [] : DB::table('bill_allocations')->where('journal_line_id', $partyLine)->orderByDesc('id')->pluck('id')
                ->map(fn ($id) => AllocationService::row((int) $id))->all(),
            'posted_at' => $n->posted_at?->toIso8601String(),
            'cancel_reason' => $meta['cancel_reason'] ?? null,
            'effects' => $this->summary ? [] : [
                ['text' => "{$n->party?->name} is debited {$amount}".($n->purchase_return_id ? ' by the return\'s entry (goods back at bill cost)' : ' (Cr Purchase returns)')],
                ['text' => match ($n->usage) {
                    'apply' => 'Applied to '.(DB::table('purchase_bills')->where('id', $meta['apply_to_document_id'] ?? 0)->value('number') ?? 'the bill').', lowering what you owe',
                    'refund' => 'To be refunded by the supplier; record the money as a receipt when it arrives',
                    default => 'Kept as credit with the supplier for a later bill',
                }],
            ],
            'journal' => $this->summary || ! $n->journal_entry_id ? [] : DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')
                ->where('l.journal_entry_id', $n->journal_entry_id)->orderBy('l.line_no')->get(['l.ledger_id', 'g.code as ledger_code', 'g.name as ledger_name', 'l.debit', 'l.credit'])
                ->map(fn ($r) => ['ledger_id' => $r->ledger_id, 'ledger_code' => $r->ledger_code, 'ledger_name' => $r->ledger_name, 'debit' => $r->debit, 'credit' => $r->credit])->all(),
            'amount_in_words' => AmountInWords::of($n->amount, 'BDT'),
            'created_at' => $n->created_at?->toIso8601String(),
            'created_by' => ! $this->summary && $n->createdBy ? ['id' => $n->createdBy->id, 'name' => $n->createdBy->name] : null,
            'version' => (int) $n->lock_version,
        ];
    }
}
