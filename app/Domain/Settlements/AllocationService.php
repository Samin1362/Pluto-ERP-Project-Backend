<?php

namespace App\Domain\Settlements;

use App\Domain\Accounting\PeriodGuard;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\Party;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Applies settling party-ledger lines to invoices and bills, and undoes it (06 §6; BR-020, BR-021, R-08; backlog
 * P6.1-BE-02). A settling line is a posted line on the party's ledger on the side that reduces what is owed — a
 * customer's credit (receipt, credit note, advance, opening credit) or a supplier's debit — that is not the
 * document's own entry. Its unapplied part = the line amount − its live allocations.
 *
 * Under lock (document row, then the line, 05 §4): the document must be posted and belong to the line's party
 * (foreign_document), and the amount may exceed neither the document's balance nor the line's unapplied part
 * (allocation_exceeds_balance). The document's amount_settled, balance_due and payment_status follow every change, and
 * a receipt's/payment's payment_details.unapplied_amount with it. Removing sets reversed_at; the row stays.
 */
final class AllocationService
{
    /** Entry types whose party lines settle documents; everything else on the ledger is a document or its reversal. */
    public const SETTLING_TYPES = ['receipt', 'payment', 'credit_note', 'debit_note', 'opening_balance', 'journal', 'contra'];

    public function __construct(private readonly PeriodGuard $periods, private readonly AuditLogger $audit) {}

