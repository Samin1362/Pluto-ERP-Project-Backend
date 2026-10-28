<?php

namespace App\Domain\Purchases;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Models\DebitNote;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\PurchaseBill;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Debit notes not made by a return (F7, F13; 06 §10; BR-021, BR-023, BR-057; R-03, R-04; TC-PUR-012; backlog
 * P10.4-BE-02): a price difference, short supply or quality claim against a supplier. Posting debits the supplier and
 * credits purchase_returns_non_stock (stock is not revalued here), then by usage applies it to a posted bill of the
 * supplier (≤ its balance, BR-023), leaves it as credit, or leaves it to be refunded (the money is recorded as a
 * receipt when it arrives). A return's note is created posted with the return and changes only through the return
 * (409 created_from_return), so the supplier is never debited twice.
 */
final class DebitNoteService
{
    public const REASONS = ['goods_returned', 'short_supply', 'price_difference', 'quality_claim'];

    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AllocationService $allocations,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $in): DebitNote
    {
        return DB::transaction(function () use ($in) {
            if (! empty($in['return_id'])) {
                throw new ApiProblem('validation_failed', errors: ['return_id' => ['A return gets its debit note when it is posted']]);
            }
            $party = $this->check($in);
            $period = $this->periods->assertOpen($in['note_date']);
            $note = new DebitNote(['status' => 'draft']);
            $note->number = $this->numbers->next('debit_note', $period->fiscal_year_id)['number'];
            $this->fill($note, $party, $in);
            $note->save();
            $this->audit->record('created', $note, null, $this->audited($note) + ['number' => $note->number]);

            return $note->refresh();
        });
    }

    public function update(DebitNote $note, array $in, int $expectedVersion): DebitNote
    {
        return DB::transaction(function () use ($note, $in, $expectedVersion) {
            $note = $this->lockOwn($note);
            $this->assertDraft($note);
            if ((int) $note->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $note->lock_version]);
            }
            $party = $this->check($in);
            $this->periods->assertOpen($in['note_date']);
            $old = $this->audited($note);
            $this->fill($note, $party, $in);
            $note->save();
            $this->audit->record('updated', $note, $old, $this->audited($note->refresh()));

            return $note;
        });
    }

    public function delete(DebitNote $note): void
    {
        DB::transaction(function () use ($note) {
            $note = $this->lockOwn($note);
            $this->assertDraft($note);
            $note->delete();
            $this->audit->record('voided_number', $note, ['number' => $note->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $note, $this->audited($note) + ['number' => $note->number]);
        });
    }

    public function post(DebitNote $note): DebitNote
    {
        return DB::transaction(function () use ($note) {
            $note = $this->lockOwn($note);
            if ($note->status === 'posted') {
                throw new ApiProblem('already_posted', "{$note->number} is already posted.");
            }
            $this->assertDraft($note);
            $date = $note->note_date->toDateString();
            $this->periods->assertOpen($date);
            $party = Party::findOrFail($note->party_id);
            $billNo = $note->purchase_bill_id ? PurchaseBill::whereKey($note->purchase_bill_id)->value('number') : null;
            $b = (new JournalBuilder)->debit($party->ledger_id, $note->amount, array_filter(['against_reference' => $billNo]))
                ->credit($this->mapper->ledgerFor('purchase_returns_non_stock'), $note->amount);
            $entry = $this->posting->persist($b, $note, 'debit_note', $date, $note->number, "Debit note to {$party->name}".($billNo ? " against {$billNo}" : ''),
                $billNo, $note->branch_id);
            $note->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now()])->save();
            if ($note->usage === 'apply') {
                $line = (int) $entry->lines->firstWhere('ledger_id', $party->ledger_id)->id;
                $this->allocations->allocate($line, 'purchase_bill', (int) $note->meta()['apply_to_document_id'], $note->amount, $date);
            }
            $this->audit->record('posted', $note, ['status' => 'draft'], ['status' => 'posted', 'journal_entry_id' => $entry->id, 'usage' => $note->usage]);

            return $note->refresh();
        }, attempts: 2);
    }

    /** POST /debit-notes/{id}/cancel (R-03): mirror entry dated today; the bills it settled are open again. */
    public function cancel(DebitNote $note, string $reason): DebitNote
    {
        return DB::transaction(function () use ($note, $reason) {
            $note = $this->lockOwn($note);
            if ($note->status === 'cancelled') {
                throw new ApiProblem('already_reversed', "{$note->number} is already cancelled.");
            }
            if ($note->status !== 'posted') {
                throw new ApiProblem('invalid_transition', "{$note->number} is a draft; delete it instead.", extra: ['status' => $note->status]);
            }
            $party = Party::withTrashed()->findOrFail($note->party_id);
            $original = JournalEntry::with('lines')->whereKey($note->journal_entry_id)->lockForUpdate()->firstOrFail();
            $this->allocations->releaseLine((int) $original->lines->firstWhere('ledger_id', $party->ledger_id)->id);
            $b = new JournalBuilder;
            foreach ($original->lines as $l) {
                $meta = array_filter(['against_reference' => $l->against_reference]);
                $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
            }
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $entry = $this->posting->persist($b, $note, 'cancellation', $date, null, "Cancellation of {$note->number} ({$reason})", $note->number, $note->branch_id, $original->id);
            $original->forceFill(['status' => 'reversed'])->save();
            $note->forceFill(['status' => 'cancelled', 'party_snapshot' => array_merge($note->party_snapshot ?? [], ['note' => $note->meta() + ['cancel_reason' => $reason,
                'cancellation_entry_id' => $entry->id]])])->save();
            $this->audit->record('cancelled', $note, ['status' => 'posted'], ['status' => 'cancelled', 'reason' => $reason, 'cancellation_entry_id' => $entry->id]);

            return $note->refresh();
        }, attempts: 2);
    }

    /** Locks the note; a return's note only changes through its return (R-04). */
    private function lockOwn(DebitNote $note): DebitNote
    {
        $note = DebitNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
        if ($note->purchase_return_id) {
            $number = DB::table('purchase_returns')->where('id', $note->purchase_return_id)->value('number');
            throw new ApiProblem('created_from_return', "{$note->number} was made by return {$number}; it posts and cancels with the return.");
        }

        return $note;
    }

    private function check(array $in): Party
    {
        $party = Party::findOrFail($in['party_id']);
        if ($party->status !== 'active') {
            throw new ApiProblem('inactive_reference', errors: ['party_id' => ["{$party->name} is inactive"]]);
        }
        foreach (['source_document_id', 'apply_to_document_id'] as $f) {
            if (! empty($in[$f])) {
                $bill = PurchaseBill::find($in[$f]);
                if (! $bill || $bill->party_id !== $party->id || $bill->status !== 'posted') {
                    throw new ApiProblem('foreign_document', errors: [$f => ["Choose a posted bill of {$party->name}"]]);
                }
            }
        }
        if ($in['usage'] === 'apply' && empty($in['apply_to_document_id'])) {
            throw new ApiProblem('validation_failed', errors: ['apply_to_document_id' => ['Choose the bill the debit note settles']]);
        }

        return $party;
    }

    private function fill(DebitNote $note, Party $party, array $in): void
    {
        $note->fill([
            'branch_id' => $note->branch_id ?? DB::table('branches')->where('company_id', $party->company_id)->where('is_active', true)->orderBy('id')->value('id'),
            'party_id' => $party->id, 'note_date' => $in['note_date'], 'reason' => $in['reason'], 'amount' => $in['amount'], 'usage' => $in['usage'],
            'purchase_bill_id' => $in['source_document_id'] ?? $in['apply_to_document_id'] ?? null,
            'party_snapshot' => ['code' => $party->code, 'name' => $party->name, 'mobile' => $party->mobile, 'address' => $party->address,
                'note' => ['apply_to_document_id' => $in['usage'] === 'apply' ? (int) $in['apply_to_document_id'] : null]],
        ]);
    }

    private function assertDraft(DebitNote $note): void
    {
        if ($note->status !== 'draft') {
            throw new ApiProblem('not_draft', "{$note->number} is {$note->status}; only a draft can be changed, posted or deleted.", extra: ['status' => $note->status]);
        }
    }

    private function audited(DebitNote $n): array
    {
        return ['party_id' => $n->party_id, 'note_date' => $n->note_date?->format('Y-m-d'), 'reason' => $n->reason, 'amount' => (string) $n->amount, 'usage' => $n->usage];
    }
}
