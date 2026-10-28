<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\TaxRule;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Credit notes (E9, E15; 06 §7; BR-021, BR-023, BR-025; R-03, R-20; TC-SAL-011, TC-SAL-018; backlog P10.3-BE-02).
 *
 * A note is created as a draft by posting a sales return (goods_returned, for the return's value) or by hand for a price
 * correction, damage on delivery or goodwill. Posting it credits the customer: Dr sales_returns (4109) with the net, Dr
 * the tax rule's ledger with the tax of returned lines, Cr customer. Then by usage: apply allocates it to a posted invoice
 * of the customer (at most its balance, BR-023); credit leaves it on the ledger as customer credit; refund also pays it
 * out, a PV Dr customer / Cr money account. Cancelling (R-03) posts the mirror entry, releases its allocations and
 * reverses a refund with it.
 */
final class CreditNoteService
{
    public const REASONS = ['goods_returned', 'price_correction', 'damaged_on_delivery', 'goodwill'];

    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AllocationService $allocations,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $in): CreditNote
    {
        return DB::transaction(function () use ($in) {
            if (! empty($in['return_id'])) {
                throw new ApiProblem('validation_failed', errors: ['return_id' => ['A return gets its credit note when it is posted']]);
            }
            $party = $this->check($in, null);
            $period = $this->periods->assertOpen($in['note_date']);
            $note = new CreditNote(['status' => 'draft']);
            $note->number = $this->numbers->next('credit_note', $period->fiscal_year_id)['number'];
            $this->fill($note, $party, $in);
            $note->save();
            $this->audit->record('created', $note, null, $this->audited($note) + ['number' => $note->number]);

            return $note->refresh();
        });
    }

    /** Called by SalesReturnService::post: the draft note for the return's value, used as the return asked (default apply to its invoice while it has a balance). */
    public function fromReturn(SalesReturn $ret, SalesInvoice $invoice): CreditNote
    {
        $hint = $ret->party_snapshot['note'] ?? [];
        $usage = $hint['usage'] ?? ($invoice->balance_due->isPositive() ? 'apply' : 'credit');
        $period = $this->periods->assertOpen($ret->return_date);
        $note = new CreditNote(['status' => 'draft']);
        $note->number = $this->numbers->next('credit_note', $period->fiscal_year_id)['number'];
        $note->fill(['branch_id' => $ret->branch_id, 'party_id' => $ret->party_id, 'sales_return_id' => $ret->id, 'sales_invoice_id' => $invoice->id,
            'note_date' => $ret->return_date->toDateString(), 'reason' => 'goods_returned', 'amount' => $ret->total, 'usage' => $usage,
            'party_snapshot' => $this->snapshot(Party::withTrashed()->findOrFail($ret->party_id), ['apply_to_document_id' => $usage === 'apply'
                ? ($hint['apply_to_document_id'] ?? $invoice->id) : null, 'refund' => null])]);
        $note->save();
        $this->audit->record('created', $note, null, $this->audited($note) + ['number' => $note->number, 'return' => $ret->number]);

        return $note;
    }

    public function update(CreditNote $note, array $in, int $expectedVersion): CreditNote
    {
        return DB::transaction(function () use ($note, $in, $expectedVersion) {
            $note = CreditNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($note);
            if ((int) $note->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $note->lock_version]);
            }
            if ($note->sales_return_id && (int) $in['party_id'] !== $note->party_id) {
                throw new ApiProblem('foreign_document', errors: ['party_id' => ['A return\'s credit note stays with the return\'s customer']]);
            }
            $party = $this->check($in, $note);
            $this->periods->assertOpen($in['note_date']);
            $old = $this->audited($note);
            $this->fill($note, $party, $in);
            $note->save();
            $this->audit->record('updated', $note, $old, $this->audited($note->refresh()));

            return $note;
        });
    }

    public function delete(CreditNote $note): void
    {
        DB::transaction(function () use ($note) {
            $note = CreditNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($note);
            if ($note->sales_return_id) {
                throw new ApiProblem('invalid_transition', "{$note->number} belongs to a posted return; cancel the return instead.", extra: ['status' => $note->status]);
            }
            $note->delete();
            $this->audit->record('voided_number', $note, ['number' => $note->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $note, $this->audited($note) + ['number' => $note->number]);
        });
    }

    public function post(CreditNote $note): CreditNote
    {
        return DB::transaction(function () use ($note) {
            $note = CreditNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($note->status === 'posted') {
                throw new ApiProblem('already_posted', "{$note->number} is already posted.");
            }
            $this->assertDraft($note);
            $date = $note->note_date->toDateString();
            $this->periods->assertOpen($date);
            $party = Party::findOrFail($note->party_id);
            $meta = $note->meta();
            $invoiceNo = $note->sales_invoice_id ? SalesInvoice::whereKey($note->sales_invoice_id)->value('number') : null;

            $b = new JournalBuilder;
            foreach ($this->debits($note) as [$ledgerId, $amount, $taxRuleId]) {
                $b->debit($ledgerId, $amount, array_filter(['tax_rule_id' => $taxRuleId]));
            }
            $b->credit($party->ledger_id, $note->amount, array_filter(['against_reference' => $invoiceNo]));
            $entry = $this->posting->persist($b, $note, 'credit_note', $date, $note->number, "Credit note to {$party->name}".($invoiceNo ? " against {$invoiceNo}" : ''),
                $invoiceNo, $note->branch_id);
            $note->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now()])->save();
            $line = (int) $entry->lines->firstWhere('ledger_id', $party->ledger_id)->id;

            if ($note->usage === 'apply') {
                $this->allocations->allocate($line, 'sales_invoice', (int) $meta['apply_to_document_id'], $note->amount, $date);
            } elseif ($note->usage === 'refund') {
                $refundId = $this->refund($note, $party, $meta['refund'], $date);
                $note->forceFill(['party_snapshot' => array_merge($note->party_snapshot, ['note' => $meta + ['refund_entry_id' => $refundId]])])->save();
            }
            $this->audit->record('posted', $note, ['status' => 'draft'], ['status' => 'posted', 'journal_entry_id' => $entry->id, 'usage' => $note->usage]);

            return $note->refresh();
        }, attempts: 2);
    }

    /**
     * POST /credit-notes/{id}/cancel (R-03, TC-SAL-018): mirror entry dated today, allocations released (the invoices
     * are open again), a refund voucher reversed with it. A return's cancellation calls this with refuseApplied, so a
     * note already applied or refunded stops it (note_applied).
     */
    public function cancel(CreditNote $note, string $reason, ?string $because = null, bool $refuseApplied = false): CreditNote
    {
        return DB::transaction(function () use ($note, $reason, $because, $refuseApplied) {
            $note = CreditNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            if ($note->status === 'cancelled') {
                throw new ApiProblem('already_reversed', "{$note->number} is already cancelled.");
            }
            if ($note->status !== 'posted') {
                throw new ApiProblem('invalid_transition', "{$note->number} is a draft; delete it (or cancel its return) instead.", extra: ['status' => $note->status]);
            }
            $meta = $note->meta();
            $party = Party::withTrashed()->findOrFail($note->party_id);
            $original = JournalEntry::with('lines')->whereKey($note->journal_entry_id)->lockForUpdate()->firstOrFail();
            $line = (int) $original->lines->firstWhere('ledger_id', $party->ledger_id)->id;
            $applied = DB::table('bill_allocations')->where('journal_line_id', $line)->whereNull('reversed_at')->exists();
            if ($refuseApplied && ($applied || ! empty($meta['refund_entry_id']))) {
                throw new ApiProblem('note_applied', "{$note->number} has been ".($applied ? 'applied to an invoice' : 'refunded').'; cancel the credit note first.');
            }
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $this->allocations->releaseLine($line);
            $cancelId = $this->mirror($original, $note, $date, "Cancellation of {$note->number} ({$reason})".($because ? ": {$because}" : ''));
            if (! empty($meta['refund_entry_id'])) {
                $refund = JournalEntry::with('lines')->whereKey($meta['refund_entry_id'])->lockForUpdate()->firstOrFail();
                $this->mirror($refund, $note, $date, "Refund of {$note->number} reversed ({$reason})");
            }
            $note->forceFill(['status' => 'cancelled', 'party_snapshot' => array_merge($note->party_snapshot, ['note' => $meta + ['cancel_reason' => $reason,
                'cancellation_entry_id' => $cancelId]])])->save();
            $this->audit->record('cancelled', $note, ['status' => 'posted'], ['status' => 'cancelled', 'reason' => $reason, 'cancellation_entry_id' => $cancelId]);

            return $note->refresh();
        }, attempts: 2);
    }

    /**
     * What the note debits: for a return's note at the return's value, the net of each returned line to sales_returns
     * and its tax to the tax rule's ledger (06 §7); otherwise (price correction, goodwill, an edited amount) the whole
     * amount to sales_returns.
     *
     * @return list<array{0: int, 1: Money, 2: ?int}>
     */
    private function debits(CreditNote $note): array
    {
        $returns = $this->mapper->ledgerFor('sales_returns');
        $ret = $note->sales_return_id ? SalesReturn::with('lines')->find($note->sales_return_id) : null;
        if (! $ret || ! $ret->total->isEqualTo($note->amount)) {
            return [[$returns, $note->amount, null]];
        }
        $invLines = SalesInvoiceLine::whereIn('id', $ret->lines->pluck('sales_invoice_line_id'))->get()->keyBy('id');
        $net = Money::zero();
        $tax = [];
        foreach ($ret->lines as $l) {
            $il = $invLines[$l->sales_invoice_line_id];
            $share = Quantity::of($l->quantity)->isEqualTo(Quantity::of($il->quantity)) ? Money::of($il->net_amount)
                : Money::of($il->net_amount->toBigDecimal()->multipliedBy($l->quantity->toBigDecimal())->dividedBy($il->quantity->toBigDecimal(), 2, RoundingMode::HalfUp));
            $lineTax = $l->value->minus($share);
            if ($il->tax_rule_id && $lineTax->isPositive()) {
                $tax[$il->tax_rule_id] = ($tax[$il->tax_rule_id] ?? Money::zero())->plus($lineTax);
                $net = $net->plus($share);
            } else {
                $net = $net->plus($l->value);
            }
        }
        $out = $net->isPositive() ? [[$returns, $net, null]] : [];
        foreach ($tax as $ruleId => $amount) {
            $out[] = [(int) TaxRule::whereKey($ruleId)->value('ledger_id'), $amount, $ruleId];
        }

        return $out;
    }

    /** Usage refund: a PV paying the credit back — Dr customer / Cr money account — with its payment details. */
    private function refund(CreditNote $note, Party $party, array $refund, string $date): int
    {
        $account = MoneyAccount::findOrFail($refund['money_account_id']);
        $period = $this->periods->assertOpen($date);
        $number = $this->numbers->next('payment', $period->fiscal_year_id)['number'];
        $b = (new JournalBuilder)->debit($party->ledger_id, $note->amount, ['against_reference' => $note->number])->credit($account->ledger_id, $note->amount);
        $entry = $this->posting->persist($b, $note, 'payment', $date, $number, "Refund of {$note->number} to {$party->name}", $refund['reference'] ?? $note->number, $note->branch_id);
        DB::table('payment_details')->insert(['company_id' => $entry->company_id, 'journal_entry_id' => $entry->id, 'direction' => 'out', 'party_id' => $party->id,
            'money_account_id' => $account->id, 'method' => $refund['method'], 'transaction_reference' => $refund['reference'] ?? null, 'amount' => (string) $note->amount,
            'bank_charge' => '0', 'unapplied_amount' => '0', 'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);

        return $entry->id;
    }

    private function mirror(JournalEntry $original, CreditNote $note, string $date, string $narration): int
    {
        $b = new JournalBuilder;
        foreach ($original->lines as $l) {
            $meta = array_filter(['tax_rule_id' => $l->tax_rule_id, 'against_reference' => $l->against_reference]);
            $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
        }
        $entry = $this->posting->persist($b, $note, 'cancellation', $date, null, $narration, $note->number, $note->branch_id, $original->id);
        $original->forceFill(['status' => 'reversed'])->save();

        return $entry->id;
    }

    /**
     * Customer active, a posted invoice of the customer to apply to (usage apply; R-20), an active money account to refund
     * from (usage refund), a source invoice of the same customer.
     */
    private function check(array $in, ?CreditNote $note): Party
    {
        $party = Party::findOrFail($in['party_id']);
        $errors = [];
        if ($party->status !== 'active') {
            throw new ApiProblem('inactive_reference', errors: ['party_id' => ["{$party->name} is inactive"]]);
        }
        foreach (['source_document_id', 'apply_to_document_id'] as $f) {
            if (! empty($in[$f])) {
                $inv = SalesInvoice::find($in[$f]);
                if (! $inv || $inv->party_id !== $party->id || $inv->status !== 'posted') {
                    throw new ApiProblem('foreign_document', errors: [$f => ["Choose a posted invoice of {$party->name}"]]);
                }
            }
        }
        if ($in['usage'] === 'apply' && empty($in['apply_to_document_id'])) {
            $errors['apply_to_document_id'] = ['Choose the invoice the credit note settles'];
        }
        if ($in['usage'] === 'refund') {
            $account = isset($in['refund']['money_account_id']) ? MoneyAccount::find($in['refund']['money_account_id']) : null;
            if (! $account) {
                $errors['refund.money_account_id'] = ['Choose the account the refund is paid from'];
            } elseif (! $account->is_active) {
                throw new ApiProblem('inactive_reference', errors: ['refund.money_account_id' => ["{$account->name} is inactive"]]);
            }
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }

        return $party;
    }

    private function fill(CreditNote $note, Party $party, array $in): void
    {
        $note->fill([
            'branch_id' => $note->branch_id ?? DB::table('branches')->where('company_id', $party->company_id)->where('is_active', true)->orderBy('id')->value('id'),
            'party_id' => $party->id, 'note_date' => $in['note_date'], 'reason' => $in['reason'], 'amount' => $in['amount'], 'usage' => $in['usage'],
            'sales_invoice_id' => $note->sales_return_id ? $note->sales_invoice_id : ($in['source_document_id'] ?? $in['apply_to_document_id'] ?? null),
            'party_snapshot' => $this->snapshot($party, ['apply_to_document_id' => $in['usage'] === 'apply' ? (int) $in['apply_to_document_id'] : null,
                'refund' => $in['usage'] === 'refund' ? $in['refund'] : null]),
        ]);
    }

    private function snapshot(Party $p, array $note): array
    {
        return ['code' => $p->code, 'name' => $p->name, 'mobile' => $p->mobile, 'address' => $p->address, 'note' => $note];
    }

    private function assertDraft(CreditNote $note): void
    {
        if ($note->status !== 'draft') {
            throw new ApiProblem('not_draft', "{$note->number} is {$note->status}; only a draft can be changed, posted or deleted.", extra: ['status' => $note->status]);
        }
    }

    private function audited(CreditNote $n): array
    {
        return ['party_id' => $n->party_id, 'note_date' => $n->note_date?->format('Y-m-d'), 'reason' => $n->reason, 'amount' => (string) $n->amount, 'usage' => $n->usage];
    }
}