    /**
     * @param  'sales_invoice'|'purchase_bill'  $type
     * @return int the new allocation's id
     */
    public function allocate(int $journalLineId, string $type, int $documentId, Money $amount, ?string $on = null): int
    {
        return DB::transaction(function () use ($journalLineId, $type, $documentId, $amount, $on) {
            $on ??= CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $this->periods->assertOpen($on);
            $doc = $this->lockDocument($type, $documentId);
            $line = $this->settlingLine($journalLineId, lock: true);
            $party = Party::withTrashed()->findOrFail($doc->party_id);
            if ($party->ledger_id !== $line->ledger_id || $party->party_type !== ($type === 'sales_invoice' ? 'customer' : 'supplier')) {
                throw new ApiProblem('foreign_document', "{$doc->number} belongs to another party than this {$line->kind}.", errors: ['document_id' => ["{$doc->number} is not {$party->name}'s"]]);
            }
            if ($doc->status !== 'posted') {
                throw new ApiProblem('validation_failed', errors: ['document_id' => ["{$doc->number} is {$doc->status}; only a posted document can be settled"]]);
            }
            if (! $amount->isPositive()) {
                throw new ApiProblem('validation_failed', errors: ['amount' => ['Enter an amount above 0']]);
            }
            $this->assertWithin($amount, $doc->balance_due, "{$doc->number} has only ".$doc->balance_due.' open', 'amount');
            $this->assertWithin($amount, $line->unapplied, "{$line->number} has only {$line->unapplied} left to apply", 'amount');

            $id = DB::table('bill_allocations')->insertGetId(['company_id' => $doc->company_id, 'journal_line_id' => $line->id,
                $type === 'sales_invoice' ? 'sales_invoice_id' : 'purchase_bill_id' => $doc->id, 'amount' => (string) $amount, 'allocated_on' => $on,
                'created_by' => auth()->id(), 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $this->settle($doc, $amount);
            $this->paymentUnapplied($line->journal_entry_id, $amount->negated());
            $this->audit->record('allocated', $doc, null, ['allocation_id' => $id, 'voucher' => $line->number, 'amount' => (string) $amount]);

            return $id;
        });
    }

    /** DELETE /allocations/{id}: marks it reversed and gives the amount back to the document and the settling line. */
    public function remove(int $allocationId): void
    {
        DB::transaction(function () use ($allocationId) {
            $a = DB::table('bill_allocations')->where('id', $allocationId)->first() ?? throw new ApiProblem('not_found');
            $type = $a->sales_invoice_id ? 'sales_invoice' : 'purchase_bill';
            $doc = $this->lockDocument($type, (int) ($a->sales_invoice_id ?? $a->purchase_bill_id));
            $a = DB::table('bill_allocations')->where('id', $allocationId)->lockForUpdate()->first();
            if ($a->reversed_at !== null) {
                throw new ApiProblem('not_found', 'This allocation was already removed.');
            }
            $this->periods->assertOpen(CarbonImmutable::now('Asia/Dhaka'));
            $amount = Money::of($a->amount);
            DB::table('bill_allocations')->where('id', $a->id)->update(['reversed_at' => now(), 'updated_by' => auth()->id(), 'updated_at' => now()]);
            $this->settle($doc, $amount->negated());
            $entryId = (int) DB::table('journal_lines')->where('id', $a->journal_line_id)->value('journal_entry_id');
            $this->paymentUnapplied($entryId, $amount);
            $this->audit->record('allocation_removed', $doc, ['allocation_id' => $a->id, 'amount' => (string) $amount], null);
        });
    }

    /** Releases every live allocation of a cancelled document (BR-024): the receipts/payments become advances again. */
    public function releaseAll(Model $doc): void
    {
        $col = $doc instanceof SalesInvoice ? 'sales_invoice_id' : 'purchase_bill_id';
        foreach (DB::table('bill_allocations')->where($col, $doc->id)->whereNull('reversed_at')->lockForUpdate()->get() as $a) {
            DB::table('bill_allocations')->where('id', $a->id)->update(['reversed_at' => now(), 'updated_by' => auth()->id(), 'updated_at' => now()]);
            $this->paymentUnapplied((int) DB::table('journal_lines')->where('id', $a->journal_line_id)->value('journal_entry_id'), Money::of($a->amount));
        }
        $doc->forceFill(['amount_settled' => Money::zero(), 'payment_status' => 'unpaid']); // saved by the cancelling service
    }

    /**
     * A settling line with its number, kind and unapplied part, or 422 when the line cannot settle anything.
     *
     * @return object{id: int, journal_entry_id: int, ledger_id: int, number: string, kind: string, date: string, amount: Money, unapplied: Money}
     */
    public function settlingLine(int $id, bool $lock = false): object
    {
        $q = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.id', $id)
            ->select('l.id', 'l.journal_entry_id', 'l.ledger_id', 'l.debit', 'l.credit', 'e.voucher_number', 'e.entry_type', 'e.entry_date', 'e.status', 'l.is_posted');
        $l = ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new ApiProblem('validation_failed', errors: ['journal_line_id' => ['Choose a receipt, payment, note or advance']]);
        $party = Party::withTrashed()->where('ledger_id', $l->ledger_id)->first();
        $side = $party?->party_type === 'customer' ? 'credit' : 'debit';
        $amount = Money::of((string) $l->{$side});
        if (! $party || ! $l->is_posted || $l->status !== 'posted' || ! in_array($l->entry_type, self::SETTLING_TYPES, true) || ! $amount->isPositive()) {
            throw new ApiProblem('validation_failed', errors: ['journal_line_id' => ['This line cannot settle a document; choose a receipt, payment, note or advance of the party']]);
        }
        $used = Money::of((string) DB::table('bill_allocations')->where('journal_line_id', $l->id)->whereNull('reversed_at')->sum('amount'));

        return (object) ['id' => $l->id, 'journal_entry_id' => $l->journal_entry_id, 'ledger_id' => $l->ledger_id, 'number' => $l->voucher_number,
            'kind' => self::kind($l->entry_type), 'date' => (string) $l->entry_date, 'amount' => $amount, 'unapplied' => $amount->minus($used)];
    }

    /** Allocation (05 schema) of one bill_allocations row. */
    public static function row(int $id): array
    {
        $a = DB::table('bill_allocations as a')->join('journal_lines as l', 'l.id', '=', 'a.journal_line_id')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('a.id', $id)->first(['a.id', 'a.amount', 'a.allocated_on', 'a.reversed_at', 'e.id as entry_id', 'e.voucher_number', 'e.entry_type']);

        return ['id' => $a->id, 'voucher_id' => $a->entry_id, 'voucher_number' => $a->voucher_number, 'date' => $a->allocated_on, 'amount' => $a->amount,
            'kind' => AllocationService::kind($a->entry_type), 'reversed' => $a->reversed_at !== null];
    }

    /** Allocations of one document, newest first (for the invoice/bill resources). */
    public static function forDocument(string $column, int $documentId): array
    {
        return DB::table('bill_allocations')->where($column, $documentId)->orderByDesc('id')->pluck('id')->map(fn ($id) => self::row((int) $id))->all();
    }

    public static function kind(string $entryType): string
    {
        return in_array($entryType, ['receipt', 'payment', 'credit_note', 'debit_note'], true) ? $entryType : 'advance';
    }

    private function lockDocument(string $type, int $id): SalesInvoice|PurchaseBill
    {
        $model = $type === 'sales_invoice' ? SalesInvoice::class : PurchaseBill::class;

        return $model::whereKey($id)->lockForUpdate()->first()
            ?? throw new ApiProblem('validation_failed', errors: ['document_id' => ['Choose an invoice or bill of this company']]);
    }

    private function assertWithin(Money $amount, Money $limit, string $message, string $field): void
    {
        if ($amount->isGreaterThan($limit)) {
            throw new ApiProblem('allocation_exceeds_balance', $message, errors: [$field => [$message]], extra: ['available' => (string) $limit]);
        }
    }

    /** amount_settled, balance_due and payment_status follow the allocations (positive = settles more). */
    private function settle(SalesInvoice|PurchaseBill $doc, Money $delta): void
    {
        $settled = $doc->amount_settled->plus($delta);
        $balance = $doc->total->minus($settled);
        $doc->forceFill(['amount_settled' => $settled, 'balance_due' => $doc->status === 'posted' ? $balance : Money::zero(),
            'payment_status' => $settled->isZero() ? 'unpaid' : ($balance->isPositive() ? 'part_paid' : 'paid')])->save();
    }

    /** Keeps payment_details.unapplied_amount of a receipt/payment in step (no row for notes, advances, openings). */
    private function paymentUnapplied(int $journalEntryId, Money $delta): void
    {
        $row = DB::table('payment_details')->where('journal_entry_id', $journalEntryId)->lockForUpdate()->first(['id', 'unapplied_amount']);
        if ($row) {
            DB::table('payment_details')->where('id', $row->id)->update(['unapplied_amount' => (string) Money::of($row->unapplied_amount)->plus($delta), 'updated_at' => now()]);
        }
    }
}
